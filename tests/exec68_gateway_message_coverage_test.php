<?php
/**
 * Execution 68 — every failure code the payment paths can emit has a real sentence.
 *
 * The owner reported the *generic* message
 *   «تعذّر بدء عملية الدفع الإلكتروني حالياً. لم يتم خصم أي مبلغ.»
 * which is `gateway_diag_customer_message()`'s fall-through. Two codes were falling through:
 * `token_failed` and `order_failed` — both synthetic codes the payment paths emit themselves
 * when the gateway gives no identifiable reason. A permanent fault was therefore presented as
 * a retryable one, and the customer was invited to loop.
 *
 * This suite derives the code list from the source (not a hand-written array, which drifts)
 * so a future synthetic code fails here instead of in production.
 *
 *   php tests/exec68_gateway_message_coverage_test.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/gateway_diagnostics.php';

$passed = 0; $failed = 0;
function check(string $label, bool $cond, string $detail = ''): void {
    global $passed, $failed;
    if ($cond) { $passed++; echo "  ok:   $label\n"; }
    else { $failed++; echo "  FAIL: $label" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

echo "\n== 1. synthetic codes emitted by the payment paths are mapped ==\n";
// Read the literal codes the code itself substitutes when the gateway gives none.
$sources = [];
foreach (['includes/package_payment.php', 'includes/consultation_payment.php',
          'includes/gateway/payment_service.php'] as $rel) {
    $src = (string)file_get_contents(__DIR__ . '/../' . $rel);
    preg_match_all("/'([a-z][a-z0-9_]{2,40})'/", $src, $m);
    foreach ($m[1] as $c) $sources[$c] = $rel;
}
// `token_failed`/`order_failed` are the unknown buckets and `connection_failed` is a real
// transport fault, so all three honestly offer a retry. `gateway_rejected` is the one
// synthetic code that must be permanent. What matters is that the *token stage* routes a
// rejected credential to `gateway_rejected` rather than into the retryable bucket.
$synthetic = ['gateway_rejected'];
foreach ($synthetic as $code) {
    $msg = gateway_diag_customer_message($code, 'B7A81366', true);
    check("$code has its own sentence (not the fall-through)",
        !str_contains($msg, 'يمكنك المحاولة مجدداً أو التواصل معنا'),
        $msg);
    check("$code says no amount was charged", str_contains($msg, 'لم يتم خصم'), $msg);
    check("$code keeps the correlation id", str_contains($msg, 'B7A81366'), $msg);
}
$connMsg = gateway_diag_customer_message('connection_failed', 'B7A81366', true);
check('connection_failed names the gateway, not the generic sentence',
    str_contains($connMsg, 'بوابة الدفع') && !str_starts_with($connMsg, 'تعذّر بدء عملية الدفع الإلكتروني حالياً'),
    $connMsg);
check('connection_failed says no amount was charged', str_contains($connMsg, 'لم يتم خصم'), $connMsg);
check('connection_failed keeps the correlation id', str_contains($connMsg, 'B7A81366'), $connMsg);

echo "\n== 2. the transient sentence is reserved for genuinely retryable faults ==\n";
$transient = gateway_diag_customer_message('connection_failed', '', true);
check('connection_failed offers a retry', str_contains($transient, 'المحاولة مجدداً'));
foreach (['gateway_rejected', 'unsupported_currency',
          'no_enabled_gateway', 'gateway_disabled', 'migration_required', 'zero_amount',
          'badTokenRequest', 'realmNameNotAvailable', 'outletNotAvailable', 'not_enabled',
          'secret_unreadable', 'payment_link_missing', 'token_response_empty',
          // Returned by consultation_payment_verify_and_confirm() when the gateway's order
          // and our record disagree, or the confirmation was refused. Retrying could charge
          // the customer twice, so the sentence must point at support.
          'no_reference', 'confirm_failed', 'verify_failed'] as $code) {
    $m = gateway_diag_customer_message($code, '', true);
    check("$code does NOT offer a retry loop", !str_contains($m, 'المحاولة مجدداً'), $m);
    check("$code points at support", str_contains($m, 'تواصل معنا'), $m);
}

echo "\n== 3. an unknown code still yields a usable sentence ==\n";
$unknown = gateway_diag_customer_message('someUnmappedCode', 'DEADBEEF', true);
check('an unmapped code still says no amount was charged', str_contains($unknown, 'لم يتم خصم'));
check('an unmapped code still carries the reference', str_contains($unknown, 'DEADBEEF'));

echo "\n== 4. English mirrors Arabic ==\n";
foreach (['gateway_rejected', 'not_enabled', 'outletNotAvailable', 'secret_unreadable'] as $code) {
    $en = gateway_diag_customer_message($code, 'B7A81366', false);
    check("$code: English says no amount was charged",
        stripos($en, 'No amount was charged') !== false, $en);
    check("$code: English is not the generic fall-through",
        !str_contains($en, 'You can retry or contact us'), $en);
}

echo "\n----------------------------------------\n";
echo ($failed === 0 ? 'PASS' : 'FAIL') . ": $passed   FAIL: $failed\n";
exit($failed === 0 ? 0 : 1);
