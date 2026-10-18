<?php
/**
 * Affiliate administration (Execution 31).
 *
 * The queries and state changes the admin consoles need. Kept out of affiliate.php
 * so the affiliate-facing core does not carry the operator's read patterns, and kept
 * out of the console pages so a second console can reuse them without copying SQL.
 */
require_once __DIR__ . '/affiliate.php';

/**
 * Legal affiliate lifecycle transitions.
 *
 * Declared in one place for the same reason the commission machine is: the console
 * button, an importer and a future API must not disagree about what `closed` can
 * become. `closed` and `rejected` are terminal — reopening is an explicit
 * administrative decision made by creating a new record, not a status flip that
 * silently resurrects an old payout history.
 */
function affiliate_status_transitions(): array {
    return [
        'pending' => ['under_review', 'approved', 'rejected', 'closed'],
        'under_review' => ['approved', 'rejected', 'closed'],
        'approved' => ['active', 'suspended', 'rejected', 'closed'],
        'active' => ['suspended', 'closed'],
        'suspended' => ['active', 'closed'],
        'rejected' => [],
        'closed' => [],
    ];
}

/** May the affiliate lifecycle move from $from to $to? */
function affiliate_status_can_transition(string $from, string $to): bool {
    return in_array($to, affiliate_status_transitions()[$from] ?? [], true);
}

/**
 * Move an affiliate to a new lifecycle state.
 *
 * A suspension or a rejection must carry a reason. Without one the affiliate is
 * told they were stopped but not why, which is both unfair and operationally
 * useless when they ask.
 */
