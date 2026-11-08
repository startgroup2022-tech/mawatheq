<?php
/**
 * Test-only fake N-Genius KSA gateway.
 *
 * Serves documented-shaped responses so the suite exercises the REAL curl path in
 * includes/ngenius.php end-to-end (no HTTP mocking). Started by
 * tests/exec62_ngenius_payment_test.php:
 *   MAWATHEQ_NGENIUS_BASE_URL=http://127.0.0.1:<port> php -S 127.0.0.1:<port> tests/fixtures/ngenius_server.php
 *
 * It is never loaded by the application; it lives under tests/ and is referenced only by tests.
 * Behaviour is steered by the order reference so one server covers every outcome:
 *   ...-FAIL     -> payment state FAILED
 *   ...-PENDING  -> payment state PENDING (authorized, not captured)
 *   ...-EXPIRED  -> payment state EXPIRED
 *   default      -> payment state CAPTURED
 *
 * Endpoints mirror the live outlet contract discovered from its HATEOAS links:
 *   POST /identity/auth/access-token                       -> { access_token, expires_in }
 *   POST /transactions/outlets/{outlet}/orders             -> { reference, _links.payment.href }
 *   GET  /transactions/outlets/{outlet}/orders/{ref}       -> { _embedded.payment[0].state, ... }
 *   POST /transactions/outlets/{outlet}/orders/{ref}/refunds -> { reference, state }
 *   GET  /__health                                         -> { ok: true }
 */
header('Content-Type: application/json');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
$body = is_array($body) ? $body : [];

$storeFile = sys_get_temp_dir() . '/ngenius_fixture_orders.json';
$store = is_file($storeFile) ? (json_decode((string)file_get_contents($storeFile), true) ?: []) : [];
$save = function () use ($storeFile, &$store) {
    file_put_contents($storeFile, json_encode($store), LOCK_EX);
};

if ($method === 'GET' && $path === '/__health') { echo json_encode(['ok' => true]); exit; }

// --- token -------------------------------------------------------------------
if ($path === '/identity/auth/access-token') {
    // The live gateway requires a JSON *object* body. json_encode([]) in PHP emits '[]' (an
    // array), which the live gateway rejects with a BARE 400 — no access_token and no reason —
    // silently disabling every payment path. Reproduce that here so the regression fails in
    // the suite instead of only in production. A raw body of '[]' (or any non-object) is
    // refused exactly as the live gateway refuses it.
    $trimmed = ltrim($raw);
    if ($trimmed === '' || $trimmed[0] !== '{') {
        http_response_code(400);
        echo json_encode(['message' => 'Bad Request', 'code' => 400]);
        exit;
    }
    // The live gateway rejects a body carrying a realmName; the fixture reproduces that so a
    // regression to the old hard-coded 'ni' fails here instead of silently in production.
    if (isset($body['realmName']) && $body['realmName'] !== '') {
        http_response_code(404);
        echo json_encode(['message' => 'Not Found', 'code' => 404,
            'errors' => [['errorCode' => 'realmNameNotAvailable']]]);
        exit;
    }
    echo json_encode(['access_token' => 'fixture-token-' . bin2hex(random_bytes(4)),
                      'expires_in' => 300, 'token_type' => 'Bearer']);
    exit;
}

// --- create order ------------------------------------------------------------
if ($method === 'POST' && preg_match('#^/transactions/outlets/[^/]+/orders$#', $path)) {
    $reference = (string)($body['merchantOrderReference'] ?? ('ORD-' . bin2hex(random_bytes(4))));
    $store[$reference] = [
        'amount' => (int)($body['amount']['value'] ?? 0),
        'currency' => (string)($body['amount']['currencyCode'] ?? 'SAR'),
        'state' => strpos($reference, 'FAIL') !== false ? 'FAILED'
                 : (strpos($reference, 'PENDING') !== false ? 'PENDING'
                 : (strpos($reference, 'EXPIRED') !== false ? 'EXPIRED' : 'CAPTURED')),
    ];
    $save();
    $base = 'http://' . ($_SERVER['HTTP_HOST'] ?? '127.0.0.1');
    echo json_encode([
        'reference' => $reference,
        '_links' => ['payment' => ['href' => $base . '/hosted/' . $reference]],
    ]);
    exit;
}

// --- refund ------------------------------------------------------------------
if ($method === 'POST' && preg_match('#^/transactions/outlets/[^/]+/orders/(.+)/refunds$#', $path, $m)) {
    $ref = urldecode($m[1]);
    $stored = $store[$ref] ?? null;
    if ($stored === null || $stored['state'] !== 'CAPTURED') {
        http_response_code(400);
        echo json_encode(['errors' => [['message' => 'Order is not refundable']]]);
        exit;
    }
    echo json_encode(['reference' => 'REF-' . substr(md5($ref), 0, 12), 'state' => 'REFUNDED']);
    exit;
}

// --- order status ------------------------------------------------------------
if ($method === 'GET' && preg_match('#^/transactions/outlets/[^/]+/orders/(.+)$#', $path, $m)) {
    $ref = urldecode($m[1]);
    // Derive the state from the reference name when the order was never created through this
    // fixture, so a test that stamps a reference directly still gets the outcome it names.
    $state = strpos($ref, 'FAIL') !== false ? 'FAILED'
           : (strpos($ref, 'PENDING') !== false ? 'PENDING'
           : (strpos($ref, 'EXPIRED') !== false ? 'EXPIRED' : 'CAPTURED'));
    $stored = $store[$ref] ?? ['amount' => 10000, 'currency' => 'SAR', 'state' => $state];
    echo json_encode([
        '_embedded' => ['payment' => [[
            'reference' => 'PAY-' . substr(md5($ref), 0, 12),
            'state' => $stored['state'],
            'amount' => ['currencyCode' => $stored['currency'], 'value' => $stored['amount']],
        ]]],
    ]);
    exit;
}

http_response_code(404);
echo json_encode(['message' => 'Not Found']);
