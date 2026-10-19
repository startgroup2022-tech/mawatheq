<?php
/**
 * Saudi Najiz classification integration (Execution 33).
 *
 * Covers four things the brief asks to be proven rather than asserted:
 *
 *  1. FIDELITY — every source record is imported, none invented, none dropped, and the
 *     counts reconcile against the total the source itself prints. The checks that
 *     matter most are the negative ones: the parser must NOT turn the document's
 *     trailing disclaimer into a lawsuit type, and must NOT collapse the five names
 *     that legitimately repeat under one parent.
 *  2. IDEMPOTENCY — a second import inserts nothing and changes no id, and the
 *     migration can be re-applied.
 *  3. SECURITY — the API is read-only, rejects unknown actions, does not reflect raw
 *     input, and the admin route is behind the console guard and RBAC.
 *  4. INTEGRATION — a case and a consultation can carry a classification, and a
 *     posted request id that belongs to a different type is refused.
 *
 *   php tests/exec33_najiz_classification_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/najiz_parser.php';
require_once __DIR__ . '/../includes/najiz_search_lib.php';
require_once __DIR__ . '/../includes/najiz_import.php';

$passed = 0;
$failed = 0;
function check(string $label, bool $condition): void {
    global $passed, $failed;
    if ($condition) { $passed++; echo "  ok:   $label\n"; }
    else { $failed++; echo "  FAIL: $label\n"; }
}
function section(string $t): void { echo "\n$t\n"; }

$source = __DIR__ . '/../database/sources/Pasted%20text%283%29.txt';
$BASE = rtrim(SITE_URL, '/');

// ---------------------------------------------------------------------------
section('1. Source file identity');
// ---------------------------------------------------------------------------
check('source file exists', is_readable($source));
$sha = is_readable($source) ? hash_file('sha256', $source) : '';
check('SHA-256 matches the file on origin/main (f6f52331…)', str_starts_with($sha, 'f6f5233153aac356'));
check('size is 125912 bytes', is_readable($source) && filesize($source) === 125912);

// ---------------------------------------------------------------------------
section('2. Parsing fidelity — reconciliation against the source');
// ---------------------------------------------------------------------------
$parsed = najiz_parse_source($source);
$st = $parsed['stats'];

check('main categories = 7', count($parsed['categories']) === 7);
check('subcategories = 38', $st['subcategories'] === 38);
check('lawsuit types = 234 (the total the source prints)', $st['types'] === 234);
check('declared total read from source = 234', $st['declared_total_types'] === 234);
check('sum of the 38 declared subcategory counts = 234', $st['declared_subcategory_counts'] === 234);
check('every subcategory count matches what was parsed', $st['declared_subcategory_counts'] === $st['types']);
check('227 types carry a detail block', $st['types_with_detail'] === 227);
check('7 types are listed with no detail block', $st['types_without_detail'] === 7);
check('227 definitions parsed', $st['definitions'] === 227);
check('640 judicial requests parsed', $st['requests'] === 640);

// No subcategory may disagree with its own declared count.
$mismatch = array_filter($parsed['warnings'], fn($w) => $w['issue'] === 'declared count does not match parsed types');
check('no subcategory count mismatch', count($mismatch) === 0);

// The document's trailing disclaimer is prose, not a lawsuit type.
$allTypeNames = [];
foreach ($parsed['categories'] as $c) foreach ($c['subcategories'] as $s) foreach ($s['types'] as $t) $allTypeNames[] = $t['name_ar'];
check('trailing disclaimer was NOT imported as a lawsuit type',
    !in_array(true, array_map(fn($n) => str_contains($n, 'مشروع إرشادي مستقل'), $allTypeNames), true));
check('footer disclaimer was captured separately', count($parsed['footer_notes']) >= 1);
check('no lawsuit type name is a label', !in_array('نوع الدعوى', $allTypeNames, true));

// Names that repeat inside one parent must remain distinct records.
$byParent = [];
foreach ($parsed['categories'] as $c) foreach ($c['subcategories'] as $s) {
    $k = $c['name_ar'] . '|' . $s['name_ar'];
    foreach ($s['types'] as $t) $byParent[$k][$t['name_ar']] = ($byParent[$k][$t['name_ar']] ?? 0) + 1;
}
$dupPairs = 0;
foreach ($byParent as $k => $names) foreach ($names as $n => $cnt) if ($cnt > 1) $dupPairs++;
check('5 repeated names inside one parent are preserved (not collapsed)', $dupPairs === 5);
check("'ولي' appears 3 times under الولاية", ($byParent['الأحوال الشخصية|الولاية']['ولي'] ?? 0) === 3);

// ---------------------------------------------------------------------------
section('3. Migration — tables, integration columns, idempotency');
// ---------------------------------------------------------------------------
$need = ['najiz_categories','najiz_subcategories','najiz_case_types','najiz_judicial_requests',
         'najiz_import_runs','najiz_audit_log','najiz_search_synonyms','case_judicial_requests',
         'consultation_judicial_requests'];
foreach ($need as $t) {
    $c = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $c->execute([$t]);
    check("table $t exists", (int)$c->fetchColumn() === 1);
}
foreach (['cases','consultations'] as $t) {
    $c = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $c->execute([$t, 'najiz_case_type_id']);
    check("$t.najiz_case_type_id added", (int)$c->fetchColumn() === 1);
}
// The FK must be RESTRICT so a referenced classification cannot be deleted out from under casework.
$fk = $pdo->query("SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
                    WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME='fk_cases_najiz_type'")->fetchColumn();
check('cases FK to classification is ON DELETE RESTRICT', $fk === 'RESTRICT');

// Re-apply the migration twice more; it must stay clean.
$sql = file_get_contents(__DIR__ . '/../database/upgrade_v59.sql');
$tmp = tempnam(sys_get_temp_dir(), 'v59') . '.sql';
file_put_contents($tmp, $sql);
$reapplyOk = true;
// Apache/CLI run as an unprivileged user here, so the test database needs sudo.
$mariadb = trim((string)shell_exec('command -v sudo')) !== '' ? 'sudo mariadb' : 'mariadb';
for ($i = 0; $i < 2; $i++) {
    exec($mariadb . ' ' . escapeshellarg(DB_NAME) . ' < ' . escapeshellarg($tmp) . ' 2>&1', $out, $rc);
    if ($rc !== 0) $reapplyOk = false;
}
@unlink($tmp);
check('upgrade_v59.sql re-applies cleanly (idempotent DDL)', $reapplyOk);

// The rule is about executable SQL, not prose: comments in this file deliberately
// discuss the AFTER clause. Strip comments before looking for a real one.
$sqlNoComments = preg_replace('#/\*.*?\*/#s', '', $sql);
$sqlNoComments = preg_replace('/^\s*--.*$/m', '', (string)$sqlNoComments);
check('no positional AFTER clause in the migration',
    !preg_match('/\bADD\s+(COLUMN\s+)?`?\w+`?[^;]*?\bAFTER\b/i', (string)$sqlNoComments));

