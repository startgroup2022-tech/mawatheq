<?php
/**
 * Test-only fake SADAD server.
 *
 * Serves documented-shaped responses so tests can exercise the REAL SadadService curl path
 * end-to-end (no HTTP mocks). Started by tests/sadad_gateway_test.php via PHP's built-in server:
 *   php -S 127.0.0.1:<port> tests/fixtures/sadad_server.php
 *
 * It is never loaded by the application; it lives under tests/ and is referenced only by tests.
 * Contract (deliberately simple, mirrors the configurable field mapping):
 *   POST /pay                        -> { reference, status, payment_url, transaction_id }
 *   GET  /order/{reference}          -> { reference, status, amount, currency, transaction_id }
 *   POST /refund                     -> { reference, status }
 *   GET  /__health                   -> { ok: true }   (connectivity probe target)
 *
 * Behaviour is steered by the reference so a single server covers success/failure/pending:
 *   ...-FAIL     -> status FAILED
 *   ...-PENDING  -> status PENDING
 *   ...-DUP      -> same reference echoed (idempotency test)
 *   default      -> status PAID
 */
header('Content-Type: application/json');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
$body = is_array($body) ? $body : [];

$reference = (string)($body['orderReference'] ?? '');

// Persist created orders so verification returns the amount that was actually charged (a real
// gateway behaves this way; a fixed amount would make partial-payment logic untestable).
$storeFile = sys_get_temp_dir() . '/sadad_fixture_orders.json';
$store = is_file($storeFile) ? (json_decode((string)file_get_contents($storeFile), true) ?: []) : [];

// GET /order/{reference}  and  GET /__health
if ($method === 'GET' && preg_match('#^/order/(.+)$#', $path, $m)) {
    $reference = urldecode($m[1]);
    $stored = $store[$reference] ?? null;
    $amount = $stored['amount'] ?? 42.50;
    echo json_encode([
        'reference' => $reference,
        'status' => strpos($reference, 'FAIL') !== false ? 'DECLINED'
                   : (strpos($reference, 'PENDING') !== false ? 'PENDING' : 'CAPTURED'),
        'amount' => $amount,
        'currency' => $stored['currency'] ?? 'SAR',
        'transaction_id' => 'TXN-' . substr(md5($reference), 0, 12),
    ]);
    exit;
}
if ($method === 'GET' && $path === '/__health') {
    echo json_encode(['ok' => true]);
    exit;
}

if ($method === 'POST' && $path === '/refund') {
    echo json_encode(['reference' => $reference, 'status' => 'REFUNDED']);
    exit;
}

if ($method === 'POST' && $path === '/pay') {
    if ($reference === '') { http_response_code(400); echo json_encode(['error' => 'missing_reference']); exit; }
    if (strpos($reference, 'BAD') !== false) { http_response_code(400); echo json_encode(['error' => 'rejected']); exit; }
    $store[$reference] = ['amount' => (float)($body['amount'] ?? 0), 'currency' => (string)($body['currency'] ?? 'SAR')];
    file_put_contents($storeFile, json_encode($store));
    echo json_encode([
        'reference' => $reference,
        'status' => 'REDIRECTED',
        'payment_url' => 'https://sandbox.example/sadad/' . rawurlencode($reference),
        'transaction_id' => 'TXN-' . substr(md5($reference), 0, 12),
    ]);
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'not_found', 'path' => $path]);
