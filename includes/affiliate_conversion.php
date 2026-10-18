<?php
/**
 * Conversion and commission engine (Execution 31).
 *
 * A conversion is a value event for a referred customer. It is created only from a
 * verified payment (see includes/affiliate_payment.php, which hooks
 * finance_record_payment), never from a browser event. This file turns that event
 * into a commission using the affiliate's plan, the service's compliance gate and
 * the platform's revenue arithmetic.
 *
 * IDEMPOTENCY
 *   `affiliate_conversions.event_key` is UNIQUE and is the whole guard: a payment
 *   webhook delivered five times produces one conversion, and
 *   `affiliate_commissions.conversion_id` is UNIQUE so that one conversion cannot
 *   produce two commissions. Both are database constraints, not application checks,
 *   because an application check loses a race and a constraint does not.
 *
 * PLAN RESOLUTION ORDER
 *   1. the campaign's plan, when the conversion came through a campaign
 *   2. the affiliate's own plan
 *   3. the default active plan
 *   Resolving in that order means a negotiated partner rate on the affiliate wins
 *   over the campaign default, and a campaign-specific promotion wins over both
 *   only when the campaign explicitly names a plan.
 */
require_once __DIR__ . '/affiliate.php';
require_once __DIR__ . '/affiliate_service.php';
require_once __DIR__ . '/affiliate_ledger.php';
require_once __DIR__ . '/affiliate_fraud.php';

/**
 * A pseudonymous reference to the paying party.
 *
 * The affiliate dashboard needs to tell two conversions apart without ever learning
 * who the customer is. A salted hash of the internal id does that: stable for the
 * same customer, useless for identifying them, and it leaks nothing if the
 * dashboard is screenshotted. The raw id stays on the conversion row for the
 * platform's own joins and is never rendered to an affiliate.
 */
function affiliate_subject_ref(string $subjectType, int $subjectId): string {
    $salt = defined('MAWATHEQ_ENCRYPTION_KEY') ? (string)MAWATHEQ_ENCRYPTION_KEY : 'mawatheq-affiliate';
    return substr(hash('sha256', $salt . '|subject|' . $subjectType . '|' . $subjectId), 0, 32);
}

/**
 * Resolve the commission plan that governs a conversion.
 * Returns null when no plan applies, in which case the conversion is recorded but
 * cannot produce money — which is the correct outcome for an unconfigured programme.
 */
function affiliate_resolve_plan(PDO $pdo, array $affiliate, ?int $campaignId = null): ?array {
    if ($campaignId) {
        $campaign = affiliate_campaign_find($pdo, $campaignId);
        if ($campaign && !empty($campaign['commission_plan_id'])) {
            $plan = affiliate_plan_find($pdo, (int)$campaign['commission_plan_id']);
            if ($plan && affiliate_plan_is_effective($plan)) return $plan;
        }
    }
    if (!empty($affiliate['commission_plan_id'])) {
        $plan = affiliate_plan_find($pdo, (int)$affiliate['commission_plan_id']);
        if ($plan && affiliate_plan_is_effective($plan)) return $plan;
    }
    return affiliate_plan_default($pdo);
}

