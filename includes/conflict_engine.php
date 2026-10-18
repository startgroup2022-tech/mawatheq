<?php
/**
 * Conflict-of-Interest engine (Executive 08).
 *
 * A law firm must screen new matters against the parties it already knows about, and an
 * unresolved conflict must be visible and traceable before work proceeds. This module
 * does four things and nothing else:
 *
 *   1. Normalises a party name so Arabic and English spellings collide correctly
 *      (`conflict_normalize_name`).
 *   2. Registers the parties a firm knows about (`conflict_party_*`).
 *   3. Screens a matter and produces candidate matches with a severity
 *      (`conflict_screen_matter`).
 *   4. Runs the human decision workflow, including a justified override
 *      (`conflict_review_*`).
 *
 * Hard rules enforced here, not just in the UI:
 *   * AI may only fill the `ai_*` columns of a review. It can never set a status, a
 *     decision or an override — `conflict_review_decide()` is the only writer of those
 *     columns and it records who decided.
 *   * Clearing a conflict despite a match requires an explicit override: a reason and
 *     an approving lawyer with the authority to give it.
 *   * Every state transition appends to `conflict_audit_trail`, which is never updated
 *     or deleted.
 *   * Every query is scoped by the session firm. A review can never be read or written
 *     across firms.
 */

require_once __DIR__ . '/tenant.php';
require_once __DIR__ . '/branch_access.php';
require_once __DIR__ . '/audit_log.php';
require_once __DIR__ . '/notifications.php';

if (!function_exists('conflict_severities')) {
    function conflict_severities(): array { return ['low', 'medium', 'high', 'critical']; }
}

if (!function_exists('conflict_statuses')) {
    function conflict_statuses(): array { return ['open', 'under_review', 'approved', 'rejected', 'cleared_with_override', 'closed']; }
}

if (!function_exists('conflict_status_label')) {
    function conflict_status_label(string $status, bool $rtl = true): string {
        $ar = ['open' => 'مفتوح', 'under_review' => 'قيد المراجعة', 'approved' => 'معتمد (لا تعارض)',
               'rejected' => 'مرفوض', 'cleared_with_override' => 'تم التجاوز بموافقة', 'closed' => 'مغلق'];
        $en = ['open' => 'Open', 'under_review' => 'Under review', 'approved' => 'Approved (no conflict)',
               'rejected' => 'Rejected', 'cleared_with_override' => 'Cleared with override', 'closed' => 'Closed'];
        return ($rtl ? $ar : $en)[$status] ?? $status;
    }
}

if (!function_exists('conflict_severity_label')) {
    function conflict_severity_label(string $severity, bool $rtl = true): string {
        $ar = ['low' => 'منخفض', 'medium' => 'متوسط', 'high' => 'مرتفع', 'critical' => 'حرج'];
        $en = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'];
        return ($rtl ? $ar : $en)[$severity] ?? $severity;
    }
}

if (!function_exists('conflict_normalize_name')) {
    /**
     * Normalisation key for party matching.
     *
     * Arabic: strips diacritics (tashkeel), unifies alef/hamza forms (أإآٱ→ا), alef maqsura
     * (ى→ي) and ta marbuta (ة→ه), removes tatweel, and folds the definite article so
     * "الشركة" and "شركة" collide. English: lowercases, strips punctuation and collapses
     * whitespace. Digits are preserved (CR numbers matter).
     *
     * Deliberately order-sensitive: tashkeel is removed before letter folding, otherwise a
     * fatha between two alefs would survive as a stray character.
     */
    function conflict_normalize_name(?string $name): string {
        $s = (string)$name;
        if ($s === '') return '';
        $s = preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]/u', '', $s) ?? $s; // tashkeel
        $s = str_replace("\u{0640}", '', $s);                    // tatweel
        $s = preg_replace('/[\x{0622}\x{0623}\x{0625}\x{0671}]/u', "\u{0627}", $s) ?? $s; // أإآٱ → ا
        $s = str_replace(['ى', 'ئ', 'ؤ'], ['ي', 'ي', 'و'], $s);   // alef maqsura / hamza forms
        $s = str_replace('ة', 'ه', $s);                          // ta marbuta
        $s = mb_strtolower($s, 'UTF-8');
        $s = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s) ?? $s;  // punctuation → space
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        $s = trim($s);
        // Fold leading definite article on each word: "الشركة الوطنية" == "شركه وطنيه".
        $words = $s === '' ? [] : explode(' ', $s);
        foreach ($words as $i => $w) {
            if (mb_strlen($w, 'UTF-8') > 3 && mb_substr($w, 0, 2, 'UTF-8') === 'ال') {
                $words[$i] = mb_substr($w, 2, null, 'UTF-8');
            }
        }
        return implode(' ', $words);
    }
}

