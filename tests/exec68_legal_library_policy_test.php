<?php
/**
 * Execution 68 — legal-library redistribution policy and the resumable walker.
 *
 * Three claims this suite pins, all of them about a decision that is easy to get
 * wrong while the code still looks finished:
 *
 *   1. Full text / a hosted file is stored ONLY when the operator has explicitly
 *      enabled capture (MAWATHEQ_LL_CAPTURE_BODY=1). While it is off, an item keeps
 *      its metadata and its official link and nothing else, whatever the importer
 *      sent — "publicly readable" is not "licensed to re-publish".
 *   2. A source whose redistribution is downgraded to link_only can no longer expose
 *      a stored body through the single render function, and upgrade_v79.sql clears
 *      the text that was already stored.
 *   3. The decisions catalogue is walked with a cursor, so successive cron runs make
 *      progress and never restart from page 1 — the gateway rate-limits a burst.
 *
 * Re-runnable: it seeds its own rows under a random suffix and deletes them, and it
 * restores the site state it changed. Needs only the configured database (no HTTP).
 *
 * Run: php tests/exec68_legal_library_policy_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/legal_library.php';

$checks = 0;
$failures = 0;
function check(string $label, bool $ok): void {
    global $checks, $failures;
    $checks++;
    if ($ok) { echo "  ok:   $label\n"; } else { $failures++; echo "  FAIL: $label\n"; }
}
function section(string $t): void { echo "\n$t\n"; }

$suffix = sprintf('%08d', random_int(10000000, 99999999));
$created = ['sources' => [], 'items' => []];
$savedCursor = null;

echo "Execution 68 — legal-library policy & resumable walker\n";
echo "suffix: $suffix\n";

function seed_source68(PDO $pdo, string $key, string $redistribution): int {
    global $created;
    // Upsert on the unique key so an interrupted prior run cannot block this one.
    $pdo->prepare('INSERT INTO legal_library_sources (source_key, name_ar, name_en, redistribution, is_enabled) VALUES (?,?,?,?,1)
                   ON DUPLICATE KEY UPDATE redistribution = VALUES(redistribution), is_enabled = 1')
        ->execute([$key, $key . ' AR', $key . ' EN', $redistribution]);
    $id = (int)$pdo->query('SELECT id FROM legal_library_sources WHERE source_key = ' . $pdo->quote($key))->fetchColumn();
    $created['sources'][] = $id;
    return $id;
}
function seed_item68(PDO $pdo, int $sourceId, array $over): int {
    global $created;
    $row = array_merge([
        'source_id' => $sourceId, 'source_key' => 'x', 'external_id' => 'exec68-' . bin2hex(random_bytes(8)),
        'item_type' => 'judgment', 'title' => 'عنصر', 'summary' => '', 'body' => null,
        'source_url' => '', 'file_url' => null, 'hosted_path' => null,
        'redistribution' => 'link_only', 'is_published' => 1,
    ], $over);
    $cols = array_keys($row);
    $pdo->prepare('INSERT INTO legal_library_items (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')
        ->execute(array_values($row));
    $id = (int)$pdo->lastInsertId();
    $created['items'][] = $id;
    return $id;
}

/* ============================================================ SECTION 1 */
section('1. Capture is opt-in — a "hosted" source stores nothing unless enabled');

// Force the capture flag off for the duration, whatever the environment had.
$envBackup = getenv('MAWATHEQ_LL_CAPTURE_BODY');
putenv('MAWATHEQ_LL_CAPTURE_BODY');
check('capture is off by default', legal_library_capture_body() === false);

$srcId = seed_source68($pdo, 'exec68_hosted', 'hosted');
$itemId = seed_item68($pdo, $srcId, [
    'source_key' => 'exec68_hosted',
    'external_id' => 'exec68-body-' . $suffix,
    'title' => 'حكم باختبار التقاط النص ' . $suffix,
    'body' => 'نص الحكم الكامل — يجب ألا يُخزَّن ' . $suffix,
    'redistribution' => 'hosted',
]);

