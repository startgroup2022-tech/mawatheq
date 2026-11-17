<?php
/**
 * Execution 76 — two defects found by exercising the running app, not by reading it.
 *
 *   1. `booking.php` answered HTTP 500 for a missing/unknown lawyer. The "lawyer not
 *      found" branch echoed a body and then included `includes/app_footer.php` WITHOUT
 *      first including `app_header.php`, and the footer calls `nav_render_bottom()` from
 *      `includes/nav.php` — a file the header loads. The result was a fatal "Call to
 *      undefined function" that HTTP 200 hid behind an 88-byte body that looked like a
 *      plain message. The branch now renders through the shell.
 *
 *   2. The customer-facing payment-failure sentence was one generic string for every
 *      cause, so a permanent fault (a currency mismatch, the gateway switched off)
 *      invited a retry loop that could never succeed. `gateway_diag_customer_message()`
 *      now chooses the sentence by the structured failure code: permanent faults point
 *      at support, transient ones still offer a retry, and every message keeps the
 *      correlation id.
 *
 * Runs against the test database and the dev server on 127.0.0.1:8080:
 *   php tests/exec76_payment_booking_regression_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/gateway_diagnostics.php';

$passed = 0;
$failed = 0;
function check(string $label, bool $condition): void {
    global $passed, $failed;
    if ($condition) { $passed++; echo "  ok:   $label\n"; }
    else { $failed++; echo "  FAIL: $label\n"; }
}

/* --------------------------------------------------- 1. the message helper ---- */
echo "\n== 1. a permanent fault does not invite a retry ==\n";

$permanent = ['unsupported_currency', 'no_enabled_gateway', 'gateway_disabled', 'migration_required', 'zero_amount', 'badTokenRequest', 'realmNameNotAvailable', 'outletNotAvailable'];
foreach ($permanent as $code) {
    $msg = gateway_diag_customer_message($code, 'ABCD1234', true);
    check("$code: says no amount was charged", strpos($msg, 'لم يتم خصم') !== false);
    check("$code: points at support, not a retry", strpos($msg, 'تواصل معنا') !== false && strpos($msg, 'المحاولة مجدداً') === false);
    check("$code: keeps the reference", strpos($msg, 'ABCD1234') !== false);
}

$transient = ['token_failed', 'order_failed', 'something_unmapped'];
foreach ($transient as $code) {
    $msg = gateway_diag_customer_message($code, 'EF567890', true);
    check("$code: offers a retry", strpos($msg, 'المحاولة مجدداً') !== false);
    check("$code: keeps the reference", strpos($msg, 'EF567890') !== false);
}

$noRef = gateway_diag_customer_message('unsupported_currency', '', true);
check('a message without a reference has no dangling "الرقم المرجعي"', strpos($noRef, 'الرقم المرجعي') === false);

$en = gateway_diag_customer_message('unsupported_currency', 'ABCD1234', false);
check('the English message is English', strpos($en, 'Online payment is not available') !== false && strpos($en, 'Reference:') !== false);

/* --------------------------------------- 2. the callers actually use it ---- */
echo "\n== 2. every payment entry page routes through the helper ==\n";
foreach (['checkout.php', 'pay-invoice.php', 'booking.php'] as $file) {
    $src = file_get_contents(__DIR__ . '/../' . $file);
    check("$file references gateway_diag_customer_message()", strpos($src, 'gateway_diag_customer_message') !== false);
}

/* ------------------------------- 2b. a rejected credential is permanent ---- */
echo "\n== 2b. the gateway's own error code is classified ==\n";
// The token helper must carry the gateway's own reason so a rejected credential can be
// classified as a permanent fault. The code itself is asserted against the fixture in
// exec64; here the classification of the known reasons is pinned.
foreach (['badTokenRequest', 'realmNameNotAvailable'] as $code) {
    $msg = gateway_diag_customer_message($code, 'ABCD1234', true);
    check("$code: classified as permanent (no retry offered)", strpos($msg, 'المحاولة مجدداً') === false);
}

/* ------------------------------------------ 3. the booking page answers 200 ---- */
echo "\n== 3. booking.php renders for an unknown lawyer instead of dying ==\n";

// The page is reached as a signed-in client. Sign in over HTTP so the real guard runs.
$base = getenv('MAWATHEQ_BASE_URL') ?: 'http://127.0.0.1:8080';
$jar = tempnam(sys_get_temp_dir(), 'exec76jar');

function http_get(string $url, string $jar): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 10,
    ]);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}
function http_post(string $url, array $fields, string $jar): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_TIMEOUT => 10,
    ]);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}

