<?php
/**
 * Execution 67 — payment failure diagnostics.
 *
 * The customer message "could not start the online payment" is produced by one line for
 * half a dozen unrelated causes. This suite proves the diagnostics layer turns that opaque
 * failure into a self-identifying one, and that it never records a credential.
 *
 * It drives the REAL curl path in includes/ngenius.php against the fixture server (no HTTP
 * mocking), exactly like exec62/exec64.
 *
 * Run: php tests/exec67_payment_diagnostics_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/ngenius.php';
require_once __DIR__ . '/../includes/gateway_diagnostics.php';
require_once __DIR__ . '/../includes/package_payment.php';
require_once __DIR__ . '/../includes/consultation_payment.php';

$failures = 0; $checks = 0;
function check(string $label, bool $ok): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) { $failures++; echo "  FAIL: $label\n"; } else echo "  ok:   $label\n";
}

if (!gateway_diag_schema_ready($pdo)) {
    echo "SKIP: gateway_diagnostics table missing — apply database/upgrade_v72.sql first.\n";
    exit(0);
}

/* ------------------------------------------------ fixture server ---- */
$port = 0; $proc = null;
for ($candidate = 18500; $candidate < 18540; $candidate++) {
    $cmd = escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $candidate . ' ' . escapeshellarg(__DIR__ . '/fixtures/ngenius_server.php') . ' > /dev/null 2>&1';
    $proc = proc_open($cmd, [], $pipes);
    usleep(300000);
    $probe = @file_get_contents('http://127.0.0.1:' . $candidate . '/__health');
    if ($probe !== false && strpos($probe, 'ok') !== false) { $port = $candidate; break; }
    if (is_resource($proc)) proc_terminate($proc);
    $proc = null;
}
if ($port === 0) { echo "SKIP: could not start the N-Genius fixture server\n"; exit(0); }
$base = 'http://127.0.0.1:' . $port;
putenv('MAWATHEQ_NGENIUS_BASE_URL=' . $base);
foreach (glob(dirname(__DIR__) . '/storage/cache/ngenius-token-*.json') ?: [] as $f) @unlink($f);
echo "fixture N-Genius gateway on 127.0.0.1:$port\n";

/* ------------------------------------------------ 1. the redactor ---- */
echo "\n== 1. credentials are never recorded ==\n";
$dirty = 'Authorization: Basic c2VjcmV0LWFjY2Vzcy10b2tlbi12YWx1ZS0xMjM0NTY3ODkw api_key="sk_live_ABCDEF0123456789abcdef" token=abcdef0123456789abcdef0123456789 card 4111 1111 1111 1111';
$clean = gateway_diag_sanitize($dirty);
check('a base64 Authorization value is redacted', stripos($clean, 'c2VjcmV0LWFjY2Vzcy10b2tlbi12YWx1ZS0xMjM0NTY3ODkw') === false);
check('a long key-shaped blob is redacted', stripos($clean, 'ABCDEF0123456789abcdef') === false);
check('a card-shaped digit run is redacted', strpos($clean, '4111 1111 1111 1111') === false);
check('an ordinary reason survives the redactor', stripos(gateway_diag_sanitize('errorCode badTokenRequest'), 'badTokenRequest') !== false);