if (!function_exists('conflict_name_block')) {
    /** Short bucket key so fuzzy matching compares only plausible candidates. */
    function conflict_name_block(string $normalized): string {
        $n = preg_replace('/\s+/u', '', $normalized) ?? $normalized;
        return mb_substr($n, 0, 3, 'UTF-8');
    }
}

if (!function_exists('conflict_levenshtein_ratio')) {
    /** Similarity 0..1 between two byte strings via Levenshtein distance. */
    function conflict_levenshtein_ratio(string $a, string $b): float {
        if ($a === '' && $b === '') return 1.0;
        $max = max(strlen($a), strlen($b));
        if ($max === 0) return 1.0;
        return max(0.0, 1.0 - (levenshtein($a, $b) / $max));
    }
}

if (!function_exists('conflict_match_score')) {
    /**
     * Score two normalised names 0..100. Token-aware: an exact normalized match scores 100;
     * an exact token subset scores high; otherwise a fuzzy ratio on the whole string and on
     * the sorted token strings, whichever is better. Arabic and English both flow through
     * the same normalizer so no language-specific branch is needed here.
     */
    function conflict_match_score(string $normA, string $normB): float {
        if ($normA === '' || $normB === '') return 0.0;
        if ($normA === $normB) return 100.0;

        $ta = array_values(array_filter(explode(' ', $normA)));
        $tb = array_values(array_filter(explode(' ', $normB)));
        if ($ta && $tb) {
            $inter = count(array_intersect($ta, $tb));
            $union = count(array_unique(array_merge($ta, $tb)));
            if ($union > 0) {
                $jaccard = $inter / $union;                       // token overlap
                $best = $jaccard * 100.0;
                $frac = 100.0 * ($inter / min(count($ta), count($tb))); // containment
                if ($frac <= 100.0) $best = max($best, $frac * 0.9);
                $sortedA = $ta; sort($sortedA); $sortedB = $tb; sort($sortedB);
                $best = max($best, conflict_levenshtein_ratio(implode(' ', $sortedA), implode(' ', $sortedB)) * 100.0);
                return round(min(100.0, $best), 2);
            }
        }
        return round(conflict_levenshtein_ratio($normA, $normB) * 100.0, 2);
    }
}

if (!function_exists('conflict_severity_for_score')) {
    /**
     * Severity from a match score and whether the counterparty is on the other side of a
     * matter (an opposing party that matches our own client is the worst case).
     */
    function conflict_severity_for_score(float $score, bool $againstClient = false): string {
        if ($score >= 95.0) return 'critical';
        if ($score >= 85.0) return $againstClient ? 'critical' : 'high';
        if ($score >= 70.0) return 'high';
        if ($score >= 55.0) return 'medium';
        return 'low';
    }
}

if (!function_exists('conflict_threshold')) {
    /** Minimum score at which a candidate is worth a human look. */
    function conflict_threshold(): float { return 55.0; }
}

