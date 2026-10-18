<?php
/**
 * Affiliate registration-to-activation journey (Execution 32).
 *
 * Covers the path that was broken end to end: a person registers an account, an
 * operator approves the application, the applicant accepts the programme terms, and
 * the account activates and can issue a working referral link.
 *
 * Each check pins a defect that made the journey stop:
 *   • affiliate_can_login() refused `pending`, so the account created by
 *     affiliate_register() could never sign in again — registration appeared to work
 *     and then the new affiliate was locked out of the account they had just made.
 *   • affiliate_approve_application() returned success for an application that already
 *     had an affiliate_id without changing anything, so the console reported "approved"
 *     while the account stayed `pending` forever.
 *   • registration wrote the `affiliate_agreements` rows itself, which made
 *     affiliate_has_accepted() true before the applicant had seen the obligations and
 *     left affiliate_activate() with nothing to unlock — an approved account could
 *     never become `active`, so its referral link never appeared.
 *   • affiliate/dashboard.php called affiliate_has_accepted() without the file that
 *     defines it, which fataled the page for every approved-but-not-yet-activated
 *     affiliate.
 *
 * Run against the test database and the local server:
 *   php tests/exec32_affiliate_journey_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/affiliate.php';
require_once __DIR__ . '/../includes/affiliate_auth.php';
require_once __DIR__ . '/../includes/affiliate_admin.php';

$passed = 0;
$failed = 0;

// Buffer output: affiliate_establish_session() calls session_regenerate_id(), which
// warns if anything has already been written. Buffering keeps that a real error rather
// than an artefact of this script's own progress output.
ob_start();

function check(string $label, bool $condition): void {
    global $passed, $failed;
    if ($condition) { $passed++; echo "  ok:   $label\n"; }
    else { $failed++; echo "  FAIL: $label\n"; }
}

/** Remove everything this test creates, so a re-run starts clean. */
function journey_cleanup(PDO $pdo, string $email): void {
    try {
        $stmt = $pdo->prepare('SELECT id FROM affiliates WHERE email=?');
        $stmt->execute([$email]);
        $id = (int)$stmt->fetchColumn();
        if ($id > 0) {
            $pdo->prepare('DELETE FROM affiliate_agreements WHERE affiliate_id=?')->execute([$id]);
            $pdo->prepare('DELETE FROM affiliate_applications WHERE affiliate_id=?')->execute([$id]);
            $pdo->prepare('DELETE FROM affiliate_audit_logs WHERE affiliate_id=?')->execute([$id]);
            $pdo->prepare('DELETE FROM affiliate_notification_log WHERE affiliate_id=?')->execute([$id]);
            $pdo->prepare('DELETE FROM affiliates WHERE id=?')->execute([$id]);
        }
        $pdo->prepare('DELETE FROM affiliate_applications WHERE email=?')->execute([$email]);
    } catch (Throwable $e) {
        // Cleanup is best-effort; a leftover row is not a test failure.
    }
}

$email = 'exec32-journey-' . bin2hex(random_bytes(5)) . '@example.com';
journey_cleanup($pdo, $email);

echo "1. Portal access policy\n";
// A registered applicant must be able to sign in, and a finished account must not.
check('pending can sign in', affiliate_can_login(['status' => 'pending']));
check('under_review can sign in', affiliate_can_login(['status' => 'under_review']));
check('approved can sign in', affiliate_can_login(['status' => 'approved']));
check('active can sign in', affiliate_can_login(['status' => 'active']));
check('suspended can sign in to read history', affiliate_can_login(['status' => 'suspended']));
check('rejected cannot sign in', !affiliate_can_login(['status' => 'rejected']));
check('closed cannot sign in', !affiliate_can_login(['status' => 'closed']));
check('null affiliate cannot sign in', !affiliate_can_login(null));

