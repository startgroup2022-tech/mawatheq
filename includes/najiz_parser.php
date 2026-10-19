<?php
/**
 * Parser for the Saudi Najiz lawsuit classification source document.
 *
 * The source is a flattened rendering of a four-level hierarchy. It is *not* a
 * nested format, so the levels are recovered from the line grammar rather than
 * from indentation:
 *
 *   <main category>              a line equal to one of the seven known names,
 *                                occurring after the table of contents
 *   <subcategory>                a line whose successor is a bare integer — that
 *   <count>                      integer is the subcategory's declared type count
 *   <lawsuit type>               a line whose successor is "التصنيف الفرعي للدعوى"
 *     التصنيف الفرعي للدعوى        then the subcategory, "نوع الدعوى" then the type,
 *     <subcategory>                "الطلبات المتاحة" then one or more requests,
 *     نوع الدعوى                    "تعريف الدعوى (إن وجد)" then one definition line
 *     <lawsuit type>
 *     الطلبات المتاحة
 *     <request>...
 *     تعريف الدعوى (إن وجد)
 *     <definition>
 *
 * Two shapes need care:
 *
 *  • A *type heading with no detail block* — a line followed by a blank line and
 *    then either a subcategory header or another type heading. It is a real
 *    source record with no requests and no definition, so it is imported with
 *    those absences preserved rather than dropped. This is also where the
 *    declared count (234) exceeds the number of detail blocks (227).
 *
 *  • A *type heading with neither a detail block nor a blank separator*, seen at
 *    the end of a subcategory. Handled by the same lookahead.
 *
 * Nothing here is specific to the content: the parser reads whatever the file
 * contains. It holds no classification names and no counts of its own.
 */

const NAJIZ_LABEL_SUB  = 'التصنيف الفرعي للدعوى';
const NAJIZ_LABEL_TYPE = 'نوع الدعوى';
const NAJIZ_LABEL_REQ  = 'الطلبات المتاحة';
const NAJIZ_LABEL_DEF  = 'تعريف الدعوى (إن وجد)';

/** The seven main categories, in source order. Used only to recognise the level-1
 *  boundary; the names themselves are read from the document. */
function najiz_main_category_names(): array {
    return [
        'الأحوال الشخصية',
        'الدعاوى التجارية',
        'الدعاوى العمالية',
        'الدعاوى الجزائية',
        'الدعاوى العامة',
        'دعاوى التنفيذ',
        'الدعاوى المتعلقة بالإنهاءات',
    ];
}

function najiz_is_blank(string $l): bool { return trim($l) === ''; }
function najiz_is_count(string $l): bool { return (bool)preg_match('/^\d+$/', trim($l)); }

/**
 * Parse the source document.
 *
 * @return array{categories:array, warnings:array, stats:array}
 */