if (!function_exists('conflict_party_create')) {
    /**
     * Register a party. Requires the firm from the session; returns the new id.
     * @param array $data party_type, name_ar, name_en, id_number, commercial_register, country, notes, linked_case_id, branch_id
     */
    function conflict_party_create(PDO $pdo, array $data): int {
        $ctx = tenant_current($pdo);
        if (empty($ctx['firm_id'])) {
            throw new RuntimeException('conflict_party_create requires an active firm tenant');
        }
        $firmId = (int)$ctx['firm_id'];
        $nameAr = trim((string)($data['name_ar'] ?? ''));
        $nameEn = trim((string)($data['name_en'] ?? ''));
        $normSource = $nameAr !== '' ? $nameAr : $nameEn;
        $norm = conflict_normalize_name($normSource);
        if ($norm === '') {
            throw new InvalidArgumentException('A party needs a name (Arabic or English)');
        }
        $branchId = isset($data['branch_id']) && $data['branch_id'] !== '' ? (int)$data['branch_id'] : null;
        // A caller may only file a party at a branch it belongs to.
        branch_access_require_branch($pdo, $branchId);

        $stmt = $pdo->prepare('INSERT INTO conflict_parties
            (firm_id, party_type, name_ar, name_en, name_norm, name_block, id_number, commercial_register,
             country, notes, linked_client_user_id, linked_case_id, branch_id, created_by_lawyer_id)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([
            $firmId,
            in_array($data['party_type'] ?? '', ['client','opposing_party','related_person','related_company','witness','other'], true) ? $data['party_type'] : 'other',
            $nameAr !== '' ? $nameAr : null,
            $nameEn !== '' ? $nameEn : null,
            $norm,
            conflict_name_block($norm),
            ($data['id_number'] ?? '') !== '' ? (string)$data['id_number'] : null,
            ($data['commercial_register'] ?? '') !== '' ? (string)$data['commercial_register'] : null,
            ($data['country'] ?? '') !== '' ? (string)$data['country'] : null,
            ($data['notes'] ?? '') !== '' ? (string)$data['notes'] : null,
            !empty($data['linked_client_user_id']) ? (int)$data['linked_client_user_id'] : null,
            !empty($data['linked_case_id']) ? (int)$data['linked_case_id'] : null,
            $branchId,
            (int)$ctx['lawyer_id'] ?: null,
        ]);
        $id = (int)$pdo->lastInsertId();
        log_action($pdo, 'تسجيل طرف لفحص التعارض: ' . ($nameAr ?: $nameEn), 'conflict_party', $id);
        return $id;
    }
}

if (!function_exists('conflict_party_update')) {
    function conflict_party_update(PDO $pdo, int $partyId, array $data): bool {
        $firmId = (int)(tenant_current($pdo)['firm_id'] ?? 0);
        if (!$firmId) return false;
        $nameAr = trim((string)($data['name_ar'] ?? ''));
        $nameEn = trim((string)($data['name_en'] ?? ''));
        $norm = conflict_normalize_name($nameAr !== '' ? $nameAr : $nameEn);
        if ($norm === '') return false;
        $branchId = isset($data['branch_id']) && $data['branch_id'] !== '' ? (int)$data['branch_id'] : null;
        branch_access_require_branch($pdo, $branchId);
        $stmt = $pdo->prepare('UPDATE conflict_parties SET party_type=?, name_ar=?, name_en=?, name_norm=?, name_block=?,
                               id_number=?, commercial_register=?, country=?, notes=?, branch_id=?
                               WHERE id=? AND firm_id=?');
        $ok = $stmt->execute([
            in_array($data['party_type'] ?? '', ['client','opposing_party','related_person','related_company','witness','other'], true) ? $data['party_type'] : 'other',
            $nameAr !== '' ? $nameAr : null, $nameEn !== '' ? $nameEn : null, $norm, conflict_name_block($norm),
            ($data['id_number'] ?? '') !== '' ? (string)$data['id_number'] : null,
            ($data['commercial_register'] ?? '') !== '' ? (string)$data['commercial_register'] : null,
            ($data['country'] ?? '') !== '' ? (string)$data['country'] : null,
            ($data['notes'] ?? '') !== '' ? (string)$data['notes'] : null,
            $branchId, $partyId, $firmId,
        ]);
        // execute() returns true for "no error"; confirm a row actually matched.
        return $ok && $stmt->rowCount() >= 0 && conflict_party_get($pdo, $partyId) !== null;
    }
}

if (!function_exists('conflict_party_get')) {
    function conflict_party_get(PDO $pdo, int $partyId): ?array {
        $firmId = (int)(tenant_current($pdo)['firm_id'] ?? 0);
        if (!$firmId) return null;
        $stmt = $pdo->prepare('SELECT * FROM conflict_parties WHERE id=? AND firm_id=? LIMIT 1');
        $stmt->execute([$partyId, $firmId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}

if (!function_exists('conflict_party_archive')) {
    function conflict_party_archive(PDO $pdo, int $partyId): bool {
        $firmId = (int)(tenant_current($pdo)['firm_id'] ?? 0);
        if (!$firmId) return false;
        $stmt = $pdo->prepare("UPDATE conflict_parties SET status='archived' WHERE id=? AND firm_id=?");
        $stmt->execute([$partyId, $firmId]);
        if ($stmt->rowCount() > 0) { log_action($pdo, 'أرشفة طرف تعارض', 'conflict_party', $partyId); return true; }
        return false;
    }
}

if (!function_exists('conflict_party_list')) {
    /** @return array rows, branch-filtered. */
    function conflict_party_list(PDO $pdo, array $opts = []): array {
        $firmId = (int)(tenant_current($pdo)['firm_id'] ?? 0);
        if (!$firmId) return [];
        $where = ['firm_id=?'];
        $params = [$firmId];
        if (!empty($opts['party_type'])) { $where[] = 'party_type=?'; $params[] = $opts['party_type']; }
        if (empty($opts['include_archived'])) { $where[] = "status='active'"; }
        if (!empty($opts['q'])) {
            $where[] = 'name_norm LIKE ?';
            $params[] = '%' . conflict_normalize_name((string)$opts['q']) . '%';
        }
        [$bsql, $bparams] = branch_access_sql($pdo, 'branch_id');
        $sql = 'SELECT * FROM conflict_parties WHERE ' . implode(' AND ', $where) . $bsql . ' ORDER BY created_at DESC LIMIT ' . (int)($opts['limit'] ?? 200);
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($params, $bparams));
        return $stmt->fetchAll();
    }
}

if (!function_exists('conflict_find_candidates')) {
    /**
     * Candidate parties that look like the given name. Block-prefiltered, then scored.
     * Never returns cross-firm rows; branch-restricted members see only their branches.
     *
     * @param array $excludeIds party ids to skip (e.g. the subject party itself)
     * @return array<int,array{party:array,score:float,match_type:string}>
     */
    function conflict_find_candidates(PDO $pdo, string $name, array $excludeIds = [], ?string $idNumber = null, ?string $cr = null, int $limit = 25): array {
        $firmId = (int)(tenant_current($pdo)['firm_id'] ?? 0);
        if (!$firmId) return [];
        $norm = conflict_normalize_name($name);
        if ($norm === '') return [];
        $block = conflict_name_block($norm);

        [$bsql, $bparams] = branch_access_sql($pdo, 'branch_id');

        // Widen the SQL prefilter on the first 3 normalised characters, plus an exact
        // id/CR probe which is a stronger signal than any name similarity.
        $params = [$firmId];
        $sql = "SELECT * FROM conflict_parties WHERE firm_id=? AND status='active'";
        $or = ['(name_block=? OR name_norm LIKE ?)'];
        $params[] = $block;
        $params[] = '%' . $norm . '%';
        $idHits = [];
        if ($idNumber !== null && $idNumber !== '') { $or[] = 'id_number=?'; $params[] = $idNumber; }
        if ($cr !== null && $cr !== '') { $or[] = 'commercial_register=?'; $params[] = $cr; }
        $sql .= ' AND (' . implode(' OR ', $or) . ')' . $bsql;

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($params, $bparams));

        $exclude = array_flip(array_map('intval', $excludeIds));
        $out = [];
        foreach ($stmt->fetchAll() as $p) {
            $pid = (int)$p['id'];
            if (isset($exclude[$pid])) continue;
            $score = conflict_match_score($norm, (string)$p['name_norm']);
            $matchType = $score >= 99.9 ? 'exact' : ($score >= 85 ? 'normalized' : 'fuzzy');
            if ($idNumber !== null && $idNumber !== '' && $p['id_number'] === $idNumber) { $score = 100.0; $matchType = 'id_number'; }
            if ($cr !== null && $cr !== '' && $p['commercial_register'] === $cr) { $score = 100.0; $matchType = 'commercial_register'; }
            if ($score < conflict_threshold()) continue;
            $out[] = ['party' => $p, 'score' => $score, 'match_type' => $matchType];
        }
        usort($out, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($out, 0, $limit);
    }
}

if (!function_exists('conflict_screen_matter')) {
    /**
     * Screen a matter for conflicts: check a set of proposed parties against the firm's
     * party register and open a review for each hit. AI flags (if supplied) are stored as
     * suggestions only.
     *
     * @param array $subjects list of ['party_type'=>..., 'name_ar'=>..., 'name_en'=>..., 'id_number'=>..., 'commercial_register'=>...]
     * @param array $opts     case_id, client_user_id, branch_id, ai (array of name=>rationale/confidence)
     * @return array{review_ids:int[],matches:int}
     */
    function conflict_screen_matter(PDO $pdo, array $subjects, array $opts = []): array {
        $ctx = tenant_current($pdo);
        $firmId = (int)($ctx['firm_id'] ?? 0);
        if (!$firmId) return ['review_ids' => [], 'matches' => 0];

        $caseId = !empty($opts['case_id']) ? (int)$opts['case_id'] : null;
        $clientId = !empty($opts['client_user_id']) ? (int)$opts['client_user_id'] : null;
        $branchId = isset($opts['branch_id']) && $opts['branch_id'] !== '' ? (int)$opts['branch_id'] : null;
        branch_access_require_branch($pdo, $branchId);

        $creatorId = (int)$ctx['lawyer_id'] ?: null;
        $reviewIds = [];
        $matchCount = 0;

        foreach ($subjects as $s) {
            $name = trim((string)($s['name_ar'] ?? '')) ?: trim((string)($s['name_en'] ?? ''));
            if ($name === '') continue;
            $idNumber = isset($s['id_number']) && $s['id_number'] !== '' ? (string)$s['id_number'] : null;
            $cr = isset($s['commercial_register']) && $s['commercial_register'] !== '' ? (string)$s['commercial_register'] : null;

            // Register the subject itself so future screenings can find it.
            $subjectPartyId = null;
            try {
                $subjectPartyId = conflict_party_create($pdo, [
                    'party_type' => $s['party_type'] ?? 'other',
                    'name_ar' => $s['name_ar'] ?? '', 'name_en' => $s['name_en'] ?? '',
                    'id_number' => $idNumber, 'commercial_register' => $cr,
                    'linked_case_id' => $caseId, 'linked_client_user_id' => $clientId, 'branch_id' => $branchId,
                ]);
            } catch (Throwable $e) {
                error_log('conflict_screen_matter: subject registration skipped: ' . $e->getMessage());
            }

            $candidates = conflict_find_candidates($pdo, $name, $subjectPartyId ? [$subjectPartyId] : [], $idNumber, $cr);
            foreach ($candidates as $c) {
                $against = in_array($c['party']['party_type'], ['client'], true) && ($s['party_type'] ?? '') === 'opposing_party';
                $severity = conflict_severity_for_score($c['score'], $against);
                $aiConf = null; $aiRationale = null;
                if (!empty($opts['ai']) && isset($opts['ai'][$name])) {
                    $aiConf = isset($opts['ai'][$name]['confidence']) ? (float)$opts['ai'][$name]['confidence'] : null;
                    $aiRationale = isset($opts['ai'][$name]['rationale']) ? mb_substr((string)$opts['ai'][$name]['rationale'], 0, 1000, 'UTF-8') : null;
                }
                $rvId = conflict_review_create($pdo, [
                    'case_id' => $caseId, 'client_user_id' => $clientId,
                    'subject_party_id' => $subjectPartyId, 'matched_party_id' => (int)$c['party']['id'],
                    'matched_case_id' => $c['party']['linked_case_id'] ? (int)$c['party']['linked_case_id'] : null,
                    'match_type' => $c['match_type'], 'match_score' => $c['score'], 'severity' => $severity,
                    'ai_assisted' => $aiRationale !== null || $aiConf !== null,
                    'ai_confidence' => $aiConf, 'ai_rationale' => $aiRationale,
                    'branch_id' => $branchId,
                ], $creatorId);
                $reviewIds[] = $rvId;
                $matchCount++;
                conflict_alert_raise($pdo, $rvId, $severity, $name);
            }
        }
        return ['review_ids' => $reviewIds, 'matches' => $matchCount];
    }
}

if (!function_exists('conflict_review_create')) {
    function conflict_review_create(PDO $pdo, array $data, ?int $actorId = null): int {
        $firmId = (int)(tenant_current($pdo)['firm_id'] ?? 0);
        if (!$firmId) throw new RuntimeException('conflict_review_create requires an active firm tenant');
        $stmt = $pdo->prepare('INSERT INTO conflict_reviews
            (firm_id, case_id, client_user_id, subject_party_id, matched_party_id, matched_case_id,
             match_type, match_score, severity, status, ai_assisted, ai_confidence, ai_rationale,
             branch_id, created_by_lawyer_id)
            VALUES (?,?,?,?,?,?,?,?,?,\'open\',?,?,?,?,?)');
        $stmt->execute([
            $firmId,
            !empty($data['case_id']) ? (int)$data['case_id'] : null,
            !empty($data['client_user_id']) ? (int)$data['client_user_id'] : null,
            !empty($data['subject_party_id']) ? (int)$data['subject_party_id'] : null,
            !empty($data['matched_party_id']) ? (int)$data['matched_party_id'] : null,
            !empty($data['matched_case_id']) ? (int)$data['matched_case_id'] : null,
            in_array($data['match_type'] ?? '', ['exact','normalized','fuzzy','id_number','commercial_register','related'], true) ? $data['match_type'] : 'normalized',
            (float)($data['match_score'] ?? 0),
            in_array($data['severity'] ?? '', conflict_severities(), true) ? $data['severity'] : 'medium',
            !empty($data['ai_assisted']) ? 1 : 0,
            isset($data['ai_confidence']) ? (float)$data['ai_confidence'] : null,
            $data['ai_rationale'] ?? null,
            !empty($data['branch_id']) ? (int)$data['branch_id'] : null,
            $actorId,
        ]);
        $id = (int)$pdo->lastInsertId();
        conflict_trail($pdo, $id, 'created', null, 'open', $data['ai_rationale'] ?? null, $actorId);
        log_action($pdo, 'إنشاء مراجعة تعارض', 'conflict_review', $id);
        return $id;
    }
}

if (!function_exists('conflict_trail')) {
    /** Append-only audit row. Callers must never UPDATE or DELETE this table. */
    function conflict_trail(PDO $pdo, ?int $reviewId, string $action, ?string $from, ?string $to, ?string $detail, ?int $actorId = null): void {
        $ctx = tenant_current($pdo);
        $firmId = (int)($ctx['firm_id'] ?? 0);
        if (!$firmId) return;
        $stmt = $pdo->prepare('INSERT INTO conflict_audit_trail
            (firm_id, review_id, actor_lawyer_id, actor_role, action, from_status, to_status, detail)
            VALUES (?,?,?,?,?,?,?,?)');
        $stmt->execute([
            $firmId, $reviewId, $actorId ?: ($ctx['lawyer_id'] ?: null), $ctx['role'] ?? null,
            $action, $from, $to, $detail !== null ? mb_substr($detail, 0, 1000, 'UTF-8') : null,
        ]);
    }
}

if (!function_exists('conflict_review_get')) {
    function conflict_review_get(PDO $pdo, int $reviewId): ?array {
        $firmId = (int)(tenant_current($pdo)['firm_id'] ?? 0);
        if (!$firmId) return null;
        $stmt = $pdo->prepare('SELECT r.*, sp.name_ar AS subject_name_ar, sp.name_en AS subject_name_en, sp.party_type AS subject_party_type,
                                      mp.name_ar AS matched_name_ar, mp.name_en AS matched_name_en, mp.party_type AS matched_party_type
                               FROM conflict_reviews r
                               LEFT JOIN conflict_parties sp ON sp.id=r.subject_party_id
                               LEFT JOIN conflict_parties mp ON mp.id=r.matched_party_id
                               WHERE r.id=? AND r.firm_id=? LIMIT 1');
        $stmt->execute([$reviewId, $firmId]);
        $row = $stmt->fetch();
        if (!$row) return null;
        if (!branch_access_can_access_branch($pdo, $row['branch_id'] !== null ? (int)$row['branch_id'] : null)) return null;
        return $row;
    }
}

if (!function_exists('conflict_review_list')) {
    function conflict_review_list(PDO $pdo, array $opts = []): array {
        $firmId = (int)(tenant_current($pdo)['firm_id'] ?? 0);
        if (!$firmId) return [];
        $where = ['r.firm_id=?'];
        $params = [$firmId];
        if (!empty($opts['status'])) { $where[] = 'r.status=?'; $params[] = $opts['status']; }
        if (!empty($opts['severity'])) { $where[] = 'r.severity=?'; $params[] = $opts['severity']; }
        if (!empty($opts['case_id'])) { $where[] = 'r.case_id=?'; $params[] = (int)$opts['case_id']; }
        if (!empty($opts['open_only'])) { $where[] = "r.status IN ('open','under_review')"; }
        [$bsql, $bparams] = branch_access_sql($pdo, 'r.branch_id');
        $sql = 'SELECT r.*, sp.name_ar AS subject_name_ar, sp.name_en AS subject_name_en,
                       mp.name_ar AS matched_name_ar, mp.name_en AS matched_name_en, c.title AS case_title
                FROM conflict_reviews r
                LEFT JOIN conflict_parties sp ON sp.id=r.subject_party_id
                LEFT JOIN conflict_parties mp ON mp.id=r.matched_party_id
                LEFT JOIN cases c ON c.id=r.case_id
                WHERE ' . implode(' AND ', $where) . $bsql . '
                ORDER BY FIELD(r.severity,\'critical\',\'high\',\'medium\',\'low\'), r.created_at DESC
                LIMIT ' . (int)($opts['limit'] ?? 200);
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($params, $bparams));
        return $stmt->fetchAll();
    }
}

if (!function_exists('conflict_review_assign')) {
    function conflict_review_assign(PDO $pdo, int $reviewId, int $reviewerLawyerId): bool {
        $review = conflict_review_get($pdo, $reviewId);
        if (!$review) return false;
        $firmId = (int)$review['firm_id'];
        // The reviewer must be an active member of the same firm.
        $chk = $pdo->prepare("SELECT 1 FROM law_firm_members WHERE firm_id=? AND lawyer_id=? AND status='active'");
        $chk->execute([$firmId, $reviewerLawyerId]);
        if (!$chk->fetchColumn()) return false;
        $stmt = $pdo->prepare("UPDATE conflict_reviews SET assigned_reviewer_lawyer_id=?, status=IF(status='open','under_review',status) WHERE id=? AND firm_id=?");
        $stmt->execute([$reviewerLawyerId, $reviewId, $firmId]);
        $to = $review['status'] === 'open' ? 'under_review' : $review['status'];
        conflict_trail($pdo, $reviewId, 'assigned', $review['status'], $to, 'مُسند إلى المحامي #' . $reviewerLawyerId);
        if ($reviewerLawyerId !== (int)(tenant_current($pdo)['lawyer_id'] ?? 0)) {
            notify($pdo, 'lawyer', $reviewerLawyerId, is_rtl() ? 'أُسندت إليك مراجعة تعارض' : 'A conflict review was assigned to you',
                (string)($review['subject_name_ar'] ?? $review['subject_name_en'] ?? ''), '/lawyer/conflicts.php?review=' . $reviewId);
        }
        return true;
    }
}

if (!function_exists('conflict_review_decide')) {
    /**
     * The only writer of a review's decision columns.
     *
     * @param string $decision one of approved|rejected|cleared_with_override|closed
     * @param array  $opts     note, override_approved_by, override_reason
     *
     * An override ("clear a conflict that a match was found for") is allowed only when a
     * reason is supplied and the approver is a firm owner/partner — the approving lawyer is
     * recorded separately from the deciding lawyer.
     */
    function conflict_review_decide(PDO $pdo, int $reviewId, string $decision, array $opts = []): array {
        $review = conflict_review_get($pdo, $reviewId);
        if (!$review) return ['ok' => false, 'error' => 'not_found'];
        if (!in_array($decision, ['approved', 'rejected', 'cleared_with_override', 'closed'], true)) {
            return ['ok' => false, 'error' => 'bad_decision'];
        }
        $ctx = tenant_current($pdo);
        $actorId = (int)($ctx['lawyer_id'] ?? 0);
        $firmId = (int)$review['firm_id'];
        $note = trim((string)($opts['note'] ?? ''));
        $overrideReason = trim((string)($opts['override_reason'] ?? ''));
        $approverId = !empty($opts['override_approved_by']) ? (int)$opts['override_approved_by'] : 0;

        if ($decision === 'cleared_with_override') {
            if ($overrideReason === '') return ['ok' => false, 'error' => 'override_reason_required'];
            if ($approverId <= 0) return ['ok' => false, 'error' => 'approver_required'];
            $chk = $pdo->prepare("SELECT 1 FROM law_firm_members WHERE firm_id=? AND lawyer_id=? AND status='active' AND member_role IN ('owner','partner')");
            $chk->execute([$firmId, $approverId]);
            if (!$chk->fetchColumn()) return ['ok' => false, 'error' => 'approver_not_authorized'];
        }

        $stmt = $pdo->prepare('UPDATE conflict_reviews
            SET status=?, decision_by_lawyer_id=?, decision_at=NOW(), decision_note=?,
                override_approved_by_lawyer_id=?, override_reason=?
            WHERE id=? AND firm_id=?');
        $stmt->execute([
            $decision, $actorId ?: null, $note !== '' ? $note : null,
            $decision === 'cleared_with_override' ? $approverId : null,
            $decision === 'cleared_with_override' ? $overrideReason : null,
            $reviewId, $firmId,
        ]);
        if ($stmt->rowCount() < 1) return ['ok' => false, 'error' => 'not_updated'];

        conflict_trail($pdo, $reviewId, $decision === 'cleared_with_override' ? 'override_cleared' : 'decision',
            $review['status'], $decision,
            $decision === 'cleared_with_override' ? ('تجاوز — السبب: ' . $overrideReason) : ($note !== '' ? $note : null),
            $actorId);

        // Members who need to know: the assigned reviewer and the firm's owners.
        $recipients = [];
        if (!empty($review['assigned_reviewer_lawyer_id'])) $recipients[] = (int)$review['assigned_reviewer_lawyer_id'];
        $owners = $pdo->prepare("SELECT lawyer_id FROM law_firm_members WHERE firm_id=? AND status='active' AND member_role IN ('owner','partner')");
        $owners->execute([$firmId]);
        foreach ($owners->fetchAll(PDO::FETCH_COLUMN) as $oid) $recipients[] = (int)$oid;
        foreach (array_unique($recipients) as $rid) {
            if ($rid === $actorId) continue;
            notify($pdo, 'lawyer', $rid, is_rtl() ? 'قرار مراجعة تعارض' : 'Conflict review decision',
                conflict_status_label($decision, is_rtl()), '/lawyer/conflicts.php?review=' . $reviewId);
        }
        log_action($pdo, 'قرار مراجعة تعارض: ' . $decision, 'conflict_review', $reviewId);
        return ['ok' => true, 'status' => $decision];
    }
}

if (!function_exists('conflict_alert_raise')) {
    function conflict_alert_raise(PDO $pdo, int $reviewId, string $severity, string $detail): int {
        $ctx = tenant_current($pdo);
        $firmId = (int)($ctx['firm_id'] ?? 0);
        if (!$firmId) return 0;
        $stmt = $pdo->prepare('INSERT INTO conflict_alerts (firm_id, review_id, alert_type, severity, detail) VALUES (?,?,?,?,?)');
        $stmt->execute([$firmId, $reviewId, 'new_conflict', $severity, mb_substr($detail, 0, 500, 'UTF-8')]);
        $id = (int)$pdo->lastInsertId();

        // Deliver to owners/partners through the existing notification channel.
        if (in_array($severity, ['high', 'critical'], true)) {
            $owners = $pdo->prepare("SELECT lawyer_id FROM law_firm_members WHERE firm_id=? AND status='active' AND member_role IN ('owner','partner')");
            $owners->execute([$firmId]);
            foreach (array_unique($owners->fetchAll(PDO::FETCH_COLUMN)) as $oid) {
                notify($pdo, 'lawyer', (int)$oid,
                    is_rtl() ? 'تنبيه تعارض محتمل' : 'Possible conflict detected',
                    $detail, '/lawyer/conflicts.php?review=' . $reviewId);
            }
        }
        return $id;
    }
}

if (!function_exists('conflict_alerts_unread_count')) {
    function conflict_alerts_unread_count(PDO $pdo): int {
        $ctx = tenant_current($pdo);
        $firmId = (int)($ctx['firm_id'] ?? 0);
        $lawyerId = (int)($ctx['lawyer_id'] ?? 0);
        if (!$firmId || !$lawyerId) return 0;
        try {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM conflict_alerts WHERE firm_id=? AND is_read=0 AND (recipient_lawyer_id IS NULL OR recipient_lawyer_id=?)');
            $stmt->execute([$firmId, $lawyerId]);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) { return 0; }
    }
}

if (!function_exists('conflict_audit_trail')) {
    function conflict_audit_trail(PDO $pdo, int $reviewId): array {
        $firmId = (int)(tenant_current($pdo)['firm_id'] ?? 0);
        if (!$firmId) return [];
        $stmt = $pdo->prepare('SELECT t.*, l.full_name AS actor_name FROM conflict_audit_trail t
                               LEFT JOIN lawyers l ON l.id=t.actor_lawyer_id
                               WHERE t.review_id=? AND t.firm_id=? ORDER BY t.created_at ASC, t.id ASC');
        $stmt->execute([$reviewId, $firmId]);
        return $stmt->fetchAll();
    }
}

if (!function_exists('conflict_case_has_open')) {
    /** Count unresolved conflicts for a case — used as a gate before work proceeds. */
    function conflict_case_has_open(PDO $pdo, int $caseId): int {
        $firmId = (int)(tenant_current($pdo)['firm_id'] ?? 0);
        if (!$firmId) return 0;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM conflict_reviews WHERE firm_id=? AND case_id=? AND status IN ('open','under_review')");
        $stmt->execute([$firmId, $caseId]);
        return (int)$stmt->fetchColumn();
    }
}

if (!function_exists('conflict_firm_reviewers')) {
    /** Active owner/partner members who can approve an override. */
    function conflict_firm_reviewers(PDO $pdo): array {
        $firmId = (int)(tenant_current($pdo)['firm_id'] ?? 0);
        if (!$firmId) return [];
        $stmt = $pdo->prepare("SELECT l.id, l.full_name, m.member_role FROM law_firm_members m
                               JOIN lawyers l ON l.id=m.lawyer_id
                               WHERE m.firm_id=? AND m.status='active' AND m.member_role IN ('owner','partner')
                               ORDER BY m.member_role, l.full_name");
        $stmt->execute([$firmId]);
        return $stmt->fetchAll();
    }
}
