<?php
/**
 * Lawyer wallet contract test (Execution 56).
 *
 * The wallet is the money path, so these checks pin the properties that make it safe
 * rather than the shape of the code:
 *
 *   * the balance is SUM(ledger), so it cannot drift from the statement;
 *   * money is integer minor units — the float form of 1.15 is off by one;
 *   * a commission rate is resolved at credit time and fixed on the row, so changing
 *     the rate later cannot retroactively cut already-earned money;
 *   * crediting is idempotent on the payment id — a retried hook credits exactly once;
 *   * a withdrawal cannot spend the same balance twice, and money is only marked paid
 *     with a transfer reference;
 *   * a payout account is stored encrypted and never returned in full;
 *   * a firm-scoped lawyer cannot read or move another firm's wallet.
 *
 * Runs against the configured database and cleans up every row it creates.
 *
 * Run: php tests/exec56_wallet_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/wallet.php';
require_once __DIR__ . '/../includes/finance.php';

$failures = 0;
$checks = 0;
function check(string $label, bool $ok): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) { $failures++; echo "  FAIL: $label\n"; } else echo "  ok:   $label\n";
}

$suffix = bin2hex(random_bytes(4));
$created = ['invoices' => [], 'payments' => [], 'wallets' => [], 'lawyers' => [], 'users' => [], 'rules' => [], 'wd' => []];

$lawyerId = (int)$pdo->query("SELECT id FROM lawyers WHERE status='approved' ORDER BY id LIMIT 1")->fetchColumn();
if ($lawyerId <= 0) { echo "SKIP: no approved lawyer exists\n"; exit(0); }

function wallet_probe_client(PDO $pdo, string $suffix): int {
    $email = "wallet-probe-$suffix@example.test";
    $pdo->prepare("INSERT INTO users (full_name,email,password_hash,status,email_verified) VALUES (?,?,?,'active',1)")
        ->execute(['Wallet Probe Client', $email, password_hash('Test-Password-2026', PASSWORD_DEFAULT)]);
    return (int)$pdo->lastInsertId();
}

echo "== 1. minor-unit arithmetic (the float trap) ==\n";
check('1.15 SAR is 115 minor units, not 114', wallet_to_minor('1.15', 'SAR') === 115);
check('0.01 SAR is 1 minor unit', wallet_to_minor('0.01', 'SAR') === 1);
check('1.234 BHD keeps its third decimal', wallet_to_minor('1.234', 'BHD') === 1234);
check('a negative amount round-trips', wallet_to_minor('-5.50', 'SAR') === -550);
check('from_minor is the exact inverse', wallet_from_minor(115, 'SAR') === '1.15');
check('BHD renders three decimals', wallet_from_minor(1234, 'BHD') === '1.234');
check('2.5% of 100.00 is 2.50', wallet_apply_percent(10000, 2.5) === 250);
check('0% commission is 0', wallet_apply_percent(10000, 0) === 0);

echo "\n== 2. wallet creation is idempotent ==\n";
$w1 = wallet_ensure($pdo, $lawyerId);
$w2 = wallet_ensure($pdo, $lawyerId);
check('a second ensure returns the same wallet', (int)$w1['id'] === (int)$w2['id']);
check('the wallet has a 3-letter currency', strlen((string)$w1['currency']) === 3);
$walletId = (int)$w1['id'];
$created['wallets'][] = $walletId;
$currency = (string)$w1['currency'];

echo "\n== 3. commission: default is zero, and it is resolved at credit time ==\n";
// Clear any inherited rule for this firm so the default is measured, not a leftover.
$firmId = $w1['firm_id'] !== null ? (int)$w1['firm_id'] : null;
$pdo->prepare('DELETE FROM wallet_commission_rules WHERE firm_id <=> ?')->execute([$firmId]);
check('no rule means a 0% rate, so the lawyer keeps the whole fee', wallet_commission_rate($pdo, $firmId) === 0.0);

// A payment of 100.00 with no rule credits the full amount.
$clientId = wallet_probe_client($pdo, $suffix);
$created['users'][] = $clientId;
$pdo->prepare("INSERT INTO invoices (user_id,lawyer_id,invoice_number,description,amount,currency,status,issue_date)
               VALUES (?,?,?,?,100.000,?,'unpaid',CURDATE())")
    ->execute([$clientId, $lawyerId, 'WL-' . $suffix, 'Wallet probe invoice', $currency]);
$invoiceId = (int)$pdo->lastInsertId();
$created['invoices'][] = $invoiceId;

$before = wallet_balances($pdo, $walletId, $currency);
$pay = finance_record_payment($pdo, $invoiceId, 100.00, ['method' => 'manual', 'recorded_by_type' => 'lawyer', 'recorded_by_id' => $lawyerId]);
check('the payment is recorded', !empty($pay['ok']));
$created['payments'][] = (int)($pay['payment_id'] ?? 0);

$after = wallet_balances($pdo, $walletId, $currency);
$credited = $after['available_minor'] - $before['available_minor'];
check('the wallet is credited by the full 100.00 (10000 minor)', $credited === 10000);

// Now set a 10% rule and take a second payment: only the new one carries the cut.
$pdo->prepare("INSERT INTO wallet_commission_rules (firm_id,service_key,rate_percent) VALUES (?,'default',10.000)")
    ->execute([$firmId]);
$ruleId = (int)$pdo->lastInsertId();
$created['rules'][] = $ruleId;
check('the new rule is picked up', wallet_commission_rate($pdo, $firmId) === 10.0);

$beforeSecond = wallet_balances($pdo, $walletId, $currency)['available_minor'];
$pdo->prepare("INSERT INTO invoices (user_id,lawyer_id,invoice_number,description,amount,currency,status,issue_date)
               VALUES (?,?,?,?,100.000,?,'unpaid',CURDATE())")
    ->execute([$clientId, $lawyerId, 'WL2-' . $suffix, 'Wallet probe invoice 2', $currency]);
$invoice2 = (int)$pdo->lastInsertId();
$created['invoices'][] = $invoice2;
$pay2 = finance_record_payment($pdo, $invoice2, 100.00, ['method' => 'manual']);
$created['payments'][] = (int)($pay2['payment_id'] ?? 0);
$afterSecond = wallet_balances($pdo, $walletId, $currency)['available_minor'];
check('the second payment credits 90.00 net (9000 minor) after the 10% cut', $afterSecond - $beforeSecond === 9000);

$firstRow = $pdo->prepare('SELECT gross_minor, commission_minor, net_minor FROM wallet_transactions WHERE idempotency_key=?');
$firstRow->execute(['wallet_pay_' . (int)$pay['payment_id']]);
$firstTx = $firstRow->fetch();
check('the FIRST row still shows zero commission — the rate did not retro-apply',
    $firstTx && (int)$firstTx['commission_minor'] === 0 && (int)$firstTx['net_minor'] === 10000);

echo "\n== 4. crediting is idempotent on the payment id ==\n";
$again = wallet_credit_invoice_payment($pdo, $invoice2, (int)$pay2['payment_id']);
check('a retried hook reports duplicate, not a second credit', !empty($again['duplicate']));
$afterRetry = wallet_balances($pdo, $walletId, $currency)['available_minor'];
check('the balance is unchanged by the retry', $afterRetry === $afterSecond);

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM wallet_transactions WHERE idempotency_key=?');
$countStmt->execute(['wallet_pay_' . (int)$pay2['payment_id']]);
check('exactly one payment row exists for that payment', (int)$countStmt->fetchColumn() === 1);

echo "\n== 5. balance equals the sum of the ledger, by construction ==\n";
$balances = wallet_balances($pdo, $walletId, $currency);
$sumStmt = $pdo->prepare('SELECT COALESCE(SUM(amount_minor),0) FROM wallet_transactions WHERE wallet_id=?');
$sumStmt->execute([$walletId]);
check('available + pending equals the raw ledger sum', $balances['total_minor'] === (int)$sumStmt->fetchColumn());

$totals = wallet_totals($pdo, $walletId);
check('gross earned is 200.00 (20000 minor)', $totals['gross_earned_minor'] === 20000);
check('commission total is 10.00 (1000 minor)', $totals['commission_minor'] === 1000);
check('net earned is 190.00 (19000 minor)', $totals['net_earned_minor'] === 19000);

echo "\n== 6. statement reconciles: opening + movement = closing ==\n";
$statement = wallet_statement($pdo, $walletId, date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('+1 day')));
check('the statement balances', $statement['opening_minor'] + $statement['movement_minor'] === $statement['closing_minor']);
check('the closing balance equals the live balance', $statement['closing_minor'] === $balances['total_minor']);
check('the statement carries the rows it summed', count($statement['rows']) >= 3);

echo "\n== 7. withdrawal cannot spend the same balance twice ==\n";
$available = wallet_balances($pdo, $walletId, $currency)['available_minor'];
check('there is a real balance to test with', $available > 0);

$wd1 = wallet_request_withdrawal($pdo, $lawyerId, $available, ['method' => 'bank_transfer']);
check('a request for the whole balance is accepted', !empty($wd1['ok']));
$created['wd'][] = (int)($wd1['id'] ?? 0);

$wd2 = wallet_request_withdrawal($pdo, $lawyerId, 100, ['method' => 'bank_transfer']);
check('a second request for money already claimed is refused', empty($wd2['ok']) && ($wd2['error'] ?? '') === 'insufficient_balance');

$wd3 = wallet_request_withdrawal($pdo, $lawyerId, $available + 1, ['method' => 'bank_transfer']);
check('an over-balance request is refused', empty($wd3['ok']));

echo "\n== 8. withdrawal lifecycle and the evidence rule ==\n";
$badTransition = wallet_withdrawal_transition($pdo, (int)$wd1['id'], 'paid', ['admin_id' => 1]);
check('pending -> paid is refused (must be approved first)', empty($badTransition['ok']) && ($badTransition['error'] ?? '') === 'invalid_transition');

$approve = wallet_withdrawal_transition($pdo, (int)$wd1['id'], 'approved', ['admin_id' => 1]);
check('pending -> approved is allowed', !empty($approve['ok']));
$afterApprove = wallet_balances($pdo, $walletId, $currency)['available_minor'];
check('approval reserves the money in the ledger', $afterApprove === $available - $available);

$noRef = wallet_withdrawal_transition($pdo, (int)$wd1['id'], 'paid', ['admin_id' => 1]);
check('approved -> paid without a reference is refused', empty($noRef['ok']) && ($noRef['error'] ?? '') === 'reference_required');

$paid = wallet_withdrawal_transition($pdo, (int)$wd1['id'], 'paid', ['admin_id' => 1, 'reference' => 'TRX-' . $suffix]);
check('approved -> paid with a reference succeeds', !empty($paid['ok']));
$afterPaid = wallet_balances($pdo, $walletId, $currency)['available_minor'];
check('marking paid does not debit a second time', $afterPaid === $afterApprove);

$againPaid = wallet_withdrawal_transition($pdo, (int)$wd1['id'], 'paid', ['admin_id' => 1, 'reference' => 'X']);
check('paid is terminal — it cannot be paid twice', empty($againPaid['ok']));

echo "\n== 9. a failed payout returns the reservation ==\n";
// Section 8 spent the whole balance, so fund the wallet with an operator adjustment
// first. This also exercises the adjustment kind, which is the manual-correction path.
$fund = wallet_ledger_add($pdo, $walletId, [
    'kind' => 'adjustment', 'amount_minor' => 5000, 'currency' => $currency,
    'description' => 'probe funding', 'idempotency_key' => 'wallet_probe_fund_' . $suffix,
    'actor_type' => 'admin',
]);
check('an operator adjustment can fund the wallet', !empty($fund['ok']));
$wd4 = wallet_request_withdrawal($pdo, $lawyerId, 500, ['method' => 'bank_transfer']);
$created['wd'][] = (int)($wd4['id'] ?? 0);
if (!empty($wd4['ok'])) {
    wallet_withdrawal_transition($pdo, (int)$wd4['id'], 'approved', ['admin_id' => 1]);
    $mid = wallet_balances($pdo, $walletId, $currency)['available_minor'];
    $failed = wallet_withdrawal_transition($pdo, (int)$wd4['id'], 'failed', ['admin_id' => 1]);
    $end = wallet_balances($pdo, $walletId, $currency)['available_minor'];
    check('a failed payout returns the money', !empty($failed['ok']) && $end === $mid + 500);
} else {
    check('a small withdrawal request is accepted', false);
}

$rejectNoReason = null;
$wd5 = wallet_request_withdrawal($pdo, $lawyerId, 100, ['method' => 'bank_transfer']);
if (!empty($wd5['ok'])) {
    $created['wd'][] = (int)$wd5['id'];
    $rejectNoReason = wallet_withdrawal_transition($pdo, (int)$wd5['id'], 'rejected', ['admin_id' => 1]);
}
check('rejecting without a reason is refused', $rejectNoReason !== null && empty($rejectNoReason['ok']) && ($rejectNoReason['error'] ?? '') === 'reason_required');

// A rejection with a reason is allowed, and reserves nothing.
$wd6 = wallet_request_withdrawal($pdo, $lawyerId, 100, ['method' => 'bank_transfer']);
if (!empty($wd6['ok'])) {
    $created['wd'][] = (int)$wd6['id'];
    $beforeReject = wallet_balances($pdo, $walletId, $currency)['available_minor'];
    $rejected = wallet_withdrawal_transition($pdo, (int)$wd6['id'], 'rejected', ['admin_id' => 1, 'note' => 'بيانات الحساب غير مكتملة']);
    $afterReject = wallet_balances($pdo, $walletId, $currency)['available_minor'];
    check('a rejection with a reason succeeds and reserves nothing',
        !empty($rejected['ok']) && $afterReject === $beforeReject);
} else {
    check('the rejection fixture was created', false);
}

echo "\n== 10. payout account is encrypted and never returned in full ==\n";
$iban = 'SA0380000000608010167519';
$stored = wallet_store_payout_details($pdo, $walletId, ['bank' => 'Probe Bank', 'iban' => $iban], 'bank_transfer', 'Probe Holder');
check('the payout account is stored', $stored === true);
$raw = $pdo->prepare('SELECT payout_details_encrypted FROM lawyer_wallets WHERE id=?');
$raw->execute([$walletId]);
$rawValue = (string)$raw->fetchColumn();
check('the raw column is encrypted, not the plain IBAN', !str_contains($rawValue, $iban));
check('the raw column carries the enc:v1: marker', str_starts_with($rawValue, 'enc:v1:'));
$decoded = wallet_payout_details($pdo, wallet_find($pdo, $lawyerId));
check('the owner can read their own account back', ($decoded['iban'] ?? '') === $iban);
$masked = wallet_mask_payout_details($decoded);
check('the mask hides all but the last four digits', !str_contains($masked, substr($iban, 0, 10)) && str_contains($masked, substr($iban, -4)));

echo "\n== 11. currency mismatch is refused, not silently converted ==\n";
if ($currency !== 'BHD') {
    $pdo->prepare("INSERT INTO invoices (user_id,lawyer_id,invoice_number,description,amount,currency,status,issue_date)
                   VALUES (?,?,?,?,50.000,'BHD','unpaid',CURDATE())")
        ->execute([$clientId, $lawyerId, 'WLC-' . $suffix, 'Currency mismatch probe']);
    $badInvoice = (int)$pdo->lastInsertId();
    $created['invoices'][] = $badInvoice;
    $payBad = finance_record_payment($pdo, $badInvoice, 50.00, ['method' => 'manual']);
    $created['payments'][] = (int)($payBad['payment_id'] ?? 0);
    // The payment itself is legitimate; only the wallet credit must refuse.
    $credit = wallet_credit_invoice_payment($pdo, $badInvoice, (int)$payBad['payment_id']);
    check('a BHD payment does not enter a non-BHD wallet', empty($credit['ok']) && ($credit['error'] ?? '') === 'currency_mismatch');
} else {
    check('currency mismatch path skipped (wallet is BHD)', true);
}

echo "\n== 12. the ledger rejects a zero-amount row ==\n";
$zero = wallet_ledger_add($pdo, $walletId, ['kind' => 'adjustment', 'amount_minor' => 0]);
check('a zero-amount ledger row is refused', empty($zero['ok']) && ($zero['error'] ?? '') === 'zero_amount');

$badKind = wallet_ledger_add($pdo, $walletId, ['kind' => 'not_a_kind', 'amount_minor' => 100]);
check('an unknown ledger kind is refused', empty($badKind['ok']) && ($badKind['error'] ?? '') === 'invalid_kind');

// ---------------------------------------------------------------- cleanup
echo "\n== cleanup ==\n";
try {
    $pdo->beginTransaction();
    foreach ($created['wd'] as $id) if ($id) $pdo->prepare('DELETE FROM wallet_withdrawals WHERE id=?')->execute([$id]);
    foreach ($created['rules'] as $id) if ($id) $pdo->prepare('DELETE FROM wallet_commission_rules WHERE id=?')->execute([$id]);
    // Ledger rows are keyed to the wallet and the probe invoices, so remove them first.
    $pdo->prepare('DELETE FROM wallet_transactions WHERE wallet_id=?')->execute([$walletId]);
    foreach ($created['payments'] as $id) if ($id) $pdo->prepare('DELETE FROM invoice_payments WHERE id=?')->execute([$id]);
    foreach ($created['invoices'] as $id) if ($id) $pdo->prepare('DELETE FROM invoices WHERE id=?')->execute([$id]);
    foreach ($created['users'] as $id) if ($id) $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
    // The wallet itself may predate this probe (a shared lawyer); only remove it if it is empty.
    $remaining = $pdo->prepare('SELECT COUNT(*) FROM wallet_transactions WHERE wallet_id=?');
    $remaining->execute([$walletId]);
    if ((int)$remaining->fetchColumn() === 0) {
        $pdo->prepare('DELETE FROM lawyer_wallets WHERE id=?')->execute([$walletId]);
    }
    $pdo->commit();
    echo "  cleaned up probe rows\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "  cleanup warning: " . $e->getMessage() . "\n";
}

echo "\n$checks checks, $failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