echo "\n2. Registration\n";
// affiliate_register() reads the client IP for the consent record.
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$registered = affiliate_register($pdo, [
    'full_name' => 'اختبار الرحلة',
    'email' => $email,
    'password' => 'Test-Password-2026',
    'password_confirm' => 'Test-Password-2026',
    'phone' => '+97330000000',
    'country' => 'BH',
    'accepted_terms' => '1',
    'accepted_privacy' => '1',
]);
check('registration succeeds', !empty($registered['ok']));
$affiliateId = (int)($registered['affiliate_id'] ?? 0);
check('an affiliate id was returned', $affiliateId > 0);

$affiliate = $affiliateId > 0 ? affiliate_find($pdo, $affiliateId) : null;
check('account starts pending', ($affiliate['status'] ?? '') === 'pending');
check('the new account can sign in', affiliate_can_login($affiliate));
check('the new account cannot earn yet', !affiliate_can_earn($affiliate));
check('a password was stored', !empty($affiliate['password_hash']));

// Registration consent belongs on the application, not the agreement table.
check('registration did NOT record the programme agreement',
    !affiliate_has_accepted($pdo, $affiliateId, 'terms'));
check('a pending account cannot be activated', empty(affiliate_activate($pdo, $affiliateId)['ok']));

$stmt = $pdo->prepare('SELECT id, status, accepted_terms, accepted_privacy FROM affiliate_applications WHERE affiliate_id=?');
$stmt->execute([$affiliateId]);
$application = $stmt->fetch();
check('an application row was created', !empty($application));
check('the application is in review', ($application['status'] ?? '') === 'submitted');
check('the application records the registration consent',
    !empty($application['accepted_terms']) && !empty($application['accepted_privacy']));

echo "\n3. Operator approval\n";
$applicationId = (int)($application['id'] ?? 0);
$approved = affiliate_approve_application($pdo, $applicationId, ['note' => 'exec32 test', 'compliance_approved' => true]);
check('approval reports success', !empty($approved['ok']));

$affiliate = affiliate_find($pdo, $affiliateId);
check('the account is now approved', ($affiliate['status'] ?? '') === 'approved');
check('compliance was approved on request', ($affiliate['compliance_status'] ?? '') === 'approved');
check('an approved account still cannot earn before accepting terms', !affiliate_can_earn($affiliate));
check('the approved account can sign in', affiliate_can_login($affiliate));

$stmt = $pdo->prepare('SELECT status FROM affiliate_applications WHERE id=?');
$stmt->execute([$applicationId]);
check('the application is marked approved', (string)$stmt->fetchColumn() === 'approved');
check('approving twice is refused', empty(affiliate_approve_application($pdo, $applicationId)['ok']));

echo "\n4. Activation by accepting the terms\n";
check('terms not yet accepted', !affiliate_has_accepted($pdo, $affiliateId, 'terms'));
check('activation is refused before the agreement exists',
    empty(affiliate_activate($pdo, $affiliateId)['ok']));

check('accepting the terms is recorded', affiliate_accept_terms($pdo, $affiliateId, 'terms'));
check('accepting the privacy policy is recorded', affiliate_accept_terms($pdo, $affiliateId, 'privacy'));
check('the terms are now on record', affiliate_has_accepted($pdo, $affiliateId, 'terms'));

$activated = affiliate_activate($pdo, $affiliateId);
check('activation succeeds after acceptance', !empty($activated['ok']));

$affiliate = affiliate_find($pdo, $affiliateId);
check('the account is active', ($affiliate['status'] ?? '') === 'active');
check('an active account can earn', affiliate_can_earn($affiliate));

echo "\n5. Referral link\n";
$link = affiliate_build_link($affiliate, '/');
check('the link carries the affiliate code', str_contains($link, 'ref=' . $affiliate['code']));
check('the link stays on the platform host', str_starts_with($link, rtrim(SITE_URL, '/')));
check('the link is not an open redirect', !str_contains(affiliate_build_link($affiliate, '//evil.example'), 'evil.example'));

