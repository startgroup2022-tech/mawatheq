<?php
/**
 * Payment orchestration service.
 *
 * The one place that turns a gateway interaction into a financial record. It does NOT touch the
 * invoice totals or the finance rules: settlement is delegated to the existing
 * finance_record_payment(), which already owns balance validation, row locking and idempotency.
 *
 * Flow: Invoice → Create Payment → Redirect → (callback/webhook) → server-side Verify →
 * finance_record_payment → Update invoice → Audit log.
 *
 * Idempotency is layered:
 *   - the gateway reference is the finance idempotency key, so a repeated event cannot create a
 *     second invoice_payments row;
 *   - the raw event hash is unique in payment_transactions, so a replayed webhook is a no-op.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../finance.php';
require_once __DIR__ . '/../audit_log.php';

/** Build a stable, unique gateway reference for an invoice payment attempt. */
function gateway_new_reference(string $gatewayId, int $invoiceId): string {
    return strtoupper($gatewayId) . '-' . $invoiceId . '-' . strtoupper(bin2hex(random_bytes(5)));
}

/** The browser return URL for a gateway (each provider may have its own return page). */
function gateway_return_url(string $gatewayId, int $invoiceId): string {
    if (strtolower($gatewayId) === 'sadad') return sadad_return_url($invoiceId);
    return rtrim(SITE_URL, '/') . '/payment-return.php?invoice_id=' . $invoiceId;
}

/**
 * Log a gateway event into payment_transactions. Returns false when it is a duplicate (the
 * unique event_hash already exists) — the caller must then skip reprocessing.
 */
function gateway_log_transaction(PDO $pdo, array $row): bool {
    $hash = null;
    if (!empty($row['event_hash'])) {
        $hash = (string)$row['event_hash'];
    } elseif (!empty($row['raw_payload'])) {
        $hash = hash('sha256', (string)$row['raw_payload']);
    }
    try {
        $pdo->prepare('INSERT INTO payment_transactions
            (invoice_id, firm_id, gateway, gateway_order_ref, gateway_transaction_id, event_type, event_hash,
             amount, currency, status, environment, response_code, response_message, callback_received_at, verified_at, metadata, raw_payload)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                $row['invoice_id'] ?? null, $row['firm_id'] ?? null, $row['gateway'] ?? null,
                $row['gateway_order_ref'] ?? null, $row['gateway_transaction_id'] ?? null,
                $row['event_type'] ?? null, $hash, $row['amount'] ?? null, $row['currency'] ?? null,
                $row['status'] ?? null, $row['environment'] ?? null, $row['response_code'] ?? null,
                $row['response_message'] ?? null, $row['callback_received_at'] ?? null,
                $row['verified_at'] ?? null, $row['metadata'] ?? null, $row['raw_payload'] ?? null,
            ]);
        return true;
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? 0) === 1062) return false; // duplicate event
        error_log('Gateway transaction log failed: ' . $e->getMessage());
        return true; // logging must not break the flow
    }
}

/**
 * Start a payment for an invoice through the resolved gateway.
 * @return array{ok:bool, redirect_url?:string, reference?:string, gateway?:string, error?:string}
 */
function gateway_start_invoice_payment(PDO $pdo, array $invoice, array $options = []): array {
    $gateway = $options['gateway'] ?? null;
    if (is_string($gateway) && $gateway !== '') {
        $adapter = $gateway === 'sadad' ? new SadadGateway(sadad_settings_for_invoice($pdo, $invoice)) : gateway_get($gateway, $pdo);
    } else {
        $adapter = gateway_for_new_payment($pdo, $invoice);
    }
    if (!$adapter) return ['ok' => false, 'error' => 'no_enabled_gateway'];
    if (!$adapter->isEnabled()) return ['ok' => false, 'error' => 'gateway_disabled'];
    if (!$adapter->supportsCurrency($invoice['currency'] ?? null)) return ['ok' => false, 'error' => 'unsupported_currency'];

    $invoiceId = (int)$invoice['id'];
    $reference = gateway_new_reference($adapter->id(), $invoiceId);
    $amount = finance_invoice_balance($invoice);

    $intent = [
        'amount' => $amount,
        'currency' => strtoupper((string)($invoice['currency'] ?? finance_currency())),
        'invoice_id' => $invoiceId,
        'reference' => $reference,
        'description' => (string)($invoice['description'] ?? ''),
        'language' => $options['language'] ?? 'ar',
        'customer' => [
            'name' => (string)($options['customer_name'] ?? $invoice['full_name'] ?? ''),
            'email' => (string)($options['customer_email'] ?? $invoice['email'] ?? ''),
            'phone' => (string)($options['customer_phone'] ?? ''),
        ],
        'urls' => [
            'return' => $options['return_url'] ?? gateway_return_url($adapter->id(), $invoiceId),
            'cancel' => $options['cancel_url'] ?? rtrim(SITE_URL, '/') . '/invoices.php',
            'webhook' => sadad_webhook_url(),
        ],
    ];

    $result = $adapter->createPayment($intent);

    gateway_log_transaction($pdo, [
        'invoice_id' => $invoiceId, 'firm_id' => $invoice['firm_id'] ?? null,
        'gateway' => $adapter->id(), 'gateway_order_ref' => $result['reference'] ?? $reference,
        'gateway_transaction_id' => $result['transaction_id'] ?? null,
        // Keep the ORDER_CREATED event name: the existing N-Genius return/webhook/status endpoints
        // read the charged amount back from this row, so renaming it would break their partial-payment
        // reconciliation. Both gateways use it for the creation record.
        'event_type' => 'ORDER_CREATED',
        'amount' => $amount, 'currency' => $intent['currency'],
        'status' => $result['state'] ?? ($result['ok'] ? 'INITIATED' : 'FAILED'),
        'environment' => method_exists($adapter, 'environment') ? $adapter->environment() : null,
        'response_message' => $result['ok'] ? null : (string)($result['error'] ?? 'unknown'),
    ]);

    if (empty($result['ok'])) {
        log_action($pdo, 'فشل إنشاء عملية دفع (' . $adapter->id() . ')', 'invoice', $invoiceId);
        return ['ok' => false, 'error' => $result['error'] ?? 'gateway_error', 'gateway' => $adapter->id()];
    }

    $pdo->prepare('UPDATE invoices SET gateway=?, gateway_order_ref=? WHERE id=?')
        ->execute([$adapter->id(), $result['reference'], $invoiceId]);
    log_action($pdo, 'إنشاء عملية دفع (' . $adapter->id() . ')', 'invoice', $invoiceId);

    return ['ok' => true, 'redirect_url' => (string)($result['redirect_url'] ?? ''), 'reference' => (string)$result['reference'], 'gateway' => $adapter->id()];
}

