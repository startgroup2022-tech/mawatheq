<?php
/**
 * Affiliate service eligibility and the compliance gate (Execution 31).
 *
 * This file exists to answer one question, and to be the only place that answers it:
 * may commission be paid on this revenue, and if not, what may be recorded instead?
 *
 * THE RULE
 *   The platform serves legal services. A lawyer's professional fee — and a
 *   consultation fee, which is the same thing sold by the hour — is regulated in
 *   Bahrain. Sharing it with a third party who referred the client is not something
 *   this software may decide is fine. So a service marked `is_regulated_fee` is
 *   NEVER commissionable here, regardless of its `commission_permitted` flag, its
 *   `attribution_mode`, or anything an administrator types into a form.
 *
 *   That check is deliberately first and unconditional. Everything else in this
 *   file is about *non-regulated* platform revenue, where the decision is a
 *   commercial one that compliance is allowed to make and record.
 *
 * THREE OUTCOMES, NOT TWO
 *   A referral is still worth recording even when it cannot pay. The brief asks for
 *   attribution, analytics and tracking, and those are useful precisely where
 *   commission is not permitted. So eligibility returns a mode:
 *
 *     commission        — may generate commission
 *     attribution_only  — recorded and attributed; no money
 *     analytics_only    — counted in aggregate; no attribution, no money
 *     disabled          — not tracked at all
 *     prohibited        — must not be tracked; a compliance prohibition
 *
 *   `disabled` and `prohibited` differ in intent and in reporting: disabled is "not
 *   switched on yet", prohibited is "do not do this". Both refuse money; only
 *   prohibited is surfaced as a compliance warning.
 */
require_once __DIR__ . '/affiliate.php';

/**
 * The full service catalog, keyed by service_key, cached per request.
 *
 * On a database that has not run upgrade_v57.sql the table is absent; the accessor
 * returns an empty array and every caller treats an unknown service as ineligible.
 * Failing closed is the only acceptable behaviour for a compliance gate.
 */
function affiliate_service_catalog(PDO $pdo): array {
    if (isset($GLOBALS['__affiliate_services'])) {
        return $GLOBALS['__affiliate_services'];
    }
    $rows = [];
    try {
        $stmt = $pdo->query('SELECT * FROM affiliate_service_catalog ORDER BY sort_order, id');
        foreach ($stmt->fetchAll() as $row) {
            $rows[(string)$row['service_key']] = $row;
        }
    } catch (Throwable $e) {
        error_log('affiliate_service_catalog: ' . $e->getMessage());
    }
    return $GLOBALS['__affiliate_services'] = $rows;
}

/** Drop the catalog cache (admin edits a service, or a test changes the gate). */
function affiliate_service_catalog_reload(): void {
    unset($GLOBALS['__affiliate_services']);
}

/** One service row, or null when unknown. */
function affiliate_service_find(PDO $pdo, string $serviceKey): ?array {
    return affiliate_service_catalog($pdo)[$serviceKey] ?? null;
}

/** Human label for a service key. */
function affiliate_service_label(?array $service, string $fallback = ''): string {
    if (!$service) return $fallback;
    return is_rtl() ? (string)$service['label_ar'] : (string)$service['label_en'];
}

/**
 * The unconditional hard block.
 *
 * Returns true when this service's revenue is a regulated professional fee. Such a
 * service may be attributed and reported, but must never produce a commission.
 * Nothing may override this — not the flag on the row, not an admin, not a plan.
 */
function affiliate_service_is_regulated(?array $service): bool {
    if (!$service) return true; // unknown revenue is treated as the most restricted case
    return (int)($service['is_regulated_fee'] ?? 0) === 1;
}

/**
 * The effective attribution mode for a service.
 *
 * Starts from the stored mode and can only ever move *downward* in permissiveness:
 * a service that requires compliance approval but has not received it is downgraded
 * from `commission` to `attribution_only`. A regulated fee is forced to
 * `prohibited` no matter what the row says, so the column cannot be edited into an
 * unsafe state without this function still refusing.
 *
 * @return array{mode:string,reason:string,allowed:bool}
 */
