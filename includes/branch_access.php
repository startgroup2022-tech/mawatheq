<?php
/**
 * Branch-level access isolation (Executive 08).
 *
 * Firm isolation (includes/tenant.php) is the outer boundary and always applies. This
 * layer narrows *within* a firm: a member restricted to one branch must not read a
 * record filed at another branch.
 *
 * Policy, in order:
 *   1. Solo lawyer (no firm)            → unrestricted (their own practice).
 *   2. owner / partner                  → firm-wide; may read every branch.
 *   3. member with explicit branch grants (member_branch_access) or a home branch
 *      (law_firm_members.branch_id)    → restricted to exactly those branches.
 *   4. member with no branch assignment → firm-wide. Branch scoping is opt-in per member;
 *      treating "no assignment" as "no access" would silently hide a firm's records from
 *      every existing member the moment this feature ships, so the safe reading of an
 *      unset assignment is "the firm has not started branching this member".
 *
 * A NULL `branch_id` on a record always means "not yet assigned / firm-wide" and stays
 * visible: the migration never invents a branch for legacy rows, and hiding them would
 * make a firm's own back catalogue disappear. Cross-branch visibility for a restricted
 * member requires an explicit `branch_record_shares` row (or a manager grant).
 *
 * Every function here is server-side and takes the firm from the session, never from a
 * request parameter. Query builders return SQL + bound params so callers can AND the
 * predicate onto their own firm-scoped WHERE clause.
 */

require_once __DIR__ . '/tenant.php';

if (!function_exists('branch_access_context')) {
    /**
     * Resolved branch context for the current lawyer, cached per (PDO, lawyer).
     *
     * @return array{
     *   lawyer_id:int, firm_id:?int, role:?string, is_firm:bool,
     *   firm_wide:bool, branches:int[]|null, manager_branches:int[]
     * }
     * `branches` is null when firm-wide, otherwise the allowed branch id list.
     */
    function branch_access_context(?PDO $pdo = null): array {
        $pdo = $pdo ?: ($GLOBALS['pdo'] ?? null);
        if (!$pdo instanceof PDO) {
            return ['lawyer_id' => 0, 'firm_id' => null, 'role' => null, 'is_firm' => false,
                    'firm_wide' => true, 'branches' => null, 'manager_branches' => []];
        }
        $lawyerId = (int)($_SESSION['lawyer_id'] ?? 0);
        $key = spl_object_id($pdo) . ':' . $lawyerId;
        // Cache holder is a global (not a function-static) so branch_access_flush_cache()
        // can invalidate it: a membership or grant change made mid-request must take effect
        // immediately, otherwise the caller keeps acting on the pre-change access set.
        $GLOBALS['__branch_access_cache'] = $GLOBALS['__branch_access_cache'] ?? [];
        $cache = &$GLOBALS['__branch_access_cache'];
        if (isset($cache[$key])) return $cache[$key];

        $base = ['lawyer_id' => $lawyerId, 'firm_id' => null, 'role' => null, 'is_firm' => false,
                 'firm_wide' => true, 'branches' => null, 'manager_branches' => []];

        $tenant = tenant_current($pdo); // tenant_current caches on session lawyer_id
        if (!$tenant['is_firm'] || empty($tenant['firm_id'])) {
            return $cache[$key] = $base;
        }

        $firmId = (int)$tenant['firm_id'];
        $role = (string)($tenant['role'] ?? '');
        $ctx = $base;
        $ctx['firm_id'] = $firmId;
        $ctx['role'] = $role;
        $ctx['is_firm'] = true;

        // Owners and partners manage the whole firm.
        if (in_array($role, ['owner', 'partner'], true)) {
            return $cache[$key] = $ctx;
        }

        $branches = [];
        $managers = [];
        try {
            $stmt = $pdo->prepare('SELECT branch_id FROM law_firm_members WHERE lawyer_id=? AND firm_id=? AND status="active" LIMIT 1');
            $stmt->execute([$lawyerId, $firmId]);
            $home = $stmt->fetchColumn();
            if ($home !== false && $home !== null) {
                $branches[] = (int)$home;
            }

            $stmt = $pdo->prepare('SELECT branch_id, access_level FROM member_branch_access WHERE lawyer_id=? AND firm_id=?');
            $stmt->execute([$lawyerId, $firmId]);
            foreach ($stmt->fetchAll() as $g) {
                $branches[] = (int)$g['branch_id'];
                if ($g['access_level'] === 'manager') $managers[] = (int)$g['branch_id'];
            }
        } catch (PDOException $e) {
            // Pre-v51 install has no branch tables yet: treat as firm-wide rather than
            // locking a lawyer out of their own firm's data.
            error_log('Branch access lookup failed, defaulting to firm-wide: ' . $e->getMessage());
            return $cache[$key] = $ctx;
        }

        $branches = array_values(array_unique($branches));
        if (!$branches) {
            // No assignment → firm-wide (rule 4).
            return $cache[$key] = $ctx;
        }
        $ctx['firm_wide'] = false;
        $ctx['branches'] = $branches;
        $ctx['manager_branches'] = array_values(array_unique($managers));
        return $cache[$key] = $ctx;
    }
}

