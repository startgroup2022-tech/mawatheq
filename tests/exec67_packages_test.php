<?php
/**
 * Execution 67 — packages (plans) management rules.
 *
 * The plan form used to accept any classification/price combination, and the columns the
 * customer-facing surfaces read (classification, billing_cycle, consultation_price) could
 * not be set from the console at all. These checks pin the rules that now live in
 * includes/plans_admin.php, and prove a saved package round-trips through the database
 * with the values packages.php renders.
 *
 * Run: php tests/exec67_packages_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/plans_admin.php';

$failures = 0; $checks = 0;
function check(string $label, bool $ok): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) { $failures++; echo "  FAIL: $label\n"; } else echo "  ok:   $label\n";
}

echo "\n== 1. classification and price must agree ==\n";
$base = ['name_ar' => 'باقة اختبار', 'name_en' => 'Test', 'classification' => 'paid', 'billing_cycle' => 'both', 'price_monthly' => '149'];
check('a paid plan with a price is valid', plan_validation_error(plan_normalize_input($base)) === null);

$free = ['name_ar' => 'مجانية', 'name_en' => 'Free', 'classification' => 'free', 'price_monthly' => '0'];
check('a free plan priced at zero is valid', plan_validation_error(plan_normalize_input($free)) === null);

$badFree = ['name_ar' => 'مجانية', 'name_en' => 'Free', 'classification' => 'free', 'price_monthly' => '149'];
check('a free plan with a price is refused', plan_validation_error(plan_normalize_input($badFree)) !== null);

$badPaid = ['name_ar' => 'مدفوعة', 'name_en' => 'Paid', 'classification' => 'paid', 'price_monthly' => '0', 'price_yearly' => ''];
check('a paid plan at zero is refused', plan_validation_error(plan_normalize_input($badPaid)) !== null);

$yearlyOnly = ['name_ar' => 'سنوية', 'name_en' => 'Yearly', 'classification' => 'paid', 'billing_cycle' => 'yearly', 'price_monthly' => '0', 'price_yearly' => '1490'];
check('a yearly-only plan with a yearly price is valid', plan_validation_error(plan_normalize_input($yearlyOnly)) === null);

$yearlyNoPrice = ['name_ar' => 'سنوية', 'name_en' => 'Yearly', 'classification' => 'paid', 'billing_cycle' => 'yearly', 'price_monthly' => '149', 'price_yearly' => '0'];
check('a yearly-only plan without a yearly price is refused', plan_validation_error(plan_normalize_input($yearlyNoPrice)) !== null);

$noName = ['name_ar' => '', 'name_en' => 'X', 'classification' => 'paid', 'price_monthly' => '10'];
check('a plan without an Arabic name is refused', plan_validation_error(plan_normalize_input($noName)) !== null);

echo "\n== 2. tampered input degrades to a safe default ==\n";
$tampered = plan_normalize_input([
    'name_ar' => 'x', 'name_en' => 'x',
    'classification' => 'DROP TABLE', 'billing_cycle' => 'weekly',
    'price_monthly' => '-50', 'price_yearly' => '-10', 'consultation_price' => '-5',
    'ai_queries_limit' => '-3', 'status' => 'hacked',
]);
check('an unknown classification falls back to paid', $tampered['classification'] === 'paid');
check('an unknown billing cycle falls back to both', $tampered['billing_cycle'] === 'both');
check('a negative monthly price is clamped to zero', $tampered['price_monthly'] === 0.0);
check('a negative yearly price is clamped to zero', (float)$tampered['price_yearly'] === 0.0);
check('a negative consultation price is clamped to zero', (float)$tampered['consultation_price'] === 0.0);
check('a negative AI limit is clamped to zero', $tampered['ai_queries_limit'] === 0);
check('an unknown status falls back to active', $tampered['status'] === 'active');

echo "\n== 3. a package round-trips with the columns packages.php reads ==\n";
$cols = 'name_ar,name_en,description_ar,description_en,price_monthly,price_yearly,ai_queries_limit,document_analysis_limit,consultations_included,classification,billing_cycle,consultation_price,is_corporate,is_featured,status,sort_order';
$suffix = bin2hex(random_bytes(4));
$payload = plan_normalize_input([
    'name_ar' => "باقة $suffix", 'name_en' => "Plan $suffix",
    'description_ar' => 'وصف', 'description_en' => 'desc',
    'price_monthly' => '199', 'price_yearly' => '1990',
    'ai_queries_limit' => '', 'document_analysis_limit' => '50',
    'consultations_included' => '3', 'classification' => 'corporate',
    'billing_cycle' => 'both', 'consultation_price' => '250',
    'is_corporate' => '1', 'is_featured' => '0', 'status' => 'active', 'sort_order' => '0',
]);
$pdo->prepare("INSERT INTO plans ($cols) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute(array_values($payload));
$planId = (int)$pdo->lastInsertId();

$row = $pdo->prepare('SELECT * FROM plans WHERE id=?'); $row->execute([$planId]);
$saved = $row->fetch();
check('the classification is stored', ($saved['classification'] ?? '') === 'corporate');
check('the billing cycle is stored', ($saved['billing_cycle'] ?? '') === 'both');
check('the consultation price is stored', (float)($saved['consultation_price'] ?? 0) === 250.0);
check('the monthly price is stored', (float)$saved['price_monthly'] === 199.0);
check('an empty AI limit is stored as unlimited (NULL)', $saved['ai_queries_limit'] === null);
check('the document limit is stored', (int)$saved['document_analysis_limit'] === 50);

// packages.php renders a plan only when it is active, and reads classification to label it.
$active = $pdo->prepare("SELECT COUNT(*) c FROM plans WHERE id=? AND status='active'"); $active->execute([$planId]);
check('an active package is visible to the customer surfaces', (int)$active->fetch()['c'] === 1);

echo "\n== cleanup ==\n";
$pdo->prepare('DELETE FROM plans WHERE id=?')->execute([$planId]);

echo "\n$checks checks, $failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
