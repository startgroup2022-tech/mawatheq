<?php
/**
 * Execution 78 — a plan edited in the admin console must reach the customer page and the
 * checkout total, with no stale price anywhere in between.
 *
 * The owner's complaint was that plans/prices did not feel connected to the database or the
 * console. Testing each layer separately cannot catch that: admin/plans.php can save, and
 * packages.php can render, while the rendered page still shows a cached or hard-coded
 * number. This suite walks the whole path over real HTTP:
 *
 *   admin login -> save_plan (149 -> 199) -> plans row -> /packages.php -> /checkout.php
 *
 * and then restores the price, whatever happens. It also re-asserts the two properties the
 * pricing surface depends on: the browser cannot set the amount, and an inactive plan cannot
 * be bought.
 */

require_once __DIR__ . '/../config/config.php';

$checks = 0;
$failures = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $checks, $failures;
    $checks++;
    if ($ok) { echo "  ok:   $label\n"; return; }
    $failures++;
    echo "  FAIL: $label" . ($detail !== '' ? " — $detail" : '') . "\n";
}

const BASE = 'http://127.0.0.1:8080';

/** A cookie-jar-backed POST/GET pair, so the session is real. */
function http_client(): array {
    $jar = tempnam(sys_get_temp_dir(), 'cj');
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 30,
    ]);
    return [$ch, $jar];
}
function http_get($ch, string $url): string {
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, false);
    return (string)curl_exec($ch);
}
function http_post($ch, string $url, array $data): string {
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    return (string)curl_exec($ch);
}
function csrf_from(string $html): string {
    return preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m) ? $m[1] : '';
}

// The suite is throttled by the same limiter the browser is; a previous suite's failures
// would otherwise make these logins report "wrong password".
$pdo->exec('DELETE FROM auth_rate_limits');

$origMonthly = null;
$origYearly = null;
$jar = null;

