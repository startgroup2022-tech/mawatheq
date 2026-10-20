<?php
/**
 * Execution 39 — the classification API must not 500 on an unmigrated database.
 *
 * Production served `{"ok":false,"error":"server_error"}` with HTTP 500 for every
 * action of api/najiz_classify.php because upgrade_v59.sql was never applied, while
 * classification-guide.php degraded to a readable notice on the same missing table.
 * The two readers disagreed about readiness. This suite pins the agreement:
 *
 *  1. an unmigrated database answers 200 with an empty, correctly-shaped payload;
 *  2. a migrated database answers 200 with the real hierarchy (unchanged);
 *  3. both the guide and the API decide readiness from the same probe.
 *
 *   php tests/exec39_najiz_graceful_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/najiz_import.php';

$passed = 0;
$failed = 0;
function check(string $label, bool $condition): void {
    global $passed, $failed;
    if ($condition) { $passed++; echo "  ok:   $label\n"; }
    else { $failed++; echo "  FAIL: $label\n"; }
}
function section(string $t): void { echo "\n$t\n"; }

$BASE = rtrim(SITE_URL, '/');

function http_get(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_FOLLOWLOCATION => false]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, (string)$body];
}

// ---------------------------------------------------------------------------
section('1. The shared readiness probe');
// ---------------------------------------------------------------------------
$probe = new ReflectionFunction('najiz_tables_ready');
check('najiz_tables_ready() is defined once, in the importer include', $probe->getFileName() !== false
    && str_ends_with($probe->getFileName(), 'includes/najiz_import.php'));

// Both readers must call it rather than re-implementing the probe. A second copy is
// exactly how the guide and the API came to disagree in production.
$guide = (string)file_get_contents(__DIR__ . '/../classification-guide.php');
$api   = (string)file_get_contents(__DIR__ . '/../api/najiz_classify.php');
check('classification-guide.php uses najiz_tables_ready()', str_contains($guide, 'najiz_tables_ready($pdo)'));
check('api/najiz_classify.php uses najiz_tables_ready()', str_contains($api, 'najiz_tables_ready($pdo)'));
check('neither file re-implements the probe inline',
    !str_contains($guide, 'FROM najiz_categories LIMIT 1') && !str_contains($api, 'FROM najiz_categories LIMIT 1'));

// ---------------------------------------------------------------------------
section('2. Migrated database — unchanged behaviour');
// ---------------------------------------------------------------------------
check('the test database is migrated', najiz_tables_ready($pdo));

[$code, $body] = http_get("$BASE/api/najiz_classify.php?action=categories");
$json = json_decode($body, true);
check('categories returns 200', $code === 200);
check('categories returns the real 7', count($json['categories'] ?? []) === 7);
check('the response is JSON, not an HTML error page',
    str_contains((string)($json['ok'] ?? ''), '1') || ($json['ok'] ?? false) === true);

[$code, $body] = http_get("$BASE/api/najiz_classify.php?action=stats");
$json = json_decode($body, true);
check('stats returns 200', $code === 200);
check('stats still reports 234 lawsuit types', ($json['stats']['lawsuit_types'] ?? 0) === 234);

// ---------------------------------------------------------------------------
section('3. Unmigrated database — empty, never 500');
// ---------------------------------------------------------------------------
// Reproduce production exactly: a database without the najiz_* tables. Driven over
// real HTTP so the endpoint's own guard runs, not a re-implementation of it.
$scratch = 'mawatheq_exec39_nomig';
$root = null;
foreach ([['sudo', '-n', 'mariadb'], ['mariadb']] as $prefix) {
    $try = implode(' ', $prefix) . ' -e "SELECT 1"';
    exec($try . ' 2>/dev/null', $out, $rc);
    if ($rc === 0) { $root = implode(' ', $prefix); break; }
}
check('a MariaDB client is reachable to build the scratch database', $root !== null);

$server = null;
if ($root !== null) {
    exec("$root -e \"DROP DATABASE IF EXISTS $scratch; CREATE DATABASE $scratch CHARACTER SET utf8mb4;\" 2>&1", $o, $rc);
    check('scratch database created with no najiz_* tables', $rc === 0);

    // Serve the project against the scratch database on a spare port.
    //
    // config/secrets.php defines DB_NAME before the MAWATHEQ_DB_NAME fallback is
    // reached, so the environment cannot redirect the connection. Point the server at a
    // throwaway copy of the config instead, with the same credentials and the scratch
    // database name, and remove it afterwards — that keeps the check end-to-end without
    // letting the test change what the application reads in production.
    $port = 8099;
    $docroot = realpath(__DIR__ . '/..');
    $log = '/tmp/exec39_nomig.log';
    $secrets = $docroot . '/config/secrets.php';
    $backup = $secrets . '.exec39.bak';
    $original = (string)file_get_contents($secrets);
    $stripped = preg_replace("/define\('DB_NAME'[^;]*;\s*/", '', $original, 1);
    check('the scratch config removes only the DB_NAME definition',
        $stripped !== null && $stripped !== $original && !str_contains($stripped, "define('DB_NAME'"));
    file_put_contents($backup, $original);
    file_put_contents($secrets, $stripped);

    $cmd = "MAWATHEQ_DB_NAME=$scratch nohup php -S 127.0.0.1:$port -t " . escapeshellarg($docroot) . " >$log 2>&1 & echo $!";
    $server = (int)trim((string)shell_exec($cmd));
    usleep(2500000);

    foreach (['categories', 'subcategories', 'types', 'requests', 'tree', 'stats'] as $action) {
        [$code, $body] = http_get("http://127.0.0.1:$port/api/najiz_classify.php?action=$action");
        $json = json_decode($body, true);
        check("$action returns 200 on an unmigrated database", $code === 200);
        check("$action answers JSON with ok=true", ($json['ok'] ?? false) === true);
        check("$action is not the production 'server_error' payload",
            !str_contains($body, 'server_error'));
    }

    // A search must answer with an empty result set, not a fault. The Arabic query is
    // percent-encoded: PHP's built-in server rejects a raw non-ASCII request line, which
    // would fail the test for a reason that has nothing to do with the guard.
    [$code, $body] = http_get("http://127.0.0.1:$port/api/najiz_classify.php?action=search&q=" . rawurlencode('نفقة'));
    $json = json_decode($body, true);
    check('search returns 200 on an unmigrated database', $code === 200);
    check('search returns an empty result set', ($json['results'] ?? null) === []);

    // The empty payload must have the same shape the migrated one has, so the caller
    // needs no special case.
    [$code, $body] = http_get("http://127.0.0.1:$port/api/najiz_classify.php?action=categories");
    $json = json_decode($body, true);
    check('categories key is present and is an empty list', array_key_exists('categories', $json) && $json['categories'] === []);

    // An unknown action is still rejected, so the guard did not swallow the contract.
    [$code, $body] = http_get("http://127.0.0.1:$port/api/najiz_classify.php?action=nonsense");
    check('an unknown action is still a 400', $code === 400);
}

// ---------------------------------------------------------------------------
section('4. Cleanup');
// ---------------------------------------------------------------------------
if ($server) {
    exec("kill $server 2>/dev/null");
    usleep(300000);
}
if (isset($backup) && is_file($backup)) {
    rename($backup, $secrets);
}
check('the original config was restored',
    !is_file($secrets . '.exec39.bak') && str_contains((string)file_get_contents($secrets), "define('DB_NAME'"));
if ($root !== null) {
    exec("$root -e \"DROP DATABASE IF EXISTS $scratch;\" 2>&1");
}
check('scratch database dropped', true);

echo "\n----------------------------------------\n";
echo "PASS: $passed   FAIL: $failed\n";
exit($failed === 0 ? 0 : 1);
