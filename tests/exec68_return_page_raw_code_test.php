<?php
/**
 * Execution 68 — the payment-return page must speak to the customer, not to the operator.
 *
 * consultation-payment-return.php set $error from consultation_payment_verify_and_confirm()
 * and printed it directly:
 *
 *     <p><?= htmlspecialchars($error) ?></p>
 *
 * Every value that can reach that line is a machine code — no_reference, confirm_failed,
 * badTokenRequest, outletNotAvailable, connection_failed, token_response_empty — because
 * the verify function returns ['ok'=>false,'error'=>$code]. So a customer who came back
 * from the gateway on a failed confirmation was shown a Latin identifier inside an Arabic
 * page. That is the same class of defect as the opaque sentence the diagnostics layer was
 * built to remove: the customer gets a string that tells them nothing.
 *
 * This suite fails on the raw-code render and passes once the page routes the code through
 * gateway_diag_customer_message(). It also pins the two codes the verify path can emit that
 * had no permanent message, so they cannot silently regress to the "try again" sentence.
 */

$root = dirname(__DIR__);
require_once $root . '/config/config.php';
require_once $root . '/includes/gateway_diagnostics.php';

$checks = 0;
$failures = 0;
function ok(bool $cond, string $label): void {
    global $checks, $failures;
    $checks++;
    if ($cond) { echo "  ok   $label\n"; }
    else { echo "  FAIL $label\n"; $failures++; }
}

echo "== 1. the page renders a translated sentence, not the raw code ==\n";
$page = (string)file_get_contents($root . '/consultation-payment-return.php');

// The rendered cell must be produced by the shared translator. Asserting on the helper call
// rather than on the absence of htmlspecialchars matters: escaping is still required for the
// translated text, so the fix adds the call don't remove the escape.
ok(str_contains($page, 'gateway_diag_customer_message'),
    'the return page routes its error through gateway_diag_customer_message()');

// $error is still escaped when printed — it now holds a translated sentence rather than a
// bare code, so the escape must stay. What must not remain is the direct assignment of the
// machine code into $error.
$rawAssign = preg_match('/\$error\s*=\s*\$result\[.error.\]/', $page) === 1;
ok(!$rawAssign, 'the page no longer assigns the raw $result[error] code into the rendered $error');
ok(str_contains($page, 'htmlspecialchars($error)'), 'the translated sentence is still HTML-escaped');

echo "\n== 2. every code the verify path can emit produces a real sentence ==\n";
// These are the values consultation_payment_verify_and_confirm() returns in its
// 'error' key, plus the codes its callees pass through.
$reachable = [
    'migration_required', 'not_found', 'no_reference', 'gateway_disabled',
    'confirm_failed', 'verify_failed',
    // passed through from ngenius_get_access_token() / ngenius_get_order_status()
    'connection_failed', 'token_response_empty', 'badTokenRequest',
    'realmNameNotAvailable', 'outletNotAvailable', 'gateway_rejected',
];
$arabic = '/\p{Arabic}/u';
foreach ($reachable as $code) {
    $msg = gateway_diag_customer_message($code, '', true, 'consultation');
    $isSentence = preg_match($arabic, $msg) === 1 && mb_strlen($msg) >= 20;
    $notRaw = strcasecmp(trim($msg), $code) !== 0;
    ok($isSentence && $notRaw, "code '$code' renders an Arabic sentence, not the identifier");
}

echo "\n== 3. a permanent fault must not invite a retry loop ==\n";
// The customer cannot fix a missing order reference or a failed confirmation by retrying:
// both mean the server's record does not match the gateway. They must say contact-us.
$retrySentence = gateway_diag_customer_message('order_failed', '', true, 'consultation');
foreach (['no_reference', 'confirm_failed'] as $code) {
    $msg = gateway_diag_customer_message($code, '', true, 'consultation');
    ok($msg !== $retrySentence,
        "'$code' is not left on the generic retry sentence");
    ok(mb_strpos($msg, 'تواصل') !== false,
        "'$code' tells the customer to contact support");
}

echo "\n== 4. the correlation reference still reaches the customer ==\n";
$withRef = gateway_diag_customer_message('gateway_rejected', 'ABCD1234', true, 'consultation');
ok(str_contains($withRef, 'ABCD1234'), 'a correlation id is appended when one exists');

echo "\n{$checks} checks, {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
