<?php
/**
 * Consultation payment orchestration (Execution 62).
 *
 * A consultation is not an invoice, so it cannot go through the invoice finance core.
 * This module gives it the same shape the invoice path already has:
 *
 *   create order → hosted payment page → (webhook / return) → independent server-side
 *   verification → confirm the booking → credit the lawyer wallet.
 *
 * The one rule that matters, and the reason this file exists rather than a few lines in
 * booking.php: **a booking is never confirmed because the customer came back to a success
 * URL.** The gateway is re-queried, and only its own answer confirms the consultation. A
 * failed, cancelled, expired or merely pending payment leaves the booking unconfirmed and
 * releases the reserved slot.
 *
 * Idempotency is layered exactly as the invoice path does it:
 *   - consultations.payment_reference is UNIQUE, so one order can confirm one booking;
 *   - payment_transactions.event_hash is UNIQUE, so a replayed webhook is a no-op;
 *   - the wallet credit is idempotent on the consultation id.
 */
require_once __DIR__ . '/ngenius.php';
require_once __DIR__ . '/finance.php';

/** True when the consultation columns from upgrade_v69.sql are present. */
function consultation_payment_schema_ready(PDO $pdo): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $ready = (bool)$pdo->query("SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consultations'
              AND COLUMN_NAME = 'payment_status' LIMIT 1")->fetchColumn();
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

/** A consultation needs paying only when it has a positive price and payment is enabled. */
function consultation_payment_required(array $consultation): bool {
    return isset($consultation['price']) && (float)$consultation['price'] > 0;
}

/**
 * A priced consultation may not be confirmed until the gateway has verified its payment.
 *
 * This is the server-side half of "payment is required before a paid consultation is
 * confirmed". The booking page already routes a priced booking through the gateway, but a
 * lawyer could otherwise press "Confirm" on the still-unpaid request (or call the API's
 * change-status action) and turn it into a confirmed appointment without money moving.
 * The customer's payment_status is the only authority here; a free booking is unaffected.
 */
function consultation_payment_blocks_confirmation(array $consultation): bool {
    if (!consultation_payment_required($consultation)) return false;
    return (string)($consultation['payment_status'] ?? 'unpaid') !== 'paid';
}

/** Stable gateway reference for a consultation payment attempt (unique per attempt). */
function consultation_payment_reference(int $consultationId): string {
    return 'CONS-' . $consultationId . '-' . strtoupper(bin2hex(random_bytes(5)));
}

/** The browser return URL for a consultation payment. */
function consultation_payment_return_url(int $consultationId): string {
    return rtrim(SITE_URL, '/') . '/consultation-payment-return.php?consultation_id=' . $consultationId;
}

/**
 * Start a payment for a consultation: create the gateway order and return where to send
 * the customer. The consultation is marked 'pending' — never 'paid' — and only after the
 * gateway confirms the capture is it confirmed and the lawyer's wallet credited.
 *
 * @return array{ok:bool, redirect_url?:string, reference?:string, gateway?:string, error?:string}
 */
function consultation_payment_start(PDO $pdo, array $consultation, array $options = []): array {
    if (!consultation_payment_schema_ready($pdo)) {
        return ['ok' => false, 'error' => 'migration_required'];
    }
    $settings = ngenius_settings($pdo);
    if (!ngenius_is_enabled($settings)) {
        return ['ok' => false, 'error' => 'no_enabled_gateway'];
    }
    // The consultation price is stored in the site currency (consultations has no currency
    // column). The gateway charges in the outlet's settlement currency. If the two differ we
    // would send the same numeric value as a different currency — a mis-charge — so the
    // payment is refused rather than silently wrong. (Comparing the outlet currency to itself
    // would make this guard unfailable; it must compare the price's currency to the outlet.)
    $siteCurrency = strtoupper(currency_code());
    $currency = ngenius_currency($settings);
    if (!ngenius_supports_currency($settings, $siteCurrency)) {
        return ['ok' => false, 'error' => 'unsupported_currency'];
    }
    $amount = (float)$consultation['price'];
    if ($amount <= 0) return ['ok' => false, 'error' => 'zero_amount'];

    $consultationId = (int)$consultation['id'];
    $reference = consultation_payment_reference($consultationId);

    $tokenResult = ngenius_get_access_token(
        $settings['ngenius_environment'], $settings['ngenius_api_key'], $settings['ngenius_realm_name'] ?? ''
    );
    if (empty($tokenResult['ok'])) {
        return ['ok' => false, 'error' => $tokenResult['error']];
    }

    $order = ngenius_create_order(
        $settings['ngenius_environment'],
        $settings['ngenius_outlet_ref'],
        $tokenResult['token'],
        $amount,
        (string)($options['customer_email'] ?? ''),
        (string)($options['customer_name'] ?? ''),
        $options['return_url'] ?? consultation_payment_return_url($consultationId),
        $options['cancel_url'] ?? (rtrim(SITE_URL, '/') . '/consultations.php'),
        $reference,
        (string)($options['language'] ?? 'ar'),
        $currency
    );

    // Record the attempt. The reference is unique, so a retry after a crash is the only
    // thing that can produce a second row for the same order, and that row is harmless.
    try {
        $pdo->prepare('INSERT INTO payment_transactions
            (consultation_id, gateway, gateway_order_ref, event_type, amount, currency, status, environment, response_message)
            VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([
                $consultationId, 'ngenius', $order['order_reference'] ?? $reference, 'ORDER_CREATED',
                $amount, $currency, $order['ok'] ? 'INITIATED' : 'FAILED',
                $settings['ngenius_environment'], $order['ok'] ? null : ($order['error'] ?? null),
            ]);
    } catch (Throwable $e) {
        error_log('consultation payment log failed: ' . $e->getMessage());
    }

    if (empty($order['ok'])) {
        $pdo->prepare("UPDATE consultations SET payment_status='failed' WHERE id=?")
            ->execute([$consultationId]);
        return ['ok' => false, 'error' => $order['error'] ?? 'gateway_error', 'gateway' => 'ngenius'];
    }

    $pdo->prepare("UPDATE consultations SET payment_status='pending', payment_reference=?, payment_amount=?, payment_currency=?, payment_gateway='ngenius' WHERE id=?")
        ->execute([$order['order_reference'], $amount, $currency, $consultationId]);

    return ['ok' => true, 'redirect_url' => (string)$order['payment_url'], 'reference' => (string)$order['order_reference'], 'gateway' => 'ngenius'];
}

/**
 * Independently verify a consultation payment against the gateway and, only when the
 * gateway confirms the capture, confirm the booking and credit the lawyer's wallet.
 *
 * Safe to call from both the return page and the webhook: the confirmation update is
 * guarded on the current status, so the second caller is a no-op.
 *
 * @return array{ok:bool, state:string, confirmed:bool, error:?string}
 */
function consultation_payment_verify_and_confirm(PDO $pdo, int $consultationId, ?string $orderRef = null): array {
    if (!consultation_payment_schema_ready($pdo)) {
        return ['ok' => false, 'state' => 'PENDING', 'confirmed' => false, 'error' => 'migration_required'];
    }
    $stmt = $pdo->prepare('SELECT * FROM consultations WHERE id=?');
    $stmt->execute([$consultationId]);
    $consultation = $stmt->fetch();
    if (!$consultation) return ['ok' => false, 'state' => 'PENDING', 'confirmed' => false, 'error' => 'not_found'];

    $reference = $orderRef ?: (string)($consultation['payment_reference'] ?? '');
    if ($reference === '') return ['ok' => false, 'state' => 'PENDING', 'confirmed' => false, 'error' => 'no_reference'];

    $settings = ngenius_settings($pdo);
    if (!ngenius_is_enabled($settings)) {
        return ['ok' => false, 'state' => 'PENDING', 'confirmed' => false, 'error' => 'gateway_disabled'];
    }
    $tokenResult = ngenius_get_access_token(
        $settings['ngenius_environment'], $settings['ngenius_api_key'], $settings['ngenius_realm_name'] ?? ''
    );
    if (empty($tokenResult['ok'])) {
        return ['ok' => false, 'state' => 'PENDING', 'confirmed' => false, 'error' => $tokenResult['error']];
    }
    $status = ngenius_get_order_status(
        $settings['ngenius_environment'], $settings['ngenius_outlet_ref'], $tokenResult['token'], $reference
    );
    if (empty($status['ok'])) {
        return ['ok' => false, 'state' => 'PENDING', 'confirmed' => false, 'error' => $status['error']];
    }

    $state = gateway_normalize_state($status['state']);
    $isPaid = gateway_is_paid_state($state);

    // Log the verification. A unique event hash keeps a replayed verify from double-logging.
    try {
        $pdo->prepare('INSERT INTO payment_transactions
            (consultation_id, gateway, gateway_order_ref, event_type, event_hash, amount, currency, status, environment, verified_at)
            VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                $consultationId, 'ngenius', $reference, 'PAYMENT_VERIFIED',
                hash('sha256', 'verify|' . $reference . '|' . $state),
                $status['amount'], $status['currency'] ?: ngenius_currency($settings), $state,
                $settings['ngenius_environment'], date('Y-m-d H:i:s'),
            ]);
    } catch (PDOException $e) {
        // duplicate verification event — fine, continue to the confirmation guard
    }

    if (!$isPaid) {
        // A definitive failure releases the slot; a pending one leaves it reserved.
        // The payment_status enum is ('unpaid','pending','paid','refunded','failed'), so a
        // cancelled or expired order maps to 'failed' — money never moved, which is exactly
        // what 'failed' means here. Writing the raw state ('expired') would be truncated.
        if (in_array($state, ['FAILED', 'CANCELLED', 'EXPIRED'], true)) {
            $pdo->prepare("UPDATE consultations SET payment_status='failed' WHERE id=? AND payment_status='pending'")
                ->execute([$consultationId]);
        }
        return ['ok' => true, 'state' => $state, 'confirmed' => false, 'error' => null];
    }

    return consultation_payment_confirm($pdo, $consultation, $status);
}

/**
 * Confirm a booking whose payment the gateway has verified. Guarded so it can run at most
 * once per consultation: the UPDATE only matches a row that is not already 'paid'.
 */
function consultation_payment_confirm(PDO $pdo, array $consultation, array $status): array {
    $consultationId = (int)$consultation['id'];
    $amount = $status['amount'] !== null ? (float)$status['amount'] : (float)$consultation['payment_amount'];
    $currency = (string)($status['currency'] ?: $consultation['payment_currency'] ?: currency_code());

    // Confirm atomically. The status guard means the return page and the webhook cannot both
    // confirm the same booking, and `status <> 'cancelled'` means a booking the release path
    // already gave up (its slot returned to availability) is never revived by a late capture —
    // a paid-but-released booking must be reconciled by a human, not silently re-confirmed
    // onto a slot another customer may now hold.
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) $pdo->beginTransaction();
    try {
        $upd = $pdo->prepare("UPDATE consultations
            SET payment_status='paid', status='confirmed', payment_amount=?, payment_currency=?
            WHERE id=? AND payment_status <> 'paid' AND status <> 'cancelled'");
        $upd->execute([$amount, $currency, $consultationId]);
        $changed = $upd->rowCount();
        if ($ownTransaction) $pdo->commit();
    } catch (Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
        error_log('consultation confirm failed: ' . $e->getMessage());
        return ['ok' => false, 'state' => 'PAID', 'confirmed' => false, 'error' => 'confirm_failed'];
    }

    // The row was already paid, or it was cancelled: do not credit again.
    if ($changed === 0) {
        return ['ok' => true, 'state' => 'PAID', 'confirmed' => false, 'error' => null];
    }

    require_once __DIR__ . '/wallet.php';
    try {
        wallet_credit_consultation_payment($pdo, $consultationId);
    } catch (Throwable $e) {
        // The booking is confirmed and paid; a wallet problem must not undo that.
        error_log('consultation wallet credit failed: ' . $e->getMessage());
    }

    if (is_file(__DIR__ . '/audit_log.php')) {
        require_once __DIR__ . '/audit_log.php';
        if (function_exists('log_action')) log_action($pdo, 'تأكيد دفعة استشارة (ngenius)', 'consultation', $consultationId);
    }

    require_once __DIR__ . '/notifications.php';
    notify($pdo, 'lawyer', (int)$consultation['lawyer_id'],
        is_rtl() ? 'تم تأكيد استشارة مدفوعة' : 'Paid consultation confirmed',
        (string)($consultation['topic'] ?? ''), '/lawyer/consultations.php');

    return ['ok' => true, 'state' => 'PAID', 'confirmed' => true, 'error' => null];
}

/**
 * Release a consultation whose payment did not complete: cancel it so the reserved slot
 * becomes bookable again. Only an unpaid, still-pending booking is released — a paid or
 * already-cancelled one is left alone.
 */
function consultation_payment_release(PDO $pdo, int $consultationId, string $reason = 'expired'): bool {
    if (!consultation_payment_schema_ready($pdo)) return false;
    $stmt = $pdo->prepare("UPDATE consultations
        SET status='cancelled', payment_status='failed'
        WHERE id=? AND payment_status <> 'paid' AND status='pending'");
    $stmt->execute([$consultationId]);
    if ($stmt->rowCount() > 0) {
        error_log("consultation $consultationId released ($reason): slot returned to availability");
        return true;
    }
    return false;
}

/**
 * Find a consultation by its gateway order reference, scoped to the gateway that owns it.
 * Returns null for an unknown reference — a webhook must never guess.
 */
function consultation_payment_find_by_reference(PDO $pdo, string $orderRef): ?array {
    if (!consultation_payment_schema_ready($pdo)) return null;
    $stmt = $pdo->prepare('SELECT * FROM consultations WHERE payment_reference = ? LIMIT 1');
    $stmt->execute([$orderRef]);
    $row = $stmt->fetch();
    return $row ?: null;
}
