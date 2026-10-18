<?php
/**
 * Fraud and abuse signals (Execution 31).
 *
 * POLICY — a flag is a question, not a verdict.
 *   Nothing in this module suspends an affiliate, rejects a conversion, or withholds
 *   a payout on the strength of a flag. Every function here records an observation
 *   and, at most, marks a click or conversion `is_suspect`. The decisions belong to a
 *   human in the admin console, and the interface says so.
 *
 *   The reason is not squeamishness. A false positive that automatically suspends a
 *   partner costs a relationship and a support escalation; a false negative that a
 *   reviewer clears in ten seconds costs ten seconds. Automating the punitive
 *   direction trades a cheap error for an expensive one, so the code refuses to.
 *
 * DETECTION IS CONSERVATIVE BY DESIGN
 *   The heuristics below look for things that are genuinely hard to explain
 *   innocently: an affiliate whose own email or payout account matches the customer's,
 *   the same visitor token producing a burst of clicks, a payment pattern that looks
 *   like the same person paying themselves. Each produces a `signal_data` string a
 *   reviewer can verify, rather than a score they have to trust.
 */
require_once __DIR__ . '/affiliate.php';

/**
 * Record a flag.
 *
 * Deduplicated on (affiliate, type, subject) within a day so a re-run of a scan does
 * not bury the queue in duplicates of the same observation.
 */
