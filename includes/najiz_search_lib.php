<?php
/**
 * Saudi Najiz classification — shared helpers.
 *
 * Arabic search needs normalisation that the database collation does not perform.
 * utf8mb4_unicode_ci treats أ إ آ ا as distinct letters and does not fold ى to ي or
 * ة to ه, so a search for 'نفقه' would not match 'النفقة' and 'حضانه' would not match
 * 'الحضانة'. These helpers fold those forms so search works the way an Arabic speaker
 * types, and the same fold is applied when the importer writes `search_norm`.
 *
 * The fold is deterministic and reversible in the sense that matters here: it only
 * widens matches, it never invents a relationship between unrelated words.
 */

/** Strip Arabic diacritics (tashkeel), tatweel, and unify letter variants. */
function najiz_normalize_ar(string $s): string {
    $s = trim($s);
    if ($s === '') return '';
    // Remove tatweel (kashida) — a decorative stretching character.
    $s = str_replace("\u{0640}", '', $s);
    // Remove the Quranic/poetic marks and the standard tashkeel block.
    $s = preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]/u', '', $s);
    // Alef variants and the wasla form -> bare alef.
    $s = preg_replace('/[\x{0622}\x{0623}\x{0625}\x{0671}]/u', "\u{0627}", $s);
    // Alef maqsura -> ya.
    $s = preg_replace('/[\x{0649}\x{06CC}]/u', "\u{064A}", $s);
    // Ta marbuta -> ha (how the word is normally typed in a search box).
    $s = str_replace("\u{0629}", "\u{0647}", $s);
    // Hamza forms -> plain hamza carrier letters.
    $s = preg_replace('/[\x{0624}]/u', "\u{0648}", $s);
    $s = preg_replace('/[\x{0626}]/u', "\u{064A}", $s);
    // Collapse whitespace runs so 'فسخ   عقد' matches 'فسخ عقد'.
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim($s);
}

/** Normalise for the optional English side: lowercase, strip punctuation. */
function najiz_normalize_en(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^\p{L}\p{N} ]+/u', ' ', $s);
    return trim(preg_replace('/\s+/u', ' ', $s));
}

/** Stable idempotency key for one source record.
 *
 *  $path  the full hierarchical path, e.g. 'الدعاوى التجارية/الملكية الفكرية/العلامات التجارية'
 *  $occ   ordinal of this name among identical names under the same parent (1-based)
 *
 *  The ordinal is what keeps genuinely repeated source records distinct — the source
 *  lists 'ولي' three times under الولاية and 'النفقة' twice under النفقة, and all five
 *  are real records. Hashing name+path+ordinal means a re-import maps every record to
 *  the row it created the first time, so a second run inserts nothing.
 */
function najiz_source_key(string $path, int $occ = 1): string {
    return sha1('najiz:v1:' . $path . '#' . $occ);
}

/** Split a search query into normalised tokens. */
function najiz_query_tokens(string $q): array {
    $n = najiz_normalize_ar($q);
    if ($n === '') return [];
    $parts = preg_split('/\s+/u', $n);
    // Drop very short tokens only when there are longer ones to match on, so a
    // single-letter query still returns something rather than nothing.
    $long = array_values(array_filter($parts, fn($p) => mb_strlen($p) >= 2));
    return $long ?: $parts;
}
