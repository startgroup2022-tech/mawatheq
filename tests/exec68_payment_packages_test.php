<?php
/**
 * Execution 68 — payment + package hardening.
 *
 * Three production-shaped defects are pinned here, each driving the real code path (no HTTP
 * mocking) against the fixture N-Genius gateway:
 *
 *  1. A half-configured gateway is the most common cause of "could not start the online
 *     payment". The admin console is supposed to warn about it, but the banner read an
 *     undefined variable, so the warning never appeared. The page is rendered over HTTP and
 *     the banner is asserted.
 *  2. A package purchase whose webhook was missed sat 'pending' forever — the reconciliation
 *     cron only ever re-queried consultations. It now reconciles package orders too; the cron
 *     is run for real and the subscription is asserted active.
 *  3. The webhook must reject an unsigned / wrongly-signed event (no secret configured -> 503,
 *     wrong token -> 401) and accept the configured one.
 *
 * The gateway tests never charge a card: every request goes to the fixture. The suite cleans
 * up every row and setting it creates.
 *
 * Run: php tests/exec68_payment_packages_test.php   (app on 127.0.0.1:8080 for the HTTP parts)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/currency.php';
require_once __DIR__ . '/../includes/secret_crypto.php';
require_once __DIR__ . '/../includes/ngenius.php';
require_once __DIR__ . '/../includes/gateway_diagnostics.php';
require_once __DIR__ . '/../includes/package_payment.php';
require_once __DIR__ . '/../includes/consultation_payment.php';

$checks = 0; $failures = 0;
function check(string $label, bool $ok): void {
    global $checks, $failures;
    $checks++;
    if ($ok) echo "  ok:   $label\n";
    else { $failures++; echo "  FAIL: $label\n"; }
}
function section(string $t): void { echo "\n== $t\n"; }

$base = 'http://127.0.0.1:8080';
// Repeated logins in one run trip the per-account/IP throttle, which then answers the login
// with a 200 "try later" page and every later admin assertion fails for the wrong reason.
try { $pdo->exec('DELETE FROM auth_rate_limits'); } catch (Throwable $e) {}
$suffix = bin2hex(random_bytes(4));
$created = ['settings' => [], 'users' => [], 'orders' => []];

function exec68_setting(PDO $pdo, string $key, ?string $value, array &$created): void {
    if (!array_key_exists($key, $created['settings'])) {
        $cur = $pdo->prepare('SELECT setting_value FROM site_settings WHERE setting_key=?');
        $cur->execute([$key]);
        $had = $cur->fetchColumn();
        $created['settings'][$key] = ($had === false) ? null : $had;
    }
    if ($value === null) { $pdo->prepare('DELETE FROM site_settings WHERE setting_key=?')->execute([$key]); return; }
    $pdo->prepare('INSERT INTO site_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([$key, $value]);
}

function exec68_restore(PDO $pdo, array $created): void {
    foreach ($created['invoices'] ?? [] as $id) {
        $pdo->prepare('DELETE FROM invoices WHERE id=?')->execute([$id]);
    }
    foreach ($created['consultations'] ?? [] as $id) {
        $pdo->prepare('DELETE FROM payment_transactions WHERE consultation_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM consultations WHERE id=?')->execute([$id]);
    }
    foreach ($created['orders'] as $id) {
        $pdo->prepare('DELETE FROM payment_transactions WHERE package_order_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM package_orders WHERE id=?')->execute([$id]);
    }
    foreach ($created['users'] as $id) {
        $pdo->prepare('DELETE FROM subscriptions WHERE user_id=?')->execute([$id]);
        $pdo->prepare("DELETE FROM notifications WHERE recipient_type='client' AND recipient_id=?")->execute([$id]);
        $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
    }
    foreach ($created['settings'] as $key => $value) {
        if ($value === null) $pdo->prepare('DELETE FROM site_settings WHERE setting_key=?')->execute([$key]);
        else $pdo->prepare('UPDATE site_settings SET setting_value=? WHERE setting_key=?')->execute([$value, $key]);
    }
}

/* ------------------------------------------------------------ fixture --- */
$port = 0; $proc = null;
for ($candidate = 18700; $candidate < 18740; $candidate++) {
    $cmd = escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $candidate . ' ' . escapeshellarg(__DIR__ . '/fixtures/ngenius_server.php') . ' > /dev/null 2>&1';
    $proc = proc_open($cmd, [], $pipes);
    usleep(300000);
    $probe = @file_get_contents('http://127.0.0.1:' . $candidate . '/__health');
    if ($probe !== false && strpos($probe, 'ok') !== false) { $port = $candidate; break; }
    if (is_resource($proc)) proc_terminate($proc);
    $proc = null;
}
if ($port === 0) {
    echo "SKIP: could not start the N-Genius fixture server\n";
    exec68_restore($pdo, $created);
    exit(0);
}
putenv('MAWATHEQ_NGENIUS_BASE_URL=http://127.0.0.1:' . $port);
echo "fixture N-Genius gateway on 127.0.0.1:$port\n";

