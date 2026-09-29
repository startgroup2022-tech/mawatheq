<?php
if (empty($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}

if (!isset($pdo) || !($pdo instanceof PDO)) { http_response_code(503); exit('Service unavailable'); }
$adminCheck=$pdo->prepare('SELECT role,full_name,totp_enabled FROM admins WHERE id=?');
$adminCheck->execute([(int)$_SESSION['admin_id']]); $adminAccount=$adminCheck->fetch();
if (!$adminAccount) { $_SESSION=[]; session_destroy(); header('Location: /admin/login.php'); exit; }
$_SESSION['admin_role']=$adminAccount['role']; $_SESSION['admin_name']=$adminAccount['full_name'];
if (!empty($adminAccount['totp_enabled']) && empty($_SESSION['admin_2fa_verified'])) { $_SESSION=[]; session_destroy(); header('Location: /admin/login.php'); exit; }

// 2FA is opt-in: admins enable it from /admin/two-factor.php. When enabled, the step above
// requires a verified code before any admin route runs. Accounts that have not enabled it log
// in with a password alone — there is no forced enrollment redirect.

// Route-level RBAC is enforced server-side, before endpoint logic runs.
$route=basename($_SERVER['SCRIPT_NAME'] ?? ''); $role=$adminAccount['role'];
$routeRoles=[
    'index.php'=>['super_admin','editor','support'], 'users.php'=>['super_admin','support'],
    'client-detail.php'=>['super_admin','support'], 'lawyers.php'=>['super_admin','support'], 'firms.php'=>['super_admin','support'],
    'consultations.php'=>['super_admin','support'], 'documents.php'=>['super_admin','support'],
    'leads.php'=>['super_admin','support'], 'articles.php'=>['super_admin','editor'],
    'laws.php'=>['super_admin','editor'], 'ai-knowledge.php'=>['super_admin','editor'], 'ai-orchestration.php'=>['super_admin'], 'ai-workflows.php'=>['super_admin'],
    'ai_test.php'=>['super_admin','editor'], 'settings.php'=>['super_admin'],
    'plans.php'=>['super_admin'], 'invoices.php'=>['super_admin'], 'reports.php'=>['super_admin','editor'],
    'audit-log.php'=>['super_admin'], 'two-factor.php'=>['super_admin','editor','support'],
    'payment-test.php'=>['super_admin'], 'reindex-embeddings.php'=>['super_admin'],
    'gateways.php'=>['super_admin'], 'gateway-transactions.php'=>['super_admin'], 'gateway-transaction.php'=>['super_admin'],
    'sadad-test.php'=>['super_admin'],
    'crm.php'=>['super_admin','support'], 'calendar.php'=>['super_admin','editor','support'],
    'notifications.php'=>['super_admin','editor','support'], 'search.php'=>['super_admin','support'],
];
if (isset($routeRoles[$route]) && !in_array($role,$routeRoles[$route],true)) { http_response_code(403); exit('Forbidden'); }
if (!isset($routeRoles[$route]) && !in_array($role,['super_admin'],true)) { http_response_code(403); exit('Forbidden'); }
if (($_SERVER['REQUEST_METHOD'] ?? 'GET')==='POST') {
    require_once __DIR__ . '/../../includes/csrf.php';
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) { http_response_code(403); exit('Invalid request'); }
}
