<?php
/**
 * Multi-tenant resolution for the legal SaaS core (v42).
 *
 * Tenant model:
 *   Platform : admins (super_admin / editor / support) — platform-wide, sees every tenant.
 *   Firm     : law_firms — the tenant boundary for firm-owned legal work.
 *   Lawyer   : lawyers — may belong to at most one firm (law_firm_members).
 *   Client   : users — a client's visibility is derived from their own records only.
 *
 * A lawyer with no active firm membership is a solo practitioner whose tenant scope is
 * their own lawyer_id. All firm-owned entities carry a firm_id (never NULL once the lawyer
 * is inside a firm); resolving the tenant from the authenticated session — never from a
 * request parameter — is what makes tenant isolation enforceable server-side.
 */

/** Returns the current tenant context (firm id, lawyer id, role) for the signed-in lawyer. */
function tenant_current(?PDO $pdo = null): array {
    static $cache = [];
    $pdo = $pdo ?: ($GLOBALS['pdo'] ?? null);
    if (!$pdo instanceof PDO) {
        return ['firm_id' => null, 'lawyer_id' => 0, 'role' => null, 'is_firm' => false];
    }
    $lawyerId = (int)($_SESSION['lawyer_id'] ?? 0);
    $key = spl_object_id($pdo) . ':' . $lawyerId;
    if (isset($cache[$key])) return $cache[$key];

    $ctx = ['firm_id' => null, 'lawyer_id' => $lawyerId, 'role' => null, 'is_firm' => false];
    if ($lawyerId > 0) {
        try {
            $stmt = $pdo->prepare("SELECT m.firm_id, m.member_role FROM law_firm_members m WHERE m.lawyer_id=? AND m.status='active' LIMIT 1");
            $stmt->execute([$lawyerId]);
            $row = $stmt->fetch();
            if ($row) {
                $ctx['firm_id'] = (int)$row['firm_id'];
                $ctx['role'] = $row['member_role'];
                $ctx['is_firm'] = true;
            }
        } catch (PDOException $e) {
            // Pre-v42 installs have no firm tables yet; fall back to solo scope.
            error_log('Tenant resolution failed: ' . $e->getMessage());
        }
    }
    return $cache[$key] = $ctx;
}

function tenant_lawyer_id(?PDO $pdo = null): int { return tenant_current($pdo)['lawyer_id']; }
function tenant_firm_id(?PDO $pdo = null): ?int { return tenant_current($pdo)['firm_id']; }
function tenant_is_firm(?PDO $pdo = null): bool { return tenant_current($pdo)['is_firm']; }
function tenant_role(?PDO $pdo = null): ?string { return tenant_current($pdo)['role']; }

/**
 * Mirror the resolved tenant context into the session so guards and views can read the
 * active firm/role without re-querying. The database stays authoritative: call this after
 * login and at the start of the lawyer guard so stale memberships self-correct.
 */
function tenant_sync_session(?PDO $pdo = null): array {
    $ctx = tenant_current($pdo);
    $_SESSION['tenant_firm_id'] = $ctx['firm_id'];
    $_SESSION['tenant_role'] = $ctx['role'];
    $_SESSION['tenant_is_firm'] = $ctx['is_firm'];
    return $ctx;
}

/**
 * Resolve a tenant context for an explicit lawyer id, independent of the PHP session.
 * Used by token-authenticated API routes where there is no session lawyer_id.
 */
function tenant_context_for_lawyer(PDO $pdo, int $lawyerId): array {
    $ctx = ['firm_id' => null, 'lawyer_id' => $lawyerId, 'role' => null, 'is_firm' => false];
    if ($lawyerId > 0) {
        try {
            $stmt = $pdo->prepare("SELECT m.firm_id, m.member_role FROM law_firm_members m WHERE m.lawyer_id=? AND m.status='active' LIMIT 1");
            $stmt->execute([$lawyerId]);
            $row = $stmt->fetch();
            if ($row) {
                $ctx['firm_id'] = (int)$row['firm_id'];
                $ctx['role'] = $row['member_role'];
                $ctx['is_firm'] = true;
            }
        } catch (PDOException $e) {
            error_log('Tenant resolution (explicit lawyer) failed: ' . $e->getMessage());
        }
    }
    return $ctx;
}

/**
 * SQL condition + params restricting a query to the tenant of an explicit lawyer id.
 *
 * @return array{sql:string, params:array}
 */
function tenant_owner_where_for_lawyer(PDO $pdo, int $lawyerId, string $alias = 'c', string $lawyerColumn = 'lawyer_id'): array {
    $ctx = tenant_context_for_lawyer($pdo, $lawyerId);
    if ($ctx['is_firm']) {
        return ['sql' => "$alias.firm_id = ?", 'params' => [$ctx['firm_id']]];
    }
    return ['sql' => "$alias.$lawyerColumn = ?", 'params' => [$lawyerId]];
}

/**
 * SQL condition + params restricting a query to rows the current tenant may see.
 *
 * @param string $alias Table alias of an entity that carries a `firm_id` column.
 * @return array{sql:string, params:array}
 */
