<?php
/**
 * Najiz classification lookup API.
 *
 * Serves the cascading selectors (category -> subcategory -> lawsuit type ->
 * judicial requests) and the Arabic search/suggest used by the public guide, the
 * lawyer case form and the client consultation form. Read-only and public: the
 * classification hierarchy is reference data, not private information.
 *
 * No classification text is hardcoded here — every response is read from the tables
 * the importer filled from the source document.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/../includes/najiz_import.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$action = $_GET['action'] ?? '';

try {
    if (!najiz_tables_ready($pdo)) {
        // Same shape as a populated database that has no matching rows, so the caller
        // needs no special case for an unmigrated install.
        switch ($action) {
            case 'categories':    najiz_json(['ok' => true, 'categories' => []]);
            case 'subcategories': najiz_json(['ok' => true, 'subcategories' => []]);
            case 'types':         najiz_json(['ok' => true, 'types' => []]);
            case 'requests':      najiz_json(['ok' => true, 'requests' => []]);
            case 'search':        najiz_json(['ok' => true, 'query' => (string)($_GET['q'] ?? ''), 'results' => [], 'suggested' => []]);
            case 'suggest':       najiz_json(['ok' => true, 'suggested' => []]);
            case 'tree':          najiz_json(['ok' => true, 'categories' => [], 'subcategories' => [], 'types' => [], 'requests' => []]);
            case 'stats':         najiz_json(['ok' => true, 'stats' => ['categories' => 0, 'subcategories' => 0, 'lawsuit_types' => 0, 'judicial_requests' => 0]]);
            default:              najiz_json(['ok' => false, 'error' => 'unknown action'], 400);
        }
    }

    switch ($action) {
        case 'categories':
            $rows = $pdo->query('SELECT id, name_ar, name_en, sort_order FROM najiz_categories
                                  WHERE is_active = 1 ORDER BY sort_order, id')->fetchAll();
            najiz_json(['ok' => true, 'categories' => $rows]);
            break;

        case 'subcategories':
            $cid = (int)($_GET['category_id'] ?? 0);
            if ($cid <= 0) najiz_json(['ok' => false, 'error' => 'category_id required'], 400);
            $s = $pdo->prepare('SELECT id, name_ar, name_en, declared_count, sort_order FROM najiz_subcategories
                                  WHERE category_id = ? AND is_active = 1 ORDER BY sort_order, id');
            $s->execute([$cid]);
            najiz_json(['ok' => true, 'subcategories' => $s->fetchAll()]);
            break;

        case 'types':
            $sid = (int)($_GET['subcategory_id'] ?? 0);
            if ($sid <= 0) najiz_json(['ok' => false, 'error' => 'subcategory_id required'], 400);
            $s = $pdo->prepare('SELECT id, name_ar, name_en, definition, definition_missing,
                                       requests_declared_absent, sort_order
                                  FROM najiz_case_types
                                 WHERE subcategory_id = ? AND is_active = 1 ORDER BY sort_order, id');
            $s->execute([$sid]);
            najiz_json(['ok' => true, 'types' => $s->fetchAll()]);
            break;

        case 'requests':
            $tid = (int)($_GET['case_type_id'] ?? 0);
            if ($tid <= 0) najiz_json(['ok' => false, 'error' => 'case_type_id required'], 400);
            $s = $pdo->prepare('SELECT id, name_ar, name_en, sort_order FROM najiz_judicial_requests
                                 WHERE case_type_id = ? AND is_active = 1 ORDER BY sort_order, id');
            $s->execute([$tid]);
            najiz_json(['ok' => true, 'requests' => $s->fetchAll()]);
            break;

        case 'search':
            $q = trim((string)($_GET['q'] ?? ''));
            if (mb_strlen($q) < 2) najiz_json(['ok' => true, 'results' => [], 'note' => 'query too short']);
            $rows = najiz_search($pdo, $q, 30);
            // Suggested category from the same free text, presented as a suggestion only.
            $suggest = najiz_suggest_from_text($pdo, $q, 5);
            najiz_json([
                'ok' => true,
                'query' => $q,
                'results' => $rows,
                'suggested' => array_map(fn($r) => [
                    'id' => (int)$r['id'], 'name_ar' => $r['name_ar'],
                    'subcategory' => $r['sub_name'], 'category' => $r['cat_name'], 'score' => $r['score'],
                ], $suggest),
                // The interface must show this: the match is a keyword score over the
                // source's own text, not legal advice.
                'disclaimer' => 'النتائج اقتراح إرشادي مبني على تصنيفات ناجز، وليست رأيًا قانونيًا.',
            ]);
            break;

        case 'suggest':
            $q = trim((string)($_POST['text'] ?? $_GET['text'] ?? ''));
            if (mb_strlen($q) < 3) najiz_json(['ok' => true, 'suggested' => []]);
            $suggest = najiz_suggest_from_text($pdo, $q, 5);
            najiz_json([
                'ok' => true,
                'suggested' => array_map(fn($r) => [
                    'id' => (int)$r['id'], 'name_ar' => $r['name_ar'],
                    'subcategory' => $r['sub_name'], 'category' => $r['cat_name'], 'score' => $r['score'],
                ], $suggest),
                'disclaimer' => 'اقتراح إرشادي مبني على تصنيفات ناجز، وليس رأيًا قانونيًا.',
            ]);
            break;

        case 'tree':
            // The whole hierarchy in one response, for the guide's single-page mode.
            $cats = $pdo->query('SELECT id, name_ar, name_en, sort_order FROM najiz_categories
                                  WHERE is_active = 1 ORDER BY sort_order, id')->fetchAll();
            $subs = $pdo->query('SELECT id, category_id, name_ar, name_en, sort_order FROM najiz_subcategories
                                  WHERE is_active = 1 ORDER BY sort_order, id')->fetchAll();
            $types = $pdo->query('SELECT id, subcategory_id, name_ar, definition, definition_missing,
                                         requests_declared_absent, sort_order
                                    FROM najiz_case_types WHERE is_active = 1 ORDER BY sort_order, id')->fetchAll();
            $reqs = $pdo->query('SELECT id, case_type_id, name_ar, sort_order FROM najiz_judicial_requests
                                  WHERE is_active = 1 ORDER BY sort_order, id')->fetchAll();
            najiz_json(['ok' => true, 'categories' => $cats, 'subcategories' => $subs,
                        'types' => $types, 'requests' => $reqs]);
            break;

        case 'stats':
            najiz_json(['ok' => true, 'stats' => [
                'categories' => (int)$pdo->query('SELECT COUNT(*) FROM najiz_categories WHERE is_active=1')->fetchColumn(),
                'subcategories' => (int)$pdo->query('SELECT COUNT(*) FROM najiz_subcategories WHERE is_active=1')->fetchColumn(),
                'lawsuit_types' => (int)$pdo->query('SELECT COUNT(*) FROM najiz_case_types WHERE is_active=1')->fetchColumn(),
                'judicial_requests' => (int)$pdo->query('SELECT COUNT(*) FROM najiz_judicial_requests WHERE is_active=1')->fetchColumn(),
            ]]);
            break;

        default:
            najiz_json(['ok' => false, 'error' => 'unknown action'], 400);
    }
} catch (Throwable $e) {
    error_log('najiz_classify: ' . $e->getMessage());
    najiz_json(['ok' => false, 'error' => 'server_error'], 500);
}

function najiz_json(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}
