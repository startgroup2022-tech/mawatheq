<?php
/**
 * Execution 29 — mobile API token lifecycle probe.
 *
 * Drives the real /api/v1/ endpoints over HTTP to prove, as an attacker or a lapsed
 * client would experience it:
 *   - an expired token is refused
 *   - a revoked token is refused
 *   - logout revokes only the calling device, not every device
 *   - a legacy plaintext token still works and is upgraded to a hash in place
 *   - an integration token cannot reach account endpoints, and now expires
 *   - the app still works for a normal client, so nothing was broken
 *
 * Requires the app running on 127.0.0.1:8080 (see tests/setup_test_env.sh).
 * Run: php tests/execution29_mobile_token_probe.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/integrations.php';

$base = 'http://127.0.0.1:8080';
$failures = 0;
$checks = 0;
function check(string $label, bool $ok): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) { $failures++; echo "  FAIL: $label\n"; } else { echo "  ok:   $label\n"; }
}

/** GET/POST with an optional bearer token; returns [httpCode, body]. */
$call = function (string $token, string $path, ?array $post = null) use ($base): array {
    $ch = curl_init($base . $path);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20];
    if ($token !== '') $opts[CURLOPT_HTTPHEADER] = ["Authorization: Bearer $token"];
    if ($post !== null) { $opts[CURLOPT_POST] = true; $opts[CURLOPT_POSTFIELDS] = $post; }
    curl_setopt_array($ch, $opts);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
};

$suffix = bin2hex(random_bytes(4));
$created = ['users' => [], 'lawyers' => [], 'api_tokens' => [], 'integration_api_tokens' => [], 'law_firms' => []];

$pdo->prepare("INSERT INTO users (full_name, email, password_hash, status, email_verified, account_type) VALUES (?,?,?,'active',1,'individual')")
    ->execute(['عميل الجوال', "e29-mobile-$suffix@example.test", password_hash('E29-Test-Pass-2026', PASSWORD_BCRYPT)]);
$clientId = (int)$pdo->lastInsertId();
$created['users'][] = $clientId;

// law_firms.owner_lawyer_id is a non-null foreign key, so a firm needs a real lawyer.
$pdo->prepare("INSERT INTO lawyers (full_name, email, password_hash, status, email_verified) VALUES (?,?,?,'approved',1)")
    ->execute(['محامي التكامل', "e29-mobile-lawyer-$suffix@example.test", password_hash('E29-Test-Pass-2026', PASSWORD_BCRYPT)]);
$lawyerId = (int)$pdo->lastInsertId();
$created['lawyers'][] = $lawyerId;

/** Issue a mobile token row and return the raw token. */
$issue = function (int $userId, ?string $expiresSql, ?string $plaintext = null) use ($pdo, &$created): string {
    $raw = $plaintext ?? bin2hex(random_bytes(32));
    $stored = $plaintext !== null ? $plaintext : hash('sha256', $raw);
    $sql = 'INSERT INTO api_tokens (token, user_type, user_id, device_label, expires_at) VALUES (?,?,?,?,'
        . ($expiresSql === null ? 'NULL' : $expiresSql) . ')';
    $pdo->prepare($sql)->execute([$stored, 'client', $userId, 'e29-mobile']);
    $created['api_tokens'][] = (int)$pdo->lastInsertId();
    return $raw;
};

echo "\n== setup ==\n";
check('a test client exists', $clientId > 0);

echo "\n== 1. a valid token reaches the API ==\n";
$good = $issue($clientId, 'DATE_ADD(NOW(), INTERVAL 30 DAY)');
[$code, $body] = $call($good, '/api/v1/me.php');
check("a valid token is accepted (HTTP $code)", $code === 200);
check('the profile belongs to the test client', str_contains($body, "e29-mobile-$suffix@example.test"));

echo "\n== 2. an expired token is refused ==\n";
$expired = $issue($clientId, 'DATE_SUB(NOW(), INTERVAL 1 MINUTE)');
[$code] = $call($expired, '/api/v1/me.php');
check("an expired token is refused (HTTP $code)", $code === 401);

echo "\n== 3. a revoked token is refused ==\n";
$revoked = $issue($clientId, 'DATE_ADD(NOW(), INTERVAL 30 DAY)');
$pdo->prepare('UPDATE api_tokens SET revoked_at=NOW() WHERE token=?')->execute([hash('sha256', $revoked)]);
[$code] = $call($revoked, '/api/v1/me.php');
check("a revoked token is refused (HTTP $code)", $code === 401);

echo "\n== 4. an unknown token is refused ==\n";
[$code] = $call(bin2hex(random_bytes(32)), '/api/v1/me.php');
check("an unknown token is refused (HTTP $code)", $code === 401);
[$code] = $call('', '/api/v1/me.php');
check("a missing token is refused (HTTP $code)", $code === 401);

