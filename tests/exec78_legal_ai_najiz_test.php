<?php
/**
 * Execution 78 — the legal assistant is grounded in the Saudi judicial classification.
 *
 * Before this execution the Najiz taxonomy (234 case types, 640 judicial requests) was
 * reachable only through the public guide, the journey picker and the admin console; no
 * prompt ever saw it, and `law_articles` / `knowledge_sources` were both empty, so the
 * assistant answered every legal question with "no verified source". The bridge is
 * includes/legal_ai_najiz.php and this suite is its contract.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/legal_ai.php';
require_once __DIR__ . '/../includes/legal_ai_najiz.php';

$checks = 0; $fail = 0;
function ck(string $label, bool $ok, string $detail = ''): void {
    global $checks, $fail;
    $checks++;
    if ($ok) { echo "  ok   $label\n"; return; }
    $fail++;
    echo "  FAIL $label" . ($detail !== '' ? " — $detail" : '') . "\n";
}

echo "Execution 78 — legal assistant ↔ Najiz classification\n\n";

// The suite asserts the code, not the seed: if the taxonomy is absent the bridge must
// degrade, and that is checked separately. Here we need rows.
$have = 0;
try { $have = (int)$pdo->query('SELECT COUNT(*) FROM najiz_case_types')->fetchColumn(); } catch (Throwable $e) {}
if ($have === 0) {
    echo "SKIP: najiz_case_types is empty — run includes/najiz_import.php first.\n";
    echo "0 checks, 0 failure(s)\n";
    exit(0);
}

// --- 1. Matching a natural-language question ------------------------------------
echo "[1] free-text matching\n";
$m = legal_ai_najiz_matches($pdo, 'حضانة أطفالي', 3);
ck('حضانة matches at least one classification', count($m) > 0);
ck('the best match is the custody category', count($m) > 0 && mb_strpos($m[0]['name_ar'], 'حضانة') !== false,
   $m[0]['name_ar'] ?? 'none');

$m2 = legal_ai_najiz_matches($pdo, 'كم نفقة زوجتي', 3);
ck('نفقة matches', count($m2) > 0);
ck('the best match is a maintenance category', count($m2) > 0 && mb_strpos($m2[0]['name_ar'], 'نفقة') !== false,
   $m2[0]['name_ar'] ?? 'none');

// --- 2. The short-token trap that produced confident wrong answers --------------
echo "[2] no confident-wrong match from a question word\n";
// "كم" is 2 chars and matches inside "الحكم"; the length floor must reject a match that
// is carried only by it. Arbitrations and rulings were the false positives observed.
$bad = legal_ai_najiz_matches($pdo, 'كم عدد الجلسات', 3);
$badNames = array_map(fn($x) => $x['name_ar'], $bad);
ck('a question with no substantive term yields no match', count($bad) === 0, implode(' | ', $badNames));

// --- 3. Shape of a match --------------------------------------------------------
echo "[3] match shape\n";
if ($m) {
    $first = $m[0];
    ck('has an integer id', is_int($first['id']) && $first['id'] > 0);
    ck('has a breadcrumb of category / subcategory', mb_strpos($first['breadcrumb'], '/') !== false, $first['breadcrumb']);
    ck('requests are a list, not one blob', is_array($first['requests']));
    ck('a placeholder definition is suppressed', mb_strpos($first['definition'], 'لا يوجد تعريف مسجل') === false);
}

// --- 4. Context block is labelled and bounded -----------------------------------
echo "[4] prompt context\n";
$ctx = legal_ai_najiz_context($m, true);
ck('context is non-empty for real matches', $ctx !== '');
ck('context marks each block with BEGIN/END', mb_strpos($ctx, '[BEGIN CLASSIFICATION 1]') !== false);
ck('context states it is not a statute', mb_strpos($ctx, 'ليس نصاً نظامياً') !== false);
ck('context carries no article number claim', !preg_match('/المادة\s*\d+/u', $ctx));
ck('empty matches produce no context', legal_ai_najiz_context([], true) === '');

// --- 5. Citations are marked unverified (classification is not statute) ---------
echo "[5] citations\n";
$cites = legal_ai_najiz_citations($m);
ck('one citation per match', count($cites) === count($m));
ck('citations are typed as classification', ($cites[0]['type'] ?? '') === 'najiz_classification');
ck('citations are NOT marked verified', ($cites[0]['verified'] ?? true) === false);
ck('citation links to the public guide', str_contains($cites[0]['url'] ?? '', '/classification-guide.php'));

// --- 6. The plain answer says it is not legal advice ----------------------------
echo "[6] deterministic answer\n";
$plain = legal_ai_najiz_plain_answer($m, true);
ck('plain answer exists', is_array($plain) && ($plain['reply'] ?? '') !== '');
ck('plain answer names the claim type', mb_strpos($plain['reply'], $m[0]['name_ar']) !== false);
ck('plain answer disclaims legal opinion', mb_strpos($plain['reply'], 'ليس رأياً قانونياً') !== false);
ck('plain answer is null with no matches', legal_ai_najiz_plain_answer([], true) === null);
$plainEn = legal_ai_najiz_plain_answer($m, false);
ck('English plain answer disclaims legal advice', mb_strpos($plainEn['reply'], 'not legal advice') !== false);

// --- 7. End to end through the real function, no AI provider --------------------
echo "[7] generate_grounded_legal_answer (no provider configured)\n";
$settings = [];
foreach ($pdo->query('SELECT setting_key, setting_value FROM site_settings') as $r) $settings[$r['setting_key']] = $r['setting_value'];
$settings['ai_provider'] = '';   // force the no-provider path this execution is about
$ans = generate_grounded_legal_answer('حضانة أطفالي', true, $settings, $pdo, 1, 'client');
ck('answer is returned', is_array($ans));
ck('answer is grounded, not the "no source" refusal',
   mb_strpos((string)($ans['reply'] ?? ''), 'لم أجد مادة ذات صلة') === false,
   mb_substr((string)($ans['reply'] ?? ''), 0, 80));
ck('answer carries sources', count($ans['sources'] ?? []) > 0);
ck('review_status is source_only', ($ans['review_status'] ?? '') === 'source_only');
ck('the classification reached the sources', in_array('najiz_classification', array_column($ans['sources'] ?? [], 'type'), true));

// The audit row must actually persist: the enum has no 'classification' value, and a
// rejected insert means the audit trail silently loses these answers.
$logged = (int)$pdo->query("SELECT COUNT(*) FROM ai_answer_reviews WHERE module_id='legal_core' AND retrieval_method='keyword'")->fetchColumn();
ck('audit rows are written for classification answers', $logged > 0, "count=$logged");

// --- 8. A question with no match still refuses honestly ------------------------
echo "[8] no-match honesty\n";
$none = generate_grounded_legal_answer('شكرا جزيلا', true, $settings, $pdo, 1, 'client');
ck('no match -> the verified-source refusal is preserved',
   mb_strpos((string)($none['reply'] ?? ''), 'لم أجد مادة ذات صلة') !== false);
ck('no match -> no sources invented', count($none['sources'] ?? []) === 0);

// --- 9. Degradation when the tables are absent --------------------------------
echo "[9] degrades when the classification tables are missing\n";
// Simulates a deployment that never applied upgrade_v59.sql: the bridge must return an
// empty list, not throw, so the rest of the assistant keeps working.
$probe = new class extends PDO {
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false { throw new PDOException('no table'); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false { throw new PDOException('no table'); }
};
$deg = legal_ai_najiz_matches($probe, 'حضانة أطفالي', 3);
ck('missing tables yield an empty match list', $deg === []);
ck('missing tables do not throw', true);

echo "\n$checks checks, $fail failure(s)\n";
exit($fail === 0 ? 0 : 1);
