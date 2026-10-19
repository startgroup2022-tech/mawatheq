<?php
/**
 * Execution 31 — affiliate, referral and partner marketing system: core lifecycle.
 *
 * Covers the money and state paths that carry the most risk:
 *   money arithmetic, the compliance gate, attribution capture and first-touch
 *   locking, conversion idempotency, commission calculation, refund handling,
 *   the ledger, the state machine, and payout controls.
 *
 *   php tests/exec31_affiliate_test.php
 *
 * Creates and removes its own rows. Never touches another suite's fixtures: the
 * affiliate tables are new in v57 and nothing else writes to them.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/affiliate.php';
require_once __DIR__ . '/../includes/affiliate_service.php';
require_once __DIR__ . '/../includes/affiliate_tracking.php';
require_once __DIR__ . '/../includes/affiliate_conversion.php';
require_once __DIR__ . '/../includes/affiliate_ledger.php';
require_once __DIR__ . '/../includes/affiliate_payout.php';
require_once __DIR__ . '/../includes/affiliate_fraud.php';

$pass = 0; $fail = 0; $failures = [];
function check(string $label, $got, $want): void {
    global $pass, $fail, $failures;
    if ($got === $want) { $pass++; return; }
    $fail++; $failures[] = $label . ': got=' . var_export($got, true) . ' want=' . var_export($want, true);
}
function check_true(string $label, $got): void { check($label, (bool)$got, true); }
function check_false(string $label, $got): void { check($label, (bool)$got, false); }

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------
$suffix = bin2hex(random_bytes(4));
$createdAffiliates = [];
$createdPlans = [];

function make_affiliate(PDO $pdo, string $suffix, array $overrides = []): array {
    $code = 'T' . strtoupper(substr($suffix, 0, 6)) . strtoupper(bin2hex(random_bytes(2)));
    $row = array_merge([
        'code' => $code,
        'email' => 'aff-' . strtolower($code) . '@example.test',
        'full_name' => 'Test Affiliate ' . $code,
        'affiliate_type' => 'individual',
        'status' => 'active',
        'compliance_status' => 'approved',
        'payout_currency' => 'BHD',
        'country' => 'BH',
    ], $overrides);
    $pdo->prepare('INSERT INTO affiliates (code, email, full_name, affiliate_type, status, compliance_status, payout_currency, country, phone)
        VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$row['code'], $row['email'], $row['full_name'], $row['affiliate_type'], $row['status'], $row['compliance_status'], $row['payout_currency'], $row['country'], $row['phone'] ?? null]);
    $id = (int)$pdo->lastInsertId();
    $row['id'] = $id;
    return $row;
}

// The programme must be on for conversions to be recorded at all.
// Remember whether the row pre-existed: cleanup restores what it found rather than deleting,
// because removing a row this suite did not create breaks whichever suite runs next. The
// application reads a missing row as "off" through its in-code defaults, so the difference
// is invisible to the app and very visible to a test that asserts the shipped default.
$progRowBefore = $pdo->query("SELECT setting_value FROM site_settings WHERE setting_key='affiliate_program_enabled'")->fetchColumn();
$progRowExisted = $progRowBefore !== false;
$progRowValue = $progRowExisted ? (string)$progRowBefore : '0';
$pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES ('affiliate_program_enabled','1')
    ON DUPLICATE KEY UPDATE setting_value='1'")->execute();
affiliate_settings_reload();

$affiliateA = make_affiliate($pdo, $suffix . 'a');
$createdAffiliates[] = $affiliateA['id'];
$affiliateB = make_affiliate($pdo, $suffix . 'b');
$createdAffiliates[] = $affiliateB['id'];
$affiliateSuspended = make_affiliate($pdo, $suffix . 's', ['status' => 'suspended']);
$createdAffiliates[] = $affiliateSuspended['id'];

// A plan that pays on a non-regulated service, so the money path can be exercised.
$planSlug = 'test-plan-' . $suffix;
$pdo->prepare("INSERT INTO affiliate_commission_plans (name, slug, version, plan_type, currency, revenue_base, percentage, first_payment_only, hold_days, effective_from, is_default, is_active)
    VALUES (?,?,1,'percentage','BHD','eligible_net','10.000',1,30,CURDATE(),0,1)")
    ->execute(['Test plan ' . $suffix, $planSlug]);
$planId = (int)$pdo->lastInsertId();
$createdPlans[] = $planId;
$plan = affiliate_plan_find($pdo, $planId);

// Point both affiliates at it.
$pdo->prepare('UPDATE affiliates SET commission_plan_id=? WHERE id IN (?,?)')->execute([$planId, $affiliateA['id'], $affiliateB['id']]);

// Open the compliance gate for one non-regulated service so commission is lawful.
$pdo->prepare("UPDATE affiliate_service_catalog SET attribution_mode='commission', commission_permitted=1, compliance_approved=1 WHERE service_key='subscription_plan'")->execute();
affiliate_service_catalog_reload();

$serviceKey = 'subscription_plan';

// ---------------------------------------------------------------------------
// 1. Attribution: capture, first-touch lock, window
// ---------------------------------------------------------------------------
echo "\n1. Attribution\n";

$visitor1 = bin2hex(random_bytes(16));
$attr = affiliate_attribution_create($pdo, $affiliateA, null, null, $visitor1, 0, affiliate_settings($pdo));
check_true('attribution created', $attr > 0);

// A second create for the same visitor must not overwrite the owner.
$attrAgain = affiliate_attribution_create($pdo, $affiliateB, null, null, $visitor1, 0, affiliate_settings($pdo));
check('same visitor keeps the same attribution', $attrAgain, $attr);
$owner = affiliate_attribution_find($pdo, $visitor1);
check('first-touch owner is A', (int)$owner['affiliate_id'], $affiliateA['id']);

// A different visitor for B is independent.
$visitor2 = bin2hex(random_bytes(16));
$attrB = affiliate_attribution_create($pdo, $affiliateB, null, null, $visitor2, 0, affiliate_settings($pdo));
check_true('second visitor attributed to B', $attrB > 0 && $attrB !== $attr);

// Bind identity: locks the owner and records a registration conversion.
$bind = affiliate_bind_identity($pdo, 'client', 999001, $visitor1);
check_true('identity bound', !empty($bind['ok']));
$locked = affiliate_attribution_find($pdo, $visitor1);
check_true('attribution locked after binding', !empty($locked['locked_at']));
check('lock reason recorded', (string)$locked['lock_reason'], 'identity_bound');

// Rebinding the same subject to a different affiliate must not move it.
$bind2 = affiliate_bind_identity($pdo, 'client', 999001, $visitor2);
check_false('subject cannot be rebound', !empty($bind2['ok']));

// Self-referral by identity is refused.
$pdo->prepare('UPDATE affiliates SET self_user_id=? WHERE id=?')->execute([999002, $affiliateB['id']]);
$visitor3 = bin2hex(random_bytes(16));
affiliate_attribution_create($pdo, $affiliateB, null, null, $visitor3, 0, affiliate_settings($pdo));
$selfBind = affiliate_bind_identity($pdo, 'client', 999002, $visitor3);
check_false('self-referral refused at binding', !empty($selfBind['ok']));
check('self-referral reason', (string)($selfBind['reason'] ?? ''), 'self_referral');

// ---------------------------------------------------------------------------
// 2. Conversion idempotency
// ---------------------------------------------------------------------------
echo "\n2. Conversion idempotency\n";

$conversionPayload = [
    'service_key' => $serviceKey,
    'event_type' => 'payment',
    'event_key' => 'test_payment:' . $suffix . ':1',
    'source_type' => 'invoice_payment',
    'source_id' => 500001,
    'subject_type' => 'client',
    'subject_id' => 999001,
    'currency' => 'BHD',
    'gross' => '115.000',
    'tax' => '15.000',
    'subtotal' => '100.000',
];

$conv1 = affiliate_record_conversion($pdo, $locked, $conversionPayload);
check_true('conversion recorded', !empty($conv1['ok']));
check_true('commission created', !empty($conv1['commission_id']));

$conv2 = affiliate_record_conversion($pdo, $locked, $conversionPayload);
check_true('duplicate flagged', !empty($conv2['duplicate']));
check('duplicate returns the same conversion', (int)$conv2['conversion_id'], (int)$conv1['conversion_id']);
check('duplicate returns the same commission', (int)$conv2['commission_id'], (int)$conv1['commission_id']);

$countConversions = $pdo->prepare('SELECT COUNT(*) FROM affiliate_conversions WHERE event_key=?');
$countConversions->execute([$conversionPayload['event_key']]);
check('exactly one conversion row', (int)$countConversions->fetchColumn(), 1);
$countCommissions = $pdo->prepare('SELECT COUNT(*) FROM affiliate_commissions WHERE conversion_id=?');
$countCommissions->execute([(int)$conv1['conversion_id']]);
check('exactly one commission row', (int)$countCommissions->fetchColumn(), 1);

// 10% of (115 - 15 tax) = 10.000
$commission = affiliate_commission_find($pdo, (int)$conv1['commission_id']);
check('commission base is eligible net', (string)$commission['base_amount'], '100.000');
check('commission is 10%', (string)$commission['commission_amount'], '10.000');
check('commission starts in hold', (string)$commission['status'], 'holding');

// ---------------------------------------------------------------------------
// 3. Compliance gate refuses regulated revenue
// ---------------------------------------------------------------------------
echo "\n3. Compliance gate\n";

$regulatedPayload = $conversionPayload;
$regulatedPayload['event_key'] = 'test_payment:' . $suffix . ':regulated';
$regulatedPayload['service_key'] = 'legal_invoice_fee';
$convRegulated = affiliate_record_conversion($pdo, $locked, $regulatedPayload);
check_true('regulated conversion recorded (tracked)', !empty($convRegulated['ok']));
check_false('regulated conversion is not eligible', !empty($convRegulated['eligible']));
check('regulated reason', (string)$convRegulated['reason'], 'regulated_professional_fee');
check_false('no commission for regulated fee', !empty($convRegulated['commission_id']));

// Flip every permissive flag on the regulated service — still refused.
$pdo->prepare("UPDATE affiliate_service_catalog SET attribution_mode='commission', commission_permitted=1, compliance_approved=1, is_active=1 WHERE service_key='legal_invoice_fee'")->execute();
affiliate_service_catalog_reload();
$svc = affiliate_service_find($pdo, 'legal_invoice_fee');
check_false('regulated fee cannot be made commissionable', affiliate_service_is_commissionable($svc));
check('regulated fee forced to prohibited', affiliate_service_effective_mode($svc)['mode'], 'prohibited');

// ---------------------------------------------------------------------------
// 4. Ledger
// ---------------------------------------------------------------------------
echo "\n4. Ledger\n";

$verify = affiliate_ledger_verify($pdo, $affiliateA['id'], 'BHD');
check_true('ledger balances', !empty($verify['ok']));
check('no unbalanced groups', count($verify['unbalanced_groups']), 0);
$balance = affiliate_ledger_balance($pdo, $affiliateA['id'], 'BHD');
check('ledger earned 10.000', $balance['earned'], '10.000');
check('ledger net 10.000', $balance['net'], '10.000');

// A deliberately unbalanced pair must be refused.
$unbalanced = affiliate_ledger_post_pair($pdo, [
    ['account' => 'commission_earned', 'direction' => 'credit', 'amount' => '5.000'],
    ['account' => 'commission_payable', 'direction' => 'debit', 'amount' => '4.000'],
], ['affiliate_id' => $affiliateA['id'], 'currency' => 'BHD']);
check_false('unbalanced posting refused', $unbalanced);

// ---------------------------------------------------------------------------
// 5. State machine
// ---------------------------------------------------------------------------
echo "\n5. State machine\n";

check_true('holding -> approved allowed', affiliate_state_can_transition('holding', 'approved'));
check_true('approved -> payable allowed', affiliate_state_can_transition('approved', 'payable'));
check_true('payable -> paid_out allowed', affiliate_state_can_transition('payable', 'paid_out'));
check_false('paid_out -> holding forbidden', affiliate_state_can_transition('paid_out', 'holding'));
check_false('reversed -> approved forbidden', affiliate_state_can_transition('reversed', 'approved'));

$trans = affiliate_commission_transition($pdo, (int)$commission['id'], 'approved', ['actor_id' => 1]);
check_true('commission approved', !empty($trans['ok']));
// 'tracked' is reachable from nothing once a commission has earned: an approved
// commission cannot be rewound to "merely observed".
$transBad = affiliate_commission_transition($pdo, (int)$commission['id'], 'tracked');
check_false('illegal transition refused', !empty($transBad['ok']));
check('illegal transition reason', (string)$transBad['reason'], 'illegal_transition');

// ---------------------------------------------------------------------------
// 6. Refunds
// ---------------------------------------------------------------------------
echo "\n6. Refunds\n";

// Partial refund: 115 gross, 15 tax, 40 refunded -> base 60 -> 10% = 6.000.
// `commission_amount` stays at the earned 10.000 and `reversed_amount` accumulates
// the 4.000 clawback, so the net owed is the difference.
$refund = affiliate_apply_refund($pdo, (int)$conv1['conversion_id'], '40.000');
check_true('partial refund applied', !empty($refund['ok']));
check('reduced commission is 6.000', (string)$refund['commission_minor'], (string)affiliate_to_minor('6.000', 'BHD'));
$afterRefund = affiliate_commission_find($pdo, (int)$commission['id']);
check('commission_amount unchanged (auditable)', (string)$afterRefund['commission_amount'], '10.000');
check('reversed amount recorded', (string)$afterRefund['reversed_amount'], '4.000');
check('net after partial refund is 6.000', affiliate_from_minor(affiliate_to_minor($afterRefund['commission_amount'], 'BHD') - affiliate_to_minor($afterRefund['reversed_amount'], 'BHD'), 'BHD'), '6.000');
check('commission still alive', (string)$afterRefund['status'], 'approved');

// Full refund: everything back -> net zero, status refunded.
$refundFull = affiliate_apply_refund($pdo, (int)$conv1['conversion_id'], '75.000');
check_true('full refund applied', !empty($refundFull['ok']));
check_true('fully reversed', !empty($refundFull['reversed']));
$afterFull = affiliate_commission_find($pdo, (int)$commission['id']);
check('reversed now equals the earned amount', (string)$afterFull['reversed_amount'], '10.000');
check('net is zero after full refund', affiliate_from_minor(affiliate_to_minor($afterFull['commission_amount'], 'BHD') - affiliate_to_minor($afterFull['reversed_amount'], 'BHD'), 'BHD'), '0.000');
check('status is refunded', (string)$afterFull['status'], 'refunded');

$verifyAfterRefund = affiliate_ledger_verify($pdo, $affiliateA['id'], 'BHD');
check_true('ledger still balances after refunds', !empty($verifyAfterRefund['ok']));

// ---------------------------------------------------------------------------
// 7. Suspended affiliate earns nothing
// ---------------------------------------------------------------------------
echo "\n7. Suspended affiliate\n";

check_false('suspended cannot earn', affiliate_can_earn($affiliateSuspended));
$visitorS = bin2hex(random_bytes(16));
$attrS = affiliate_attribution_create($pdo, $affiliateSuspended, null, null, $visitorS, 0, affiliate_settings($pdo));
$convS = affiliate_record_conversion($pdo, affiliate_attribution_find($pdo, $visitorS), [
    'service_key' => $serviceKey, 'event_type' => 'payment',
    'event_key' => 'test_payment:' . $suffix . ':suspended',
    'source_type' => 'invoice_payment', 'source_id' => 500009,
    'subject_type' => 'client', 'subject_id' => 999009,
    'currency' => 'BHD', 'gross' => '100.000', 'tax' => '0.000',
]);
check_false('suspended conversion not eligible', !empty($convS['eligible']));
check('suspended reason', (string)$convS['reason'], 'affiliate_not_active');

// ---------------------------------------------------------------------------
// 8. Payout controls
// ---------------------------------------------------------------------------
echo "\n8. Payout controls\n";

// A second affiliate with a clean, approved commission to pay out.
$visitorP = bin2hex(random_bytes(16));
$attrP = affiliate_attribution_create($pdo, $affiliateB, null, null, $visitorP, 0, affiliate_settings($pdo));
affiliate_bind_identity($pdo, 'client', 999100, $visitorP);
$lockedP = affiliate_attribution_find($pdo, $visitorP);
$convP = affiliate_record_conversion($pdo, $lockedP, [
    'service_key' => $serviceKey, 'event_type' => 'payment',
    'event_key' => 'test_payment:' . $suffix . ':payout',
    'source_type' => 'invoice_payment', 'source_id' => 500100,
    'subject_type' => 'client', 'subject_id' => 999100,
    'currency' => 'BHD', 'gross' => '1000.000', 'tax' => '0.000',
]);
check_true('payout fixture conversion recorded', !empty($convP['commission_id']));
$commissionP = affiliate_commission_find($pdo, (int)$convP['commission_id']);
check('payout fixture commission is 100.000', (string)$commissionP['commission_amount'], '100.000');

// A holding commission is not payable.
check('nothing payable while holding', affiliate_payable_minor($pdo, $affiliateB['id'], 'BHD'), 0);
$tooEarly = affiliate_request_payout($pdo, $affiliateB['id']);
check_false('payout refused while holding', !empty($tooEarly['ok']));
check('refusal reason', (string)($tooEarly['error'] ?? ''), 'nothing_payable');

// Approve it, then it is payable.
affiliate_commission_transition($pdo, (int)$commissionP['id'], 'approved', ['actor_id' => 1]);
check('payable is now 100.000', affiliate_payable_minor($pdo, $affiliateB['id'], 'BHD'), affiliate_to_minor('100.000', 'BHD'));

// Below the minimum is refused when a minimum is configured.
$pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES ('affiliate_min_payout','500.000') ON DUPLICATE KEY UPDATE setting_value='500.000'")->execute();
affiliate_settings_reload();
$belowMin = affiliate_request_payout($pdo, $affiliateB['id'], '100.000');
check_false('below minimum refused', !empty($belowMin['ok']));
check('below minimum reason', (string)($belowMin['error'] ?? ''), 'below_minimum');

// Restore a workable minimum and request.
$pdo->prepare("UPDATE site_settings SET setting_value='50.000' WHERE setting_key='affiliate_min_payout'")->execute();
affiliate_settings_reload();

$payoutReq = affiliate_request_payout($pdo, $affiliateB['id'], '100.000', ['method' => 'bank_transfer']);
check_true('payout requested', !empty($payoutReq['ok']));
$requestId = (int)($payoutReq['request_id'] ?? 0);
check_true('payout request id', $requestId > 0);

// A second open request is refused.
$second = affiliate_request_payout($pdo, $affiliateB['id']);
check_false('second open request refused', !empty($second['ok']));
check('second request reason', (string)($second['error'] ?? ''), 'open_request_exists');

// Requested amount cannot exceed the payable balance.
$pdo->prepare("UPDATE site_settings SET setting_value='500.000' WHERE setting_key='affiliate_min_payout'")->execute();
affiliate_settings_reload();
$overReq = affiliate_request_payout($pdo, $affiliateA['id'], '9999.000');
check_false('over-request refused', !empty($overReq['ok']));
$pdo->prepare("UPDATE site_settings SET setting_value='50.000' WHERE setting_key='affiliate_min_payout'")->execute();
affiliate_settings_reload();

// Manual payout requires evidence before it can be paid. The path is
// requested → approved → processing → paid.
$approveP = affiliate_payout_transition($pdo, $requestId, 'approved', ['actor_id' => 1]);
check_true('payout approved', !empty($approveP['ok']));
$toProcessing = affiliate_payout_transition($pdo, $requestId, 'processing', ['actor_id' => 1]);
check_true('can move to processing', !empty($toProcessing['ok']));
$paidNoEvidence = affiliate_payout_transition($pdo, $requestId, 'paid', ['actor_id' => 1]);
check_false('paid without evidence refused', !empty($paidNoEvidence['ok']));
check('evidence reason', (string)($paidNoEvidence['error'] ?? ''), 'evidence_required');

// With evidence it settles, the commission is paid_out, and the ledger balances.
$paid = affiliate_payout_transition($pdo, $requestId, 'paid', ['actor_id' => 1, 'evidence_reference' => 'BANK-REF-001']);
check_true('payout paid with evidence', !empty($paid['ok']));
$commissionAfterPay = affiliate_commission_find($pdo, (int)$commissionP['id']);
check('commission is paid_out', (string)$commissionAfterPay['status'], 'paid_out');
check('nothing left payable', affiliate_payable_minor($pdo, $affiliateB['id'], 'BHD'), 0);
$verifyPaid = affiliate_ledger_verify($pdo, $affiliateB['id'], 'BHD');
check_true('ledger balances after payout', !empty($verifyPaid['ok']));
$balPaid = affiliate_ledger_balance($pdo, $affiliateB['id'], 'BHD');
check('ledger net is zero after payout', $balPaid['net'], '0.000');
check('ledger shows 100.000 paid', $balPaid['paid'], '100.000');

// ---------------------------------------------------------------------------
// 9. Dual approval
// ---------------------------------------------------------------------------
echo "\n9. Dual approval\n";

// A large commission needing two approvers.
$visitorD = bin2hex(random_bytes(16));
affiliate_attribution_create($pdo, $affiliateA, null, null, $visitorD, 0, affiliate_settings($pdo));
affiliate_bind_identity($pdo, 'client', 999200, $visitorD);
$convD = affiliate_record_conversion($pdo, affiliate_attribution_find($pdo, $visitorD), [
    'service_key' => $serviceKey, 'event_type' => 'payment',
    'event_key' => 'test_payment:' . $suffix . ':dual',
    'source_type' => 'invoice_payment', 'source_id' => 500200,
    'subject_type' => 'client', 'subject_id' => 999200,
    'currency' => 'BHD', 'gross' => '20000.000', 'tax' => '0.000',
]);
check_true('dual fixture conversion', !empty($convD['commission_id']));
affiliate_commission_transition($pdo, (int)$convD['commission_id'], 'approved', ['actor_id' => 1]);
$dualReq = affiliate_request_payout($pdo, $affiliateA['id'], '2000.000', ['method' => 'bank_transfer']);
check_true('dual payout requested', !empty($dualReq['ok']));
$dualId = (int)($dualReq['request_id'] ?? 0);

// First approval by admin 1 -> awaits a second approver.
$first = affiliate_payout_transition($pdo, $dualId, 'approved', ['actor_id' => 1]);
check_true('first approval accepted', !empty($first['ok']));
check('first approval awaits second', (string)($first['error'] ?? ''), 'awaiting_second_approval');
$dualRow = affiliate_payout_request_find($pdo, $dualId);
check('still under review after first approval', (string)$dualRow['status'], 'under_review');
check('first approver recorded', (int)$dualRow['approved_by'], 1);

// The same admin cannot be the second approver.
$sameAgain = affiliate_payout_transition($pdo, $dualId, 'approved', ['actor_id' => 1]);
check_false('same admin cannot second-approve', !empty($sameAgain['ok']));
check('second approver required', (string)($sameAgain['error'] ?? ''), 'second_approver_required');

// A different admin completes the approval.
$second = affiliate_payout_transition($pdo, $dualId, 'approved', ['actor_id' => 2]);
check_true('second admin approves', !empty($second['ok']));
$dualRow2 = affiliate_payout_request_find($pdo, $dualId);
check('now approved', (string)$dualRow2['status'], 'approved');
check('second approver recorded', (int)$dualRow2['second_approved_by'], 2);

// ---------------------------------------------------------------------------
// 10. Payout details encryption
// ---------------------------------------------------------------------------
echo "\n10. Payout details\n";

$stored = affiliate_store_payout_details($pdo, $dualId, ['bank_name' => 'Bank of Bahrain', 'iban' => 'BH67BMAG00001299123456']);
check_true('payout details stored encrypted', $stored);
$raw = $pdo->prepare('SELECT payout_details_enc, payout_details_mask FROM affiliate_payout_requests WHERE id=?');
$raw->execute([$dualId]);
$rawRow = $raw->fetch();
check_true('details are encrypted at rest', str_starts_with((string)$rawRow['payout_details_enc'], 'enc:v1:'));
check_false('raw IBAN not in the mask', str_contains((string)$rawRow['payout_details_mask'], 'BH67BMAG00001299123456'));
check_true('mask shows only the tail', str_contains((string)$rawRow['payout_details_mask'], '3456'));
$decrypted = affiliate_read_payout_details((string)$rawRow['payout_details_enc']);
check('details decrypt', (string)($decrypted['iban'] ?? ''), 'BH67BMAG00001299123456');

// ---------------------------------------------------------------------------
// 11. Link building and open-redirect defence
// ---------------------------------------------------------------------------
echo "\n11. Link building\n";

$link = affiliate_build_link($affiliateA, '/pricing.php', ['utm_source' => 'newsletter']);
check_true('link contains the code', str_contains($link, 'ref=' . $affiliateA['code']));
check_true('link is same-site', str_starts_with($link, rtrim(SITE_URL, '/')));
check('protocol-relative destination neutralised', affiliate_safe_destination('//evil.example/x'), '/');
check('absolute URL neutralised', affiliate_safe_destination('https://evil.example/x'), '/');
check('traversal neutralised', affiliate_safe_destination('/../../etc/passwd'), '/');
check('backslash neutralised', affiliate_safe_destination('/\\evil.example'), '/');
check('valid path kept', affiliate_safe_destination('/pricing.php'), '/pricing.php');
check('query stripped from destination', affiliate_safe_destination('/a.php?x=1'), '/a.php');

// ---------------------------------------------------------------------------
// 12. Earnings summary excludes demo data
// ---------------------------------------------------------------------------
echo "\n12. Earnings summary\n";

// Demo commissions must never appear in a real earnings figure. A dedicated demo
// conversion and commission are created here rather than reusing one that was paid
// out, because a paid-out commission contributes to `paid_out` and the assertion
// would then be testing the payout rather than the demo filter.
$pdo->prepare("INSERT INTO affiliate_conversions (affiliate_id, service_key, subject_ref, subject_type, event_type, event_key, source_type, currency, gross_amount, eligible_amount, is_eligible, status, is_demo)
    VALUES (?, ?, ?, 'client', 'payment', ?, 'demo', 'BHD', '777.000', '777.000', 1, 'holding', 1)")
    ->execute([$affiliateB['id'], $serviceKey, substr(hash('sha256', 'demo' . $suffix), 0, 32), 'demo_conv:' . $suffix]);
$demoConversionId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO affiliate_commissions (affiliate_id, conversion_id, plan_id, plan_version, currency, base_amount, commission_amount, status, is_demo)
    VALUES (?, ?, ?, 1, 'BHD', '777.000', '77.700', 'holding', 1)")
    ->execute([$affiliateB['id'], $demoConversionId, $planId]);
$demoCommissionId = (int)$pdo->lastInsertId();
check_true('demo commission created', $demoCommissionId > 0);

$summaryNoDemo = affiliate_earnings_summary($pdo, $affiliateB['id']);
$summaryWithDemo = affiliate_earnings_summary($pdo, $affiliateB['id'], ['include_demo' => true]);
check_false('demo excluded by default', str_contains($summaryNoDemo['holding'], '77.7'));
check_true('demo included when asked', str_contains($summaryWithDemo['holding'], '77.7'));
check('real holding is zero', $summaryNoDemo['holding'], affiliate_from_minor(0, 'BHD'));

$pdo->prepare('DELETE FROM affiliate_commissions WHERE id=?')->execute([$demoCommissionId]);
$pdo->prepare('DELETE FROM affiliate_conversions WHERE id=?')->execute([$demoConversionId]);

// ---------------------------------------------------------------------------
// Cleanup
// ---------------------------------------------------------------------------
echo "\nCleanup\n";
$ids = implode(',', array_map('intval', $createdAffiliates));
if ($ids !== '') {
    // Conversions, commissions, attributions, clicks, ledger entries and payouts all
    // cascade from the affiliate row.
    $pdo->exec("DELETE FROM affiliate_payouts WHERE affiliate_id IN ($ids)");
    $pdo->exec("DELETE FROM affiliate_payout_requests WHERE affiliate_id IN ($ids)");
    $pdo->exec("DELETE FROM affiliate_ledger_entries WHERE affiliate_id IN ($ids)");
    $pdo->exec("DELETE FROM affiliate_commissions WHERE affiliate_id IN ($ids)");
    $pdo->exec("DELETE FROM affiliate_conversions WHERE affiliate_id IN ($ids)");
    $pdo->exec("DELETE FROM affiliate_attributions WHERE affiliate_id IN ($ids)");
    $pdo->exec("DELETE FROM affiliate_clicks WHERE affiliate_id IN ($ids)");
    $pdo->exec("DELETE FROM affiliate_fraud_flags WHERE affiliate_id IN ($ids)");
    $pdo->exec("DELETE FROM affiliate_notification_log WHERE affiliate_id IN ($ids)");
    $pdo->exec("DELETE FROM affiliate_audit_logs WHERE affiliate_id IN ($ids)");
    $pdo->exec("DELETE FROM affiliate_daily_stats WHERE affiliate_id IN ($ids)");
    $pdo->exec("DELETE FROM affiliates WHERE id IN ($ids)");
}
foreach ($createdPlans as $planIdToDrop) {
    $pdo->exec('DELETE FROM affiliate_commission_plans WHERE id=' . (int)$planIdToDrop);
}
// Restore the compliance gate to its shipped state so a later suite sees the real seed.
$pdo->exec("UPDATE affiliate_service_catalog SET attribution_mode='disabled', commission_permitted=0, compliance_approved=0 WHERE service_key='subscription_plan'");
$pdo->exec("UPDATE affiliate_service_catalog SET attribution_mode='prohibited', commission_permitted=0, compliance_approved=0 WHERE service_key='legal_invoice_fee'");
if ($progRowExisted) {
    $pdo->prepare("UPDATE site_settings SET setting_value=? WHERE setting_key='affiliate_program_enabled'")->execute([$progRowValue]);
} else {
    $pdo->exec("DELETE FROM site_settings WHERE setting_key='affiliate_program_enabled'");
}
affiliate_settings_reload();

echo "\n----------------------------------------\n";
printf("checks passed: %d   failed: %d\n", $pass, $fail);
if ($fail > 0) {
    foreach ($failures as $f) echo "  FAIL $f\n";
    exit(1);
}
echo "exec31 affiliate core: OK\n";
exit(0);
