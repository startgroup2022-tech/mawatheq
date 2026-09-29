<?php
require_once __DIR__ . '/../config/config.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { http_response_code(405); exit('Method not allowed'); }
require_once __DIR__ . '/../includes/audit_log.php';
if (!empty($_SESSION['admin_id'])) log_action($pdo,'logout','admin',(int)$_SESSION['admin_id']);
if (session_status() === PHP_SESSION_ACTIVE) { $_SESSION=[]; if (ini_get('session.use_cookies')) { $p=session_get_cookie_params(); setcookie(session_name(),'',time()-42000,$p['path'],$p['domain'],$p['secure'],$p['httponly']); } session_destroy(); }
header('Location: /admin/login.php');
exit;
