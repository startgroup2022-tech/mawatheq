<?php
/**
 * Execution 68 — plan prices reach every client in the currency's own precision.
 *
 * `plans.price_monthly` is DECIMAL(10,3), so it reads back as "149.000" no matter which
 * currency the deployment uses. Two things then went wrong:
 *
 *   - the mobile API sent that raw string, and the app printed "149.000 SAR" for a
 *     2-decimal currency;
 *   - the pricing screen rounded it to a whole number, so "149.00" read "149".
 *
 * Both are the defect AGENTS.md forbids on the web side: an amount that does not go
 * through includes/currency.php. The fix is that the API now also sends a `*_display`
 * field produced by `currency_number()`, the same helper `money()` uses.
 *
 * This suite asserts the *contract*, not one deployment's numbers: whatever the plans
 * table holds, the display string must equal `currency_number(raw)` and must carry the
 * currency's own decimal count.
 *
 * Run: php tests/exec68_plan_price_format_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/currency.php';

$failures = 0; $checks = 0;
function check(string $label, bool $ok, string $extra = ''): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) { $failures++; echo "  FAIL: $label" . ($extra !== '' ? " ($extra)" : '') . "\n"; }
    else echo "  ok:   $label\n";
}

$decimals = currency_decimals();
echo "\n== 1. the currency helper is the single formatter ==\n";
check('currency_code() is a non-empty code', currency_code() !== '', currency_code());
check('currency_decimals() is 0..3', $decimals >= 0 && $decimals <= 3, (string)$decimals);
// SAR/AED/QAR/EGP/USD/EUR are 2-decimal; BHD/KWD/OMR/JOD are 3.
check('currency_number(149) uses the currency precision',
    currency_number(149) === number_format(149, $decimals, '.', ','),
    currency_number(149) . ' vs decimals=' . $decimals);
check('currency_number(1490) groups thousands',
    currency_number(1490) === number_format(1490, $decimals, '.', ','),
    currency_number(1490));

echo "\n== 2. plans.php sends a display price that matches the helper ==\n";

// The endpoint is exercised through its real include path, with a session-free request.
// It is public (no auth), so a CLI include is representative.
$raw = shell_exec('php ' . escapeshellarg(__DIR__ . '/../api/v1/plans.php') . ' 2>/dev/null');
$payload = json_decode((string)$raw, true);

if (!is_array($payload) || empty($payload['plans'])) {
    check('plans.php returned a plans array', false, 'payload was: ' . substr((string)$raw, 0, 200));
} else {
    check('plans.php returned a plans array', true);
    check('plans.php declares the active currency',
        isset($payload['currency']) && $payload['currency'] === currency_code(),
        (string)($payload['currency'] ?? 'missing'));

    $mismatches = 0; $missing = 0; $checked = 0;
    foreach ($payload['plans'] as $plan) {
        foreach (['price_monthly', 'price_yearly'] as $field) {
            if (!array_key_exists($field, $plan) || $plan[$field] === null) continue;
            $displayKey = $field . '_display';
            if (!array_key_exists($displayKey, $plan)) { $missing++; continue; }
            $expected = currency_number($plan[$field]);
            $checked++;
            if ($plan[$displayKey] !== $expected) {
                $mismatches++;
                echo "        {$plan['name_ar']} {$field}: raw={$plan[$field]} display={$plan[$displayKey]} expected={$expected}\n";
            }
        }
    }
    check('every plan carries the display fields', $missing === 0, $missing . ' missing');
    check('every display value equals currency_number(raw)', $mismatches === 0, $mismatches . ' mismatched');
    check('at least one price was checked', $checked > 0, (string)$checked);

    // The regression itself: a 2-decimal currency must never ship a 3-decimal label.
    $threeDecimalLabels = 0;
    foreach ($payload['plans'] as $plan) {
        foreach (['price_monthly_display', 'price_yearly_display'] as $k) {
            if (!isset($plan[$k])) continue;
            if ($decimals < 3 && preg_match('/\.\d{' . ($decimals + 1) . ',}$/', $plan[$k])) $threeDecimalLabels++;
        }
    }
    check('no display price carries more decimals than the currency',
        $threeDecimalLabels === 0, $threeDecimalLabels . ' over-precise labels');
}

echo "\n== 3. subscription.php sends the same display fields ==\n";
// The endpoint needs an authenticated lawyer. Issue a real token and call it over HTTP so
// the assertion runs against the response a client actually receives, not the source text.
$token = trim((string)shell_exec(
    'php -r ' . escapeshellarg(
        'require "' . __DIR__ . '/../config/config.php"; require "' . __DIR__ . '/../api/v1/bootstrap.php";'
        . ' echo api_issue_token($pdo, "lawyer", 1, "exec68-price-test");'
    ) . ' 2>/dev/null'
));
check('a lawyer token could be issued', strlen($token) >= 32, 'len=' . strlen($token));

$sub = null;
if ($token !== '') {
    $ch = curl_init('http://127.0.0.1:8080/api/v1/subscription.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    $sub = json_decode((string)$body, true);
}

if (!is_array($sub) || empty($sub['ok'])) {
    // No dev server on 8080 is an environment gap, not a code failure — but it must be
    // visible rather than silently green.
    check('subscription.php answered over HTTP (needs php -S on 127.0.0.1:8080)', false,
        'no usable response');
} else {
    check('subscription.php answered over HTTP', true);
    $p = $sub['plan'] ?? null;
    if (!is_array($p)) {
        check('a plan is attached to the lawyer', false, 'plan was null');
    } else {
        check('a plan is attached to the lawyer', true, (string)($p['name_ar'] ?? ''));
        check('plan carries price_monthly_display', array_key_exists('price_monthly_display', $p));
        if (isset($p['price_monthly'])) {
            check('plan display equals currency_number(raw)',
                ($p['price_monthly_display'] ?? null) === currency_number($p['price_monthly']),
                (string)($p['price_monthly_display'] ?? 'null') . ' vs ' . currency_number($p['price_monthly']));
            check('plan display carries the currency precision',
                $decimals >= 3 || !preg_match('/\.\d{' . ($decimals + 1) . ',}$/', (string)$p['price_monthly_display']),
                (string)$p['price_monthly_display']);
        }
    }
    check('limits are typed (enforced flag present on each)',
        !empty($sub['limits']) && array_reduce($sub['limits'],
            fn($c, $l) => $c && array_key_exists('enforced', $l), true));
}

echo "\n== 4. the app's model prefers the display field ==\n";
$model = (string)file_get_contents(__DIR__ . '/../apps/mobile/lib/core/network/workspace_models.dart');
check('workspace model reads price_monthly_display',
    str_contains($model, "p['price_monthly_display']"));
$planModel = (string)file_get_contents(__DIR__ . '/../apps/mobile/lib/core/network/models.dart');
check('Plan model exposes a display label', str_contains($planModel, 'monthlyLabel'));
$pricing = (string)file_get_contents(__DIR__ . '/../apps/mobile/lib/features/public/pricing_screen.dart');
check('pricing screen prints the label, not a rounded double',
    str_contains($pricing, 'monthlyLabel') && !str_contains($pricing, 'priceMonthly.toStringAsFixed(0)'));

echo "\n{$checks} checks, {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
