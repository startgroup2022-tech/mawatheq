<?php
/**
 * Country for the whole platform.
 *
 * Saudi Arabia is the platform default. Every form that asks for a country reads
 * it from here, so a default is stated once rather than repeated as a literal in
 * each page — the affiliate country list used to be duplicated verbatim in three
 * files, which is how 'BH' survived as the default in all of them.
 *
 * The currency default (CURRENCY_DEFAULT, includes/currency.php) and the legal
 * jurisdiction default (jurisdiction.php) are separate mechanisms; this file owns
 * the ISO country code only.
 *
 * Storage note: `affiliates.country` and `affiliate_applications.country` are
 * CHAR(2) ISO codes. `lawyers.jurisdiction` is a localised country *name*, which
 * jurisdiction.php owns.
 */
require_once __DIR__ . '/ui.php';

/** ISO 3166-1 alpha-2 code used when nothing else is chosen. */
const COUNTRY_DEFAULT = 'SA';

/**
 * Countries the platform offers, in display order: Saudi Arabia first because it
 * is the default, then the rest of the GCC, then the wider region. Order is
 * presentation only — selection is preserved wherever a country is supported.
 */
function country_options(): array {
    return [
        'SA' => ['ar' => 'السعودية', 'en' => 'Saudi Arabia'],
        'BH' => ['ar' => 'البحرين',  'en' => 'Bahrain'],
        'AE' => ['ar' => 'الإمارات', 'en' => 'UAE'],
        'KW' => ['ar' => 'الكويت',   'en' => 'Kuwait'],
        'QA' => ['ar' => 'قطر',      'en' => 'Qatar'],
        'OM' => ['ar' => 'عمان',     'en' => 'Oman'],
    ];
}

function country_is_valid(string $code): bool {
    return isset(country_options()[strtoupper($code)]);
}

/**
 * Normalise any input to a supported code, falling back to the default.
 *
 * Accepts mixed rather than ?string because the callers pass straight from request
 * data: a country field submitted as an array would otherwise raise a TypeError
 * instead of simply falling back to the default.
 */
function country_normalize($code): string {
    if (!is_string($code)) return COUNTRY_DEFAULT;
    $code = strtoupper(trim($code));
    return country_is_valid($code) ? $code : COUNTRY_DEFAULT;
}

/** Localised country name, or the code itself when it is not one we offer. */
function country_name(string $code): string {
    $options = country_options();
    $code = strtoupper(trim($code));
    if (!isset($options[$code])) return $code;
    return is_rtl() ? $options[$code]['ar'] : $options[$code]['en'];
}

/**
 * Country picker. `$selected` defaults to the platform default rather than to
 * whatever the first option happens to be, so an empty form opens on Saudi Arabia.
 */
function render_country_select(string $name, ?string $selected = null, bool $required = false): void {
    $selected = country_normalize($selected);
    echo '<select name="' . ui_e($name) . '"' . ($required ? ' required' : '') . '>';
    foreach (country_options() as $code => $labels) {
        $label = is_rtl() ? $labels['ar'] : $labels['en'];
        $isSel = $code === $selected ? ' selected' : '';
        echo '<option value="' . ui_e($code) . '"' . $isSel . '>' . ui_e($label) . '</option>';
    }
    echo '</select>';
}
