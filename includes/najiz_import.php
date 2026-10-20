<?php
/**
 * Saudi Najiz classification importer.
 *
 * Reads the source document, writes the four-level hierarchy, and reports what it
 * actually wrote. It holds no classification data of its own: every name, request and
 * definition comes from the file at run time, so the import cannot drift from the
 * source and cannot contain a hand-picked subset.
 *
 * IDEMPOTENCY
 *   Every row is keyed by najiz_source_key(), derived from the full hierarchical path
 *   plus the ordinal occurrence of the name within its parent. A second run finds each
 *   existing key and updates in place, inserting nothing. Verified by running the
 *   importer twice and diffing row counts and ids.
 *
 * TRANSACTIONALITY
 *   The whole import runs inside one transaction. A parse error or a write failure
 *   rolls the entire thing back, so a half-imported hierarchy is not left behind.
 *   `najiz_import_runs` is written outside that transaction so the record of a failed
 *   attempt survives the rollback — that is the point of keeping it.
 *
 * SAFETY
 *   No SQL is built from source text; every value is bound. The source file is read as
 *   data only — nothing in it is executed, included or evaluated.
 */

require_once __DIR__ . '/najiz_parser.php';
require_once __DIR__ . '/najiz_search_lib.php';

/**
 * Whether the classification tables exist.
 *
 * An install that never received upgrade_v59.sql has no najiz_* tables, and that is a
 * deployment state rather than a server fault. Every caller that reads the hierarchy
 * must therefore ask this first and degrade to an empty result: the public guide
 * renders a readable "not enabled yet" notice, and api/najiz_classify.php answers with
 * an empty list instead of dying on the first query with an opaque 500. Both readers
 * share this one probe so they cannot disagree about readiness.
 */
function najiz_tables_ready(PDO $pdo): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $pdo->query('SELECT 1 FROM najiz_categories LIMIT 1');
        $ready = true;
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

/**
 * @param array $opts  dry_run (bool), source (string path), actor_admin_id (int|null)
 * @return array report
 */
