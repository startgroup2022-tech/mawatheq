<?php
/**
 * الدول والمدن — قائمة موحّدة لدولة المحامي ومدينته.
 *
 * The platform stores a lawyer's country as a localised name (`lawyers.jurisdiction`,
 * owned by jurisdiction.php) and, since upgrade_v68.sql, also as an ISO code
 * (`lawyers.country_code`) plus a `city`. The city selector is *dependent*: it only
 * ever offers the cities of the chosen country, and a city that does not belong to
 * that country is refused on write rather than stored.
 *
 * Why a fixed list and not a table: the country list is already a code constant
 * (includes/country.php), and a city list small enough to be authoritative is
 * better stated once in code than shipped as a migration that an operator would
 * then have to extend through SQL. Every helper here degrades to an empty list for
 * a country we do not know, so an unknown country simply offers no cities instead
 * of offering a wrong one.
 *
 * The canonical key is the ISO alpha-2 code from country.php; the localised name
 * used by `jurisdiction` is derived through jurisdiction_country_map() so the two
 * columns can never disagree about which country a row describes.
 */
require_once __DIR__ . '/country.php';
require_once __DIR__ . '/jurisdiction.php';

/**
 * Jurisdiction display name (as stored in `lawyers.jurisdiction`) → ISO code.
 *
 * This is the one mapping between the two representations. jurisdiction.php owns
 * the names, country.php owns the codes; a name absent from this map has no code,
 * and its country_code stays NULL rather than being guessed.
 *
 * @return array<string,string>
 */
function jurisdiction_country_map(): array {
    return [
        'السعودية' => 'SA',
        'البحرين'  => 'BH',
        'الإمارات' => 'AE',
        'الكويت'   => 'KW',
        'قطر'      => 'QA',
        'عُمان'    => 'OM',
        'مصر'      => 'EG',
        'الأردن'   => 'JO',
    ];
}

/** ISO code → jurisdiction display name, or null when the code has no name here. */
function jurisdiction_for_country(string $code): ?string {
    $code = strtoupper(trim($code));
    $flip = array_flip(jurisdiction_country_map());
    return $flip[$code] ?? null;
}

/**
 * Cities per ISO country code, in display order.
 *
 * Saudi Arabia is the platform default (COUNTRY_DEFAULT), so its list is the most
 * complete; the GCC and the two wider-region countries the platform supports follow.
 * The lists are deliberately finite and curated — a free-text city field would let
 * two spellings of one city both appear in the filter, which is exactly the defect
 * the dependent selector removes.
 *
 * @return array<string, array<int, string>>
 */
function city_options(): array {
    return [
        'SA' => [
            'الرياض', 'جدة', 'مكة المكرمة', 'المدينة المنورة', 'الدمام', 'الخبر', 'الظهران',
            'الطائف', 'بريدة', 'تبوك', 'أبها', 'خميس مشيط', 'حائل', 'نجران', 'جيزان',
            'الأحساء', 'الجبيل', 'ينبع', 'عرعر', 'سكاكا', 'الباحة', 'القريات', 'بيشة', 'الخرج',
        ],
        'BH' => ['المنامة', 'المحرق', 'الرفاع', 'مدينة عيسى', 'مدينة حمد', 'سترة', 'البديع', 'جدحفص'],
        'AE' => ['أبوظبي', 'دبي', 'الشارقة', 'عجمان', 'رأس الخيمة', 'الفجيرة', 'أم القيوين', 'العين', 'خورفكان'],
        'KW' => ['مدينة الكويت', 'حولي', 'السالمية', 'الفروانية', 'الجهراء', 'الأحمدي', 'الفنطاس', 'الفحيحيل'],
        'QA' => ['الدوحة', 'الريان', 'الوكرة', 'الخور', 'أم صلال', 'الشحانية', 'لوسيل', 'مسيعيد'],
        'OM' => ['مسقط', 'صلالة', 'صحار', 'نزوى', 'صور', 'البريمي', 'إبراء', 'الرستاق'],
        'EG' => ['القاهرة', 'الجيزة', 'الإسكندرية', 'المنصورة', 'طنطا', 'أسيوط', 'الزقازيق', 'بورسعيد', 'السويس', 'الأقصر', 'أسوان', 'دمياط'],
        'JO' => ['عمّان', 'الزرقاء', 'إربد', 'العقبة', 'السلط', 'مادبا', 'المفرق', 'الكرك'],
    ];
}

/** The cities of one country, or [] for a country we do not know. */
function cities_for_country(string $countryCode): array {
    $code = strtoupper(trim($countryCode));
    return city_options()[$code] ?? [];
}

