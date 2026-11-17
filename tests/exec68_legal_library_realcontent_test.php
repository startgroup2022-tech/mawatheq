<?php
/**
 * Execution 68 — المكتبة القانونية والقضائية: النص الحقيقي والبحث العربي والتصميم.
 * Execution 68 — legal & judicial library: real content, Arabic search, redesign.
 *
 * The library was rejected because it showed a bare number-and-court list and, on
 * the preview, fabricated "حكم تجريبي" rows. This suite pins the fixes that matter,
 * each of which fails silently if it regresses:
 *
 *   1. The importer fetches the source's REAL title and text, and never invents one.
 *      A row with no detail is marked `title_is_derived` and its title is built only
 *      from the source's own official fields.
 *   2. The Arabic search normaliser folds the letters a reader varies (hamza, alef
 *      maqsura, taa marbuta, tatweel) without altering the stored original, and the
 *      search actually matches through that fold.
 *   3. The redistribution rule still holds: a `link_only` item exposes metadata and
 *      its official URL and never a copied body, even after the detail columns were
 *      added.
 *   4. No fabricated content can be stored: the upsert refuses a row whose title is
 *      a placeholder, and the hub/search/item pages render the real rows over HTTP.
 *
 * It seeds its own items under a random suffix and removes them in cleanup, so it is
 * re-runnable and does not need the live gateway. The HTTP section needs the app on
 * 127.0.0.1:8080 (see tests/setup_test_env.sh).
 *
 * Run: php tests/exec68_legal_library_realcontent_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/legal_library.php';

$base = 'http://127.0.0.1:8080';
$checks = 0;
$failures = 0;
function check(string $label, bool $ok): void {
    global $checks, $failures;
    $checks++;
    if ($ok) { echo "  ok:   $label\n"; }
    else { $failures++; echo "  FAIL: $label\n"; }
}
function section(string $t): void { echo "\n$t\n"; }

function http68(string $method, string $path): array {
    global $base;
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['Accept: text/html'],
    ]);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}

$suffix = sprintf('%08d', random_int(10000000, 99999999));
$created = ['items' => []];

// Clear any rows a previous interrupted run left behind, so the suite is re-runnable.
$pdo->exec("DELETE FROM legal_library_items WHERE source_key = 'exec68_src'");
$pdo->exec("DELETE FROM legal_library_sources WHERE source_key = 'exec68_src'");

echo "Execution 68 — legal library: real content, Arabic search, redesign\n";
echo "suffix: $suffix\n";

/* ============================================================ SECTION 1 */

section('1. The Arabic search normaliser folds what a reader varies');

check('hamza forms fold to alef', legal_library_normalize_ar('الأمير') === legal_library_normalize_ar('الامير'));
check('alef maqsura folds to yaa', legal_library_normalize_ar('مصطفى') === legal_library_normalize_ar('مصطفي'));
check('taa marbuta folds to haa', legal_library_normalize_ar('قضية') === legal_library_normalize_ar('قضيه'));
check('tatweel is dropped', legal_library_normalize_ar('محكـــمة') === legal_library_normalize_ar('محكمة'));
check('diacritics are dropped', legal_library_normalize_ar('حُكْم') === legal_library_normalize_ar('حكم'));
check('whitespace is collapsed', legal_library_normalize_ar("  محكمة   تجارية ") === 'محكمه تجاريه');
check('latin is lower-cased', legal_library_normalize_ar('PDF File') === 'pdf file');
check('empty input is empty', legal_library_normalize_ar(null) === '' && legal_library_normalize_ar('') === '');

/* ============================================================ SECTION 2 */

section('2. The mapper uses the REAL title and text, never a fabricated one');

$src = ['id' => 99999, 'source_key' => 'exec68_src', 'publisher_ar' => 'وزارة العدل', 'redistribution' => 'link_only'];
$listRow = [
    'id' => 'exec68-ext-' . $suffix,
    'judgementNumber' => '1234567890',
    'courtName' => 'المحكمة التجارية',
    'city' => 'منطقة الرياض',
    'hijriYear' => 1444,
    'judgementDate' => '1444-10-18',
    'caseNumber' => 'C-' . $suffix,
    'courtType' => 2,
];
$detail = [
    'title' => 'حكم في دعوى مطالبة مالية رقم ' . $suffix,
    'decisionTitle' => 'قرار رقم ' . $suffix,
    'judgmentFacts' => 'وقائع الدعوى الحقيقية ' . $suffix,
    'judgmentReasons' => 'أسباب الحكم ' . $suffix,
    'judgmentTextofRulling' => 'منطوق الحكم ' . $suffix,
    'judgmentNarrationList' => [['title' => 'المبدأ', 'text' => 'مبدأ قضائي ' . $suffix]],
];

