<?php
require_once __DIR__ . '/config/config.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !csrf_is_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(403); exit('Invalid request');
}
require_once __DIR__ . '/lang/strings.php';
require_once __DIR__ . '/includes/auth_security.php';
$error = null;
// Unverified email is not a credential failure; keep it on its own channel so the
// client is told exactly what to do instead of a generic "sign-in failed".
$notice = null;
$showResend = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $emailInput = $_POST['email'] ?? '';
    $passwordInput = $_POST['password'] ?? '';
    $email = is_string($emailInput) ? strtolower(trim($emailInput)) : '';
    $password = is_string($passwordInput) ? $passwordInput : '';
    if (strlen($email) > 254 || strlen($password) > 4096 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = is_rtl() ? 'صيغة البريد الإلكتروني غير صحيحة.' : 'Invalid email format.';
    } elseif (auth_rate_limit_remaining($pdo, 'client', $email) > 0) {
        $error = is_rtl() ? 'محاولات كثيرة. يرجى الانتظار 15 دقيقة قبل المحاولة مجدداً.' : 'Too many attempts. Please wait 15 minutes before trying again.';
    } else {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email=?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if ($user && password_verify($password, $user['password_hash']) && $user['status'] === 'active') {
            if (empty($user['email_verified'])) {
            $notice = is_rtl() ? 'لم يتم تأكيد بريدك الإلكتروني بعد. افتح رابط التأكيد الذي أرسلناه، ثم حاول تسجيل الدخول مرة أخرى.' : 'Your email is not confirmed yet. Open the confirmation link we sent you, then sign in again.';
            $showResend = true;
            } else {
                auth_rate_limit_clear($pdo, 'client', $email);
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['full_name'];
                require_once __DIR__ . '/includes/audit_log.php';
                log_action($pdo, 'تسجيل دخول العميل', 'client', (int)$user['id']);
                header('Location: /ai-assistant.php');
                exit;
            }
        } else {
            auth_rate_limit_failure($pdo, 'client', $email);
            $error = is_rtl() ? 'البريد الإلكتروني أو كلمة المرور غير صحيحة.' : 'Incorrect email or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= current_lang() ?>" dir="<?= is_rtl() ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="/assets/img/logo.webp">
<title><?= t('nav_login') ?> — <?= SITE_NAME_AR ?></title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@700;800&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>">
</head>
<body>
<div class="auth-wrap">
  <div class="auth-card">
    <a href="/" class="brand"><img src="/assets/img/logo.webp" alt="<?= htmlspecialchars(is_rtl() ? SITE_NAME_AR : SITE_NAME_EN) ?>" style="height:38px"></a>
    <h1 style="text-align:center;font-size:1.4rem;margin-top:10px"><?= t('nav_login') ?></h1>
    <p class="auth-role-note"><?= is_rtl() ? 'هذه بوابة العملاء لمتابعة طلباتك واستشاراتك. إن كنت محامياً استخدم بوابة المحامين.' : 'This is the client portal for your requests and consultations. If you are a lawyer, use the lawyer portal.' ?></p>
    <?php if ($notice): ?><div class="form-alert notice"><?= htmlspecialchars($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="form-alert error"><?= $error === strip_tags($error) ? htmlspecialchars($error) : $error ?></div><?php endif; ?>
    <?php if ($showResend): ?><a class="btn btn-outline btn-block" href="/resend-verification.php?as=client"><?= is_rtl() ? 'إعادة إرسال رابط التأكيد' : 'Resend confirmation link' ?></a><?php endif; ?>
    <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
      <div class="field"><label for="email"><?= is_rtl() ? 'البريد الإلكتروني' : 'Email' ?></label><input id="email" type="email" name="email" required></div>
      <div class="field"><label for="loginPassword"><?= is_rtl() ? 'كلمة المرور' : 'Password' ?></label><div class="password-input"><input type="password" name="password" id="loginPassword" autocomplete="current-password" required><button type="button" class="password-toggle" data-password-toggle="loginPassword"><?= is_rtl() ? 'إظهار' : 'Show' ?></button></div></div>
      <div class="auth-inline-link"><a href="/forgot-password.php?as=client"><?= is_rtl() ? 'نسيت كلمة المرور؟' : 'Forgot password?' ?></a></div>
      <button class="btn btn-primary btn-block" type="submit"><?= t('nav_login') ?></button>
    </form>
    <div class="muted-link"><?= is_rtl() ? 'ليس لديك حساب؟' : "Don't have an account?" ?> <a href="/register.php" style="color:var(--teal);font-weight:700"><?= t('nav_get_started') ?></a></div>
    <div class="auth-switch">
      <span><?= is_rtl() ? 'هل أنت محامٍ؟' : 'Are you a lawyer?' ?></span>
      <a href="/lawyer-login.php"><?= is_rtl() ? 'دخول المحامين' : 'Lawyer sign in' ?></a>
    </div>
  </div>
</div>
<script>document.querySelectorAll('[data-password-toggle]').forEach(function(button){button.addEventListener('click',function(){var input=document.getElementById(button.dataset.passwordToggle),show=input.type==='password';input.type=show?'text':'password';button.textContent=show?'<?= is_rtl() ? 'إخفاء' : 'Hide' ?>':'<?= is_rtl() ? 'إظهار' : 'Show' ?>';});});</script>
</body>
</html>
