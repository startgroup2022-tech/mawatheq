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

echo "\n{$passed} checks, {$failed} failure(s)\n";
exit($failed === 0 ? 0 : 1);
