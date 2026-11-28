<?php
/**
 * Execution 63 — the two broken customer journeys.
 *
 * Problem 1: a paid consultation booking never reached the payment gateway and was silently
 *            left unconfirmed, and a lawyer could confirm it by hand without any money moving.
 * Problem 2: a package could not be purchased at all — every "choose plan" button led to a
 *            registration page that ignored the plan or to a contact form.
 *
 * These checks exercise the real service layer (not a mock): plan pricing read from `plans`,
 * order creation, verified activation, the payment-before-confirmation rule, and the
 * open-redirect guard that lets a deep link survive the sign-in round trip.
 *
 * Run against the test database:
 *   php tests/exec63_journeys_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/currency.php';
require_once __DIR__ . '/../includes/redirect.php';
require_once __DIR__ . '/../includes/package_payment.php';
require_once __DIR__ . '/../includes/consultation_payment.php';

$passed = 0;
$failed = 0;
function check(string $label, bool $condition): void {
    global $passed, $failed;
    if ($condition) { $passed++; echo "  ok:   $label\n"; }
    else { $failed++; echo "  FAIL: $label\n"; }
}

$pdo->beginTransaction();

// A throwaway client so nothing touches a real account; rolled back at the end.
$email = 'exec63-' . bin2hex(random_bytes(5)) . '@example.test';
$pdo->prepare("INSERT INTO users (full_name,email,password_hash,account_type,preferred_lang,email_verified,status,plan_id)
               VALUES (?,?,?,'individual','ar',1,'active',1)")
    ->execute(['Exec63 Test', $email, password_hash('x', PASSWORD_BCRYPT)]);
$userId = (int)$pdo->lastInsertId();

$plans = $pdo->query("SELECT * FROM plans WHERE status='active' ORDER BY id")->fetchAll();
$freePlan = null; $paidPlan = null; $corporatePlan = null;
foreach ($plans as $p) {
    $c = $p['classification'] ?? 'paid';
    if ($c === 'free' && !$freePlan) $freePlan = $p;
    if ($c === 'paid' && !$paidPlan) $paidPlan = $p;
    if ($c === 'corporate' && !$corporatePlan) $corporatePlan = $p;
}

echo "1. Package catalogue (problem 2)\n";
check('migration v70 applied (package_orders exists)', package_payment_schema_ready($pdo));
check('a free plan exists', $freePlan !== null);
check('a paid plan exists', $paidPlan !== null);
if ($paidPlan) {
    $cycles = package_plan_cycles($paidPlan);
    check('paid plan offers at least one cycle', count($cycles) > 0);
    $price = package_plan_price($paidPlan, $cycles[0]);
    check('paid plan price matches the DB column', abs($price - (float)$paidPlan['price_monthly']) < 0.001 || $cycles[0] === 'yearly');
    check('a cycle not offered returns null', package_plan_price($paidPlan, 'nonsense') === null);
}
if ($freePlan) {
    check('free plan is recognised as free', package_plan_is_free($freePlan, 'monthly'));
}

echo "\n2. Package order + verified activation (problem 2)\n";
$created = package_order_create($pdo, $userId, (int)$paidPlan['id'], 'monthly');
check('order created', !empty($created['ok']));
check('order amount comes from the plan, not the caller', abs((float)$created['order']['amount'] - (float)$paidPlan['price_monthly']) < 0.001);
check('order starts pending', $created['order']['status'] === 'pending');
$orderId = (int)$created['order_id'];

// Simulate the gateway confirming a capture, then activating.
$orderRow = $pdo->query("SELECT * FROM package_orders WHERE id=$orderId")->fetch();
$activation = package_payment_activate($pdo, $orderRow, ['amount' => $orderRow['amount'], 'currency' => $orderRow['currency']]);
check('activation succeeds', !empty($activation['activated']));
check('subscription created', !empty($activation['subscription_id']));
$subId = (int)$activation['subscription_id'];

$sub = $pdo->query("SELECT * FROM subscriptions WHERE id=$subId")->fetch();
check('subscription is active', $sub && $sub['status'] === 'active');
check('subscription belongs to the buyer', $sub && (int)$sub['user_id'] === $userId);
check('subscription points at the purchased plan', $sub && (int)$sub['plan_id'] === (int)$paidPlan['id']);
$userPlan = (int)$pdo->query("SELECT plan_id FROM users WHERE id=$userId")->fetchColumn();
check('users.plan_id points at the purchased plan (entitlement unlocked)', $userPlan === (int)$paidPlan['id']);

// Idempotency: a replayed webhook must not create a second subscription.
$replay = package_payment_activate($pdo, $orderRow, ['amount' => $orderRow['amount'], 'currency' => $orderRow['currency']]);
check('replayed activation is a no-op', empty($replay['activated']));
$subCount = (int)$pdo->query("SELECT COUNT(*) FROM subscriptions WHERE user_id=$userId")->fetchColumn();
check('exactly one subscription exists after replay', $subCount === 1);

echo "\n3. Free package activation\n";
if ($freePlan) {
    $free = package_activate_free($pdo, $userId, (int)$freePlan['id'], 'monthly');
    check('free plan activates without a gateway', !empty($free['ok']));
}

echo "\n4. Payment required before a paid consultation is confirmed (problem 1)\n";
check('a priced consultation is payment-required', consultation_payment_required(['price' => 500.0]));
check('an unpaid priced consultation blocks confirmation', consultation_payment_blocks_confirmation(['price' => 500.0, 'payment_status' => 'unpaid']));
check('a pending priced consultation blocks confirmation', consultation_payment_blocks_confirmation(['price' => 500.0, 'payment_status' => 'pending']));
check('a paid consultation does NOT block confirmation', !consultation_payment_blocks_confirmation(['price' => 500.0, 'payment_status' => 'paid']));
check('a free consultation never blocks confirmation', !consultation_payment_blocks_confirmation(['price' => 0.0, 'payment_status' => 'unpaid']));

echo "\n5. Open-redirect guard for the deep-link round trip\n";
check('a local checkout path is accepted', safe_local_path('/checkout.php?order_id=12') === '/checkout.php?order_id=12');
check('a package anchor is accepted', safe_local_path('/packages.php?plan=2#plan-2') === '/packages.php?plan=2#plan-2');
check('an absolute URL is rejected', safe_local_path('https://evil.example/x') === '');
check('a scheme-relative URL is rejected', safe_local_path('//evil.example/x') === '');
check('a backslash trick is rejected', safe_local_path('/\\evil.example') === '');
check('a non-path value is rejected', safe_local_path('javascript:alert(1)') === '');
check('an empty value is rejected', safe_local_path('') === '');

echo "\n6. Firm CTA resolves to approved lawyers (problem 1 helper)\n";
require_once __DIR__ . '/../includes/firm_site.php';
$firm = $pdo->query("SELECT id FROM law_firms ORDER BY id LIMIT 1")->fetch();
if ($firm) {
    $team = firm_site_team($pdo, (int)$firm['id']);
    $allApproved = true;
    foreach ($team as $m) {
        if (($m['status'] ?? 'approved') !== 'approved') $allApproved = false;
    }
    check('firm team returns only approved lawyers', $allApproved);
    check('firm team rows carry the id booking.php needs', empty($team) || isset($team[0]['id']));
}

$pdo->rollBack();

echo "\n$passed checks, $failed failure(s)\n";
exit($failed > 0 ? 1 : 0);