function affiliate_service_effective_mode(?array $service): array {
    if (!$service) {
        return ['mode' => 'disabled', 'reason' => 'unknown_service', 'allowed' => false];
    }

    if (affiliate_service_is_regulated($service)) {
        // A regulated fee is prohibited outright. Even attribution is limited to the
        // aggregate count, because attributing a client to an affiliate around a
        // regulated fee creates a record that implies a commercial relationship
        // that does not exist.
        return ['mode' => 'prohibited', 'reason' => 'regulated_professional_fee', 'allowed' => false];
    }

    if ((int)($service['is_active'] ?? 1) !== 1) {
        return ['mode' => 'disabled', 'reason' => 'service_inactive', 'allowed' => false];
    }

    $stored = (string)($service['attribution_mode'] ?? 'disabled');

    if ($stored === 'commission') {
        if ((int)($service['requires_compliance_approval'] ?? 1) === 1
            && (int)($service['compliance_approved'] ?? 0) !== 1) {
            // Commercial decision not yet recorded: keep the tracking, withhold the money.
            return ['mode' => 'attribution_only', 'reason' => 'awaiting_compliance_approval', 'allowed' => false];
        }
        if ((int)($service['commission_permitted'] ?? 0) !== 1) {
            return ['mode' => 'attribution_only', 'reason' => 'commission_not_permitted', 'allowed' => false];
        }
        return ['mode' => 'commission', 'reason' => 'eligible', 'allowed' => true];
    }

    return ['mode' => $stored, 'reason' => 'stored_mode', 'allowed' => false];
}

/**
 * May commission be paid on this service right now?
 * The single question every money path asks.
 */
function affiliate_service_is_commissionable(?array $service): bool {
    return affiliate_service_effective_mode($service)['allowed'];
}

/**
 * The revenue base for a commission, as integer minor units.
 *
 * `$money` carries the components as DECIMAL strings straight from the database:
 *   gross, subtotal, tax, discount, refunded
 *
 * NOTE ON DISCOUNT — it is NOT subtracted here.
 *   In this platform `invoices.amount` is the grand total with the discount already
 *   applied (amount = subtotal - discount_total + tax_total, see includes/finance.php).
 *   Subtracting `discount` from `amount` would remove it twice and understate every
 *   commission. The parameter is accepted so callers can pass the whole invoice row
 *   without filtering, and is recorded in the formula string for the audit note, but
 *   it does not enter the arithmetic.
 *
 * Only `eligible_net` is safe as a default, because it is the only base with the tax
 * the platform must remit removed. A percentage of the gross would pay commission on
 * money the platform never kept.
 *
 * @return array{minor:int,formula:string}
 */
function affiliate_revenue_base(string $base, array $money, ?string $currency = null): array {
    $gross = affiliate_to_minor($money['gross'] ?? '0', $currency);
    $tax = affiliate_to_minor($money['tax'] ?? '0', $currency);
    $refunded = affiliate_to_minor($money['refunded'] ?? '0', $currency);
    $subtotal = array_key_exists('subtotal', $money)
        ? affiliate_to_minor($money['subtotal'], $currency)
        : null;

    // A negative component would inflate the base; clamp rather than trust the input.
    if ($tax < 0) $tax = 0;
    if ($refunded < 0) $refunded = 0;

    switch ($base) {
        case 'gross':
            // Refunds still reduce the base even here: commission is never owed on
            // money that was returned, whichever base the plan nominates.
            $minor = $gross - $refunded;
            $formula = 'gross - refunded';
            break;
        case 'subtotal':
            // Line items before tax and discount. Falls back to gross - tax when the
            // caller did not supply the subtotal, which is the same figure for an
            // invoice with no discount.
            $baseSubtotal = $subtotal ?? ($gross - $tax);
            if ($baseSubtotal < 0) $baseSubtotal = 0;
            $minor = $baseSubtotal - $refunded;
            $formula = $subtotal !== null ? 'subtotal - refunded' : '(gross - tax) - refunded';
            break;
        case 'eligible_net':
        default:
            $minor = $gross - $tax - $refunded;
            $formula = 'gross - tax - refunded';
            break;
    }
    if ($minor < 0) $minor = 0;
    return ['minor' => $minor, 'formula' => $formula];
}

/**
 * Decide eligibility for one revenue event, end to end.
 *
 * @param array $context gross/tax/discount/refunded as DECIMAL strings, plus
 *                       optional 'campaign_id' and 'affiliate_id' for the audit note.
 * @return array{
 *   eligible:bool, mode:string, reason:string, currency:string,
 *   eligible_minor:int, gross_minor:int, tax_minor:int, refunded_minor:int,
 *   formula:string, service:?array, note:string
 * }
 */
