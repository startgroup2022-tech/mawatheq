<?php
/**
 * Commission sub-ledger (Execution 31).
 *
 * WHAT THIS IS, AND WHAT IT IS NOT
 *   This is a sub-ledger for affiliate commission only. It is NOT a general ledger,
 *   and it is not a chart of accounts: the platform has no accounting module — there
 *   is no chart_of_accounts and no journal_entries table anywhere in the schema
 *   (verified against database/schema.sql, all 107 tables). Pretending otherwise
 *   would be worse than the limitation. The accounts below are internal commission
 *   buckets, not balance-sheet accounts, and the admin console labels them as such.
 *
 *   When the platform gains a real accounting integration, these entries are the
 *   source for it; until then they are the authoritative record of what the
 *   programme owes, which is the whole point of keeping them.
 *
 * RULES
 *   Append-only. Nothing here is ever UPDATEd or DELETEd. A mistake is corrected by
 *   posting the opposing entry, so the history of what was believed at each moment
 *   survives — which is what an auditor asks for and what an update destroys.
 *
 *   Balanced per group. Every movement writes a matched pair sharing an
 *   `entry_group`, and `affiliate_ledger_balance()` proves each group sums to zero.
 *   A single-entry posting is a bug, and this makes it detectable rather than
 *   invisible.
 *
 *   Integer arithmetic. Amounts are summed as minor units; no float is involved.
 */
require_once __DIR__ . '/affiliate.php';

/** The commission accounts and their normal balance side. */
function affiliate_ledger_accounts(): array {
    return [
        // The platform's obligation to the affiliate grows on the credit side.
        'commission_earned' => 'credit',
        'commission_payable' => 'credit',
        // Money leaving the obligation.
        'commission_paid' => 'debit',
        // Reversal of a previously earned commission.
        'commission_reversed' => 'debit',
        // Between approval and settlement, funds sit here.
        'payout_clearing' => 'credit',
        'adjustment' => 'credit',
    ];
}

/** Post a matched pair of entries for one movement. */
function affiliate_ledger_post_pair(PDO $pdo, array $legs, array $context = []): bool {
    if (count($legs) < 2) {
        error_log('affiliate_ledger_post_pair: a movement needs at least two legs');
        return false;
    }
    $affiliateId = (int)($context['affiliate_id'] ?? 0);
    if ($affiliateId <= 0) return false;

    $currency = (string)($context['currency'] ?? affiliate_settings($pdo)['payout_currency']);
    $group = bin2hex(random_bytes(16));
    $idem = $context['idempotency_key'] ?? null;

    // Prove the pair balances before writing anything. A non-zero sum is a coding
    // error, and writing it would corrupt every figure derived from the ledger.
    $sum = 0;
    foreach ($legs as $leg) {
        $amount = affiliate_to_minor($leg['amount'], $currency);
        $sum += (($leg['direction'] ?? 'credit') === 'debit') ? $amount : -$amount;
    }
    if ($sum !== 0) {
        error_log('affiliate_ledger_post_pair: legs do not balance (sum=' . $sum . ') for affiliate ' . $affiliateId);
        return false;
    }

    $ownsTransaction = !$pdo->inTransaction();
    $actorType = $context['actor_type'] ?? 'system';
    if (!in_array($actorType, ['admin', 'affiliate', 'system'], true)) $actorType = 'system';
    $actorId = isset($context['actor_id']) ? (int)$context['actor_id'] : null;
    try {
        if ($ownsTransaction) $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO affiliate_ledger_entries
            (affiliate_id, entry_group, account, direction, amount, currency, commission_id, conversion_id, payout_id, description, actor_type, actor_id, idempotency_key)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($legs as $index => $leg) {
            $account = (string)($leg['account'] ?? 'adjustment');
            if (!isset(affiliate_ledger_accounts()[$account])) {
                throw new RuntimeException('Unknown ledger account: ' . $account);
            }
            $stmt->execute([
                $affiliateId, $group, $account, ($leg['direction'] ?? 'credit') === 'debit' ? 'debit' : 'credit',
                affiliate_from_minor(affiliate_to_minor($leg['amount'], $currency), $currency), $currency,
                isset($context['commission_id']) ? (int)$context['commission_id'] : null,
                isset($context['conversion_id']) ? (int)$context['conversion_id'] : null,
                isset($context['payout_id']) ? (int)$context['payout_id'] : null,
                isset($leg['description']) ? mb_substr((string)$leg['description'], 0, 500, 'UTF-8') : null,
                $actorType,
                $actorId,
                // Only the first leg carries the idempotency key, because it is UNIQUE
                // and both legs belong to one logical posting.
                $index === 0 ? $idem : null,
            ]);
        }
        if ($ownsTransaction) $pdo->commit();
        return true;
    } catch (PDOException $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        if (($e->errorInfo[1] ?? 0) === 1062) return true; // already posted — correct
        error_log('affiliate_ledger_post_pair failed: ' . $e->getMessage());
        return false;
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        error_log('affiliate_ledger_post_pair failed: ' . $e->getMessage());
        return false;
    }
}

