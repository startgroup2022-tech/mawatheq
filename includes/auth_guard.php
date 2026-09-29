<?php
// يستخدم في كل صفحات لوحة العميل — يتأكد أنه مسجّل دخول
if (empty($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}
$accountCheck=$pdo->prepare('SELECT status,email_verified FROM users WHERE id=?');
$accountCheck->execute([(int)$_SESSION['user_id']]); $clientAccount=$accountCheck->fetch();
if (!$clientAccount || $clientAccount['status']!=='active' || empty($clientAccount['email_verified'])) { $_SESSION=[]; session_destroy(); header('Location: /login.php'); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? 'GET')==='POST') {
    require_once __DIR__ . '/csrf.php';
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) { http_response_code(403); exit('Invalid request'); }
}
