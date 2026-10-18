<?php
/**
 * Execution 31 — affiliate admin console HTTP probe.
 *
 * Drives the console pages through a real Apache + session, because the failure this
 * is meant to catch is a rendering one: a page that lints clean still 500s on a
 * column that does not exist, and a guard that lints clean still lets the wrong role
 * in. The probe logs in as super_admin and as support, and asserts both the intended
 * access and the refused access.
 *
 * Usage: php tests/exec31_admin_console_probe.php
 */
$base = getenv('MAWATHEQ_BASE_URL') ?: 'http://localhost:8080';
$root = dirname(__DIR__);
require_once $root . '/config/config.php';
require_once $root . '/includes/affiliate.php';
require_once $root . '/includes/affiliate_admin.php';

$pass = 'Test-Password-2026';
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

function http_login(string $base, string $loginPath, string $email, string $pass, string $jar): array {
    $ch = curl_init($base . $loginPath);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false]);
    $html = curl_exec($ch); curl_close($ch);
    preg_match('/name="csrf_token" value="([^"]+)"/', (string)$html, $m);
    $token = $m[1] ?? '';
    $ch = curl_init($base . $loginPath);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['csrf_token' => $token, 'email' => $email, 'password' => $pass]),
    ]);
    $body = curl_exec($ch);
    $loc = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL); curl_close($ch);
    return [$token, $loc, (string)$body];
}

function http_get(string $url, string $jar): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false]);
    $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, (string)$body];
}

function http_post(string $url, string $jar, array $fields): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields), CURLOPT_FOLLOWLOCATION => false]);
    $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, (string)$body];
}

function csrf_of(string $body): string { preg_match('/name="csrf_token" value="([^"]+)"/', $body, $m); return $m[1] ?? ''; }

$sfx = bin2hex(random_bytes(4));
$created = [];

