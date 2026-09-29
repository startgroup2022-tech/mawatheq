<?php
/**
 * SADAD webhook endpoint — the trusted server-to-server notification.
 *
 * Independent of the browser return URL. Validates the request (signature when the operator
 * configured one), enforces replay protection, stores the event, then verifies server-side
 * before any invoice is marked paid. A webhook alone never marks an invoice paid: it triggers
 * a verification call to the gateway, and only a confirmed PAID state is recorded.
 *
 * Endpoint path is documented for operators; the gateway must be pointed at:
 *   https://<site>/sadad-webhook.php
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/gateway/bootstrap.php';
require_once __DIR__ . '/includes/gateway/payment_service.php';
require_once __DIR__ . '/includes/notifications.php';

header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input') ?: '';
$headers = gateway_request_headers();

// Resolve the tenant from the reference so per-tenant credentials are used for verification.
// The reference is embedded in the payload; we scan every configured tenant by matching the
// stored order reference, which is unique per payment attempt.
$probe = json_decode($raw, true);
$reference = '';
if (is_array($probe)) {
    foreach (['reference', 'orderReference', 'order_reference', 'merchantOrderReference'] as $k) {
        if (!empty($probe[$k]) && is_scalar($probe[$k])) { $reference = (string)$probe[$k]; break; }
    }
}

if ($reference === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_reference']);
    exit;
}

// Find the invoice this reference belongs to (any tenant) to select the right credentials.
$stmt = $pdo->prepare('SELECT * FROM invoices WHERE gateway_order_ref=? AND is_draft=0 LIMIT 1');
$stmt->execute([$reference]);
$invoice = $stmt->fetch();

if (!$invoice) {
    // Unknown reference: acknowledge so the gateway does not retry forever, but do nothing.
    http_response_code(200);
    echo json_encode(['ok' => true, 'ignored' => true]);
    exit;
}

$config = sadad_settings_for_invoice($pdo, $invoice);
$gateway = new SadadGateway($config);

if (!$gateway->isEnabled()) {
    error_log('SADAD webhook rejected: gateway not enabled/configured for this tenant.');
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'gateway_not_configured']);
    exit;
}

$event = $gateway->handleWebhook($headers, $raw);
if (empty($event['ok'])) {
    // Log the rejected event (best-effort) without trusting it.
    gateway_log_webhook_event($pdo, 'sadad', ['reference' => $reference], $raw, 'rejected', (string)($event['error'] ?? 'invalid'));
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $event['error'] ?? 'invalid']);
    exit;
}

// Replay protection: the raw body hash is unique per gateway.
$eventId = gateway_log_webhook_event($pdo, 'sadad', $event, $raw, 'received');
if ($eventId === null) {
    http_response_code(200);
    echo json_encode(['ok' => true, 'duplicate' => true]);
    exit;
}

// Server-side verification — never trust the webhook body's own claim of success.
$settle = gateway_verify_and_settle($pdo, $invoice, 'sadad', [
    'reference' => $event['reference'] ?: $reference,
    'amount' => $event['amount'],
    'currency' => $event['currency'],
    'source' => 'webhook',
]);

$status = $settle['recorded'] ? 'processed' : ($settle['duplicate'] ? 'duplicate' : 'ignored');
gateway_mark_webhook_event($pdo, $eventId, $status, $settle['error'] ?? $settle['state']);

http_response_code(200);
echo json_encode(['ok' => true, 'state' => $settle['state'], 'recorded' => $settle['recorded']]);
