<?php
/**
 * Consultation pricing — the single authoritative breakdown (Execution 66 phase B).
 *
 * Before this file the charged amount was computed in three places (booking.php, the REST
 * API, and consultation_payment_start) and described differently in a fourth (the "per hour"
 * label on the booking form). The number that reached the gateway was always the stored
 * `consultations.price`, so the charge was consistent; only the *description* disagreed with
 * it, and there was no one function that said what the amount was made of.
 *
 * This module is that function. Every surface — the website, the API, the mobile app, the
 * N-Genius order and any future invoice — must read the payable amount from here, so a change
 * to the rule is a change in one place.
 *
 * ## The rule (deliberately unchanged)
 *
 * A consultation costs the lawyer's **hourly rate, charged as a flat amount for the booked
 * consultation**, and `consultations.price` stores exactly that payable amount. This is what
 * the code already did and what customers were already charged; the redesign makes the label
 * tell the truth ("سعر الاستشارة" — consultation price) instead of implying an hourly meter.
 *
 * ## Why nothing here changes a charge
 *
 * If the rule were instead "hourly rate × (duration / 60)", a 30-minute consultation would
 * become half its current price. That is a financial-policy change and needs the owner's
 * decision, so it is **not** implemented. `consultation_pricing_breakdown()` computes the
 * amount with an explicit `mode`, and the default mode is the existing flat rule; a future
 * approved change flips the mode in one place.
 *
 * Tax is represented but zero: no tax rate exists in this deployment and none is invented.
 * If the owner enables VAT, it is set once (site setting `consultation_tax_percent`) and the
 * breakdown applies it consistently everywhere.
 */
require_once __DIR__ . '/currency.php';

/** The pricing rule in force. 'flat' = the lawyer's rate charged once (the shipped rule). */
const CONSULTATION_PRICING_MODE_FLAT = 'flat';

/** Default consultation length when neither the lawyer nor the caller states one. */
const CONSULTATION_DEFAULT_MINUTES = 30;

/** Largest consultation length the UI will offer, in minutes (a sane ceiling, not a policy). */
const CONSULTATION_MAX_MINUTES = 240;

/**
 * The site's consultation tax rate as a percentage (0 when unset). Cached per request.
 *
 * Kept as a setting rather than a constant so an operator can turn VAT on in one place; the
 * value is 0 on every deployment that has never configured it, which is the honest default
 * (the platform has never charged tax).
 */
function consultation_tax_percent(PDO $pdo): float {
    static $cached = null;
    if ($cached !== null) return $cached;
    $cached = 0.0;
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'consultation_tax_percent' LIMIT 1");
        $stmt->execute();
        $v = $stmt->fetchColumn();
        if ($v !== false && $v !== null && is_numeric($v)) {
            $cached = max(0.0, min(100.0, (float)$v));
        }
    } catch (Throwable $e) {
        $cached = 0.0;
    }
    return $cached;
}

/** Normalise a requested duration to a positive, bounded whole number of minutes. */
function consultation_normalize_duration($minutes): int {
    if (is_array($minutes) || is_object($minutes)) return CONSULTATION_DEFAULT_MINUTES;
    if (!is_numeric($minutes)) return CONSULTATION_DEFAULT_MINUTES;
    $m = (int)round((float)$minutes);
    if ($m <= 0) return CONSULTATION_DEFAULT_MINUTES;
    return min($m, CONSULTATION_MAX_MINUTES);
}

/**
 * The payable amount for a consultation, from the lawyer's rate and the booked length.
 *
 * @param float|null $rate       the lawyer's hourly rate (null/0 = no price set)
 * @param int|null   $minutes    the booked length; defaults to 30
 * @param string     $mode       CONSULTATION_PRICING_MODE_FLAT (the shipped rule)
 */
function consultation_price_amount($rate, $minutes = null, string $mode = CONSULTATION_PRICING_MODE_FLAT): float {
    if ($rate === null || $rate === '' || is_array($rate)) return 0.0;
    if (!is_numeric($rate)) return 0.0;
    $rate = (float)$rate;
    if ($rate <= 0) return 0.0;

    // 'per_minute' is the alternative the owner may choose; it is intentionally not the
    // default, because switching to it changes what a 30-minute consultation costs.
    if ($mode === 'per_minute') {
        $m = consultation_normalize_duration($minutes);
        return round($rate * ($m / 60), currency_decimals());
    }
    return round($rate, currency_decimals());
}

/**
 * The full, itemised breakdown for one consultation. This is the object every surface renders
 * and the one the gateway amount is read from.
 *
 * @return array{
 *   mode:string, currency:string, rate:?float, duration_minutes:int,
 *   base:float, tax_percent:float, tax:float, fees:float, total:float,
 *   payable:float, is_paid:bool, has_price:bool
 * }
 */
function consultation_pricing_breakdown(PDO $pdo, $rate, $minutes = null, array $options = []): array {
    $mode = (string)($options['mode'] ?? CONSULTATION_PRICING_MODE_FLAT);
    $duration = consultation_normalize_duration($minutes);
    $base = consultation_price_amount($rate, $duration, $mode);
    $taxPercent = consultation_tax_percent($pdo);
    $decimals = currency_decimals();
    $tax = $base > 0 && $taxPercent > 0 ? round($base * $taxPercent / 100, $decimals) : 0.0;
    // Fees is a fixed add-on hook; it is 0 and no fee is invented. Kept so the breakdown the
    // UI renders is complete, and so a future platform fee has one place to live.
    $fees = isset($options['fees']) && is_numeric($options['fees']) ? max(0.0, (float)$options['fees']) : 0.0;
    $total = round($base + $tax + $fees, $decimals);

    return [
        'mode'             => $mode,
        'currency'         => currency_code(),
        'rate'             => ($rate === null || $rate === '') ? null : (float)$rate,
        'duration_minutes' => $duration,
        'base'             => $base,
        'tax_percent'      => $taxPercent,
        'tax'              => $tax,
        'fees'             => $fees,
        'total'            => $total,
        'payable'          => $total,
        'is_paid'          => $total > 0,
        'has_price'        => ($rate !== null && $rate !== '' && is_numeric($rate) && (float)$rate > 0),
    ];
}

/**
 * The breakdown for a lawyer row, using the lawyer's own slot length when one is published.
 *
 * The website and the app both start from a `lawyers` row, so this keeps them from each
 * deciding what "duration" means. A lawyer with no published `slot_minutes` gets the 30-minute
 * default — which is what the consultation actually is today.
 */
function consultation_pricing_for_lawyer(PDO $pdo, array $lawyer, array $options = []): array {
    $minutes = $options['duration_minutes'] ?? null;
    if ($minutes === null && isset($lawyer['slot_minutes']) && (int)$lawyer['slot_minutes'] > 0) {
        $minutes = (int)$lawyer['slot_minutes'];
    }
    return consultation_pricing_breakdown($pdo, $lawyer['hourly_rate'] ?? null, $minutes, $options);
}

/**
 * A short human label for the price on a card, e.g. "450.00 ر.س".
 * Returns null when the lawyer has no price, so the caller can render "عند الطلب".
 */
function consultation_price_label(PDO $pdo, array $lawyer): ?string {
    $b = consultation_pricing_for_lawyer($pdo, $lawyer);
    if (!$b['has_price']) return null;
    return money_text($b['total']);
}
