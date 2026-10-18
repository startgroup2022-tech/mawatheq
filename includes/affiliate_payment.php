<?php
/**
 * Payment integration (Execution 31).
 *
 * Bridges the platform's real money events into the affiliate programme. The hook
 * point is `finance_record_payment()`, which is the single choke point every payment
 * passes through — the lawyer's manual receipt, the N-Genius webhook, the SADAD
 * webhook, the return page and the mobile API all converge there. That is why this
 * integration is small: one function, called from one place, sees every payment.
 *
 * WHY THE CALL IS AT THE END OF THE FUNCTION, INSIDE A TRY
 *   Recording a payment must never fail because of the affiliate programme. The
 *   programme is an observability and compensation layer on top of billing; if its
 *   tables are missing, its configuration is wrong, or it throws, the invoice is
 *   still paid. Every entry point here is wrapped, and the wrapper logs and returns
 *   rather than propagating.
 *
 * WHAT COUNTS AS A CONVERSION
 *   A payment is attributed to whichever affiliate owns the paying customer, and only
 *   when that customer's attribution exists and is still open (or was locked at
 *   registration). No attribution, no conversion — a referral is a specific recorded
 *   event, not a guess from a UTM parameter on a later visit.
 */
require_once __DIR__ . '/affiliate.php';
require_once __DIR__ . '/affiliate_tracking.php';
require_once __DIR__ . '/affiliate_conversion.php';
require_once __DIR__ . '/affiliate_service.php';

/**
 * Resolve the affiliate that owns a paying customer.
 *
 * Preference order:
 *   1. an attribution bound to the user (created at registration or booking)
 *   2. an attribution bound to the lawyer, for a lawyer-side payment
 *   3. nothing — the payment is not referred
 *
 * Only a bound attribution is used. Falling back to a cookie here would attribute a
 * payment to whoever last followed a referral link on the device, which for a shared
 * computer is the wrong person entirely.
 */
function affiliate_owner_for_payment(PDO $pdo, array $invoice): ?array {
    $userId = (int)($invoice['user_id'] ?? 0);
    if ($userId > 0) {
        $attribution = affiliate_attribution_for_subject($pdo, 'client', $userId);
        if ($attribution) return $attribution;
    }
    $lawyerId = (int)($invoice['lawyer_id'] ?? 0);
    if ($lawyerId > 0) {
        $attribution = affiliate_attribution_for_subject($pdo, 'lawyer', $lawyerId);
        if ($attribution) return $attribution;
    }
    return null;
}

/**
 * Which service is this payment's revenue?
 *
 * An explicit `invoices.affiliate_service_key` wins. Otherwise the invoice is a
 * lawyer's professional fee — the regulated case — because that is what
 * `invoices` holds (`lawyer_id` is NOT NULL, so every invoice is a lawyer billing a
 * client). Defaulting the other way would silently make every invoice
 * commission-eligible, which is precisely the mistake the compliance gate exists to
 * prevent.
 */
function affiliate_service_for_invoice(array $invoice): string {
    $explicit = trim((string)($invoice['affiliate_service_key'] ?? ''));
    if ($explicit !== '') return $explicit;
    return 'legal_invoice_fee';
}

/**
 * Handle one recorded payment.
 *
 * Called by finance_record_payment() after the payment row and the invoice status
 * have been committed. Idempotent by construction: the conversion's event_key is
 * derived from the payment id, so a repeated call cannot produce a second conversion.
 *
 * @return array{ok:bool,reason?:string,conversion_id?:int,commission_id?:?int}
 */
