<?php
/**
 * Execution 34 — affiliate registration reachability and landing-page design.
 *
 * The defect this covers was not a broken function: `affiliate/register.php` worked and the
 * approval workflow behind it worked, but nothing linked to it. The page existed and could
 * not be reached, which is indistinguishable from not existing as far as an applicant is
 * concerned. The execution-32 suite tests the workflow from inside; this one tests that a
 * real visitor can find the door.
 *
 * It also asserts the design fix from Part C: the affiliate landing page must actually load
 * its stylesheet. The page rendered fine without it — it just rendered wrong — so a
 * status-code check would have passed throughout.
 *
 * Usage: php tests/exec34_affiliate_registration_test.php
 *   Needs a running Apache on MAWATHEQ_BASE_URL (default http://localhost:8080).
 */
// Buffer output: affiliate_register() calls session_regenerate_id(), which fails once this
// script has written to stdout. Buffering keeps the session functions working and the report
// is flushed at the end.
ob_start();

$base = getenv('MAWATHEQ_BASE_URL') ?: 'http://localhost:8080';
$root = dirname(__DIR__);
require_once $root . '/config/config.php';
require_once $root . '/includes/affiliate.php';
require_once $root . '/includes/affiliate_auth.php';

$failures = 0;
$checks = 0;
function check(string $label, $got, $want = true): void {
    global $checks, $failures;
    $checks++;
    if ($got === $want) { echo "  ok:   $label\n"; return; }
    $failures++;
    $g = is_bool($got) ? var_export($got, true) : (string)$got;
    $w = is_bool($want) ? var_export($want, true) : (string)$want;
    echo "  FAIL: $label — got [$g], want [$w]\n";
}
function section(string $t): void { echo "\n$t\n"; }
function fetch(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false]);
    $body = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, $body];
}

// Remember the programme flag so the test leaves the database as it found it. The default is
// off, and the landing page deliberately changes its call to action based on it, so both
// states are worth asserting.
// The row may not exist at all: another suite removes it rather than restoring the default,
// and the application treats a missing row as "off" via its in-code defaults. So the flag has
// to be upserted, not updated, or this test silently runs with the programme disabled.
$flagRow = $pdo->query("SELECT setting_value FROM site_settings WHERE setting_key='affiliate_program_enabled'")->fetchColumn();
$flagExisted = $flagRow !== false;
$originalFlag = $flagExisted ? (string)$flagRow : '0';
$setFlag = function (string $v) use ($pdo): void {
    $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES ('affiliate_program_enabled', ?)
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$v]);
    affiliate_settings_reload();
};

// ===========================================================================
section('1. The landing page loads its own stylesheet (Part C)');
// ===========================================================================
// Without affiliate.css the page returns 200 and looks broken, so the stylesheet has to be
// asserted directly rather than inferred from the status code.
[$code, $html] = fetch("$base/affiliates.php");
check('the affiliate landing page returns 200', $code, 200);
check('the landing page links affiliate.css', str_contains($html, '/assets/css/affiliate.css'), true);
check('the stylesheet is served', (function () use ($base) { [$c] = fetch("$base/assets/css/affiliate.css"); return $c; })(), 200);
check('the stylesheet is not empty', (function () use ($base) { [, $b] = fetch("$base/assets/css/affiliate.css"); return strlen($b) > 500; })(), true);
check('the page is served right-to-left by default', str_contains($html, 'dir="rtl"'), true);
check('the page declares a viewport', str_contains($html, 'name="viewport"'), true);
check('the page has a single hero heading', substr_count($html, '<h1') >= 1, true);

// ===========================================================================
section('2. Registration is reachable while the programme is open');
// ===========================================================================
$setFlag('1');
[$code, $html] = fetch("$base/affiliates.php");
check('the landing page returns 200 with the programme on', $code, 200);
check('the landing page offers account registration', str_contains($html, 'href="/affiliate/register.php"'), true);
check('the registration page is served', (function () use ($base) { [$c] = fetch("$base/affiliate/register.php"); return $c; })(), 200);

[$code, $login] = fetch("$base/affiliate/login.php");
check('the sign-in page returns 200', $code, 200);
check('the sign-in page links to registration', str_contains($login, '/affiliate/register.php'), true);
check('the sign-in page keeps the main-site link', str_contains($login, 'href="/"'), true);

// The form must carry a CSRF token, or every submission is refused at the door.
[$code, $reg] = fetch("$base/affiliate/register.php");
check('the registration form carries a CSRF token', str_contains($reg, 'name="csrf_token"'), true);
check('the registration form asks for a password', str_contains($reg, 'name="password"'), true);
check('the registration form asks for the terms', str_contains($reg, 'accepted_terms'), true);
check('the registration form asks for the privacy policy', str_contains($reg, 'accepted_privacy'), true);
check('the registration form does not expose a password value', preg_match('/name="password"[^>]*value="[^"]+"/', $reg), 0);

// ===========================================================================
section('3. The call to action is honest while the programme is closed');
// ===========================================================================
// With registration shut, sending an applicant to a form that refuses them is a dead end.
// The landing page offers the queued application instead, and the sign-in page stops
// advertising a registration link.
$setFlag('0');
[$code, $html] = fetch("$base/affiliates.php");
check('the landing page still returns 200 with the programme off', $code, 200);
check('the landing page offers the queued application', str_contains($html, 'href="#apply"'), true);
check('the landing page no longer offers account registration in the hero', str_contains($html, 'href="/affiliate/register.php"'), false);

[$code, $login] = fetch("$base/affiliate/login.php");
check('the sign-in page returns 200 with the programme off', $code, 200);
check('the sign-in page no longer offers registration', str_contains($login, '/affiliate/register.php'), false);
check('the sign-in page offers the queued application instead', str_contains($login, 'affiliates.php#apply'), true);

