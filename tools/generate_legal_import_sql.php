<?php
/**
 * Generate MAWATHEQ_LEGAL_CLASSIFICATIONS_IMPORT.sql from the Najiz source document.
 *
 *   php tools/generate_legal_import_sql.php [--out=path]
 *
 * Why a generator rather than a checked-in hand-written INSERT dump: the SQL must be
 * byte-faithful to what includes/najiz_import.php would write, or the two paths diverge
 * and a re-import through the admin console starts editing rows this file created.
 * Every value here — including the SHA-1 `source_key` and the normalised `search_norm`
 * — is produced by calling the same functions the importer calls, so the two cannot
 * drift. Re-run it whenever the source document changes.
 *
 * The output is a pure .sql file: no PHP, no CLI tooling, no admin console. It imports
 * through phpMyAdmin and is safe to run repeatedly (see the header it writes).
 */

require_once __DIR__ . '/../includes/najiz_parser.php';
require_once __DIR__ . '/../includes/najiz_search_lib.php';
require_once __DIR__ . '/../includes/najiz_import.php';

$root = dirname(__DIR__);
$source = $root . '/database/sources/Pasted%20text%283%29.txt';
$out = $root . '/database/MAWATHEQ_LEGAL_CLASSIFICATIONS_IMPORT.sql';

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--out=')) $out = substr($arg, 6);
}

$parsed = najiz_parse_source($source);
$cats = $parsed['categories'];
$stats = $parsed['stats'];

/** Escape for a MySQL single-quoted literal. Escapes the characters MySQL treats
 *  specially inside a string, so Arabic punctuation and any stray apostrophe in the
 *  source survive verbatim. */
function sql_str(?string $v): string {
    if ($v === null) return 'NULL';
    $v = str_replace(
        ["\\",  "\0",  "\n",  "\r",  "\x1a", "'"],
        ["\\\\", "\\0", "\\n", "\\r", "\\Z",  "''"],
        $v
    );
    return "'" . $v . "'";
}

function sql_int(?int $v): string { return $v === null ? 'NULL' : (string)$v; }

$TABLES = ['najiz_categories','najiz_subcategories','najiz_case_types',
           'najiz_judicial_requests','najiz_search_synonyms','najiz_import_runs',
           'najiz_audit_log'];

$out_sql = [];
$w = function (string $s = '') use (&$out_sql) { $out_sql[] = $s; };

// ---------------------------------------------------------------- header
$w('-- ============================================================================');
$w('-- MAWATHEQ — LEGAL CLASSIFICATIONS IMPORT');
$w('-- MAWATHEQ_LEGAL_CLASSIFICATIONS_IMPORT.sql');
$w('-- ============================================================================');
$w('--');
$w('-- WHAT THIS IS');
$w('--   The complete Saudi Najiz legal classification hierarchy — the seven main');
$w('--   categories, their subcategories, every lawsuit type and every judicial');
$w('--   request — as plain SQL, ready to import from phpMyAdmin.');
$w('--');
$w('--   Generated from database/sources/Pasted text(3).txt by');
$w('--   tools/generate_legal_import_sql.php, which calls the same parser and the same');
$w('--   key/normalisation functions as the admin-console importer. The rows written');
$w('--   here are therefore the exact rows that importer would write: the two paths');
$w('--   are interchangeable and re-importing through either is a no-op.');
$w('--');
$w('-- HOW TO RUN (phpMyAdmin)');
$w('--   1. Select the database (mawatheq_thaer) in the left column.');
$w('--   2. Open the "Import" tab.');
$w('--   3. Choose this file, leave the charset as utf-8, press "Go".');
$w('--   No admin console, no CLI, no PHP.');
$w('--');
$w('-- ORDER');
$w('--   Run MAWATHEQ_DB_SCHEMA_FIX.sql FIRST. This file checks that and aborts with a');
$w('--   plain message if the tables or columns are missing.');
$w('--');
$w('-- SAFETY');
$w('--   * ADDITIVE / IDEMPOTENT. Every row is matched on a deterministic key, so');
$w('--     running this file twice inserts nothing the second time and never');
$w('--     duplicates a classification.');
$w('--   * No DROP, no TRUNCATE, no DELETE. Customer, lawyer, case, consultation,');
$w('--     invoice and document data are never read or written by this file.');
$w('--   * No hard-coded ids. Parent rows are resolved by their unique key, so the');
$w('--     file works on a database whose ids are already in use.');
$w('--   * It does not overwrite wording an operator has customised: the');
$w('--     customer-facing description and icon are written only where blank.');
$w('--   * utf8mb4 throughout; Arabic text is preserved exactly as in the source.');
$w('--');
$w('-- CONTENTS');
$w('--   ' . $stats['subcategories'] . ' subcategories, ' . $stats['types'] . ' lawsuit types, '
   . $stats['requests'] . ' judicial requests');