/* ------------------------------------------------ 2. stage: config ---- */
echo "\n== 2. a disabled gateway is reported as the config stage ==\n";
$prev = [];
foreach (['ngenius_enabled','ngenius_environment','ngenius_outlet_ref','ngenius_api_key','ngenius_realm_name','ngenius_currency'] as $k) {
    $s = $pdo->prepare('SELECT setting_value FROM site_settings WHERE setting_key=?'); $s->execute([$k]);
    $prev[$k] = $s->fetchColumn();
    $pdo->prepare('INSERT INTO site_settings (setting_key,setting_value) VALUES (?,?)
                   ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([$k, '']);
}
$probe = gateway_diag_probe($pdo, ['dry_run' => true]);
check('the probe stops at the config stage', $probe['verdict'] === 'config');
check('a correlation id is returned', gateway_diag_is_correlation_id($probe['correlation_id']));
$row = $pdo->prepare('SELECT * FROM gateway_diagnostics WHERE correlation_id=?');
$row->execute([$probe['correlation_id']]);
$rec = $row->fetch();
check('a diagnostics row was written for the failure', (bool)$rec);
check('the failure row records the stage', ($rec['stage'] ?? '') === 'config');

/* ------------------------------------------------ 3. stage: token + ok ---- */
echo "\n== 3. a configured gateway reaches the token stage ==\n";
$settings = ['ngenius_enabled' => '1', 'ngenius_environment' => 'sandbox',
    'ngenius_outlet_ref' => '00000000-0000-0000-0000-0000000000ff',
    'ngenius_api_key' => 'FIXTURE-NOT-A-REAL-KEY', 'ngenius_realm_name' => '', 'ngenius_currency' => 'SAR'];
foreach ($settings as $k => $v) {
    $pdo->prepare('INSERT INTO site_settings (setting_key,setting_value) VALUES (?,?)
                   ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([$k, $v]);
}
$probe = gateway_diag_probe($pdo, ['dry_run' => true]);
check('the dry-run probe succeeds', !empty($probe['ok']));
check('the probe reports the token stage as reached', in_array('token', array_column($probe['stages'], 'stage'), true));
$probeFull = gateway_diag_probe($pdo, ['dry_run' => false, 'amount' => 1.0]);
check('the full probe creates an unpaid order', !empty($probeFull['ok']) && !empty($probeFull['order_reference']));
check('the full probe never claims a payment', strpos((string)($probeFull['stages'][count($probeFull['stages'])-1]['detail'] ?? ''), 'NOT paid') !== false);

/* ------------------------------------------------ 4. order failure ---- */
echo "\n== 4. an order rejected by the outlet is reported as the order stage ==\n";
// Point the token at the fixture but the outlet at one the fixture rejects, so the failure
// is genuinely in order creation, not auth.
$pdo->prepare("UPDATE site_settings SET setting_value=? WHERE setting_key='ngenius_outlet_ref'")
    ->execute(['BADOUTLET']);
$probe = gateway_diag_probe($pdo, ['dry_run' => false]);
check('the probe stops at the order stage', $probe['verdict'] === 'order');
check('the order stage carries the gateway reason', strpos((string)($probe['stages'][count($probe['stages'])-1]['detail'] ?? ''), 'outletNotAvailable') !== false);
$pdo->prepare("UPDATE site_settings SET setting_value=? WHERE setting_key='ngenius_outlet_ref'")
    ->execute(['00000000-0000-0000-0000-0000000000ff']);

/* ------------------------------------------------ 5. callers surface the ref ---- */
echo "\n== 5. the payment callers return the correlation id ==\n";
$suffix = bin2hex(random_bytes(4));
$email = "exec67-$suffix@example.test";
$pdo->prepare("INSERT INTO users (full_name,email,password_hash,account_type,preferred_lang,email_verified,status,plan_id)
               VALUES (?,?,?,'individual','ar',1,'active',1)")
    ->execute(['Exec67 Payer', $email, password_hash('x', PASSWORD_BCRYPT)]);
$userId = (int)$pdo->lastInsertId();
$plan = $pdo->query("SELECT * FROM plans WHERE status='active' AND price_monthly > 0 ORDER BY id LIMIT 1")->fetch();
$orderId = 0;
if ($plan) {
    $created = package_order_create($pdo, $userId, (int)$plan['id'], 'monthly');
    $orderId = (int)($created['order_id'] ?? 0);
    // A healthy path returns a redirect URL, not a correlation id.
    $ok = package_payment_start($pdo, $created['order'], ['customer_email' => $email, 'customer_name' => 'Exec67 Payer']);
    check('a healthy package payment returns a redirect URL', !empty($ok['ok']) && !empty($ok['redirect_url']));

    // A failing path returns a correlation id a customer can quote.
    $pdo->prepare("UPDATE site_settings SET setting_value='' WHERE setting_key='ngenius_enabled'")->execute();
    $bad = package_payment_start($pdo, $created['order'], ['customer_email' => $email, 'customer_name' => 'Exec67 Payer']);
    check('a failing package payment returns a correlation id', !empty($bad['correlation_id']) && gateway_diag_is_correlation_id($bad['correlation_id']));
    check('the failing package payment names the reason', ($bad['error'] ?? '') === 'no_enabled_gateway');
    $pdo->prepare("UPDATE site_settings SET setting_value='1' WHERE setting_key='ngenius_enabled'");
}

/* ------------------------------------------------ cleanup ---- */
echo "\n== cleanup ==\n";
$pdo->prepare('DELETE FROM payment_transactions WHERE package_order_id=?')->execute([$orderId]);
$pdo->prepare('DELETE FROM package_orders WHERE user_id=?')->execute([$userId]);
$pdo->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);
// Remove every diagnostics row this run created, by its correlation id.
$ids = array_filter([
    $probe['correlation_id'] ?? null,
    $probeFull['correlation_id'] ?? null,
    $bad['correlation_id'] ?? null,
]);
if ($ids) {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $pdo->prepare("DELETE FROM gateway_diagnostics WHERE correlation_id IN ($ph)")->execute(array_values($ids));
}
foreach (['ngenius_enabled','ngenius_environment','ngenius_outlet_ref','ngenius_api_key','ngenius_realm_name','ngenius_currency'] as $k) {
    if ($prev[$k] === false || $prev[$k] === null) {
        $pdo->prepare('DELETE FROM site_settings WHERE setting_key=?')->execute([$k]);
    } else {
        $pdo->prepare('UPDATE site_settings SET setting_value=? WHERE setting_key=?')->execute([$prev[$k], $k]);
    }
}
if (is_resource($proc)) proc_terminate($proc);

echo "\n$checks checks, $failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