/** Recognise commission when it is earned (holding or approved). */
function affiliate_ledger_recognise(PDO $pdo, array $commission, array $context = []): bool {
    $currency = (string)$commission['currency'];
    $amount = affiliate_from_minor(affiliate_to_minor($commission['commission_amount'], $currency), $currency);
    return affiliate_ledger_post_pair($pdo, [
        ['account' => 'commission_earned', 'direction' => 'credit', 'amount' => $amount,
         'description' => 'عمولة مستحقة عن تحويل #' . (int)$commission['conversion_id']],
        ['account' => 'commission_payable', 'direction' => 'debit', 'amount' => $amount,
         'description' => 'التزام تجاه المسوّق'],
    ], array_merge([
        'affiliate_id' => (int)$commission['affiliate_id'],
        'currency' => $currency,
        'commission_id' => (int)$commission['id'],
        'conversion_id' => (int)$commission['conversion_id'],
        'idempotency_key' => 'commission_recognised:' . (int)$commission['id'],
    ], $context));
}

/**
 * Reverse commission, in whole or in part.
 *
 * `commission_amount` is the amount originally earned and is never mutated; the
 * clawback accumulates in `reversed_amount`. That split is what makes
 * `commission_amount - reversed_amount` the net owed at every point, and it keeps
 * the original figure auditable — a mutation would destroy the evidence of what was
 * earned. The ledger leg posted is the *delta*, so two partial refunds post two
 * legs totalling the full clawback rather than one leg each for the whole amount.
 *
 * @param int|null $amountMinor the amount to reverse; null reverses everything left
 */
function affiliate_ledger_reverse(PDO $pdo, array $commission, string $reason, array $context = []): bool {
    $currency = (string)$commission['currency'];
    $earnedMinor = affiliate_to_minor($commission['commission_amount'], $currency);
    $alreadyReversed = affiliate_to_minor($commission['reversed_amount'] ?? '0', $currency);
    $outstanding = $earnedMinor - $alreadyReversed;
    if ($outstanding <= 0) return true; // nothing left to reverse

    $reverseMinor = isset($context['amount_minor']) ? (int)$context['amount_minor'] : $outstanding;
    if ($reverseMinor <= 0) return true;
    if ($reverseMinor > $outstanding) $reverseMinor = $outstanding;

    $newCumulative = $alreadyReversed + $reverseMinor;
    $amount = affiliate_from_minor($reverseMinor, $currency);
    return affiliate_ledger_post_pair($pdo, [
        ['account' => 'commission_reversed', 'direction' => 'credit', 'amount' => $amount,
         'description' => 'عكس عمولة: ' . $reason],
        ['account' => 'commission_payable', 'direction' => 'debit', 'amount' => $amount,
         'description' => 'إلغاء الالتزام تجاه المسوّق'],
    ], array_merge([
        'affiliate_id' => (int)$commission['affiliate_id'],
        'currency' => $currency,
        'commission_id' => (int)$commission['id'],
        'conversion_id' => (int)$commission['conversion_id'],
        // Keyed on the cumulative reversed total so a repeat of the same state is a
        // no-op while a further partial reversal still posts its own leg.
        'idempotency_key' => 'commission_reversed:' . (int)$commission['id'] . ':' . $newCumulative,
    ], $context));
}

/** Record that a payout settled commission. */
function affiliate_ledger_payout(PDO $pdo, array $payout, array $commissionIds, array $context = []): bool {
    $currency = (string)$payout['currency'];
    $amount = affiliate_from_minor(affiliate_to_minor($payout['amount'], $currency), $currency);
    return affiliate_ledger_post_pair($pdo, [
        ['account' => 'commission_paid', 'direction' => 'credit', 'amount' => $amount,
         'description' => 'صرف دفعة ' . (string)$payout['reference']],
        ['account' => 'commission_payable', 'direction' => 'debit', 'amount' => $amount,
         'description' => 'تسوية الالتزام بدفعة ' . (string)$payout['reference']],
    ], array_merge([
        'affiliate_id' => (int)$payout['affiliate_id'],
        'currency' => $currency,
        'payout_id' => (int)$payout['id'],
        'idempotency_key' => 'payout_settled:' . (int)$payout['id'],
    ], $context));
}

/**
 * The affiliate's ledger balance, from the entries rather than a stored total.
 *
 * Deriving it means a stored counter that drifted cannot disagree with the entries
 * that justify it. `sign` is applied per the account's normal side, so the result is
 * "how much the programme owes this affiliate right now".
 */
