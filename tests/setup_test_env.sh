#!/usr/bin/env bash
# Bring up the local Mawatheq test environment from a clean checkout.
#
# Idempotent and non-destructive: it never writes config/secrets.php into git and
# never touches a production database. Intended for Debian/Ubuntu containers.
#
#   bash tests/setup_test_env.sh
#
# After it finishes:
#   php tests/<suite>_test.php          # unit/contract suites
#   npx playwright test                 # browser suite (needs 12000)
#
# Ports: 12000 is the browser-suite base URL, 8080 is what the *_http_probe.php
# files hardcode. Both are served by the same Apache vhost, so both must listen.

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DB_NAME="${MAWATHEQ_DB_NAME:-mawatheq_test}"
DB_USER="${MAWATHEQ_DB_USER:-mawatheq}"
DB_PASS="${MAWATHEQ_DB_PASS:-testpass}"
MOCK_AI_PORT=11434

log() { printf '\n== %s\n' "$*"; }

log "1/6 installing PHP, MariaDB, Apache and test helpers"
sudo apt-get update -qq
sudo DEBIAN_FRONTEND=noninteractive apt-get install -y -qq \
    php-cli php-mysql php-mbstring php-curl php-xml php-zip php-intl php-gd \
    mariadb-server apache2 libapache2-mod-php unzip zip antiword catdoc composer

log "2/6 starting MariaDB"
sudo mkdir -p /run/mysqld && sudo chown mysql:mysql /run/mysqld
if ! mariadb -e 'SELECT 1' >/dev/null 2>&1; then
    sudo nohup mariadbd --user=mysql >/tmp/mariadb.log 2>&1 &
    for _ in $(seq 1 30); do mariadb -e 'SELECT 1' >/dev/null 2>&1 && break; sleep 1; done
fi
mariadb -e 'SELECT VERSION()'

log "3/6 creating database '$DB_NAME' and applying schema + every upgrade"
sudo mariadb -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
                  CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
                  GRANT ALL PRIVILEGES ON *.* TO '$DB_USER'@'localhost' WITH GRANT OPTION;
                  FLUSH PRIVILEGES;"
sudo mariadb "$DB_NAME" < "$ROOT/database/schema.sql"
# Upgrade files must run in numeric order; a lexical sort puts v10 before v2.
for f in $(ls "$ROOT"/database/upgrade_v*.sql \
           | sed 's/.*upgrade_v\([0-9]*\)\.sql/\1 &/' | sort -n | awk '{print $2}'); do
    sudo mariadb "$DB_NAME" < "$f" || { echo "upgrade failed: $f"; exit 1; }
done
echo "applied $(ls "$ROOT"/database/upgrade_v*.sql | wc -l) upgrade files"

log "4/6 writing config/secrets.php (git-ignored) and seeding QA data"
cat > "$ROOT/config/secrets.php" <<PHP
<?php
// LOCAL TEST ONLY — never committed (see .gitignore).
define('DB_HOST', '127.0.0.1');
define('DB_NAME', '$DB_NAME');
define('DB_USER', '$DB_USER');
define('DB_PASS', '$DB_PASS');
define('SITE_URL', 'http://127.0.0.1:12000');
define('MAIL_FROM_EMAIL', 'info@mawatheq.com');
define('ADMIN_SETUP_KEY', 'test-only-admin-setup-key-0123456789abcdef');
define('MAWATHEQ_ENCRYPTION_KEY', 'test-only-encryption-key-0123456789abcdef');
PHP
# Apache runs as www-data, the CLI as the invoking user: group-readable lets both in.
# 0640 keeps it off the world, which production_check.php accepts for local runs.
sudo chown "$(id -un):www-data" "$ROOT/config/secrets.php"
chmod 640 "$ROOT/config/secrets.php"

php "$ROOT/tests/seed_phase6.php"
php "$ROOT/tests/seed_phase6_business.php"
php "$ROOT/tests/seed_crm_demo.php"

log "5/6 installing PHP and JS dependencies"
(cd "$ROOT" && composer install --no-interaction --no-progress)
(cd "$ROOT" && npm install --no-audit --no-fund)
(cd "$ROOT" && npx playwright install --with-deps chromium)

