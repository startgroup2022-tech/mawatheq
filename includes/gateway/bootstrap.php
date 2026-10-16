<?php
/**
 * Gateway bootstrap — registers the available adapters and exposes per-invoice resolution.
 *
 * Included by config/bootstrap paths that need gateways. Registration is idempotent, so it is
 * safe to require this from several entry points.
 */
require_once __DIR__ . '/contract.php';
require_once __DIR__ . '/sadad/gateway.php';
require_once __DIR__ . '/ngenius/gateway.php';

/** Register the built-in adapters (once). */
function gateway_bootstrap(?PDO $pdo = null): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = $pdo ?: ($GLOBALS['pdo'] ?? null);

    GatewayRegistry::register('sadad', function () use ($pdo) {
        $config = $pdo instanceof PDO ? sadad_settings($pdo, 'platform', 0) : sadad_default_template();
        return new SadadGateway($config);
    });
    GatewayRegistry::register('ngenius', function () use ($pdo) {
        return $pdo instanceof PDO ? new NGeniusGateway($pdo) : new NGeniusGateway($GLOBALS['pdo']);
    });
}

/** Resolve a gateway adapter by id. */
function gateway_get(string $id, ?PDO $pdo = null): ?PaymentGateway {
    gateway_bootstrap($pdo);
    return GatewayRegistry::get($id);
}

/** All registered gateways, keyed by id. */
function gateway_all(?PDO $pdo = null): array {
    gateway_bootstrap($pdo);
    return GatewayRegistry::all();
}

/**
 * Resolve the adapter for an invoice's owning tenant. SADAD credentials are per-tenant, so this
 * builds a fresh SADAD adapter bound to that tenant rather than the shared registry instance.
 */
function gateway_for_invoice(PDO $pdo, array $invoice): ?PaymentGateway {
    $gateway = (string)($invoice['gateway'] ?? '');
    $id = $gateway !== '' ? $gateway : 'ngenius';
    if ($id === 'sadad') {
        return new SadadGateway(sadad_settings_for_invoice($pdo, $invoice));
    }
    return gateway_get($id, $pdo);
}

/**
 * The enabled gateway that should handle a new payment for this invoice, or null.
 * Prefers the gateway already attached to the invoice; otherwise the first enabled one whose
 * configured currency matches the invoice.
 */
function gateway_for_new_payment(PDO $pdo, array $invoice): ?PaymentGateway {
    if (!empty($invoice['gateway'])) {
        $existing = gateway_for_invoice($pdo, $invoice);
        if ($existing && $existing->isEnabled() && $existing->supportsCurrency($invoice['currency'] ?? null)) {
            return $existing;
        }
    }
    foreach (['sadad', 'ngenius'] as $id) {
        $g = $id === 'sadad'
            ? new SadadGateway(sadad_settings_for_invoice($pdo, $invoice))
            : gateway_get('ngenius', $pdo);
        if ($g && $g->isEnabled() && $g->supportsCurrency($invoice['currency'] ?? null)) return $g;
    }
    return null;
}

/** Extract lower-cased request headers in a SAPI-independent way. */
function gateway_request_headers(): array {
    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (strpos($key, 'HTTP_') === 0) {
            $name = strtolower(str_replace('_', '-', substr($key, 5)));
            $headers[$name] = (string)$value;
        }
    }
    if (isset($_SERVER['CONTENT_TYPE'])) $headers['content-type'] = (string)$_SERVER['CONTENT_TYPE'];
    return $headers;
}

/**
 * Record an inbound webhook event for audit + replay protection.
 * Returns the inserted event id, or null when the event hash was already seen (a replay).
 */
function gateway_log_webhook_event(PDO $pdo, string $gateway, array $event, string $rawBody, string $status = 'received', ?string $message = null, ?int $paymentId = null): ?int {
    $hash = hash('sha256', $rawBody);
    try {
        $pdo->prepare('INSERT INTO gateway_webhook_events
            (gateway, event_id, event_type, reference, payment_id, processing_status, message, event_hash, raw_payload, processed_at)
            VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                $gateway, $event['event_id'] ?? null, $event['event_type'] ?? null,
                $event['reference'] ?? null, $paymentId, $status, $message, $hash, $rawBody,
                $status === 'received' ? null : date('Y-m-d H:i:s'),
            ]);
        return (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? 0) === 1062) return null; // replay
        error_log('Webhook event log failed: ' . $e->getMessage());
        return null;
    }
}

/** Mark a previously logged webhook event's outcome. */
function gateway_mark_webhook_event(PDO $pdo, int $eventId, string $status, ?string $message = null, ?int $paymentId = null): void {
    try {
        $pdo->prepare('UPDATE gateway_webhook_events SET processing_status=?, message=?, payment_id=COALESCE(?, payment_id), processed_at=? WHERE id=?')
            ->execute([$status, $message, $paymentId, date('Y-m-d H:i:s'), $eventId]);
    } catch (PDOException $e) {
        error_log('Webhook event update failed: ' . $e->getMessage());
    }
}