if (!function_exists('branch_access_flush_cache')) {
    /**
     * Invalidate the per-request branch context after a membership or grant change, so the
     * new access set is used for the rest of the request.
     */
    function branch_access_flush_cache(): void {
        $GLOBALS['__branch_access_cache'] = [];
    }
}

if (!function_exists('branch_access_is_firm_wide')) {
    function branch_access_is_firm_wide(?PDO $pdo = null): bool {
        return branch_access_context($pdo)['firm_wide'];
    }
}

if (!function_exists('branch_access_allowed_branches')) {
    /** @return int[]|null null = every branch in the firm. */
    function branch_access_allowed_branches(?PDO $pdo = null): ?array {
        return branch_access_context($pdo)['branches'];
    }
}

if (!function_exists('branch_access_can_access_branch')) {
    /**
     * Whether the current lawyer may act on a record filed at $branchId.
     * A null branch id is always accessible (unassigned / firm-wide legacy data).
     */
    function branch_access_can_access_branch(?PDO $pdo, ?int $branchId): bool {
        if ($branchId === null) return true;
        $ctx = branch_access_context($pdo);
        if ($ctx['firm_wide']) return true;
        return in_array((int)$branchId, $ctx['branches'] ?? [], true);
    }
}

if (!function_exists('branch_access_is_manager_of')) {
    function branch_access_is_manager_of(?PDO $pdo, int $branchId): bool {
        $ctx = branch_access_context($pdo);
        if (in_array($ctx['role'] ?? '', ['owner', 'partner'], true)) return true;
        return in_array($branchId, $ctx['manager_branches'] ?? [], true);
    }
}

if (!function_exists('branch_access_require_branch')) {
    /** Abort 403 unless the current lawyer may act on records at $branchId. */
    function branch_access_require_branch(?PDO $pdo, ?int $branchId): void {
        if (!branch_access_can_access_branch($pdo, $branchId)) {
            http_response_code(403);
            exit('Forbidden');
        }
    }
}

if (!function_exists('branch_access_sql')) {
    /**
     * SQL predicate that keeps only rows the current lawyer may see, AND-ed onto the
     * caller's firm-scoped query.
     *
     * @param string      $column     qualified column holding the row's branch id (e.g. 'c.branch_id')
     * @param string|null $entityType when given, enables the branch_record_shares lookup
     * @param string|null $entityId   qualified column holding the row id (e.g. 'c.id')
     * @return array{0:string,1:array} [sql, params]; sql is '' when unrestricted
     */
    function branch_access_sql(?PDO $pdo, string $column = 'branch_id', ?string $entityType = null, ?string $entityId = null): array {
        $ctx = branch_access_context($pdo);
        if ($ctx['firm_wide']) return ['', []];
        $branches = $ctx['branches'] ?? [];
        if (!$branches) return [' AND 1=0', []];

        $ph = implode(',', array_fill(0, count($branches), '?'));
        // Unassigned (NULL) rows stay visible; so do rows filed at an allowed branch,
        // and rows another branch explicitly shared with one of the allowed branches.
        $sql = " AND ($column IS NULL OR $column IN ($ph)";
        $params = $branches;

        if ($entityType !== null && $entityId !== null) {
            $sql .= " OR EXISTS (SELECT 1 FROM branch_record_shares s
                                 WHERE s.entity_type=? AND s.entity_id=$entityId
                                   AND s.revoked_at IS NULL AND s.branch_id IN ($ph))";
            $params[] = $entityType;
            $params = array_merge($params, $branches);
        }
        $sql .= ')';

        return [$sql, $params];
    }
}

if (!function_exists('branch_access_filter_rows')) {
    /**
     * Row-level guard for results that were fetched by primary key (e.g. a detail page).
     * Returns true when the row is reachable by the current lawyer.
     */
    function branch_access_allows_row(?PDO $pdo, ?array $row, string $column = 'branch_id'): bool {
        if (!$row) return false;
        return branch_access_can_access_branch($pdo, isset($row[$column]) && $row[$column] !== null ? (int)$row[$column] : null);
    }
}

if (!function_exists('branch_access_set_member_branch')) {
    /**
     * Set a member's home branch (null = firm-wide). Firm owner/partner only, and the
     * target branch must be in the caller's own firm. Flushes the context cache.
     */
    function branch_access_set_member_branch(PDO $pdo, int $lawyerId, ?int $branchId): bool {
        $ctx = branch_access_context($pdo);
        if (empty($ctx['firm_id'])) return false;
        if (!in_array($ctx['role'] ?? '', ['owner', 'partner'], true)) return false;
        if ($branchId !== null) {
            $chk = $pdo->prepare('SELECT 1 FROM firm_branches WHERE id=? AND firm_id=?');
            $chk->execute([$branchId, (int)$ctx['firm_id']]);
            if (!$chk->fetchColumn()) return false;
        }
        $stmt = $pdo->prepare('UPDATE law_firm_members SET branch_id=? WHERE firm_id=? AND lawyer_id=?');
        $stmt->execute([$branchId, (int)$ctx['firm_id'], $lawyerId]);
        branch_access_flush_cache();
        log_action($pdo, 'تحديد فرع العضو', 'law_firm_member', $lawyerId);
        return true;
    }
}

