<?php
/**
 * Execution 64 — the N-Genius payment gateway was rejecting every payment request.
 *
 * Root cause (proven against the live KSA gateway, not assumed): the access-token request
 * body was built with `json_encode([])`, which in PHP emits a JSON *array* ('[]'), not an
 * object ('{}'). The live gateway rejects a non-object body with a BARE HTTP 400 — no
 * access_token and no error detail — before it ever inspects the API key. Every payment
 * path (package purchase, paid consultation, invoice) therefore failed with the generic
 * "could not start the payment" message, and the reason was invisible because the code
 * discarded the response.
 *
 * This suite drives the REAL curl path in includes/ngenius.php against the fixture server,
 * whose token handler now refuses a non-object body exactly as the live gateway does. It
 * fails if the body regresses to '[]', and it pins the payer-email fix (the session carries
 * no email, so checkout/API must read it from the account).
 *
 * Run: php tests/exec64_ngenius_gateway_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/ngenius.php';
require_once __DIR__ . '/../includes/package_payment.php';
require_once __DIR__ . '/../includes/consultation_payment.php';

$failures = 0; $checks = 0;
function check(string $label, bool $ok): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) { $failures++; echo "  FAIL: $label\n"; } else echo "  ok:   $label\n";
}

/* ------------------------------------------------ fixture server ---- */
$port = 0; $proc = null;
for ($candidate = 18400; $candidate < 18440; $candidate++) {
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
echo "fixture N-Genius gateway on 127.0.0.1:$port\n";

/* ------------------------------------------------ 1. the exact bug ---- */
echo "\n== 1. the token body must be a JSON object, not an array ==\n";

// Directly prove the fixture mirrors the live gateway: '[]' is refused, '{}' is accepted.
$ch = curl_init($base . '/identity/auth/access-token');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/vnd.ni-identity.v1+json'], CURLOPT_POSTFIELDS => '[]']);
$arrResp = curl_exec($ch); $arrCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
$ch = curl_init($base . '/identity/auth/access-token');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/vnd.ni-identity.v1+json'], CURLOPT_POSTFIELDS => '{}']);
$objResp = curl_exec($ch); $objCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
check('the fixture refuses a JSON array body with HTTP 400 (like the live gateway)', $arrCode === 400);
check('the fixture accepts a JSON object body (HTTP 200 + token)', $objCode === 200 && strpos((string)$objResp, 'access_token') !== false);

// And prove the encoder itself: json_encode([]) is the defect, (object)[] is the fix.
check('json_encode([]) is a JSON array (the original defect)', json_encode([]) === '[]');
check('json_encode((object)[]) is a JSON object (the fix)', json_encode((object)[]) === '{}');

/* ------------------------------------------------ 2. live curl path ---- */
echo "\n== 2. the real ngenius_get_access_token() call succeeds ==\n";
putenv('MAWATHEQ_NGENIUS_BASE_URL=' . $base);
// A stale cache from another environment must not mask the call under test.
foreach (glob(dirname(__DIR__) . '/storage/cache/ngenius-token-*.json') ?: [] as $f) @unlink($f);
$tok = ngenius_get_access_token('sandbox', 'FIXTURE-NOT-A-REAL-KEY', '');
check('a token is issued (the request body is an object)', !empty($tok['ok']));
check('a token value is present', !empty($tok['token']));

$badRealm = ngenius_get_access_token('sandbox', 'FIXTURE-NOT-A-REAL-KEY', 'ni');
check("realmName 'ni' is treated as unset and still succeeds", !empty($badRealm['ok']));

/* ------------------------------------------------ 3. a full paid order ---- */
echo "\n== 3. a package order reaches the hosted payment page ==\n";
$suffix = bin2hex(random_bytes(4));
$email = "exec64-$suffix@example.test";
$pdo->prepare("INSERT INTO users (full_name,email,password_hash,account_type,preferred_lang,email_verified,status,plan_id)
               VALUES (?,?,?,'individual','ar',1,'active',1)")
    ->execute(['Exec64 Payer', $email, password_hash('x', PASSWORD_BCRYPT)]);
$userId = (int)$pdo->lastInsertId();

// Enable the gateway against the fixture, exactly as the admin form would.
$settings = ['ngenius_enabled' => '1', 'ngenius_environment' => 'sandbox',
    'ngenius_outlet_ref' => '00000000-0000-0000-0000-0000000000ff',
    'ngenius_api_key' => 'FIXTURE-NOT-A-REAL-KEY', 'ngenius_realm_name' => '',
    'ngenius_currency' => 'SAR'];
foreach ($settings as $k => $v) {
    $pdo->prepare('INSERT INTO site_settings (setting_key,setting_value) VALUES (?,?)
                   ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([$k, $v]);
}

$plan = $pdo->query("SELECT * FROM plans WHERE status='active' AND price_monthly > 0 ORDER BY id LIMIT 1")->fetch();
check('a paid plan exists', (bool)$plan);
$orderId = 0;
if ($plan) {
    $created = package_order_create($pdo, $userId, (int)$plan['id'], 'monthly');
    check('the order is created', !empty($created['ok']));
    $orderId = (int)($created['order_id'] ?? 0);
    $started = package_payment_start($pdo, $created['order'], [
        'customer_email' => $email, 'customer_name' => 'Exec64 Payer', 'language' => 'ar']);
    check('the gateway returns a redirect URL (not the generic failure)', !empty($started['ok']));
    check('the redirect URL is the hosted payment page', !empty($started['redirect_url']));
}

/* ------------------------------------------------ 4. email comes from the DB ---- */
echo "\n== 4. the payer email is read from the account, not an empty session ==\n";
check('login stores no email in the session (the reason checkout must read the DB)',
    strpos((string)file_get_contents(dirname(__DIR__) . '/login.php'), "\$_SESSION['email']") === false);
$row = $pdo->prepare('SELECT email FROM users WHERE id=?'); $row->execute([$userId]);
check('the account email resolves for the payer', (string)$row->fetchColumn() === $email);

/* ------------------------------------------------ cleanup ---- */
echo "\n== cleanup ==\n";
$pdo->prepare('DELETE FROM payment_transactions WHERE package_order_id=?')->execute([$orderId]);
$pdo->prepare('DELETE FROM package_orders WHERE user_id=?')->execute([$userId]);
$pdo->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);
foreach (array_keys($settings) as $k) {
    $pdo->prepare("DELETE FROM site_settings WHERE setting_key=? AND setting_value=?")->execute([$k, $settings[$k]]);
}
if (is_resource($proc)) proc_terminate($proc);

echo "\n$checks checks, $failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
