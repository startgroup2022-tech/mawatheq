<?php
/**
 * Lawyer financial wallet (Execution 56).
 *
 * A ledger, not a balance column. Every credit and debit is a row in
 * `wallet_transactions`; the balance is SUM(amount_minor) over those rows. A stored
 * total is a cache that becomes silently wrong the first time a write half-fails or
 * two requests race, and it cannot answer "where did this figure come from".
 *
 * Money is handled in integer minor units (halalas for SAR, fils for BHD). A float
 * ledger accumulates representation error; an integer ledger cannot. Conversion
 * happens only at the edges, through wallet_to_minor()/wallet_from_minor().
 *
 * The commission rate is resolved once, when the payment is recorded, and the split
 * is written onto the row. Changing the rate later therefore cannot retroactively
 * alter money the lawyer already earned.
 *
 * @package Mawatheq
 */

require_once __DIR__ . '/currency.php';
require_once __DIR__ . '/secret_crypto.php';

/** Decimal places for a currency, delegated so there is one definition. */
function wallet_currency_decimals(?string $code = null): int {
    return currency_decimals($code);
}

/**
 * Convert a decimal amount to integer minor units.
 *
 * Uses string maths on a formatted value rather than `(int)($x * 100)`: the float
 * form is off by one at values such as 1.15, and a ledger cannot be off by one.
 */
function wallet_to_minor($amount, ?string $code = null): int {
    $decimals = wallet_currency_decimals($code);
    $formatted = number_format((float)$amount, $decimals, '.', '');
    $negative = str_starts_with($formatted, '-');
    if ($negative) $formatted = substr($formatted, 1);
    $parts = explode('.', $formatted, 2);
    $whole = $parts[0] !== '' ? $parts[0] : '0';
    $frac  = $parts[1] ?? '';
    $frac  = str_pad($frac, $decimals, '0');
    $minor = ((int)$whole) * (10 ** $decimals) + ($decimals > 0 ? (int)$frac : 0);
    return $negative ? -$minor : $minor;
}

/** Convert integer minor units back to a decimal string for storage/display. */
function wallet_from_minor(int $minor, ?string $code = null): string {
    $decimals = wallet_currency_decimals($code);
    $sign = $minor < 0 ? '-' : '';
    $abs  = abs($minor);
    if ($decimals === 0) return $sign . (string)$abs;
    $factor = 10 ** $decimals;
    $whole = intdiv($abs, $factor);
    $frac  = $abs % $factor;
    return $sign . $whole . '.' . str_pad((string)$frac, $decimals, '0', STR_PAD_LEFT);
}

/** Format minor units for display, through the platform currency helpers. */
function wallet_money(int $minor, ?string $code = null): string {
    return money(wallet_from_minor($minor, $code), $code);
}

/** Plain-text formatting for CSV/exports. */
function wallet_money_text(int $minor, ?string $code = null): string {
    return money_text(wallet_from_minor($minor, $code), $code);
}

/* ---------------------------------------------------------------- wallets ---- */

