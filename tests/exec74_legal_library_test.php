<?php
/**
 * Execution 74 — the smart legal & judicial library.
 *
 * Three things this suite proves, because they are the three ways a library can be
 * wrong while looking finished:
 *
 *   1. The redistribution rule is applied in exactly one place
 *      (`legal_library_public_item()`) — a `hosted` item exposes readable text, a
 *      `link_only` item exposes metadata and its official URL and NEVER a copied
 *      file. This is a legal constraint, not a UI preference.
 *   2. The public web surfaces (hub, search, item) render over real HTTP and the
 *      search filters actually narrow the result set; the favorites list is
 *      client-only and redirects a guest to login.
 *   3. The public REST endpoint (`api/v1/legal-library.php`) answers the read
 *      actions without a token and refuses the personal ones without one — and it
 *      never leaks the internal taxonomy vocabulary to a caller.
 *
 * The suite seeds its own items under a random suffix and deletes them in cleanup,
 * so it is re-runnable and does not depend on the importer having run. It needs the
 * app on 127.0.0.1:8080 (see tests/setup_test_env.sh).
 *
 * Run: php tests/exec74_legal_library_test.php
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

function http74(string $method, string $path, array $opts = []): array {
    global $base;
    $ch = curl_init($base . $path);
    $cfg = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ];
    if (!empty($opts['jar'])) { $cfg[CURLOPT_COOKIEJAR] = $opts['jar']; $cfg[CURLOPT_COOKIEFILE] = $opts['jar']; }
    if (array_key_exists('post', $opts)) { $cfg[CURLOPT_POSTFIELDS] = $opts['post']; }
    curl_setopt_array($ch, $cfg);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}

function json74(string $body): array {
    $d = json_decode($body, true);
    return is_array($d) ? $d : [];
}

$suffix = sprintf('%08d', random_int(10000000, 99999999));
$created = ['items' => []];

echo "Execution 74 — legal & judicial library\n";
echo "suffix: $suffix\n";

/* ------------------------------------------------------------------ helpers */

function seed_source(PDO $pdo, string $key, string $redistribution): int {
    $st = $pdo->prepare('SELECT id FROM legal_library_sources WHERE source_key = ?');
    $st->execute([$key]);
    $id = (int)$st->fetchColumn();
    if ($id > 0) {
        $pdo->prepare('UPDATE legal_library_sources SET redistribution = ? WHERE id = ?')
            ->execute([$redistribution, $id]);
        return $id;
    }
    $pdo->prepare('INSERT INTO legal_library_sources (source_key, name_ar, name_en, redistribution, is_enabled) VALUES (?,?,?,?,1)')
        ->execute([$key, $key . ' AR', $key . ' EN', $redistribution]);
    return (int)$pdo->lastInsertId();
}

function seed_item(PDO $pdo, int $sourceId, array $over): int {
    $row = array_merge([
        'source_id' => $sourceId,
        'source_key' => 'x',
        'external_id' => 'exec74-' . bin2hex(random_bytes(8)),
        'item_type' => 'judgment',
        'title' => 'عنصر',
        'summary' => '',
        'body' => null,
        'court_name' => null,
        'city' => null,
        'case_number' => null,
        'decision_number' => null,
        'decision_date' => null,
        'hijri_year' => null,
        'gregorian_year' => null,
        'category' => null,
        'publisher' => null,
        'source_url' => '',
        'file_url' => null,
        'file_type' => null,
        'file_size_kb' => null,
        'lang' => 'ar',
        'redistribution' => 'link_only',
        'hosted_path' => null,
        'is_published' => 1,
        'created_at' => date('Y-m-d H:i:s'),
    ], $over);

    $cols = array_keys($row);
    $sql = 'INSERT INTO legal_library_items (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
    $pdo->prepare($sql)->execute(array_values($row));
    return (int)$pdo->lastInsertId();
}

/* ================================================================ SECTION 1 */

section('1. Redistribution rule — one function decides what leaves the platform');

$srcHosted = seed_source($pdo, 'exec74_hosted', 'hosted');
$srcLink = seed_source($pdo, 'exec74_link', 'link_only');

// A real hosted file, so the download route is exercised end to end and not only
// its refusal paths. The filename is 40 hex + .pdf, exactly what the route requires.
if (!is_dir(LEGAL_LIBRARY_DIR)) { @mkdir(LEGAL_LIBRARY_DIR, 0755, true); }
$hostedFileName = bin2hex(random_bytes(20)) . '.pdf';
$hostedFileBytes = "%PDF-1.4 exec74 " . $suffix;
@file_put_contents(LEGAL_LIBRARY_DIR . $hostedFileName, $hostedFileBytes);

