<?php
/**
 * GET /api/v1/plans.php  → الخطط المنشورة (نفس بيانات الموقع).
 * لا يحتاج مصادقة (تصفح عام، مثل pricing.php تماماً).
 * response: { ok, plans: [...] }
 *
 * يقرأ من جدول plans المُصدَّر في schema.sql — لا توجد أسعار مكتوبة في التطبيق.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/plan_features.php';

// The catalogue decides which columns describe a plan. Listing them here by hand is how
// the app previously shipped a package with five unadvertised limits and six capability
// flags it never showed: the API selected the two AI counters and stopped.
$limitKeys = array_keys(plan_limit_catalog());
$featureKeys = array_keys(plan_feature_catalog());
$select = array_merge(
    ['id', 'name_ar', 'name_en', 'description_ar', 'description_en',
     'price_monthly', 'price_yearly', 'classification', 'billing_cycle',
     'consultation_price', 'is_corporate', 'is_featured', 'sort_order'],
    $limitKeys,
    $featureKeys
);

$plans = $pdo->query(
    'SELECT ' . implode(',', array_map(fn($c) => "`$c`", $select)) . "
     FROM plans WHERE status='active' ORDER BY sort_order, price_monthly"
)->fetchAll();

// Typed values, so a client does not have to know that a NULL column means "unlimited"
// while `0` means "not included at all". `limits` and `features` are additive fields:
// a client that ignores them keeps working against the flat columns above.
foreach ($plans as &$plan) {
    $limits = [];
    foreach ($limitKeys as $k) {
        $limits[$k] = $plan[$k] === null ? null : (int)$plan[$k];
    }
    $features = [];
    foreach ($featureKeys as $k) {
        $features[$k] = (int)($plan[$k] ?? 0) === 1;
    }
    $plan['limits'] = $limits;
    $plan['capabilities'] = $features;
}
unset($plan);

// The marketing benefits are stored in `plan_features`, the same table the website and
// the admin console read. The app showed only the numeric limits, so a plan's real
// selling points (clause bank, dedicated advisor, ...) were invisible there while the
// website listed them. Attach them per plan so both surfaces describe a plan identically.
// The field is additive: a client that ignores `features` keeps working unchanged.
$featureRows = $pdo->query(
    'SELECT plan_id, feature_ar, feature_en FROM plan_features ORDER BY plan_id, sort_order, id'
)->fetchAll();
$byPlan = [];
foreach ($featureRows as $f) {
    $byPlan[(int)$f['plan_id']][] = ['ar' => (string)$f['feature_ar'], 'en' => (string)$f['feature_en']];
}
foreach ($plans as &$plan) {
    $plan['features'] = $byPlan[(int)$plan['id']] ?? [];
}
unset($plan);

api_json(['ok' => true, 'plans' => $plans, 'currency' => currency_code()]);