function tenant_owner_where(PDO $pdo, string $alias = 'c', string $lawyerColumn = 'lawyer_id'): array {
    $ctx = tenant_current($pdo);
    if ($ctx['is_firm']) {
        // Firm rows are always tagged with the firm id; legacy NULL rows are adopted on join.
        return ['sql' => "$alias.firm_id = ?", 'params' => [$ctx['firm_id']]];
    }
    return ['sql' => "$alias.$lawyerColumn = ?", 'params' => [$ctx['lawyer_id']]];
}

/**
 * Subquery selecting the case ids the current tenant owns (firm-wide or solo).
 * Safe to interpolate: it returns only bound placeholders, no user input.
 *
 * @return array{sql:string, params:array}
 */
function tenant_case_ids_subquery(PDO $pdo): array {
    return tenant_owner_where($pdo, 'c');
}

/**
 * Subquery selecting the client (users.id) the current tenant may see — derived from
 * cases, consultations, and invoices that belong to the tenant.
 *
 * @return array{sql:string, params:array}
 */
function tenant_client_ids_subquery(PDO $pdo): array {
    $ctx = tenant_current($pdo);
    if ($ctx['is_firm']) {
        $fid = $ctx['firm_id'];
        return [
            'sql' => 'SELECT user_id FROM cases WHERE firm_id=? UNION SELECT user_id FROM consultations WHERE firm_id=? UNION SELECT user_id FROM invoices WHERE firm_id=?',
            'params' => [$fid, $fid, $fid],
        ];
    }
    $lid = $ctx['lawyer_id'];
    return [
        'sql' => 'SELECT user_id FROM cases WHERE lawyer_id=? UNION SELECT user_id FROM consultations WHERE lawyer_id=? UNION SELECT user_id FROM invoices WHERE lawyer_id=?',
        'params' => [$lid, $lid, $lid],
    ];
}

/** True when the given case row (with firm_id/lawyer_id) is visible to the current tenant. */
function tenant_owns_case_row(?PDO $pdo, ?array $caseRow): bool {
    if (!$caseRow) return false;
    $ctx = tenant_current($pdo);
    if ($ctx['is_firm']) {
        return (int)($caseRow['firm_id'] ?? 0) === (int)$ctx['firm_id'];
    }
    return (int)($caseRow['lawyer_id'] ?? 0) === (int)$ctx['lawyer_id'];
}

/**
 * Resolve the firm id that a newly created business row should be tagged with.
 * Solo lawyers get NULL (their rows are scoped by lawyer_id).
 */
function tenant_write_firm_id(?PDO $pdo = null): ?int {
    return tenant_firm_id($pdo);
}

/**
 * Adopt a lawyer's existing solo rows into a firm when they join it, so no historical
 * record becomes invisible. Called when a membership is created (self-serve or admin).
 */
function tenant_adopt_lawyer_data(PDO $pdo, int $firmId, int $lawyerId): void {
    if ($firmId <= 0 || $lawyerId <= 0) return;
    $tables = [
        'cases' => 'lawyer_id',
        'consultations' => 'lawyer_id',
        'invoices' => 'lawyer_id',
        'draft_documents' => 'lawyer_id',
        'case_time_entries' => 'lawyer_id',
        'legal_calendar_events' => 'lawyer_id',
    ];
    foreach ($tables as $table => $column) {
        $pdo->prepare("UPDATE $table SET firm_id=? WHERE $column=? AND firm_id IS NULL")->execute([$firmId, $lawyerId]);
    }
    // Evidence and notes inherit the tenant through their case.
    $pdo->prepare('UPDATE case_evidence e JOIN cases c ON c.id=e.case_id SET e.firm_id=c.firm_id WHERE c.lawyer_id=? AND e.firm_id IS NULL')->execute([$lawyerId]);
    $pdo->prepare('UPDATE case_notes n JOIN cases c ON c.id=n.case_id SET n.firm_id=c.firm_id WHERE c.lawyer_id=? AND n.firm_id IS NULL')->execute([$lawyerId]);
}

/**
 * The full set of assignable tenant capabilities, grouped for the permission editor UI.
 * Any permission a firm owner grants to a member must come from this list.
 */
function tenant_permission_catalog(): array {
    return [
        'firm'          => ['firm.view', 'firm.edit', 'firm.manage_members'],
        'clients'       => ['clients.view', 'clients.create', 'clients.edit', 'clients.delete'],
        'cases'         => ['cases.view', 'cases.create', 'cases.edit', 'cases.delete', 'cases.assign', 'cases.export'],
        'documents'     => ['documents.view', 'documents.upload', 'documents.download', 'documents.delete'],
        'evidence'      => ['evidence.view', 'evidence.upload', 'evidence.download', 'evidence.delete'],
        'consultations' => ['consultations.view', 'consultations.manage', 'consultations.respond'],
        'drafting'      => ['drafting.create', 'drafting.edit', 'drafting.review', 'drafting.approve', 'drafting.export'],
        'finance'       => ['finance.view_invoices', 'finance.create_invoices', 'finance.record_payments', 'finance.view_reports'],
        'calendar'      => ['calendar.manage'],
    ];
}