$hostedId = seed_item($pdo, $srcHosted, [
    'title' => 'حكم مُستضاف ' . $suffix,
    'source_key' => 'exec74_hosted',
    'item_type' => 'judgment',
    'redistribution' => 'hosted',
    'hosted_path' => $hostedFileName,
    'file_type' => 'pdf',
    'body' => '<p>نص الحكم الكامل ' . $suffix . '</p>',
    'decision_number' => 'D-' . $suffix,
    'hijri_year' => 1445,
]);
$linkId = seed_item($pdo, $srcLink, [
    'title' => 'كتاب برابط خارجي ' . $suffix,
    'source_key' => 'exec74_link',
    'item_type' => 'book',
    'redistribution' => 'link_only',
    'body' => 'THIS MUST NEVER BE EXPOSED',
    'source_url' => 'https://example.org/item/' . $suffix,
    'file_url' => 'https://example.org/item/' . $suffix . '.pdf',
]);
$created['items'] = [$hostedId, $linkId];

$hostedRow = legal_library_item($hostedId);
$linkRow = legal_library_item($linkId);
check('a hosted item is readable', $hostedRow !== null && $hostedRow['redistribution'] === 'hosted');
check('a link_only item is retrievable', $linkRow !== null);

$pubHosted = legal_library_public_item($hostedRow, null, true);
$pubLink = legal_library_public_item($linkRow, null, true);

check('hosted: body is exposed with withBody=true', ($pubHosted['body'] ?? null) !== null && str_contains($pubHosted['body'], $suffix));
check('hosted: can_read_here is true', $pubHosted['can_read_here'] === true);
check('hosted: download is internal (platform-served)', $pubHosted['download_is_external'] === false && str_contains((string)$pubHosted['download_url'], '/legal-library-file.php'));
check('link_only: body is NULL even though the column holds text', ($pubLink['body'] ?? null) === null);
check('link_only: can_read_here is false', $pubLink['can_read_here'] === false);
check('link_only: download points at the official URL', $pubLink['download_url'] === 'https://example.org/item/' . $suffix . '.pdf');
check('link_only: download_is_external is true', $pubLink['download_is_external'] === true);
check('link_only: no platform file route is ever produced', !str_contains((string)$pubLink['download_url'], 'legal-library-file'));

/* ================================================================ SECTION 2 */

section('2. Search and facets over the seeded rows');

$res = legal_library_search(['q' => $suffix], 1, 20);
check('search by the unique suffix finds exactly the two seeded rows', (int)$res['total'] === 2);

$res2 = legal_library_search(['q' => $suffix, 'item_type' => 'book'], 1, 20);
check('the type filter narrows to the book', (int)$res2['total'] === 1 && (int)$res2['items'][0]['id'] === $linkId);

$res3 = legal_library_search(['q' => $suffix, 'source_key' => 'exec74_hosted'], 1, 20);
check('the source filter narrows to the hosted source', (int)$res3['total'] === 1 && (int)$res3['items'][0]['id'] === $hostedId);

$res4 = legal_library_search(['q' => $suffix, 'year' => 1445], 1, 20);
check('the year filter narrows to the hosted item', (int)$res4['total'] === 1 && (int)$res4['items'][0]['id'] === $hostedId);

$res5 = legal_library_search(['q' => $suffix, 'court_name' => 'محكمة لا وجود لها'], 1, 20);
check('a filter with no match returns zero rows, not everything', (int)$res5['total'] === 0);

// An unpublished row must never appear in any result.
$hiddenId = seed_item($pdo, $srcHosted, ['title' => 'مخفي ' . $suffix, 'is_published' => 0]);
$created['items'][] = $hiddenId;
$res6 = legal_library_search(['q' => $suffix], 1, 20);
$ids6 = array_map(fn($r) => (int)$r['id'], $res6['items']);
check('an unpublished item is excluded from search', !in_array($hiddenId, $ids6, true));

/* ================================================================ SECTION 3 */

section('3. Public web pages over HTTP');

[$codeHub, $hub] = http74('GET', '/legal-library.php');
check('the hub renders 200', $codeHub === 200);
check('the hub is the public shell (shows the marketing nav)', str_contains($hub, 'المكتبة'));
// The hub and search pages use .ll-* classes; without their stylesheet they render
// unstyled. That is a real defect (they were shipped without it once), so it is pinned.
check('the hub loads the library stylesheet', str_contains($hub, 'legal-library.css'));

[$codeSearch, $search] = http74('GET', '/legal-library-search.php?q=' . rawurlencode($suffix));
check('search renders 200', $codeSearch === 200);
check('search shows the hosted item title', str_contains($search, 'حكم مُستضاف ' . $suffix));
check('search loads the library stylesheet', str_contains($search, 'legal-library.css'));

[$codeItem, $item] = http74('GET', '/legal-library-item.php?id=' . $hostedId);
check('the item page renders 200', $codeItem === 200);
check('the item page shows the readable body', str_contains($item, $suffix));

