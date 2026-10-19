<?php
if (empty($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}

if (!isset($pdo) || !($pdo instanceof PDO)) { http_response_code(503); exit('Service unavailable'); }
$adminCheck=$pdo->prepare('SELECT role,full_name,totp_enabled,is_active FROM admins WHERE id=?');
$adminCheck->execute([(int)$_SESSION['admin_id']]); $adminAccount=$adminCheck->fetch();
if (!$adminAccount) { $_SESSION=[]; session_destroy(); header('Location: /admin/login.php'); exit; }
require_once __DIR__ . '/../../includes/auth_security.php';
if (account_session_is_stale($pdo, 'admin', (int)$_SESSION['admin_id'])) { $_SESSION=[]; session_destroy(); header('Location: /admin/login.php'); exit; }
$_SESSION['admin_role']=$adminAccount['role']; $_SESSION['admin_name']=$adminAccount['full_name'];
if (!empty($adminAccount['totp_enabled']) && empty($_SESSION['admin_2fa_verified'])) { $_SESSION=[]; session_destroy(); header('Location: /admin/login.php'); exit; }

// 2FA is opt-in: admins enable it from /admin/two-factor.php. When enabled, the step above
// requires a verified code before any admin route runs. Accounts that have not enabled it log
// in with a password alone — there is no forced enrollment redirect.

// Route-level RBAC is enforced server-side, before endpoint logic runs. The role map lives
// in includes/admin_rbac.php so root-level routes can apply the same rule — see
// download-document.php, which is not under /admin/ and cannot include this guard.
require_once __DIR__ . '/../../includes/admin_rbac.php';
require_once __DIR__ . '/../../includes/admin_permissions.php';

// A deactivated account is refused here, before any route logic. The permission library
// re-reads is_active from the database rather than trusting the session, so switching an
// employee off takes effect on their next request.
if ((int)($adminAccount['is_active'] ?? 1) !== 1) {
    $_SESSION = []; session_destroy();
    header('Location: /admin/login.php?status=disabled');
    exit;
}

$route = basename($_SERVER['SCRIPT_NAME'] ?? '');
$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');

// admin_authorize_route() defers to the role map for super_admin/editor/support, so those
// roles behave exactly as before, and applies module/action rules for the `staff` role.
// Deny-by-default: an unmapped route, an unknown module or an empty grant set all refuse.
if (!admin_authorize_route($pdo, $route, $method, $_POST)) {
    // Record the attempt. A staff member probing a URL they were not given is exactly the
    // event an operator needs to see, and it is invisible if only the 403 is returned.
    // log_action() never writes secrets, and the route name is not one.
    try {
        require_once __DIR__ . '/../../includes/audit_log.php';
        log_action($pdo, 'admin.access_denied', 'admin_route:' . $route, null);
    } catch (Throwable $e) {
        error_log('access_denied audit failed: ' . $e->getMessage());
    }
    http_response_code(403);
    exit('Forbidden');
}

if ($method === 'POST') {
    require_once __DIR__ . '/../../includes/csrf.php';
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) { http_response_code(403); exit('Invalid request'); }
}