$w('--   across ' . count($cats) . ' main categories. The source declares '
   . $stats['declared_total_types'] . ' types;');
$w('--   ' . $stats['types'] . ' are parsed (' . $stats['types_with_detail'] . ' with a detail block, '
   . $stats['types_without_detail'] . ' without).');
$w('-- ============================================================================');
$w('');
$w('SET NAMES utf8mb4;');
$w('');
$w('-- ----------------------------------------------------------------------------');
$w('-- Preconditions.');
$w('--   Each check below raises a deliberate error naming the missing prerequisite,');
$w('--   which stops the import before any row is written. That is the intended');
$w('--   behaviour: a half-applied import is worse than a refused one. If you see');
$w('--   "PLEASE RUN MAWATHEQ_DB_SCHEMA_FIX.sql FIRST", run that file and retry.');
$w('-- ----------------------------------------------------------------------------');
$w('SET @tables := (SELECT COUNT(*) FROM information_schema.TABLES');
$w('                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN');
$w("                (" . implode(',', array_map(fn($t) => sql_str($t), $TABLES)) . "));");
$w("SET @s := IF(@tables < " . count($TABLES) . ",");
$w("    'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PLEASE RUN MAWATHEQ_DB_SCHEMA_FIX.sql FIRST''',");
$w("    'SELECT 1');");
$w('PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;');
$w('');
$w('SET @cols := (SELECT COUNT(*) FROM information_schema.COLUMNS');
$w("              WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='najiz_categories'");
$w("                AND COLUMN_NAME IN ('customer_desc_ar','customer_icon_key'));");
$w("SET @s := IF(@cols < 2,");
$w("    'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PLEASE RUN MAWATHEQ_DB_SCHEMA_FIX.sql FIRST''',");
$w("    'SELECT 1');");
$w('PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;');
$w('');

// ---------------------------------------------------------------- categories
$w('-- ----------------------------------------------------------------------------');
$w('-- 1) Main categories (' . count($cats) . ')');
$w('--    Keyed on the unique name. The customer-facing description and icon are the');
$w('--    same ones upgrade_v63.sql backfills, so the journey cards render correctly');
$w('--    without a second migration run.');
$w('-- ----------------------------------------------------------------------------');
$catDesc = [
    'الأحوال الشخصية'                => 'شؤون الأسرة: الزواج والطلاق والنفقة والحضانة والميراث.',
    'الدعاوى التجارية'               => 'النزاعات التجارية والشركات والعقود والأوراق التجارية والإفلاس.',
    'الدعاوى العمالية'               => 'نزاعات العمل: حقوق وواجبات العامل وصاحب العمل ومستحقات نهاية الخدمة.',
    'الدعاوى الجزائية'               => 'القضايا الجزائية والمطالبات المالية الناشئة عن الجرائم.',
    'الدعاوى العامة'                 => 'الدعاوى المدنية والعقارية والعقود والتحكيم.',
    'دعاوى التنفيذ'                  => 'تنفيذ الأحكام والسندات التنفيذية والمنازعات المتعلقة بها.',
    'الدعاوى المتعلقة بالإنهاءات'    => 'إنهاءات الأوقاف والمواريث والصكوك والولايات.',
];
$catIcon = [
    'الأحوال الشخصية'                => 'family',
    'الدعاوى التجارية'               => 'commercial',
    'الدعاوى العمالية'               => 'labor',
    'الدعاوى الجزائية'               => 'criminal',
    'الدعاوى العامة'                 => 'general',
    'دعاوى التنفيذ'                  => 'enforcement',
    'الدعاوى المتعلقة بالإنهاءات'    => 'endowments',
];
$w('INSERT INTO najiz_categories');
$w('    (name_ar, name_en, description_ar, source_ref, sort_order, is_active,');
$w('     customer_desc_ar, customer_icon_key)');
$w('VALUES');
$rows = [];
foreach ($cats as $c) {
    $rows[] = '    (' . implode(', ', [
        sql_str($c['name_ar']),
        sql_str($c['name_en'] ?: null),
        sql_str(null),
        sql_str('Najiz source'),
        sql_int((int)$c['sort_order']),
        '1',
        sql_str($catDesc[$c['name_ar']] ?? null),
        sql_str($catIcon[$c['name_ar']] ?? null),
    ]) . ')';
}
$w(implode(",\n", $rows));
$w('ON DUPLICATE KEY UPDATE');
$w('    name_en            = VALUES(name_en),');
$w('    source_ref         = VALUES(source_ref),');
$w('    sort_order         = VALUES(sort_order),');
$w('    is_active          = 1,');
$w('    -- Only fill blanks: an operator\'s own wording is never overwritten.');
$w("    customer_desc_ar   = COALESCE(NULLIF(customer_desc_ar,''), VALUES(customer_desc_ar)),");
$w("    customer_icon_key  = COALESCE(NULLIF(customer_icon_key,''), VALUES(customer_icon_key));");
$w('');