// A guest hitting the favorites page is redirected to login, not served the list.
[$codeFav, , ] = [0, ''];
$ch = curl_init($base . '/legal-library-favorites.php');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30]);
$favBody = (string)curl_exec($ch);
$codeFav = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
check('the favorites page redirects a guest (302) rather than rendering', $codeFav === 302);

// The hosted file route actually serves the stored file, and refuses a link_only id.
[$codeFile, $fileBody] = http74('GET', '/legal-library-file.php?id=' . $hostedId);
check('the file route serves a real hosted file (200)', $codeFile === 200);
check('the served file carries the stored bytes', str_contains($fileBody, 'exec74 ' . $suffix));
[$codeFileLink, ] = http74('GET', '/legal-library-file.php?id=' . $linkId);
check('the file route refuses a link_only item (404)', $codeFileLink === 404);
[$codeFileBad, ] = http74('GET', '/legal-library-file.php?id=999999999');
check('the file route refuses an unknown id (404)', $codeFileBad === 404);

/* ================================================================ SECTION 4 */

section('4. REST endpoint — public reads, token-only writes');

[$codeHome, $homeBody] = http74('GET', '/api/v1/legal-library.php?action=home');
check('action=home is public (200)', $codeHome === 200);
$home = json74($homeBody);
check('home returns ok', ($home['ok'] ?? false) === true);
check('home lists sources', is_array($home['sources'] ?? null) && count($home['sources']) > 0);
$sourceKeys = array_column($home['sources'] ?? [], 'key');
check('home includes the seeded sources', in_array('exec74_hosted', $sourceKeys, true));

[$codeSearchApi, $searchApiBody] = http74('GET', '/api/v1/legal-library.php?action=search&q=' . rawurlencode($suffix));
check('action=search is public (200)', $codeSearchApi === 200);
$searchApi = json74($searchApiBody);
check('search returns the seeded rows', (int)($searchApi['total'] ?? 0) === 2);
$apiItem = $searchApi['items'][0] ?? [];
check('search items never carry the internal taxonomy id', !array_key_exists('najiz_case_type_id', $apiItem));
check('search items never carry an internal classification name', !array_key_exists('case_type_name', $apiItem));

[$codeItemApi, $itemApiBody] = http74('GET', '/api/v1/legal-library.php?action=item&id=' . $linkId);
$itemApi = json74($itemApiBody);
check('item action is public (200)', $codeItemApi === 200);
check('item action returns the link_only item with a null body', array_key_exists('body', $itemApi['item'] ?? []) && $itemApi['item']['body'] === null);
check('item action keeps the official download URL', ($itemApi['item']['download_url'] ?? '') === 'https://example.org/item/' . $suffix . '.pdf');

[$codeFacets, $facetsBody] = http74('GET', '/api/v1/legal-library.php?action=facets');
check('action=facets is public (200)', $codeFacets === 200);
$facets = json74($facetsBody);
check('facets exposes years as a list', is_array($facets['years'] ?? null));

// The personal actions must refuse an anonymous caller.
[$codeFavApi, $favApiBody] = http74('GET', '/api/v1/legal-library.php?action=favorites');
check('action=favorites refuses a caller with no token (401)', $codeFavApi === 401);
check('the refusal is the standard envelope', (json74($favApiBody)['ok'] ?? true) === false);

[$codeFavPost, ] = http74('POST', '/api/v1/legal-library.php?action=favorite', ['post' => json_encode(['action' => 'favorite', 'item_id' => $hostedId])]);
check('action=favorite refuses a caller with no token (401)', $codeFavPost === 401);

[$codeUnknown, ] = http74('GET', '/api/v1/legal-library.php?action=definitely-not-an-action');
check('an unknown action is refused (400)', $codeUnknown === 400);

/* ================================================================ SECTION 5 */

section('5. Favorite toggle — server state, not a UI guess');

$userId = (int)$pdo->query("SELECT id FROM users ORDER BY id LIMIT 1")->fetchColumn();
if ($userId > 0) {
    $before = legal_library_is_favorite($pdo, $userId, $hostedId);
    $now = legal_library_favorite_toggle($pdo, $userId, $hostedId);
    check('toggle flips the state', $now !== $before);
    check('a second toggle restores the original state', legal_library_favorite_toggle($pdo, $userId, $hostedId) === $before);
} else {
    check('a seeded user exists to test favorites', false);
}

/* ================================================================ CLEANUP */

section('Cleanup');

$in = implode(',', array_map('intval', $created['items']));
if ($in !== '') {
    $pdo->exec("DELETE FROM legal_library_favorites WHERE item_id IN ($in)");
    $pdo->exec("DELETE FROM legal_library_items WHERE id IN ($in)");
}
$pdo->exec("DELETE FROM legal_library_sources WHERE source_key IN ('exec74_hosted','exec74_link')");
@unlink(LEGAL_LIBRARY_DIR . $hostedFileName);
echo "  removed seeded rows\n";

echo "\n{$checks} checks, {$failures} failure(s)\n";
exit($failures > 0 ? 1 : 0);