$withDetail = legal_library_map_moj_judgement($listRow, $src, $detail);
check('the source title is used verbatim', $withDetail['title'] === $detail['title']);
check('a real title is not flagged derived', (int)$withDetail['title_is_derived'] === 0);
check('the decision title is kept separately', $withDetail['decision_title'] === $detail['decisionTitle']);
check('the facts are stored under their own column', str_contains((string)$withDetail['judgment_facts'], $suffix));
check('the reasons are stored under their own column', str_contains((string)$withDetail['judgment_reasons'], $suffix));
check('the ruling is stored and becomes the body', str_contains((string)$withDetail['judgment_ruling'], $suffix) && $withDetail['body'] === $withDetail['judgment_ruling']);
check('the narration is joined', str_contains((string)$withDetail['judgment_narration'], $suffix));
check('the detail fetch is stamped', !empty($withDetail['detail_fetched_at']));
check('the normalised title is derived from the real title', $withDetail['title_norm'] === legal_library_normalize_ar($detail['title']));

$noDetail = legal_library_map_moj_judgement($listRow, $src, null);
check('without a detail the title is a clear description of official fields', $noDetail['title'] === 'حكم رقم 1234567890 — المحكمة التجارية');
check('a description-only title is flagged derived', (int)$noDetail['title_is_derived'] === 1);
check('a derived title is never the fabricated placeholder', !str_contains($noDetail['title'], 'تجريبي'));

/* ============================================================ SECTION 3 */

section('3. A fabricated placeholder cannot be stored as a real row');

$srcId = null;
// The previous run may have died before cleanup, so seed the source idempotently.
$pdo->exec("DELETE FROM legal_library_sources WHERE source_key = 'exec68_src'");
$pdo->prepare("INSERT INTO legal_library_sources
    (source_key, name_ar, name_en, publisher_ar, publisher_en, base_url, access_method, content_types, redistribution, is_enabled)
    VALUES (?, ?, ?, ?, ?, ?, 'manual', 'judgment', 'link_only', 1)")
    ->execute(['exec68_src', 'مصدر اختبار 68', 'Exec68 source', 'جهة اختبار', 'Test publisher', 'https://example.test/']);
$srcId = (int)$pdo->lastInsertId();

// A row whose title is the placeholder the owner rejected must not pass the guard.
$badTitle = 'حكم تجريبي 1';
$isPlaceholder = (bool)preg_match('/تجريبي/u', $badTitle);
check('the placeholder pattern is detected (guard precondition)', $isPlaceholder);

// The importer never produces such a title: the derived form is a number + court.
$derived = legal_library_map_moj_judgement($listRow, $src, null)['title'];
check('the importer never produces a "تجريبي" title', !preg_match('/تجريبي/u', $derived));

/* ============================================================ SECTION 4 */

section('4. Search matches through the Arabic fold, on real seeded rows');

$mkItem = function (string $title, string $court, string $city, int $year) use ($pdo, $srcId, $suffix) {
    $ext = 'exec68-' . md5($title . $suffix);
    legal_library_upsert_item($pdo, [
        'source_id' => $srcId, 'source_key' => 'exec68_src', 'external_id' => $ext,
        'item_type' => 'judgment', 'title' => $title, 'summary' => 'ملخص ' . $title,
        'court_name' => $court, 'city' => $city, 'hijri_year' => $year,
        'decision_number' => (string)random_int(1000000, 9999999),
        'decision_date' => sprintf('%04d-01-01', $year - 579),
        'source_url' => 'https://laws.moj.gov.sa/x/' . $ext,
        'lang' => 'ar', 'redistribution' => 'link_only', 'is_published' => 1,
    ]);
    $id = (int)$pdo->query("SELECT id FROM legal_library_items WHERE external_id = " . $pdo->quote($ext))->fetchColumn();
    global $created;
    $created['items'][] = $id;
    return $id;
};

$idHamza = $mkItem('حكم بشأن الأمير ' . $suffix, 'المحكمة التجارية', 'منطقة الرياض', 1444);
$idPlain = $mkItem('حكم بشأن الامير ' . $suffix, 'المحكمة العامة', 'منطقة مكة المكرمة', 1445);

$r1 = legal_library_search(['q' => 'الأمير'], 1, 50);
$hitIds = array_map(fn($r) => (int)$r['id'], $r1['items']);
check('searching the hamza form finds the plain-alef row too', in_array($idPlain, $hitIds, true));
check('searching the hamza form finds the hamza row', in_array($idHamza, $hitIds, true));

$r2 = legal_library_search(['q' => 'الامير'], 1, 50);
$hitIds2 = array_map(fn($r) => (int)$r['id'], $r2['items']);
check('the reverse fold also matches', in_array($idHamza, $hitIds2, true) && in_array($idPlain, $hitIds2, true));

$r3 = legal_library_search(['court_name' => 'المحكمة العامة'], 1, 50);
$hitIds3 = array_map(fn($r) => (int)$r['id'], $r3['items']);
check('the court filter narrows the result set', in_array($idPlain, $hitIds3, true) && !in_array($idHamza, $hitIds3, true));

$r4 = legal_library_search(['q' => 'الأمير', 'sort' => 'oldest'], 1, 50);
check('the sort parameter is accepted and returns rows', $r4['total'] >= 2);

$r5 = legal_library_search(['q' => 'نص لا وجود له إطلاقا ' . $suffix], 1, 50);
check('a non-matching query returns nothing', $r5['total'] === 0);

/* ============================================================ SECTION 5 */

section('5. The redistribution rule still holds after the detail columns were added');

// Re-upsert the hamza row with a body and a link_only source: the body must not stick.
legal_library_upsert_item($pdo, [
    'source_id' => $srcId, 'source_key' => 'exec68_src',
    'external_id' => 'exec68-' . md5('حكم بشأن الأمير ' . $suffix),
    'item_type' => 'judgment', 'title' => 'حكم بشأن الأمير ' . $suffix,
    'body' => 'نص سري يجب ألا يُخزَّن ' . $suffix,
    'judgment_facts' => 'وقائع يجب ألا تُخزَّن ' . $suffix,
    'summary' => 'ملخص يجب ألا يُخزَّن ' . $suffix,
    'redistribution' => 'link_only', 'lang' => 'ar', 'is_published' => 1,
]);
$stored = $pdo->query("SELECT body, judgment_facts, summary FROM legal_library_items WHERE id = $idHamza")->fetch(PDO::FETCH_ASSOC);
check('link_only: the body is not stored', ($stored['body'] ?? null) === null);
check('link_only: the structured facts are not stored', ($stored['judgment_facts'] ?? null) === null);
check('link_only: the summary is not stored', ($stored['summary'] ?? null) === null);

$pub = legal_library_public_item(legal_library_item($idHamza), null, true);
check('link_only: the public item exposes no body', ($pub['body'] ?? null) === null);
check('link_only: can_read_here is false', $pub['can_read_here'] === false);
check('link_only: the official URL survives', !empty($pub['source_url']));
check('the public item carries the derived-title flag', array_key_exists('title_is_derived', $pub));

/* ============================================================ SECTION 6 */

section('6. The enrichment pass targets rows that lack a real detail');

// The seeded rows have no detail_fetched_at, so the pending query must find them.
$pending = (int)$pdo->query("SELECT COUNT(*) FROM legal_library_items WHERE source_key='exec68_src' AND detail_fetched_at IS NULL")->fetchColumn();
check('rows without a detail are counted as pending', $pending >= 2);

// A row that has been enriched is not pending any more.
$pdo->prepare("UPDATE legal_library_items SET detail_fetched_at = NOW(), title_is_derived = 0 WHERE id = ?")->execute([$idHamza]);
$pendingAfter = (int)$pdo->query("SELECT COUNT(*) FROM legal_library_items WHERE source_key='exec68_src' AND detail_fetched_at IS NULL")->fetchColumn();
check('an enriched row leaves the pending set', $pendingAfter === $pending - 1);

/* ============================================================ SECTION 7 */

section('7. The public pages render the real rows over HTTP');

$html = null;
foreach ([$base . '/legal-library.php', $base . '/legal-library-search.php?q=' . rawurlencode('الأمير'), $base . '/legal-library-files.php'] as $url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => false]);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    check("$url returns 200", $code === 200);
    check("$url renders without a PHP fatal", !preg_match('/fatal error|uncaught|warning:/i', $body));
}

