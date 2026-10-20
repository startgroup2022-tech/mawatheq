<?php
/**
 * Payout engine (Execution 31).
 *
 * The path from earned commission to money in a bank account:
 *
 *   requested → under_review → approved → processing → paid
 *                    ↘ rejected / failed / cancelled
 *
 * CONTROLS, and what each one is for
 *   • Only `approved` and `payable` commissions can be requested. `holding` is
 *     excluded because the hold period exists precisely to keep money unavailable
 *     until the refund window has passed; letting a payout draw on it would defeat
 *     the hold entirely.
 *   • One open request at a time. A second request while one is pending would let an
 *     affiliate request the same balance twice and be paid twice if both cleared.
 *   • Dual approval above a threshold: two distinct administrators. The second
 *     approver is compared by id, so the same person cannot satisfy both steps.
 *   • Payout details are encrypted at rest and never displayed in full.
 *   • A manual bank payout must carry an evidence reference before it can be marked
 *     paid. Without that, "paid" is an assertion nobody can check.
 */
require_once __DIR__ . '/affiliate.php';
require_once __DIR__ . '/affiliate_ledger.php';
require_once __DIR__ . '/affiliate_fraud.php';

/** Payout request states that are still open (money not yet settled). */
function affiliate_payout_open_states(): array {
    return ['requested', 'under_review', 'approved', 'processing'];
}

