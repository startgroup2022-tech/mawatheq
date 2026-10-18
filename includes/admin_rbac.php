<?php
/**
 * Admin route authorization (RBAC), shared by the console guard and by root-level routes.
 *
 * The map used to live inside admin/includes/admin_guard.php as a local variable. That made
 * it unreachable from a route outside /admin/ — and download-document.php, which serves any
 * client's file to an admin session, never included the guard. The result was that an
 * `editor`, who is refused the documents console, could still download every client's
 * documents by id. Keeping the map here means any route can apply the same rule.
 *
 * A route absent from the map is restricted to super_admin.
 */

/**
 * Role allow-list per console script name.
 *
 * @return array<string, list<string>>
 */
function admin_route_roles(): array {
    return [
        'index.php' => ['super_admin', 'editor', 'support'],
        'users.php' => ['super_admin', 'support'],
        'client-detail.php' => ['super_admin', 'support'],
        'lawyers.php' => ['super_admin', 'support'],
        'firms.php' => ['super_admin', 'support'],
        'consultations.php' => ['super_admin', 'support'],
        'consultation-attachments.php' => ['super_admin', 'support'],
        'documents.php' => ['super_admin', 'support'],
        'leads.php' => ['super_admin', 'support'],
        'articles.php' => ['super_admin', 'editor'],
        'laws.php' => ['super_admin', 'editor'],
        'ai-knowledge.php' => ['super_admin', 'editor'],
        'ai-orchestration.php' => ['super_admin'],
        'ai-operations.php' => ['super_admin'],
        'ai-workflows.php' => ['super_admin'],
        'ai_test.php' => ['super_admin', 'editor'],
        'settings.php' => ['super_admin'],
        'plans.php' => ['super_admin'],
        'invoices.php' => ['super_admin'],
        'reports.php' => ['super_admin', 'editor'],
        'audit-log.php' => ['super_admin'],
        'two-factor.php' => ['super_admin', 'editor', 'support'],
        'integrations.php' => ['super_admin'],
        'payment-test.php' => ['super_admin'],
        'reindex-embeddings.php' => ['super_admin'],
        'gateways.php' => ['super_admin'],
        'gateway-transactions.php' => ['super_admin'],
        'gateway-transaction.php' => ['super_admin'],
        'sadad-test.php' => ['super_admin'],
        'crm.php' => ['super_admin', 'support'],
        'calendar.php' => ['super_admin', 'editor', 'support'],
        'notifications.php' => ['super_admin', 'editor', 'support'],
        'search.php' => ['super_admin', 'support'],
        // Affiliate programme. Commission and payout data is money, so the consoles
        // that can move it are super_admin only; reading the affiliate list is a
        // support task. A route absent from this map defaults to super_admin, so
        // omitting one of these would be safe but would break the intended role.
        'affiliates.php' => ['super_admin', 'support'],
        'affiliate-detail.php' => ['super_admin'],
        'affiliate-payouts.php' => ['super_admin'],
        'affiliate-payout.php' => ['super_admin'],
        'affiliate-commissions.php' => ['super_admin'],
        'affiliate-campaigns.php' => ['super_admin'],
        'affiliate-settings.php' => ['super_admin'],
        'affiliate-fraud.php' => ['super_admin'],
    ];
}

/** True when $role may open the console script $route. Unknown routes require super_admin. */
function admin_role_allows(string $route, ?string $role): bool {
    $route = basename($route);
    $roles = admin_route_roles()[$route] ?? ['super_admin'];
    return in_array((string)$role, $roles, true);
}

/**
 * The current admin's role, read from the database rather than the session.
 *
 * A session role can be stale: if an operator is demoted while signed in, the session keeps
 * the old value until the next guard run. Authorization decisions therefore re-read the row.
 * Returns null when the session is not an admin or the account no longer exists.
 */
function admin_current_role(PDO $pdo): ?string {
    $adminId = (int)($_SESSION['admin_id'] ?? 0);
    if ($adminId <= 0) return null;
    try {
        $stmt = $pdo->prepare('SELECT role FROM admins WHERE id=?');
        $stmt->execute([$adminId]);
        $role = $stmt->fetchColumn();
        return $role === false ? null : (string)$role;
    } catch (PDOException $e) {
        error_log('admin_current_role failed: ' . $e->getMessage());
        return null;
    }
}

/** True when the signed-in admin (per the database) may open $route. */
function admin_can_access(PDO $pdo, string $route): bool {
    return admin_role_allows($route, admin_current_role($pdo));
}