echo "\n== 5. logout revokes only the calling device ==\n";
$deviceA = $issue($clientId, 'DATE_ADD(NOW(), INTERVAL 30 DAY)');
$deviceB = $issue($clientId, 'DATE_ADD(NOW(), INTERVAL 30 DAY)');
[$codeA] = $call($deviceA, '/api/v1/logout.php', []);
check("logout succeeds (HTTP $codeA)", $codeA === 200);
[$code] = $call($deviceA, '/api/v1/me.php');
check("the logged-out device is now refused (HTTP $code)", $code === 401);
[$code] = $call($deviceB, '/api/v1/me.php');
check("the other device still works (HTTP $code)", $code === 200);
[$code] = $call($deviceA, '/api/v1/logout.php', []);
check("logging out again is idempotent (HTTP $code)", $code === 401 || $code === 200);

echo "\n== 6. a legacy plaintext token still works and is upgraded ==\n";
$legacyRaw = bin2hex(random_bytes(32));
$issue($clientId, 'DATE_ADD(NOW(), INTERVAL 30 DAY)', $legacyRaw);
$before = (string)$pdo->query("SELECT token FROM api_tokens WHERE token='" . $legacyRaw . "'")->fetchColumn();
check('the legacy token is stored as plaintext', $before === $legacyRaw);
[$code] = $call($legacyRaw, '/api/v1/me.php');
check("the legacy plaintext token is accepted (HTTP $code)", $code === 200);
$after = (string)$pdo->query("SELECT token FROM api_tokens WHERE token='" . hash('sha256', $legacyRaw) . "'")->fetchColumn();
check('the legacy token was upgraded to its hash', $after === hash('sha256', $legacyRaw));
[$code] = $call($legacyRaw, '/api/v1/me.php');
check("the token still works after the upgrade (HTTP $code)", $code === 200);

echo "\n== 7. an integration token cannot reach account endpoints ==\n";
$pdo->prepare("INSERT INTO law_firms (name, owner_lawyer_id) VALUES (?, ?)")->execute(['E29 Firm ' . $suffix, $lawyerId]);
$firmId = (int)$pdo->lastInsertId();
$created['law_firms'][] = $firmId;
$issued = integration_token_issue($pdo, $firmId, 'e29-probe-' . $suffix, ['cases:read']);
check('an integration token was issued', $issued['ok'] === true);
$created['integration_api_tokens'][] = (int)$issued['id'];
foreach (['/api/v1/me.php', '/api/v1/notifications.php'] as $path) {
    [$code] = $call($issued['token'], $path);
    check("integration token refused on $path (HTTP $code)", $code === 403);
}
[$code] = $call($issued['token'], '/api/v1/cases.php');
check("integration token allowed on its scoped endpoint (HTTP $code)", $code === 200);

echo "\n== 8. integration tokens now carry an expiry ==\n";
$hasExpiry = (int)$pdo->query('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="integration_api_tokens" AND COLUMN_NAME="expires_at"')->fetchColumn();
check('integration_api_tokens.expires_at exists', $hasExpiry === 1);
$exp = (string)$pdo->query('SELECT expires_at FROM integration_api_tokens WHERE id=' . (int)$issued['id'])->fetchColumn();
check('the issued token has an expiry set', $exp !== '' && $exp !== '0');
$ttlDays = (int)$pdo->query('SELECT DATEDIFF(expires_at, NOW()) FROM integration_api_tokens WHERE id=' . (int)$issued['id'])->fetchColumn();
check("the expiry is the configured TTL (got {$ttlDays}d)", $ttlDays > INTEGRATION_TOKEN_TTL_DAYS - 2 && $ttlDays <= INTEGRATION_TOKEN_TTL_DAYS);
// An expired integration token must be refused exactly like an unknown one.
$pdo->prepare('UPDATE integration_api_tokens SET expires_at=DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id=?')->execute([(int)$issued['id']]);
[$code] = $call($issued['token'], '/api/v1/cases.php');
check("an expired integration token is refused (HTTP $code)", $code === 401);
$pdo->prepare('UPDATE integration_api_tokens SET expires_at=DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE id=?')->execute([(int)$issued['id']]);
[$code] = $call($issued['token'], '/api/v1/cases.php');
check("restoring the expiry re-enables the token (HTTP $code)", $code === 200);

echo "\n== 9. the integration logout guard ==\n";
[$code] = $call($issued['token'], '/api/v1/logout.php', []);
check("an integration token cannot call logout (HTTP $code)", $code === 403);

echo "\n== cleanup ==\n";
if ($created['integration_api_tokens']) {
    $ids = implode(',', array_map('intval', $created['integration_api_tokens']));
    $pdo->exec("DELETE FROM integration_api_tokens WHERE id IN ($ids)");
}
foreach (['api_tokens', 'users', 'law_firms'] as $table) {
    if (empty($created[$table])) continue;
    $ids = implode(',', array_map('intval', $created[$table]));
    $pdo->exec("DELETE FROM `$table` WHERE id IN ($ids)");
}
$left = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE email LIKE 'e29-mobile-$suffix@example.test'")->fetchColumn();
check('the seeded account was removed', $left === 0);

echo "\n  Execution 29 mobile token lifecycle: $checks checks, $failures failure(s)\n";
exit($failures > 0 ? 1 : 0);
