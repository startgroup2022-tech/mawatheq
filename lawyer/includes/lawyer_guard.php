<?php
if (empty($_SESSION['lawyer_id'])) {
    header('Location: /lawyer-login.php');
    exit;
}
if (!isset($pdo) || !($pdo instanceof PDO)) { http_response_code(503); exit('Service unavailable'); }
$lawyerCheck=$pdo->prepare('SELECT status,email_verified FROM lawyers WHERE id=?');
$lawyerCheck->execute([(int)$_SESSION['lawyer_id']]); $lawyerAccount=$lawyerCheck->fetch();
if (!$lawyerAccount || $lawyerAccount['status']!=='approved' || empty($lawyerAccount['email_verified'])) { $_SESSION=[]; session_destroy(); header('Location: /lawyer-login.php'); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? 'GET')==='POST') {
    require_once __DIR__ . '/../../includes/csrf.php';
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) { http_response_code(403); exit('Invalid request'); }
}
