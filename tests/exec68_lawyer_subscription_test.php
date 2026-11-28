<?php
/**
 * Lawyer subscription page (Execution 68 continuation).
 *
 * The page exists because a lawyer could not see which plan governs them or how much of
 * each limit was left. The property that matters: it reports the *enforced* numbers.
 * A page that reads `plans` directly can drift from plan_check(); this one goes through
 * plan_for_firm()/plan_usage(), which are the same functions the server refuses with.
 *
 *   php tests/exec68_lawyer_subscription_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/tenant.php';
require_once __DIR__ . '/../includes/plan_features.php';

$passed = 0;
$failed = 0;
function check(string $label, bool $condition): void {
    global $passed, $failed;
    if ($condition) { $passed++; echo "  ok   $label\n"; }
    else { $failed++; echo "  FAIL $label\n"; }
}

echo "== lawyer subscription page ==\n";

$page = __DIR__ . '/../lawyer/subscription.php';
check('lawyer/subscription.php exists', is_file($page));
$src = (string)file_get_contents($page);
check('page resolves the plan via plan_for_firm', str_contains($src, 'plan_for_firm('));
check('page measures usage via plan_usage', str_contains($src, 'plan_usage('));
check('page lists limits from the shared catalog', str_contains($src, 'plan_limit_catalog()'));
check('page does not re-derive a limit with a raw SELECT on plans',
    !preg_match('/SELECT[^;]*FROM\s+plans\s+WHERE[^;]*max_/i', $src));

// --- The reported limit equals the enforced limit -----------------------------
$plan = plan_by_id($pdo, 2); // احترافية
check('plan 2 loads from the database', $plan !== null);

if ($plan) {
    $catalog = plan_limit_catalog();
    $usage = plan_usage($pdo, $plan, ['firm_id' => 0, 'lawyer_id' => 0]);
    check('usage covers every catalog key', count($usage) === count($catalog));

    $mismatch = [];
    foreach ($catalog as $key => $meta) {
        $reported = $usage[$key]['limit'] ?? null;
        $enforced = plan_limit_value($plan, $key);
        if ($reported !== $enforced) {
            $mismatch[] = $key;
        }
    }
    check('every reported limit equals the value plan_check enforces'
        . ($mismatch ? ' (mismatch: ' . implode(',', $mismatch) . ')' : ''), $mismatch === []);
}

// --- A NULL limit means unlimited, not zero ----------------------------------
$unlimitedPlan = null;
foreach ($pdo->query("SELECT * FROM plans WHERE status='active'")->fetchAll() as $p) {
    if ($p['ai_queries_limit'] === null) { $unlimitedPlan = $p; break; }
}
if ($unlimitedPlan) {
    check('a NULL limit reports as unlimited, not 0', plan_limit_value($unlimitedPlan, 'ai_queries_limit') === null);
} else {
    check('a plan with a NULL limit exists to test the unlimited path', false);
}

// --- plan_check refuses at the ceiling and allows below it -------------------
if ($plan) {
    $limit = plan_limit_value($plan, 'max_active_cases');
    // The scope must be measurable: plan_count_usage() returns null for an empty scope
    // and plan_check() then fails open by design, so an unmeasurable scope proves nothing.
    $lawyerId = (int)$pdo->query("SELECT lawyer_id FROM cases WHERE lawyer_id IS NOT NULL LIMIT 1")->fetchColumn();
    $firmId   = (int)$pdo->query("SELECT firm_id FROM cases WHERE firm_id IS NOT NULL LIMIT 1")->fetchColumn();
    if ($limit !== null && $lawyerId > 0) {
        $res = plan_check($pdo, $plan, ['firm_id' => $firmId, 'lawyer_id' => $lawyerId], 'max_active_cases', $limit + 1000);
        check('plan_check refuses a request past the ceiling', $res['allowed'] === false);
        check('refusal names plan_limit_reached', ($res['reason'] ?? '') === 'plan_limit_reached');
    } else {
        check('plan 2 has a ceiling and a measurable lawyer scope to test against', false);
    }
}

echo "\n{$passed} checks, {$failed} failure(s)\n";
exit($failed > 0 ? 1 : 0);