// ---------------------------------------------------------------- subcategories
$w('-- ----------------------------------------------------------------------------');
$w('-- 2) Subcategories (' . $stats['subcategories'] . ')');
$w('--    category_id is resolved by name, never assumed. source_key is the same');
$w('--    SHA-1 the importer computes, so both paths address the same row.');
$w('-- ----------------------------------------------------------------------------');
$batch = [];
$flush = function () use (&$batch, $w) {
    if (!$batch) return;
    $w('INSERT INTO najiz_subcategories');
    $w('    (category_id, name_ar, declared_count, source_key, source_ref, sort_order, is_active)');
    $w('VALUES');
    $w(implode(",\n", $batch));
    $w('ON DUPLICATE KEY UPDATE');
    $w('    category_id    = VALUES(category_id),');
    $w('    name_ar        = VALUES(name_ar),');
    $w('    declared_count = VALUES(declared_count),');
    $w('    source_ref     = VALUES(source_ref),');
    $w('    sort_order     = VALUES(sort_order),');
    $w('    is_active      = 1;');
    $w('');
    $batch = [];
};
foreach ($cats as $c) {
    foreach ($c['subcategories'] as $s) {
        $key = najiz_source_key($c['name_ar'] . '/' . $s['name_ar']);
        $batch[] = '    ((SELECT id FROM najiz_categories WHERE name_ar=' . sql_str($c['name_ar']) . '), '
            . implode(', ', [
                sql_str($s['name_ar']),
                sql_int(isset($s['declared_count']) ? (int)$s['declared_count'] : null),
                sql_str($key),
                sql_str($c['name_ar']),
                sql_int((int)$s['sort_order']),
                '1',
            ]) . ')';
        if (count($batch) >= 40) $flush();
    }
}
$flush();

// ---------------------------------------------------------------- case types
$w('-- ----------------------------------------------------------------------------');
$w('-- 3) Lawsuit types (' . $stats['types'] . ')');
$w('--    subcategory_id is resolved through the subcategory source_key, which is');
$w('--    unique, so a name that recurs under two parents still lands correctly.');
$w('--    `definition_missing` and `requests_declared_absent` are preserved as the');
$w('--    source has them: the source declares ' . $stats['declared_total_types'] . ' types, of which '
   . $stats['types_without_detail']);
$w('--    carry no detail block. Those are real records and are imported, not dropped.');
$w('-- ----------------------------------------------------------------------------');
$batch = [];
$flushT = function () use (&$batch, $w) {
    if (!$batch) return;
    $w('INSERT INTO najiz_case_types');
    $w('    (subcategory_id, name_ar, definition, definition_missing, requests_declared_absent,');
    $w('     source_key, source_ref, search_norm, parse_note, sort_order, is_active)');
    $w('VALUES');
    $w(implode(",\n", $batch));
    $w('ON DUPLICATE KEY UPDATE');
    $w('    subcategory_id            = VALUES(subcategory_id),');
    $w('    name_ar                   = VALUES(name_ar),');
    $w('    definition                = VALUES(definition),');
    $w('    definition_missing        = VALUES(definition_missing),');
    $w('    requests_declared_absent  = VALUES(requests_declared_absent),');
    $w('    source_ref                = VALUES(source_ref),');
    $w('    search_norm               = VALUES(search_norm),');
    $w('    parse_note                = VALUES(parse_note),');
    $w('    sort_order                = VALUES(sort_order),');
    $w('    is_active                 = 1;');
    $w('');
    $batch = [];
};
foreach ($cats as $c) {
    foreach ($c['subcategories'] as $s) {
        $subKey = najiz_source_key($c['name_ar'] . '/' . $s['name_ar']);
        foreach ($s['types'] as $t) {
            $path = $c['name_ar'] . '/' . $s['name_ar'] . '/' . $t['name_ar'];
            $key = najiz_source_key($path, (int)$t['sort_order']);
            $batch[] = '    ((SELECT id FROM najiz_subcategories WHERE source_key=' . sql_str($subKey) . '), '
                . implode(', ', [
                    sql_str($t['name_ar']),
                    sql_str($t['definition'] ?? null),
                    !empty($t['definition_missing']) ? '1' : '0',
                    !empty($t['requests_declared_absent']) ? '1' : '0',
                    sql_str($key),
                    sql_str($t['source_ref'] ?? null),
                    sql_str(najiz_normalize_ar($t['name_ar'])),
                    sql_str($t['parse_note'] ?? null),
                    sql_int((int)$t['sort_order']),
                    '1',
                ]) . ')';
            if (count($batch) >= 40) $flushT();
        }
    }
}
$flushT();