// ---------------------------------------------------------------------------
// The public landing page takes an application without creating an account, so the
// applicant never chose a password. Approval then creates an account that cannot be
// signed into, and the invitation is the only route to credentials. Three defects met
// on this path:
//   • affiliate_create_invitation() read `$data['invite_type']` after testing it with
//     `??`, so the value was null and the NOT NULL column rejected every invitation —
//     the function could not create an invitation at all.
//   • it also refused any email that already had an affiliate row, which is exactly the
//     account this path needs to reach.
//   • affiliate_accept_invitation() wrote the affiliate_agreements rows itself, so the
//     terms page found the agreement already accepted, rendered no form, and the
//     activation that only runs from that form never fired. The invited partner stayed
//     `approved` and could never earn.
// ---------------------------------------------------------------------------
echo "\n6. Public-form applicant: approval, invitation, activation\n";

$publicEmail = 'exec32-public-' . bin2hex(random_bytes(5)) . '@example.com';
journey_cleanup($pdo, $publicEmail);

// The public form is rate limited per IP and per email, and every successful submission
// counts. A test that ran the suite twice inside the 15-minute window would otherwise be
// refused with `rate_limited` rather than testing the journey, so the buckets this test
// owns are cleared first.
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
foreach ([['affiliate-apply', $publicEmail], ['affiliate-apply-ip', (string)$_SERVER['REMOTE_ADDR']]] as [$bucket, $key]) {
    foreach (auth_rate_limit_keys($bucket, $key) as $scopeKey) {
        auth_rate_limit_clear_key($pdo, $scopeKey);
    }
}

// This section deliberately attempts one failed sign-in (a password-less account must
// not authenticate), and a failed sign-in consumes the shared per-IP login budget. Left
// alone, a second run of the suite inside the 15-minute window would find the IP blocked
// and report the real login below as `rate_limited`. The buckets this test touches are
// therefore cleared here as well.
foreach ([['affiliate-login', $publicEmail], ['affiliate-login-ip', (string)$_SERVER['REMOTE_ADDR']]] as [$bucket, $key]) {
    foreach (auth_rate_limit_keys($bucket, $key) as $scopeKey) {
        auth_rate_limit_clear_key($pdo, $scopeKey);
    }
}

$submitted = affiliate_submit_application($pdo, [
    'full_name' => 'متقدم الصفحة العامة',
    'email' => $publicEmail,
    'country' => 'BH',
    'accepted_terms' => true,
    'accepted_privacy' => true,
]);
check('the public form accepts an application', !empty($submitted['ok']));
check('no account is created by the form itself', empty($submitted['affiliate_id']));

$stmt = $pdo->prepare('SELECT id FROM affiliate_applications WHERE email=? ORDER BY id DESC LIMIT 1');
$stmt->execute([$publicEmail]);
$applicationId = (int)$stmt->fetchColumn();
check('the application is queued', $applicationId > 0);

$approved = affiliate_approve_application($pdo, $applicationId);
check('the operator can approve it', !empty($approved['ok']));
$publicId = (int)($approved['affiliate_id'] ?? 0);
check('approval created the account', $publicId > 0);

$created = affiliate_find($pdo, $publicId);
check('the created account is approved', ($created['status'] ?? '') === 'approved');
check('the created account has no password yet', empty($created['password_hash']));
// `approved` may sign in by policy, but without credentials there is nothing to sign in
// with — this is the actual lock-out the invitation exists to resolve.
check('a password-less account cannot authenticate', empty(affiliate_login($pdo, $publicEmail, 'Journey-Password-2026')['ok']));

// The invitation is the only way in. It must create, and must accept this email.
$invitation = affiliate_create_invitation($pdo, [
    'email' => $publicEmail,
    'full_name' => 'متقدم الصفحة العامة',
    'invite_type' => 'individual',
    'preapproved' => true,
]);
check('an invitation can be created for the applicant', !empty($invitation['ok']));
$openEmail = 'exec32-open-' . bin2hex(random_bytes(5)) . '@example.com';
check('an invitation without an explicit type still creates', !empty(affiliate_create_invitation($pdo, [
    'email' => $openEmail,
])['ok']));

