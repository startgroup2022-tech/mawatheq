<?php
/**
 * Execution 69 — الباقات: what a plan promises is what the site shows and what the
 * server enforces.
 *
 * The reported defect was that packages, prices, features and subscriptions did not
 * agree with the database or the console. The concrete cause: `plans` carried
 * `max_staff`, `max_clients`, `max_active_cases`, `storage_mb`, `max_templates` and
 * six `feature_*` flags that **nothing read**, while the admin form could not edit
 * them and the save path could not write them. A plan could therefore advertise
 * anything in free text and enforce none of it.
 *
 * This suite attacks that from both ends:
 *
 *  1. the catalogue is the only list (no duplicate definitions, every column real);
 *  2. the admin form edits every limit and flag, and the value round-trips;
 *  3. the gates actually refuse at the boundary and only at the boundary;
 *  4. an unlimited (NULL) limit and a plan-less account are both "allow";
 *  5. every public surface describes the plan from the same rows.
 *
 * Run: php tests/exec69_plan_catalog_test.php   (app needed on 127.0.0.1:8080)
 */

$base = getenv('MAWATHEQ_BASE_URL') ?: 'http://127.0.0.1:8080';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/plan_features.php';
require_once __DIR__ . '/../includes/plans_admin.php';

$failures = 0; $checks = 0;
function check(string $label, bool $ok, string $extra = ''): void {
    global $failures, $checks;
    $checks++;
    if ($ok) { echo "  ok:   $label\n"; }
    else { $failures++; echo "  FAIL: $label" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

$jar = tempnam(sys_get_temp_dir(), 'exec69plan');
function req(string $url, ?array $post = null): array {
    global $jar;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, (string)$body];
}

echo "\n== 1. the catalogue is the single definition ==\n";
$limits = plan_limit_catalog();
$features = plan_feature_catalog();
check('the catalogue declares limits', count($limits) >= 8, (string)count($limits));
check('the catalogue declares capabilities', count($features) >= 6, (string)count($features));

// Every catalogue key must be a real column: a typo would make the gate read a missing
// key, which silently means "unlimited" and silently means no enforcement.
$cols = $pdo->query('SHOW COLUMNS FROM plans')->fetchAll(PDO::FETCH_COLUMN);
$missing = array_diff(array_merge(array_keys($limits), array_keys($features)), $cols);
check('every catalogue key is a real plans column', $missing === [], implode(',', $missing));

// The three plans the platform ships.
$seeded = $pdo->query('SELECT COUNT(*) FROM plans')->fetchColumn();
check('the seeded plans are present', (int)$seeded >= 3, (string)$seeded);

echo "\n== 2. normalisation covers everything the catalogue owns ==\n";
$post = [
    'name_ar' => 'باقة اختبار', 'name_en' => 'Test plan',
    'classification' => 'paid', 'billing_cycle' => 'both',
    'price_monthly' => '99', 'price_yearly' => '',
    'max_staff' => '4', 'max_clients' => '40', 'max_active_cases' => '20',
    'storage_mb' => '5120', 'max_templates' => '12',
    'ai_queries_limit' => '', 'document_analysis_limit' => '7', 'consultations_included' => '2',
    'status' => 'active',
];
$post['feature_calendar'] = '1';
$post['feature_finance'] = '1';
$data = plan_normalize_input($post);
foreach (['max_staff' => 4, 'max_clients' => 40, 'max_active_cases' => 20, 'storage_mb' => 5120, 'max_templates' => 12] as $k => $v) {
    check("$k is normalised", ($data[$k] ?? null) === $v, var_export($data[$k] ?? null, true));
}
check('an empty limit becomes NULL (unlimited)', array_key_exists('ai_queries_limit', $data) && $data['ai_queries_limit'] === null,
    var_export($data['ai_queries_limit'] ?? 'MISSING', true));
check('a ticked feature is 1', ($data['feature_calendar'] ?? 0) === 1);
check('an unticked feature is 0', ($data['feature_reports'] ?? 1) === 0);
check('every catalogue limit is in the payload', array_diff(array_keys($limits), array_keys($data)) === [],
    implode(',', array_diff(array_keys($limits), array_keys($data))));
check('every catalogue capability is in the payload', array_diff(array_keys($features), array_keys($data)) === []);

echo "\n== 3. the gate refuses at the boundary and allows below it ==\n";
$firmId = (int)$pdo->query('SELECT id FROM law_firms ORDER BY id LIMIT 1')->fetchColumn();
if ($firmId <= 0) {
    echo "  SKIP: no seeded firm\n";
} else {
    // A plan whose client ceiling is exactly the current count must refuse one more.
    $plan = [
        'id' => 0, 'name_ar' => 'حد اختبار', 'name_en' => 'Boundary',
        'max_clients' => 1, 'max_staff' => null, 'max_active_cases' => null,
        'storage_mb' => null, 'max_templates' => null,
        'ai_queries_limit' => null, 'document_analysis_limit' => null,
        'consultations_included' => 0,
        'feature_calendar' => 0,
    ];
    $scope = ['firm_id' => $firmId, 'user_id' => 0, 'lawyer_id' => 0];
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM clients WHERE firm_id=?')->execute([$firmId]);
    $r0 = plan_check($pdo, $plan, $scope, 'max_clients');
    check('an empty register is allowed', $r0['allowed'] === true, json_encode($r0));
    check('an empty register reports zero used', $r0['used'] === 0, var_export($r0['used'], true));

    $pdo->prepare("INSERT INTO clients (firm_id, display_name, status) VALUES (?,?, 'active')")
        ->execute([$firmId, 'عميل حدود']);
    // Ceiling 2 with 1 used: the next create must still be allowed (1+1 <= 2).
    $room = $plan; $room['max_clients'] = 2;
    $r1 = plan_check($pdo, $room, $scope, 'max_clients');
    check('one below the ceiling is allowed', $r1['allowed'] === true, json_encode($r1));
    check('the used count is reported', $r1['used'] === 1, var_export($r1['used'], true));
    check('the remaining count is one', $r1['remaining'] === 1, var_export($r1['remaining'], true));

    $r2 = plan_check($pdo, $plan, $scope, 'max_clients');
    check('a further create is refused at the ceiling', $r2['allowed'] === false, json_encode($r2));
    check('the refusal names the reason', $r2['reason'] === 'plan_limit_reached', (string)$r2['reason']);
    check('the refusal carries a customer message', !empty($r2['message']));

    // NULL means unlimited, and must not be confused with "zero allowed".
    $unlimited = $plan; $unlimited['max_clients'] = null;
    check('a NULL limit allows past the count', plan_check($pdo, $unlimited, $scope, 'max_clients')['allowed'] === true);
    check('a NULL limit is not enforced', plan_usage($pdo, $unlimited, $scope)['max_clients']['enforced'] === false);

    // No plan at all (legacy account): no limit, and no measurement.
    check('no plan allows', plan_check($pdo, null, $scope, 'max_clients')['allowed'] === true);
    check('no plan reports nothing enforced', plan_usage($pdo, null, $scope)['max_clients']['enforced'] === false);

    // A limit of 0 is a real refusal, not "unlimited".
    $zero = $plan; $zero['max_clients'] = 0;
    check('a zero limit refuses immediately', plan_check($pdo, $zero, $scope, 'max_clients')['allowed'] === false);

    $pdo->rollBack();
}

echo "\n== 4. the admin console edits every limit and flag ==\n";
[, $login] = req($base . '/admin/login.php');
preg_match('/name="csrf_token"\s+value="([^"]+)"/', $login, $m);
[$code] = req($base . '/admin/login.php', [
    'email' => 'admin@mawatheq.com', 'password' => 'Test-Password-2026', 'csrf_token' => $m[1] ?? '',
]);
check('the admin signs in', $code === 302, "HTTP $code");

[$code, $page] = req($base . '/admin/plans.php');
check('the plans console renders 200', $code === 200, "HTTP $code");
check('no fatal in the output', stripos($page, 'Fatal error') === false && stripos($page, 'Undefined variable') === false);
foreach (array_merge(array_keys($limits), array_keys($features)) as $key) {
    check("the form exposes $key", strpos($page, 'name="' . $key . '"') !== false);
}

// Round-trip: change a limit through the real form and read it back from the table.
$target = $pdo->query("SELECT * FROM plans WHERE status='active' ORDER BY id LIMIT 1")->fetch();
if (!$target) {
    echo "  SKIP: no active plan to edit\n";
} else {
    preg_match('/name="csrf_token"\s+value="([^"]+)"/', $page, $c);
    $csrf = $c[1] ?? '';
    check('a CSRF token is available', $csrf !== '');

    // The save is posted to the LIVE admin page, which runs on its own connection and
    // commits by itself — a transaction on this connection cannot undo it. The old code
    // wrapped the POST in beginTransaction()/rollBack() anyway, so every run permanently
    // overwrote the seeded free plan with the fixture numbers (3 staff / 77 clients /
    // 9000 MB), and the catalogue a customer read drifted from the migration. Restore the
    // captured row explicitly instead.
    $before = $target;
    $restore = function () use ($pdo, $before) {
        $cols = ['max_staff','max_clients','max_active_cases','storage_mb','max_templates',
                 'feature_calendar','feature_finance','feature_reports','feature_chatbot',
                 'feature_branding','feature_whatsapp'];
        $set = implode(',', array_map(fn($c) => "$c=?", $cols));
        $pdo->prepare("UPDATE plans SET $set WHERE id=?")
            ->execute(array_merge(array_map(fn($c) => $before[$c], $cols), [(int)$before['id']]));
    };
    $payload = [
        'csrf_token' => $csrf, 'action' => 'save_plan', 'plan_id' => $target['id'],
        'name_ar' => $target['name_ar'], 'name_en' => $target['name_en'],
        'description_ar' => $target['description_ar'], 'description_en' => $target['description_en'],
        'price_monthly' => $target['price_monthly'], 'price_yearly' => (string)$target['price_yearly'],
        'classification' => $target['classification'], 'billing_cycle' => $target['billing_cycle'],
        'consultation_price' => (string)$target['consultation_price'],
        'ai_queries_limit' => (string)($target['ai_queries_limit'] ?? ''),
        'document_analysis_limit' => (string)($target['document_analysis_limit'] ?? ''),
        'consultations_included' => (string)$target['consultations_included'],
        'status' => $target['status'], 'sort_order' => $target['sort_order'],
        'max_staff' => '3', 'max_clients' => '77', 'max_active_cases' => '33',
        'storage_mb' => '9000', 'max_templates' => '9',
    ];
    // One feature on, its neighbours off, so a stray "always 1" default would show.
    $payload['feature_calendar'] = '1';
    [$code, $body] = req($base . '/admin/plans.php', $payload);
    check('the save does not 500', $code === 200 || $code === 302, "HTTP $code");
    $after = $pdo->query('SELECT * FROM plans WHERE id=' . (int)$target['id'])->fetch();
    check('max_staff round-trips', (int)$after['max_staff'] === 3, (string)$after['max_staff']);
    check('max_clients round-trips', (int)$after['max_clients'] === 77, (string)$after['max_clients']);
    check('storage_mb round-trips', (int)$after['storage_mb'] === 9000, (string)$after['storage_mb']);
    check('feature_calendar is on', (int)$after['feature_calendar'] === 1);
    check('feature_reports was switched off', (int)$after['feature_reports'] === 0, (string)$after['feature_reports']);
    check('the price was not disturbed', (string)$after['price_monthly'] === (string)$before['price_monthly'],
        $after['price_monthly'] . ' vs ' . $before['price_monthly']);
    $restore();
    $reverted = $pdo->query('SELECT * FROM plans WHERE id=' . (int)$before['id'])->fetch();
    check('the fixture left the plan as it found it',
        (string)$reverted['max_clients'] === (string)$before['max_clients']
        && (string)$reverted['storage_mb'] === (string)$before['storage_mb'],
        (string)$reverted['max_clients'] . '/' . (string)$reverted['storage_mb']);
}

echo "\n== 5. every public surface describes the plan from the same row ==\n";
$planId = (int)$pdo->query("SELECT id FROM plans WHERE status='active' ORDER BY id LIMIT 1")->fetchColumn();
if ($planId > 0) {
    [$code, $api] = req($base . '/api/v1/plans.php');
    check('the plans API answers 200', $code === 200, "HTTP $code");
    $json = json_decode($api, true);
    check('the API returns a plans list', isset($json['plans']) && is_array($json['plans']));
    $row = null;
    foreach (($json['plans'] ?? []) as $p) { if ((int)$p['id'] === $planId) { $row = $p; break; } }
    check('the API includes the first active plan', $row !== null);
    if ($row !== null) {
        $db = $pdo->query('SELECT * FROM plans WHERE id=' . $planId)->fetch();
        check('the API price matches the database', (string)$row['price_monthly'] === (string)$db['price_monthly']);
        check('the API carries the client ceiling', array_key_exists('max_clients', $row));
        check('the API ceiling matches the database', (string)($row['max_clients'] ?? '') === (string)($db['max_clients'] ?? ''));
        check('the API carries the capability flags', array_key_exists('feature_calendar', $row));
    }

    // The public price table renders the same numbers, not a hardcoded copy.
    [$code, $pricing] = req($base . '/pricing.php');
    check('pricing.php renders 200', $code === 200, "HTTP $code");
    check('no fatal on the price table', stripos($pricing, 'Fatal error') === false);

    // A catalogue limit the operator set must be visible on the public card. Read the
    // current DB value and look for it rendered, rather than assuming a specific plan.
    $dbRow = $pdo->query('SELECT * FROM plans WHERE id=' . $planId)->fetch();
    if (!empty($dbRow['max_clients'])) {
        check('the price table shows the client ceiling', strpos($pricing, (string)(int)$dbRow['max_clients']) !== false);
    }

    // packages.php is the client's buying page and is correctly behind the client guard;
    // asserted as a redirect so a future change to that guard is noticed here.
    [$code, , ] = req($base . '/packages.php');
    check('packages.php requires a signed-in client (302 to login)', $code === 302, "HTTP $code");
}

echo "\n== 6. there is one definition, not three ==\n";
// A second hand-written list of limits is how the surfaces drifted apart in the first
// place. The catalogue must be the only place these column names are enumerated.
$sources = [
    'includes/plan_features.php' => file_get_contents(__DIR__ . '/../includes/plan_features.php'),
    'admin/includes/plan_form_fields.php' => file_get_contents(__DIR__ . '/../admin/includes/plan_form_fields.php'),
    'admin/plans.php' => file_get_contents(__DIR__ . '/../admin/plans.php'),
];
foreach ($sources as $name => $src) {
    check("$name does not hardcode the plans column list",
        !preg_match('/max_staff\s*,\s*max_clients|max_clients\s*,\s*max_active_cases/', $src),
        $name);
}
check('the form renders from plan_limit_catalog()', strpos($sources['admin/includes/plan_form_fields.php'], 'plan_limit_catalog()') !== false);
check('the form renders from plan_feature_catalog()', strpos($sources['admin/includes/plan_form_fields.php'], 'plan_feature_catalog()') !== false);
check('the console derives its write columns from the payload', strpos($sources['admin/plans.php'], 'array_keys($data)') !== false);

echo "\n== 7. the advertised ceilings are enforced at the write paths ==\n";
// A limit that only the display understands is marketing, not a limit. These assertions
// read the write-path source, because the behaviour itself is exercised end to end in
// tests/exec69_plan_enforcement_probe.php (which needs a lawyer session).
foreach (['max_staff' => 'lawyer/firm.php', 'max_active_cases' => 'lawyer/cases.php', 'max_templates' => 'lawyer/templates.php'] as $limit => $file) {
    $src = file_get_contents(__DIR__ . '/../' . $file);
    check("$file gates on $limit", strpos($src, "plan_check(") !== false && strpos($src, "'$limit'") !== false, $file);
}
$firmSrc = file_get_contents(__DIR__ . '/../lawyer/firm.php');
$casesSrc = file_get_contents(__DIR__ . '/../lawyer/cases.php');
check('the staff gate rejects before the INSERT', strpos($firmSrc, "\$staffCheck['allowed']") !== false);
check('the case gate rejects before the INSERT', strpos($casesSrc, "\$caseCheck['allowed']") !== false);
// The refusal must not fall through to the write: the INSERT has to be inside the allowed branch.
check('the staff INSERT is inside the allowed branch',
    (bool)preg_match('/if \(!\$staffCheck\[.allowed.\]\)\s*\{.*?\}\s*else\s*\{.*?INSERT INTO law_firm_members/s', $firmSrc));
check('the case INSERT is inside the allowed branch',
    (bool)preg_match('/if \(!\$caseCheck\[.allowed.\]\)\s*\{.*?\}\s*else\s*\{.*?INSERT INTO cases/s', $casesSrc));

// The template library is the third write path that consumes a plan ceiling, and it has
// two ways to add a row: a blank template and a copy of a platform one. Both must gate,
// and both must gate before the write.
$tplSrc = file_get_contents(__DIR__ . '/../lawyer/templates.php');
check('the template create gate rejects before template_save()',
    (bool)preg_match('/if \(!\$tplCheck\[.allowed.\]\)\s*\{.*?\}\s*else\s*\{.*?template_save\(/s', $tplSrc));
check('copying a template also checks max_templates',
    (bool)preg_match('/template_can_use\(\$src, \$firmTier\).*?plan_check\(.*?max_templates/s', $tplSrc));

echo "\n{$checks} checks, {$failures} failure(s)\n";
@unlink($jar);
exit($failures === 0 ? 0 : 1);