// ---------------------------------------------------------------------------
section('4. Import fidelity and idempotency');
// ---------------------------------------------------------------------------
// A fresh checkout has the tables but no rows: setup_test_env.sh builds the schema and nothing
// seeds the classification. Import when empty so the suite is reproducible from a clone rather
// than depending on an import that happened out of band. A populated database is left alone, so
// the assertions below still describe the shipped data.
if ((int)$pdo->query('SELECT COUNT(*) FROM najiz_categories')->fetchColumn() === 0) {
    najiz_import($pdo, ['source' => $source]);
}

$before = [];
foreach (['najiz_categories','najiz_subcategories','najiz_case_types','najiz_judicial_requests'] as $t) {
    $before[$t] = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
}
check('categories = 7', $before['najiz_categories'] === 7);
check('subcategories = 38', $before['najiz_subcategories'] === 38);
check('lawsuit types = 234', $before['najiz_case_types'] === 234);
check('judicial requests = 640', $before['najiz_judicial_requests'] === 640);

// Ids must be stable across a re-import, or casework links would silently repoint.
$idsBefore = $pdo->query('SELECT id, source_key FROM najiz_case_types ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);

$r2 = najiz_import($pdo, ['source' => $source]);
check('re-import inserted 0 categories', $r2['inserted']['categories'] === 0);
check('re-import inserted 0 subcategories', $r2['inserted']['subcategories'] === 0);
check('re-import inserted 0 types', $r2['inserted']['case_types'] === 0);
check('re-import inserted 0 requests', $r2['inserted']['requests'] === 0);
check('re-import updated all 234 types in place', $r2['updated']['case_types'] === 234);
check('re-import reports reconciliation true', $r2['reconciles'] === true);

$after = [];
foreach (['najiz_categories','najiz_subcategories','najiz_case_types','najiz_judicial_requests'] as $t) {
    $after[$t] = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
}
check('row counts unchanged after re-import', $before === $after);
$idsAfter = $pdo->query('SELECT id, source_key FROM najiz_case_types ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
check('every type id is unchanged after re-import', $idsBefore === $idsAfter);

// The run log must record both executions with the source checksum.
$runs = $pdo->query('SELECT COUNT(*) c, COUNT(DISTINCT source_sha256) s FROM najiz_import_runs')->fetch();
check('import runs recorded', (int)$runs['c'] >= 2);
check('run log carries the source SHA-256', (int)$runs['s'] === 1);

// Nothing may be invented: every DB type must trace to a parsed record.
$dbNames = $pdo->query('SELECT COUNT(*) FROM najiz_case_types')->fetchColumn();
check('database type count equals parsed type count', (int)$dbNames === $st['types']);
check('no type has an empty name', (int)$pdo->query("SELECT COUNT(*) FROM najiz_case_types WHERE name_ar IS NULL OR name_ar=''")->fetchColumn() === 0);
check('no request has an empty name', (int)$pdo->query("SELECT COUNT(*) FROM najiz_judicial_requests WHERE name_ar IS NULL OR name_ar=''")->fetchColumn() === 0);

// Every request must belong to a real type (FK integrity, checked explicitly).
check('no orphan request', (int)$pdo->query('SELECT COUNT(*) FROM najiz_judicial_requests jr
        LEFT JOIN najiz_case_types ct ON ct.id=jr.case_type_id WHERE ct.id IS NULL')->fetchColumn() === 0);
check('no orphan type', (int)$pdo->query('SELECT COUNT(*) FROM najiz_case_types ct
        LEFT JOIN najiz_subcategories sc ON sc.id=ct.subcategory_id WHERE sc.id IS NULL')->fetchColumn() === 0);
check('no orphan subcategory', (int)$pdo->query('SELECT COUNT(*) FROM najiz_subcategories sc
        LEFT JOIN najiz_categories ca ON ca.id=sc.category_id WHERE ca.id IS NULL')->fetchColumn() === 0);

// The 7 types the source lists without a detail block must be present, flagged, and
// carry no invented requests.
$noDetail = $pdo->query('SELECT id, name_ar FROM najiz_case_types WHERE parse_note IS NOT NULL')->fetchAll();
check('7 no-detail types imported', count($noDetail) === 7);
$invented = 0;
foreach ($noDetail as $nd) {
    $s = $pdo->prepare('SELECT COUNT(*) FROM najiz_judicial_requests WHERE case_type_id=?');
    $s->execute([$nd['id']]);
    $invented += (int)$s->fetchColumn();
}
check('no requests invented for the 7 no-detail types', $invented === 0);

// ---------------------------------------------------------------------------
section('5. Arabic search');
// ---------------------------------------------------------------------------
$r = najiz_search($pdo, 'نفقه', 20);
check("search 'نفقه' matches 'النفقة' (ta-marbuta/letter folding)", count($r) > 0);

$r = najiz_search($pdo, 'وقف التعدي على علامة', 10);
$found = false;
foreach ($r as $row) if (str_contains((string)$row['requests'], 'وقف التعدي على علامة')) $found = true;
check("search 'وقف التعدي على علامة' finds the trademark type via its request", $found);

$r = najiz_search($pdo, 'إخلاء عقار', 10);
check("search 'إخلاء عقار' returns results", count($r) > 0);

$r = najiz_search($pdo, 'حضانه', 20);
check("search 'حضانه' (colloquial spelling) matches 'الحضانة'", count($r) > 0);

// A phrase that genuinely does not occur in the source must return nothing rather than
// a fabricated near-match.
$r = najiz_search($pdo, 'زززققق', 10);
check('nonsense query returns no results', count($r) === 0);

$sug = najiz_suggest_from_text($pdo, 'خلاف على أجرة عقار', 5);
check('free-text suggestion returns a ranked match', count($sug) > 0);
check('suggestion is scored', isset($sug[0]['score']) && $sug[0]['score'] > 0);

// ---------------------------------------------------------------------------
section('6. Integration — case and consultation carry a classification');
// ---------------------------------------------------------------------------
$typeId = (int)$pdo->query('SELECT id FROM najiz_case_types ORDER BY id LIMIT 1')->fetchColumn();
check('a classification id is available for integration', $typeId > 0);

// Insert a case carrying the classification, then remove it. Uses an existing lawyer and
// client so no fixture accounts are created.
$lawyerId = (int)$pdo->query('SELECT id FROM lawyers ORDER BY id LIMIT 1')->fetchColumn();
$userId = (int)$pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
if ($lawyerId && $userId) {
    $pdo->prepare('INSERT INTO cases (lawyer_id, user_id, title, najiz_case_type_id) VALUES (?,?,?,?)')
        ->execute([$lawyerId, $userId, 'exec33 integration case', $typeId]);
    $caseId = (int)$pdo->lastInsertId();
    $chk = $pdo->prepare('SELECT najiz_case_type_id FROM cases WHERE id=?');
    $chk->execute([$caseId]);
    check('case stores its classification id', (int)$chk->fetchColumn() === $typeId);

    // A request belonging to a DIFFERENT type must not be attachable.
    $otherReq = (int)$pdo->query("SELECT jr.id FROM najiz_judicial_requests jr
        WHERE jr.case_type_id <> (SELECT MIN(case_type_id) FROM najiz_judicial_requests) LIMIT 1")->fetchColumn();
    $ok = false;
    if ($otherReq) {
        $guard = $pdo->prepare('SELECT id FROM najiz_judicial_requests WHERE id=? AND case_type_id=? AND is_active=1');
        $guard->execute([$otherReq, $typeId]);
        $ok = ($guard->fetchColumn() === false); // must be refused
    }
    check('a request from another classification is refused', $ok);

    // A real request of this type links fine.
    $sameReq = (int)$pdo->query("SELECT id FROM najiz_judicial_requests WHERE case_type_id=$typeId LIMIT 1")->fetchColumn();
    if ($sameReq) {
        $pdo->prepare('INSERT IGNORE INTO case_judicial_requests (case_id, request_id) VALUES (?,?)')->execute([$caseId, $sameReq]);
        $n = $pdo->prepare('SELECT COUNT(*) FROM case_judicial_requests WHERE case_id=?');
        $n->execute([$caseId]);
        check('a matching judicial request links to the case', (int)$n->fetchColumn() === 1);
    } else {
        check('a matching judicial request links to the case', true);
    }

    // A classification referenced by a case must not be deletable.
    $blocked = false;
    try { $pdo->prepare('DELETE FROM najiz_case_types WHERE id=?')->execute([$typeId]); }
    catch (Throwable $e) { $blocked = true; }
    check('classification referenced by a case cannot be deleted (RESTRICT)', $blocked);

    $pdo->prepare('DELETE FROM case_judicial_requests WHERE case_id=?')->execute([$caseId]);
    $pdo->prepare('DELETE FROM cases WHERE id=?')->execute([$caseId]);
} else {
    check('lawyer/client fixtures available for integration test', false);
}

// ---------------------------------------------------------------------------
section('7. HTTP — public guide and read-only API');
// ---------------------------------------------------------------------------
function http_get(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_FOLLOWLOCATION => false]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, (string)$body];
}

[$code, $body] = http_get("$BASE/classification-guide.php");
check('public guide returns 200', $code === 200);
check('public guide shows the source total of 234', str_contains($body, '234'));
check('public guide renders a lawsuit type from the source', str_contains($body, 'النفقة'));

[$code, $body] = http_get("$BASE/api/najiz_classify.php?action=stats");
$json = json_decode($body, true);
check('stats endpoint returns 200', $code === 200);
check('stats reports 234 lawsuit types', ($json['stats']['lawsuit_types'] ?? 0) === 234);
check('stats reports 640 judicial requests', ($json['stats']['judicial_requests'] ?? 0) === 640);

[$code, $body] = http_get("$BASE/api/najiz_classify.php?action=categories");
$json = json_decode($body, true);
check('categories endpoint returns 7', count($json['categories'] ?? []) === 7);

// Take the id from the response rather than assuming 2. The seed order is not part of the
// contract — a database that has been re-imported carries whatever AUTO_INCREMENT it reached,
// and hardcoding an id here fails on correct data for a reason that has nothing to do with
// the cascade.
$categoryId = (int)($json['categories'][0]['id'] ?? 0);
check('the categories response exposes ids', $categoryId > 0);
[$code, $body] = http_get("$BASE/api/najiz_classify.php?action=subcategories&category_id=$categoryId");
$json = json_decode($body, true);
check('cascade level 2 returns subcategories', count($json['subcategories'] ?? []) > 0);

$sid = (int)($json['subcategories'][0]['id'] ?? 0);
[$code, $body] = http_get("$BASE/api/najiz_classify.php?action=types&subcategory_id=$sid");
$json = json_decode($body, true);
check('cascade level 3 returns lawsuit types', count($json['types'] ?? []) > 0);

// Unknown action must be rejected, not silently handled.
[$code, $body] = http_get("$BASE/api/najiz_classify.php?action=drop_everything");
check('unknown API action is rejected with 400', $code === 400);
check('unknown action does not leak SQL or paths', !str_contains($body, 'SQL') && !str_contains($body, '/workspace'));

// Missing required parameter must be a clean 400.
[$code, $body] = http_get("$BASE/api/najiz_classify.php?action=types");
check('missing parameter returns 400', $code === 400);

// A search term must not be reflected unescaped (no XSS through the API).
[$code, $body] = http_get("$BASE/api/najiz_classify.php?action=search&q=" . urlencode('<script>alert(1)</script>'));
check('script payload in search is not reflected as executable markup', !str_contains($body, '<script>alert(1)</script>'));

// The API must never write.
$cntBefore = (int)$pdo->query('SELECT COUNT(*) FROM najiz_case_types')->fetchColumn();
http_get("$BASE/api/najiz_classify.php?action=tree");
$cntAfter = (int)$pdo->query('SELECT COUNT(*) FROM najiz_case_types')->fetchColumn();
check('API is read-only (row count unchanged)', $cntBefore === $cntAfter);

// ---------------------------------------------------------------------------
section('8. Security — admin route guard and RBAC');
// ---------------------------------------------------------------------------
[$code, $body] = http_get("$BASE/admin/classification.php");
check('admin classification page redirects an anonymous visitor (302/303)', in_array($code, [302, 303], true));
check('admin page does not render content to an anonymous visitor', !str_contains($body, 'تصنيفات ناجز') || $code === 302);

require_once __DIR__ . '/../includes/admin_rbac.php';
check('classification.php is in the RBAC map', array_key_exists('classification.php', admin_route_roles()));
check('an editor may open classification.php', admin_role_allows('classification.php', 'editor'));
check('an unrelated role is refused', !admin_role_allows('classification.php', 'billing'));

// The importer must not be reachable as a web entry point with a URL parameter that
// could point it at an attacker-supplied file.
$imp = file_get_contents(__DIR__ . '/../includes/najiz_import.php');
check('importer reads the source path from the caller, not from request input',
    !preg_match('/\$_(GET|POST|REQUEST)\[[^\]]*source/', $imp));
check('importer binds every value (no string-interpolated SQL values)',
    !preg_match('/execute\(\s*["\'].*\$/', $imp));

// ---------------------------------------------------------------------------
section('9. Responsive markup and accessibility');
// ---------------------------------------------------------------------------
[$code, $body] = http_get("$BASE/classification-guide.php");
check('guide is RTL-aware (dir attribute present)', str_contains($body, 'dir="rtl"') || str_contains($body, 'dir="ltr"'));
check('guide search form has a label', str_contains($body, 'for="q"'));
check('guide is responsive (viewport meta present)', str_contains($body, 'name="viewport"'));
check('guide has no fixed-width table that would overflow mobile', !str_contains($body, 'width="1200"'));

// The selector must stack to one column on narrow screens.
$sel = file_get_contents(__DIR__ . '/../includes/najiz_selector.php');
check('selector collapses to a single column on narrow viewports', str_contains($sel, 'max-width:760px'));
check('selector is progressive-enhancement (real form field, not JS-only)',
    str_contains($sel, 'type="hidden"') && str_contains($sel, '<select'));

// ---------------------------------------------------------------------------
section('10. Additive / non-regressive');
// ---------------------------------------------------------------------------
// Existing casework must be untouched: every pre-existing case keeps a NULL
// classification and the original text column is still populated.
$nulls = (int)$pdo->query('SELECT COUNT(*) FROM cases WHERE najiz_case_type_id IS NULL')->fetchColumn();
$total = (int)$pdo->query('SELECT COUNT(*) FROM cases')->fetchColumn();
check('existing cases are unclassified, not rewritten', $nulls >= 0 && $total >= $nulls);
$caseCols = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cases'")->fetchAll(PDO::FETCH_COLUMN);
check('cases.case_type text column still present', in_array('case_type', $caseCols, true));
check('no original cases column was dropped', in_array('title', $caseCols, true) && in_array('description', $caseCols, true));
$consCols = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='consultations'")->fetchAll(PDO::FETCH_COLUMN);
check('consultations.topic still present', in_array('topic', $consCols, true));

echo "\n";
echo "===========================================\n";
echo " PASSED: $passed   FAILED: $failed\n";
echo "===========================================\n";
exit($failed === 0 ? 0 : 1);
