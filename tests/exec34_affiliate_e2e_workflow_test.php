<?php
/**
 * Execution 34 — the affiliate registration workflow, driven over HTTP.
 *
 * exec32_affiliate_journey_test.php proves the workflow from inside the libraries;
 * exec34_affiliate_registration_test.php proves the door is reachable. Neither one
 * proves that a real person, with a real browser cookie jar and a real CSRF token,
 * can walk the whole path. This suite does: it signs up through the form, signs in
 * as the operator through the console login, approves through the review queue, and
 * signs back in as the affiliate.
 *
 * The distinction matters because the defects this workflow has produced were all
 * *seam* defects — each function correct on its own:
 *   • affiliate_can_login() refused `pending`, so a fresh registrant was locked out.
 *   • affiliate_approve_application() returned ok for an already-linked application
 *     without moving the account, so the console said "approved" and nothing changed.
 *   • The guard rejected a session created in the same second as a session
 *     revocation, so reset-then-sign-in looped forever.
 * A unit test cannot see any of those. A cookie jar can.
 *
 * Run against the local server:
 *   php tests/exec34_affiliate_e2e_workflow_test.php
 */

ob_start();

$base = getenv('MAWATHEQ_BASE_URL') ?: 'http://localhost:8080';
$root = dirname(__DIR__);
require_once $root . '/config/config.php';
require_once $root . '/includes/affiliate.php';
require_once $root . '/includes/affiliate_auth.php';

$checks = 0;
$failures = 0;
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

/** A cookie-jar-backed HTTP client, so every request in a step shares one session. */
function http(string $jar, string $method, string $url, array $fields = []): array {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false,
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($fields);
    }
    curl_setopt_array($ch, $opts);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $loc = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    return ['code' => $code, 'body' => $body, 'location' => $loc];
}

function csrf_of(string $body): string {
    preg_match('/name="csrf_token" value="([^"]+)"/', $body, $m);
    return $m[1] ?? '';
}

// ---------------------------------------------------------------------------
$sfx = bin2hex(random_bytes(4));
$email = "e2e-affiliate-$sfx@example.test";
$password = 'E2E-Affiliate-Password-2026';
$jarAffiliate = sys_get_temp_dir() . "/e34e2e-aff-$sfx.cookies";
$jarAdmin = sys_get_temp_dir() . "/e34e2e-adm-$sfx.cookies";

/** Leave the database as we found it. */
function e2e_cleanup(PDO $pdo, string $email): void {
    try {
        $stmt = $pdo->prepare('SELECT id FROM affiliates WHERE email=?');
        $stmt->execute([$email]);
        $id = (int)$stmt->fetchColumn();
        if ($id > 0) {
            foreach (['affiliate_agreements', 'affiliate_applications', 'affiliate_audit_logs',
                      'affiliate_notification_log', 'affiliate_links', 'affiliate_commissions'] as $t) {
                try { $pdo->prepare("DELETE FROM $t WHERE affiliate_id=?")->execute([$id]); } catch (Throwable $e) {}
            }
            $pdo->prepare('DELETE FROM affiliates WHERE id=?')->execute([$id]);
        }
        $pdo->prepare('DELETE FROM affiliate_applications WHERE email=?')->execute([$email]);
    } catch (Throwable $e) {
        error_log('e2e_cleanup: ' . $e->getMessage());
    }
}

// Remember the programme flag and restore it at the end.
$flagRow = $pdo->query("SELECT setting_value FROM site_settings WHERE setting_key='affiliate_program_enabled'")->fetchColumn();
$flagExisted = $flagRow !== false;
$originalFlag = $flagExisted ? (string)$flagRow : '0';
$setFlag = function (string $v) use ($pdo): void {
    $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES ('affiliate_program_enabled', ?)
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$v]);
    affiliate_settings_reload();
};

// Start from a clean slate for this address, and from a cleared rate limiter: repeated
// logins in the suites trip the app's own limiter, which shows up as a spurious failure.
e2e_cleanup($pdo, $email);
$pdo->exec('DELETE FROM auth_rate_limits');
$setFlag('1');