// ---------------------------------------------------------------- requests
$w('-- ----------------------------------------------------------------------------');
$w('-- 4) Judicial requests (' . $stats['requests'] . ')');
$w('--    case_type_id is resolved through the type source_key. Requests belong to');
$w('--    exactly one type, as the source presents them; a type with none carries');
$w('--    requests_declared_absent rather than a placeholder row.');
$w('-- ----------------------------------------------------------------------------');
$batch = [];
$flushR = function () use (&$batch, $w) {
    if (!$batch) return;
    $w('INSERT INTO najiz_judicial_requests');
    $w('    (case_type_id, name_ar, source_key, search_norm, sort_order, is_active)');
    $w('VALUES');
    $w(implode(",\n", $batch));
    $w('ON DUPLICATE KEY UPDATE');
    $w('    case_type_id = VALUES(case_type_id),');
    $w('    name_ar      = VALUES(name_ar),');
    $w('    search_norm  = VALUES(search_norm),');
    $w('    sort_order   = VALUES(sort_order),');
    $w('    is_active    = 1;');
    $w('');
    $batch = [];
};
foreach ($cats as $c) {
    foreach ($c['subcategories'] as $s) {
        foreach ($s['types'] as $t) {
            $typePath = $c['name_ar'] . '/' . $s['name_ar'] . '/' . $t['name_ar'];
            $typeKey = najiz_source_key($typePath, (int)$t['sort_order']);
            foreach ($t['requests'] as $i => $reqName) {
                $ord = $i + 1;
                $key = najiz_source_key($typePath . '/' . $reqName, $ord);
                $batch[] = '    ((SELECT id FROM najiz_case_types WHERE source_key=' . sql_str($typeKey) . '), '
                    . implode(', ', [
                        sql_str($reqName),
                        sql_str($key),
                        sql_str(najiz_normalize_ar($reqName)),
                        sql_int($ord),
                        '1',
                    ]) . ')';
                if (count($batch) >= 40) $flushR();
            }
        }
    }
}
$flushR();

// ---------------------------------------------------------------- footer
$w('-- ----------------------------------------------------------------------------');
$w('-- 5) Result. Read-only: what this file produced, so the outcome is seen rather');
$w('--    than assumed. Compare against the expected counts above.');
$w('-- ----------------------------------------------------------------------------');
$w("SELECT 'categories'          AS kind, COUNT(*) AS rows_now, " . count($cats) . " AS expected FROM najiz_categories");
$w("UNION ALL SELECT 'subcategories',      COUNT(*), " . $stats['subcategories'] . " FROM najiz_subcategories");
$w("UNION ALL SELECT 'case types',         COUNT(*), " . $stats['types'] . " FROM najiz_case_types");
$w("UNION ALL SELECT 'judicial requests',  COUNT(*), " . $stats['requests'] . " FROM najiz_judicial_requests;");
$w('');
$w('-- If a count is lower than expected, re-running this file is safe and will');
$w('-- complete the remainder. Nothing is ever deleted by doing so.');
$w('');

file_put_contents($out, implode("\n", $out_sql));
printf("wrote %s (%d bytes, %d lines)\n", $out, filesize($out), count($out_sql));
printf("categories=%d subcategories=%d types=%d requests=%d\n",
    count($cats), $stats['subcategories'], $stats['types'], $stats['requests']);
