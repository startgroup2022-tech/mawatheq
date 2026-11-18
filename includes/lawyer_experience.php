<?php
/**
 * سنوات الخبرة — تطبيع القيمة وعصابات التصفية غير المتقاطعة.
 *
 * A lawyer may type experience as a display value ("20+", "١٢", "12.5 سنة"). Storing
 * that verbatim would make the numeric filter compare against a string, so every
 * write path normalises it to a whole non-negative number first. The original display
 * intent ("20+") is expressed honestly by the value 20 — the "+" carries no numeric
 * meaning a filter could use.
 *
 * The bands are defined once here and consumed by both the website and the API, so the
 * two surfaces cannot disagree about what "5–10 years" means. Their boundaries do not
 * overlap and leave no gap:
 *
 *     under 5     0–4
 *     5–10        5–10
 *     11–15      11–15
 *     16–20      16–20
 *     more than 20  21+
 */

/** The maximum a normalized experience value may be, so a typo cannot poison the filter. */
const LAWYER_EXPERIENCE_MAX = 80;

/**
 * Normalise a posted experience value to a whole non-negative number.
 *
 * Accepts Arabic-Indic digits and a trailing "+", rounds a decimal, and clamps to
 * [0, LAWYER_EXPERIENCE_MAX]. Anything unparseable becomes 0 rather than throwing, so
 * a malformed field degrades to "no experience recorded" instead of a 500.
 */
function lawyer_experience_normalize($raw): int {
    if (is_array($raw)) return 0;
    $s = trim((string)$raw);
    if ($s === '') return 0;

    // Arabic-Indic (٠-٩) and extended (۰-۹) digits → ASCII.
    $s = strtr($s, [
        '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
        '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
    ]);

    if (!preg_match('/-?\d+(?:\.\d+)?/', $s, $m)) return 0;
    $value = (float)$m[0];
    if ($value <= 0) return 0;
    $value = (int)round($value);
    return min($value, LAWYER_EXPERIENCE_MAX);
}

/**
 * The filter bands, in display order. `min`/`max` are inclusive; a null bound is open.
 * The first entry is the "no experience filter" option and is not a real band.
 *
 * @return array<int, array{key:string, min:?int, max:?int, label_ar:string, label_en:string}>
 */
function lawyer_experience_bands(): array {
    return [
        ['key' => 'under5',  'min' => 0,  'max' => 4,   'label_ar' => 'أقل من 5 سنوات',   'label_en' => 'Under 5 years'],
        ['key' => '5to10',   'min' => 5,  'max' => 10,  'label_ar' => '5 – 10 سنوات',     'label_en' => '5–10 years'],
        ['key' => '11to15',  'min' => 11, 'max' => 15,  'label_ar' => '11 – 15 سنة',      'label_en' => '11–15 years'],
        ['key' => '16to20',  'min' => 16, 'max' => 20,  'label_ar' => '16 – 20 سنة',      'label_en' => '16–20 years'],
        ['key' => 'over20',  'min' => 21, 'max' => null,'label_ar' => 'أكثر من 20 سنة',   'label_en' => 'More than 20 years'],
    ];
}

/** The band with this key, or null when the key is unknown (i.e. "all"). */
function lawyer_experience_band(string $key): ?array {
    foreach (lawyer_experience_bands() as $b) {
        if ($b['key'] === $key) return $b;
    }
    return null;
}

/** The band a given number of years falls into, or null when it falls in none. */
function lawyer_experience_band_for(int $years): ?string {
    foreach (lawyer_experience_bands() as $b) {
        if ($years >= $b['min'] && ($b['max'] === null || $years <= $b['max'])) return $b['key'];
    }
    return null;
}

/** The localised label of a band key, for a UI chip. */
function lawyer_experience_band_label(string $key): string {
    $b = lawyer_experience_band($key);
    if (!$b) return is_rtl() ? 'كل مستويات الخبرة' : 'All experience levels';
    return is_rtl() ? $b['label_ar'] : $b['label_en'];
}

/**
 * Normalise a posted price to a non-negative number rounded to the site currency's
 * precision, or null when the field was left empty ("no price filter" / "on request").
 *
 * A negative or malformed value becomes null, never a negative stored rate, so the
 * "prevent negative prices" rule holds at the write boundary rather than relying on the
 * browser's `min="0"`.
 */
function lawyer_price_normalize($raw): ?float {
    if ($raw === null || is_array($raw)) return null;
    $s = trim((string)$raw);
    if ($s === '') return null;
    $s = strtr($s, [
        '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
    ]);
    if (!preg_match('/-?\d+(?:\.\d+)?/', $s, $m)) return null;
    $value = (float)$m[0];
    if ($value <= 0) return 0.0;
    $decimals = function_exists('currency_decimals') ? currency_decimals() : 2;
    return round($value, $decimals);
}