/** Generate a unique payout reference, e.g. PAY-2026-000042. */
function affiliate_next_payout_reference(PDO $pdo): string {
    $prefix = 'PAY-' . date('Y') . '-';
    try {
        $stmt = $pdo->prepare('SELECT reference FROM affiliate_payout_requests WHERE reference LIKE ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$prefix . '%']);
        $last = (string)($stmt->fetchColumn() ?: '');
        $next = $last !== '' ? ((int)substr($last, strlen($prefix)) + 1) : 1;
    } catch (Throwable $e) {
        $next = random_int(1, 999999);
    }
    return $prefix . str_pad((string)$next, 6, '0', STR_PAD_LEFT);
}

/**
 * The commissions a payout may draw on: approved or payable, not already assigned to
 * a payout.
 */
function affiliate_payable_commissions(PDO $pdo, int $affiliateId, ?string $currency = null): array {
    $currency = $currency ?: affiliate_payout_currency_for($pdo, $affiliateId);
    try {
        $stmt = $pdo->prepare("SELECT * FROM affiliate_commissions
            WHERE affiliate_id=? AND currency=? AND status IN ('approved','payable') AND payout_id IS NULL
            ORDER BY id");
        $stmt->execute([$affiliateId, $currency]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** The total the affiliate could request right now, as integer minor units. */
function affiliate_payable_minor(PDO $pdo, int $affiliateId, ?string $currency = null): int {
    $currency = $currency ?: affiliate_payout_currency_for($pdo, $affiliateId);
    $total = 0;
    foreach (affiliate_payable_commissions($pdo, $affiliateId, $currency) as $commission) {
        $total += affiliate_to_minor($commission['commission_amount'], $currency) - affiliate_to_minor($commission['reversed_amount'], $currency);
    }
    return max(0, $total);
}

/** An open payout request for the affiliate, if any. */
function affiliate_open_payout_request(PDO $pdo, int $affiliateId): ?array {
    $states = affiliate_payout_open_states();
    $in = implode(',', array_fill(0, count($states), '?'));
    try {
        $stmt = $pdo->prepare("SELECT * FROM affiliate_payout_requests WHERE affiliate_id=? AND status IN ($in) ORDER BY id DESC LIMIT 1");
        $stmt->execute(array_merge([$affiliateId], $states));
        return $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Create a payout request.
 *
 * @param array $options method, details (array), note
 * @return array{ok:bool,request_id?:int,reference?:string,amount?:string,error?:string}
 */
function affiliate_request_payout(PDO $pdo, int $affiliateId, ?string $requestedAmount = null, array $options = []): array {
    $settings = affiliate_settings($pdo);
    if (!$settings['program_enabled']) return ['ok' => false, 'error' => 'program_disabled'];

    $affiliate = affiliate_find($pdo, $affiliateId);
    if (!$affiliate) return ['ok' => false, 'error' => 'not_found'];
    if (!affiliate_can_earn($affiliate)) return ['ok' => false, 'error' => 'affiliate_not_active'];

    $currency = (string)($options['currency'] ?? affiliate_payout_currency($pdo, $affiliate));

    // One open request at a time.
    if (affiliate_open_payout_request($pdo, $affiliateId)) {
        return ['ok' => false, 'error' => 'open_request_exists'];
    }

    $payableMinor = affiliate_payable_minor($pdo, $affiliateId, $currency);
    if ($payableMinor <= 0) return ['ok' => false, 'error' => 'nothing_payable'];

    $amountMinor = $requestedAmount === null || $requestedAmount === ''
        ? $payableMinor
        : affiliate_to_minor($requestedAmount, $currency);

    if ($amountMinor <= 0) return ['ok' => false, 'error' => 'amount_invalid'];
    if ($amountMinor > $payableMinor) return ['ok' => false, 'error' => 'amount_exceeds_payable', 'payable' => affiliate_from_minor($payableMinor, $currency)];

    $minMinor = affiliate_to_minor($settings['min_payout'], $currency);
    if ($minMinor > 0 && $amountMinor < $minMinor) {
        return ['ok' => false, 'error' => 'below_minimum', 'minimum' => affiliate_from_minor($minMinor, $currency)];
    }

    $method = (string)($options['method'] ?? 'bank_transfer');
    if (!in_array($method, ['bank_transfer', 'cheque'], true)) $method = 'bank_transfer';
    $reference = affiliate_next_payout_reference($pdo);

    // One transaction for the request row and the reservation of the commissions it
    // draws on. Without it a crash between the two leaves a request that reserves
    // nothing (or a commission reserved by a request that does not exist), and the
    // affiliate can then request the same balance twice.
    try {
        $pdo->beginTransaction();

        $pdo->prepare('INSERT INTO affiliate_payout_requests (affiliate_id, reference, amount, currency, status, method, affiliate_note)
            VALUES (?,?,?,?,?,?,?)')
            ->execute([
                $affiliateId, $reference, affiliate_from_minor($amountMinor, $currency), $currency,
                'requested', $method,
                isset($options['note']) ? mb_substr((string)$options['note'], 0, 500, 'UTF-8') : null,
            ]);
        $requestId = (int)$pdo->lastInsertId();

        // Reserve the commissions against this request so they cannot be included in a
        // second one while this is open. `payout_id` points at the request until it is
        // settled, then is repointed at the payout record. The UPDATE carries its own
        // `payout_id IS NULL` guard and its row count decides whether the reservation
        // actually took, so a commission already claimed by another request is skipped
        // rather than double-spent.
        $remaining = $amountMinor;
        $claimed = 0;
        $claim = $pdo->prepare("UPDATE affiliate_commissions SET payout_id=?, status=CASE WHEN status='approved' THEN 'payable' ELSE status END WHERE id=? AND payout_id IS NULL");
        foreach (affiliate_payable_commissions($pdo, $affiliateId, $currency) as $commission) {
            if ($remaining <= 0) break;
            $commissionMinor = affiliate_to_minor($commission['commission_amount'], $currency) - affiliate_to_minor($commission['reversed_amount'], $currency);
            if ($commissionMinor <= 0) continue;
            $claim->execute([$requestId, (int)$commission['id']]);
            if ($claim->rowCount() > 0) { $remaining -= $commissionMinor; $claimed++; }
        }
        if ($claimed === 0) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'nothing_payable'];
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('affiliate_request_payout: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'create_failed'];
    }

    // Payout details are stored separately and only if they can be encrypted. A
    // failure here is reported rather than swallowed: an operator needs to know the
    // affiliate will have to supply them again before the transfer can be made.
    $detailsStored = true;
    if (!empty($options['details']) && is_array($options['details'])) {
        $detailsStored = affiliate_store_payout_details($pdo, $requestId, $options['details']);
        if ($detailsStored) {
            affiliate_flag_payout_change($pdo, $affiliateId, $requestId, $options['details']);
        }
    }

    affiliate_audit($pdo, 'payout_requested', [
        'affiliate_id' => $affiliateId, 'subject_type' => 'payout_request', 'subject_id' => $requestId,
        'to_state' => 'requested',
        'detail' => 'amount=' . affiliate_from_minor($amountMinor, $currency) . ' method=' . $method,
    ]);
    affiliate_notify($pdo, $affiliateId, 'payout_requested:' . $requestId, 'payout_requested',
        is_rtl() ? 'تم استلام طلب الصرف' : 'Your payout request was received',
        is_rtl() ? 'المبلغ: ' . affiliate_format_money(affiliate_from_minor($amountMinor, $currency), $currency) . ' — المرجع ' . $reference
                 : 'Amount: ' . affiliate_format_money(affiliate_from_minor($amountMinor, $currency), $currency) . ' — reference ' . $reference,
        '/affiliate/payouts.php');

    return [
        'ok' => true, 'request_id' => $requestId, 'reference' => $reference,
        'amount' => affiliate_from_minor($amountMinor, $currency),
        'details_stored' => $detailsStored,
    ];
}

/**
 * Move a payout request to a new state, enforcing the approval controls.
 *
 * @return array{ok:bool,from:string,to:string,error?:string}
 */
function affiliate_payout_transition(PDO $pdo, int $requestId, string $to, array $options = []): array {
    $request = affiliate_payout_request_find($pdo, $requestId);
    if (!$request) return ['ok' => false, 'from' => '', 'to' => $to, 'error' => 'not_found'];
    $from = (string)$request['status'];

    $allowed = [
        'requested' => ['under_review', 'approved', 'rejected', 'cancelled'],
        'under_review' => ['approved', 'rejected', 'cancelled'],
        'approved' => ['processing', 'rejected', 'cancelled'],
        'processing' => ['paid', 'failed'],
        'paid' => [],
        'rejected' => [],
        'failed' => ['processing', 'cancelled'],
        'cancelled' => [],
    ];
    if (!in_array($to, $allowed[$from] ?? [], true)) {
        return ['ok' => false, 'from' => $from, 'to' => $to, 'error' => 'illegal_transition'];
    }

    $actorId = isset($options['actor_id']) ? (int)$options['actor_id'] : (int)($_SESSION['admin_id'] ?? 0);
    $settings = affiliate_settings($pdo);
    $currency = (string)$request['currency'];

    // Dual approval. The threshold is compared against the requested amount, and the
    // second approver must be a different administrator — comparing ids is what makes
    // "two approvals" mean two people rather than one person clicking twice.
    if ($to === 'approved') {
        $thresholdMinor = affiliate_to_minor($settings['dual_approval_threshold'], $currency);
        $amountMinor = affiliate_to_minor($request['amount'], $currency);
        if ($thresholdMinor > 0 && $amountMinor >= $thresholdMinor) {
            if ($actorId > 0 && (int)($request['approved_by'] ?? 0) === $actorId) {
                return ['ok' => false, 'from' => $from, 'to' => $to, 'error' => 'second_approver_required'];
            }
            if (empty($request['approved_by'])) {
                // First approval: record it and stop. The request stays where it is
                // until a second, different administrator approves.
                try {
                    $pdo->prepare('UPDATE affiliate_payout_requests SET approved_by=?, approved_at=NOW(), status=? WHERE id=?')
                        ->execute([$actorId ?: null, 'under_review', $requestId]);
                } catch (Throwable $e) {
                    return ['ok' => false, 'from' => $from, 'to' => $to, 'error' => 'update_failed'];
                }
                affiliate_audit($pdo, 'payout_first_approval', [
                    'affiliate_id' => (int)$request['affiliate_id'], 'subject_type' => 'payout_request', 'subject_id' => $requestId,
                    'from_state' => $from, 'to_state' => 'under_review',
                    'detail' => 'اعتماد أول — يلزم معتمد ثانٍ للمبلغ ' . affiliate_from_minor($amountMinor, $currency),
                ]);
                return ['ok' => true, 'from' => $from, 'to' => 'under_review', 'error' => 'awaiting_second_approval'];
            }
            // Second approval: record who it was, then fall through to the update.
            $options['second_approver'] = $actorId;
        }
    }

    // A manual bank payout needs evidence before it can be called paid.
    if ($to === 'paid' && empty($request['evidence_reference']) && empty($options['evidence_reference'])) {
        return ['ok' => false, 'from' => $from, 'to' => $to, 'error' => 'evidence_required'];
    }

    $sets = ['status = ?'];
    $params = [$to];
    $now = date('Y-m-d H:i:s');
    if ($to === 'under_review') { $sets[] = 'reviewed_by = ?'; $sets[] = 'reviewed_at = ?'; array_push($params, $actorId ?: null, $now); }
    if ($to === 'approved') {
        $sets[] = 'approved_by = ?'; $sets[] = 'approved_at = ?';
        array_push($params, $actorId ?: null, $now);
        if (!empty($options['second_approver'])) { $sets[] = 'second_approved_by = ?'; $sets[] = 'second_approved_at = ?'; array_push($params, (int)$options['second_approver'], $now); }
    }
    if ($to === 'processing') { $sets[] = 'processed_by = ?'; $sets[] = 'processed_at = ?'; array_push($params, $actorId ?: null, $now); }
    if ($to === 'paid') {
        $sets[] = 'paid_by = ?'; $sets[] = 'paid_at = ?';
        array_push($params, $actorId ?: null, $now);
        if (!empty($options['evidence_reference'])) { $sets[] = 'evidence_reference = ?'; $params[] = mb_substr((string)$options['evidence_reference'], 0, 120, 'UTF-8'); }
    }
    if (!empty($options['note'])) { $sets[] = ($to === 'rejected' ? 'review_note' : 'failure_reason') . ' = ?'; $params[] = mb_substr((string)$options['note'], 0, 500, 'UTF-8'); }
    $params[] = $requestId;

    try {
        $pdo->prepare('UPDATE affiliate_payout_requests SET ' . implode(', ', $sets) . ' WHERE id=?')->execute($params);
    } catch (Throwable $e) {
        error_log('affiliate_payout_transition: ' . $e->getMessage());
        return ['ok' => false, 'from' => $from, 'to' => $to, 'error' => 'update_failed'];
    }

    if ($to === 'paid') {
        affiliate_settle_payout($pdo, $request, $options);
    }
    if (in_array($to, ['rejected', 'cancelled'], true)) {
        // Release the reserved commissions so the affiliate can request again.
        try {
            $pdo->prepare("UPDATE affiliate_commissions SET payout_id=NULL, status=CASE WHEN status='payable' THEN 'approved' ELSE status END WHERE payout_id=?")
                ->execute([$requestId]);
        } catch (Throwable $e) { error_log('affiliate_payout_transition release: ' . $e->getMessage()); }
    }

    affiliate_audit($pdo, 'payout_status_changed', [
        'affiliate_id' => (int)$request['affiliate_id'], 'subject_type' => 'payout_request', 'subject_id' => $requestId,
        'from_state' => $from, 'to_state' => $to, 'detail' => (string)($options['note'] ?? ''),
    ]);

    $label = is_rtl() ? 'تحديث حالة طلب الصرف' : 'Your payout request status changed';
    affiliate_notify($pdo, (int)$request['affiliate_id'], 'payout_state:' . $requestId . ':' . $to, 'payout_status',
        $label,
        is_rtl() ? 'المرجع ' . (string)$request['reference'] . ' — الحالة: ' . $to : 'Reference ' . (string)$request['reference'] . ' — status: ' . $to,
        '/affiliate/payouts.php');

    return ['ok' => true, 'from' => $from, 'to' => $to];
}

/**
 * Create the payout record and post the ledger entries.
 *
 * The commissions reserved by the request are repointed at the payout record so the
 * link survives, and each is moved to `paid_out` — which is what removes it from the
 * payable balance and stops it being requested again.
 */
function affiliate_settle_payout(PDO $pdo, array $request, array $options = []): ?int {
    $currency = (string)$request['currency'];
    $reference = 'SET-' . (string)$request['reference'];
    try {
        $pdo->prepare('INSERT INTO affiliate_payouts (payout_request_id, affiliate_id, reference, amount, currency, method, status, paid_at, paid_by, bank_reference, evidence_reference, note)
            VALUES (?,?,?,?,?,?,?,NOW(),?,?,?,?)')
            ->execute([
                (int)$request['id'], (int)$request['affiliate_id'], $reference,
                affiliate_from_minor(affiliate_to_minor($request['amount'], $currency), $currency), $currency,
                (string)$request['method'], 'paid',
                isset($options['actor_id']) ? (int)$options['actor_id'] : null,
                isset($options['bank_reference']) ? mb_substr((string)$options['bank_reference'], 0, 120, 'UTF-8') : null,
                mb_substr((string)($options['evidence_reference'] ?? $request['evidence_reference'] ?? ''), 0, 120, 'UTF-8') ?: null,
                isset($options['note']) ? mb_substr((string)$options['note'], 0, 500, 'UTF-8') : null,
            ]);
        $payoutId = (int)$pdo->lastInsertId();

        // Move every commission reserved by this request to paid_out and repoint it at
        // the payout record.
        $commissions = $pdo->prepare('SELECT * FROM affiliate_commissions WHERE payout_id=?');
        $commissions->execute([(int)$request['id']]);
        $settled = $commissions->fetchAll();

        $update = $pdo->prepare("UPDATE affiliate_commissions SET status='paid_out', paid_out_at=NOW(), payout_id=? WHERE id=?");
        foreach ($settled as $commission) {
            $update->execute([$payoutId, (int)$commission['id']]);
            $pdo->prepare('UPDATE affiliate_conversions SET status=\'paid_out\' WHERE id=?')->execute([(int)$commission['conversion_id']]);
        }

        $payout = ['id' => $payoutId, 'affiliate_id' => (int)$request['affiliate_id'], 'reference' => $reference, 'amount' => $request['amount'], 'currency' => $currency];
        affiliate_ledger_payout($pdo, $payout, array_map(static fn($c) => (int)$c['id'], $settled), [
            'actor_type' => 'admin', 'actor_id' => $options['actor_id'] ?? null,
        ]);

        affiliate_stats_bump($pdo, (int)$request['affiliate_id'], ['paid_conversions' => count($settled)]);
        return $payoutId;
    } catch (Throwable $e) {
        error_log('affiliate_settle_payout: ' . $e->getMessage());
        return null;
    }
}

/** A payout request by id. */
function affiliate_payout_request_find(PDO $pdo, int $id): ?array {
    try {
        $stmt = $pdo->prepare('SELECT * FROM affiliate_payout_requests WHERE id=?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Payout requests for an affiliate. */
function affiliate_payout_requests(PDO $pdo, int $affiliateId, int $limit = 50): array {
    $limit = max(1, min(200, $limit));
    try {
        $stmt = $pdo->prepare('SELECT * FROM affiliate_payout_requests WHERE affiliate_id=? ORDER BY id DESC LIMIT ' . $limit);
        $stmt->execute([$affiliateId]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** Payout requests across the programme, for the admin queue. */
function affiliate_payout_queue(PDO $pdo, array $filters = []): array {
    $where = ['1=1'];
    $params = [];
    if (!empty($filters['status'])) { $where[] = 'r.status = ?'; $params[] = $filters['status']; }
    if (!empty($filters['affiliate_id'])) { $where[] = 'r.affiliate_id = ?'; $params[] = (int)$filters['affiliate_id']; }
    $limit = max(1, min(500, (int)($filters['limit'] ?? 200)));
    try {
        $stmt = $pdo->prepare('SELECT r.*, a.code, a.full_name, a.email,
                (SELECT COUNT(*) FROM affiliate_fraud_flags f WHERE f.payout_request_id = r.id AND f.status IN (\'open\',\'reviewing\')) AS open_flags
            FROM affiliate_payout_requests r
            JOIN affiliates a ON a.id = r.affiliate_id
            WHERE ' . implode(' AND ', $where) . ' ORDER BY FIELD(r.status,\'requested\',\'under_review\',\'approved\',\'processing\',\'paid\',\'rejected\',\'failed\',\'cancelled\'), r.id DESC LIMIT ' . $limit);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** Programme-wide payout totals. */
function affiliate_payout_summary(PDO $pdo): array {
    $out = ['requested' => '0', 'approved' => '0', 'paid' => '0', 'open_count' => 0, 'paid_count' => 0, 'currency' => affiliate_settings($pdo)['payout_currency']];
    try {
        $stmt = $pdo->query("SELECT status, COUNT(*) n, COALESCE(SUM(amount),0) total FROM affiliate_payout_requests GROUP BY status");
        foreach ($stmt->fetchAll() as $row) {
            $status = (string)$row['status'];
            if ($status === 'requested' || $status === 'under_review') { $out['requested'] = affiliate_round((string)((float)$out['requested'] + (float)$row['total']), $out['currency']); }
            if ($status === 'approved' || $status === 'processing') { $out['approved'] = affiliate_round((string)((float)$out['approved'] + (float)$row['total']), $out['currency']); }
            if ($status === 'paid') { $out['paid'] = affiliate_round((string)$row['total'], $out['currency']); $out['paid_count'] = (int)$row['n']; }
            if (in_array($status, ['requested', 'under_review', 'approved', 'processing'], true)) $out['open_count'] += (int)$row['n'];
        }
    } catch (Throwable $e) { /* pre-v57 database */ }
    return $out;
}

/** Payouts already settled, for the finance view. */
function affiliate_settled_payouts(PDO $pdo, int $limit = 100): array {
    $limit = max(1, min(500, $limit));
    try {
        $stmt = $pdo->prepare('SELECT p.*, a.code, a.full_name FROM affiliate_payouts p
            JOIN affiliates a ON a.id = p.affiliate_id ORDER BY p.id DESC LIMIT ' . $limit);
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}