// Seed a known client and clear the login throttle, matching the other suites.
$email = 'exec76-' . bin2hex(random_bytes(4)) . '@example.test';
$pwd = 'Exec76-Password-2026';
$pdo->prepare("INSERT INTO users (full_name,email,password_hash,account_type,preferred_lang,email_verified,status,plan_id)
               VALUES (?,?,?,'individual','ar',1,'active',1)")
    ->execute(['Exec76 Client', $email, password_hash($pwd, PASSWORD_BCRYPT)]);
$userId = (int)$pdo->lastInsertId();
try { $pdo->exec('DELETE FROM auth_rate_limits'); } catch (Throwable $e) {}

[$c, $b] = http_get("$base/login.php", $jar);
preg_match('/name="csrf_token" value="([^"]+)"/', $b, $m);
[$c, $b] = http_post("$base/login.php", ['csrf_token' => $m[1] ?? '', 'email' => $email, 'password' => $pwd], $jar);
check('the seeded client signs in', $c === 302);

[$c, $b] = http_get("$base/booking.php?lawyer_id=999999", $jar);
check('an unknown lawyer id answers 200, not 500', $c === 200);
check('the page explains the lawyer is unavailable', strpos($b, 'غير متاح للحجز') !== false || strpos($b, 'not available for booking') !== false);
check('the page renders the full shell (has the nav toggle)', strpos($b, 'data-app-nav-toggle') !== false || strpos($b, 'app-navtoggle') !== false);
check('no uncaught fatal leaked into the body', stripos($b, 'Fatal error') === false && stripos($b, 'undefined function') === false);

[$c, $b] = http_get("$base/booking.php", $jar);
check('a booking page with no lawyer id also answers 200', $c === 200);

/* --------------------------------- 4. packages are DB-backed, end to end ---- */
echo "\n== 4. packages/pricing/subscription render the live plans table ==\n";

$plans = $pdo->query("SELECT id,name_ar,price_monthly FROM plans WHERE status='active' ORDER BY sort_order")->fetchAll();
check('the database has active plans to render', count($plans) >= 1);

[$c, $b] = http_get("$base/pricing.php", $jar);
check('pricing.php answers 200', $c === 200);
$allNamed = true;
foreach ($plans as $p) { if (strpos($b, htmlspecialchars($p['name_ar'])) === false) { $allNamed = false; break; } }
check('every active plan name appears on the public pricing page', $allNamed);

foreach (['packages.php', 'subscription.php'] as $page) {
    [$c, $b] = http_get("$base/$page", $jar);
    check("$page answers 200", $c === 200);
    check("$page shows a plan name from the database", strpos($b, htmlspecialchars($plans[0]['name_ar'])) !== false);
    check("$page exposes exactly one <h1>", substr_count(strtolower($b), '<h1') === 1);
}

// A free plan activates with no gateway; a paid plan creates a pending order whose
// amount is read from `plans` — never from the browser.
$free = null; $paid = null;
foreach ($plans as $p) {
    if ((float)$p['price_monthly'] == 0.0 && $free === null) $free = $p;
    elseif ((float)$p['price_monthly'] > 0 && $paid === null) $paid = $p;
}
if ($free) {
    [$c, $b] = http_get("$base/checkout.php", $jar);
    preg_match_all('/name="csrf_token" value="([^"]+)"/', $b, $mm);
    [$c, $b] = http_post("$base/checkout.php", ['csrf_token' => $mm[1][0] ?? '', 'action' => 'checkout', 'plan_id' => $free['id'], 'cycle' => 'monthly'], $jar);
    check('choosing the free plan redirects to the catalogue', $c === 302);
    $active = (int)$pdo->query("SELECT COUNT(*) FROM subscriptions WHERE user_id=$userId AND plan_id=" . (int)$free['id'] . " AND status='active'")->fetchColumn();
    check('the free plan activated an active subscription row', $active === 1);
}
if ($paid) {
    [$c, $b] = http_get("$base/checkout.php", $jar);
    preg_match_all('/name="csrf_token" value="([^"]+)"/', $b, $mm);
    [$c, $b] = http_post("$base/checkout.php", ['csrf_token' => $mm[1][0] ?? '', 'action' => 'checkout', 'plan_id' => $paid['id'], 'cycle' => 'monthly'], $jar);
    check('choosing a paid plan redirects to its order page', $c === 302);
    $order = $pdo->query("SELECT amount,currency,status FROM package_orders WHERE user_id=$userId AND plan_id=" . (int)$paid['id'] . " ORDER BY id DESC LIMIT 1")->fetch();
    check('the order amount is read from the plans table', $order && (float)$order['amount'] == (float)$paid['price_monthly']);
    check('the order stays pending until the gateway confirms', $order && $order['status'] === 'pending');
}

/* --------------------------------------------------- cleanup ---- */
echo "\n== cleanup ==\n";
$pdo->prepare('DELETE FROM package_orders WHERE user_id=?')->execute([$userId]);
$pdo->prepare('DELETE FROM subscriptions WHERE user_id=?')->execute([$userId]);
$pdo->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);
try { $pdo->exec('DELETE FROM auth_rate_limits'); } catch (Throwable $e) {}
@unlink($jar);
echo "removed test client #$userId\n";

echo "\n------------------------------------------------------------\n";
echo "checks: $passed   failures: $failed\n";
exit($failed === 0 ? 0 : 1);