function affiliate_flag(PDO $pdo, array $data): ?int {
    $affiliateId = isset($data['affiliate_id']) ? (int)$data['affiliate_id'] : null;
    $type = (string)($data['flag_type'] ?? 'other');
    $allowedTypes = ['self_referral', 'duplicate_account', 'repeated_payment', 'coupon_abuse', 'fake_conversion', 'param_manipulation', 'cookie_stuffing', 'automated_traffic', 'unauthorized_change', 'payout_account_change', 'conversion_spike', 'other'];
    if (!in_array($type, $allowedTypes, true)) $type = 'other';
    $severity = in_array($data['severity'] ?? 'medium', ['low', 'medium', 'high', 'critical'], true) ? $data['severity'] : 'medium';

    try {
        // Suppress an identical open flag raised in the last 24 hours.
        $dup = $pdo->prepare('SELECT id FROM affiliate_fraud_flags
            WHERE flag_type=? AND COALESCE(affiliate_id,0)=? AND COALESCE(conversion_id,0)=? AND COALESCE(click_id,0)=?
              AND status IN (\'open\',\'reviewing\') AND created_at > (NOW() - INTERVAL 1 DAY) LIMIT 1');
        $dup->execute([$type, (int)$affiliateId, (int)($data['conversion_id'] ?? 0), (int)($data['click_id'] ?? 0)]);
        $existing = $dup->fetchColumn();
        if ($existing) return (int)$existing;

        $pdo->prepare('INSERT INTO affiliate_fraud_flags (affiliate_id, conversion_id, click_id, payout_request_id, flag_type, severity, status, signal_data, description)
            VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([
                $affiliateId,
                isset($data['conversion_id']) ? (int)$data['conversion_id'] : null,
                isset($data['click_id']) ? (int)$data['click_id'] : null,
                isset($data['payout_request_id']) ? (int)$data['payout_request_id'] : null,
                $type, $severity, 'open',
                isset($data['signal_data']) ? mb_substr((string)$data['signal_data'], 0, 1000, 'UTF-8') : null,
                isset($data['description']) ? mb_substr((string)$data['description'], 0, 500, 'UTF-8') : null,
            ]);
        $id = (int)$pdo->lastInsertId();

        affiliate_audit($pdo, 'fraud_flag_raised', [
            'affiliate_id' => $affiliateId, 'subject_type' => 'fraud_flag', 'subject_id' => $id,
            'detail' => $type . '/' . $severity,
        ]);
        return $id;
    } catch (Throwable $e) {
        error_log('affiliate_flag failed: ' . $e->getMessage());
        return null;
    }
}

/** Flags matching a filter. */
function affiliate_flags(PDO $pdo, array $filters = []): array {
    $where = ['1=1'];
    $params = [];
    if (!empty($filters['status'])) { $where[] = 'f.flag_type IS NOT NULL AND f.status = ?'; $params[] = $filters['status']; }
    if (!empty($filters['affiliate_id'])) { $where[] = 'f.affiliate_id = ?'; $params[] = (int)$filters['affiliate_id']; }
    if (!empty($filters['severity'])) { $where[] = 'f.severity = ?'; $params[] = $filters['severity']; }
    if (!empty($filters['flag_type'])) { $where[] = 'f.flag_type = ?'; $params[] = $filters['flag_type']; }
    $limit = max(1, min(500, (int)($filters['limit'] ?? 200)));
    try {
        $stmt = $pdo->prepare('SELECT f.*, a.code, a.full_name FROM affiliate_fraud_flags f
            LEFT JOIN affiliates a ON a.id = f.affiliate_id
            WHERE ' . implode(' AND ', $where) . ' ORDER BY FIELD(f.severity,\'critical\',\'high\',\'medium\',\'low\'), f.id DESC LIMIT ' . $limit);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** Open flag counts by severity, for the console badge. */
function affiliate_flag_counts(PDO $pdo): array {
    $out = ['open' => 0, 'reviewing' => 0, 'critical' => 0, 'high' => 0];
    try {
        foreach ($pdo->query("SELECT status, severity, COUNT(*) n FROM affiliate_fraud_flags GROUP BY status, severity")->fetchAll() as $row) {
            if (isset($out[$row['status']])) $out[$row['status']] += (int)$row['n'];
            if ($row['status'] === 'open' && isset($out[$row['severity']])) $out[$row['severity']] += (int)$row['n'];
        }
    } catch (Throwable $e) { /* pre-v57 database */ }
    return $out;
}

/**
 * Close out a flag with a human decision.
 *
 * `confirmed` is the only outcome that permits punitive follow-up, and even then the
 * caller has to take that step deliberately — this function changes the flag and
 * nothing else.
 */
function affiliate_flag_review(PDO $pdo, int $flagId, string $status, ?string $note = null): bool {
    if (!in_array($status, ['reviewing', 'cleared', 'confirmed', 'dismissed'], true)) return false;
    try {
        $pdo->prepare('UPDATE affiliate_fraud_flags SET status=?, reviewed_by=?, reviewed_at=NOW(), resolution_note=? WHERE id=?')
            ->execute([$status, isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null, $note !== null ? mb_substr($note, 0, 500, 'UTF-8') : null, $flagId]);
        affiliate_audit($pdo, 'fraud_flag_reviewed', [
            'subject_type' => 'fraud_flag', 'subject_id' => $flagId,
            'to_state' => $status, 'detail' => (string)$note,
        ]);
        return true;
    } catch (Throwable $e) {
        error_log('affiliate_flag_review: ' . $e->getMessage());
        return false;
    }
}

/**
 * Is this affiliate referring themselves?
 *
 * Three independent signals, any one of which is enough to flag:
 *   1. the identity was recorded on the affiliate row (the reliable case)
 *   2. the customer's email matches the affiliate's email
 *   3. the customer's phone matches the affiliate's phone
 *
 * Email and phone are normalised before comparison so `+973 3333 4444` and
 * `+97333334444` are recognised as the same number — the naive comparison is the one
 * a fraudster relies on.
 */
function affiliate_check_self_referral(PDO $pdo, array $affiliate, string $subjectType, int $subjectId): ?array {
    if ($subjectId <= 0) return null;

    if (affiliate_is_self_referral($affiliate, $subjectType, $subjectId)) {
        return ['signal' => 'identity_match', 'detail' => 'الحساب مرتبط بالمسوّق نفسه'];
    }

    $table = $subjectType === 'lawyer' ? 'lawyers' : 'users';
    try {
        $stmt = $pdo->prepare("SELECT email, phone FROM {$table} WHERE id=?");
        $stmt->execute([$subjectId]);
        $subject = $stmt->fetch();
    } catch (Throwable $e) {
        return null;
    }
    if (!$subject) return null;

    $affiliateEmail = strtolower(trim((string)$affiliate['email']));
    $subjectEmail = strtolower(trim((string)($subject['email'] ?? '')));
    if ($affiliateEmail !== '' && $subjectEmail !== '' && $affiliateEmail === $subjectEmail) {
        return ['signal' => 'email_match', 'detail' => 'البريد الإلكتروني مطابق لبريد المسوّق'];
    }

    $affiliatePhone = affiliate_normalize_phone($affiliate['phone'] ?? '');
    $subjectPhone = affiliate_normalize_phone($subject['phone'] ?? '');
    if ($affiliatePhone !== null && $subjectPhone !== null && $affiliatePhone === $subjectPhone) {
        return ['signal' => 'phone_match', 'detail' => 'رقم الجوال مطابق لرقم المسوّق'];
    }
    return null;
}

/**
 * Run the abuse heuristics for one affiliate.
 *
 * Called from the admin console on demand and from the payout request path, so a
 * reviewer sees the signals before approving money rather than after.
 *
 * @return array{flags:int,signals:array<int,array{type:string,severity:string,detail:string}>}
 */
function affiliate_scan_signals(PDO $pdo, int $affiliateId): array {
    $affiliate = affiliate_find($pdo, $affiliateId);
    if (!$affiliate) return ['flags' => 0, 'signals' => []];
    $signals = [];

    // 1. Duplicate accounts sharing a payout account or email pattern.
    try {
        $stmt = $pdo->prepare("SELECT id, code, email FROM affiliates WHERE id <> ? AND (email = ? OR (phone IS NOT NULL AND phone <> '' AND phone = ?))");
        $stmt->execute([$affiliateId, (string)$affiliate['email'], (string)($affiliate['phone'] ?? '')]);
        foreach ($stmt->fetchAll() as $dup) {
            $signals[] = ['type' => 'duplicate_account', 'severity' => 'high',
                'detail' => 'حساب مطابق: ' . (string)$dup['code'] . ' (' . (string)$dup['email'] . ')'];
        }
    } catch (Throwable $e) {}

    // 2. Self-referral across every bound conversion.
    try {
        $stmt = $pdo->prepare('SELECT id, subject_ref, subject_type, source_id, event_type FROM affiliate_conversions WHERE affiliate_id=? AND event_type <> \'registration\' LIMIT 200');
        $stmt->execute([$affiliateId]);
        $selfHits = 0;
        foreach ($stmt->fetchAll() as $conv) {
            $subjectType = (string)$conv['subject_type'];
            $subjectId = (int)($conv['source_id'] ?? 0);
            if ($subjectId <= 0) continue;
            $match = affiliate_check_self_referral($pdo, $affiliate, $subjectType, $subjectId);
            if ($match) {
                $selfHits++;
                $signals[] = ['type' => 'self_referral', 'severity' => 'high',
                    'detail' => 'تحويل #' . (int)$conv['id'] . ': ' . $match['detail']];
            }
        }
    } catch (Throwable $e) {}

    // 3. Conversion spike: an implausible number of eligible conversions in 24 hours.
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM affiliate_conversions WHERE affiliate_id=? AND is_eligible=1 AND converted_at > (NOW() - INTERVAL 1 DAY)");
        $stmt->execute([$affiliateId]);
        $last24 = (int)$stmt->fetchColumn();
        if ($last24 >= 25) {
            $signals[] = ['type' => 'conversion_spike', 'severity' => 'medium',
                'detail' => $last24 . ' تحويلاً مؤهلاً في 24 ساعة'];
        }
    } catch (Throwable $e) {}

    // 4. Repeated payments from one pseudonymous customer.
    try {
        $stmt = $pdo->prepare("SELECT subject_ref, COUNT(*) n FROM affiliate_conversions WHERE affiliate_id=? AND is_eligible=1 GROUP BY subject_ref HAVING n >= 5 LIMIT 20");
        $stmt->execute([$affiliateId]);
        foreach ($stmt->fetchAll() as $row) {
            $signals[] = ['type' => 'repeated_payment', 'severity' => 'medium',
                'detail' => (int)$row['n'] . ' مدفوعات من نفس العميل'];
        }
    } catch (Throwable $e) {}

    // 5. Automated traffic share.
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) total, COALESCE(SUM(is_bot),0) bots, COALESCE(SUM(is_suspect),0) suspects FROM affiliate_clicks WHERE affiliate_id=? AND clicked_at > (NOW() - INTERVAL 7 DAY)");
        $stmt->execute([$affiliateId]);
        $row = $stmt->fetch() ?: [];
        $total = (int)($row['total'] ?? 0);
        if ($total >= 20 && ((int)($row['bots'] ?? 0)) / $total > 0.5) {
            $signals[] = ['type' => 'automated_traffic', 'severity' => 'high',
                'detail' => 'أكثر من نصف الزيارات من برامج آلية (' . (int)$row['bots'] . '/' . $total . ')'];
        }
        if ((int)($row['suspects'] ?? 0) > 0) {
            $signals[] = ['type' => 'cookie_stuffing', 'severity' => 'medium',
                'detail' => (int)$row['suspects'] . ' زيارة مشبوهة (نقرات متسارعة)'];
        }
    } catch (Throwable $e) {}

    // Persist each signal. Flags are deduplicated by affiliate_flag(), so running the
    // scan twice in a day does not double the queue.
    $raised = 0;
    foreach ($signals as $signal) {
        if (affiliate_flag($pdo, [
            'affiliate_id' => $affiliateId,
            'flag_type' => $signal['type'],
            'severity' => $signal['severity'],
            'description' => $signal['detail'],
            'signal_data' => $signal['detail'],
        ]) !== null) $raised++;
    }
    return ['flags' => $raised, 'signals' => $signals];
}

/**
 * Signal that payout details changed.
 *
 * Changing a bank account and immediately requesting a payout is the classic
 * account-takeover pattern, so the change itself is recorded and the payout path
 * surfaces it. The change is still permitted — an affiliate who legitimately switches
 * banks must not be blocked — but a reviewer sees it.
 */
function affiliate_flag_payout_change(PDO $pdo, int $affiliateId, int $payoutRequestId, array $details): void {
    affiliate_flag($pdo, [
        'affiliate_id' => $affiliateId,
        'payout_request_id' => $payoutRequestId,
        'flag_type' => 'payout_account_change',
        'severity' => 'medium',
        'description' => 'تم تحديث بيانات الصرف عند طلب دفعة',
        'signal_data' => affiliate_mask_payout_details($details),
    ]);
}

/**
 * Fraud statistics for the console.
 *
 * Deliberately counts *signals raised* rather than claiming a fraud rate: the
 * programme has no ground truth about which flags were genuine until a reviewer
 * marks them, so reporting anything else would be inventing a number.
 */
function affiliate_fraud_summary(PDO $pdo): array {
    $out = ['by_type' => [], 'open' => 0, 'confirmed' => 0, 'cleared' => 0, 'affiliates_flagged' => 0];
    try {
        foreach ($pdo->query('SELECT flag_type, status, COUNT(*) n FROM affiliate_fraud_flags GROUP BY flag_type, status')->fetchAll() as $row) {
            $out['by_type'][$row['flag_type']] = ($out['by_type'][$row['flag_type']] ?? 0) + (int)$row['n'];
            if ($row['status'] === 'open') $out['open'] += (int)$row['n'];
            if ($row['status'] === 'confirmed') $out['confirmed'] += (int)$row['n'];
            if ($row['status'] === 'cleared' || $row['status'] === 'dismissed') $out['cleared'] += (int)$row['n'];
        }
        $out['affiliates_flagged'] = (int)$pdo->query('SELECT COUNT(DISTINCT affiliate_id) FROM affiliate_fraud_flags WHERE affiliate_id IS NOT NULL')->fetchColumn();
    } catch (Throwable $e) { /* pre-v57 database */ }
    return $out;
}