function najiz_parse_source(string $path): array {
    if (!is_readable($path)) {
        throw new RuntimeException("source file is not readable: $path");
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException("source file could not be read: $path");
    }
    // The document is UTF-8; strip a BOM if a text editor added one.
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    $lines = preg_split('/\r\n|\r|\n/', $raw);

    $mainNames = najiz_main_category_names();
    $labels = [NAJIZ_LABEL_SUB, NAJIZ_LABEL_TYPE, NAJIZ_LABEL_REQ, NAJIZ_LABEL_DEF];
    $warnings = [];
    $n = count($lines);

    // ---- locate the seven main-category bodies ---------------------------------
    // Each main category name occurs twice: once in the tab strip near the top and
    // once as the heading of its own section. The heading is the occurrence that is
    // *not* part of that opening run of consecutive category names.
    $occurrences = [];
    foreach ($mainNames as $name) {
        $hits = [];
        foreach ($lines as $i => $l) {
            if (trim($l) === $name) $hits[] = $i;
        }
        $occurrences[$name] = $hits;
    }
    // Find the opening run: the longest ascending consecutive run of one hit each.
    $openingRun = [];
    $cursor = -1;
    foreach ($mainNames as $name) {
        $cand = null;
        foreach ($occurrences[$name] as $h) {
            if ($h > $cursor) { $cand = $h; break; }
        }
        if ($cand === null) continue;
        $openingRun[$name] = $cand;
        $cursor = $cand;
    }
    $sectionStart = [];
    foreach ($mainNames as $name) {
        $body = array_values(array_filter($occurrences[$name], function ($h) use ($openingRun, $name) {
            return !isset($openingRun[$name]) || $h !== $openingRun[$name];
        }));
        if (!$body) {
            $warnings[] = ['level' => 'main_category', 'issue' => 'no section body found', 'name' => $name];
            continue;
        }
        $sectionStart[$name] = $body[0];
    }
    asort($sectionStart);
    $orderedNames = array_keys($sectionStart);
    $bounds = [];
    foreach ($orderedNames as $k => $name) {
        $start = $sectionStart[$name];
        $end = isset($orderedNames[$k + 1]) ? $sectionStart[$orderedNames[$k + 1]] : $n;
        $bounds[] = ['name' => $name, 'start' => $start, 'end' => $end];
    }

    $footerNotes = [];
    $lastDefinitionLine = -1;
    foreach ($lines as $i => $l) {
        if (trim($l) === NAJIZ_LABEL_DEF) $lastDefinitionLine = max($lastDefinitionLine, $i + 1);
    }
    for ($i = $lastDefinitionLine + 1; $i < $n; $i++) {
        $t = trim($lines[$i]);
        if ($t === '' || najiz_is_count($t)) continue;
        $footerNotes[] = ['line' => $i + 1, 'text' => $t];
    }
    // The final section ends where the trailing footer prose begins, not at EOF.
    $contentEnd = ($lastDefinitionLine >= 0) ? $lastDefinitionLine + 1 : $n;
    $lastIdx = count($bounds) - 1;
    if ($lastIdx >= 0 && $bounds[$lastIdx]['end'] > $contentEnd) {
        $bounds[$lastIdx]['end'] = $contentEnd;
    }

    $declaredTotal = null;
    if (preg_match('/إجمالي أنواع الدعاوى المصنّفة:\s*(\d+)/u', $raw, $m)) {
        $declaredTotal = (int)$m[1];
    }

    $categories = [];
    $stats = [
        'declared_total_types' => $declaredTotal,
        'subcategories'        => 0,
        'types'                => 0,
        'types_with_detail'    => 0,
        'types_without_detail' => 0,
        'requests'             => 0,
        'definitions'          => 0,
        'declared_subcategory_counts' => 0,
    ];

    foreach ($bounds as $bIdx => $b) {
        $name = $b['name'];
        $cat = [
            'name_ar' => $name,
            'name_en' => najiz_english_for_main($name),
            'sort_order' => $bIdx + 1,
            'subcategories' => [],
        ];

        // A subcategory header inside this section is a line followed by a bare count.
        $subStarts = [];
        for ($i = $b['start'] + 1; $i < $b['end'] - 1; $i++) {
            if (najiz_is_count($lines[$i + 1])
                && !najiz_is_blank($lines[$i])
                && !in_array(trim($lines[$i]), $labels, true)) {
                $subStarts[] = $i;
            }
        }

        $subCount = count($subStarts);
        for ($s = 0; $s < $subCount; $s++) {
            $sStart = $subStarts[$s];
            $sEnd = ($s + 1 < $subCount) ? $subStarts[$s + 1] : $b['end'];
            $subName = trim($lines[$sStart]);
            $declared = (int)trim($lines[$sStart + 1]);

            $sub = [
                'name_ar' => $subName,
                'sort_order' => $s + 1,
                'declared_count' => $declared,
                'types' => [],
            ];

            // Type headings: a line whose successor is the "التصنيف الفرعي للدعوى" label.
            // A type may also appear with no detail block at all; those are found by the
            // gap scan below, so first collect the detail-block types in order.
            $typeStarts = [];
            for ($i = $sStart + 2; $i < $sEnd - 1; $i++) {
                if (trim($lines[$i + 1]) === NAJIZ_LABEL_SUB && !najiz_is_blank($lines[$i])) {
                    $typeStarts[] = $i;
                }
            }

            $typeIdx = 0;
            $consumed = [];
            foreach ($typeStarts as $tIdx => $tStart) {
                $tEnd = ($tIdx + 1 < count($typeStarts)) ? $typeStarts[$tIdx + 1] : $sEnd;
                $type = najiz_parse_type_block($lines, $tStart, $tEnd, $warnings, $subName, $name);
                if ($type === null) continue;
                // Consume only up to the definition line. A type heading with no detail
                // block sits between two blocks, and extending consumption to the next
                // heading would silently swallow it — which is exactly how three source
                // records went missing before this was bounded.
                for ($i = $tStart; $i <= $type['_consumed_to']; $i++) $consumed[$i] = true;
                unset($type['_consumed_to']);
                $typeIdx++;
                $type['sort_order'] = $typeIdx;
                $sub['types'][] = $type;
                $stats['types_with_detail']++;
            }

            // Types with no detail block: any non-blank, non-label line in the
            // subcategory body that is not part of a detail block we already consumed.
            for ($i = $sStart + 2; $i < $sEnd; $i++) {
                if (isset($consumed[$i])) continue;
                $t = trim($lines[$i]);
                if ($t === '' || najiz_is_count($t) || in_array($t, $labels, true)) continue;
                // The line immediately before a type heading was already consumed by
                // that heading, so it cannot reach here. A bare line here is a type
                // heading whose detail block is absent.
                $typeIdx++;
                $sub['types'][] = [
                    'name_ar' => $t,
                    'sort_order' => $typeIdx,
                    'requests' => [],
                    'definition' => null,
                    'definition_missing' => true,
                    'source_ref' => "{$name} / {$subName}",
                    'parse_note' => 'type heading present in source with no detail block',
                ];
                $stats['types_without_detail']++;
                $warnings[] = [
                    'level' => 'lawsuit_type',
                    'issue' => 'no detail block in source (no requests, no definition)',
                    'name' => $t,
                    'path' => "{$name} / {$subName}",
                ];
            }

            $sub['types_declared'] = $declared;
            $sub['types_parsed'] = count($sub['types']);
            if ($declared !== count($sub['types'])) {
                $warnings[] = [
                    'level' => 'subcategory',
                    'issue' => 'declared count does not match parsed types',
                    'name' => $subName,
                    'path' => "{$name} / {$subName}",
                    'declared' => $declared,
                    'parsed' => count($sub['types']),
                ];
            }
            $stats['declared_subcategory_counts'] += $declared;
            $cat['subcategories'][] = $sub;
            $stats['subcategories']++;
        }

        if (!$cat['subcategories']) {
            $warnings[] = ['level' => 'main_category', 'issue' => 'no subcategories parsed', 'name' => $name];
        }
        foreach ($cat['subcategories'] as $sub) {
            foreach ($sub['types'] as $t) {
                $stats['types']++;
                $stats['requests'] += count($t['requests']);
                if (!empty($t['definition'])) $stats['definitions']++;
            }
        }
        $categories[] = $cat;
    }

    return ['categories' => $categories, 'warnings' => $warnings, 'stats' => $stats, 'footer_notes' => $footerNotes];
}