/**
 * Verify a payment server-side and, when the gateway confirms it, record the payment.
 * Idempotent: safe to call from both the return page and the webhook.
 *
 * @param array $payment ['reference'=>string, 'amount'=>?float, 'currency'=>?string, 'source'=>string]
 * @return array{ok:bool, state:string, recorded:bool, duplicate:bool, error:?string}
 */
function gateway_verify_and_settle(PDO $pdo, array $invoice, string $gatewayId, array $payment): array {
    $adapter = $gatewayId === 'sadad'
        ? new SadadGateway(sadad_settings_for_invoice($pdo, $invoice))
        : gateway_get($gatewayId, $pdo);
    if (!$adapter) return ['ok' => false, 'state' => 'PENDING', 'recorded' => false, 'duplicate' => false, 'error' => 'unknown_gateway'];

    $reference = (string)($payment['reference'] ?? '');
    $verify = $adapter->verifyPayment([
        'reference' => $reference,
        'invoice_id' => (int)$invoice['id'],
        'amount' => $payment['amount'] ?? null,
        'currency' => $payment['currency'] ?? ($invoice['currency'] ?? null),
    ]);

    $state = gateway_normalize_state($verify['state'] ?? 'PENDING');
    $verifiedAt = date('Y-m-d H:i:s');

    gateway_log_transaction($pdo, [
        'invoice_id' => (int)$invoice['id'], 'firm_id' => $invoice['firm_id'] ?? null,
        'gateway' => $gatewayId, 'gateway_order_ref' => $reference,
        'event_type' => 'PAYMENT_VERIFIED', 'amount' => $verify['amount'] ?? null,
        'currency' => $verify['currency'] ?? ($invoice['currency'] ?? null), 'status' => $state,
        'environment' => method_exists($adapter, 'environment') ? $adapter->environment() : null,
        'response_message' => $verify['error'] ?? null, 'verified_at' => $verifiedAt,
    ]);

    if (!gateway_is_paid_state($state)) {
        return ['ok' => !empty($verify['ok']), 'state' => $state, 'recorded' => false, 'duplicate' => false, 'error' => $verify['error'] ?? null];
    }

    // Prefer the amount the gateway actually confirms; fall back to the outstanding balance.
    $amount = isset($verify['amount']) && $verify['amount'] !== null ? (float)$verify['amount'] : finance_invoice_balance($invoice);
    $recorded = finance_record_payment($pdo, (int)$invoice['id'], $amount, [
        'method' => $gatewayId,
        'reference' => $reference,
        'idempotency_key' => $gatewayId . ':' . $reference,
        'recorded_by_type' => 'system',
        'notes' => $adapter->label() . ' ' . ($payment['source'] ?? 'verify') . ': ' . $state,
    ]);

    if (!empty($recorded['ok']) && empty($recorded['duplicate'])) {
        log_action($pdo, 'تأكيد دفعة إلكترونية (' . $gatewayId . ')', 'invoice', (int)$invoice['id']);
        if ($gatewayId === 'sadad') {
            [$tType, $tId] = sadad_tenant_for_invoice($invoice);
            sadad_touch_last_success($pdo, sadad_settings($pdo, $tType, $tId), $tType, $tId);
        }
        if (function_exists('notify')) {
            notify($pdo, 'lawyer', $invoice['lawyer_id'],
                is_rtl() ? 'تم استلام دفعة إلكترونية' : 'Online payment received',
                (string)($invoice['description'] ?? ''), '/lawyer/invoices.php');
        }
        // Settlement is the accounting-relevant moment: emit it once, only when the
        // payment was actually recorded (not on a duplicate replay of the same event).
        require_once __DIR__ . '/../integrations.php';
        integration_emit_quietly($pdo, isset($invoice['firm_id']) && $invoice['firm_id'] !== null ? (int)$invoice['firm_id'] : null, 'invoice.paid', [
            'invoice_id' => (int)$invoice['id'], 'amount' => $amount,
            'currency' => (string)($invoice['currency'] ?? 'SAR'), 'gateway' => $gatewayId,
            'reference' => $reference, 'lawyer_id' => (int)($invoice['lawyer_id'] ?? 0),
            'client_id' => (int)($invoice['user_id'] ?? 0),
        ]);
    }

    return [
        'ok' => !empty($recorded['ok']),
        'state' => $state,
        'recorded' => !empty($recorded['ok']) && empty($recorded['duplicate']),
        'duplicate' => !empty($recorded['duplicate']),
        'error' => $recorded['error'] ?? null,
    ];
}
