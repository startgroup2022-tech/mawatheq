<?php
/**
 * Granular administrative permissions (Execution 34).
 *
 * The console's original access control is role-based: `admin_route_roles()` in
 * admin_rbac.php maps a script name to the roles that may open it. That works for three
 * fixed roles, but a role is a single value, so it cannot say "this person may read the
 * blog and nothing else".
 *
 * This file adds a fourth role, `staff`, whose access is decided by rows in
 * `admin_staff_permissions` — one row per (module, action) pair actually granted. It does
 * not replace the role model: `super_admin`, `editor` and `support` keep resolving through
 * `admin_role_allows()` exactly as before, and a database with no staff account behaves
 * identically to one that predates this file.
 *
 * THREE RULES GOVERN EVERY DECISION HERE
 *
 *   1. Deny by default. No grant row means no access. An unknown route, an unknown module
 *      and an empty grant set are all treated the same way: refused.
 *   2. Some areas are not grantable at all. Confidential case material, client records,
 *      finance, AI keys, security settings, backups and permission administration are
 *      marked `grantable = 0` in `admin_staff_modules` and refused even if a grant row is
 *      inserted by hand. Dashboard access never implies any of them.
 *   3. The database is the authority, not the session. The role and the active flag are
 *      re-read on every check, so deactivating an account takes effect on the next request
 *      rather than the next login.
 */

/**
 * The closed set of actions that can be granted.
 *
 * Deliberately small. `delete` and `export` are separate from `edit` because a person who
 * may correct a record is not necessarily a person who may destroy it or take a copy of
 * the whole table.
 *
 * @return array<string, array{ar: string, en: string}>
 */
function admin_staff_actions(): array {
    return [
        'view'    => ['ar' => 'عرض',    'en' => 'View'],
        'create'  => ['ar' => 'إنشاء',  'en' => 'Create'],
        'edit'    => ['ar' => 'تعديل',  'en' => 'Edit'],
        'delete'  => ['ar' => 'حذف',    'en' => 'Delete'],
        'approve' => ['ar' => 'اعتماد', 'en' => 'Approve'],
        'export'  => ['ar' => 'تصدير',  'en' => 'Export'],
    ];
}

/**
 * Which console script belongs to which module.
 *
 * A script absent from this map is denied to staff outright — that is the point of the
 * map being an allow-list. Adding a new console page therefore means adding it here as
 * well as to `admin_route_roles()`; forgetting the first locks staff out of the page
 * (safe), forgetting the second leaves it super_admin-only (also safe).
 *
 * `logout.php` is handled separately in admin_authorize_route() because a person must
 * always be able to sign out.
 *
 * @return array<string, string> script name => module key
 */
function admin_module_routes(): array {
    return [
        // ---- grantable modules ------------------------------------------------
        'index.php'                  => 'dashboard',
        'articles.php'               => 'blog', 
        'banners.php'                => 'blog',
        'laws.php'                   => 'laws',
        'classification.php'         => 'classification',
        'leads.php'                  => 'leads',
        'crm.php'                    => 'crm',
        'affiliate-campaigns.php'    => 'marketing',
        'affiliate-applications.php' => 'affiliate-applications',
        'reports.php'                => 'reports',
        'notifications.php'          => 'notifications',
        'calendar.php'               => 'calendar',

        // ---- present but not grantable: mapped so the refusal is explicit -----
        // Mapping these is deliberate. If they were simply absent the answer would be
        // the same (denied), but the console could not explain *why* they are refused.
        'users.php'                  => 'clients',
        'client-detail.php'          => 'clients',
        'lawyers.php'                => 'clients',
        'firms.php'                  => 'clients',
        'consultations.php'          => 'consultations',
        'consultation-attachments.php' => 'consultations',
        'documents.php'              => 'cases',
        'search.php'                 => 'cases',
        'invoices.php'               => 'finance',
        'plans.php'                  => 'finance',
        'gateways.php'               => 'payments',
        'gateway-transactions.php'   => 'payments',
        'gateway-transaction.php'    => 'payments',
        'payment-test.php'           => 'payments',
        'sadad-test.php'             => 'payments',
        'affiliates.php'             => 'affiliate-finance',
        'affiliate-detail.php'       => 'affiliate-finance',
        'affiliate-commissions.php'  => 'affiliate-finance',
        'affiliate-payouts.php'      => 'affiliate-finance',
        'affiliate-payout.php'       => 'affiliate-finance',
        'affiliate-settings.php'     => 'affiliate-finance',
        'affiliate-fraud.php'        => 'affiliate-finance',
        'ai-control.php'             => 'ai',
        'ai-knowledge.php'           => 'ai',
        'ai-operations.php'          => 'ai',
        'ai-orchestration.php'       => 'ai',
        'ai-workflows.php'           => 'ai',
        'ai_test.php'                => 'ai',
        'reindex-embeddings.php'     => 'ai',
        'settings.php'               => 'system-settings',
        'two-factor.php'             => 'security',
        'staff.php'                  => 'staff',
        'staff-edit.php'             => 'staff',
        'integrations.php'           => 'integrations',
        'audit-log.php'              => 'audit',
        'setup.php'                  => 'system-settings',
    ];
}