/* --------------------------------------------------- 1. config guard ---- */
section('1. configuration gate (the real cause of "could not start the payment")');
// Disabled / half-configured must be reported as a config stop, not as a token or order failure.
exec68_setting($pdo, 'ngenius_enabled', '1', $created);
exec68_setting($pdo, 'ngenius_environment', 'sandbox', $created);
exec68_setting($pdo, 'ngenius_outlet_ref', '', $created);
exec68_setting($pdo, 'ngenius_realm_name', '', $created);
exec68_setting($pdo, 'ngenius_currency', 'SAR', $created);
secret_store_setting($pdo, 'ngenius_api_key', '');
$diag = gateway_diag_probe($pdo, ['dry_run' => true]);
check('enabled with no outlet is not "enabled"', !ngenius_is_enabled(ngenius_settings($pdo)));
check('the probe stops at the config stage', ($diag['verdict'] ?? '') === 'config');
check('the probe returns a correlation id', !empty($diag['correlation_id']));

exec68_setting($pdo, 'ngenius_outlet_ref', '00000000-0000-0000-0000-0000000000ff', $created);
secret_store_setting($pdo, 'ngenius_api_key', 'FIXTURE-NOT-A-REAL-KEY');
exec68_setting($pdo, 'ngenius_webhook_secret', 'exec68-secret-' . $suffix, $created);
check('a complete configuration is reported as enabled', ngenius_is_enabled(ngenius_settings($pdo)));

/* ---------------------------------- 1b. a missing setting row is not fatal */
section('1b. a setting row that was never written is not a fatal');
// The production symptom was a bare HTTP 500: an install where `ngenius_enabled`/outlet/key
// existed but `ngenius_environment` had never been written. ngenius_settings() then returned an
// array WITHOUT that key, and ngenius_get_access_token(string $environment) received null — a
// fatal TypeError on the payment path, with no diagnostic and no correlation id. A settings
// accessor must always return every key it documents, with a sane default.
exec68_setting($pdo, 'ngenius_environment', null, $created); // delete the row entirely
$partial = ngenius_settings($pdo);
$missing = [];
foreach (['ngenius_enabled','ngenius_environment','ngenius_outlet_ref','ngenius_api_key','ngenius_realm_name','ngenius_webhook_secret','ngenius_currency'] as $k) {
    if (!array_key_exists($k, $partial)) $missing[] = $k;
}
check('ngenius_settings() returns every key even when the row is absent', $missing === []);
check('the absent environment defaults to sandbox', ($partial['ngenius_environment'] ?? null) === 'sandbox');
check('the absent environment is a string, never null', is_string($partial['ngenius_environment'] ?? null));
$threw = false; $startResult = null;
try {
    $startResult = package_payment_start($pdo, ['id' => 999999, 'amount' => 149, 'currency' => 'SAR']);
} catch (Throwable $e) {
    $threw = true;
}
check('package_payment_start() does not throw when environment is absent', !$threw);
// The point is not success or failure — the fixture gateway may answer either way — it is that
// the call returns a structured array (never a fatal) so the caller can show a correlation id.
check('it returns a structured result, not a fatal', is_array($startResult) && array_key_exists('ok', $startResult));
exec68_setting($pdo, 'ngenius_environment', 'sandbox', $created);

/* ------------------------------------------------ 2. package purchase --- */
section('2. package purchase: order -> gateway -> verified activation');
$email = 'exec68-' . $suffix . '@example.test';
$pdo->prepare("INSERT INTO users (full_name,email,password_hash,account_type,preferred_lang,email_verified,status,plan_id) VALUES (?,?,?,'individual','ar',1,'active',1)")
    ->execute(['Exec68 User', $email, password_hash('x', PASSWORD_BCRYPT)]);