/** Flat list of every assignable capability. */
function tenant_all_permissions(): array {
    return array_merge(...array_values(tenant_permission_catalog()));
}

/** Capability map for firm member roles. Owner and partner manage the firm's legal work;
 * staff is a view/upload role. Per-member permissions_json can further restrict (never widen)
 * these defaults.
 */
function tenant_firm_capabilities(string $role): array {
    $all = [
        'firm.view', 'firm.edit', 'firm.manage_members',
        'clients.view', 'clients.create', 'clients.edit', 'clients.delete',
        'cases.view', 'cases.create', 'cases.edit', 'cases.delete', 'cases.assign', 'cases.export',
        'documents.view', 'documents.upload', 'documents.download', 'documents.delete',
        'evidence.view', 'evidence.upload', 'evidence.download', 'evidence.delete',
        'consultations.view', 'consultations.manage', 'consultations.respond',
        'drafting.create', 'drafting.edit', 'drafting.review', 'drafting.approve', 'drafting.export',
        'finance.view_invoices', 'finance.create_invoices', 'finance.record_payments', 'finance.view_reports',
        'calendar.manage',
    ];
    $partner = array_diff($all, ['firm.manage_members', 'clients.delete', 'finance.view_reports']);
    $lawyer = [
        'firm.view', 'clients.view', 'clients.create', 'clients.edit',
        'cases.view', 'cases.create', 'cases.edit', 'cases.export',
        'documents.view', 'documents.upload', 'documents.download',
        'evidence.view', 'evidence.upload', 'evidence.download',
        'consultations.view', 'consultations.respond',
        'drafting.create', 'drafting.edit', 'drafting.review', 'drafting.export', 'calendar.manage',
    ];
    $staff = [
        'firm.view', 'clients.view',
        'cases.view', 'documents.view', 'documents.upload', 'documents.download',
        'evidence.view', 'evidence.upload', 'evidence.download', 'consultations.view', 'calendar.manage',
    ];
    return [
        'owner' => $all,
        'partner' => array_values($partner),
        'lawyer' => $lawyer,
        'staff' => $staff,
    ][$role] ?? [];
}

/**
 * Optional per-member permission override stored on the membership row.
 * Returns null when the member has no override, so the role defaults apply.
 */
function tenant_member_override(PDO $pdo, int $firmId, int $lawyerId): ?array {
    try {
        $stmt = $pdo->prepare('SELECT permissions_json FROM law_firm_members WHERE lawyer_id=? AND firm_id=? LIMIT 1');
        $stmt->execute([$lawyerId, $firmId]);
        $raw = $stmt->fetchColumn();
        if (is_string($raw) && $raw !== '') {
            $override = json_decode($raw, true);
            if (is_array($override)) return array_values(array_filter($override, 'is_string'));
        }
    } catch (PDOException $e) {
        error_log('Tenant permission lookup failed: ' . $e->getMessage());
    }
    return null;
}

/**
 * Effective capability list for a lawyer resolved by id, independent of the PHP session.
 * Token-authenticated API routes have no session, so they cannot use tenant_can(); this is
 * the single source both paths share. A solo practitioner owns their whole practice.
 */
function tenant_permissions_for_lawyer(PDO $pdo, int $lawyerId): array {
    $ctx = tenant_context_for_lawyer($pdo, $lawyerId);
    if (!$ctx['is_firm']) return tenant_all_permissions();
    $override = tenant_member_override($pdo, (int)$ctx['firm_id'], $lawyerId);
    return $override ?? tenant_firm_capabilities($ctx['role'] ?? 'lawyer');
}

/**
 * Capability check for a lawyer resolved by id — the token-authenticated counterpart of
 * tenant_can(). Both read tenant_permissions_for_lawyer(), so the API and the web app can
 * never disagree about what a role may do.
 */
function tenant_can_for_lawyer(PDO $pdo, int $lawyerId, string $permission): bool {
    return in_array($permission, tenant_permissions_for_lawyer($pdo, $lawyerId), true);
}

/**
 * Whether the current lawyer holds a capability within their tenant.
 * Solo lawyers are treated as their own owner (full legal-core capabilities).
 */
function tenant_can(?PDO $pdo, string $permission): bool {
    $ctx = tenant_current($pdo);
    if (!$ctx['is_firm']) {
        // Solo practitioner — full control of their own practice.
        return true;
    }
    if (!$pdo instanceof PDO) {
        return in_array($permission, tenant_firm_capabilities($ctx['role'] ?? 'lawyer'), true);
    }
    $override = tenant_member_override($pdo, (int)$ctx['firm_id'], (int)$ctx['lawyer_id']);
    $caps = $override ?? tenant_firm_capabilities($ctx['role'] ?? 'lawyer');
    return in_array($permission, $caps, true);
}

/** Abort with 403 unless the current lawyer holds the capability. */
function tenant_require(?PDO $pdo, string $permission): void {
    if (!tenant_can($pdo, $permission)) {
        http_response_code(403);
        exit('Forbidden');
    }
}
