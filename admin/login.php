<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/../includes/auth_security.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/totp.php';
require_once __DIR__ . '/../includes/audit_log.php';
$error = null;
$twoFactorPending = !empty($_SESSION['pending_admin_2fa_id']) && (time()-(int)($_SESSION['pending_admin_2fa_at'] ?? 0)) < 300;
if (!$twoFactorPending) { unset($_SESSION['pending_admin_2fa_id'],$_SESSION['pending_admin_2fa_at'],$_SESSION['pending_admin_2fa_email']); }

$completeAdminLogin = function(array $admin) use ($pdo): void {
    session_regenerate_id(true);
    unset($_SESSION['pending_admin_2fa_id'],$_SESSION['pending_admin_2fa_at'],$_SESSION['pending_admin_2fa_email']);
    $_SESSION['admin_id']=(int)$admin['id']; $_SESSION['admin_name']=$admin['full_name']; $_SESSION['admin_role']=$admin['role'] ?? 'editor';
    $_SESSION['admin_2fa_verified']=!empty($admin['totp_enabled']);
    // Restart the session clock on every successful sign-in. config.php sets this once and
    // carries it for the life of the session, and the guard treats a session that started
    // before the account's session_invalidated_at as stale. Without this line a session that
    // was revoked keeps its original start time through session_regenerate_id(), so the next
    // sign-in is judged stale as well and the account loops between login and the guard.
    $_SESSION['mawatheq_session_started_at']=time();
    // Record the sign-in so an operator can see when a staff account was last used. A
    // failure here must not block a legitimate login.
    try { $pdo->prepare('UPDATE admins SET last_login_at=NOW() WHERE id=?')->execute([(int)$admin['id']]); } catch (Throwable $e) { error_log('last_login_at update failed: '.$e->getMessage()); }
    try { log_action($pdo,'تسجيل دخول إداري ناجح'); } catch (Throwable $e) { error_log('Admin login audit failed: '.$e->getMessage()); }
    header('Location: /admin/index.php'); exit;
};

// A deactivated account must not be able to start a session at all. The console guard also
// refuses it, but answering here means the employee sees why instead of being bounced in a
// loop. Applies to both the password step and the 2FA step.
$adminIsDisabled = static function(?array $admin): bool {
    return $admin !== null && array_key_exists('is_active', $admin) && (int)$admin['is_active'] !== 1;
};
$disabledMessage = 'تم إيقاف هذا الحساب. تواصل مع مدير النظام.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) { http_response_code(403); exit('Invalid request'); }
    if ($twoFactorPending) {
        $id=(int)$_SESSION['pending_admin_2fa_id']; $email=(string)($_SESSION['pending_admin_2fa_email'] ?? '');
        $stmt=$pdo->prepare('SELECT * FROM admins WHERE id=? AND totp_enabled=1'); $stmt->execute([$id]); $admin=$stmt->fetch();
        if ($adminIsDisabled($admin)) { unset($_SESSION['pending_admin_2fa_id'],$_SESSION['pending_admin_2fa_at'],$_SESSION['pending_admin_2fa_email']); $twoFactorPending=false; $error=$disabledMessage; }
        elseif (!$admin || auth_rate_limit_remaining($pdo,'admin-2fa',$email)>0) $error='تعذر التحقق. ابدأ تسجيل الدخول من جديد بعد الانتظار.';
        else {
            $code=trim($_POST['totp_code'] ?? ''); $valid=totp_verify((string)$admin['totp_secret'],$code);
            if (!$valid && $code!=='' && !empty($admin['totp_recovery_hashes'])) {
                $hashes=json_decode($admin['totp_recovery_hashes'],true); $candidate=hash('sha256',strtoupper(str_replace('-','',$code)));
                if(is_array($hashes)) foreach($hashes as $i=>$hash) if(hash_equals((string)$hash,$candidate)){unset($hashes[$i]);$hashes=array_values($hashes);$pdo->prepare('UPDATE admins SET totp_recovery_hashes=? WHERE id=?')->execute([json_encode($hashes),$id]);$valid=true;break;}
            }
            if ($valid) { auth_rate_limit_clear($pdo,'admin-2fa',$email); $completeAdminLogin($admin); }
            else { auth_rate_limit_failure($pdo,'admin-2fa',$email); $error='رمز التحقق غير صحيح.'; }
        }
    } else {
        $email = strtolower(trim($_POST['email'] ?? '')); $password = $_POST['password'] ?? '';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $error='البريد الإلكتروني أو كلمة المرور غير صحيحة.';
        elseif (auth_rate_limit_remaining($pdo,'admin',$email)>0) $error='محاولات كثيرة. يرجى الانتظار 15 دقيقة قبل المحاولة مجدداً.';
        else {
            $stmt=$pdo->prepare('SELECT * FROM admins WHERE email=?'); $stmt->execute([$email]); $admin=$stmt->fetch();
            if ($adminIsDisabled($admin)) { $error=$disabledMessage; }
            elseif ($admin && password_verify($password,$admin['password_hash'])) {
                auth_rate_limit_clear($pdo,'admin',$email);
                if (!empty($admin['totp_enabled'])) {
                    $_SESSION['pending_admin_2fa_id']=(int)$admin['id']; $_SESSION['pending_admin_2fa_email']=$email; $_SESSION['pending_admin_2fa_at']=time(); $twoFactorPending=true;
                } else $completeAdminLogin($admin);
            } else { auth_rate_limit_failure($pdo,'admin',$email); $error='البريد الإلكتروني أو كلمة المرور غير صحيحة.'; }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="/assets/img/logo.webp">
<title>دخول لوحة تحكم مواثيق</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@700;800&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>">
</head>
<body>
<div class="auth-wrap">
  <div class="auth-card">
    <a href="/" class="brand"><img src="/assets/img/logo.webp" alt="<?= htmlspecialchars(is_rtl() ? SITE_NAME_AR : SITE_NAME_EN) ?>" style="height:38px"></a>
    <h1 style="text-align:center;font-size:1.3rem;margin-top:10px">دخول لوحة التحكم</h1>
    <?php if ($error): ?><div class="form-alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
      <?php if($twoFactorPending): ?><div class="field"><label>رمز تطبيق المصادقة (6 أرقام) أو رمز استرداد</label><input inputmode="numeric" name="totp_code" autocomplete="one-time-code" required autofocus></div><button class="btn btn-primary btn-block" type="submit">تحقق وتسجيل الدخول</button>
      <?php else: ?>
      <div class="field"><label for="email">البريد الإلكتروني</label><input id="email" type="email" name="email" autocomplete="username" required></div>
      <div class="field"><label for="adminLoginPassword">كلمة المرور</label><div class="password-input"><input type="password" name="password" id="adminLoginPassword" autocomplete="current-password" required><button type="button" class="password-toggle" data-password-toggle="adminLoginPassword">إظهار</button></div></div>
      <div class="auth-inline-link"><a href="/forgot-password.php?as=admin">نسيت كلمة المرور؟</a></div>
      <button class="btn btn-primary btn-block" type="submit">دخول</button>
      <?php endif; ?>
    </form>
  </div>
</div>
<script>document.querySelectorAll('[data-password-toggle]').forEach(function(button){button.addEventListener('click',function(){var input=document.getElementById(button.dataset.passwordToggle),show=input.type==='password';input.type=show?'text':'password';button.textContent=show?'إخفاء':'إظهار';});});</script>
</body>
</html>