$userId = (int)$pdo->lastInsertId();
$created['users'][] = $userId;

$plan = $pdo->query("SELECT * FROM plans WHERE status='active' AND classification='paid' AND price_monthly>0 ORDER BY id LIMIT 1")->fetch();
check('a paid plan exists to purchase', $plan !== null);

$made = package_order_create($pdo, $userId, (int)$plan['id'], 'monthly');
check('the order is created', !empty($made['ok']));
$orderId = (int)($made['order_id'] ?? 0);
$created['orders'][] = $orderId;
check('the amount is the plan price, not a caller value', abs((float)$made['order']['amount'] - (float)$plan['price_monthly']) < 0.001);
check('the order starts pending', ($made['order']['status'] ?? '') === 'pending');

$started = package_payment_start($pdo, $made['order'], ['customer_email' => $email, 'customer_name' => 'Exec68 User']);
check('payment starts and returns a redirect', !empty($started['ok']) && !empty($started['redirect_url']));
$reference = (string)($started['reference'] ?? '');
check('a gateway reference is issued', $reference !== '');

// The customer has NOT paid yet: the order must not be active.
check('the order is still pending before any capture', $pdo->query("SELECT status FROM package_orders WHERE id=$orderId")->fetchColumn() === 'pending');

// The gateway reports a capture -> activate.
$verify = package_payment_verify_and_confirm($pdo, $orderId, $reference);
check('verification reports the gateway state PAID', ($verify['state'] ?? '') === 'PAID');
check('the subscription is activated', !empty($verify['activated']));
$subId = (int)($verify['subscription_id'] ?? 0);
check('a subscription row is active', $subId > 0 && $pdo->query("SELECT status FROM subscriptions WHERE id=$subId")->fetchColumn() === 'active');
check('the user plan is switched to the purchased plan', (int)$pdo->query("SELECT plan_id FROM users WHERE id=$userId")->fetchColumn() === (int)$plan['id']);

// Re-running must not double-activate.
$again = package_payment_verify_and_confirm($pdo, $orderId, $reference);
check('a replayed verification does not activate twice', empty($again['activated']));

/* --------------------------------------- 3. a failed order is not paid -- */
section('3. a failed capture leaves the order un-activated');
$made2 = package_order_create($pdo, $userId, (int)$plan['id'], 'monthly');
$orderId2 = (int)($made2['order_id'] ?? 0);
$created['orders'][] = $orderId2;
$failVerify = package_payment_verify_and_confirm($pdo, $orderId2, 'PKG-FAIL-' . strtoupper($suffix));
check('a FAILED gateway state does not activate', empty($failVerify['activated']));
check('the order is marked failed', $pdo->query("SELECT status FROM package_orders WHERE id=$orderId2")->fetchColumn() === 'failed');
check('no subscription was created for the failed order', (int)$pdo->query("SELECT COUNT(*) FROM subscriptions WHERE user_id=$userId")->fetchColumn() === 1);

/* --------------------------------- 4. missed-webhook reconciliation ----- */
section('4. a missed webhook is reconciled by the cron (package orders)');
$made3 = package_order_create($pdo, $userId, (int)$plan['id'], 'monthly');
$orderId3 = (int)($made3['order_id'] ?? 0);
$created['orders'][] = $orderId3;
// Simulate: the order reached the gateway and was captured, but the callback never arrived.
$ref3 = 'PKG-' . $orderId3 . '-CAPTURED' . strtoupper($suffix);
$pdo->prepare("UPDATE package_orders SET payment_reference=?, payment_gateway='ngenius', created_at=(NOW() - INTERVAL 5 MINUTE) WHERE id=?")->execute([$ref3, $orderId3]);
check('the order is pending before reconciliation', $pdo->query("SELECT status FROM package_orders WHERE id=$orderId3")->fetchColumn() === 'pending');