$token = (string)($invitation['token'] ?? '');
check('the token is a 64-character hex string', (bool)preg_match('/^[a-f0-9]{64}$/', $token));

$accepted = affiliate_accept_invitation($pdo, $token, [
    'email' => $publicEmail,
    'full_name' => 'متقدم الصفحة العامة',
    'password' => 'Journey-Password-2026',
    'password_confirm' => 'Journey-Password-2026',
    'accepted_terms' => true,
    'country' => 'BH',
]);
check('the applicant can accept the invitation', !empty($accepted['ok']));
check('acceptance reuses the existing account rather than duplicating it', (int)($accepted['affiliate_id'] ?? 0) === $publicId);

$invited = affiliate_find($pdo, $publicId);
check('the applicant now has a password', !empty($invited['password_hash']));
check('the applicant can now authenticate', !empty(affiliate_login($pdo, $publicEmail, 'Journey-Password-2026')['ok']));
check('acceptance did not pre-record the agreement', !affiliate_has_accepted($pdo, $publicId, 'terms'));
check('the account is not active until the terms are accepted', ($invited['status'] ?? '') !== 'active');

// A second use of the same token must fail: invitations are single-use.
$reuse = affiliate_accept_invitation($pdo, $token, [
    'email' => $publicEmail, 'password' => 'Journey-Password-2026',
    'password_confirm' => 'Journey-Password-2026', 'accepted_terms' => true,
]);
check('a used invitation cannot be accepted again', empty($reuse['ok']));

// An account that already has credentials must not be reachable by a fresh invitation,
// or a leaked token would be a password reset.
$stmt = $pdo->prepare('SELECT accepted_at FROM affiliate_invitations WHERE email=? ORDER BY id DESC LIMIT 1');
$stmt->execute([$publicEmail]);
check('the invitation is recorded as accepted', !empty($stmt->fetchColumn()));
check('an established affiliate is refused a new invitation', empty(affiliate_create_invitation($pdo, [
    'email' => $publicEmail,
])['ok']));

// Accepting the terms is what activates, exactly as for the registered applicant.
check('the invited applicant can accept the terms', affiliate_accept_terms($pdo, $publicId, 'terms'));
check('the invited applicant can accept the privacy policy', affiliate_accept_terms($pdo, $publicId, 'privacy'));
$finalActivation = affiliate_activate($pdo, $publicId);
check('the invited applicant activates', !empty($finalActivation['ok']));
$final = affiliate_find($pdo, $publicId);
check('the invited applicant is active', ($final['status'] ?? '') === 'active');
// Activation is separate from the compliance ruling: `active` alone does not earn, which
// is what stops an affiliate promoting a service compliance has not cleared.
check('an active but un-cleared account does not earn', !affiliate_can_earn($final));
affiliate_set_compliance($pdo, $publicId, 'approved');
$cleared = affiliate_find($pdo, $publicId);
check('the invited applicant can earn once compliance clears them', affiliate_can_earn($cleared));

echo "\n7. Cleanup\n";
journey_cleanup($pdo, $email);
journey_cleanup($pdo, $publicEmail);
try {
    $pdo->prepare('DELETE FROM affiliate_invitations WHERE email IN (?,?,?)')->execute([$email, $publicEmail, $openEmail]);
} catch (Throwable $e) { /* best effort */ }
$stmt = $pdo->prepare('SELECT COUNT(*) FROM affiliates WHERE email IN (?,?)');
$stmt->execute([$email, $publicEmail]);
check('the test accounts were removed', (int)$stmt->fetchColumn() === 0);

echo "\n----------------------------------------\n";
echo "checks passed: $passed   failed: $failed\n";
echo $failed === 0 ? "exec32 affiliate journey: OK\n" : "exec32 affiliate journey: FAILED\n";
if (ob_get_level() > 0) { ob_end_flush(); }
exit($failed === 0 ? 0 : 1);