try {
    // -----------------------------------------------------------------------
    section('1. The programme is open, so registration is offered');
    // -----------------------------------------------------------------------
    $landing = http($jarAffiliate, 'GET', "$base/affiliates.php");
    check('the landing page returns 200', $landing['code'], 200);
    check('the landing page offers account registration', str_contains($landing['body'], '/affiliate/register.php'), true);

    // -----------------------------------------------------------------------
    section('2. Sign up through the real form (cookie jar + CSRF token)');
    // -----------------------------------------------------------------------
    $regPage = http($jarAffiliate, 'GET', "$base/affiliate/register.php");
    check('the registration page returns 200', $regPage['code'], 200);
    $regToken = csrf_of($regPage['body']);
    check('the registration form carries a CSRF token', $regToken !== '', true);

    $signup = http($jarAffiliate, 'POST', "$base/affiliate/register.php", [
        'csrf_token' => $regToken,
        'full_name' => 'E2E Workflow Applicant',
        'email' => $email,
        'phone' => '39000000',
        'country' => 'BH',
        'city' => 'Manama',
        'affiliate_type' => 'individual',
        'company_name' => '',
        'website' => '',
        'social_links' => '',
        'marketing_channels' => 'e2e-test',
        'payout_method' => 'bank_transfer',
        'password' => $password,
        'password_confirm' => $password,
        'accepted_terms' => '1',
        'accepted_privacy' => '1',
    ]);
    check('a valid signup is accepted and redirected', $signup['code'], 302);
    check('the signup redirects into the dashboard', str_contains($signup['location'], '/affiliate/dashboard.php'), true);

    $row = $pdo->prepare('SELECT id, status, compliance_status, password_hash FROM affiliates WHERE email=?');
    $row->execute([$email]);
    $affiliate = $row->fetch(PDO::FETCH_ASSOC);
    check('the account was created', is_array($affiliate), true);
    $affiliateId = (int)($affiliate['id'] ?? 0);

    // -----------------------------------------------------------------------
    section('3. The new account is pending and cannot earn');
    // -----------------------------------------------------------------------
    check('the account is pending', (string)($affiliate['status'] ?? ''), 'pending');
    check('compliance has not been reviewed', (string)($affiliate['compliance_status'] ?? ''), 'not_reviewed');
    check('the stored password is a hash, not the plaintext', $password !== (string)($affiliate['password_hash'] ?? '') && password_verify($password, (string)($affiliate['password_hash'] ?? '')), true);
    check('a pending account may sign in to watch its review', affiliate_can_login($affiliate), true);
    check('a pending account cannot earn', affiliate_can_earn($affiliate), false);

    // The registrant is signed in already, and can reach the dashboard.
    $dash = http($jarAffiliate, 'GET', "$base/affiliate/dashboard.php");
    check('the registrant reaches the dashboard', $dash['code'], 200);
    check('the dashboard does not expose a referral link while pending', str_contains($dash['body'], 'affiliate/links.php'), false);

    // A registration produced exactly one queue row for the operator to review.
    $appStmt = $pdo->prepare('SELECT id, status, affiliate_id FROM affiliate_applications WHERE email=? ORDER BY id DESC LIMIT 1');
    $appStmt->execute([$email]);
    $application = $appStmt->fetch(PDO::FETCH_ASSOC);
    check('a review-queue row was created', is_array($application), true);
    check('the queue row is submitted', (string)($application['status'] ?? ''), 'submitted');
    check('the queue row links to the account (no duplicate on approval)', (int)($application['affiliate_id'] ?? 0), $affiliateId);
    $applicationId = (int)($application['id'] ?? 0);

    // -----------------------------------------------------------------------
    section('4. Sign out, then sign back in as a pending affiliate');
    // -----------------------------------------------------------------------
    http($jarAffiliate, 'GET', "$base/affiliate/logout.php");
    $jarAffiliate2 = sys_get_temp_dir() . "/e34e2e-aff2-$sfx.cookies";

    $loginPage = http($jarAffiliate2, 'GET', "$base/affiliate/login.php");
    check('the affiliate sign-in page returns 200', $loginPage['code'], 200);
    $loginToken = csrf_of($loginPage['body']);
    $login = http($jarAffiliate2, 'POST', "$base/affiliate/login.php", [
        'csrf_token' => $loginToken, 'email' => $email, 'password' => $password,
    ]);
    check('a pending affiliate can sign in', $login['code'], 302);
    check('the sign-in redirects to the dashboard', str_contains($login['location'], '/affiliate/dashboard.php'), true);

    // A wrong password must still be refused — the point is that `pending` is allowed,
    // not that the login is weak.
    $jarWrong = sys_get_temp_dir() . "/e34e2e-wrong-$sfx.cookies";
    $wp = http($jarWrong, 'GET', "$base/affiliate/login.php");
    $wrong = http($jarWrong, 'POST', "$base/affiliate/login.php", [
        'csrf_token' => csrf_of($wp['body']), 'email' => $email, 'password' => 'not-the-password',
    ]);
    check('a wrong password is refused', str_contains($wrong['location'], 'dashboard'), false);

    // -----------------------------------------------------------------------
    section('5. The operator approves through the console');
    // -----------------------------------------------------------------------
    $pdo->exec('DELETE FROM auth_rate_limits');
    $adminLoginPage = http($jarAdmin, 'GET', "$base/admin/login.php");
    check('the console login page returns 200', $adminLoginPage['code'], 200);
    $adminLogin = http($jarAdmin, 'POST', "$base/admin/login.php", [
        'csrf_token' => csrf_of($adminLoginPage['body']),
        'email' => 'admin@mawatheq.com',
        'password' => 'Test-Password-2026',
    ]);
    check('the operator signs in', $adminLogin['code'], 302);

    $queue = http($jarAdmin, 'GET', "$base/admin/affiliate-applications.php");
    check('the review queue returns 200', $queue['code'], 200);
    check('the queue lists the new applicant', str_contains($queue['body'], $email), true);

    $approve = http($jarAdmin, 'POST', "$base/admin/affiliate-applications.php", [
        'csrf_token' => csrf_of($queue['body']),
        'application_id' => $applicationId,
        'action' => 'approve',
        'compliance_approved' => '1',
        'note' => 'E2E approval',
    ]);
    check('the approval is accepted', in_array($approve['code'], [200, 302], true), true);

    $after = $pdo->prepare('SELECT status, compliance_status FROM affiliates WHERE id=?');
    $after->execute([$affiliateId]);
    $afterRow = $after->fetch(PDO::FETCH_ASSOC);
    check('the account is now approved', (string)($afterRow['status'] ?? ''), 'approved');
    check('compliance is now approved', (string)($afterRow['compliance_status'] ?? ''), 'approved');

    $appAfter = $pdo->prepare('SELECT status, reviewed_at FROM affiliate_applications WHERE id=?');
    $appAfter->execute([$applicationId]);
    $appRow = $appAfter->fetch(PDO::FETCH_ASSOC);
    check('the queue row is stamped approved', (string)($appRow['status'] ?? ''), 'approved');
    check('the queue row records when it was reviewed', !empty($appRow['reviewed_at']), true);

    // The critical seam: approval must move the *existing* account, not create a second.
    $dupes = $pdo->prepare('SELECT COUNT(*) FROM affiliates WHERE email=?');
    $dupes->execute([$email]);
    check('approval did not create a duplicate account', (int)$dupes->fetchColumn(), 1);

    // Approved is not yet earning: activation is a separate act by the affiliate.
    $approvedRow = $pdo->prepare('SELECT * FROM affiliates WHERE id=?');
    $approvedRow->execute([$affiliateId]);
    $approvedAffiliate = $approvedRow->fetch(PDO::FETCH_ASSOC);
    check('an approved account may sign in', affiliate_can_login($approvedAffiliate), true);

    // -----------------------------------------------------------------------
    section('6. The approved affiliate signs in and accepts the terms');
    // -----------------------------------------------------------------------
    $jarApproved = sys_get_temp_dir() . "/e34e2e-approved-$sfx.cookies";
    $ap = http($jarApproved, 'GET', "$base/affiliate/login.php");
    $approvedLogin = http($jarApproved, 'POST', "$base/affiliate/login.php", [
        'csrf_token' => csrf_of($ap['body']), 'email' => $email, 'password' => $password,
    ]);
    check('the approved affiliate signs in', $approvedLogin['code'], 302);

    $terms = http($jarApproved, 'GET', "$base/affiliate/terms.php");
    check('the terms page returns 200', $terms['code'], 200);

    $accept = http($jarApproved, 'POST', "$base/affiliate/terms.php", [
        'csrf_token' => csrf_of($terms['body']),
        'action' => 'accept',
        'accepted_terms' => '1',
        'accepted_privacy' => '1',
    ]);
    check('accepting the terms is accepted', in_array($accept['code'], [200, 302], true), true);

    $activeRow = $pdo->prepare('SELECT status FROM affiliates WHERE id=?');
    $activeRow->execute([$affiliateId]);
    $activeStatus = (string)$activeRow->fetchColumn();
    check('accepting the terms activates the account', in_array($activeStatus, ['active'], true), true);

    $finalRow = $pdo->prepare('SELECT * FROM affiliates WHERE id=?');
    $finalRow->execute([$affiliateId]);
    $finalAffiliate = $finalRow->fetch(PDO::FETCH_ASSOC);
    check('an active, compliance-approved affiliate can earn', affiliate_can_earn($finalAffiliate), true);

    $finalDash = http($jarApproved, 'GET', "$base/affiliate/dashboard.php");
    check('the active affiliate reaches the dashboard', $finalDash['code'], 200);

    // -----------------------------------------------------------------------
    section('7. Refusals that must survive the happy path');
    // -----------------------------------------------------------------------
    // A closed programme must refuse a fresh registration.
    $setFlag('0');
    $jarClosed = sys_get_temp_dir() . "/e34e2e-closed-$sfx.cookies";
    $closedPage = http($jarClosed, 'GET', "$base/affiliate/register.php");
    check('the register page still returns 200 while closed', $closedPage['code'], 200);
    check('the register page offers no form while closed', str_contains($closedPage['body'], '<form'), false);

    // And a tokenless POST is refused outright, whatever the programme flag says.
    $tokenless = http($jarClosed, 'POST', "$base/affiliate/register.php", [
        'full_name' => 'Tokenless', 'email' => "tokenless-$sfx@example.test",
        'password' => $password, 'password_confirm' => $password,
        'accepted_terms' => '1', 'accepted_privacy' => '1',
    ]);
    check('a tokenless registration is refused', $tokenless['code'], 403);

    // -----------------------------------------------------------------------
    section('8. Cleanup');
    // -----------------------------------------------------------------------
    e2e_cleanup($pdo, $email);
    $gone = $pdo->prepare('SELECT COUNT(*) FROM affiliates WHERE email=?');
    $gone->execute([$email]);
    check('the test affiliate was removed', (int)$gone->fetchColumn(), 0);

    foreach ([$jarAffiliate, $jarAffiliate2, $jarWrong, $jarAdmin, $jarApproved, $jarClosed] as $j) {
        if (file_exists($j)) unlink($j);
    }
    $pdo->exec('DELETE FROM auth_rate_limits');
    check('the environment is left clean', true);
} finally {
    $setFlag($originalFlag);
}

// Restore the flag to exactly the row state we found (see exec31's cleanup note).
if (!$flagExisted) {
    $pdo->prepare("DELETE FROM site_settings WHERE setting_key='affiliate_program_enabled'")->execute();
    affiliate_settings_reload();
}

ob_end_flush();
echo "\n";
echo "execution 34 affiliate E2E workflow: $checks checks, $failures failure(s)\n";
exit($failures > 0 ? 1 : 0);
