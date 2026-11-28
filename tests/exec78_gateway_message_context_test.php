<?php
/**
 * Execution 78 — the payment failure sentence must name what was being bought.
 *
 * The failure texts in gateway_diagnostics.php were written for the subscription flow, so
 * every one of them closed with «تواصل معنا لإتمام الاشتراك». A customer whose *consultation*
 * payment failed was told to complete their subscription — a noun they had never chosen,
 * and one that sends them looking for a subscription page that has nothing to do with the
 * booking they were paying for.
 *
 * The shared sentences stay shared; only the noun is re-targeted by $context. This suite
 * pins both halves: the noun changes where it should, and the default is unchanged so no
 * existing caller silently moves.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/gateway_diagnostics.php';

$checks = 0;
$failures = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $checks, $failures;
    $checks++;
    if ($ok) { echo "  ok:   $label\n"; return; }
    $failures++;
    echo "  FAIL: $label" . ($detail !== '' ? " — $detail" : '') . "\n";
}

echo "== 1. the subscription default is unchanged ==\n";
$subAr = gateway_diag_customer_message('no_enabled_gateway', '', true);
check('no context still says الاشتراك', str_contains($subAr, 'لإتمام الاشتراك.'));
check('no context does not say الاستشارة', !str_contains($subAr, 'الاستشارة'));

$subEn = gateway_diag_customer_message('no_enabled_gateway', '', false);
check('EN default says subscription', str_contains($subEn, 'complete your subscription.'));

echo "\n== 2. a consultation failure names the consultation ==\n";
$conAr = gateway_diag_customer_message('no_enabled_gateway', '', true, 'consultation');
check('AR says الاستشارة', str_contains($conAr, 'لإتمام الاستشارة.'));
check('AR no longer says الاشتراك', !str_contains($conAr, 'الاشتراك'), $conAr);

$conEn = gateway_diag_customer_message('no_enabled_gateway', '', false, 'consultation');
check('EN says consultation', str_contains($conEn, 'complete your consultation.'));
check('EN no longer says subscription', !str_contains($conEn, 'subscription'), $conEn);

echo "\n== 3. an invoice failure names the invoice ==\n";
$invAr = gateway_diag_customer_message('unsupported_currency', '', true, 'invoice');
check('AR says الفاتورة', str_contains($invAr, 'لإتمام الفاتورة.'));
check('AR no longer says الاشتراك', !str_contains($invAr, 'الاشتراك'), $invAr);

$invEn = gateway_diag_customer_message('unsupported_currency', '', false, 'invoice');
check('EN says invoice', str_contains($invEn, 'complete your invoice.'));

echo "\n== 4. every permanent code is re-targeted, not just one ==\n";
$permanentCodes = [
    'unsupported_currency', 'no_enabled_gateway', 'gateway_disabled',
    'badTokenRequest', 'realmNameNotAvailable', 'outletNotAvailable',
    'secret_unreadable', 'not_enabled',
];
$leaked = [];
foreach ($permanentCodes as $code) {
    $m = gateway_diag_customer_message($code, '', true, 'consultation');
    if (str_contains($m, 'الاشتراك')) $leaked[] = $code;
}
check('no permanent code leaks the subscription noun', $leaked === [], implode(', ', $leaked));

echo "\n== 5. the reference is still appended after re-targeting ==\n";
$withRef = gateway_diag_customer_message('no_enabled_gateway', 'ABCD1234', true, 'consultation');
check('correlation id survives the rewrite', str_contains($withRef, 'ABCD1234'));
check('the noun and the reference coexist',
    str_contains($withRef, 'الاستشارة') && str_contains($withRef, 'الرقم المرجعي'));

echo "\n== 6. an unknown context degrades to the default, never to a broken sentence ==\n";
$bogus = gateway_diag_customer_message('no_enabled_gateway', '', true, 'nonsense');
check('unknown context falls back to الاشتراك', str_contains($bogus, 'لإتمام الاشتراك.'));
check('unknown context leaves no empty noun', !str_contains($bogus, 'لإتمام .'), $bogus);

echo "\n== 7. a transient fault keeps the retry sentence in every context ==\n";
$transient = gateway_diag_customer_message('token_failed', '', true, 'consultation');
check('transient still offers a retry', str_contains($transient, 'المحاولة مجدداً'), $transient);
check('transient does not claim a permanent fault', !str_contains($transient, 'الاشتراك'));

echo "\n== 8. the pages that call it pass their own context ==\n";
$root = dirname(__DIR__);
$expect = [
    'consultations.php' => 'consultation',
    'booking.php'       => 'consultation',
    'pay-invoice.php'   => 'invoice',
];
foreach ($expect as $file => $context) {
    $src = (string)@file_get_contents($root . '/' . $file);
    check(
        "$file passes '$context'",
        str_contains($src, "'" . $context . "'") && str_contains($src, 'gateway_diag_customer_message'),
        'the call site was not re-targeted'
    );
}
$checkout = (string)@file_get_contents($root . '/checkout.php');
check('checkout.php keeps the subscription default (no 4th argument)',
    !preg_match('/gateway_diag_customer_message\([^)]*[\'"]subscription[\'"]/', $checkout));

echo "\n" . str_repeat('-', 60) . "\n";
echo "  Execution 78 gateway message context: $checks checks, $failures failure(s)\n";
exit($failures > 0 ? 1 : 0);