/** Find the wallet row for a lawyer, without creating one. */
function wallet_find(PDO $pdo, int $lawyerId): ?array {
    $stmt = $pdo->prepare('SELECT * FROM lawyer_wallets WHERE lawyer_id=?');
    $stmt->execute([$lawyerId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Return the lawyer's wallet, creating it on first use.
 *
 * The currency is fixed at creation from the lawyer's firm, falling back to the
 * site currency. A wallet never silently changes currency: mixing halalas and fils
 * in one integer ledger would sum incompatible units.
 */
function wallet_ensure(PDO $pdo, int $lawyerId): array {
    $existing = wallet_find($pdo, $lawyerId);
    if ($existing) return $existing;

    $ctx = function_exists('tenant_context_for_lawyer')
        ? tenant_context_for_lawyer($pdo, $lawyerId)
        : ['firm_id' => null];
    $firmId = $ctx['firm_id'] ?? null;

    $currency = currency_code();
    $stmt = $pdo->prepare('INSERT INTO lawyer_wallets (lawyer_id, firm_id, currency) VALUES (?,?,?)
        ON DUPLICATE KEY UPDATE lawyer_id=VALUES(lawyer_id)');
    $stmt->execute([$lawyerId, $firmId, $currency]);

    $wallet = wallet_find($pdo, $lawyerId);
    // A concurrent request may have won the insert; re-read rather than assume.
    if (!$wallet) {
        throw new RuntimeException('wallet_create_failed');
    }
    return $wallet;
}

/**
 * Balance summary for a wallet, computed from the ledger.
 *
 * @return array{available_minor:int,pending_minor:int,total_minor:int,currency:string}
 */
function wallet_balances(PDO $pdo, int $walletId, ?string $currency = null): array {
    $stmt = $pdo->prepare("SELECT
            COALESCE(SUM(CASE WHEN state='available' THEN amount_minor ELSE 0 END),0) AS available,
            COALESCE(SUM(CASE WHEN state='pending'   THEN amount_minor ELSE 0 END),0) AS pending,
            COALESCE(SUM(amount_minor),0) AS total
        FROM wallet_transactions WHERE wallet_id=?");
    $stmt->execute([$walletId]);
    $row = $stmt->fetch() ?: ['available' => 0, 'pending' => 0, 'total' => 0];
    return [
        'available_minor' => (int)$row['available'],
        'pending_minor'   => (int)$row['pending'],
        'total_minor'     => (int)$row['total'],
        'currency'        => $currency ?? currency_code(),
    ];
}

/**
 * Lifetime figures for the wallet page: earned, commission paid, withdrawn.
 * Derived from the ledger so the numbers cannot disagree with the statement below.
 */
function wallet_totals(PDO $pdo, int $walletId): array {
    $stmt = $pdo->prepare("SELECT
            COALESCE(SUM(CASE WHEN kind='payment' THEN gross_minor ELSE 0 END),0) AS gross_earned,
            COALESCE(SUM(CASE WHEN kind='payment' THEN net_minor ELSE 0 END),0) AS net_earned,
            COALESCE(SUM(CASE WHEN kind='commission' THEN -amount_minor ELSE 0 END),0) AS commission_total,
            COALESCE(SUM(CASE WHEN kind='withdrawal' THEN -amount_minor ELSE 0 END),0) AS withdrawn,
            COALESCE(SUM(CASE WHEN kind='withdrawal_reversal' THEN amount_minor ELSE 0 END),0) AS reversed
        FROM wallet_transactions WHERE wallet_id=?");
    $stmt->execute([$walletId]);
    $row = $stmt->fetch() ?: [];
    return [
        'gross_earned_minor' => (int)($row['gross_earned'] ?? 0),
        'net_earned_minor'   => (int)($row['net_earned'] ?? 0),
        'commission_minor'   => (int)($row['commission_total'] ?? 0),
        'withdrawn_minor'    => (int)($row['withdrawn'] ?? 0),
        'reversed_minor'     => (int)($row['reversed'] ?? 0),
    ];
}

/** The lawyer's ledger, newest first. */
function wallet_transactions(PDO $pdo, int $walletId, int $limit = 100, int $offset = 0): array {
    $limit = max(1, min(500, $limit));
    $offset = max(0, $offset);
    $stmt = $pdo->prepare('SELECT * FROM wallet_transactions WHERE wallet_id=? ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . $offset);
    $stmt->execute([$walletId]);
    return $stmt->fetchAll();
}

/* --------------------------------------------------------------- ledger ---- */

/**
 * Append one ledger row. The only way money enters or leaves a wallet.
 *
 * Callers must already hold a transaction when the write must be atomic with
 * something else; the UNIQUE key on `idempotency_key` is the second guard, so a
 * retry of the same event cannot double-post even without a transaction.
 *
 * @return array{ok:bool,id?:int,duplicate?:bool,error?:string}
 */
function wallet_ledger_add(PDO $pdo, int $walletId, array $entry): array {
    $lawyerId = (int)($entry['lawyer_id'] ?? 0);
    if ($lawyerId <= 0) {
        $stmt = $pdo->prepare('SELECT lawyer_id, firm_id, currency FROM lawyer_wallets WHERE id=?');
        $stmt->execute([$walletId]);
        $w = $stmt->fetch();
        if (!$w) return ['ok' => false, 'error' => 'wallet_not_found'];
        $lawyerId = (int)$w['lawyer_id'];
        $entry['firm_id']  = $entry['firm_id']  ?? $w['firm_id'];
        $entry['currency'] = $entry['currency'] ?? $w['currency'];
    }
    $kind  = (string)($entry['kind'] ?? '');
    $allowedKinds = ['payment','commission','refund','withdrawal','withdrawal_reversal','payout','adjustment'];
    if (!in_array($kind, $allowedKinds, true)) return ['ok' => false, 'error' => 'invalid_kind'];
    $state = (string)($entry['state'] ?? 'available');
    if (!in_array($state, ['available','pending'], true)) $state = 'available';

    $amountMinor = (int)($entry['amount_minor'] ?? 0);
    if ($amountMinor === 0) return ['ok' => false, 'error' => 'zero_amount'];

    $idempotencyKey = isset($entry['idempotency_key']) ? trim((string)$entry['idempotency_key']) : null;
    if ($idempotencyKey === '') $idempotencyKey = null;

    if ($idempotencyKey !== null) {
        $dup = $pdo->prepare('SELECT id FROM wallet_transactions WHERE idempotency_key=?');
        $dup->execute([$idempotencyKey]);
        $found = $dup->fetchColumn();
        if ($found) return ['ok' => true, 'id' => (int)$found, 'duplicate' => true];
    }

    try {
        $stmt = $pdo->prepare('INSERT INTO wallet_transactions
            (wallet_id,lawyer_id,firm_id,currency,amount_minor,gross_minor,commission_minor,net_minor,kind,state,
             source_type,source_id,invoice_id,payment_id,withdrawal_id,description,idempotency_key,actor_type,actor_id)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([
            $walletId,
            $lawyerId,
            $entry['firm_id'] ?? null,
            $entry['currency'] ?? currency_code(),
            $amountMinor,
            (int)($entry['gross_minor'] ?? 0),
            (int)($entry['commission_minor'] ?? 0),
            (int)($entry['net_minor'] ?? 0),
            $kind,
            $state,
            $entry['source_type'] ?? null,
            isset($entry['source_id']) ? (int)$entry['source_id'] : null,
            isset($entry['invoice_id']) ? (int)$entry['invoice_id'] : null,
            isset($entry['payment_id']) ? (int)$entry['payment_id'] : null,
            isset($entry['withdrawal_id']) ? (int)$entry['withdrawal_id'] : null,
            isset($entry['description']) ? mb_substr((string)$entry['description'], 0, 255, 'UTF-8') : null,
            $idempotencyKey,
            in_array($entry['actor_type'] ?? 'system', ['system','admin','lawyer','client'], true) ? $entry['actor_type'] : 'system',
            isset($entry['actor_id']) ? (int)$entry['actor_id'] : null,
        ]);
        return ['ok' => true, 'id' => (int)$pdo->lastInsertId()];
    } catch (PDOException $e) {
        // 1062 = a concurrent identical event already landed. Treat as success: the
        // money is in the ledger exactly once, which is the contract.
        if (($e->errorInfo[1] ?? 0) === 1062 && $idempotencyKey !== null) {
            $dup = $pdo->prepare('SELECT id FROM wallet_transactions WHERE idempotency_key=?');
            $dup->execute([$idempotencyKey]);
            $found = $dup->fetchColumn();
            if ($found) return ['ok' => true, 'id' => (int)$found, 'duplicate' => true];
        }
        error_log('wallet_ledger_add failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'ledger_write_failed'];
    }
}

/* ----------------------------------------------------------- commission ---- */

/**
 * The commission rate that applies to a firm (percent, 0–100).
 *
 * No rule means ZERO commission: the lawyer keeps the whole collected amount. That
 * is the only safe default — inventing a cut nobody agreed to would take money from
 * a lawyer's fee. The rate is a commercial decision made in the admin console.
 */
function wallet_commission_rate(PDO $pdo, ?int $firmId, string $serviceKey = 'default'): float {
    $stmt = $pdo->prepare("SELECT rate_percent FROM wallet_commission_rules
        WHERE is_active=1 AND firm_id <=> ? AND service_key IN (?, 'default')
        ORDER BY (service_key = ?) DESC, (firm_id IS NOT NULL) DESC, id DESC LIMIT 1");
    try {
        $stmt->execute([$firmId, $serviceKey, $serviceKey]);
    } catch (Throwable $e) {
        // The table is absent on a database that has not run upgrade_v66.sql. Billing
        // must keep working there, so the rate degrades to zero rather than fataling.
        error_log('wallet_commission_rate unavailable: ' . $e->getMessage());
        return 0.0;
    }
    $rate = $stmt->fetchColumn();
    if ($rate === false || $rate === null) return 0.0;
    $rate = (float)$rate;
    if (!is_finite($rate) || $rate < 0) return 0.0;
    return min(100.0, $rate);
}

/** Apply a percentage to minor units, rounding half-up on the minor unit. */
function wallet_apply_percent(int $minor, float $percent): int {
    if ($minor <= 0 || $percent <= 0) return 0;
    return (int)round($minor * ($percent / 100.0), 0, PHP_ROUND_HALF_UP);
}

/* ------------------------------------------------------------- payouts ---- */

/**
 * Credit a lawyer's wallet for a client payment on their invoice.
 *
 * Called from finance_record_payment() after the payment has committed. It is
 * idempotent on `payment_id`, so a retried hook credits exactly once.
 *
 * @return array{ok:bool,credited?:bool,duplicate?:bool,error?:string}
 */
function wallet_credit_invoice_payment(PDO $pdo, int $invoiceId, int $paymentId): array {
    $stmt = $pdo->prepare('SELECT i.id, i.lawyer_id, i.firm_id, i.currency, p.amount
        FROM invoice_payments p JOIN invoices i ON i.id = p.invoice_id WHERE p.id = ?');
    $stmt->execute([$paymentId]);
    $row = $stmt->fetch();
    if (!$row) return ['ok' => false, 'error' => 'payment_not_found'];

    $lawyerId = (int)$row['lawyer_id'];
    if ($lawyerId <= 0) return ['ok' => false, 'error' => 'no_lawyer'];

    $wallet = wallet_ensure($pdo, $lawyerId);

    // Currency must match the wallet. Converting silently would invent an exchange
    // rate the platform has no basis for, and summing mixed units corrupts the ledger.
    $paymentCurrency = (string)($row['currency'] ?: currency_code());
    if ($paymentCurrency !== (string)$wallet['currency']) {
        error_log("wallet: payment currency $paymentCurrency does not match wallet currency {$wallet['currency']} for invoice $invoiceId");
        return ['ok' => false, 'error' => 'currency_mismatch'];
    }

    $grossMinor = wallet_to_minor((float)$row['amount'], $paymentCurrency);
    if ($grossMinor <= 0) return ['ok' => false, 'error' => 'zero_amount'];

    $rate = wallet_commission_rate($pdo, $wallet['firm_id'] !== null ? (int)$wallet['firm_id'] : null);
    $commissionMinor = wallet_apply_percent($grossMinor, $rate);
    // The lawyer never receives less than nothing: a rate of 100% is the ceiling.
    $netMinor = max(0, $grossMinor - $commissionMinor);

    $owns = !$pdo->inTransaction();
    if ($owns) $pdo->beginTransaction();
    try {
        $credit = wallet_ledger_add($pdo, (int)$wallet['id'], [
            'lawyer_id'       => $lawyerId,
            'firm_id'         => $wallet['firm_id'],
            'currency'        => $paymentCurrency,
            // The ledger credits the GROSS and the commission is debited as its own row
            // below. Crediting `net` here and then debiting the cut would subtract the
            // commission twice, so the lawyer would keep 80% of a 10% cut.
            'amount_minor'    => $grossMinor,
            'gross_minor'     => $grossMinor,
            'commission_minor'=> $commissionMinor,
            'net_minor'       => $netMinor,
            'kind'            => 'payment',
            'state'           => 'available',
            'source_type'     => 'invoice_payment',
            'source_id'       => $paymentId,
            'invoice_id'      => $invoiceId,
            'payment_id'      => $paymentId,
            'description'     => 'دفعة فاتورة',
            'idempotency_key' => 'wallet_pay_' . $paymentId,
            'actor_type'      => 'system',
        ]);
        if (!$credit['ok']) {
            if ($owns && $pdo->inTransaction()) $pdo->rollBack();
            return $credit;
        }
        // The commission is keyed on its own idempotency key rather than on whether the
        // credit was a duplicate: if a first attempt died between the credit and the
        // commission, a retry must still write the missing cut. Relying on the credit's
        // duplicate flag would leave the lawyer over-credited by the whole commission.
        if ($commissionMinor > 0) {
            wallet_ledger_add($pdo, (int)$wallet['id'], [
                'lawyer_id'       => $lawyerId,
                'firm_id'         => $wallet['firm_id'],
                'currency'        => $paymentCurrency,
                'amount_minor'    => -$commissionMinor,
                'commission_minor'=> $commissionMinor,
                'kind'            => 'commission',
                'state'           => 'available',
                'source_type'     => 'invoice_payment',
                'source_id'       => $paymentId,
                'invoice_id'      => $invoiceId,
                'payment_id'      => $paymentId,
                'description'     => 'عمولة المنصة',
                'idempotency_key' => 'wallet_comm_' . $paymentId,
                'actor_type'      => 'system',
            ]);
        }
        if ($owns) $pdo->commit();
        return ['ok' => true, 'credited' => true, 'duplicate' => !empty($credit['duplicate']), 'net_minor' => $netMinor];
    } catch (Throwable $e) {
        if ($owns && $pdo->inTransaction()) $pdo->rollBack();
        error_log('wallet_credit_invoice_payment failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'credit_failed'];
    }
}

/* ---------------------------------------------------------- withdrawals ---- */

function wallet_withdrawal_statuses(): array {
    return ['pending','approved','rejected','paid','failed'];
}

/** Human label for a ledger row's kind. */
function wallet_kind_label(string $kind): string {
    $rtl = function_exists('is_rtl') ? is_rtl() : true;
    $map = [
        'payment'             => ['دفعة فاتورة', 'Invoice payment'],
        'commission'          => ['عمولة المنصة', 'Platform commission'],
        'refund'              => ['استرداد', 'Refund'],
        'withdrawal'          => ['حجز سحب', 'Withdrawal reserved'],
        'withdrawal_reversal' => ['إرجاع سحب', 'Withdrawal returned'],
        'payout'              => ['صرف', 'Payout'],
        'adjustment'          => ['تسوية', 'Adjustment'],
    ];
    $pair = $map[$kind] ?? [$kind, $kind];
    return $rtl ? $pair[0] : $pair[1];
}

function wallet_withdrawal_status_label(string $status): string {
    $rtl = function_exists('is_rtl') ? is_rtl() : true;
    $map = [
        'pending'  => ['قيد المراجعة', 'Pending review'],
        'approved' => ['تمت الموافقة', 'Approved'],
        'rejected' => ['مرفوض', 'Rejected'],
        'paid'     => ['تم الصرف', 'Paid'],
        'failed'   => ['فشل الصرف', 'Failed'],
    ];
    $pair = $map[$status] ?? [$status, $status];
    return $rtl ? $pair[0] : $pair[1];
}

/** The lawyer's own withdrawal requests, newest first. */
function wallet_withdrawals(PDO $pdo, int $walletId, int $limit = 50): array {
    $limit = max(1, min(200, $limit));
    $stmt = $pdo->prepare('SELECT * FROM wallet_withdrawals WHERE wallet_id=? ORDER BY id DESC LIMIT ' . $limit);
    $stmt->execute([$walletId]);
    return $stmt->fetchAll();
}

/**
 * A lawyer asks to withdraw. No money is reserved yet — that happens on approval,
 * so an unreviewed request does not lock a balance the lawyer may still need.
 *
 * @return array{ok:bool,id?:int,error?:string,available_minor?:int}
 */
function wallet_request_withdrawal(PDO $pdo, int $lawyerId, int $amountMinor, array $options = []): array {
    if ($amountMinor <= 0) return ['ok' => false, 'error' => 'amount_invalid'];

    $wallet = wallet_ensure($pdo, $lawyerId);
    if ($wallet['status'] !== 'active') return ['ok' => false, 'error' => 'wallet_not_active'];

    $method = (string)($options['method'] ?? 'bank_transfer');
    $allowed = ['bank_transfer','cash','other'];
    if (!in_array($method, $allowed, true)) $method = 'bank_transfer';

    $owns = !$pdo->inTransaction();
    if ($owns) $pdo->beginTransaction();
    try {
        // Lock the wallet row so two simultaneous requests cannot both read the same
        // balance and both pass the check — the classic double-spend on a withdrawal.
        $lock = $pdo->prepare('SELECT id, status FROM lawyer_wallets WHERE id=? FOR UPDATE');
        $lock->execute([(int)$wallet['id']]);
        if (!$lock->fetch()) {
            if ($owns) $pdo->rollBack();
            return ['ok' => false, 'error' => 'wallet_not_found'];
        }

        $balances = wallet_balances($pdo, (int)$wallet['id'], (string)$wallet['currency']);
        $available = $balances['available_minor'];

        // Only *pending* requests need subtracting: an approved one has already debited
        // the ledger, so `available` above is net of it and subtracting again would
        // count the same reservation twice and refuse a legitimate request.
        $reservedStmt = $pdo->prepare("SELECT COALESCE(SUM(amount_minor),0) FROM wallet_withdrawals
            WHERE wallet_id=? AND status = 'pending'");
        $reservedStmt->execute([(int)$wallet['id']]);
        $reserved = (int)$reservedStmt->fetchColumn();
        $free = $available - $reserved;

        if ($amountMinor > $free) {
            if ($owns) $pdo->rollBack();
            return ['ok' => false, 'error' => 'insufficient_balance', 'available_minor' => $free];
        }

        $accountSnapshot = wallet_mask_payout_details(wallet_payout_details($pdo, $wallet));
        $stmt = $pdo->prepare('INSERT INTO wallet_withdrawals
            (wallet_id,lawyer_id,firm_id,amount_minor,currency,status,method,account_snapshot,requested_note)
            VALUES (?,?,?,?,?,?,?,?,?)');
        $stmt->execute([
            (int)$wallet['id'], $lawyerId, $wallet['firm_id'], $amountMinor, (string)$wallet['currency'],
            'pending', $method, $accountSnapshot,
            isset($options['note']) ? mb_substr((string)$options['note'], 0, 500, 'UTF-8') : null,
        ]);
        $id = (int)$pdo->lastInsertId();
        if ($owns) $pdo->commit();
        return ['ok' => true, 'id' => $id, 'available_minor' => $free];
    } catch (Throwable $e) {
        if ($owns && $pdo->inTransaction()) $pdo->rollBack();
        error_log('wallet_request_withdrawal failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'request_failed'];
    }
}

/**
 * Move a withdrawal through its lifecycle. Every change goes through here: the
 * transition table and the ledger effect live in one place, so an admin page cannot
 * pay a withdrawal with no transfer reference or approve the same request twice.
 *
 * @return array{ok:bool,error?:string,status?:string}
 */
function wallet_withdrawal_transition(PDO $pdo, int $withdrawalId, string $to, array $options = []): array {
    $allowed = [
        'pending'  => ['approved','rejected'],
        'approved' => ['paid','failed'],
        'rejected' => [],
        'paid'     => [],
        'failed'   => [],
    ];
    $stmt = $pdo->prepare('SELECT * FROM wallet_withdrawals WHERE id=?');
    $stmt->execute([$withdrawalId]);
    $wd = $stmt->fetch();
    if (!$wd) return ['ok' => false, 'error' => 'not_found'];

    $from = (string)$wd['status'];
    if (!isset($allowed[$from]) || !in_array($to, $allowed[$from], true)) {
        return ['ok' => false, 'error' => 'invalid_transition'];
    }

    $adminId = isset($options['admin_id']) ? (int)$options['admin_id'] : null;
    $note    = isset($options['note']) ? mb_substr(trim((string)$options['note']), 0, 500, 'UTF-8') : null;
    $reference = isset($options['reference']) ? mb_substr(trim((string)$options['reference']), 0, 120, 'UTF-8') : null;

    // Evidence rule: money is never marked sent without a reference to point at.
    if ($to === 'paid' && ($reference === null || $reference === '')) {
        return ['ok' => false, 'error' => 'reference_required'];
    }
    if ($to === 'rejected' && ($note === null || $note === '')) {
        return ['ok' => false, 'error' => 'reason_required'];
    }

    $owns = !$pdo->inTransaction();
    if ($owns) $pdo->beginTransaction();
    try {
        // Re-read under lock: a concurrent reviewer must not approve what was just paid.
        $lock = $pdo->prepare('SELECT * FROM wallet_withdrawals WHERE id=? FOR UPDATE');
        $lock->execute([$withdrawalId]);
        $current = $lock->fetch();
        if (!$current || (string)$current['status'] !== $from) {
            if ($owns) $pdo->rollBack();
            return ['ok' => false, 'error' => 'concurrent_change'];
        }

        $walletStmt = $pdo->prepare('SELECT * FROM lawyer_wallets WHERE id=?');
        $walletStmt->execute([(int)$wd['wallet_id']]);
        $wallet = $walletStmt->fetch();
        if (!$wallet) {
            if ($owns) $pdo->rollBack();
            return ['ok' => false, 'error' => 'wallet_not_found'];
        }

        $amountMinor = (int)$wd['amount_minor'];
        $currency = (string)$wd['currency'];

        if ($to === 'approved') {
            // Reserve the money now. If the ledger refuses, the approval does not happen.
            $reserve = wallet_ledger_add($pdo, (int)$wallet['id'], [
                'lawyer_id'       => (int)$wd['lawyer_id'],
                'firm_id'         => $wd['firm_id'],
                'currency'        => $currency,
                'amount_minor'    => -$amountMinor,
                'kind'            => 'withdrawal',
                'state'           => 'available',
                'source_type'     => 'withdrawal',
                'source_id'       => $withdrawalId,
                'withdrawal_id'   => $withdrawalId,
                'description'     => 'حجز مبلغ طلب سحب',
                'idempotency_key' => 'wallet_wd_reserve_' . $withdrawalId,
                'actor_type'      => 'admin',
                'actor_id'        => $adminId,
            ]);
            if (!$reserve['ok']) {
                if ($owns) $pdo->rollBack();
                return ['ok' => false, 'error' => $reserve['error'] ?? 'reserve_failed'];
            }
        }

        if ($to === 'failed') {
            // Return the reservation. The request stays failed; the money is spendable.
            wallet_ledger_add($pdo, (int)$wallet['id'], [
                'lawyer_id'       => (int)$wd['lawyer_id'],
                'firm_id'         => $wd['firm_id'],
                'currency'        => $currency,
                'amount_minor'    => $amountMinor,
                'kind'            => 'withdrawal_reversal',
                'state'           => 'available',
                'source_type'     => 'withdrawal',
                'source_id'       => $withdrawalId,
                'withdrawal_id'   => $withdrawalId,
                'description'     => 'إعادة مبلغ سحب فاشل',
                'idempotency_key' => 'wallet_wd_reverse_' . $withdrawalId,
                'actor_type'      => 'admin',
                'actor_id'        => $adminId,
            ]);
        }

        if ($to === 'paid') {
            // No ledger row here on purpose. The money left the balance at approval,
            // where the reservation debit was written; this transition only records that
            // the transfer was actually sent, which is what `status='paid'` plus
            // `transfer_reference` says. Writing a second, zero-amount row would add a
            // line to the statement that moves nothing and explains nothing.
        }

        $pdo->prepare("UPDATE wallet_withdrawals
            SET status=?, review_note=COALESCE(?, review_note), transfer_reference=COALESCE(?, transfer_reference),
                reviewed_by_admin_id=COALESCE(?, reviewed_by_admin_id), reviewed_at=COALESCE(reviewed_at, NOW()),
                paid_at=CASE WHEN ? = 'paid' THEN NOW() ELSE paid_at END
            WHERE id=?")
            ->execute([$to, $note, $reference, $adminId, $to, $withdrawalId]);

        if ($owns) $pdo->commit();
    } catch (Throwable $e) {
        if ($owns && $pdo->inTransaction()) $pdo->rollBack();
        error_log('wallet_withdrawal_transition failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'transition_failed'];
    }

    // Notifications and the audit trail sit outside the transaction on purpose: they
    // observe the change, they do not participate in it, and a mail failure must not
    // undo a completed payout.
    //
    // `notify()` and `log_action()` are guarded because this module is also called from
    // contexts that do not load the notification/audit layer (a CLI test, a cron job).
    // An unguarded call there is a fatal that would abort after the money already moved.
    if (function_exists('notify')) {
        $title = function_exists('is_rtl') && is_rtl()
            ? 'تحديث طلب السحب'
            : 'Withdrawal request updated';
        $body = (function_exists('is_rtl') && is_rtl() ? 'حالة طلب السحب الآن: ' : 'Your withdrawal request is now: ')
            . wallet_withdrawal_status_label($to);
        notify($pdo, 'lawyer', (int)$wd['lawyer_id'], $title, $body, '/lawyer/wallet.php');
    }
    if (function_exists('log_action')) {
        log_action($pdo, 'تحديث حالة طلب سحب إلى ' . $to, 'wallet_withdrawal', $withdrawalId);
    }

    return ['ok' => true, 'status' => $to];
}

/** Withdrawal requests awaiting review, for the admin console. */
function wallet_pending_withdrawals(PDO $pdo, int $limit = 50): array {
    $limit = max(1, min(200, $limit));
    $stmt = $pdo->prepare("SELECT w.*, l.full_name AS lawyer_name, l.email AS lawyer_email
        FROM wallet_withdrawals w JOIN lawyers l ON l.id = w.lawyer_id
        WHERE w.status IN ('pending','approved') ORDER BY w.id ASC LIMIT " . $limit);
    $stmt->execute();
    return $stmt->fetchAll();
}

/* ------------------------------------------------------- payout account ---- */

/**
 * Store the payout account, encrypted at rest.
 *
 * The details hold an IBAN or an account number, which is a credential: the database
 * is copied in every backup and support ticket, so plaintext there is a disclosure.
 * Encryption uses the same key the gateway credentials use.
 */
function wallet_store_payout_details(PDO $pdo, int $walletId, array $details, ?string $method = null, ?string $holder = null): bool {
    $plain = json_encode($details, JSON_UNESCAPED_UNICODE);
    if ($plain === false) return false;
    $encrypted = secret_encrypt_value($plain);
    if ($encrypted === null) {
        // No encryption key configured. Refusing is correct: storing an IBAN in the
        // clear is worse than not storing it.
        error_log('wallet: refusing to store payout details without an encryption key');
        return false;
    }
    $stmt = $pdo->prepare('UPDATE lawyer_wallets SET payout_details_encrypted=?, payout_method=?, payout_holder_name=? WHERE id=?');
    $stmt->execute([$encrypted, $method, $holder !== null ? mb_substr($holder, 0, 160, 'UTF-8') : null, $walletId]);
    return true;
}

/** Read back the payout account. Never returned by an API; the UI shows a mask. */
function wallet_payout_details(PDO $pdo, array $wallet): array {
    if (empty($wallet['payout_details_encrypted'])) return [];
    $plain = secret_decrypt_value((string)$wallet['payout_details_encrypted']);
    if ($plain === '') return [];
    $decoded = json_decode($plain, true);
    return is_array($decoded) ? $decoded : [];
}

/** A display-safe mask: enough to recognise the account, not enough to use it. */
function wallet_mask_payout_details(array $details): string {
    if (!$details) return '';
    $parts = [];
    foreach ($details as $key => $value) {
        $value = (string)$value;
        if ($value === '') continue;
        $len = mb_strlen($value);
        $tail = $len > 4 ? mb_substr($value, -4) : $value;
        $parts[] = $key . ': ••••' . $tail;
    }
    return mb_substr(implode(' · ', $parts), 0, 255);
}

/* ------------------------------------------------------------ statements ---- */

/**
 * A statement for a date range: opening balance, rows, closing balance.
 *
 * The opening balance is computed, not stored, so the statement always reconciles:
 * opening + sum(rows) = closing, by construction.
 */
function wallet_statement(PDO $pdo, int $walletId, ?string $from = null, ?string $to = null): array {
    $from = $from && strtotime($from) ? date('Y-m-d 00:00:00', strtotime($from)) : null;
    $to   = $to && strtotime($to) ? date('Y-m-d 23:59:59', strtotime($to)) : null;

    $openingMinor = 0;
    if ($from !== null) {
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount_minor),0) FROM wallet_transactions WHERE wallet_id=? AND created_at < ?');
        $stmt->execute([$walletId, $from]);
        $openingMinor = (int)$stmt->fetchColumn();
    }

    $sql = 'SELECT * FROM wallet_transactions WHERE wallet_id=?';
    $params = [$walletId];
    if ($from !== null) { $sql .= ' AND created_at >= ?'; $params[] = $from; }
    if ($to !== null)   { $sql .= ' AND created_at <= ?'; $params[] = $to; }
    $sql .= ' ORDER BY id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $movement = 0;
    foreach ($rows as $r) { $movement += (int)$r['amount_minor']; }

    return [
        'opening_minor' => $openingMinor,
        'movement_minor' => $movement,
        'closing_minor' => $openingMinor + $movement,
        'rows' => $rows,
    ];
}

/** Ledger rows for a firm, for the admin audit view. */
function wallet_firm_transactions(PDO $pdo, int $firmId, int $limit = 100): array {
    $limit = max(1, min(500, $limit));
    $stmt = $pdo->prepare('SELECT t.*, l.full_name AS lawyer_name FROM wallet_transactions t
        LEFT JOIN lawyers l ON l.id = t.lawyer_id
        WHERE t.firm_id = ? ORDER BY t.id DESC LIMIT ' . $limit);
    $stmt->execute([$firmId]);
    return $stmt->fetchAll();
}

/** Every wallet with its live balance, for the admin console list. */
function wallet_admin_overview(PDO $pdo, int $limit = 200): array {
    $limit = max(1, min(500, $limit));
    $stmt = $pdo->prepare("SELECT w.*, l.full_name AS lawyer_name, l.email AS lawyer_email,
            COALESCE((SELECT SUM(amount_minor) FROM wallet_transactions t WHERE t.wallet_id=w.id AND t.state='available'),0) AS available_minor,
            COALESCE((SELECT SUM(amount_minor) FROM wallet_transactions t WHERE t.wallet_id=w.id AND t.state='pending'),0) AS pending_minor
        FROM lawyer_wallets w LEFT JOIN lawyers l ON l.id = w.lawyer_id
        ORDER BY available_minor DESC, w.id ASC LIMIT " . $limit);
    $stmt->execute();
    return $stmt->fetchAll();
}