try {
    echo "== 1. the seeded plans are readable and priced from the database ==\n";
    $plans = $pdo->query("SELECT * FROM plans WHERE status='active' ORDER BY sort_order")->fetchAll();
    check('at least one active plan exists', count($plans) > 0);
    $paid = null;
    foreach ($plans as $p) { if ((float)$p['price_monthly'] > 0) { $paid = $p; break; } }
    check('a paid plan exists to exercise the flow', $paid !== null);
    if ($paid === null) { throw new RuntimeException('no paid plan to test'); }
    $planId = (int)$paid['id'];
    $origMonthly = (float)$paid['price_monthly'];
    $origYearly = $paid['price_yearly'];

    echo "\n== 2. the admin console reaches the plans editor ==\n";
    [$ach, $ajar] = http_client();
    $jar = $ajar;
    $login = http_get($ach, BASE . '/admin/login.php');
    $tok = csrf_from($login);
    check('admin login page carries a CSRF token', $tok !== '');
    http_post($ach, BASE . '/admin/login.php', [
        'email' => 'admin@mawatheq.com', 'password' => 'Test-Password-2026', 'csrf_token' => $tok,
    ]);
    $editor = http_get($ach, BASE . '/admin/plans.php');
    check('the plans editor renders', strlen($editor) > 5000, strlen($editor) . ' bytes');
    check('it offers a save_plan action', str_contains($editor, 'save_plan'));
    $editTok = csrf_from($editor);
    check('the editor carries a CSRF token', $editTok !== '');

    echo "\n== 3. a saved price change is persisted ==\n";
    $newPrice = $origMonthly + 50.0;
    http_post($ach, BASE . '/admin/plans.php', [
        'csrf_token' => $editTok, 'action' => 'save_plan', 'plan_id' => $planId,
        'name_ar' => $paid['name_ar'], 'name_en' => $paid['name_en'],
        'description_ar' => (string)$paid['description_ar'], 'description_en' => (string)$paid['description_en'],
        'price_monthly' => (string)$newPrice, 'price_yearly' => (string)$paid['price_yearly'],
        'ai_queries_limit' => (string)($paid['ai_queries_limit'] ?? ''),
        'document_analysis_limit' => (string)($paid['document_analysis_limit'] ?? ''),
        'consultations_included' => (string)$paid['consultations_included'],
        'classification' => (string)$paid['classification'], 'billing_cycle' => 'both',
        'status' => 'active', 'sort_order' => (string)$paid['sort_order'],
        'is_featured' => $paid['is_featured'] ? '1' : '',
    ]);
    $stored = (float)$pdo->query("SELECT price_monthly FROM plans WHERE id=$planId")->fetchColumn();
    check('the database holds the new price', abs($stored - $newPrice) < 0.005, "stored $stored, wanted $newPrice");

    echo "\n== 4. the customer page renders the new price (and not the old one) ==\n";
    [$cch, $cjar] = http_client();
    $lp = http_get($cch, BASE . '/login.php');
    http_post($cch, BASE . '/login.php', [
        'email' => 'client-a@example.com', 'password' => 'Test-Password-2026', 'csrf_token' => csrf_from($lp),
    ]);
    $pk = http_get($cch, BASE . '/packages.php');
    check('the packages page renders', strlen($pk) > 3000, strlen($pk) . ' bytes');
    $newFmt = number_format($newPrice, 2, '.', ',');
    $oldFmt = number_format($origMonthly, 2, '.', ',');
    check("the page shows the new price $newFmt", str_contains($pk, $newFmt));
    check("the page no longer shows the old price $oldFmt", !str_contains($pk, $oldFmt));
    check('the packages page reads plans from the database (no hard-coded list)',
        str_contains((string)file_get_contents(__DIR__ . '/../packages.php'), 'FROM plans'));

    echo "\n== 5. checkout charges the database price, not a browser-supplied amount ==\n";
    $coTok = csrf_from($pk);
    // A tampered amount is the classic price-manipulation attack.
    $co = http_post($cch, BASE . '/checkout.php', [
        'csrf_token' => $coTok, 'action' => 'checkout', 'plan_id' => $planId,
        'cycle' => 'monthly', 'amount' => '1',
    ]);
    check('checkout does not show the tampered 1.00', !str_contains($co, '1.00 ر'));
    $order = $pdo->query("SELECT * FROM package_orders ORDER BY id DESC LIMIT 1")->fetch();
    check('an order was created', (bool)$order);
    if ($order) {
        check('the order amount is the plan price', abs((float)$order['amount'] - $newPrice) < 0.005,
            "amount {$order['amount']}");
        check('the order carries the site currency', (string)$order['currency'] !== '');
        $pdo->prepare('DELETE FROM package_orders WHERE id=?')->execute([(int)$order['id']]);
    }

    echo "\n== 6. the browser cannot buy a hidden plan ==\n";
    $pdo->prepare("UPDATE plans SET status='hidden' WHERE id=?")->execute([$planId]);
    $coTok2 = csrf_from(http_get($cch, BASE . '/packages.php'));
    $before = (int)$pdo->query('SELECT COUNT(*) FROM package_orders')->fetchColumn();
    http_post($cch, BASE . '/checkout.php', [
        'csrf_token' => $coTok2, 'action' => 'checkout', 'plan_id' => $planId, 'cycle' => 'monthly',
    ]);
    $after = (int)$pdo->query('SELECT COUNT(*) FROM package_orders')->fetchColumn();
    check('a hidden plan cannot be ordered', $after === $before, "orders $before -> $after");
    $pdo->prepare("UPDATE plans SET status='active' WHERE id=?")->execute([$planId]);
} catch (Throwable $e) {
    $failures++;
    $checks++;
    echo "  FAIL: unexpected error — " . $e->getMessage() . "\n";
} finally {
    // Restore the shipped price whatever happened, and drop anything this suite created.
    if ($origMonthly !== null) {
        $pdo->prepare('UPDATE plans SET price_monthly=?, price_yearly=? WHERE id=?')
            ->execute([$origMonthly, $origYearly, $planId ?? 0]);
    }
    if ($jar) @unlink($jar);
}

echo "\n" . str_repeat('-', 60) . "\n";
echo "  Execution 78 plans round-trip: $checks checks, $failures failure(s)\n";
exit($failures > 0 ? 1 : 0);
