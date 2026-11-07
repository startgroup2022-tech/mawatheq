<?php
// يستخدم في كل صفحات لوحة العميل — يتأكد أنه مسجّل دخول
//
// A signed-out customer who opens a deep link (a package checkout, a booking) should be
// sent back to that page after signing in, not to the dashboard. The path is passed as a
// local `next` parameter and validated by login.php before it is used.
if (empty($_SESSION['user_id'])) {
    require_once __DIR__ . '/redirect.php';
    // The full request path (including its query string) so the checkout or booking the
    // customer opened is restored after sign-in. safe_local_path() refuses anything that
    // is not a single-slash local path, so this cannot become an open redirect.
    $next = safe_local_path($_SERVER['REQUEST_URI'] ?? '');
    header('Location: /login.php' . ($next !== '' ? '?next=' . rawurlencode($next) : ''));
    exit;
}
$accountCheck=$pdo->prepare('SELECT status,email_verified FROM users WHERE id=?');
$accountCheck->execute([(int)$_SESSION['user_id']]); $clientAccount=$accountCheck->fetch();
if (!$clientAccount || $clientAccount['status']!=='active' || empty($clientAccount['email_verified'])) { $_SESSION=[]; session_destroy(); header('Location: /login.php'); exit; }
// A password reset after this session began invalidates it, so a stolen session cookie
// cannot outlive the credential change.
require_once __DIR__ . '/auth_security.php';
if (account_session_is_stale($pdo, 'client', (int)$_SESSION['user_id'])) { $_SESSION=[]; session_destroy(); header('Location: /login.php'); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? 'GET')==='POST') {
    require_once __DIR__ . '/csrf.php';
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) { http_response_code(403); exit('Invalid request'); }
}