/** The module a console script belongs to, or null when the script is not mapped. */
function admin_route_module(string $route): ?string {
    return admin_module_routes()[basename($route)] ?? null;
}

/**
 * The action a write request needs, inferred from what it is asking for.
 *
 * The console posts an `action` field for destructive and state-changing operations. Where
 * it is absent the request is treated as an ordinary edit, which is the common case for a
 * plain form save. The strongest matching keyword wins, so `action=delete` cannot be
 * satisfied by an `edit` grant.
 *
 * @param array<string, mixed> $post
 */
function admin_write_action(array $post): string {
    $action = strtolower(trim((string)($post['action'] ?? '')));
    if ($action === '') return 'edit';
    foreach (['delete' => 'delete', 'remove' => 'delete', 'destroy' => 'delete',
              'approve' => 'approve', 'reject' => 'approve', 'publish' => 'approve',
              'export' => 'export', 'download' => 'export', 'csv' => 'export',
              'create' => 'create', 'add' => 'create', 'new' => 'create'] as $needle => $actionKey) {
        if (str_contains($action, $needle)) return $actionKey;
    }
    return 'edit';
}

/**
 * Read the module catalogue.
 *
 * @return list<array<string, mixed>>
 */
function admin_staff_module_catalogue(PDO $pdo): array {
    try {
        $rows = $pdo->query(
            'SELECT module_key, label_ar, label_en, description_ar, description_en, grantable, is_sensitive, sort_order
             FROM admin_staff_modules ORDER BY sort_order, module_key'
        )->fetchAll(PDO::FETCH_ASSOC);
        return $rows ?: [];
    } catch (Throwable $e) {
        // Pre-v60 database. Returning nothing denies every staff request, which is the
        // safe direction: no catalogue means no module can be shown to be grantable.
        error_log('admin_staff_module_catalogue: ' . $e->getMessage());
        return [];
    }
}

/** One catalogue entry by key, or null. */
function admin_staff_module(PDO $pdo, string $moduleKey): ?array {
    foreach (admin_staff_module_catalogue($pdo) as $module) {
        if ($module['module_key'] === $moduleKey) return $module;
    }
    return null;
}

/** The module keys that may ever be granted to a restricted employee. */
function admin_staff_grantable_modules(PDO $pdo): array {
    $out = [];
    foreach (admin_staff_module_catalogue($pdo) as $module) {
        if ((int)$module['grantable'] === 1) $out[] = $module['module_key'];
    }
    return $out;
}

/**
 * Every grant held by one account, as module => list of actions.
 *
 * @return array<string, list<string>>
 */
function admin_staff_permissions(PDO $pdo, int $adminId): array {
    if ($adminId <= 0) return [];
    try {
        $stmt = $pdo->prepare('SELECT module_key, action_key FROM admin_staff_permissions WHERE admin_id = ?');
        $stmt->execute([$adminId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string)$row['module_key']][] = (string)$row['action_key'];
        }
        return $out;
    } catch (Throwable $e) {
        error_log('admin_staff_permissions: ' . $e->getMessage());
        return [];
    }
}

/**
 * The central permission question.
 *
 * Order matters: the account is checked before the grant, and the module's own
 * grantability is checked before the grant is consulted. A grant row for a non-grantable
 * module is therefore inert, which is what stops a hand-inserted row from opening the
 * finance or staff areas.
 */
function admin_staff_can(PDO $pdo, int $adminId, string $moduleKey, string $actionKey): bool {
    if ($adminId <= 0) return false;
    if (!array_key_exists($actionKey, admin_staff_actions())) return false;

    $module = admin_staff_module($pdo, $moduleKey);
    if ($module === null) return false;              // unknown module
    if ((int)$module['grantable'] !== 1) return false; // never grantable, whatever the table says

    $grants = admin_staff_permissions($pdo, $adminId);
    return in_array($actionKey, $grants[$moduleKey] ?? [], true);
}

/**
 * The account behind the current session, or null.
 *
 * Read from the database on every call so a role change, a deactivation or a deleted
 * account takes effect immediately rather than at the next sign-in.
 */