/** Whether a city genuinely belongs to a country — the write-side validation. */
function city_is_valid(string $countryCode, string $city): bool {
    $city = trim($city);
    if ($city === '') return false;
    return in_array($city, cities_for_country($countryCode), true);
}

/**
 * The country code a lawyer row describes, derived from either column.
 *
 * A row created before upgrade_v68.sql has only `jurisdiction`; one written after
 * has both. Reading either keeps the filters working across the migration boundary.
 */
function lawyer_country_code(array $lawyer): ?string {
    $code = trim((string)($lawyer['country_code'] ?? ''));
    if ($code !== '' && country_is_valid($code)) return strtoupper($code);
    $name = trim((string)($lawyer['jurisdiction'] ?? ''));
    return jurisdiction_country_map()[$name] ?? null;
}

/**
 * Resolve the country a lawyer write should store, from the posted country code
 * (preferred) or the legacy jurisdiction name. Returns null when neither is a
 * country we support, so the caller can refuse the write instead of guessing.
 *
 * @return array{code:?string, jurisdiction:?string}
 */
function lawyer_country_from_input($countryCode, $jurisdiction): array {
    if (is_string($countryCode) && $countryCode !== '' && country_is_valid($countryCode)) {
        $code = strtoupper(trim($countryCode));
        return ['code' => $code, 'jurisdiction' => jurisdiction_for_country($code) ?? $jurisdiction];
    }
    if (is_string($jurisdiction)) {
        $name = trim($jurisdiction);
        if (isset(jurisdiction_country_map()[$name])) {
            return ['code' => jurisdiction_country_map()[$name], 'jurisdiction' => $name];
        }
    }
    return ['code' => null, 'jurisdiction' => null];
}

/**
 * Country picker whose value is the ISO code (unlike render_jurisdiction_select(),
 * whose value is the localised name). Used by the lawyer forms so the stored value
 * and the city list share one key.
 */
function render_lawyer_country_select(string $name, ?string $selectedCode = null, bool $required = false, string $id = ''): void {
    $selected = $selectedCode !== null && country_is_valid($selectedCode)
        ? strtoupper($selectedCode)
        : COUNTRY_DEFAULT;
    $id = $id !== '' ? $id : $name;
    echo '<select name="' . ui_e($name) . '" id="' . ui_e($id) . '" class="pro-select" data-country-select' . ($required ? ' required' : '') . '>';
    foreach (country_options() as $code => $labels) {
        $label = is_rtl() ? $labels['ar'] : $labels['en'];
        $sel = $code === $selected ? ' selected' : '';
        echo '<option value="' . ui_e($code) . '"' . $sel . '>' . ui_e($label) . '</option>';
    }
    echo '</select>';
}

/**
 * The city `<select>` for a country, with the dependent behaviour wired through
 * `data-country`/`data-selected` so one shared script (assets/js/lawyer-form.js)
 * drives every form that uses it — no inline handler, which keeps the page CSP-safe.
 *
 * `$selected` is preselected only when it genuinely belongs to `$countryCode`; a
 * stale city from a previous country is dropped rather than shown.
 */
function render_city_select(string $name, ?string $countryCode, ?string $selected = null, bool $required = false, string $id = ''): void {
    $code = $countryCode !== null && country_is_valid($countryCode) ? strtoupper($countryCode) : COUNTRY_DEFAULT;
    $selected = (string)$selected;
    if ($selected !== '' && !city_is_valid($code, $selected)) $selected = '';
    $id = $id !== '' ? $id : $name;
    echo '<select name="' . ui_e($name) . '" id="' . ui_e($id) . '" class="pro-select" data-city-select data-country="' . ui_e($code) . '"'
        . ' data-selected="' . ui_e($selected) . '"' . ($required ? ' required' : '') . '>';
    echo '<option value="">' . (is_rtl() ? 'اختر المدينة...' : 'Select city...') . '</option>';
    foreach (cities_for_country($code) as $city) {
        $sel = $city === $selected ? ' selected' : '';
        echo '<option value="' . ui_e($city) . '"' . $sel . '>' . ui_e($city) . '</option>';
    }
    echo '</select>';
}

/** City → country label, for a compact display such as "الرياض، السعودية". */
function lawyer_location_label(?string $countryCode, ?string $city): string {
    $parts = [];
    if (is_string($city) && trim($city) !== '') $parts[] = trim($city);
    if (is_string($countryCode) && $countryCode !== '' && country_is_valid($countryCode)) {
        $parts[] = country_name($countryCode);
    }
    return implode(is_rtl() ? '، ' : ', ', $parts);
}