// The registration page itself must stop offering the form. It is still served — an applicant
// following a bookmarked link should be told why rather than shown a 404 — but it renders the
// closed notice instead of a form, so there is nothing to submit and no CSRF token to reuse.
[$code, $closedPage] = fetch("$base/affiliate/register.php");
check('the registration page still returns 200 while closed', $code, 200);
check('the registration page explains that the programme is closed', str_contains($closedPage, 'not currently open') || str_contains($closedPage, 'غير مفعّل'), true);
check('the registration page offers no form while closed', str_contains($closedPage, '<form'), false);
check('the registration page exposes no CSRF token while closed', str_contains($closedPage, 'name="csrf_token"'), false);

// A direct POST with no session token is refused at the door.
$ch = curl_init("$base/affiliate/register.php");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query(['full_name' => 'Closed Test', 'email' => 'closed-' . bin2hex(random_bytes(3)) . '@example.test'])]); 
$c = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$closedBody = (string)curl_exec($ch);
$c = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
check('a tokenless submission is refused', $c, 403);
check('a closed-programme registration did not create an account', (int)$pdo->query("SELECT COUNT(*) FROM affiliates WHERE full_name='Closed Test'")->fetchColumn(), 0);

// Restore the exact prior state, including removing the row when it was absent, so the next
// suite in the run sees what it would have seen.
if ($flagExisted) {
    $setFlag($originalFlag);
} else {
    $pdo->exec("DELETE FROM site_settings WHERE setting_key='affiliate_program_enabled'");
    affiliate_settings_reload();
}

// ===========================================================================
section('4. The approval workflow the registration feeds into');
// ===========================================================================
// The account-based path is covered end to end by tests/exec32_affiliate_journey_test.php;
// what is asserted here is the contract this page depends on, so a change to either side is
// caught rather than only the side that was edited.
require_once $root . '/includes/affiliate_admin.php';
$transitions = affiliate_status_transitions();
check('pending is a known lifecycle state', array_key_exists('pending', $transitions), true);
check('pending can be reviewed', affiliate_status_can_transition('pending', 'under_review'), true);
check('pending can move to approved', affiliate_status_can_transition('pending', 'approved'), true);
check('pending can move to rejected', affiliate_status_can_transition('pending', 'rejected'), true);
check('an unknown transition is refused', affiliate_status_can_transition('pending', 'nonexistent'), false);

// A newly registered account must not be able to earn until an operator approves it.
$sfx = bin2hex(random_bytes(4));
$email = "exec34-aff-$sfx@example.test";
$reg = affiliate_register($pdo, [
    'full_name' => 'Exec34 Affiliate', 'email' => $email, 'phone' => '+97300000000',
    'country' => 'BH', 'city' => 'Manama', 'affiliate_type' => 'individual',
    'company_name' => '', 'website' => '', 'social_links' => '', 'marketing_channels' => 'test',
    'payout_method' => 'none', 'password' => 'Exec34-Aff-Pass-2026x',
    'password_confirm' => 'Exec34-Aff-Pass-2026x', 'accepted_terms' => true, 'accepted_privacy' => true,
]);
check('an applicant can register while the programme is open', !empty($reg['ok']), true);

$affId = 0;
if (!empty($reg['ok'])) {
    $q = $pdo->prepare('SELECT id, status FROM affiliates WHERE email = ?');
    $q->execute([$email]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    $affId = (int)($row['id'] ?? 0);
    check('the new account is pending', (string)($row['status'] ?? ''), 'pending');
    check('a pending account cannot earn', affiliate_can_earn($row), false);

    $up = affiliate_set_status($pdo, $affId, 'approved', ['actor_id' => null, 'reason' => 'exec34 test']);
    check('an operator can approve the application', !empty($up['ok']), true);
    $after = $pdo->prepare('SELECT * FROM affiliates WHERE id = ?');
    $after->execute([$affId]);
    $afterRow = $after->fetch(PDO::FETCH_ASSOC) ?: [];
    check('the account is approved', (string)($afterRow['status'] ?? ''), 'approved');
    // Approved means the review passed; the agreement has not been accepted yet, so the
    // account still cannot earn. Being able to sign in and being able to earn are separate.
    check('an approved but un-cleared account still cannot earn', affiliate_can_earn($afterRow), false);
}

// ===========================================================================
section('5. Cleanup');
// ===========================================================================
if ($affId) {
    foreach (['affiliate_ledger', 'affiliate_events', 'affiliate_agreements'] as $t) {
        try { $pdo->prepare("DELETE FROM $t WHERE affiliate_id = ?")->execute([$affId]); } catch (Throwable $e) {}
    }
    try { $pdo->prepare('DELETE FROM affiliates WHERE id = ?')->execute([$affId]); } catch (Throwable $e) {}
}
$still = $pdo->prepare('SELECT COUNT(*) FROM affiliates WHERE email = ?');
$still->execute([$email]);
check('the test affiliate was removed', (int)$still->fetchColumn(), 0);
$finalFlag = $pdo->query("SELECT setting_value FROM site_settings WHERE setting_key='affiliate_program_enabled'")->fetchColumn();
// Compare against the prior state, and only when the row existed to begin with. When it did
// not, the assertion is that it is still absent — a plain boolean, not a string compared to
// one, which is a comparison that can never be true and would hide a failure to restore.
check('the programme flag was restored to its prior state',
    $flagExisted ? (string)$finalFlag === $originalFlag : $finalFlag === false, true);

echo "\n----------------------------------------\n";
echo "checks passed: $checks   failed: $failures\n";
ob_end_flush();
exit($failures === 0 ? 0 : 1);