function admin_staff_account(PDO $pdo, int $adminId): ?array {
    if ($adminId <= 0) return null;
    try {
        $stmt = $pdo->prepare('SELECT id, full_name, email, role, is_active FROM admins WHERE id = ?');
        $stmt->execute([$adminId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        error_log('admin_staff_account: ' . $e->getMessage());
        return null;
    }
}

/** True when the account exists, is active, and holds the `staff` role. */
function admin_is_active_staff(PDO $pdo, int $adminId): bool {
    $account = admin_staff_account($pdo, $adminId);
    return $account !== null
        && (string)$account['role'] === 'staff'
        && (int)$account['is_active'] === 1;
}

/**
 * The single check every mutating function in this file makes.
 *
 * Re-read from the database, not the session: an administrator demoted mid-session must not
 * be able to keep using this page.
 */
function admin_staff_actor_is_super_admin(PDO $pdo, int $actorId): bool {
    if ($actorId <= 0) return false;
    try {
        $stmt = $pdo->prepare('SELECT role, is_active FROM admins WHERE id = ?');
        $stmt->execute([$actorId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row
            && (string)$row['role'] === 'super_admin'
            && (int)$row['is_active'] === 1;
    } catch (Throwable $e) {
        error_log('admin_staff_actor_is_super_admin: ' . $e->getMessage());
        return false;
    }
}

/**
 * Authorize a console request.
 *
 * This is the single decision the guard calls. For `super_admin`, `editor` and `support`
 * it defers to the existing role map, so nothing about those roles changes. For `staff`
 * it applies the module/action rules above.
 *
 * `logout.php` is always permitted: an employee whose access has just been withdrawn must
 * still be able to end their session.
 *
 * @param array<string, mixed> $post
 */
function admin_authorize_route(PDO $pdo, string $route, string $method = 'GET', array $post = []): bool {
    $route = basename($route);
    if ($route === 'logout.php' || $route === 'login.php') return true;

    $adminId = (int)($_SESSION['admin_id'] ?? 0);
    $account = admin_staff_account($pdo, $adminId);
    if ($account === null) return false;

    // A deactivated account is refused before any role logic, so switching an employee off
    // cannot be worked around by them holding a powerful role.
    if ((int)$account['is_active'] !== 1) return false;

    $role = (string)$account['role'];

    if ($role !== 'staff') {
        return admin_role_allows($route, $role);
    }

    $moduleKey = admin_route_module($route);
    if ($moduleKey === null) return false; // unmapped console page: denied to staff

    // The dashboard is the staff member's landing page. It is shown to any active staff
    // account, and the page itself renders only the modules that account may open — so
    // "can see the dashboard" never widens into "can see the data behind it".
    if ($moduleKey === 'dashboard') {
        return $method === 'GET';
    }

    $actionKey = $method === 'GET' ? 'view' : admin_write_action($post);
    return admin_staff_can($pdo, $adminId, $moduleKey, $actionKey);
}

/** True when the signed-in staff account may open $route for reading. Used to build the nav. */
function admin_staff_can_view_route(PDO $pdo, string $route, int $adminId): bool {
    $moduleKey = admin_route_module($route);
    if ($moduleKey === null) return false;
    if ($moduleKey === 'dashboard') return true;
    return admin_staff_can($pdo, $adminId, $moduleKey, 'view');
}

/**
 * Replace an account's grants with exactly $grants.
 *
 * Runs in a transaction so a partial write cannot leave an account holding more than the
 * administrator intended. Modules that are not grantable are dropped rather than stored,
 * and unknown actions are dropped, so the table cannot accumulate inert rows that look
 * like access.
 *
 * @param array<string, list<string>> $grants module => actions
 * @return int number of grants stored
 */
function admin_staff_set_permissions(PDO $pdo, int $adminId, array $grants, int $grantedBy): int {
    if ($adminId <= 0) return 0;
    // The page that calls this is super_admin-only, but the check is repeated here so the
    // function cannot be reused from a context that forgot to guard it — and so an employee
    // cannot widen their own access even if a future caller passes their id as $grantedBy.
    if (!admin_staff_actor_is_super_admin($pdo, $grantedBy)) return 0;

    $validActions = array_keys(admin_staff_actions());
    $grantable = array_flip(admin_staff_grantable_modules($pdo));

    $rows = [];
    foreach ($grants as $moduleKey => $actions) {
        $moduleKey = (string)$moduleKey;
        if (!isset($grantable[$moduleKey])) continue;
        if (!is_array($actions)) continue;
        foreach ($actions as $actionKey) {
            $actionKey = (string)$actionKey;
            if (!in_array($actionKey, $validActions, true)) continue;
            $rows[$moduleKey . ':' . $actionKey] = [$moduleKey, $actionKey];
        }
    }

    $pdo->beginTransaction();
    try {
        $del = $pdo->prepare('DELETE FROM admin_staff_permissions WHERE admin_id = ?');
        $del->execute([$adminId]);
        if ($rows) {
            $ins = $pdo->prepare(
                'INSERT INTO admin_staff_permissions (admin_id, module_key, action_key, granted_by)
                 VALUES (?, ?, ?, ?)'
            );
            foreach ($rows as [$moduleKey, $actionKey]) {
                $ins->execute([$adminId, $moduleKey, $actionKey, $grantedBy ?: null]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('admin_staff_set_permissions: ' . $e->getMessage());
        return 0;
    }
    return count($rows);
}