function affiliate_handle_payment(PDO $pdo, int $invoiceId, int $paymentId): array {
    try {
        $settings = affiliate_settings($pdo);
        if (!$settings['program_enabled']) return ['ok' => false, 'reason' => 'program_disabled'];

        $stmt = $pdo->prepare('SELECT * FROM invoices WHERE id=?');
        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch();
        if (!$invoice) return ['ok' => false, 'reason' => 'invoice_not_found'];

        $pstmt = $pdo->prepare('SELECT * FROM invoice_payments WHERE id=?');
        $pstmt->execute([$paymentId]);
        $payment = $pstmt->fetch();
        if (!$payment) return ['ok' => false, 'reason' => 'payment_not_found'];
        if (($payment['status'] ?? '') !== 'completed') return ['ok' => false, 'reason' => 'payment_not_completed'];

        $attribution = affiliate_owner_for_payment($pdo, $invoice);
        if (!$attribution) return ['ok' => false, 'reason' => 'no_attribution'];

        $serviceKey = affiliate_service_for_invoice($invoice);
        $currency = currency_normalize((string)($payment['currency'] ?? $invoice['currency'] ?? $settings['payout_currency']));

        // A lawyer paying their own invoice, or a payment with no customer, is not a
        // referred sale. Self-referral is checked here as well as at binding time
        // because the binding is not the only way an attribution can exist.
        $affiliate = affiliate_find($pdo, (int)$attribution['affiliate_id']);
        if (!$affiliate) return ['ok' => false, 'reason' => 'no_affiliate'];

        $subjectType = (int)($attribution['user_id'] ?? 0) > 0 ? 'client' : 'lawyer';
        $subjectId = (int)($attribution['user_id'] ?? 0) > 0 ? (int)$attribution['user_id'] : (int)($attribution['lawyer_id'] ?? 0);
        if ($subjectId > 0) {
            $match = affiliate_check_self_referral($pdo, $affiliate, $subjectType, $subjectId);
            if ($match) {
                affiliate_flag($pdo, [
                    'affiliate_id' => (int)$affiliate['id'],
                    'flag_type' => 'self_referral', 'severity' => 'high',
                    'description' => 'دفعة على فاتورة #' . $invoiceId . ': ' . $match['detail'],
                    'signal_data' => $match['signal'],
                ]);
                return ['ok' => false, 'reason' => 'self_referral'];
            }
        }

        // Tax and refund context comes from the invoice, not the payment: the payment
        // is an instalment and carries no tax breakdown of its own.
        $result = affiliate_record_conversion($pdo, $attribution, [
            'service_key' => $serviceKey,
            'event_type' => 'payment',
            'event_key' => 'invoice_payment:' . $paymentId,
            'source_type' => 'invoice_payment',
            'source_id' => $paymentId,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'invoice_id' => $invoiceId,
            'payment_id' => $paymentId,
            'currency' => $currency,
            'gross' => (string)$payment['amount'],
            'tax' => (string)($invoice['tax_total'] ?? '0'),
            'subtotal' => (string)($invoice['subtotal'] ?? '0'),
            'refunded' => '0',
        ]);

        if (!empty($result['ok']) && empty($result['duplicate']) && !empty($result['commission_id'])) {
            affiliate_notify($pdo, (int)$affiliate['id'],
                'payment_converted:' . $paymentId, 'conversion_recorded',
                is_rtl() ? 'تم تسجيل تحويل من إحالة' : 'A referred payment was recorded',
                is_rtl() ? 'الخدمة: ' . $serviceKey : 'Service: ' . $serviceKey,
                '/affiliate/earnings.php');
        }
        return $result;
    } catch (Throwable $e) {
        // Never let the affiliate layer break billing.
        error_log('affiliate_handle_payment failed for invoice ' . $invoiceId . ' payment ' . $paymentId . ': ' . $e->getMessage());
        return ['ok' => false, 'reason' => 'exception'];
    }
}

/**
 * Handle a refund or reversal against an invoice.
 *
 * Called by whatever reverses a payment (an admin marking a refund, a gateway
 * chargeback). Finds the conversion created from that payment and reduces it.
 * A refund the platform never learns about cannot be handled here — which is why the
 * hold period exists as the structural defence.
 */
function affiliate_handle_payment_reversal(PDO $pdo, int $paymentId, $refundAmount): array {
    try {
        $settings = affiliate_settings($pdo);
        if (!$settings['program_enabled']) return ['ok' => false, 'reason' => 'program_disabled'];

        $stmt = $pdo->prepare('SELECT id FROM affiliate_conversions WHERE event_key=?');
        $stmt->execute(['invoice_payment:' . $paymentId]);
        $conversionId = $stmt->fetchColumn();
        if (!$conversionId) return ['ok' => false, 'reason' => 'no_conversion'];

        return affiliate_apply_refund($pdo, (int)$conversionId, $refundAmount);
    } catch (Throwable $e) {
        error_log('affiliate_handle_payment_reversal failed for payment ' . $paymentId . ': ' . $e->getMessage());
        return ['ok' => false, 'reason' => 'exception'];
    }
}

/**
 * The integration seam for finance_record_payment().
 *
 * A single named function so the hook in includes/finance.php is one line, is
 * greppable, and can be disabled by a deployment that does not want the programme by
 * leaving the table absent — the wrapper fails closed and returns.
 */
function affiliate_payment_hook(PDO $pdo, int $invoiceId, int $paymentId, array $options = []): void {
    // A payment that was itself recorded as a reversal (a negative adjustment) must
    // not create a commission.
    if (!empty($options['is_reversal'])) {
        affiliate_handle_payment_reversal($pdo, $paymentId, $options['refund_amount'] ?? 0);
        return;
    }
    affiliate_handle_payment($pdo, $invoiceId, $paymentId);
}
