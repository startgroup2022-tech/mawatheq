<?php
/**
 * Saudi Najiz judicial classification as a grounded source for the legal assistant.
 *
 * The classification (categories → subcategories → case types → judicial requests) is
 * reference data: it names the *type of claim* a Saudi court hears and the requests it
 * carries. It is not a statute and carries no article numbers, so it may only ever be
 * presented as a classification aid — never as legal authority for a rule.
 *
 * This module is the one bridge between `najiz_*` and the AI layer. Before it existed the
 * taxonomy was readable only through the classification guide, the journey picker and the
 * admin console; no prompt ever saw it. Keeping the bridge in one place is what lets the
 * "classification, not statute" boundary be enforced and tested in a single function.
 *
 * Degradation is deliberate and matches the rest of the platform: the tables may be absent
 * (v59 not applied), the taxonomy may be empty (importer never run), or the search may
 * throw. Each returns an empty result so the assistant falls back to its existing sources
 * rather than failing the request.
 */

require_once __DIR__ . '/najiz_search_lib.php';

/**
 * Classification matches for a free-text question, shaped for the assistant.
 *
 * @return array<int, array{
 *   id:int, name_ar:string, definition:string, requests:array<int,string>,
 *   category:string, subcategory:string, breadcrumb:string, has_detail:bool
 * }>
 */
function legal_ai_najiz_matches(PDO $pdo, string $question, int $limit = 3): array {
    $question = trim($question);
    if ($question === '') return [];

    // najiz_search() requires *every* token to match, which a natural question never
    // satisfies ("أريد فسخ عقد إيجار" fails on "أريد"). najiz_suggest_from_text() scores by
    // token overlap and drops Arabic function words, so it is the right primitive for free
    // text; its output is then enriched with the definition and requests below.
    if (!function_exists('najiz_suggest_from_text')) {
        $lib = __DIR__ . '/najiz_import.php';
        if (!is_file($lib)) return [];
        require_once $lib;
    }
    if (!function_exists('najiz_suggest_from_text')) return [];

    try {
        // Over-fetch, then re-score below. najiz_suggest_from_text() scores any substring
        // hit, so a 2-letter question word matches inside a longer word ("كم" inside
        // "الحكم") and a confident wrong classification is worse than none in a legal
        // answer. The re-score is done here, on the detail rows, because the shared helper
        // also serves the public classification guide and its behaviour must not change.
        $scored = najiz_suggest_from_text($pdo, $question, max(1, $limit) * 6);
    } catch (Throwable $e) {
        // A missing `najiz_*` table (v59 not applied) lands here, and that is expected.
        error_log('legal_ai_najiz_matches unavailable: ' . $e->getMessage());
        return [];
    }
    if (!$scored) return [];

    $ids = array_map(fn($r) => (int)$r['id'], $scored);
    $in = implode(',', array_fill(0, count($ids), '?'));
    try {
        $st = $pdo->prepare(
            'SELECT ct.id, ct.name_ar, ct.definition, ct.definition_missing,
                    sc.name_ar AS sub_name, ca.name_ar AS cat_name,
                    (SELECT GROUP_CONCAT(jr.name_ar ORDER BY jr.sort_order SEPARATOR " • ")
                       FROM najiz_judicial_requests jr
                      WHERE jr.case_type_id = ct.id AND jr.is_active = 1) AS requests
               FROM najiz_case_types ct
               JOIN najiz_subcategories sc ON sc.id = ct.subcategory_id
               JOIN najiz_categories ca ON ca.id = sc.category_id
              WHERE ct.id IN (' . $in . ')'
        );
        $st->execute($ids);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('legal_ai_najiz_matches detail unavailable: ' . $e->getMessage());
        return [];
    }
    if (!$rows) return [];
    // Preserve the relevance order the scorer produced; the IN() query does not.
    $byId = [];
    foreach ($rows as $r) $byId[(int)$r['id']] = $r;
    $ordered = [];
    foreach ($ids as $id) if (isset($byId[$id])) $ordered[] = $byId[$id];

    // Re-score with a length floor: only tokens of 3+ characters count towards relevance.
    // A single distinctive term ("حضانة") is enough, while a question word ("كم") is not.
    $tokens = array_values(array_filter(
        najiz_query_tokens($question),
        fn($t) => mb_strlen($t) >= 3
    ));
    if (!$tokens) return [];
    $rescored = [];
    foreach ($ordered as $r) {
        $hay = najiz_normalize_ar(
            (string)$r['name_ar'] . ' ' . (string)($r['definition'] ?? '') . ' ' . (string)($r['requests'] ?? '')
            . ' ' . (string)($r['sub_name'] ?? '') . ' ' . (string)($r['cat_name'] ?? '')
        );
        $score = 0;
        foreach ($tokens as $t) {
            if (mb_strpos($hay, $t) !== false) {
                // A hit in the type's own name is far stronger evidence than one in a
                // long definition or a sibling list.
                $score += mb_strpos(najiz_normalize_ar((string)$r['name_ar']), $t) !== false ? 3 : 1;
            }
        }
        if ($score >= 3) { $r['score'] = $score; $rescored[] = $r; }
    }
    if (!$rescored) return [];
    usort($rescored, fn($a, $b) => $b['score'] <=> $a['score']);
    $ordered = array_slice($rescored, 0, max(1, $limit));

    $out = [];
    foreach ($ordered as $r) {
        $requests = [];
        if (!empty($r['requests'])) {
            foreach (explode('•', (string)$r['requests']) as $req) {
                $req = trim($req);
                if ($req !== '') $requests[] = $req;
            }
        }
        $definition = trim((string)($r['definition'] ?? ''));
        // The importer writes a placeholder sentence for types the source leaves undefined.
        // Passing that to a model reads as content and invites it to paraphrase it.
        $hasDetail = $definition !== '' && empty($r['definition_missing']) && mb_strpos($definition, 'لا يوجد تعريف مسجل') === false;
        if (!$hasDetail) $definition = '';

        $cat = (string)($r['cat_name'] ?? '');
        $sub = (string)($r['sub_name'] ?? '');
        $out[] = [
            'id'          => (int)$r['id'],
            'name_ar'     => (string)($r['name_ar'] ?? ''),
            // najiz_search() does not select name_en, and the source populates it for no
            // case type (234/234 empty), so there is no English name to offer. The Arabic
            // name is shown in both languages rather than a fabricated translation.
            'definition'  => $definition,
            'requests'    => $requests,
            'category'    => $cat,
            'subcategory' => $sub,
            'breadcrumb'  => trim($cat . ' / ' . $sub, ' /'),
            'has_detail'  => $hasDetail && $requests !== [],
        ];
    }
    return $out;
}

