<?php
/**
 * GET /api/v1/subscription.php → باقة المحامي/المكتب الحالية، السعر، والدورة، واستهلاك الحدود
 *
 * Every number here comes from the same functions that enforce the limit
 * (`plan_usage()` / `plan_check()` in includes/plan_features.php). A subscription screen
 * that reported its own count would eventually disagree with what the server refuses, and
 * the customer would be told they have quota the gate will not honour.
 *
 * `enforced: false` is reported explicitly. It means "no plan, or an unlimited limit", which
 * the platform treats as *no limit* (legacy accounts) — not as zero remaining.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/tenant.php';
require_once __DIR__ . '/../../includes/plan_features.php';

$auth = api_authenticate($pdo);
if (($auth['type'] ?? '') !== 'lawyer') {
    api_error('هذا العنصر متاح للمحامين فقط.', 403);
}
$lawyerId = (int)$auth['id'];
$ctx = tenant_context_for_lawyer($pdo, $lawyerId);
$firmId = !empty($ctx['firm_id']) ? (int)$ctx['firm_id'] : 0;

// The plan is resolved for the firm when the lawyer belongs to one, otherwise for the
// lawyer's own account. plan_features.php owns that resolution.
$plan = plan_for_firm($pdo, $firmId, $lawyerId);

$scope = ['firm_id' => $firmId, 'lawyer_id' => $lawyerId, 'user_id' => 0];
$usage = plan_usage($pdo, $plan, $scope);

$limits = [];
foreach ($usage as $key => $row) {
    $limits[] = [
        'key' => $key,
        'limit' => $row['limit'],
        'used' => $row['used'],
        'remaining' => $row['remaining'],
        'enforced' => $row['enforced'],
        'exhausted' => $row['exhausted'],
    ];
}

// The active subscription row, when the schema tracks one. Absent on a legacy account that
// was never sold a plan, which is a real state and must not read as an error.
$subscription = null;
try {
    $stmt = $pdo->prepare(
        $firmId > 0
            ? 'SELECT * FROM subscriptions WHERE firm_id = ? ORDER BY id DESC LIMIT 1'
            : 'SELECT * FROM subscriptions WHERE lawyer_id = ? ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$firmId > 0 ? $firmId : $lawyerId]);
    $subscription = $stmt->fetch() ?: null;
} catch (PDOException $e) {
    // The subscriptions table is not present on every install; the plan itself still answers.
    $subscription = null;
}

$plans = [];
try {
    $plans = $pdo->query("SELECT id, name_ar, name_en, price_monthly, price_yearly, description_ar FROM plans WHERE status='active' ORDER BY price_monthly")->fetchAll();
} catch (PDOException $e) {
    $plans = [];
}

// `plans.price_monthly` is a DECIMAL(10,3) column, so it reads back as "149.000" — three
// decimals regardless of the currency. Sending that raw made the app print "149.000 SAR"
// for a 2-decimal currency, which is the same defect AGENTS.md forbids on the web side.
// The raw numeric value stays (a client may compute with it); the `*_display` fields are
// formatted by the one currency helper the website uses, so the app cannot drift from it.
foreach ($plans as &$p) {
    $p['price_monthly_display'] = $p['price_monthly'] === null ? null : currency_number($p['price_monthly']);
    $p['price_yearly_display'] = $p['price_yearly'] === null ? null : currency_number($p['price_yearly']);
}
unset($p);

api_json([
    'ok' => true,
    'plan' => $plan ? [
        'id' => (int)$plan['id'],
        'name_ar' => $plan['name_ar'] ?? null,
        'name_en' => $plan['name_en'] ?? null,
        'price_monthly' => $plan['price_monthly'] ?? null,
        'price_yearly' => $plan['price_yearly'] ?? null,
        'price_monthly_display' => isset($plan['price_monthly']) ? currency_number($plan['price_monthly']) : null,
        'price_yearly_display' => isset($plan['price_yearly']) ? currency_number($plan['price_yearly']) : null,
    ] : null,
    'subscription' => $subscription,
    'limits' => $limits,
    'available_plans' => $plans,
    'currency' => function_exists('currency_code') ? currency_code() : 'SAR',
]);
