<?php
/**
 * Currency for the whole platform.
 *
 * The site-wide currency is an admin setting (site_settings.site_currency) and
 * defaults to the Saudi riyal. Every screen reads it through these helpers so a
 * change in the console takes effect everywhere without touching page code.
 *
 * Money is stored with the currency's own precision: SAR uses 2 decimals, while
 * the legacy 3-decimal currencies (BHD/KWD/OMR) keep their third decimal. Callers
 * must format through money()/currency_decimals() rather than hard-coding 3.
 */
require_once __DIR__ . '/../lang/strings.php';

const CURRENCY_DEFAULT = 'SAR';

/** Currencies offered in the console. Order is the order shown in the picker. */
function currency_options(): array {
    return [
        'SAR' => ['ar' => 'ريال سعودي',  'en' => 'Saudi riyal',    'symbol_ar' => 'ر.س',  'symbol_en' => 'SAR', 'decimals' => 2],
        'AED' => ['ar' => 'درهم إماراتي', 'en' => 'UAE dirham',     'symbol_ar' => 'د.إ',  'symbol_en' => 'AED', 'decimals' => 2],
        'QAR' => ['ar' => 'ريال قطري',   'en' => 'Qatari riyal',   'symbol_ar' => 'ر.ق',  'symbol_en' => 'QAR', 'decimals' => 2],
        'KWD' => ['ar' => 'دينار كويتي', 'en' => 'Kuwaiti dinar',  'symbol_ar' => 'د.ك',  'symbol_en' => 'KWD', 'decimals' => 3],
        'BHD' => ['ar' => 'دينار بحريني','en' => 'Bahraini dinar', 'symbol_ar' => 'د.ب',  'symbol_en' => 'BHD', 'decimals' => 3],
        'OMR' => ['ar' => 'ريال عماني',  'en' => 'Omani rial',     'symbol_ar' => 'ر.ع',  'symbol_en' => 'OMR', 'decimals' => 3],
        'EGP' => ['ar' => 'جنيه مصري',   'en' => 'Egyptian pound', 'symbol_ar' => 'ج.م',  'symbol_en' => 'EGP', 'decimals' => 2],
        'JOD' => ['ar' => 'دينار أردني', 'en' => 'Jordanian dinar','symbol_ar' => 'د.أ',  'symbol_en' => 'JOD', 'decimals' => 3],
        'USD' => ['ar' => 'دولار أمريكي','en' => 'US dollar',      'symbol_ar' => '$',    'symbol_en' => 'USD', 'decimals' => 2],
        'EUR' => ['ar' => 'يورو',        'en' => 'Euro',           'symbol_ar' => '€',    'symbol_en' => 'EUR', 'decimals' => 2],
    ];
}

function currency_is_valid(string $code): bool {
    return isset(currency_options()[strtoupper($code)]);
}

/** Normalise any input to a supported code, falling back to the default. */
function currency_normalize(?string $code): string {
    $code = strtoupper(trim((string)$code));
    return currency_is_valid($code) ? $code : CURRENCY_DEFAULT;
}

/**
 * The active currency code. Reads site_settings once per request and caches it;
 * falls back to CURRENCY_DEFAULT when the row is missing (fresh install) or the
 * database is not reachable (e.g. a static include during setup).
 */
function currency_code(?PDO $pdo = null): string {
    if (isset($GLOBALS['__mawatheq_currency_cache'])) {
        return $GLOBALS['__mawatheq_currency_cache'];
    }

    if ($pdo === null) {
        global $pdo;
    }
    if (!$pdo instanceof PDO) return CURRENCY_DEFAULT;

    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'site_currency' LIMIT 1");
        $stmt->execute();
        $value = $stmt->fetchColumn();
    } catch (PDOException $e) {
        return CURRENCY_DEFAULT;
    }

    return $GLOBALS['__mawatheq_currency_cache'] = currency_normalize($value === false ? null : (string)$value);
}

/**
 * Drop the cached code after an in-process settings write, so a page that saves
 * the currency and then renders shows the new one.
 */
function currency_forget_cache(): void {
    $GLOBALS['__mawatheq_currency_cache'] = null;
}

function currency_decimals(?string $code = null): int {
    $code = currency_normalize($code ?? currency_code());
    return currency_options()[$code]['decimals'];
}

/** Localised symbol, e.g. "ر.س" in Arabic and "SAR" in English. */
function currency_symbol(?string $code = null): string {
    $code = currency_normalize($code ?? currency_code());
    $meta = currency_options()[$code];
    return is_rtl() ? $meta['symbol_ar'] : $meta['symbol_en'];
}

/** Localised full name, e.g. "ريال سعودي" / "Saudi riyal". */
function currency_name(?string $code = null): string {
    $code = currency_normalize($code ?? currency_code());
    $meta = currency_options()[$code];
    return is_rtl() ? $meta['ar'] : $meta['en'];
}

/** Formatted number only, no symbol — for inputs, exports and chart axes. */
function currency_number($amount, ?string $code = null, ?int $decimals = null): string {
    $decimals = $decimals ?? currency_decimals($code);
    return number_format((float)$amount, $decimals, '.', ',');
}

/** Raw decimal string for machine use: form values, JS arguments, CSV cells. */
function currency_raw($amount, ?string $code = null, ?int $decimals = null): string {
    $decimals = $decimals ?? currency_decimals($code);
    return number_format((float)$amount, $decimals, '.', '');
}

/** HTML step attribute matching the active currency's precision (0.01 vs 0.001). */
function currency_step(?string $code = null): string {
    return currency_decimals($code) === 3 ? '0.001' : '0.01';
}

/**
 * Display amount plus symbol, e.g. "1,250.00 ر.س". Drop-in replacement for the
 * old ui_money(): same shape, but the currency and precision come from settings.
 */
function money($amount, ?string $code = null, ?int $decimals = null): string {
    $code = currency_normalize($code ?? currency_code());
    return '<span class="num-ltr">' . htmlspecialchars(currency_number($amount, $code, $decimals), ENT_QUOTES, 'UTF-8')
        . ' ' . htmlspecialchars(currency_symbol($code), ENT_QUOTES, 'UTF-8') . '</span>';
}

/** Plain-text amount plus symbol, for notifications, exports and CSV. */
function money_text($amount, ?string $code = null, ?int $decimals = null): string {
    $code = currency_normalize($code ?? currency_code());
    return currency_number($amount, $code, $decimals) . ' ' . currency_symbol($code);
}