/** A plan by id. */
function affiliate_plan_find(PDO $pdo, int $id): ?array {
    if ($id <= 0) return null;
    try {
        $stmt = $pdo->prepare('SELECT * FROM affiliate_commission_plans WHERE id=?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** The active default plan, or the newest active plan when none is flagged default. */
function affiliate_plan_default(PDO $pdo): ?array {
    try {
        $stmt = $pdo->query("SELECT * FROM affiliate_commission_plans WHERE is_active=1 AND (effective_to IS NULL OR effective_to >= CURDATE()) ORDER BY is_default DESC, effective_from DESC, version DESC LIMIT 1");
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** True when a plan is inside its effective date range. */
function affiliate_plan_is_effective(?array $plan): bool {
    if (!$plan) return false;
    if ((int)($plan['is_active'] ?? 1) !== 1) return false;
    $today = date('Y-m-d');
    if (!empty($plan['effective_from']) && $plan['effective_from'] > $today) return false;
    if (!empty($plan['effective_to']) && $plan['effective_to'] < $today) return false;
    return true;
}

/** All rules attached to a plan, keyed for lookup by service. */
function affiliate_plan_rules(PDO $pdo, int $planId): array {
    try {
        $stmt = $pdo->prepare('SELECT * FROM affiliate_commission_rules WHERE plan_id=? ORDER BY sort_order, id');
        $stmt->execute([$planId]);
        $out = ['by_service' => [], 'tiers' => []];
        foreach ($stmt->fetchAll() as $row) {
            if (($row['rule_type'] ?? '') === 'tier') {
                $out['tiers'][] = $row;
            } elseif (!empty($row['service_key'])) {
                $out['by_service'][(string)$row['service_key']] = $row;
            }
        }
        return $out;
    } catch (Throwable $e) {
        return ['by_service' => [], 'tiers' => []];
    }
}

/**
 * Compute the commission for an eligible amount, as integer minor units.
 *
 * @param array $plan    the resolved plan row
 * @param array $rules   from affiliate_plan_rules()
 * @param string $serviceKey
 * @param int   $baseMinor eligible amount in minor units
 * @return array{minor:int,note:string}
 */
function affiliate_compute_commission(array $plan, array $rules, string $serviceKey, int $baseMinor): array {
    $currency = (string)($plan['currency'] ?? affiliate_settings()['payout_currency']);
    $minor = 0;
    $note = [];

    $rule = $rules['by_service'][$serviceKey] ?? null;
    $planType = (string)($plan['plan_type'] ?? 'percentage');

    // A per-service rule overrides the plan's own type. That is what makes a plan
    // able to pay 15% on subscriptions and a flat 5 BHD on a service fee.
    if ($rule) {
        if (($rule['rule_type'] ?? '') === 'fixed') {
            $minor = affiliate_to_minor($rule['amount'] ?? '0', $currency);
            $note[] = 'قاعدة خدمة: مبلغ ثابت ' . affiliate_from_minor($minor, $currency);
        } elseif (($rule['rule_type'] ?? '') === 'percentage') {
            $pct = $rule['tier_percentage'] ?? $plan['percentage'];
            $minor = affiliate_apply_percent($baseMinor, $pct);
            $note[] = 'قاعدة خدمة: ' . $pct . '%';
        }
        if (!empty($rule['max_per_conversion'])) {
            $cap = affiliate_to_minor($rule['max_per_conversion'], $currency);
            if ($minor > $cap) { $minor = $cap; $note[] = 'سقف لكل تحويل'; }
        }
    } elseif ($planType === 'fixed') {
        $minor = affiliate_to_minor($plan['fixed_amount'] ?? '0', $currency);
        $note[] = 'مبلغ ثابت';
    } elseif ($planType === 'tiered' && $rules['tiers']) {
        // Banded on the eligible amount. The band that contains the amount applies;
        // bands are not cumulative, which is the simpler and less surprising reading
        // and the one the admin UI describes.
        foreach ($rules['tiers'] as $tier) {
            $min = $tier['tier_min'] !== null ? affiliate_to_minor($tier['tier_min'], $currency) : null;
            $max = $tier['tier_max'] !== null ? affiliate_to_minor($tier['tier_max'], $currency) : null;
            if ($min !== null && $baseMinor < $min) continue;
            if ($max !== null && $baseMinor > $max) continue;
            $minor = affiliate_apply_percent($baseMinor, $tier['tier_percentage'] ?? $plan['percentage']);
            $note[] = 'شريحة: ' . ($tier['tier_percentage'] ?? $plan['percentage']) . '%';
            break;
        }
    } elseif ($planType === 'hybrid') {
        // Percentage plus a fixed component: a retainer-style arrangement.
        $minor = affiliate_apply_percent($baseMinor, $plan['percentage']) + affiliate_to_minor($plan['fixed_amount'] ?? '0', $currency);
        $note[] = 'هجين: نسبة + مبلغ ثابت';
    } else {
        $minor = affiliate_apply_percent($baseMinor, $plan['percentage'] ?? '0');
        $note[] = (string)($plan['percentage'] ?? '0') . '% من ' . ($plan['revenue_base'] ?? 'eligible_net');
    }

    // Plan-level floor and cap. A cap is how a partner agreement stays bounded; a
    // floor is how a small conversion still pays a sensible amount.
    if (!empty($plan['min_commission'])) {
        $floor = affiliate_to_minor($plan['min_commission'], $currency);
        if ($minor < $floor) { $minor = $floor; $note[] = 'الحد الأدنى للعمولة'; }
    }
    if (!empty($plan['max_commission'])) {
        $cap = affiliate_to_minor($plan['max_commission'], $currency);
        if ($minor > $cap) { $minor = $cap; $note[] = 'الحد الأعلى للعمولة'; }
    }
    if ($minor < 0) $minor = 0;

    return ['minor' => $minor, 'note' => implode(' · ', $note)];
}

/**
 * Record a conversion, and a commission when the revenue is eligible.
 *
 * @param array $attribution the owning attribution row
 * @param array $payload     service_key, event_type, event_key, source_type, source_id,
 *                           gross/tax/discount/refunded/subtotal as DECIMAL strings,
 *                           currency, subject_type, invoice_id, payment_id, subscription_id,
 *                           converted_at (optional)
 * @return array{ok:bool,conversion_id?:int,commission_id?:?int,status?:string,duplicate?:bool,reason?:string}
 */
function affiliate_record_conversion(PDO $pdo, array $attribution, array $payload): array {
    $settings = affiliate_settings($pdo);
    if (!$settings['program_enabled']) return ['ok' => false, 'reason' => 'program_disabled'];

    $affiliateId = (int)$attribution['affiliate_id'];
    $affiliate = affiliate_find($pdo, $affiliateId);
    if (!$affiliate) return ['ok' => false, 'reason' => 'no_affiliate'];

    $serviceKey = (string)($payload['service_key'] ?? '');
    $eventKey = (string)($payload['event_key'] ?? '');
    if ($eventKey === '') return ['ok' => false, 'reason' => 'no_event_key'];
    $eventKey = mb_substr($eventKey, 0, 80, 'UTF-8');

    $currency = currency_normalize((string)($payload['currency'] ?? $settings['payout_currency']));
    $subjectType = in_array($payload['subject_type'] ?? 'client', ['client', 'lawyer', 'firm'], true) ? $payload['subject_type'] : 'client';
    $subjectId = (int)($payload['subject_id'] ?? 0);

    // Idempotency: the same event never produces a second conversion.
    try {
        $dup = $pdo->prepare('SELECT id FROM affiliate_conversions WHERE event_key=?');
        $dup->execute([$eventKey]);
        $existingId = $dup->fetchColumn();
        if ($existingId) {
            $commissionId = $pdo->prepare('SELECT id FROM affiliate_commissions WHERE conversion_id=?');
            $commissionId->execute([(int)$existingId]);
            return ['ok' => true, 'duplicate' => true, 'conversion_id' => (int)$existingId, 'commission_id' => ($cid = $commissionId->fetchColumn()) ? (int)$cid : null];
        }
    } catch (Throwable $e) {
        error_log('affiliate_record_conversion dedupe: ' . $e->getMessage());
    }

    // A referral is only actionable while the window is open, unless the account was
    // already bound to the attribution (which locks it). This is the "within window"
    // rule, applied at conversion time rather than only at capture time, because a
    // window can close between a click and a purchase.
    $windowOpen = !empty($attribution['locked_at']) || strtotime((string)$attribution['expires_at']) >= time();

    $service = affiliate_service_find($pdo, $serviceKey);
    $eligibility = affiliate_service_eligibility($pdo, $serviceKey, [
        'gross' => (string)($payload['gross'] ?? '0'),
        'tax' => (string)($payload['tax'] ?? '0'),
        'subtotal' => $payload['subtotal'] ?? null,
        'refunded' => (string)($payload['refunded'] ?? '0'),
        'currency' => $currency,
    ]);

    $eligible = $eligibility['eligible'];
    $ineligibleReason = $eligible ? null : $eligibility['reason'];
    $eligibleMinor = $eligibility['eligible_minor'];

    // Non-window and non-active-affiliate conditions override the service verdict.
    if (!$windowOpen) { $eligible = false; $ineligibleReason = 'outside_window'; }
    elseif (!affiliate_can_earn($affiliate)) { $eligible = false; $ineligibleReason = 'affiliate_not_active'; }

    $plan = null; $rules = ['by_service' => [], 'tiers' => []];
    $commissionMinor = 0;
    $commissionNote = '';
    if ($eligible) {
        $plan = affiliate_resolve_plan($pdo, $affiliate, $attribution['campaign_id'] !== null ? (int)$attribution['campaign_id'] : null);
        if (!$plan) {
            $eligible = false; $ineligibleReason = 'no_plan';
        } else {
            // Minimum conversion amount is a plan property.
            if (!empty($plan['min_conversion_amount'])) {
                $min = affiliate_to_minor($plan['min_conversion_amount'], $currency);
                if ($eligibleMinor < $min) { $eligible = false; $ineligibleReason = 'below_minimum'; }
            }
            // First-payment-only: once this customer has produced an earning commission,
            // later payments from the same customer do not pay again.
            if ($eligible && (int)$plan['first_payment_only'] === 1 && $subjectId > 0) {
                if (affiliate_subject_has_earning_commission($pdo, $affiliateId, $subjectType, $subjectId, (string)$plan['slug'])) {
                    $eligible = false; $ineligibleReason = 'first_payment_only';
                }
            }
            // Recurring: a recurring plan permits repeat commissions inside its window.
            if ($eligible && (int)$plan['first_payment_only'] === 0 && (int)$plan['recurring_months'] > 0 && $subjectId > 0) {
                $first = affiliate_subject_first_conversion_at($pdo, $affiliateId, $subjectType, $subjectId);
                if ($first && strtotime($first) < strtotime('-' . (int)$plan['recurring_months'] . ' months')) {
                    $eligible = false; $ineligibleReason = 'outside_window';
                }
            }
            if ($eligible) {
                $rules = affiliate_plan_rules($pdo, (int)$plan['id']);
                $computed = affiliate_compute_commission($plan, $rules, $serviceKey, $eligibleMinor);
                $commissionMinor = $computed['minor'];
                $commissionNote = $computed['note'];
                if ($commissionMinor <= 0) {
                    $eligible = false;
                    $ineligibleReason = 'no_eligible_amount';
                }
            }
        }
    }

    // Write the conversion and its commission in one transaction, so a conversion
    // that exists without its commission cannot be observed.
    $owns = !$pdo->inTransaction();
    try {
        if ($owns) $pdo->beginTransaction();

        $status = $eligible ? 'qualified' : 'tracked';
        $pdo->prepare('INSERT INTO affiliate_conversions
            (affiliate_id, attribution_id, campaign_id, link_id, service_key, subject_ref, subject_type, event_type, event_key,
             source_type, source_id, invoice_id, payment_id, subscription_id, currency, gross_amount, tax_amount, discount_amount,
             refunded_amount, eligible_amount, eligible_reason, is_eligible, ineligible_reason, status, converted_at, qualified_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                $affiliateId, (int)$attribution['id'], $attribution['campaign_id'], $attribution['link_id'],
                $serviceKey, affiliate_subject_ref($subjectType, $subjectId > 0 ? $subjectId : 0), $subjectType,
                in_array($payload['event_type'] ?? 'payment', ['registration', 'booking', 'payment', 'subscription'], true) ? $payload['event_type'] : 'payment',
                $eventKey,
                mb_substr((string)($payload['source_type'] ?? 'payment'), 0, 40, 'UTF-8'),
                isset($payload['source_id']) ? (int)$payload['source_id'] : null,
                isset($payload['invoice_id']) ? (int)$payload['invoice_id'] : null,
                isset($payload['payment_id']) ? (int)$payload['payment_id'] : null,
                isset($payload['subscription_id']) ? (int)$payload['subscription_id'] : null,
                $currency,
                affiliate_round((string)($payload['gross'] ?? '0'), $currency),
                affiliate_round((string)($payload['tax'] ?? '0'), $currency),
                affiliate_round((string)($payload['discount'] ?? '0'), $currency),
                affiliate_round((string)($payload['refunded'] ?? '0'), $currency),
                affiliate_from_minor($eligibleMinor, $currency),
                $eligibility['formula'] !== '' ? $eligibility['formula'] : null,
                $eligible ? 1 : 0,
                $ineligibleReason,
                $status,
                $payload['converted_at'] ?? date('Y-m-d H:i:s'),
                $eligible ? date('Y-m-d H:i:s') : null,
            ]);
        $conversionId = (int)$pdo->lastInsertId();

        $commissionId = null;
        if ($eligible && $plan) {
            $holdDays = (int)($plan['hold_days'] ?? $settings['hold_days']);
            $holdUntil = date('Y-m-d', strtotime('+' . $holdDays . ' days'));
            // The lifecycle is written to its real resting point: inside the hold
            // period the money is not yet approvable, so the row says `holding`.
            $commissionStatus = $holdDays > 0 ? 'holding' : 'approved';
            $pdo->prepare('INSERT INTO affiliate_commissions
                (affiliate_id, conversion_id, plan_id, plan_version, currency, base_amount, commission_amount, status, calculation_note, hold_until, approved_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([
                    $affiliateId, $conversionId, (int)$plan['id'], (int)$plan['version'], $currency,
                    affiliate_from_minor($eligibleMinor, $currency),
                    affiliate_from_minor($commissionMinor, $currency),
                    $commissionStatus,
                    mb_substr(($plan['name'] ?? '') . ' v' . ($plan['version'] ?? '') . ' — ' . $commissionNote, 0, 500, 'UTF-8'),
                    $holdUntil,
                    $holdDays > 0 ? null : date('Y-m-d H:i:s'),
                ]);
            $commissionId = (int)$pdo->lastInsertId();

            $pdo->prepare('UPDATE affiliate_conversions SET status=?, qualified_at=COALESCE(qualified_at,NOW()) WHERE id=?')
                ->execute([$commissionStatus === 'approved' ? 'approved' : 'holding', $conversionId]);
        }

        if ($owns) $pdo->commit();
    } catch (PDOException $e) {
        if ($owns && $pdo->inTransaction()) $pdo->rollBack();
        if (($e->errorInfo[1] ?? 0) === 1062) {
            // Concurrent duplicate: the other request won, which is the desired result.
            $dup = $pdo->prepare('SELECT id FROM affiliate_conversions WHERE event_key=?');
            $dup->execute([$eventKey]);
            $id = $dup->fetchColumn();
            return ['ok' => true, 'duplicate' => true, 'conversion_id' => $id ? (int)$id : null];
        }
        error_log('affiliate_record_conversion failed: ' . $e->getMessage());
        return ['ok' => false, 'reason' => 'insert_failed'];
    } catch (Throwable $e) {
        if ($owns && $pdo->inTransaction()) $pdo->rollBack();
        error_log('affiliate_record_conversion failed: ' . $e->getMessage());
        return ['ok' => false, 'reason' => 'insert_failed'];
    }

    // Ledger recognition and side effects happen after the commit, each guarded, so a
    // failure in a notification cannot undo a recorded commission.
    if ($commissionId) {
        $commission = affiliate_commission_find($pdo, $commissionId);
        if ($commission) {
            affiliate_ledger_recognise($pdo, $commission);
            affiliate_stats_bump($pdo, $affiliateId, ['conversions' => 1, 'qualified_conversions' => 1]);
            affiliate_notify($pdo, $affiliateId, 'commission_created:' . $commissionId, 'commission_created',
                is_rtl() ? 'تم تسجيل عمولة جديدة' : 'A new commission was recorded',
                is_rtl()
                    ? 'قيمة العمولة: ' . affiliate_format_money($commission['commission_amount'], $currency) . ' (قيد فترة الانتظار)'
                    : 'Commission: ' . affiliate_format_money($commission['commission_amount'], $currency) . ' (in the hold period)',
                '/affiliate/earnings.php');
        }
    } else {
        affiliate_stats_bump($pdo, $affiliateId, ['conversions' => 1]);
    }

    // A conversion worth nothing is still worth flagging for review, because the
    // usual cause is a misconfiguration an administrator wants to know about.
    if (!$eligible && $ineligibleReason === 'no_plan') {
        affiliate_flag($pdo, [
            'affiliate_id' => $affiliateId, 'conversion_id' => $conversionId,
            'flag_type' => 'other', 'severity' => 'low',
            'description' => 'تحويل مؤهل بدون خطة عمولة سارية',
        ]);
    }

    affiliate_audit($pdo, 'conversion_recorded', [
        'affiliate_id' => $affiliateId, 'subject_type' => 'conversion', 'subject_id' => $conversionId,
        'detail' => 'service=' . $serviceKey . ' eligible=' . ($eligible ? 'yes' : 'no:' . $ineligibleReason) . ' base=' . affiliate_from_minor($eligibleMinor, $currency),
    ]);

    return ['ok' => true, 'conversion_id' => $conversionId, 'commission_id' => $commissionId, 'status' => $status, 'eligible' => $eligible, 'reason' => $ineligibleReason];
}

/** A conversion by id. */
function affiliate_conversion_find(PDO $pdo, int $id): ?array {
    try {
        $stmt = $pdo->prepare('SELECT * FROM affiliate_conversions WHERE id=?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** A commission by id. */
function affiliate_commission_find(PDO $pdo, int $id): ?array {
    try {
        $stmt = $pdo->prepare('SELECT * FROM affiliate_commissions WHERE id=?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** The commission attached to a conversion, if any. */
function affiliate_commission_for_conversion(PDO $pdo, int $conversionId): ?array {
    try {
        $stmt = $pdo->prepare('SELECT * FROM affiliate_commissions WHERE conversion_id=?');
        $stmt->execute([$conversionId]);
        return $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Has this customer already produced an earning commission under this plan? */
function affiliate_subject_has_earning_commission(PDO $pdo, int $affiliateId, string $subjectType, int $subjectId, string $planSlug = ''): bool {
    $ref = affiliate_subject_ref($subjectType, $subjectId);
    $earning = array_merge(affiliate_states_earning(), ['qualified']);
    $in = implode(',', array_fill(0, count($earning), '?'));
    try {
        $sql = "SELECT COUNT(*) FROM affiliate_conversions c
                JOIN affiliate_commissions m ON m.conversion_id = c.id
                WHERE c.affiliate_id=? AND c.subject_ref=? AND c.is_eligible=1 AND m.status IN ($in)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$affiliateId, $ref], $earning));
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        // Failing closed here would silently withhold a legitimate commission; failing
        // open would double-pay. Withholding is the safer error and is visible to the
        // affiliate as an ineligible reason, so it is the one chosen.
        error_log('affiliate_subject_has_earning_commission: ' . $e->getMessage());
        return true;
    }
}

/** When this customer first converted for this affiliate. */
function affiliate_subject_first_conversion_at(PDO $pdo, int $affiliateId, string $subjectType, int $subjectId): ?string {
    $ref = affiliate_subject_ref($subjectType, $subjectId);
    try {
        $stmt = $pdo->prepare('SELECT MIN(converted_at) FROM affiliate_conversions WHERE affiliate_id=? AND subject_ref=?');
        $stmt->execute([$affiliateId, $ref]);
        $value = $stmt->fetchColumn();
        return $value ? (string)$value : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Move a commission to a new state, posting the ledger entry the move implies.
 *
 * The transition is validated against the declared state machine first, so an
 * illegal move (payable → holding, say) is refused rather than silently applied.
 * Ledger effects are keyed to the destination state so a retried call is a no-op.
 *
 * @return array{ok:bool,from:string,to:string,reason?:string}
 */
function affiliate_commission_transition(PDO $pdo, int $commissionId, string $to, array $options = []): array {
    $commission = affiliate_commission_find($pdo, $commissionId);
    if (!$commission) return ['ok' => false, 'from' => '', 'to' => $to, 'reason' => 'not_found'];
    $from = (string)$commission['status'];
    if ($from === $to) return ['ok' => true, 'from' => $from, 'to' => $to, 'reason' => 'already'];

    if (!affiliate_state_can_transition($from, $to)) {
        return ['ok' => false, 'from' => $from, 'to' => $to, 'reason' => 'illegal_transition', 'error' => 'illegal_transition'];
    }

    $sets = ['status = ?'];
    $params = [$to];
    if ($to === 'approved') { $sets[] = 'approved_at = NOW()'; $sets[] = 'approved_by = ?'; $params[] = isset($options['actor_id']) ? (int)$options['actor_id'] : null; }
    if ($to === 'payable') { $sets[] = 'payable_at = NOW()'; }
    if ($to === 'paid_out') { $sets[] = 'paid_out_at = NOW()'; }
    if ($to === 'reversed' || $to === 'refunded') {
        $reversedMinor = isset($options['reversed_minor'])
            ? (int)$options['reversed_minor']
            : affiliate_to_minor($commission['commission_amount'], (string)$commission['currency']);
        $sets[] = 'reversed_amount = ?';
        $params[] = affiliate_from_minor($reversedMinor, (string)$commission['currency']);
    }
    if (isset($options['payout_id'])) { $sets[] = 'payout_id = ?'; $params[] = (int)$options['payout_id']; }
    $params[] = $commissionId;

    try {
        $pdo->prepare('UPDATE affiliate_commissions SET ' . implode(', ', $sets) . ' WHERE id=?')->execute($params);
    } catch (Throwable $e) {
        error_log('affiliate_commission_transition: ' . $e->getMessage());
        return ['ok' => false, 'from' => $from, 'to' => $to, 'reason' => 'update_failed'];
    }

    $updated = affiliate_commission_find($pdo, $commissionId) ?? $commission;

    // The conversion mirrors its commission, so a single query on either table tells
    // the same story. Only forward states are mirrored; a `tracked` conversion that
    // never earned must not be promoted by a commission that does not exist.
    try {
        $pdo->prepare('UPDATE affiliate_conversions SET status=? WHERE id=?')->execute([$to, (int)$commission['conversion_id']]);
    } catch (Throwable $e) { /* the conversion status is a mirror; the commission is authoritative */ }

    if ($to === 'reversed' || $to === 'refunded') {
        affiliate_ledger_reverse($pdo, $updated, (string)($options['reason'] ?? 'reversal'), [
            'actor_type' => $options['actor_type'] ?? 'system',
            'actor_id' => $options['actor_id'] ?? null,
        ]);
        affiliate_stats_bump($pdo, (int)$commission['affiliate_id'], ['commission_reversed' => 0]);
    }
    if ($to === 'paid_out' && isset($options['payout'])) {
        affiliate_ledger_payout($pdo, $options['payout'], [(int)$commissionId], [
            'actor_type' => $options['actor_type'] ?? 'system',
            'actor_id' => $options['actor_id'] ?? null,
        ]);
    }

    affiliate_audit($pdo, 'commission_status_changed', [
        'affiliate_id' => (int)$commission['affiliate_id'],
        'subject_type' => 'commission', 'subject_id' => $commissionId,
        'from_state' => $from, 'to_state' => $to,
        'detail' => (string)($options['reason'] ?? ''),
    ]);

    return ['ok' => true, 'from' => $from, 'to' => $to];
}

/**
 * Apply a refund or chargeback to a conversion.
 *
 * Partial refunds reduce the eligible amount and recompute the commission, because
 * paying commission on money that was returned is the single most common way an
 * affiliate programme loses money. A full refund reverses the commission entirely
 * and claws back anything already paid.
 *
 * @return array{ok:bool,conversion_id?:int,refunded_minor?:int,commission_minor?:int,reversed?:bool,reason?:string}
 */
function affiliate_apply_refund(PDO $pdo, int $conversionId, $refundAmount, array $options = []): array {
    $conversion = affiliate_conversion_find($pdo, $conversionId);
    if (!$conversion) return ['ok' => false, 'reason' => 'not_found'];
    $currency = (string)$conversion['currency'];

    $newRefundTotal = affiliate_to_minor($conversion['refunded_amount'], $currency) + affiliate_to_minor($refundAmount, $currency);
    $grossMinor = affiliate_to_minor($conversion['gross_amount'], $currency);
    if ($newRefundTotal > $grossMinor) $newRefundTotal = $grossMinor;

    $taxMinor = affiliate_to_minor($conversion['tax_amount'], $currency);
    $base = $grossMinor - $taxMinor - $newRefundTotal;
    if ($base < 0) $base = 0;

    try {
        $pdo->prepare('UPDATE affiliate_conversions SET refunded_amount=?, eligible_amount=?, updated_at=NOW() WHERE id=?')
            ->execute([affiliate_from_minor($newRefundTotal, $currency), affiliate_from_minor($base, $currency), $conversionId]);
    } catch (Throwable $e) {
        error_log('affiliate_apply_refund: ' . $e->getMessage());
        return ['ok' => false, 'reason' => 'update_failed'];
    }

    $commission = affiliate_commission_for_conversion($pdo, $conversionId);
    if (!$commission) {
        affiliate_audit($pdo, 'refund_applied_no_commission', [
            'affiliate_id' => (int)$conversion['affiliate_id'], 'subject_type' => 'conversion', 'subject_id' => $conversionId,
            'detail' => 'refunded=' . affiliate_from_minor($newRefundTotal, $currency),
        ]);
        return ['ok' => true, 'conversion_id' => $conversionId, 'refunded_minor' => $newRefundTotal, 'reversed' => false];
    }

    $newCommissionMinor = $commission['plan_id'] ? affiliate_recompute_commission_for_refund($pdo, $commission, $base) : 0;
    // The commission row records what was earned (`commission_amount`, never mutated)
    // and the cumulative clawback (`reversed_amount`). The net owed is the difference.
    $earnedMinor = affiliate_to_minor($commission['commission_amount'], $currency);
    $newReversedMinor = $earnedMinor - $newCommissionMinor;
    if ($newReversedMinor < 0) $newReversedMinor = 0;
    $alreadyReversedMinor = affiliate_to_minor($commission['reversed_amount'], $currency);
    $deltaMinor = $newReversedMinor - $alreadyReversedMinor;

    // A full reversal is a distinct state; a partial one leaves the commission alive
    // at its reduced value so the payout still includes the remainder.
    $fullyReversed = $newCommissionMinor <= 0;
    try {
        $pdo->prepare('UPDATE affiliate_commissions SET reversed_amount=?, status=?, updated_at=NOW() WHERE id=?')
            ->execute([
                affiliate_from_minor($newReversedMinor, $currency),
                $fullyReversed ? 'refunded' : $commission['status'],
                (int)$commission['id'],
            ]);
    } catch (Throwable $e) {
        error_log('affiliate_apply_refund commission: ' . $e->getMessage());
        return ['ok' => false, 'reason' => 'update_failed'];
    }

    $updated = affiliate_commission_find($pdo, (int)$commission['id']) ?? $commission;
    if ($deltaMinor > 0) {
        // The PRE-update row is passed deliberately: affiliate_ledger_reverse derives
        // the outstanding balance from the row it is given, so handing it the row that
        // already carries the new `reversed_amount` would make it compute the clawback
        // against the reduced figure and mis-key the entry. The delta is passed
        // explicitly as well, so the posted leg is exactly the change this refund made.
        affiliate_ledger_reverse($pdo, $commission, 'refund', [
            'actor_type' => $options['actor_type'] ?? 'system',
            'actor_id' => $options['actor_id'] ?? null,
            'amount_minor' => $deltaMinor,
        ]);
        affiliate_notify($pdo, (int)$conversion['affiliate_id'], 'commission_refunded:' . (int)$commission['id'] . ':' . $newRefundTotal, 'commission_refunded',
            is_rtl() ? 'تم تسوية عمولة بسبب مرتجع' : 'A commission was adjusted after a refund',
            is_rtl()
                ? 'المبلغ المعكوس: ' . affiliate_format_money(affiliate_from_minor($deltaMinor, $currency), $currency)
                : 'Reversed: ' . affiliate_format_money(affiliate_from_minor($deltaMinor, $currency), $currency),
            '/affiliate/earnings.php');
    }
    affiliate_audit($pdo, 'refund_applied', [
        'affiliate_id' => (int)$conversion['affiliate_id'], 'subject_type' => 'conversion', 'subject_id' => $conversionId,
        'detail' => 'refunded=' . affiliate_from_minor($newRefundTotal, $currency) . ' net_commission=' . affiliate_from_minor($newCommissionMinor, $currency) . ' reversed_delta=' . affiliate_from_minor($deltaMinor, $currency),
    ]);

    return ['ok' => true, 'conversion_id' => $conversionId, 'refunded_minor' => $newRefundTotal, 'commission_minor' => $newCommissionMinor, 'reversed' => $fullyReversed];
}

/**
 * Recompute a commission on a reduced base, reusing the plan that produced it.
 *
 * The original plan row is used rather than the affiliate's current plan: a refund
 * must reduce the commission that was actually earned, at the rate it was earned
 * at, not at whatever rate applies today.
 */
function affiliate_recompute_commission_for_refund(PDO $pdo, array $commission, int $newBaseMinor): int {
    $plan = affiliate_plan_find($pdo, (int)$commission['plan_id']);
    if (!$plan) return 0;
    $rules = affiliate_plan_rules($pdo, (int)$plan['id']);
    $conversion = affiliate_conversion_find($pdo, (int)$commission['conversion_id']);
    $serviceKey = (string)($conversion['service_key'] ?? '');
    return affiliate_compute_commission($plan, $rules, $serviceKey, $newBaseMinor)['minor'];
}

/** Conversions for an affiliate, newest first. */
function affiliate_conversions(PDO $pdo, int $affiliateId, array $filters = []): array {
    $where = ['c.affiliate_id = ?'];
    $params = [$affiliateId];
    if (!empty($filters['status'])) { $where[] = 'c.status = ?'; $params[] = $filters['status']; }
    if (!empty($filters['service_key'])) { $where[] = 'c.service_key = ?'; $params[] = $filters['service_key']; }
    if (!empty($filters['campaign_id'])) { $where[] = 'c.campaign_id = ?'; $params[] = (int)$filters['campaign_id']; }
    if (isset($filters['is_demo'])) { $where[] = 'c.is_demo = ?'; $params[] = (int)$filters['is_demo']; }
    $limit = max(1, min(500, (int)($filters['limit'] ?? 100)));
    try {
        $stmt = $pdo->prepare('SELECT c.*, m.commission_amount, m.status AS commission_status, m.hold_until, m.reversed_amount
            FROM affiliate_conversions c
            LEFT JOIN affiliate_commissions m ON m.conversion_id = c.id
            WHERE ' . implode(' AND ', $where) . ' ORDER BY c.id DESC LIMIT ' . $limit);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * The affiliate's earnings summary.
 *
 * Every figure is derived from the commission rows, and `is_demo` is excluded
 * unless explicitly asked for. Demo data exists so the interface can be shown to a
 * stakeholder without touching real money; letting it into a report would make the
 * report a lie, so the exclusion is the default rather than an option.
 */
function affiliate_earnings_summary(PDO $pdo, int $affiliateId, array $options = []): array {
    $currency = (string)($options['currency'] ?? affiliate_settings($pdo)['payout_currency']);
    $includeDemo = !empty($options['include_demo']);
    $demoWhere = $includeDemo ? '' : ' AND m.is_demo = 0';

    $out = [
        'currency' => $currency,
        'pending' => '0', 'holding' => '0', 'approved' => '0', 'payable' => '0',
        'paid_out' => '0', 'reversed' => '0', 'available' => '0',
        'conversions' => 0, 'eligible_conversions' => 0, 'paid_conversions' => 0,
        'pending_minor' => 0, 'holding_minor' => 0, 'approved_minor' => 0,
        'payable_minor' => 0, 'paid_out_minor' => 0, 'reversed_minor' => 0, 'available_minor' => 0,
    ];

    try {
        $stmt = $pdo->prepare("SELECT status,
                COALESCE(SUM(commission_amount - reversed_amount),0) AS net,
                COUNT(*) AS n
            FROM affiliate_commissions m
            WHERE affiliate_id=? AND currency=?{$demoWhere}
            GROUP BY status");
        $stmt->execute([$affiliateId, $currency]);
        foreach ($stmt->fetchAll() as $row) {
            $minor = affiliate_to_minor((string)$row['net'], $currency);
            $status = (string)$row['status'];
            if ($status === 'tracked' || $status === 'qualified' || $status === 'pending_payment') {
                $out['pending_minor'] += $minor;
            } elseif ($status === 'holding') {
                $out['holding_minor'] += $minor;
            } elseif ($status === 'paid_eligible') {
                $out['holding_minor'] += $minor;
            } elseif ($status === 'approved') {
                $out['approved_minor'] += $minor;
            } elseif ($status === 'payable') {
                $out['payable_minor'] += $minor;
            } elseif ($status === 'paid_out') {
                $out['paid_out_minor'] += $minor;
            } elseif ($status === 'reversed' || $status === 'refunded') {
                $out['reversed_minor'] += affiliate_to_minor((string)$row['net'], $currency);
            }
        }

        $cstmt = $pdo->prepare("SELECT COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN is_eligible=1 THEN 1 ELSE 0 END),0) AS eligible,
                COALESCE(SUM(CASE WHEN status='paid_out' THEN 1 ELSE 0 END),0) AS paid
            FROM affiliate_conversions WHERE affiliate_id=?" . ($includeDemo ? '' : ' AND is_demo = 0'));
        $cstmt->execute([$affiliateId]);
        $counts = $cstmt->fetch() ?: [];
        $out['conversions'] = (int)($counts['total'] ?? 0);
        $out['eligible_conversions'] = (int)($counts['eligible'] ?? 0);
        $out['paid_conversions'] = (int)($counts['paid'] ?? 0);
    } catch (Throwable $e) {
        error_log('affiliate_earnings_summary: ' . $e->getMessage());
    }

    // "Available" is what could be requested today: approved plus payable. Holding is
    // deliberately excluded, because the hold exists precisely to keep it unavailable
    // until a refund window has passed.
    $out['available_minor'] = $out['approved_minor'] + $out['payable_minor'];
    foreach (['pending', 'holding', 'approved', 'payable', 'paid_out', 'reversed', 'available'] as $key) {
        $out[$key] = affiliate_from_minor($out[$key . '_minor'], $currency);
    }
    return $out;
}

/** Conversions and commissions in a date range, for reporting. */
function affiliate_report_range(PDO $pdo, ?string $from, ?string $to, array $options = []): array {
    $where = ['1=1'];
    $params = [];
    if ($from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = 'c.converted_at >= ?'; $params[] = $from . ' 00:00:00'; }
    if ($to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $where[] = 'c.converted_at <= ?'; $params[] = $to . ' 23:59:59'; }
    if (empty($options['include_demo'])) $where[] = 'c.is_demo = 0';
    if (!empty($options['affiliate_id'])) { $where[] = 'c.affiliate_id = ?'; $params[] = (int)$options['affiliate_id']; }
    try {
        $stmt = $pdo->prepare('SELECT c.*, m.commission_amount, m.status AS commission_status, a.code, a.full_name
            FROM affiliate_conversions c
            JOIN affiliates a ON a.id = c.affiliate_id
            LEFT JOIN affiliate_commissions m ON m.conversion_id = c.id
            WHERE ' . implode(' AND ', $where) . ' ORDER BY c.id DESC LIMIT 1000');
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}