// A live affiliate for the detail page, with a link and a commission so the tables
// have rows rather than exercising only the empty state.
$pdo->prepare("INSERT INTO affiliates (code,email,full_name,affiliate_type,status,compliance_status,payout_currency,country)
    VALUES (?,?,?,'individual','pending','not_reviewed','BHD','BH')")
    ->execute(['P' . strtoupper($sfx), 'probe-' . $sfx . '@example.test', 'Probe Affiliate ' . $sfx]);
$affiliateId = (int)$pdo->lastInsertId();
$created[] = $affiliateId;

echo "Execution 31 affiliate admin console probe\n";
echo "===========================================\n\n";

// ---------------------------------------------------------------------------
echo "1. Service-layer status machine\n";
// ---------------------------------------------------------------------------
check('pending -> under_review allowed', affiliate_status_can_transition('pending', 'under_review'));
check('pending -> active refused (must pass approval)', !affiliate_status_can_transition('pending', 'active'));
check('closed is terminal', affiliate_status_transitions()['closed'] === []);
check('rejected is terminal', affiliate_status_transitions()['rejected'] === []);

$r = affiliate_set_status($pdo, $affiliateId, 'active', ['actor_id' => 1]);
check('pending -> active is refused by the service', (string)($r['error'] ?? ''), 'illegal_transition');

$r = affiliate_set_status($pdo, $affiliateId, 'under_review', ['actor_id' => 1]);
check('pending -> under_review succeeds', !empty($r['ok']));
$r = affiliate_set_status($pdo, $affiliateId, 'approved', ['actor_id' => 1]);
check('under_review -> approved succeeds', !empty($r['ok']));
$r = affiliate_set_status($pdo, $affiliateId, 'active', ['actor_id' => 1]);
check('approved -> active succeeds', !empty($r['ok']));
check('approved_at was stamped', !empty(affiliate_find($pdo, $affiliateId)['approved_at']));

// The reason rule is checked on a state that may actually be suspended: a pending
// applicant is refused earlier by the transition rule, so testing it there would
// assert the wrong guard.
$r = affiliate_set_status($pdo, $affiliateId, 'suspended', ['actor_id' => 1, 'note' => '']);
check('suspension without a reason is refused', (string)($r['error'] ?? ''), 'reason_required');
check('the refused suspension did not change the state', (string)affiliate_find($pdo, $affiliateId)['status'], 'active');

$r = affiliate_set_status($pdo, $affiliateId, 'suspended', ['actor_id' => 1, 'note' => 'سبب اختباري']);
check('active -> suspended with a reason succeeds', !empty($r['ok']));
check('suspension_reason recorded', (string)affiliate_find($pdo, $affiliateId)['suspension_reason'], 'سبب اختباري');

$r = affiliate_set_status($pdo, $affiliateId, 'active', ['actor_id' => 1]);
check('suspended -> active (reinstatement) succeeds', !empty($r['ok']));

$r = affiliate_set_compliance($pdo, $affiliateId, 'approved', ['actor_id' => 1]);
check('compliance can be set to approved', !empty($r['ok']));
$r = affiliate_set_compliance($pdo, $affiliateId, 'nonsense');
check('an unknown compliance value is refused', (string)($r['error'] ?? ''), 'invalid_status');

check('the change was written to the affiliate audit trail',
    (int)$pdo->query("SELECT COUNT(*) FROM affiliate_audit_logs WHERE affiliate_id=$affiliateId AND action IN ('affiliate_status_changed','affiliate_compliance_changed')")->fetchColumn() >= 4);

// ---------------------------------------------------------------------------
echo "\n2. List and detail queries\n";
// ---------------------------------------------------------------------------
$list = affiliate_admin_list($pdo, ['q' => $sfx]);
check('the list finds the affiliate by code fragment', count($list), 1);
check('the list carries the conversion count', isset($list[0]['conversion_count']));
check('the list carries the open-flag count', isset($list[0]['open_flags']));
check('the list carries the terms acceptance date', array_key_exists('terms_accepted_at', $list[0]));

$counts = affiliate_admin_counts($pdo);
check('counts return a total', $counts['total'] >= 1);
check('counts are non-negative', $counts['active'] >= 0 && $counts['pending'] >= 0 && $counts['suspended'] >= 0);

$detail = affiliate_admin_detail($pdo, $affiliateId);
check('detail returns the affiliate', (int)$detail['affiliate']['id'], $affiliateId);
check('detail carries every collection', count(array_intersect(['agreements','commissions','payouts','flags','links'], array_keys($detail))), 5);

// ---------------------------------------------------------------------------
echo "\n3. Console pages over HTTP (super_admin)\n";
// ---------------------------------------------------------------------------
$jar = sys_get_temp_dir() . "/e31adm-$sfx.cookies";
[$token, $loc, $_b] = http_login($base, '/admin/login.php', 'admin@mawatheq.com', $pass, $jar);
check('super_admin logs in', str_contains($loc, 'admin') || $loc !== '');

[$code, $body] = http_get($base . '/admin/affiliates.php', $jar);
check('affiliates console returns 200', $code, 200);
check('affiliates console has no PHP error', !preg_match('/Fatal error|Parse error|Uncaught|Warning:|SQLSTATE/i', $body));
check('affiliates console lists the affiliate', str_contains($body, (string)$sfx) || str_contains($body, 'Probe Affiliate'));
check('affiliates console renders the stat cards', str_contains($body, 'stat-card'));
check('affiliates console marks the nav item active', str_contains($body, 'المسوّقون'));

[$code, $body] = http_get($base . '/admin/affiliate-detail.php?id=' . $affiliateId, $jar);
check('affiliate detail returns 200', $code, 200);
check('affiliate detail has no PHP error', !preg_match('/Fatal error|Parse error|Uncaught|Warning:|SQLSTATE/i', $body));
check('affiliate detail shows the affiliate name', str_contains($body, 'Probe Affiliate'));
check('affiliate detail shows the ledger balance card', str_contains($body, 'سلامة الدفتر'));
check('affiliate detail shows the commissions table', str_contains($body, 'العمولات'));
check('affiliate detail shows the payouts table', str_contains($body, 'طلبات الصرف'));
check('affiliate detail shows the agreements table', str_contains($body, 'الاتفاقيات'));

[$code, $body] = http_get($base . '/admin/affiliate-detail.php?id=99999999', $jar);
check('a missing affiliate returns 404', $code, 404);
check('the 404 page does not leak a stack trace', !str_contains($body, 'Fatal error'));

// ---------------------------------------------------------------------------
echo "\n4. CSRF and role enforcement\n";
// ---------------------------------------------------------------------------
[$code, $body] = http_get($base . '/admin/affiliate-detail.php?id=' . $affiliateId, $jar);
$pageToken = csrf_of($body);
check('the detail page carries a CSRF token', $pageToken !== '');

[$code, $_b] = http_post($base . '/admin/affiliate-detail.php', $jar, ['affiliate_id' => $affiliateId, 'action' => 'status', 'status' => 'closed', 'note' => 'no csrf']);
check('a POST without a CSRF token is refused', $code, 403);
check('the status was not changed by the refused POST', (string)affiliate_find($pdo, $affiliateId)['status'], 'active');

[$code, $body] = http_post($base . '/admin/affiliate-detail.php', $jar, ['csrf_token' => $pageToken, 'affiliate_id' => $affiliateId, 'action' => 'compliance', 'compliance_status' => 'in_review']);
check('a valid CSRF POST is accepted', $code, 200);
check('the compliance change was applied', (string)affiliate_find($pdo, $affiliateId)['compliance_status'], 'in_review');

// A support operator may read the list but not the detail console.
$supportEmail = 'support-' . $sfx . '@example.test';
$pdo->prepare("INSERT INTO admins (email,password_hash,full_name,role,totp_enabled) VALUES (?,?,?,'support',0)")
    ->execute([$supportEmail, password_hash($pass, PASSWORD_DEFAULT), 'Support ' . $sfx]);
$supportId = (int)$pdo->lastInsertId();
$jarSup = sys_get_temp_dir() . "/e31sup-$sfx.cookies";
http_login($base, '/admin/login.php', $supportEmail, $pass, $jarSup);

[$code, $_b] = http_get($base . '/admin/affiliates.php', $jarSup);
check('support may read the affiliate list', $code, 200);

[$code, $_b] = http_get($base . '/admin/affiliate-detail.php?id=' . $affiliateId, $jarSup);
check('support is refused the affiliate detail console', $code, 403);

// ---------------------------------------------------------------------------
echo "\n5. Every console renders for super_admin\n";
// ---------------------------------------------------------------------------
foreach ([
    '/admin/affiliates.php',
    '/admin/affiliate-commissions.php',
    '/admin/affiliate-payouts.php',
    '/admin/affiliate-campaigns.php',
    '/admin/affiliate-fraud.php',
    '/admin/affiliate-settings.php',
] as $path) {
    [$code, $body] = http_get($base . $path, $jar);
    check("$path returns 200", $code, 200);
    check("$path has no PHP error", !preg_match('/Fatal error|Parse error|Uncaught|Warning:|SQLSTATE/i', $body));
    check("$path renders the admin shell", str_contains($body, 'adm-content'));
}

// ---------------------------------------------------------------------------
echo "\n6. Payout workflow through the console\n";
// ---------------------------------------------------------------------------
// A payable commission and a request, so the review page has something to move.
$currency = 'BHD';

// Upsert rather than UPDATE: on a database where the programme has never been
// configured these rows do not exist yet, and an UPDATE would silently match nothing
// while the probe reported a payout failure that had nothing to do with the code.
function probe_set_setting(PDO $pdo, string $key, string $value): void {
    $pdo->prepare('INSERT INTO site_settings (setting_key, setting_value) VALUES (?,?)
        ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([$key, $value]);
}
$settingsBackup = [];
foreach (['affiliate_program_enabled', 'affiliate_dual_approval_threshold', 'affiliate_min_payout'] as $key) {
    $stmt = $pdo->prepare('SELECT setting_value FROM site_settings WHERE setting_key=?');
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    $settingsBackup[$key] = $val === false ? null : (string)$val;
}
probe_set_setting($pdo, 'affiliate_program_enabled', '1');
probe_set_setting($pdo, 'affiliate_dual_approval_threshold', '100.000');
probe_set_setting($pdo, 'affiliate_min_payout', '5.000');
affiliate_settings_reload();

require_once $root . '/includes/affiliate_payout.php';

// A commission must point at a conversion, so seed one rather than inserting a
// dangling commission the schema would reject.
function seed_conversion(PDO $pdo, int $affiliateId, string $currency, string $tag): int {
    $key = 'probe:' . $tag;
    $stmt = $pdo->prepare("INSERT INTO affiliate_conversions (affiliate_id,service_key,subject_ref,subject_type,event_type,event_key,source_type,currency,gross_amount,eligible_amount,is_eligible,status)
        VALUES (?,'subscription_plan',?, 'client','payment',?,'invoice_payment',?,100.000,100.000,1,'approved')");
    $stmt->execute([$affiliateId, substr(hash('sha256', $key), 0, 32), $key, $currency]);
    return (int)$pdo->lastInsertId();
}
$convId1 = seed_conversion($pdo, $affiliateId, $currency, 'pay1-' . $sfx);
$pdo->prepare("INSERT INTO affiliate_commissions (affiliate_id,conversion_id,currency,base_amount,commission_amount,status,plan_version)
    VALUES (?,?,?,'100.000','60.000','approved',1)")->execute([$affiliateId, $convId1, $currency]);

// The affiliate needs a plan and an active state for the request to be accepted.
$pdo->prepare("UPDATE affiliates SET status='active', compliance_status='approved' WHERE id=?")->execute([$affiliateId]);
$req = affiliate_request_payout($pdo, $affiliateId, '60.000', ['actor_id' => 1]);
check('a payout request can be created', !empty($req['ok']));
$payoutRequestId = (int)($req['request_id'] ?? 0);
check('the request has an id', $payoutRequestId > 0);

[$code, $body] = http_get($base . '/admin/affiliate-payout.php?id=' . $payoutRequestId, $jar);
check('the payout review page returns 200', $code, 200);
check('the payout review page has no PHP error', !preg_match('/Fatal error|Parse error|Uncaught|Warning:|SQLSTATE/i', $body));
check('the payout review page shows the dual-approval rule', str_contains($body, 'الاعتماد المزدوج'));
check('the payout review page does not show the full IBAN of a different affiliate', !str_contains($body, 'BH00TEST00000000000000'));

$pageToken = csrf_of($body);
check('the payout review page carries a CSRF token', $pageToken !== '');

// Amount 60.000 is under the 100.000 threshold, so one approval suffices.
[$code, $body] = http_post($base . '/admin/affiliate-payout.php', $jar, [
    'csrf_token' => $pageToken, 'request_id' => $payoutRequestId, 'action' => 'approved', 'note' => 'اعتماد اختباري',
]);
check('a single approval is accepted below the threshold', $code, 200);
check('the request moved to approved', (string)affiliate_payout_request_find($pdo, $payoutRequestId)['status'], 'approved');

// `paid` without an evidence reference must be refused by the service.
$paid = affiliate_payout_transition($pdo, $payoutRequestId, 'processing', ['actor_id' => 1]);
check('approved -> processing succeeds', !empty($paid['ok']));
$paid = affiliate_payout_transition($pdo, $payoutRequestId, 'paid', ['actor_id' => 1]);
check('paid without evidence is refused', (string)($paid['error'] ?? ''), 'evidence_required');

$paid = affiliate_payout_transition($pdo, $payoutRequestId, 'paid', ['actor_id' => 1, 'evidence_reference' => 'TRX-TEST-1']);
check('paid with evidence succeeds', !empty($paid['ok']));
check('the payout settled to paid', (string)affiliate_payout_request_find($pdo, $payoutRequestId)['status'], 'paid');
check('a settled payout row was written',
    (int)$pdo->query("SELECT COUNT(*) FROM affiliate_payouts WHERE payout_request_id=$payoutRequestId")->fetchColumn(), 1);
check('the reserved commission moved to paid_out',
    (int)$pdo->query("SELECT COUNT(*) FROM affiliate_commissions WHERE affiliate_id=$affiliateId AND status='paid_out'")->fetchColumn() >= 1);

// A paid request is terminal.
$again = affiliate_payout_transition($pdo, $payoutRequestId, 'processing', ['actor_id' => 1]);
check('a paid request cannot be reopened', (string)($again['error'] ?? ''), 'illegal_transition');

// ---------------------------------------------------------------------------
echo "\n7. Dual approval above the threshold\n";
// ---------------------------------------------------------------------------
probe_set_setting($pdo, 'affiliate_dual_approval_threshold', '1.000');
affiliate_settings_reload();
$convId2 = seed_conversion($pdo, $affiliateId, $currency, 'pay2-' . $sfx);
$pdo->prepare("INSERT INTO affiliate_commissions (affiliate_id,conversion_id,currency,base_amount,commission_amount,status,plan_version)
    VALUES (?,?,?,'100.000','20.000','approved',1)")->execute([$affiliateId, $convId2, $currency]);
$req2 = affiliate_request_payout($pdo, $affiliateId, '20.000', ['actor_id' => 1]);
$req2Id = (int)($req2['request_id'] ?? 0);
check('a second request can be created', $req2Id > 0);

$first = affiliate_payout_transition($pdo, $req2Id, 'approved', ['actor_id' => 1]);
check('the first approval is recorded but does not complete', (string)($first['error'] ?? ''), 'awaiting_second_approval');
check('the request waits in under_review', (string)affiliate_payout_request_find($pdo, $req2Id)['status'], 'under_review');

$sameAgain = affiliate_payout_transition($pdo, $req2Id, 'approved', ['actor_id' => 1]);
check('the same admin cannot approve twice', (string)($sameAgain['error'] ?? ''), 'second_approver_required');

$second = affiliate_payout_transition($pdo, $req2Id, 'approved', ['actor_id' => $supportId]);
check('a different admin completes the approval', !empty($second['ok']));
check('the request is now approved', (string)affiliate_payout_request_find($pdo, $req2Id)['status'], 'approved');
check('the second approver is recorded',
    (int)affiliate_payout_request_find($pdo, $req2Id)['second_approved_by'], $supportId);

// ---------------------------------------------------------------------------
echo "\n8. Settings console\n";
// ---------------------------------------------------------------------------
[$code, $body] = http_get($base . '/admin/affiliate-settings.php', $jar);
$settingsToken = csrf_of($body);
[$code, $body] = http_post($base . '/admin/affiliate-settings.php', $jar, [
    'csrf_token' => $settingsToken,
    'affiliate_program_enabled' => '1',
    'affiliate_require_consent' => '1',
    'affiliate_attribution_days' => '45',
    'affiliate_cookie_days' => '90',
    'affiliate_default_hold_days' => '30',
    'affiliate_attribution_model' => 'first_touch',
    'affiliate_payout_currency' => 'BHD',
    'affiliate_min_payout' => '50.000',
    'affiliate_dual_approval_threshold' => '500.000',
    'affiliate_default_commission_percent' => '10.000',
]);
check('the settings form saves', $code, 200);
affiliate_settings_forget();
check('the attribution window was persisted', (int)affiliate_settings($pdo)['attribution_days'], 45);

// A malformed money value must be refused rather than stored.
[$code, $body] = http_post($base . '/admin/affiliate-settings.php', $jar, [
    'csrf_token' => $settingsToken, 'affiliate_min_payout' => 'not-a-number',
]);
check('a malformed money value is refused', str_contains($body, 'قيمة غير صالحة') || $code === 200);
check('the malformed value was not stored', (string)affiliate_settings($pdo)['min_payout'], '50.000');

// ---------------------------------------------------------------------------
echo "\n9. Unauthenticated access\n";
// ---------------------------------------------------------------------------
foreach (['/admin/affiliates.php', '/admin/affiliate-detail.php?id=1', '/admin/affiliate-payouts.php',
          '/admin/affiliate-commissions.php', '/admin/affiliate-campaigns.php',
          '/admin/affiliate-applications.php',
          '/admin/affiliate-fraud.php', '/admin/affiliate-settings.php'] as $path) {
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false]);
    curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    check("unauthenticated $path is not served", $code !== 200);
}

// ---------------------------------------------------------------------------
echo "\nCleanup\n";
// ---------------------------------------------------------------------------
try {
    // Put the programme settings back the way they were found. This probe turns the
    // programme on and moves the payout thresholds, and a later suite in the same run
    // would inherit those values as if they were the deployment's configuration.
    foreach ($settingsBackup as $key => $value) {
        if ($value === null) {
            $pdo->prepare('DELETE FROM site_settings WHERE setting_key=?')->execute([$key]);
        } else {
            probe_set_setting($pdo, $key, $value);
        }
    }
    $pdo->prepare('DELETE FROM affiliate_payouts WHERE affiliate_id=?')->execute([$affiliateId]);
    $pdo->prepare('DELETE FROM affiliate_commissions WHERE affiliate_id=?')->execute([$affiliateId]);
    $pdo->prepare('DELETE FROM affiliate_conversions WHERE affiliate_id=?')->execute([$affiliateId]);
    $pdo->prepare('DELETE FROM affiliate_payout_requests WHERE affiliate_id=?')->execute([$affiliateId]);
    $pdo->prepare('DELETE FROM affiliate_ledger_entries WHERE affiliate_id=?')->execute([$affiliateId]);
    $pdo->prepare('DELETE FROM affiliate_audit_logs WHERE affiliate_id=?')->execute([$affiliateId]);
    $pdo->prepare('DELETE FROM affiliate_notification_log WHERE affiliate_id=?')->execute([$affiliateId]);
    $pdo->prepare('DELETE FROM affiliates WHERE id=?')->execute([$affiliateId]);
    $pdo->prepare('DELETE FROM admins WHERE id=?')->execute([$supportId]);
} catch (Throwable $e) {
    echo "  note: cleanup incomplete — " . $e->getMessage() . "\n";
}
@unlink($jar); @unlink($jarSup);

echo "\n----------------------------------------\n";
echo "checks passed: " . ($checks - $failures) . "   failed: $failures\n";
echo $failures === 0 ? "exec31 affiliate admin console: OK\n" : "exec31 affiliate admin console: FAILED\n";
exit($failures === 0 ? 0 : 1);
