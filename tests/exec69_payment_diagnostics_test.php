<?php
/**
 * اختبار تشخيص بوابة الدفع — Execution 69
 *
 * Pins the defect the owner reported: the customer saw
 *
 *   «تعذّر بدء عملية الدفع الإلكتروني حالياً. لم يتم خصم أي مبلغ.»
 *
 * with no reference and no diagnosis, on a production N-Genius payment. That is
 * the *transient* sentence, and it was shown for **every** order failure because
 * `gateway_start_invoice_payment()` overwrote the adapter's classified reason
 * with the literal `'order_failed'` before recording the diagnostic. So:
 *
 *  - a rejected API key (badTokenRequest) read as "try again", which can never work;
 *  - a wrong outlet (outletNotAvailable) read the same;
 *  - the operator's diagnostics row said `order_failed` for all of them, which
 *    tells an operator nothing about which part of the configuration is wrong.
 *
 * Run: php tests/exec69_payment_diagnostics_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/gateway_diagnostics.php';
require_once __DIR__ . '/../includes/gateway/bootstrap.php';
require_once __DIR__ . '/../includes/gateway/payment_service.php';

$passed = 0; $failed = 0;
function check(string $label, bool $cond): void {
    global $passed, $failed;
    if ($cond) { $passed++; echo "  ok:   $label\n"; }
    else { $failed++; echo "  FAIL: $label\n"; }
}

echo "\n== 1. the adapter's own code survives classification ==\n";
$known = [
    'badTokenRequest'      => 'badTokenRequest',
    'realmNameNotAvailable'=> 'realmNameNotAvailable',
    'outletNotAvailable'   => 'outletNotAvailable',
    'unsupported_currency' => 'unsupported_currency',
    'zero_amount'          => 'zero_amount',
];
foreach ($known as $raw => $expected) {
    check("'$raw' classifies to itself", gateway_diag_reason_code($raw) === $expected);
}

echo "\n== 2. a sentence with an embedded gateway reason yields the reason ==\n";
check(
    'N-Genius order sentence -> outletNotAvailable',
    gateway_diag_reason_code('فشل إنشاء طلب الدفع (HTTP 400). [outletNotAvailable]') === 'outletNotAvailable'
);
check(
    'a bracketed badTokenRequest survives the sentence',
    gateway_diag_reason_code('فشل الحصول على رمز دخول (HTTP 401). [badTokenRequest]') === 'badTokenRequest'
);

echo "\n== 3. transport faults are separated from configuration faults ==\n";
check(
    'curl failure -> connection_failed',
    gateway_diag_reason_code('connection_failed: Could not resolve host: api-gateway.ksa.ngenius-payments.com') === 'connection_failed'
);
check(
    'an Arabic connection sentence -> connection_failed',
    gateway_diag_reason_code('تعذّر الاتصال ببوابة الدفع: Operation timed out after 20000 milliseconds') === 'connection_failed'
);
check(
    'HTTP 500 -> connection_failed (worth retrying)',
    gateway_diag_reason_code('فشل إنشاء طلب الدفع (HTTP 500).') === 'connection_failed'
);
check(
    'HTTP 400 with no reason -> gateway_rejected (permanent)',
    gateway_diag_reason_code('فشل إنشاء طلب الدفع (HTTP 400).') === 'gateway_rejected'
);
check(
    'an unknown sentence -> order_failed, never a blank code',
    gateway_diag_reason_code('') === 'order_failed'
);

// The synthetic `http_<status>` code is what both payment helpers pass when the gateway names
// no reason. It looks like a bare identifier, so it used to be returned verbatim and fall
// through the customer-message map to the generic "try again" sentence — a 400 told the
// customer to retry a rejection that can never clear. That was the exact opaque message the
// owner reported, and it is the one case where the code, not the sentence, decides.
check('http_400 -> gateway_rejected (permanent)', gateway_diag_reason_code('http_400') === 'gateway_rejected');
check('http_401 -> gateway_rejected (permanent)', gateway_diag_reason_code('http_401') === 'gateway_rejected');
check('http_403 -> gateway_rejected (permanent)', gateway_diag_reason_code('http_403') === 'gateway_rejected');
check('http_429 -> connection_failed (rate limit clears)', gateway_diag_reason_code('http_429') === 'connection_failed');
check('http_500 -> connection_failed (worth retrying)', gateway_diag_reason_code('http_500') === 'connection_failed');
check('http_502 -> connection_failed (worth retrying)', gateway_diag_reason_code('http_502') === 'connection_failed');
check(
    'a 4xx customer message never offers a retry',
    strpos(gateway_diag_customer_message(gateway_diag_reason_code('http_400'), 'R1', true), 'المحاولة مجدداً') === false
);

// Both helpers used to overwrite the code with 'order_failed' before the classifier saw it,
// which discarded the status distinction above. Guard the source so it cannot come back.
foreach (['consultation_payment.php', 'package_payment.php'] as $helper) {
    $src = file_get_contents(__DIR__ . '/../includes/' . $helper);
    check("$helper classifies the gateway code instead of flattening it",
        strpos($src, 'gateway_diag_reason_code(') !== false);
    check("$helper no longer forces the generic order_failed",
        !preg_match("/\\\$code = 'order_failed'|\\\\\$\\w+ = 'order_failed'/", $src));
}

echo "\n== 4. the customer message matches the classification ==\n";
$permanent = ['badTokenRequest', 'realmNameNotAvailable', 'outletNotAvailable', 'gateway_rejected', 'unsupported_currency'];
foreach ($permanent as $code) {
    $msg = gateway_diag_customer_message($code, 'REF12345', true);
    check("$code: no amount was charged is stated", strpos($msg, 'لم يتم خصم') !== false);
    check("$code: offers support, not a retry loop", strpos($msg, 'المحاولة مجدداً') === false);
    check("$code: carries the reference", strpos($msg, 'REF12345') !== false);
}
$retryable = ['connection_failed', 'order_failed', 'something_unmapped'];
foreach ($retryable as $code) {
    $msg = gateway_diag_customer_message($code, 'REF12345', true);
    check("$code: offers a retry", strpos($msg, 'المحاولة مجدداً') !== false);
}

echo "\n== 5. the payment service no longer flattens the reason (source guard) ==\n";
$svc = file_get_contents(__DIR__ . '/../includes/gateway/payment_service.php');
check('payment_service calls gateway_diag_reason_code()', strpos($svc, 'gateway_diag_reason_code') !== false);
check(
    'payment_service does not pass a hardcoded order_failed as the diagnostic code',
    !preg_match("/gateway_diag_failure\\s*\\([^;]*'order'\\s*,\\s*'order_failed'/s", $svc)
);

echo "\n== 6. a real failed initiation is recorded with its true code ==\n";
// Create an invoice and point the tenant at a gateway that will refuse: no enabled
// gateway is the one failure the test can force without credentials, and it proves
// the diagnostics row is written before the customer message is chosen.
$invoiceId = 0;
try {
    $pdo->beginTransaction();
    $u = $pdo->query("SELECT id FROM users ORDER BY id LIMIT 1")->fetchColumn();
    if (!$u) throw new RuntimeException('no seeded user');
    $l = $pdo->query("SELECT id FROM lawyers ORDER BY id LIMIT 1")->fetchColumn();
    $pdo->prepare("INSERT INTO invoices (invoice_number, user_id, lawyer_id, description, amount, currency, subtotal, status, due_date)
                   VALUES (?,?,?,?,?,?,?,'unpaid',CURDATE())")
        ->execute(['EXEC69-' . bin2hex(random_bytes(4)), $u, $l, 'diagnostic probe', 100, finance_currency(), 100]);
    $invoiceId = (int)$pdo->lastInsertId();
    $inv = $pdo->query("SELECT i.*, u.full_name, u.email FROM invoices i JOIN users u ON u.id=i.user_id WHERE i.id=$invoiceId")->fetch();

    // Disable every gateway for this call by forcing an explicit unknown one.
    $res = gateway_start_invoice_payment($pdo, $inv, ['gateway' => 'does_not_exist']);
    check('initiation fails rather than returning ok', empty($res['ok']));
    check('a correlation id is returned', !empty($res['correlation_id']));
    check('the error code is not the generic order_failed', ($res['error'] ?? '') !== 'order_failed');

    $row = $pdo->prepare('SELECT * FROM gateway_diagnostics WHERE correlation_id=? LIMIT 1');
    $row->execute([(string)$res['correlation_id']]);
    $diag = $row->fetch();
    check('the diagnostics row was written', !empty($diag));

    $pdo->rollBack();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "  FAIL: probe threw " . get_class($e) . ': ' . $e->getMessage() . "\n";
    $failed++;
}

echo "\n== 7. an adapter that names a code but writes an Arabic sentence ==\n";
// This is the production shape exactly: the N-Genius adapter returns `error` (a sentence for
// the customer) and `code` (the machine reason). The service classified on `error`, so the
// sentence never matched a known code, fell through to `order_failed`, and the customer was
// shown the generic retry sentence — «تعذّر بدء عملية الدفع الإلكتروني حالياً. لم يتم خصم أي
// مبلغ.» — for a fault no retry could fix. An injected adapter proves the whole chain
// (service -> classifier -> customer message) rather than reading the source.
final class Exec69FailingGateway implements PaymentGateway {
    public function __construct(private string $code, private string $sentence) {}
    public function id(): string { return 'exec69_fake'; }
    public function label(): string { return 'Exec69 fake'; }
    public function isEnabled(): bool { return true; }
    public function supportsCurrency(?string $currency): bool { return true; }
    public function environment(): string { return 'sandbox'; }
    public function settings(): array { return []; }
    public function createPayment(array $intent): array {
        return ['ok' => false, 'reference' => null, 'redirect_url' => null, 'state' => 'FAILED',
                'error' => $this->sentence, 'code' => $this->code, 'raw' => []];
    }
    public function verifyPayment(array $payment): array {
        return ['ok' => false, 'state' => 'PENDING', 'amount' => null, 'currency' => null, 'reference' => null, 'error' => 'x', 'raw' => []];
    }
    public function handleWebhook(array $headers, string $rawBody): array {
        return ['ok' => false, 'event_id' => null, 'event_type' => null, 'reference' => null,
                'state' => 'PENDING', 'amount' => null, 'currency' => null, 'duplicate' => false, 'error' => 'x', 'raw' => []];
    }
    public function supportsRefund(): bool { return false; }
    public function refundPayment(array $payment, ?float $amount = null): array {
        return ['ok' => false, 'state' => 'FAILED', 'refund_ref' => null, 'amount' => null, 'error' => 'unsupported'];
    }
    public function healthCheck(): array { return ['ok' => true, 'detail' => 'fixture']; }
}

$cases = [
    ['unsupported_currency', 'الدفع الإلكتروني غير متاح حالياً لهذه العملة.'],
    ['badTokenRequest',      'فشل الحصول على رمز دخول من بوابة الدفع (HTTP 401).'],
];
try {
    $pdo->beginTransaction();
    $u = $pdo->query("SELECT id FROM users ORDER BY id LIMIT 1")->fetchColumn();
    $l = $pdo->query("SELECT id FROM lawyers ORDER BY id LIMIT 1")->fetchColumn();
    if (!$u || !$l) throw new RuntimeException('no seeded user/lawyer');

    foreach ($cases as [$code, $sentence]) {
        GatewayRegistry::register('exec69_fake', fn() => new Exec69FailingGateway($code, $sentence));

        $pdo->prepare("INSERT INTO invoices (invoice_number, user_id, lawyer_id, description, amount, currency, subtotal, status, due_date)
                       VALUES (?,?,?,?,?,?,?,'unpaid',CURDATE())")
            ->execute(['EXEC69C-' . bin2hex(random_bytes(4)), $u, $l, 'code-vs-sentence probe', 100, finance_currency(), 100]);
        $id = (int)$pdo->lastInsertId();
        $inv = $pdo->query("SELECT i.*, u.full_name, u.email FROM invoices i JOIN users u ON u.id=i.user_id WHERE i.id=$id")->fetch();

        $res = gateway_start_invoice_payment($pdo, $inv, ['gateway' => 'exec69_fake']);
        check("$code: the service surfaces the machine code, not order_failed",
            ($res['error'] ?? '') === $code);

        $msg = gateway_diag_customer_message((string)($res['error'] ?? ''), (string)($res['correlation_id'] ?? ''), true);
        check("$code: the customer is not offered a retry that cannot succeed",
            strpos($msg, 'المحاولة مجدداً') === false);
        check("$code: the customer is told no amount was charged",
            strpos($msg, 'لم يتم خصم') !== false);

        // The correlation id is one per process (it is a per-request reference), so both
        // iterations share it. Read the newest row — the order the operator view uses.
        $row = $pdo->prepare('SELECT error_code FROM gateway_diagnostics WHERE correlation_id=? ORDER BY id DESC LIMIT 1');
        $row->execute([(string)$res['correlation_id']]);
        check("$code: the diagnostics row records the true code",
            (string)$row->fetchColumn() === $code);
    }
    $pdo->rollBack();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "  FAIL: adapter probe threw " . get_class($e) . ': ' . $e->getMessage() . "\n";
    $failed++;
}

echo "\n{$passed} checks, {$failed} failure(s)\n";
exit($failed === 0 ? 0 : 1);