// Re-upsert the same item through the real path: capture off must blank the body.
legal_library_upsert_item($pdo, [
    'source_id' => $srcId, 'source_key' => 'exec68_hosted', 'external_id' => 'exec68-body-' . $suffix,
    'item_type' => 'judgment', 'title' => 'حكم باختبار التقاط النص ' . $suffix,
    'body' => 'نص الحكم الكامل — يجب ألا يُخزَّن ' . $suffix,
    'redistribution' => 'hosted', 'lang' => 'ar', 'is_published' => 1, 'summary' => '', 'source_url' => 'https://laws.moj.gov.sa/x/' . $suffix,
]);
$stored = $pdo->query("SELECT body, hosted_path FROM legal_library_items WHERE id = $itemId")->fetch(PDO::FETCH_ASSOC);
check('capture off: body is not stored by the upsert', ($stored['body'] ?? null) === null);
check('capture off: hosted_path is not stored by the upsert', ($stored['hosted_path'] ?? null) === null);

// With capture on, the same upsert keeps the body (so the opt-in really works).
putenv('MAWATHEQ_LL_CAPTURE_BODY=1');
check('capture is on when MAWATHEQ_LL_CAPTURE_BODY=1', legal_library_capture_body() === true);
legal_library_upsert_item($pdo, [
    'source_id' => $srcId, 'source_key' => 'exec68_hosted', 'external_id' => 'exec68-body-' . $suffix,
    'item_type' => 'judgment', 'title' => 'حكم باختبار التقاط النص ' . $suffix,
    'body' => 'نص الحكم الكامل — يجب ألا يُخزَّن ' . $suffix,
    'redistribution' => 'hosted', 'lang' => 'ar', 'is_published' => 1, 'summary' => '', 'source_url' => 'https://laws.moj.gov.sa/x/' . $suffix,
]);
$stored2 = $pdo->query("SELECT body FROM legal_library_items WHERE id = $itemId")->fetchColumn();
check('capture on: the body is stored', $stored2 !== null && str_contains((string)$stored2, $suffix));
putenv('MAWATHEQ_LL_CAPTURE_BODY'); // back off for the rest of the suite
if ($envBackup !== false) putenv('MAWATHEQ_LL_CAPTURE_BODY=' . $envBackup);

/* ============================================================ SECTION 2 */
section('2. A downgraded source cannot expose a stored body');

// The row still holds text (it was captured above). Downgrade the source and prove
// the single render function refuses to hand it out.
$pdo->prepare("UPDATE legal_library_sources SET redistribution='link_only' WHERE id=?")->execute([$srcId]);
$pdo->prepare("UPDATE legal_library_items SET redistribution='link_only' WHERE id=?")->execute([$itemId]);
$row = legal_library_item($itemId);
$pub = legal_library_public_item($row, null, true);
check('link_only: the stored body is not exposed', ($pub['body'] ?? null) === null);
check('link_only: can_read_here is false', $pub['can_read_here'] === false);
check('link_only: the official URL is still present', !empty($pub['source_url']));

/* ============================================================ SECTION 3 */
section('3. The decisions catalogue walk is resumable');

check('a hijri year is computed without intl', legal_library_hijri_now_year() > 1400);
$parts = legal_library_decisions_partitions(1);
check('partitions are month windows, newest first', count($parts) === 24 && $parts[0]['month'] === 1);

[$y, $m, $p] = legal_library_cursor_parse('1448:03:17');
check('cursor parses back to (year, month, page)', $y === 1448 && $m === 3 && $p === 17);
[$y0, $m0, $p0] = legal_library_cursor_parse(null);
check('a null cursor restarts at the first window/page', $y0 === 0 && $p0 === 1);
[$yb, $mb, $pb] = legal_library_cursor_parse('garbage');
check('a malformed cursor degrades to the start', $yb === 0 && $pb === 1);