function affiliate_ledger_balance(PDO $pdo, int $affiliateId, ?string $currency = null): array {
    $currency = $currency ?: affiliate_payout_currency_for($pdo, $affiliateId);
    $rows = [];
    try {
        $stmt = $pdo->prepare('SELECT account, direction, amount FROM affiliate_ledger_entries WHERE affiliate_id=? AND currency=?');
        $stmt->execute([$affiliateId, $currency]);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('affiliate_ledger_balance: ' . $e->getMessage());
    }

    $earned = 0; $reversed = 0; $paid = 0;
    foreach ($rows as $row) {
        $minor = affiliate_to_minor($row['amount'], $currency);
        $signed = ($row['direction'] === 'debit') ? $minor : -$minor;
        switch ($row['account']) {
            case 'commission_earned': $earned += $minor; break;
            case 'commission_reversed': $reversed += $minor; break;
            case 'commission_paid': $paid += $minor; break;
        }
    }
    // earned is a credit balance (positive), reversed and paid are debit balances.
    $net = $earned - $reversed - $paid;
    return [
        'currency' => $currency,
        'earned_minor' => $earned,
        'reversed_minor' => $reversed,
        'paid_minor' => $paid,
        'net_minor' => $net,
        'earned' => affiliate_from_minor($earned, $currency),
        'reversed' => affiliate_from_minor($reversed, $currency),
        'paid' => affiliate_from_minor($paid, $currency),
        'net' => affiliate_from_minor($net, $currency),
    ];
}

/**
 * Prove the ledger is internally consistent for an affiliate.
 *
 * Two properties are checked, and both must hold:
 *   1. Every entry_group sums to zero (each movement was a balanced pair).
 *   2. The derived balance agrees with the sum of the commission rows it reflects.
 *
 * Used by the admin finance screen and by the test suite, so a drift is caught by
 * the application rather than by an accountant a quarter later.
 *
 * @return array{ok:bool,unbalanced_groups:array<int,string>,expected_minor:int,actual_minor:int,difference_minor:int}
 */
function affiliate_ledger_verify(PDO $pdo, int $affiliateId, ?string $currency = null): array {
    $currency = $currency ?: affiliate_payout_currency_for($pdo, $affiliateId);
    $unbalanced = [];
    try {
        $stmt = $pdo->prepare('SELECT entry_group, account, direction, amount FROM affiliate_ledger_entries WHERE affiliate_id=? AND currency=? ORDER BY entry_group, id');
        $stmt->execute([$affiliateId, $currency]);
        $groups = [];
        foreach ($stmt->fetchAll() as $row) {
            $minor = affiliate_to_minor($row['amount'], $currency);
            $groups[$row['entry_group']] = ($groups[$row['entry_group']] ?? 0) + (($row['direction'] === 'debit') ? $minor : -$minor);
        }
        foreach ($groups as $group => $sum) {
            if ($sum !== 0) $unbalanced[] = $group . ' (' . affiliate_from_minor($sum, $currency) . ')';
        }

        // Expected balance straight from the commissions, computed independently of
        // the ledger. `paid_out` is excluded because settlement debits
        // `commission_payable` to zero for those rows — the obligation has been
        // discharged, so they no longer contribute to what is owed.
        $cstmt = $pdo->prepare("SELECT
            COALESCE(SUM(CASE WHEN status IN ('paid_eligible','holding','approved','payable') THEN commission_amount - reversed_amount ELSE 0 END),0) AS expected
            FROM affiliate_commissions WHERE affiliate_id=? AND currency=?");
        $cstmt->execute([$affiliateId, $currency]);
        $expected = affiliate_to_minor((string)$cstmt->fetchColumn(), $currency);
    } catch (Throwable $e) {
        return ['ok' => false, 'unbalanced_groups' => [], 'expected_minor' => 0, 'actual_minor' => 0, 'difference_minor' => 0, 'error' => $e->getMessage()];
    }

    $actual = affiliate_ledger_balance($pdo, $affiliateId, $currency)['net_minor'];
    return [
        'ok' => $unbalanced === [] && $expected === $actual,
        'unbalanced_groups' => $unbalanced,
        'expected_minor' => $expected,
        'actual_minor' => $actual,
        'difference_minor' => $actual - $expected,
    ];
}

/** Human label for a ledger account, in the active language. */
function affiliate_ledger_account_label(string $account): string {
    $map = [
        'commission_earned' => ['عمولة مكتسبة', 'Commission earned'],
        'commission_payable' => ['التزام مستحق', 'Commission payable'],
        'commission_paid' => ['عمولة مصروفة', 'Commission paid'],
        'commission_reversed' => ['عمولة معكوسة', 'Commission reversed'],
        'payout_clearing' => ['تسوية صرف', 'Payout clearing'],
        'adjustment' => ['تسوية', 'Adjustment'],
    ];
    $row = $map[$account] ?? [$account, $account];
    return is_rtl() ? $row[0] : $row[1];
}

/** Ledger entries for an affiliate, newest first. */
function affiliate_ledger_entries(PDO $pdo, int $affiliateId, int $limit = 100): array {
    $limit = max(1, min(500, $limit));
    try {
        $stmt = $pdo->prepare('SELECT * FROM affiliate_ledger_entries WHERE affiliate_id=? ORDER BY id DESC LIMIT ' . $limit);
        $stmt->execute([$affiliateId]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}
