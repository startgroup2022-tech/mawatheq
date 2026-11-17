<?php
/**
 * GET/POST /api/v1/legal-library.php — المكتبة القانونية والقضائية.
 *
 * The shared backend for the legal & judicial library (website + Flutter app), so the
 * two surfaces cannot describe the catalogue differently. Everything the catalogue
 * exposes is public legal content, so the read actions need no token — exactly like
 * the public lawyer directory and `journey.php`.
 *
 * Read actions (no token):
 *   ?action=home                                  → sources, counts, content types
 *   ?action=search&q=&type=&source=&court=&city=&year=&page=&per_page=
 *   ?action=item&id=N                             → one item (body only if hosted)
 *   ?action=facets                                → the filter vocabulary, from the data
 *
 * Saved-items actions (Bearer token required — a personal list):
 *   ?action=favorites                             → the caller's saved items
 *   POST { action: 'favorite', item_id: N }       → toggle, returns { saved: bool }
 *
 * The redistribution rule is applied once, in legal_library_public_item(): a
 * `link_only` source returns metadata and its official URL, never a copied file.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/legal_library.php';

$action = (string)($_GET['action'] ?? 'home');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/** Read actions are public; the personal ones go through api_authenticate(). */
$needsAuth = in_array($action, ['favorites', 'favorite'], true);

try {
    switch ($action) {
        case 'home':
            $sources = legal_library_sources(true);
            $stats = legal_library_stats();
            $out = [];
            foreach ($sources as $s) {
                $out[] = [
                    'key' => $s['source_key'],
                    'name_ar' => $s['name_ar'],
                    'name_en' => $s['name_en'],
                    'publisher_ar' => $s['publisher_ar'],
                    'publisher_en' => $s['publisher_en'],
                    'item_count' => (int)$s['item_count'],
                    'redistribution' => $s['redistribution'],
                ];
            }
            $types = [];
            foreach (legal_library_item_types() as $k => $t) {
                $types[] = ['key' => $k, 'name_ar' => $t['ar'], 'name_en' => $t['en'], 'count' => (int)($stats['by_type'][$k] ?? 0)];
            }
            // `latest` keeps its original {id,title} shape (the app's home carousel) and
            // gains the full public item so a richer client can render a real card
            // without a second call. Existing clients read the fields they already know.
            $latest = array_map(function ($r) {
                $pub = legal_library_public_item($r);
                $pub['title'] = $r['title'];   // explicit, in case a future change renames it
                return $pub;
            }, $stats['latest']);
            api_json([
                'ok' => true,
                'total' => (int)$stats['total'],
                'enabled_sources' => (int)$stats['sources'],
                'sources' => $out,
                'types' => $types,
                'latest' => $latest,
                // Additive: the browse vocabulary and the extra counters. Older clients
                // ignore unknown keys, so this stays backward compatible.
                'courts' => (int)$stats['courts'],
                'files' => (int)$stats['files'],
                'browse' => legal_library_browse(),
            ]);
            break;

        case 'search':
            $f = [
                'q'          => trim((string)($_GET['q'] ?? '')),
                'item_type'  => trim((string)($_GET['type'] ?? '')),
                'source_key' => trim((string)($_GET['source'] ?? '')),
                'court_name' => trim((string)($_GET['court'] ?? '')),
                'city'       => trim((string)($_GET['city'] ?? '')),
                'category'   => trim((string)($_GET['category'] ?? '')),
                'file_type'  => trim((string)($_GET['file_type'] ?? '')),
                'year'       => (int)($_GET['year'] ?? 0),
                'sort'       => trim((string)($_GET['sort'] ?? 'newest')),
            ];
            if (($_GET['has_file'] ?? '') !== '') $f['has_file'] = 1;
            $page = max(1, (int)($_GET['page'] ?? 1));
            $perPage = (int)($_GET['per_page'] ?? 20);
            $res = legal_library_search($f, $page, $perPage);
            $items = array_map(fn($r) => legal_library_public_item($r), $res['items']);
            api_json([
                'ok' => true,
                'total' => (int)$res['total'],
                'page' => (int)$res['page'],
                'pages' => (int)$res['pages'],
                'per_page' => (int)$res['per_page'],
                'items' => $items,
            ]);
            break;

        case 'item':
            $id = (int)($_GET['id'] ?? 0);
            $row = legal_library_item($id);
            if (!$row) { api_error('العنصر غير موجود.', 404); }
            // `body` is only ever populated for a hosted source; pass no user so the
            // public action never leaks another account's saved state.
            $item = legal_library_public_item($row, null, true);
            // Additive: real related items (same court / city+type), never invented.
            $item['related'] = array_map(fn($r) => legal_library_public_item($r), legal_library_related($id, 6));
            api_json(['ok' => true, 'item' => $item]);
            break;

        case 'facets':
            $fc = legal_library_facets();
            api_json([
                'ok' => true,
                'sources' => array_map(fn($s) => ['key' => $s['source_key'], 'name_ar' => $s['name_ar'], 'name_en' => $s['name_en'], 'count' => (int)$s['item_count']], $fc['sources']),
                'courts' => $fc['courts'],
                'cities' => $fc['cities'],
                'years' => array_map('intval', $fc['years']),
                'categories' => $fc['categories'],
                'types' => $fc['types'],
                'file_types' => $fc['file_types'],
            ]);
            break;

        case 'favorites':
            $auth = api_authenticate($pdo);
            $rows = legal_library_favorites($pdo, (int)$auth['id'], 300);
            api_json(['ok' => true, 'items' => array_map(fn($r) => legal_library_public_item($r, (int)$auth['id']), $rows)]);
            break;

        case 'favorite':
            if ($method !== 'POST') { api_error('استخدم POST لحفظ العنصر.', 405); }
            $auth = api_authenticate($pdo);
            $input = api_input();
            $itemId = (int)($input['item_id'] ?? 0);
            if ($itemId <= 0 || !legal_library_item($itemId)) { api_error('العنصر غير موجود.', 404); }
            $saved = legal_library_favorite_toggle($pdo, (int)$auth['id'], $itemId);
            api_json(['ok' => true, 'saved' => $saved]);
            break;

        default:
            api_error('إجراء غير معروف.', 400);
    }
} catch (Throwable $e) {
    api_error('تعذّر تنفيذ الطلب.', 500);
}
