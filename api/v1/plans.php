<?php
/**
 * GET /api/v1/plans.php  → الخطط المنشورة (نفس بيانات الموقع).
 * لا يحتاج مصادقة (تصفح عام، مثل pricing.php تماماً).
 * response: { ok, plans: [...] }
 *
 * يقرأ من جدول plans المُصدَّر في schema.sql — لا توجد أسعار مكتوبة في التطبيق.
 */
require_once __DIR__ . '/bootstrap.php';

$plans = $pdo->query(
    "SELECT id, name_ar, name_en, description_ar, description_en,
            price_monthly, price_yearly, ai_queries_limit,
            document_analysis_limit, consultations_included,
            is_corporate, is_featured, sort_order
     FROM plans WHERE status='active' ORDER BY sort_order, price_monthly"
)->fetchAll();

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