function affiliate_service_eligibility(PDO $pdo, string $serviceKey, array $context = []): array {
    $service = affiliate_service_find($pdo, $serviceKey);
    $currency = (string)($context['currency'] ?? affiliate_settings($pdo)['payout_currency']);
    $effective = affiliate_service_effective_mode($service);

    $gross = affiliate_to_minor($context['gross'] ?? '0', $currency);
    $tax = affiliate_to_minor($context['tax'] ?? '0', $currency);
    $refunded = affiliate_to_minor($context['refunded'] ?? '0', $currency);

    $out = [
        'eligible' => false,
        'mode' => $effective['mode'],
        'reason' => $effective['reason'],
        'currency' => $currency,
        'eligible_minor' => 0,
        'gross_minor' => $gross,
        'tax_minor' => $tax,
        'refunded_minor' => $refunded,
        'formula' => '',
        'service' => $service,
        'note' => '',
    ];

    if (!$effective['allowed']) {
        $out['note'] = affiliate_eligibility_reason_label($effective['reason']);
        return $out;
    }

    // A minimum conversion amount is a property of the plan, not the service, so it
    // is applied by the caller that has a plan in hand. Everything up to here is
    // about whether this *kind* of revenue may ever carry commission.
    $base = affiliate_revenue_base('eligible_net', $context, $currency);
    $out['eligible_minor'] = $base['minor'];
    $out['formula'] = $base['formula'];
    $out['eligible'] = $base['minor'] > 0;
    if (!$out['eligible']) {
        $out['reason'] = 'no_eligible_amount';
        $out['note'] = affiliate_eligibility_reason_label('no_eligible_amount');
    } else {
        $out['reason'] = 'eligible';
    }
    return $out;
}

/** Bilingual explanation for an ineligibility reason, shown in the UI and stored on the row. */
function affiliate_eligibility_reason_label(string $reason): string {
    $map = [
        'regulated_professional_fee' => ['أتعاب مهنية منظّمة — لا يجوز تقاسمها مع طرف ثالث', 'Regulated professional fee — may not be shared with a third party'],
        'awaiting_compliance_approval' => ['بانتظار موافقة مسؤول الالتزام', 'Awaiting compliance approval'],
        'commission_not_permitted' => ['العمولة غير مسموح بها لهذه الخدمة', 'Commission is not permitted for this service'],
        'service_inactive' => ['الخدمة غير مفعّلة', 'Service is not active'],
        'unknown_service' => ['خدمة غير معروفة', 'Unknown service'],
        'no_eligible_amount' => ['لا يوجد مبلغ مؤهل بعد خصم الضريبة والمرتجعات', 'No eligible amount after tax and refunds'],
        'no_plan' => ['لا توجد خطة عمولة سارية', 'No active commission plan'],
        'below_minimum' => ['أقل من الحد الأدنى للتحويل', 'Below the minimum conversion amount'],
        'self_referral' => ['إحالة ذاتية', 'Self-referral'],
        'program_disabled' => ['البرنامج غير مفعّل', 'Programme is not enabled'],
        'affiliate_not_active' => ['حساب المسوّق غير نشط', 'Affiliate is not active'],
        'outside_window' => ['خارج نافذة الإحالة', 'Outside the referral window'],
        'no_attribution' => ['لا توجد إحالة مسجّلة لهذا العميل', 'No referral recorded for this customer'],
        'eligible' => ['مؤهل', 'Eligible'],
    ];
    $row = $map[$reason] ?? [$reason, $reason];
    return is_rtl() ? $row[0] : $row[1];
}

/**
 * Every service a commission-bearing link may point at.
 * Used by the affiliate link builder to offer only lawful destinations.
 */
function affiliate_service_commissionable_keys(PDO $pdo): array {
    $keys = [];
    foreach (affiliate_service_catalog($pdo) as $key => $service) {
        if (affiliate_service_is_commissionable($service)) $keys[] = $key;
    }
    return $keys;
}

/**
 * A compliance summary for the admin console.
 *
 * Reports the gate's actual state rather than the stored flags, so an administrator
 * sees what the system will really do — which is how the regulated-fee block stays
 * visible instead of being a footnote in a migration file.
 */
function affiliate_service_compliance_summary(PDO $pdo): array {
    $rows = [];
    $commissionable = 0;
    $blockedRegulated = 0;
    foreach (affiliate_service_catalog($pdo) as $key => $service) {
        $effective = affiliate_service_effective_mode($service);
        $regulated = affiliate_service_is_regulated($service);
        if ($effective['allowed']) $commissionable++;
        if ($regulated) $blockedRegulated++;
        $rows[] = [
            'service_key' => $key,
            'label' => affiliate_service_label($service, $key),
            'revenue_source' => (string)$service['revenue_source'],
            'stored_mode' => (string)$service['attribution_mode'],
            'effective_mode' => $effective['mode'],
            'reason' => $effective['reason'],
            'regulated' => $regulated,
            'commissionable' => $effective['allowed'],
            'compliance_approved' => (int)($service['compliance_approved'] ?? 0) === 1,
            'requires_approval' => (int)($service['requires_compliance_approval'] ?? 1) === 1,
            'note' => (string)($service['compliance_note'] ?? ''),
        ];
    }
    return [
        'rows' => $rows,
        'commissionable_count' => $commissionable,
        'regulated_count' => $blockedRegulated,
        'total' => count($rows),
    ];
}