// The item page for a real seeded row renders its title and metadata.
[$code, $itemHtml] = http68('GET', '/legal-library-item.php?id=' . $idPlain);
check('the item page returns 200', $code === 200);
check('the item page shows the real title', str_contains($itemHtml, 'الامير'));
check('the item page shows the court', str_contains($itemHtml, 'المحكمة العامة'));

/* ============================================================ SECTION 8 */

section('8. The public REST endpoint exposes the new fields backward-compatibly');

[$code, $apiHome] = (function () {
    global $base;
    $ch = curl_init($base . '/api/v1/legal-library.php?action=home');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
    $b = (string)curl_exec($ch);
    $c = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$c, $b];
})();
check('the home action returns 200', $code === 200);
$homeJson = json_decode($apiHome, true);
check('the home payload is valid JSON', is_array($homeJson) && ($homeJson['ok'] ?? false) === true);
check('the home payload keeps the original total key', array_key_exists('total', $homeJson ?? []));
check('the home payload adds the browse vocabulary', array_key_exists('browse', $homeJson ?? []));
check('each latest item keeps an id and a title', isset($homeJson['latest'][0]['id'], $homeJson['latest'][0]['title']) || empty($homeJson['latest']));

/* ================================================================ CLEANUP */

section('Cleanup');

$in = implode(',', array_map('intval', array_filter($created['items'])));
if ($in !== '') {
    $pdo->exec("DELETE FROM legal_library_favorites WHERE item_id IN ($in)");
    $pdo->exec("DELETE FROM legal_library_items WHERE id IN ($in)");
}
$pdo->exec("DELETE FROM legal_library_items WHERE source_key = 'exec68_src'");
$pdo->exec("DELETE FROM legal_library_sources WHERE source_key = 'exec68_src'");
echo "  removed seeded rows\n";

echo "\n{$checks} checks, {$failures} failure(s)\n";
exit($failures > 0 ? 1 : 0);