/** Parse one complete detail block beginning at $start (the type heading line). */
function najiz_parse_type_block(array $lines, int $start, int $end, array &$warnings, string $subName, string $mainName): ?array {
    $name = trim($lines[$start]);
    if ($name === '') return null;

    $subLabelAt = null;
    for ($i = $start + 1; $i < $end; $i++) {
        if (trim($lines[$i]) === NAJIZ_LABEL_SUB) { $subLabelAt = $i; break; }
    }
    if ($subLabelAt === null) {
        $warnings[] = ['level' => 'lawsuit_type', 'issue' => 'detail block has no subcategory label', 'name' => $name];
        return null;
    }
    $typeLabelAt = null;
    for ($i = $subLabelAt + 1; $i < $end; $i++) {
        if (trim($lines[$i]) === NAJIZ_LABEL_TYPE) { $typeLabelAt = $i; break; }
    }
    $reqLabelAt = null;
    for ($i = ($typeLabelAt ?? $subLabelAt) + 1; $i < $end; $i++) {
        if (trim($lines[$i]) === NAJIZ_LABEL_REQ) { $reqLabelAt = $i; break; }
    }
    $defLabelAt = null;
    for ($i = ($reqLabelAt ?? $typeLabelAt ?? $subLabelAt) + 1; $i < $end; $i++) {
        if (trim($lines[$i]) === NAJIZ_LABEL_DEF) { $defLabelAt = $i; break; }
    }
    if ($reqLabelAt === null || $defLabelAt === null) {
        $warnings[] = ['level' => 'lawsuit_type', 'issue' => 'detail block is incomplete', 'name' => $name];
        return null;
    }

    // The type name as declared inside the block (authoritative over the heading).
    $declaredType = ($typeLabelAt !== null && isset($lines[$typeLabelAt + 1]))
        ? trim($lines[$typeLabelAt + 1]) : $name;

    $requests = [];
    for ($i = $reqLabelAt + 1; $i < $defLabelAt; $i++) {
        $t = trim($lines[$i]);
        if ($t === '') continue;
        $requests[] = $t;
    }

    $definition = null;
    $definitionMissing = false;
    $dLine = $defLabelAt + 1;
    if ($dLine < $end) {
        $d = trim($lines[$dLine]);
        if ($d !== '') $definition = $d;
    }
    if ($definition === null) {
        $definitionMissing = true;
        $warnings[] = ['level' => 'lawsuit_type', 'issue' => 'definition absent', 'name' => $declaredType, 'path' => "$mainName / $subName"];
    }

    // "لم تُحدَّد طلبات في المصدر لهذا النوع." is the source's own marker for a type
    // with no available requests. Keep it as an explicit absence, not as a request.
    $noRequests = false;
    $kept = [];
    foreach ($requests as $r) {
        if (str_starts_with($r, 'لم تُحدَّد طلبات')) { $noRequests = true; continue; }
        $kept[] = $r;
    }

    return [
        'name_ar' => $declaredType,
        'heading' => $name,
        'requests' => $kept,
        'requests_declared_absent' => $noRequests,
        'definition' => $definition,
        'definition_missing' => $definitionMissing,
        'source_ref' => "$mainName / $subName",
        'parse_note' => null,
        // Last line index belonging to this block, so the caller can mark exactly
        // this span as consumed without swallowing an intervening orphan heading.
        '_consumed_to' => $dLine,
    ];
}

/** English labels for the seven main categories, matching the platform's bilingual
 *  convention. These are translations of the source names, not new classifications. */
function najiz_english_for_main(string $ar): string {
    return [
        'الأحوال الشخصية' => 'Personal Status',
        'الدعاوى التجارية' => 'Commercial Cases',
        'الدعاوى العمالية' => 'Labor Cases',
        'الدعاوى الجزائية' => 'Criminal Cases',
        'الدعاوى العامة' => 'General Cases',
        'دعاوى التنفيذ' => 'Enforcement Cases',
        'الدعاوى المتعلقة بالإنهاءات' => 'Termination-related Cases',
    ][$ar] ?? '';
}