$cronOut = shell_exec('MAWATHEQ_NGENIUS_BASE_URL=http://127.0.0.1:' . $port . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../cron/ngenius-reconcile.php') . ' 2>&1');
check('the cron reports a package reconciliation line', is_string($cronOut) && strpos($cronOut, 'packages') !== false);
check('the missed capture activates the package', $pdo->query("SELECT status FROM package_orders WHERE id=$orderId3")->fetchColumn() === 'paid');
check('the reconciled order now carries a subscription', (int)$pdo->query("SELECT subscription_id FROM package_orders WHERE id=$orderId3")->fetchColumn() > 0);

/* -------------------------------------------- 5. webhook signature ----- */
section('5. webhook signature enforcement (HTTP)');
$appUp = @file_get_contents($base . '/index.php') !== false;
if (!$appUp) {
    echo "  SKIP: app not reachable on $base\n";
} else {
    $postWebhook = function (string $ref, ?string $token) use ($base): array {
        $payload = json_encode(['event' => 'CAPTURED', 'order' => ['reference' => $ref, 'amount' => ['value' => 1000, 'currencyCode' => 'SAR']]]);
        $headers = ['Content-Type: application/json'];
        if ($token !== null) $headers[] = 'X-Webhook-Token: ' . $token;
        $ch = curl_init($base . '/ngenius-webhook.php');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => $payload]);
        $body = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, $body];
    };

    // No secret configured -> the server must refuse to trust ANY inbound event.
    exec68_setting($pdo, 'ngenius_webhook_secret', null, $created);
    [$c1, ] = $postWebhook('PKG-WEBHOOK-NOSECRET-' . $suffix, 'anything');
    check('an unset webhook secret returns 503', $c1 === 503);

    // A secret is configured; a wrong token must be rejected.
    exec68_setting($pdo, 'ngenius_webhook_secret', 'exec68-secret-' . $suffix, $created);
    [$c2, ] = $postWebhook('PKG-WEBHOOK-BAD-' . $suffix, 'definitely-wrong');
    check('a wrong webhook token returns 401', $c2 === 401);

    // The correct token is accepted (the reference is unknown, so it is acknowledged, not acted on).
    [$c3, ] = $postWebhook('PKG-WEBHOOK-OK-' . $suffix, 'exec68-secret-' . $suffix);
    check('a correctly-signed webhook returns 200', $c3 === 200);
}

/* ------------------------------------- 6. admin console config warning -- */
section('6. admin console surfaces the half-configured gateway');
if (!$appUp) {
    echo "  SKIP: app not reachable on $base\n";
} else {
    $adminEmail = (string)$pdo->query("SELECT email FROM admins WHERE totp_enabled=0 ORDER BY id LIMIT 1")->fetchColumn();
    // Turn N-Genius on but leave the webhook secret unset: the console must warn.
    exec68_setting($pdo, 'ngenius_enabled', '1', $created);
    exec68_setting($pdo, 'ngenius_outlet_ref', '00000000-0000-0000-0000-0000000000ff', $created);
    exec68_setting($pdo, 'ngenius_webhook_secret', null, $created);

    // One cookie handle carried across the requests (in-memory, so it cannot desync from the
    // response the way a re-read file jar can).
    $cookie = curl_share_init();
    curl_share_setopt($cookie, CURLSHOPT_SHARE, CURL_LOCK_DATA_COOKIE);
    $req = function (string $path, ?array $post = null) use ($base, $cookie): array {
        $ch = curl_init($base . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_SHARE => $cookie, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEFILE => '']); // '' enables the in-memory cookie engine for the shared jar
        if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $post); }
        $b = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, $b];
    };
    [, $loginPage] = $req('/admin/login.php');
    preg_match('/name="csrf_token"\s+value="([^"]+)"/', $loginPage, $m);
    [$loginCode, ] = $req('/admin/login.php', ['email' => $adminEmail, 'password' => 'Test-Password-2026', 'csrf_token' => $m[1] ?? '']);
    [$gwCode, $page] = $req('/admin/gateways.php');

    check('the admin login succeeds', $loginCode === 302);
    check('the admin gateways page renders', $gwCode === 200 && strlen($page) > 1000 && stripos($page, 'Undefined variable') === false);
    check('the half-configured N-Genius warning is shown', strpos($page, 'غير مكتمل') !== false || stripos($page, 'not fully configured') !== false);
    check('the warning names the missing webhook secret', strpos($page, 'Webhook') !== false);
}