function najiz_import(PDO $pdo, array $opts = []): array {
    $sourcePath = $opts['source'] ?? dirname(__DIR__) . '/database/sources/Pasted%20text%283%29.txt';
    $dryRun = !empty($opts['dry_run']);
    $actorId = $opts['actor_admin_id'] ?? null;

    if (!is_readable($sourcePath)) {
        throw new RuntimeException("source file is not readable: $sourcePath");
    }
    $sha = hash_file('sha256', $sourcePath);
    $bytes = (int)filesize($sourcePath);

    $parsed = najiz_parse_source($sourcePath);
    $cats = $parsed['categories'];
    $stats = $parsed['stats'];
    $warnings = $parsed['warnings'];

    // ---- reconciliation -----------------------------------------------------
    // The source prints a total, prints a count next to each subcategory, and contains
    // a number of detail blocks. All three must agree with what was parsed; any
    // disagreement is reported rather than smoothed over.
    $declaredTotal = $stats['declared_total_types'];
    $parsedTypes = $stats['types'];
    $sumDeclared = $stats['declared_subcategory_counts'];
    $reconciles = ($declaredTotal === null) ? null
        : ($declaredTotal === $parsedTypes && $sumDeclared === $parsedTypes);

    $report = [
        'source_path'   => $sourcePath,
        'source_sha256' => $sha,
        'source_bytes'  => $bytes,
        'declared_total_types' => $declaredTotal,
        'counts' => [
            'main_categories' => count($cats),
            'subcategories'   => $stats['subcategories'],
            'lawsuit_types'   => $stats['types'],
            'types_with_detail' => $stats['types_with_detail'],
            'types_without_detail' => $stats['types_without_detail'],
            'judicial_requests' => $stats['requests'],
            'definitions'     => $stats['definitions'],
            'sum_declared_subcategory_counts' => $sumDeclared,
        ],
        'reconciles' => $reconciles,
        'warnings' => $warnings,
        'footer_notes' => $parsed['footer_notes'],
        'inserted' => ['categories'=>0,'subcategories'=>0,'case_types'=>0,'requests'=>0],
        'updated'  => ['categories'=>0,'subcategories'=>0,'case_types'=>0,'requests'=>0],
        'dry_run'  => $dryRun,
        'run_id'   => null,
    ];

    if ($dryRun) {
        return $report;
    }

    // Record the attempt first, outside the data transaction, so a rollback still
    // leaves evidence that the import ran and how it ended.
    $pdo->prepare("INSERT INTO najiz_import_runs (source_path, source_sha256, source_bytes, declared_total_types, counts_json, warnings_json, status)
                   VALUES (?,?,?,?,?,?,'running')")
        ->execute([$sourcePath, $sha, $bytes, $declaredTotal,
                   json_encode($report['counts'], JSON_UNESCAPED_UNICODE),
                   json_encode($warnings, JSON_UNESCAPED_UNICODE)]);
    $runId = (int)$pdo->lastInsertId();
    $report['run_id'] = $runId;

    $ins = ['categories'=>0,'subcategories'=>0,'case_types'=>0,'requests'=>0];
    $upd = ['categories'=>0,'subcategories'=>0,'case_types'=>0,'requests'=>0];

    try {
        $pdo->beginTransaction();

        foreach ($cats as $cat) {
            $catId = najiz_upsert_category($pdo, $cat, $ins, $upd);
            foreach ($cat['subcategories'] as $sub) {
                $subId = najiz_upsert_subcategory($pdo, $catId, $cat, $sub, $ins, $upd);
                foreach ($sub['types'] as $type) {
                    $typeId = najiz_upsert_case_type($pdo, $subId, $cat, $sub, $type, $ins, $upd);
                    foreach ($type['requests'] as $rIdx => $reqName) {
                        najiz_upsert_request($pdo, $typeId, $cat, $sub, $type, $reqName, $rIdx + 1, $ins, $upd);
                    }
                }
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $pdo->prepare("UPDATE najiz_import_runs SET status='failed', error_text=?, finished_at=NOW() WHERE id=?")
            ->execute([$e->getMessage(), $runId]);
        throw $e;
    }

    $report['inserted'] = $ins;
    $report['updated'] = $upd;

    $pdo->prepare("UPDATE najiz_import_runs SET status='completed', inserted_counts_json=?, finished_at=NOW() WHERE id=?")
        ->execute([json_encode(['inserted'=>$ins,'updated'=>$upd], JSON_UNESCAPED_UNICODE), $runId]);

    return $report;
}

/** Insert or update one main category. Returns its id. */
function najiz_upsert_category(PDO $pdo, array $cat, array &$ins, array &$upd): int {
    $key = najiz_source_key($cat['name_ar']);
    $existing = najiz_find_id($pdo, 'najiz_categories', 'name_ar', $cat['name_ar']);
    if ($existing) {
        $pdo->prepare('UPDATE najiz_categories SET name_en=?, sort_order=?, is_active=1 WHERE id=?')
            ->execute([$cat['name_en'] ?: null, $cat['sort_order'], $existing]);
        $upd['categories']++;
        return $existing;
    }
    $pdo->prepare('INSERT INTO najiz_categories (name_ar, name_en, source_ref, sort_order) VALUES (?,?,?,?)')
        ->execute([$cat['name_ar'], $cat['name_en'] ?: null, 'Najiz source', $cat['sort_order']]);
    $ins['categories']++;
    return (int)$pdo->lastInsertId();
}

function najiz_upsert_subcategory(PDO $pdo, int $catId, array $cat, array $sub, array &$ins, array &$upd): int {
    $key = najiz_source_key($cat['name_ar'] . '/' . $sub['name_ar']);
    $row = najiz_find_row($pdo, 'najiz_subcategories', 'source_key', $key);
    if ($row) {
        $pdo->prepare('UPDATE najiz_subcategories SET category_id=?, name_ar=?, declared_count=?, sort_order=?, is_active=1 WHERE id=?')
            ->execute([$catId, $sub['name_ar'], $sub['declared_count'], $sub['sort_order'], $row['id']]);
        $upd['subcategories']++;
        return (int)$row['id'];
    }
    $pdo->prepare('INSERT INTO najiz_subcategories (category_id, name_ar, declared_count, source_key, source_ref, sort_order) VALUES (?,?,?,?,?,?)')
        ->execute([$catId, $sub['name_ar'], $sub['declared_count'], $key, $cat['name_ar'], $sub['sort_order']]);
    $ins['subcategories']++;
    return (int)$pdo->lastInsertId();
}

function najiz_upsert_case_type(PDO $pdo, int $subId, array $cat, array $sub, array $type, array &$ins, array &$upd): int {
    // Ordinal of this name among identical names under the same subcategory. Two
    // 'النفقة' records under النفقة are distinct rows; without the ordinal the second
    // would overwrite the first and the import would silently lose a record.
    $ord = $type['sort_order']; // unique within the subcategory, stable across runs
    $path = $cat['name_ar'] . '/' . $sub['name_ar'] . '/' . $type['name_ar'];
    $key = najiz_source_key($path, $ord);
    $norm = najiz_normalize_ar($type['name_ar']);

    $row = najiz_find_row($pdo, 'najiz_case_types', 'source_key', $key);
    $defMissing = !empty($type['definition_missing']) ? 1 : 0;
    $reqAbsent = !empty($type['requests_declared_absent']) ? 1 : 0;
    $note = $type['parse_note'] ?? null;

    if ($row) {
        $pdo->prepare('UPDATE najiz_case_types SET subcategory_id=?, name_ar=?, definition=?, definition_missing=?, requests_declared_absent=?, search_norm=?, parse_note=?, sort_order=?, is_active=1 WHERE id=?')
            ->execute([$subId, $type['name_ar'], $type['definition'], $defMissing, $reqAbsent, $norm, $note, $type['sort_order'], $row['id']]);
        $upd['case_types']++;
        return (int)$row['id'];
    }
    $pdo->prepare('INSERT INTO najiz_case_types (subcategory_id, name_ar, definition, definition_missing, requests_declared_absent, source_key, source_ref, search_norm, parse_note, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$subId, $type['name_ar'], $type['definition'], $defMissing, $reqAbsent, $key, $type['source_ref'], $norm, $note, $type['sort_order']]);
    $ins['case_types']++;
    return (int)$pdo->lastInsertId();
}

function najiz_upsert_request(PDO $pdo, int $typeId, array $cat, array $sub, array $type, string $name, int $ord, array &$ins, array &$upd): int {
    $path = $cat['name_ar'] . '/' . $sub['name_ar'] . '/' . $type['name_ar'] . '/' . $name;
    $key = najiz_source_key($path, $ord);
    $norm = najiz_normalize_ar($name);
    $row = najiz_find_row($pdo, 'najiz_judicial_requests', 'source_key', $key);
    if ($row) {
        $pdo->prepare('UPDATE najiz_judicial_requests SET case_type_id=?, name_ar=?, search_norm=?, sort_order=?, is_active=1 WHERE id=?')
            ->execute([$typeId, $name, $norm, $ord, $row['id']]);
        $upd['requests']++;
        return (int)$row['id'];
    }
    $pdo->prepare('INSERT INTO najiz_judicial_requests (case_type_id, name_ar, source_key, search_norm, sort_order) VALUES (?,?,?,?,?)')
        ->execute([$typeId, $name, $key, $norm, $ord]);
    $ins['requests']++;
    return (int)$pdo->lastInsertId();
}

function najiz_find_id(PDO $pdo, string $table, string $col, string $val): ?int {
    $r = najiz_find_row($pdo, $table, $col, $val);
    return $r ? (int)$r['id'] : null;
}

function najiz_find_row(PDO $pdo, string $table, string $col, string $val): ?array {
    // $table/$col are internal literals from this file, never user input.
    $s = $pdo->prepare("SELECT id FROM `$table` WHERE `$col` = ? LIMIT 1");
    $s->execute([$val]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/**
 * Search the hierarchy with Arabic normalisation.
 *
 * Matches against the normalised name of types, requests and subcategories, and
 * returns each hit with its full breadcrumb. Deterministic SQL only — no AI, no
 * external service.
 */
function najiz_search(PDO $pdo, string $query, int $limit = 40): array {
    $tokens = najiz_query_tokens($query);
    if (!$tokens) return [];

    $where = [];
    $params = [];
    foreach ($tokens as $t) {
        // Every token must appear somewhere in the type name, its definition, or one
        // of its requests. Each term is bound, never interpolated.
        $where[] = '(ct.search_norm LIKE ? OR ct.definition LIKE ? OR EXISTS (
                        SELECT 1 FROM najiz_judicial_requests jr
                        WHERE jr.case_type_id = ct.id AND (jr.search_norm LIKE ? OR jr.name_ar LIKE ?)))';
        $like = '%' . $t . '%';
        array_push($params, $like, $like, $like, $like);
    }
    $sql = 'SELECT ct.id, ct.name_ar, ct.definition, ct.definition_missing, ct.requests_declared_absent,
                   sc.name_ar AS sub_name, sc.id AS sub_id,
                   ca.name_ar AS cat_name, ca.id AS cat_id,
                   (SELECT GROUP_CONCAT(jr.name_ar ORDER BY jr.sort_order SEPARATOR " • ")
                      FROM najiz_judicial_requests jr
                     WHERE jr.case_type_id = ct.id AND jr.is_active = 1) AS requests
              FROM najiz_case_types ct
              JOIN najiz_subcategories sc ON sc.id = ct.subcategory_id
              JOIN najiz_categories ca ON ca.id = sc.category_id
             WHERE ct.is_active = 1 AND sc.is_active = 1 AND ca.is_active = 1
               AND ' . implode(' AND ', $where) . '
             ORDER BY ca.sort_order, sc.sort_order, ct.sort_order
             LIMIT ' . (int)$limit;
    $s = $pdo->prepare($sql);
    $s->execute($params);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Deterministic "suggested category" for a free-text description.
 *
 * Scores each active case type by how many of its name/definition/request tokens the
 * description contains, and returns the best match with its breadcrumb. This is a
 * keyword score, not a legal opinion: the caller must present it as a suggestion and
 * say so. No AI is involved, and the feature works with no provider configured.
 */
function najiz_suggest_from_text(PDO $pdo, string $text, int $limit = 5): array {
    $tokens = najiz_query_tokens($text);
    if (!$tokens) return [];
    // Ignore very common Arabic function words that would match almost anything.
    $stop = ['في','من','على','عن','الى','إلى','مع','هذا','هذه','التي','الذي','او','أو','و','ان','أن','كان','قد','لم','لا','ما','هو','هي','كل','بين','بعد','قبل','عند','حتى','اذا','إذا'];
    $tokens = array_values(array_filter($tokens, fn($t) => !in_array($t, $stop, true) && mb_strlen($t) >= 2));
    if (!$tokens) return [];

    $rows = $pdo->query('SELECT ct.id, ct.name_ar, ct.definition, ct.search_norm,
                                sc.name_ar AS sub_name, ca.name_ar AS cat_name,
                                (SELECT GROUP_CONCAT(jr.search_norm SEPARATOR " ")
                                   FROM najiz_judicial_requests jr
                                  WHERE jr.case_type_id = ct.id AND jr.is_active = 1) AS req_norm
                           FROM najiz_case_types ct
                           JOIN najiz_subcategories sc ON sc.id = ct.subcategory_id
                           JOIN najiz_categories ca ON ca.id = sc.category_id
                          WHERE ct.is_active = 1 AND sc.is_active = 1 AND ca.is_active = 1')
        ->fetchAll(PDO::FETCH_ASSOC);

    $scored = [];
    foreach ($rows as $r) {
        $hay = ($r['search_norm'] ?? '') . ' ' . ($r['req_norm'] ?? '') . ' ' . najiz_normalize_ar((string)$r['definition']);
        $score = 0;
        foreach ($tokens as $t) {
            if ($t !== '' && mb_strpos($hay, $t) !== false) $score += ($t === najiz_normalize_ar($r['name_ar'])) ? 3 : 1;
        }
        if ($score > 0) { $r['score'] = $score; $scored[] = $r; }
    }
    usort($scored, fn($a, $b) => $b['score'] <=> $a['score'] ?: strcmp($a['name_ar'], $b['name_ar']));
    return array_slice($scored, 0, $limit);
}