function affiliate_set_status(PDO $pdo, int $affiliateId, string $to, array $options = []): array {
    $affiliate = affiliate_find($pdo, $affiliateId);
    if (!$affiliate) return ['ok' => false, 'error' => 'not_found'];
    if (!in_array($to, affiliate_statuses(), true)) return ['ok' => false, 'error' => 'invalid_status'];

    $from = (string)$affiliate['status'];
    if ($from === $to) return ['ok' => true, 'from' => $from, 'to' => $to, 'noop' => true];
    if (!affiliate_status_can_transition($from, $to)) {
        return ['ok' => false, 'error' => 'illegal_transition', 'from' => $from, 'to' => $to];
    }

    $note = trim((string)($options['note'] ?? ''));
    if (in_array($to, ['suspended', 'rejected'], true) && $note === '') {
        return ['ok' => false, 'error' => 'reason_required', 'from' => $from, 'to' => $to];
    }

    $sets = ['status = ?'];
    $params = [$to];
    $actorId = isset($options['actor_id']) ? (int)$options['actor_id'] : (int)($_SESSION['admin_id'] ?? 0);

    if ($to === 'active' || $to === 'approved') {
        $sets[] = 'approved_at = COALESCE(approved_at, NOW())';
        $sets[] = 'approved_by = COALESCE(approved_by, ?)';
        $params[] = $actorId ?: null;
    }
    if ($to === 'suspended') {
        $sets[] = 'suspended_at = NOW()';
        $sets[] = 'suspension_reason = ?';
        $params[] = mb_substr($note, 0, 500, 'UTF-8');
    }
    if ($to === 'rejected') {
        $sets[] = 'rejection_reason = ?';
        $params[] = mb_substr($note, 0, 500, 'UTF-8');
    }
    $params[] = $affiliateId;

    try {
        $pdo->prepare('UPDATE affiliates SET ' . implode(', ', $sets) . ' WHERE id=?')->execute($params);
    } catch (Throwable $e) {
        error_log('affiliate_set_status: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'update_failed'];
    }

    affiliate_audit($pdo, 'affiliate_status_changed', [
        'affiliate_id' => $affiliateId, 'actor_type' => 'admin', 'actor_id' => $actorId ?: null,
        'subject_type' => 'affiliate', 'subject_id' => $affiliateId,
        'from_state' => $from, 'to_state' => $to, 'detail' => $note,
    ]);

    // A suspension withdraws the ability to earn, so say so. The event key includes
    // the state, so a later reactivation is a separate notice rather than a duplicate.
    $titles = [
        'active' => is_rtl() ? 'تم تفعيل حسابك' : 'Your account is active',
        'suspended' => is_rtl() ? 'تم إيقاف حسابك' : 'Your account was suspended',
        'rejected' => is_rtl() ? 'تم رفض طلبك' : 'Your application was rejected',
        'closed' => is_rtl() ? 'تم إغلاق حسابك' : 'Your account was closed',
        'approved' => is_rtl() ? 'تم اعتماد حسابك' : 'Your account was approved',
    ];
    if (isset($titles[$to])) {
        affiliate_notify($pdo, $affiliateId, 'affiliate_status:' . $affiliateId . ':' . $to, 'account_' . $to,
            $titles[$to], $note !== '' ? $note : null, '/affiliate/dashboard.php');
    }

    return ['ok' => true, 'from' => $from, 'to' => $to];
}

/** Set the compliance review outcome for an affiliate. */
function affiliate_set_compliance(PDO $pdo, int $affiliateId, string $to, array $options = []): array {
    $allowed = ['not_reviewed', 'in_review', 'approved', 'rejected', 'needs_action'];
    if (!in_array($to, $allowed, true)) return ['ok' => false, 'error' => 'invalid_status'];
    $affiliate = affiliate_find($pdo, $affiliateId);
    if (!$affiliate) return ['ok' => false, 'error' => 'not_found'];

    $from = (string)$affiliate['compliance_status'];
    try {
        $pdo->prepare('UPDATE affiliates SET compliance_status=? WHERE id=?')->execute([$to, $affiliateId]);
    } catch (Throwable $e) {
        error_log('affiliate_set_compliance: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'update_failed'];
    }

    affiliate_audit($pdo, 'affiliate_compliance_changed', [
        'affiliate_id' => $affiliateId, 'actor_type' => 'admin',
        'actor_id' => isset($options['actor_id']) ? (int)$options['actor_id'] : (int)($_SESSION['admin_id'] ?? 0),
        'subject_type' => 'affiliate', 'subject_id' => $affiliateId,
        'from_state' => $from, 'to_state' => $to, 'detail' => (string)($options['note'] ?? ''),
    ]);

    return ['ok' => true, 'from' => $from, 'to' => $to];
}

/**
 * Affiliates for the console list.
 *
 * The conversion count and the open-flag count are correlated subqueries rather than
 * joins: a join against conversions would multiply the affiliate row once per
 * conversion and make every other column need a GROUP BY, which is exactly the kind
 * of query that quietly returns the wrong payout total.
 */
function affiliate_admin_list(PDO $pdo, array $filters = []): array {
    $where = [];
    $params = [];
    if (!empty($filters['status'])) { $where[] = 'a.status = ?'; $params[] = $filters['status']; }
    if (!empty($filters['compliance_status'])) { $where[] = 'a.compliance_status = ?'; $params[] = $filters['compliance_status']; }
    if (!empty($filters['q'])) {
        $where[] = '(a.full_name LIKE ? OR a.email LIKE ? OR a.code LIKE ?)';
        $like = '%' . $filters['q'] . '%';
        array_push($params, $like, $like, $like);
    }
    $limit = max(1, min(500, (int)($filters['limit'] ?? 100)));

    $sql = "SELECT a.*,
            (SELECT COUNT(*) FROM affiliate_conversions c WHERE c.affiliate_id = a.id) AS conversion_count,
            (SELECT COUNT(*) FROM affiliate_fraud_flags f WHERE f.affiliate_id = a.id AND f.status = 'open') AS open_flags,
            (SELECT MAX(g.accepted_at) FROM affiliate_agreements g WHERE g.affiliate_id = a.id AND g.agreement_type = 'terms') AS terms_accepted_at
        FROM affiliates a"
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY a.id DESC LIMIT ' . $limit;

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('affiliate_admin_list: ' . $e->getMessage());
        return [];
    }
}

/** Headline counts for the console header. */
function affiliate_admin_counts(PDO $pdo): array {
    $out = ['total' => 0, 'active' => 0, 'pending' => 0, 'suspended' => 0, 'awaiting_compliance' => 0];
    try {
        $out['total'] = (int)$pdo->query('SELECT COUNT(*) FROM affiliates')->fetchColumn();
        $out['active'] = (int)$pdo->query("SELECT COUNT(*) FROM affiliates WHERE status='active'")->fetchColumn();
        $out['pending'] = (int)$pdo->query("SELECT COUNT(*) FROM affiliates WHERE status IN ('pending','under_review')")->fetchColumn();
        $out['suspended'] = (int)$pdo->query("SELECT COUNT(*) FROM affiliates WHERE status='suspended'")->fetchColumn();
        $out['awaiting_compliance'] = (int)$pdo->query("SELECT COUNT(*) FROM affiliates WHERE compliance_status IN ('not_reviewed','in_review')")->fetchColumn();
    } catch (Throwable $e) { /* pre-v57 database */ }
    return $out;
}

/** Applications awaiting a decision, newest first. */
function affiliate_admin_applications(PDO $pdo, array $filters = []): array {
    $status = (string)($filters['status'] ?? 'submitted');
    try {
        if ($status === 'all') {
            $stmt = $pdo->query('SELECT * FROM affiliate_applications ORDER BY id DESC LIMIT 200');
        } else {
            $stmt = $pdo->prepare('SELECT * FROM affiliate_applications WHERE status=? ORDER BY id DESC LIMIT 200');
            $stmt->execute([$status]);
        }
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * The full picture for one affiliate: agreements, commissions, payouts, flags and
 * the ledger. Read in one call so the detail page does not run a dozen queries.
 */
function affiliate_admin_detail(PDO $pdo, int $affiliateId): array {
    $out = ['affiliate' => affiliate_find($pdo, $affiliateId), 'agreements' => [], 'commissions' => [],
            'payouts' => [], 'flags' => [], 'attributions' => [], 'links' => []];

    try {
        $stmt = $pdo->prepare('SELECT * FROM affiliate_agreements WHERE affiliate_id=? ORDER BY id DESC');
        $stmt->execute([$affiliateId]);
        $out['agreements'] = $stmt->fetchAll();

        $stmt = $pdo->prepare('SELECT * FROM affiliate_commissions WHERE affiliate_id=? ORDER BY id DESC LIMIT 100');
        $stmt->execute([$affiliateId]);
        $out['commissions'] = $stmt->fetchAll();

        $stmt = $pdo->prepare('SELECT * FROM affiliate_payout_requests WHERE affiliate_id=? ORDER BY id DESC LIMIT 50');
        $stmt->execute([$affiliateId]);
        $out['payouts'] = $stmt->fetchAll();

        $stmt = $pdo->prepare('SELECT * FROM affiliate_fraud_flags WHERE affiliate_id=? ORDER BY id DESC LIMIT 50');
        $stmt->execute([$affiliateId]);
        $out['flags'] = $stmt->fetchAll();

        $stmt = $pdo->prepare('SELECT * FROM affiliate_links WHERE affiliate_id=? ORDER BY id DESC LIMIT 50');
        $stmt->execute([$affiliateId]);
        $out['links'] = $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('affiliate_admin_detail: ' . $e->getMessage());
    }

    return $out;
}