# Apache writes consultation attachments as www-data; the test suites run as the invoking
# user and must be able to read, rename and delete the same files. Sharing the group is what
# makes that work — the directory mode alone cannot, because a setgid directory still denies
# a non-member. The private root is a SIBLING of the app root, so it is created here if the
# application has not run yet.
PRIVATE_ROOT="$(dirname "$ROOT")/mawatheq-private"
sudo mkdir -p "$PRIVATE_ROOT"
sudo chgrp -R www-data "$PRIVATE_ROOT"
sudo chmod 2770 "$PRIVATE_ROOT"
if ! id -nG "$(id -un)" | tr ' ' '\n' | grep -qx www-data; then
    sudo usermod -aG www-data "$(id -un)"
    echo "note: added $(id -un) to the www-data group; re-login for it to take effect"
fi

log "6/6 starting the mock AI provider and Apache on 12000 + 8080"
# executive09/execution12 tests expect an OpenAI-compatible server on $MOCK_AI_PORT.
mkdir -p /tmp/mockai
cat > /tmp/mockai/router.php <<'PHP'
<?php
// Mock OpenAI-compatible provider. Contract pinned by
// tests/execution12_ai_connectivity_test.php: the reply must contain
// 'خادم الاختبار' and usage must be prompt_tokens=12, completion_tokens=5.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
header('Content-Type: application/json');
if ($path === '/v1/models') {
    echo json_encode(['object' => 'list', 'data' => [['id' => 'mock-legal-model', 'object' => 'model']]]);
    exit;
}
if (preg_match('#^/v1/chat/completions$#', $path)) {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    echo json_encode([
        'id' => 'chatcmpl-mock', 'object' => 'chat.completion',
        'model' => $in['model'] ?? 'mock-legal-model',
        'choices' => [[
            'index' => 0,
            'message' => ['role' => 'assistant', 'content' => 'رد من خادم الاختبار المحلي.'],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 5, 'total_tokens' => 17],
    ]);
    exit;
}
echo json_encode(['error' => ['message' => 'not found']]);
PHP
if ! (ss -lnt 2>/dev/null | grep -q ":$MOCK_AI_PORT "); then
    nohup php -S "127.0.0.1:$MOCK_AI_PORT" /tmp/mockai/router.php >/tmp/mockai/server.log 2>&1 &
fi

sudo a2enmod rewrite headers >/dev/null 2>&1
# 8080 may already be present from an earlier run; only add what is missing.
for port in 12000 8080; do
    grep -qE "^Listen $port$" /etc/apache2/ports.conf || echo "Listen $port" | sudo tee -a /etc/apache2/ports.conf >/dev/null
done
for port in 12000 8080; do
    sudo tee "/etc/apache2/sites-available/mawatheq-$port.conf" >/dev/null <<CONF
<VirtualHost *:$port>
    DocumentRoot $ROOT
    <Directory $ROOT>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    ErrorLog /tmp/apache-$port-error.log
    CustomLog /tmp/apache-$port-access.log combined
</VirtualHost>
CONF
    sudo a2ensite "mawatheq-$port" >/dev/null 2>&1
done
sudo a2dissite 000-default >/dev/null 2>&1
sudo apachectl restart

sleep 2
for port in 12000 8080; do
    printf 'http://127.0.0.1:%s/index.php -> %s\n' "$port" \
        "$(curl -s -o /dev/null -w '%{http_code}' -m 10 "http://127.0.0.1:$port/index.php")"
done
printf 'mock AI /v1/models -> %s\n' \
    "$(curl -s -o /dev/null -w '%{http_code}' -m 10 "http://127.0.0.1:$MOCK_AI_PORT/v1/models")"

cat <<'NOTE'

Environment ready.

Run the suites with:
  php tests/<name>_test.php
  npx playwright test

Note: repeated logins trip the app's own auth rate limiter, which shows up as
login failures in the *_http_probe.php files. Clear it before a fresh run:
  mariadb mawatheq_test -e "DELETE FROM auth_rate_limits;"
NOTE
