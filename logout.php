<?php
require_once __DIR__ . '/config/config.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { http_response_code(405); exit('Method not allowed'); }
if (!empty($_SESSION['user_id'])) {
    require_once __DIR__ . '/includes/audit_log.php';
    log_action($pdo, 'تسجيل خروج العميل', 'client', (int)$_SESSION['user_id']);
}
if (session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) { $p=session_get_cookie_params(); setcookie(session_name(),'',time()-42000,$p['path'],$p['domain'],$p['secure'],$p['httponly']); }
    session_destroy();
}
header('Location: /');
exit;
