<?php
/**
 * Legal-aware chunking (Phase 5).
 *
 * A legal document is not a bag of characters: an answer is only defensible if it can
 * cite the article or clause it came from. Splitting purely on a character count loses
 * that structure, so this module detects the structural markers a Saudi/English legal
 * text actually uses (باب/فصل/مادة/بند — Part/Chapter/Article/Clause) and records, per
 * chunk, the heading path, section, article, clause and page it belongs to.
 *
 * Two rules keep citations honest:
 *   1. A chunk never spans two different articles, so a citation cannot blend provisions.
 *   2. When no structure is detectable the provenance fields stay NULL — nothing is
 *      guessed, so the system never cites an article number that was not in the text.
 *
 * This module is pure text processing: no database, no AI, no network.
 */

if (!defined('LEGAL_CHUNK_TARGET')) define('LEGAL_CHUNK_TARGET', 2000); // preferred chunk size (chars)
if (!defined('LEGAL_CHUNK_MAX')) define('LEGAL_CHUNK_MAX', 3000);       // hard cap per chunk

/** Convert Arabic-Indic digits to ASCII so labels compare and index consistently. */
function legal_chunk_ascii_digits(string $s): string {
    return strtr($s, ['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
}

/** Arabic ordinal words used after "المادة" (المادة الأولى …). */
function legal_chunk_ordinals(): array {
    return [
        'الأولى'=>'1','الاولى'=>'1','الأول'=>'1','الاول'=>'1','الثانية'=>'2','الثاني'=>'2',
        'الثالثة'=>'3','الثالث'=>'3','الرابعة'=>'4','الرابع'=>'4','الخامسة'=>'5','الخامس'=>'5',
        'السادسة'=>'6','السادس'=>'6','السابعة'=>'7','السابع'=>'7','الثامنة'=>'8','الثامن'=>'8',
        'التاسعة'=>'9','التاسع'=>'9','العاشرة'=>'10','العاشر'=>'10','الحادية'=>'11','الحادي'=>'11',
        'الثانية عشرة'=>'12','الثاني عشر'=>'12',
    ];
}

/**
 * Classify a single line as a structural marker or ordinary text.
 * @return array{kind:string,label:?string,number:?string}
 *   kind ∈ section|article|clause|text
 */
function legal_chunk_classify_line(string $line): array {
    $t = trim($line);
    if ($t === '' || mb_strlen($t, 'UTF-8') > 200) return ['kind'=>'text','label'=>null,'number'=>null];

    // Section: الباب/الفصل/القسم/الجزء/المبحث/الفرع — Part/Chapter/Section.
    // Note: PCRE `\b` is ASCII-only here, so Arabic keywords are matched with an explicit
    // following-space/colon test instead of a word boundary.
    $isSection = false;
    if (preg_match('/^\s*(الباب|الفصل|القسم|الجزء|المبحث|الفرع)(?=\s|:)\s*(.*)$/u', $t, $m)
        || preg_match('/^\s*(Chapter|Part|Section)\s+(.*)$/iu', $t, $m)) {
        $rest = trim($m[2] ?? '');
        // A heading line is short and is not a full sentence ending in a period.
        if (mb_strlen($rest, 'UTF-8') <= 120 && !preg_match('/[.؛]\s*$/u', $rest)) $isSection = true;
    }
    if ($isSection) return ['kind'=>'section','label'=>$t,'number'=>null];

    // Article: المادة / المادة رقم / Article — with Arabic ordinal or numeric.
    if (preg_match('/^\s*(?:المادة|المادّة|الماده)\s*(?:رقم\s*)?[\(\[\{]?\s*([\p{Arabic}\p{N}]+)\s*[\)\]\}]?/u', $t, $m)
        || preg_match('/^\s*Article\s+([\p{Arabic}\p{N}]+)/iu', $t, $m)) {
        $raw = trim($m[1]);
        $num = legal_chunk_ascii_digits($raw);
        $ord = legal_chunk_ordinals();
        if (isset($ord[$raw])) $num = $ord[$raw];
        elseif (isset($ord[$num])) $num = $ord[$num];
        if (preg_match('/^\d+$/', $num)) {
            return ['kind'=>'article','label'=>$t,'number'=>$num];
        }
    }

    // Clause: a numbered/lettered sub-item, e.g. "1- …", "(أ) …", "١- …"
    if (preg_match('/^\s*[\(\[\{]?\s*([\p{Arabic}\p{N}]{1,3})\s*[\)\]\}]?\s*[-–.)]\s+\S/u', $t, $m)) {
        return ['kind'=>'clause','label'=>$t,'number'=>legal_chunk_ascii_digits(trim($m[1]))];
    }

    return ['kind'=>'text','label'=>null,'number'=>null];
}

/**
 * Split legal text into structural chunks carrying provenance.
 *
 * @return array<int,array{text:string,heading_path:?string,section_label:?string,
 *   article_label:?string,clause_label:?string,page_no:?int,chunk_kind:string}>
 */
function legal_chunk_content(string $content): array {
    $content = str_replace(["\r\n", "\r"], "\n", $content);
    if (trim($content) === '') return [];

    // PDF text extraction often separates pages with a form feed.
    $pages = explode("\f", $content);

    // Pass 1 — walk lines, carrying the current structural context, emitting blocks.
    $blocks = []; // each: text, heading_path, section_label, article_label, clause_label, page_no, chunk_kind
    $sectionStack = [];   // heading path for the current section
    $article = null;      // ['label'=>..,'number'=>..]
    $clause  = null;
    $buffer  = '';
    $pageNo  = 0;

    $flush = static function () use (&$blocks, &$buffer, &$sectionStack, &$article, &$clause, &$pageNo): void {
        $text = trim($buffer);
        $buffer = '';
        if ($text === '') return;
        $blocks[] = [
            'text' => $text,
            'heading_path' => $sectionStack ? implode(' › ', $sectionStack) : null,
            'section_label' => $sectionStack ? end($sectionStack) : null,
            'article_label' => $article['label'] ?? null,
            'clause_label' => $clause['label'] ?? null,
            'page_no' => $pageNo > 0 ? $pageNo : null,
            'chunk_kind' => $article ? 'article' : ($clause ? 'clause' : ($sectionStack ? 'section' : 'paragraph')),
        ];
    };

    foreach ($pages as $pageIndex => $pageText) {
        $pageNo = $pageIndex + 1;
        $lines = explode("\n", $pageText);
        foreach ($lines as $line) {
            $info = legal_chunk_classify_line($line);
            if ($info['kind'] === 'section') {
                $flush();
                // Replace the trailing heading at the same depth, else append.
                $sectionStack[] = $info['label'];
                $article = null; $clause = null;
                continue;
            }
            if ($info['kind'] === 'article') {
                $flush();
                $article = $info; $clause = null;
                continue;
            }
            // Clause lines (1- … / (أ) …) stay inside their article's text so the article
            // remains the citable unit; a merged article chunk is not labelled as one clause.
            $buffer .= ($buffer === '' ? '' : "\n") . $line;
        }
        $flush();
        // A page break is a structural boundary.
        $article = $article; $clause = null;
    }
    $flush();

    // Pass 2 — pack blocks into chunks, never merging across an article boundary.
    $chunks = [];
    $cur = null; // ['text','meta...']
    $push = static function (array $chunk) use (&$chunks): void {
        $chunk['text'] = trim($chunk['text']);
        if ($chunk['text'] !== '') $chunks[] = $chunk;
    };
    $sameContext = static function (array $a, array $b): bool {
        return ($a['article_label'] ?? null) === ($b['article_label'] ?? null)
            && ($a['section_label'] ?? null) === ($b['section_label'] ?? null)
            && ($a['page_no'] ?? null) === ($b['page_no'] ?? null);
    };

    foreach ($blocks as $block) {
        $meta = $block; unset($meta['text']);
        if ($cur !== null && $sameContext($cur, $meta)
            && mb_strlen($cur['text'] . "\n" . $block['text'], 'UTF-8') <= LEGAL_CHUNK_TARGET) {
            $cur['text'] .= "\n" . $block['text'];
            continue;
        }
        if ($cur !== null) $push($cur);
        $cur = $meta; $cur['text'] = $block['text'];
        // An oversized single block is split on sentence/paragraph boundaries.
        if (mb_strlen($cur['text'], 'UTF-8') > LEGAL_CHUNK_MAX) {
            $pieces = legal_chunk_split_oversize($cur['text']);
            $first = array_shift($pieces);
            $cur['text'] = $first;
            $push($cur);
            foreach ($pieces as $piece) {
                $next = $meta; $next['text'] = $piece; $push($next);
            }
            $cur = null;
        }
    }
    if ($cur !== null) $push($cur);

    // Guarantee the hard cap even after sentence splitting.
    $final = [];
    foreach ($chunks as $chunk) {
        if (mb_strlen($chunk['text'], 'UTF-8') <= LEGAL_CHUNK_MAX) { $final[] = $chunk; continue; }
        foreach (legal_chunk_split_oversize($chunk['text']) as $piece) {
            $p = $chunk; $p['text'] = $piece; $final[] = $p;
        }
    }
    return $final;
}

/**
 * Split an oversized block preferring paragraph, then sentence, then hard boundaries —
 * so a split never lands mid-sentence unless a single sentence exceeds the cap.
 * @return array<int,string>
 */
function legal_chunk_split_oversize(string $text): array {
    $out = [];
    $remaining = trim($text);
    while (mb_strlen($remaining, 'UTF-8') > LEGAL_CHUNK_MAX) {
        $window = mb_substr($remaining, 0, LEGAL_CHUNK_MAX, 'UTF-8');
        $cut = 0;
        foreach (["\n\n", "\n", '۔ ', '؛ ', '. ', '؟ ', '! '] as $sep) {
            $pos = mb_strrpos($window, $sep, 0, 'UTF-8');
            if ($pos !== false && $pos > (int)(LEGAL_CHUNK_MAX * 0.5)) { $cut = $pos + mb_strlen($sep, 'UTF-8'); break; }
        }
        if ($cut <= 0) $cut = LEGAL_CHUNK_MAX;
        $out[] = trim(mb_substr($remaining, 0, $cut, 'UTF-8'));
        $remaining = trim(mb_substr($remaining, $cut, null, 'UTF-8'));
    }
    if ($remaining !== '') $out[] = $remaining;
    return array_values(array_filter($out, static fn($s) => trim($s) !== ''));
}

/** Human-readable citation label for a chunk, e.g. "الفصل الأول › المادة 3". */
function legal_chunk_citation_label(array $chunk): string {
    $parts = [];
    if (!empty($chunk['section_label'])) $parts[] = $chunk['section_label'];
    if (!empty($chunk['article_label'])) $parts[] = $chunk['article_label'];
    if (!empty($chunk['clause_label'])) $parts[] = $chunk['clause_label'];
    if (!$parts && !empty($chunk['heading_path'])) $parts[] = $chunk['heading_path'];
    return $parts ? implode(' › ', array_map(static fn($p) => mb_substr(trim((string)$p), 0, 120, 'UTF-8'), $parts)) : '';
}

/**
 * SELECT-list fragment for the chunk provenance columns, or '' when upgrade_v48 has not
 * been applied yet — so retrieval keeps working on an un-migrated database and simply
 * returns no article/page detail (it never invents one).
 */
function legal_chunk_provenance_select(PDO $pdo): string {
    static $cols = null;
    if ($cols === null) {
        try {
            $n = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='knowledge_chunks' AND COLUMN_NAME='article_label'")->fetchColumn();
            $cols = $n > 0;
        } catch (PDOException $e) { $cols = false; }
    }
    return $cols ? ',k.heading_path,k.section_label,k.article_label,k.clause_label,k.page_no' : '';
}

/**
 * Attach a display citation label to a retrieved chunk row (from knowledge_retrieve or
 * knowledge_semantic_search). Returns '' when the chunk carries no structural provenance.
 */
function legal_chunk_row_citation(array $row): string {
    return legal_chunk_citation_label([
        'section_label' => $row['section_label'] ?? null,
        'article_label' => $row['article_label'] ?? null,
        'clause_label' => $row['clause_label'] ?? null,
        'heading_path' => $row['heading_path'] ?? null,
    ]);
}