/**
 * The classification block for a system prompt, or '' when nothing matched.
 *
 * Every match is wrapped in BEGIN/END markers and prefixed with an explicit statement of
 * what it is, because the surrounding prompt already tells the model that retrieved text
 * is untrusted data. The block never contains an article number and never claims to.
 */
function legal_ai_najiz_context(array $matches, bool $isArabic): string {
    if (!$matches) return '';

    $head = $isArabic
        ? "\n\nتصنيف قضائي سعودي (مرجعي — ليس نصاً نظامياً ولا يحمل أرقام مواد). استخدمه للتعرف على نوع الدعوى والطلبات الممكنة فقط، ولا تنسب إليه حكماً نظامياً:"
        : "\n\nSaudi judicial classification (reference only — not a statute and carries no article numbers). Use it to identify the type of claim and its possible requests only; do not attribute a legal rule to it:";

    $body = '';
    foreach ($matches as $i => $m) {
        $n = $i + 1;
        $lines = [];
        $lines[] = $isArabic
            ? 'التصنيف: ' . $m['breadcrumb'] . ' — ' . $m['name_ar']
            : 'Classification: ' . $m['breadcrumb'] . ' — ' . ($m['name_ar']);
        if ($m['definition'] !== '') {
            $lines[] = ($isArabic ? 'التعريف: ' : 'Definition: ') . $m['definition'];
        }
        if ($m['requests']) {
            $lines[] = ($isArabic ? 'الطلبات: ' : 'Requests: ') . implode(' • ', array_slice($m['requests'], 0, 8));
        }
        $body .= "\n[BEGIN CLASSIFICATION {$n}]\n" . implode("\n", $lines) . "\n[END CLASSIFICATION {$n}]\n";
    }
    return $head . $body;
}

/**
 * Citations in the shape `generate_grounded_legal_answer()` already uses.
 *
 * `verified` is false on purpose: the classification is authoritative reference data, but
 * it is not a verified statutory source, and marking it otherwise would let a citation
 * check treat a taxonomy entry as a statute.
 */
function legal_ai_najiz_citations(array $matches): array {
    $out = [];
    foreach ($matches as $m) {
        $out[] = [
            'name'     => trim($m['breadcrumb'] . ' — ' . $m['name_ar'], ' —'),
            'url'      => '/classification-guide.php?type=' . (int)$m['id'],
            'version'  => 1,
            'verified' => false,
            'type'     => 'najiz_classification',
        ];
    }
    return $out;
}

/**
 * Deterministic answer built from the classification alone.
 *
 * This is what the assistant returns when no AI provider is configured. It is not a
 * substitute for the model — it does not reason, and it says so — but it is grounded,
 * specific and honest, which the previous "activate an AI provider" sentence was not.
 */
function legal_ai_najiz_plain_answer(array $matches, bool $isArabic): ?array {
    if (!$matches) return null;

    $first = $matches[0];
    if ($isArabic) {
        $lines = ['بحسب التصنيف القضائي السعودي، أقرب نوع دعوى لسؤالك هو: ' . $first['name_ar'] . ' (' . $first['breadcrumb'] . ').'];
        if ($first['definition'] !== '') $lines[] = 'التعريف: ' . $first['definition'];
        if ($first['requests']) $lines[] = 'من الطلبات المدرجة تحت هذا النوع: ' . implode(' • ', array_slice($first['requests'], 0, 5)) . '.';
        if (count($matches) > 1) {
            $others = array_map(fn($m) => $m['name_ar'], array_slice($matches, 1));
            $lines[] = 'وهناك أنواع قريبة: ' . implode('، ', $others) . '.';
        }
        $lines[] = 'هذا تصنيف مرجعي وليس رأياً قانونياً. لتقييم حالتك وحجز موعد مع محامٍ مرخّص، تابع من صفحة الاستشارة.';
        return ['reply' => implode("\n", $lines)];
    }

    $lines = ['According to the Saudi judicial classification, the closest claim type is: ' . $first['name_ar'] . ' (' . $first['breadcrumb'] . ').'];
    if ($first['definition'] !== '') $lines[] = 'Definition: ' . $first['definition'];
    if ($first['requests']) $lines[] = 'Listed requests under this type: ' . implode(' • ', array_slice($first['requests'], 0, 5)) . '.';
    if (count($matches) > 1) {
        $others = array_map(fn($m) => $m['name_ar'], array_slice($matches, 1));
        $lines[] = 'Related types: ' . implode(', ', $others) . '.';
    }
    $lines[] = 'This is reference classification, not legal advice. To assess your matter and book a licensed lawyer, continue from the consultation page.';
    return ['reply' => implode("\n", $lines)];
}