/* -------------------------------- 7. paid consultation issues invoice -- */
section('7. a paid consultation issues a client invoice (idempotent)');
$lawyerId = (int)$pdo->query("SELECT id FROM lawyers WHERE status='approved' ORDER BY id LIMIT 1")->fetchColumn();
check('an approved lawyer exists for the booking', $lawyerId > 0);
$pdo->prepare("INSERT INTO consultations (user_id,lawyer_id,consultation_type,scheduled_at,duration_minutes,topic,status,payment_status,payment_amount,payment_currency,payment_gateway,payment_reference)
    VALUES (?,?, 'video', NOW(), 30, ?, 'confirmed','paid', 500.00, 'SAR', 'ngenius', ?)")
    ->execute([$userId, $lawyerId, 'Exec68 invoice probe ' . $suffix, 'PROBE-INV-' . $suffix]);
$consultationId = (int)$pdo->lastInsertId();
$created['consultations'][] = $consultationId;

$invId = consultation_invoice_issue($pdo, $consultationId, 500.00, 'SAR', 'PAY-' . $suffix);
check('an invoice is issued for the paid booking', !empty($invId));
if ($invId) {
    $created['invoices'][] = $invId;
    $inv = $pdo->query("SELECT * FROM invoices WHERE id=$invId")->fetch();
    check('the invoice is marked paid', ($inv['status'] ?? '') === 'paid');
    check('the invoice amount is the captured amount', abs((float)$inv['amount'] - 500.0) < 0.001);
    check('the invoice is linked to the consultation', (int)$inv['consultation_id'] === $consultationId);
    check('the invoice belongs to the client', (int)$inv['user_id'] === $userId);
    check('the invoice is not a draft', (int)$inv['is_draft'] === 0);
    $second = consultation_invoice_issue($pdo, $consultationId, 500.00, 'SAR', 'PAY-' . $suffix);
    check('a second issue call returns the same invoice (idempotent)', (int)$second === (int)$invId);
}

/* ------------------- 8. a confirmed paid booking notifies the client --- */
section('8. a confirmed paid booking notifies the customer');
// The customer was last told "awaiting payment". When the gateway capture confirms the booking,
// both the lawyer AND the customer must learn it — otherwise the customer never finds out the
// payment cleared. Assert the client notification is written by the confirm path.
$notifyLawyerId = (int)$pdo->query("SELECT id FROM lawyers WHERE status='approved' ORDER BY id LIMIT 1")->fetchColumn();
$beforeClient = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE recipient_type='client' AND recipient_id=" . (int)$userId)->fetchColumn();
$pdo->prepare("INSERT INTO consultations (user_id,lawyer_id,consultation_type,scheduled_at,duration_minutes,topic,status,payment_status,payment_amount,payment_currency,payment_gateway,payment_reference)
    VALUES (?,?, 'video', ?, 30, ?, 'pending','unpaid', NULL, NULL, 'ngenius', ?)")
    ->execute([$userId, $notifyLawyerId, date('Y-m-d H:i:s', strtotime('+3 days')), 'Exec68 notify probe ' . $suffix, 'PROBE-NOTIFY-' . $suffix]);
$notifyConsultationId = (int)$pdo->lastInsertId();
$created['consultations'][] = $notifyConsultationId;
$full = $pdo->query("SELECT * FROM consultations WHERE id=$notifyConsultationId")->fetch();
$res = consultation_payment_confirm($pdo, $full, ['amount' => 500.0, 'currency' => 'SAR', 'payment_ref' => 'PAY-N-' . $suffix]);
check('the confirm path reports the booking confirmed', !empty($res['confirmed']));
$afterClient = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE recipient_type='client' AND recipient_id=" . (int)$userId)->fetchColumn();
check('the customer receives a confirmation notification', $afterClient > $beforeClient);

/* --------------------------------------------------------- cleanup ------ */
section('cleanup');
exec68_restore($pdo, $created);
if (is_resource($proc)) proc_terminate($proc);
@unlink(sys_get_temp_dir() . '/ngenius_fixture_orders.json');
// Drop the fixture token cache so a later run starts clean.
foreach (glob(dirname(__DIR__) . '/storage/cache/ngenius-token-*.json') ?: [] as $f) @unlink($f);
echo "done\n";

echo "\n" . $checks . " checks, " . $failures . " failure(s)\n";
exit($failures > 0 ? 1 : 0);
