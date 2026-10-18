<?php
require_once __DIR__ . '/config/config.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !csrf_is_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(403); exit('Invalid request');
}
require_once __DIR__ . '/lang/strings.php';
require_once __DIR__ . '/includes/auth_security.php';
$error = null;

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
            $error = is_rtl() ? 'تعذر تسجيل الدخول. تحقق من بياناتك وحالة تفعيل الحساب، أو استخدم استعادة التفعيل إذا كان الحساب لك.' : 'Unable to sign in. Check your credentials and account activation, or use the activation recovery option if this is your account.';
            } else {
                auth_rate_limit_clear($pdo, 'client', $email);
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['full_name'];
                header('Location: /ai-assistant.php');
                exit;
            }
        } else {
            auth_rate_limit_failure($pdo, 'client', $email);
            $error = is_rtl() ? 'تعذر تسجيل الدخول. تحقق من بياناتك وحالة تفعيل الحساب، أو استخدم استعادة التفعيل إذا كان الحساب لك.' : 'Unable to sign in. Check your credentials and account activation, or use the activation recovery option if this is your account.';
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
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div class="auth-wrap">
  <div class="auth-card">
    <a href="/" class="brand"><img src="/assets/img/logo.webp" style="height:38px"></a>
    <h2 style="text-align:center;font-size:1.4rem;margin-top:10px"><?= t('nav_login') ?></h2>
    <?php if ($error): ?><div class="form-alert error"><?= $error === strip_tags($error) ? htmlspecialchars($error) : $error ?></div><?php endif; ?>
    <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
      <div class="field"><label><?= is_rtl() ? 'البريد الإلكتروني' : 'Email' ?></label><input type="email" name="email" required></div>
      <div class="field"><label><?= is_rtl() ? 'كلمة المرور' : 'Password' ?></label><div class="password-input"><input type="password" name="password" id="loginPassword" autocomplete="current-password" required><button type="button" class="password-toggle" data-password-toggle="loginPassword"><?= is_rtl() ? 'إظهار' : 'Show' ?></button></div></div>
      <div style="text-align:end;margin:-10px 0 16px"><a href="/forgot-password.php?as=client" style="font-size:.82rem;color:var(--teal)"><?= is_rtl() ? 'نسيت كلمة المرور؟' : 'Forgot password?' ?></a></div>
      <button class="btn btn-primary btn-block" type="submit"><?= t('nav_login') ?></button>
    </form>
    <div class="muted-link"><?= is_rtl() ? 'ليس لديك حساب؟' : "Don't have an account?" ?> <a href="/register.php" style="color:var(--teal);font-weight:700"><?= t('nav_get_started') ?></a></div>
  </div>
</div>
<script>document.querySelectorAll('[data-password-toggle]').forEach(function(button){button.addEventListener('click',function(){var input=document.getElementById(button.dataset.passwordToggle),show=input.type==='password';input.type=show?'text':'password';button.textContent=show?'<?= is_rtl() ? 'إخفاء' : 'Hide' ?>':'<?= is_rtl() ? 'إظهار' : 'Show' ?>';});});</script>
</body>
</html>
