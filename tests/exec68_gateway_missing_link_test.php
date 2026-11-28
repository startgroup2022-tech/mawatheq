<?php
/**
 * Execution 68 — a gateway order that is accepted but has no hosted-page link must name itself.
 *
 * The production report was the opaque sentence «تعذّر بدء عملية الدفع الإلكتروني حالياً. لم
 * يتم خصم أي مبلغ.» — the fallback in gateway_diag_customer_message() for any code it does
 * not name. Every HTTP-failure shape was pinned before this suite: a 4xx becomes
 * `gateway_rejected`, a 5xx/429 becomes `connection_failed`, a transport failure carries
 * `connection_failed` explicitly. One shape was missed.
 *
 * `ngenius_create_order()` treats "HTTP < 300 but the response has no `_links.payment.href`"
 * as an order failure and reports `code = 'http_201'`. That is a real, reachable case: a 201
 * with a `reference` and no payment link. `gateway_diag_reason_code('http_201')` mapped it to
 * `order_failed`, which the customer-message map deliberately leaves on the generic
 * "retry or contact us" sentence — so an operator reading the production report could not
 * tell this apart from a transient network blip, and the customer was invited to retry a
 * gateway response that will never produce a payment page.
 *
 * This suite pins the classification in both directions:
 *
 *   - the reason code is NOT the bare catch-all `order_failed` for a 2xx/3xx
 *   - the customer message names the gateway rather than the generic fallback
 *   - a genuine 5xx still classifies as transient (a retryable fault is not mislabelled)
 *
 * Run: php tests/exec68_gateway_missing_link_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/gateway_diagnostics.php';

$checks = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $checks, $fail;
    $checks++;
    if ($ok) { echo "  ok:   $label\n"; return; }
    $fail++;
    echo "  FAIL: $label" . ($detail !== '' ? " — $detail" : '') . "\n";
}

/** The sentence a customer is shown for a failure code, both languages. */
function customer_message(string $code, string $corr = 'REF12345'): string {
    return gateway_diag_customer_message($code, $corr, true, 'consultation');
}

// The exact production fallback. If a message contains this stem, the customer was told
// nothing about the cause.
const GENERIC_STEM = 'تعذّر بدء عملية الدفع الإلكتروني حالياً';

echo "Execution 68 — a gateway order with no hosted-page link names its cause\n\n";

echo "== 1. http_201 is not the bare catch-all\n";
$code201 = gateway_diag_reason_code('http_201');
check('reason code is a named code, not `order_failed`', $code201 !== 'order_failed', "got $code201");
check('reason code is not empty', trim($code201) !== '');

echo "\n== 2. the customer message names the cause, not the fallback\n";
$msg201 = customer_message($code201);
check('the message does not use the generic production sentence',
    !str_contains($msg201, GENERIC_STEM), 'message: ' . $msg201);
check('the message still tells the customer nothing was charged',
    str_contains($msg201, 'لم يتم خصم'));
check('the message still carries the reference number',
    str_contains($msg201, 'REF12345'));

echo "\n== 3. the same holds for every 2xx/3xx status the order stage can synthesise\n";
foreach ([200, 201, 202, 204, 301, 302] as $status) {
    $c = gateway_diag_reason_code('http_' . $status);
    $m = customer_message($c);
    check("http_$status classifies to a named code", $c !== 'order_failed', "got $c");
    check("http_$status does not show the generic sentence",
        !str_contains($m, GENERIC_STEM), "code=$c message: " . $m);
}

echo "\n== 4. a genuinely transient fault is still transient\n";
foreach ([429, 500, 502, 503, 504] as $status) {
    $c = gateway_diag_reason_code('http_' . $status);
    check("http_$status still classifies as connection_failed (retryable)", $c === 'connection_failed', "got $c");
}

echo "\n== 5. a genuine rejection is still permanent\n";
foreach ([400, 401, 403, 404, 422] as $status) {
    $c = gateway_diag_reason_code('http_' . $status);
    check("http_$status still classifies as gateway_rejected (permanent)", $c === 'gateway_rejected', "got $c");
    $m = customer_message($c);
    check("http_$status tells the customer to contact us, not to retry forever",
        str_contains($m, 'تواصل') && !str_contains($m, 'المحاولة مجدداً'), 'message: ' . $m);
}

echo "\n== 6. the token stage is not mislabelled as an order stage\n";
// A 2xx from the ORDER endpoint means 'order accepted, no checkout link'. A 2xx from the
// TOKEN endpoint means something else entirely, so ngenius_get_access_token() names it
// `token_response_empty`. If it emitted `http_200` instead, the fix above would report a
// credential fault as a missing checkout page and send the operator to the wrong setting.
$tok = gateway_diag_reason_code('token_response_empty');
check('token_response_empty stays its own code', $tok === 'token_response_empty', "got $tok");
check('token_response_empty is not payment_link_missing', $tok !== 'payment_link_missing');
$tokMsg = customer_message($tok);
check('token_response_empty does not show the generic sentence',
    !str_contains($tokMsg, GENERIC_STEM), $tokMsg);
check('token_response_empty still says nothing was charged', str_contains($tokMsg, 'لم يتم خصم'));
$tokOp = (string)(gateway_diag_reason($tok)['ar'] ?? '');
$linkOp = (string)(gateway_diag_reason('payment_link_missing')['ar'] ?? '');
check('the token-stage operator cause differs from the order-stage one', $tokOp !== $linkOp,
    "both say: $tokOp");

echo "\n== 7. the operator cause is distinguishable from a transport blip\n";
$opMissing = (string)(gateway_diag_reason($code201)['ar'] ?? '');
$opConn = (string)(gateway_diag_reason('connection_failed')['ar'] ?? '');
check('the operator cause is not empty', trim($opMissing) !== '');
check('the operator cause differs from a connection failure', $opMissing !== $opConn,
    "both say: $opMissing");

echo "\n" . str_repeat('-', 60) . "\n";
echo "  Execution 68 missing-link diagnosis: $checks checks, $fail failure(s)\n";
exit($fail > 0 ? 1 : 0);