// Save/restore the real source's cursor so the suite does not disturb a live walk.
$savedCursor = $pdo->query("SELECT sync_cursor FROM legal_library_sources WHERE source_key='moj_decisions'")->fetchColumn();
legal_library_save_cursor($pdo, 'moj_decisions', '1448:07:9');
$after = $pdo->query("SELECT sync_cursor FROM legal_library_sources WHERE source_key='moj_decisions'")->fetchColumn();
check('the cursor is persisted on the source row', $after === '1448:07:9');

/* ============================================================ SECTION 4 */
section('4. upgrade_v79.sql declares exactly what it does');

$v79 = file_get_contents(__DIR__ . '/../database/upgrade_v79.sql');
check('v79 adds the sync_cursor column', str_contains($v79, '`sync_cursor`'));
check('v79 guards the column on information_schema', str_contains($v79, 'information_schema.COLUMNS'));
check('v79 downgrades the two MOJ sources', str_contains($v79, "'moj_decisions', 'moj_legislation'"));
check('v79 clears stored body and hosted_path', str_contains($v79, '`body` = NULL') && str_contains($v79, '`hosted_path` = NULL'));
check('v79 uses no positional AFTER clause', !preg_match('/\bAFTER\s+`?\w+`?/i', $v79));

/* ============================================================ SECTION 5 */
section('5. The shipped seed classifies MOJ honestly');

/*
 * This asserts the SHIPPED seed (the SQL files a fresh install loads), not the live
 * row. An operator legitimately flips a source to `hosted` to run a real import with
 * MAWATHEQ_LL_CAPTURE_BODY=1 — that is the documented, intended action, not drift —
 * so a test that reads the live row fails on a correctly configured database. The
 * invariant that must never move is what a *fresh* install classifies the source as.
 */
function shipped_seed_redistribution(string $sql, string $sourceKey): ?string {
    $needle = "('" . $sourceKey . "'";
    $pos = strpos($sql, $needle);
    if ($pos === false) { return null; }
    $row = substr($sql, $pos, 1600); // one seed tuple, comfortably
    if (preg_match("/'(link_only|hosted|unknown)'/", $row, $m)) { return $m[1]; }
    return null;
}
$schema = file_get_contents(__DIR__ . '/../database/schema.sql');
$v78 = file_get_contents(__DIR__ . '/../database/upgrade_v78.sql');
foreach (['moj_decisions', 'moj_legislation', 'mashortich_books'] as $key) {
    check("schema.sql seeds $key as link_only", shipped_seed_redistribution($schema, $key) === 'link_only');
    check("upgrade_v78.sql seeds $key as link_only", shipped_seed_redistribution($v78, $key) === 'link_only');
}
check('schema.sql declares the sync_cursor column', str_contains($schema, '`sync_cursor`'));

// The live row may legitimately differ (operator enabled capture for a real import);
// what must hold is that the live value is one of the three known states.
$live = $pdo->query("SELECT redistribution FROM legal_library_sources WHERE source_key='moj_decisions'")->fetchColumn();
check('the live moj_decisions redistribution is a known state', in_array($live, ['link_only', 'hosted', 'unknown'], true));

/* ============================================================ CLEANUP */
section('Cleanup');
if ($savedCursor !== null) legal_library_save_cursor($pdo, 'moj_decisions', $savedCursor);
else $pdo->prepare("UPDATE legal_library_sources SET sync_cursor=NULL WHERE source_key='moj_decisions'")->execute();
if ($created['items']) {
    $pdo->exec('DELETE FROM legal_library_items WHERE id IN (' . implode(',', array_map('intval', $created['items'])) . ')');
}
if ($created['sources']) {
    $pdo->exec('DELETE FROM legal_library_sources WHERE id IN (' . implode(',', array_map('intval', $created['sources'])) . ')');
}
echo "  removed seeded rows\n";

echo "\n$checks checks, $failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
