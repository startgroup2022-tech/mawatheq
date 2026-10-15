<?php
if (empty($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}

if (!isset($pdo) || !($pdo instanceof PDO)) { http_response_code(503); exit('Service unavailable'); }
$adminCheck=$pdo->prepare('SELECT role,full_name,totp_enabled FROM admins WHERE id=?');
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
$route=basename($_SERVER['SCRIPT_NAME'] ?? '');
$role=(string)$adminAccount['role'];
if (!admin_role_allows($route, $role)) { http_response_code(403); exit('Forbidden'); }
if (($_SERVER['REQUEST_METHOD'] ?? 'GET')==='POST') {
    require_once __DIR__ . '/../../includes/csrf.php';
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) { http_response_code(403); exit('Invalid request'); }
}