if (!function_exists('branch_access_grant')) {
    /** Grant a member access to another branch. Owner/partner only. */
    function branch_access_grant(PDO $pdo, int $lawyerId, int $branchId, string $level = 'member'): bool {
        $ctx = branch_access_context($pdo);
        if (empty($ctx['firm_id']) || !in_array($ctx['role'] ?? '', ['owner', 'partner'], true)) return false;
        if (!in_array($level, ['member', 'manager'], true)) $level = 'member';
        $chk = $pdo->prepare('SELECT 1 FROM firm_branches WHERE id=? AND firm_id=?');
        $chk->execute([$branchId, (int)$ctx['firm_id']]);
        if (!$chk->fetchColumn()) return false;
        $pdo->prepare('INSERT INTO member_branch_access (firm_id,lawyer_id,branch_id,access_level,granted_by_lawyer_id)
                       VALUES (?,?,?,?,?)
                       ON DUPLICATE KEY UPDATE access_level=VALUES(access_level), granted_by_lawyer_id=VALUES(granted_by_lawyer_id)')
            ->execute([(int)$ctx['firm_id'], $lawyerId, $branchId, $level, (int)$ctx['lawyer_id'] ?: null]);
        branch_access_flush_cache();
        log_action($pdo, 'منح صلاحية فرع', 'member_branch_access', $lawyerId);
        return true;
    }
}

if (!function_exists('branch_access_revoke')) {
    function branch_access_revoke(PDO $pdo, int $lawyerId, int $branchId): bool {
        $ctx = branch_access_context($pdo);
        if (empty($ctx['firm_id']) || !in_array($ctx['role'] ?? '', ['owner', 'partner'], true)) return false;
        $stmt = $pdo->prepare('DELETE FROM member_branch_access WHERE firm_id=? AND lawyer_id=? AND branch_id=?');
        $stmt->execute([(int)$ctx['firm_id'], $lawyerId, $branchId]);
        branch_access_flush_cache();
        return $stmt->rowCount() > 0;
    }
}

if (!function_exists('branch_record_share')) {
    /** Share one record with another branch so that branch's members can see it. */
    function branch_record_share(PDO $pdo, string $entityType, int $entityId, int $branchId): bool {
        $ctx = branch_access_context($pdo);
        if (empty($ctx['firm_id'])) return false;
        if (!in_array($entityType, ['case', 'document', 'evidence', 'consultation', 'invoice'], true)) return false;
        // The sharer must be able to reach the record themselves.
        branch_access_require_branch($pdo, $branchId);
        $pdo->prepare('INSERT INTO branch_record_shares (firm_id,entity_type,entity_id,branch_id,granted_by_lawyer_id)
                       VALUES (?,?,?,?,?)
                       ON DUPLICATE KEY UPDATE revoked_at=NULL, granted_by_lawyer_id=VALUES(granted_by_lawyer_id)')
            ->execute([(int)$ctx['firm_id'], $entityType, $entityId, $branchId, (int)$ctx['lawyer_id'] ?: null]);
        log_action($pdo, 'مشاركة سجل مع فرع', $entityType, $entityId);
        return true;
    }
}

if (!function_exists('branch_record_unshare')) {
    function branch_record_unshare(PDO $pdo, string $entityType, int $entityId, int $branchId): bool {
        $ctx = branch_access_context($pdo);
        if (empty($ctx['firm_id'])) return false;
        $stmt = $pdo->prepare('UPDATE branch_record_shares SET revoked_at=NOW()
                               WHERE firm_id=? AND entity_type=? AND entity_id=? AND branch_id=? AND revoked_at IS NULL');
        $stmt->execute([(int)$ctx['firm_id'], $entityType, $entityId, $branchId]);
        return $stmt->rowCount() > 0;
    }
}

if (!function_exists('branch_access_firm_branches')) {
    /** Active branches of the current firm, for select inputs. [] when not in a firm. */
    function branch_access_firm_branches(?PDO $pdo = null): array {
        $pdo = $pdo ?: ($GLOBALS['pdo'] ?? null);
        $ctx = branch_access_context($pdo);
        if (!$ctx['firm_id']) return [];
        try {
            $stmt = $pdo->prepare("SELECT id, name_ar, name_en, city, is_primary FROM firm_branches
                                   WHERE firm_id=? AND status='active' ORDER BY is_primary DESC, name_ar");
            $stmt->execute([$ctx['firm_id']]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log('Branch list failed: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('branch_access_branch_label')) {
    function branch_access_branch_label(?array $branch, bool $rtl = true): string {
        if (!$branch) return $rtl ? 'غير محدّد (على مستوى المكتب)' : 'Unassigned (firm-wide)';
        $name = $rtl ? ($branch['name_ar'] ?? '') : ($branch['name_en'] ?? $branch['name_ar'] ?? '');
        return (string)$name;
    }
}
