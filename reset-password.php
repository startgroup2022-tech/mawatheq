<?php
require_once __DIR__ . '/config/config.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !csrf_is_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(403); exit('Invalid request');
}
require_once __DIR__ . '/lang/strings.php';

$asInput = $_GET['as'] ?? ($_POST['as'] ?? 'client');
$as = is_string($asInput) ? $asInput : 'client';
if (!in_array($as, ['client', 'lawyer', 'admin'], true)) $as = 'client';

$tables = [
    'client' => ['table' => 'users',   'login' => '/login.php',        'forgot' => '/forgot-password.php?as=client'],
    'lawyer' => ['table' => 'lawyers', 'login' => '/lawyer-login.php', 'forgot' => '/forgot-password.php?as=lawyer'],
    'admin'  => ['table' => 'admins',  'login' => '/admin/login.php',  'forgot' => '/forgot-password.php?as=admin'],
];
$cfg = $tables[$as];
$tokenInput = $_GET['token'] ?? ($_POST['token'] ?? '');
$token = is_string($tokenInput) ? $tokenInput : '';
$error = null;
$done = false;

$account = null;
if ($token) {
    $stmt = $pdo->prepare("SELECT id FROM {$cfg['table']} WHERE reset_token = ? AND reset_expires > NOW()");
    $stmt->execute([$token]);
    $account = $stmt->fetch();
}

if (!$token || !$account) {
    $error = is_rtl() ? 'رابط إعادة التعيين غير صالح أو منتهي الصلاحية. اطلب رابطاً جديداً.' : 'This reset link is invalid or has expired. Please request a new one.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $passwordInput = $_POST['password'] ?? '';
    $confirmInput = $_POST['password_confirm'] ?? '';
    $password = is_string($passwordInput) ? $passwordInput : '';
    $confirm = is_string($confirmInput) ? $confirmInput : '';
    if (strlen($password) < 8) {
        $error = is_rtl() ? 'كلمة المرور يجب أن تكون 8 أحرف على الأقل.' : 'Password must be at least 8 characters.';
    } elseif (strlen($password) > 72) {
        $error = is_rtl() ? 'كلمة المرور يجب ألا تتجاوز 72 بايتاً.' : 'Password must not exceed 72 bytes.';
    } elseif ($password !== $confirm) {
        $error = is_rtl() ? 'كلمتا المرور غير متطابقتين.' : 'Passwords do not match.';
    } else {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $pdo->prepare("UPDATE {$cfg['table']} SET password_hash = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?")
            ->execute([$hash, $account['id']]);
        $done = true;
    }
}
?>
<!DOCTYPE html>
<html lang="<?= current_lang() ?>" dir="<?= is_rtl() ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="/assets/img/logo.webp">
<title><?= is_rtl() ? 'إعادة تعيين كلمة المرور' : 'Reset password' ?> — <?= SITE_NAME_AR ?></title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@700;800&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div class="auth-wrap">
  <div class="auth-card">
    <a href="/" class="brand"><img src="/assets/img/logo.webp" style="height:38px"></a>
    <h2 style="text-align:center;font-size:1.4rem;margin-top:10px"><?= is_rtl() ? 'إعادة تعيين كلمة المرور' : 'Reset your password' ?></h2>

    <?php if ($error): ?>
      <div class="form-alert error"><?= htmlspecialchars($error) ?></div>
      <?php if (!$account): ?><a href="<?= $cfg['forgot'] ?>" class="btn btn-outline btn-block"><?= is_rtl() ? 'طلب رابط جديد' : 'Request a new link' ?></a><?php endif; ?>
    <?php endif; ?>

    <?php if ($done): ?>
      <div class="form-alert success"><?= is_rtl() ? 'تم تغيير كلمة المرور بنجاح. يمكنك تسجيل الدخول الآن.' : 'Your password was changed successfully. You can now log in.' ?></div>
      <a href="<?= $cfg['login'] ?>" class="btn btn-primary btn-block"><?= is_rtl() ? 'تسجيل الدخول' : 'Log in' ?></a>
    <?php elseif ($account): ?>
      <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="as" value="<?= htmlspecialchars($as) ?>">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
        <div class="field"><label><?= is_rtl() ? 'كلمة المرور الجديدة' : 'New password' ?></label><div class="password-input"><input type="password" name="password" id="newPassword" minlength="8" autocomplete="new-password" required autofocus><button type="button" class="password-toggle" data-password-toggle="newPassword"><?= is_rtl() ? 'إظهار' : 'Show' ?></button></div></div>
        <div class="field"><label><?= is_rtl() ? 'تأكيد كلمة المرور' : 'Confirm password' ?></label><div class="password-input"><input type="password" name="password_confirm" id="newPasswordConfirm" minlength="8" autocomplete="new-password" required><button type="button" class="password-toggle" data-password-toggle="newPasswordConfirm"><?= is_rtl() ? 'إظهار' : 'Show' ?></button></div></div>
        <button class="btn btn-primary btn-block" type="submit"><?= is_rtl() ? 'تغيير كلمة المرور' : 'Change password' ?></button>
      </form>
    <?php endif; ?>
  </div>
</div>
<script>document.querySelectorAll('[data-password-toggle]').forEach(function(button){button.addEventListener('click',function(){var input=document.getElementById(button.dataset.passwordToggle),show=input.type==='password';input.type=show?'text':'password';button.textContent=show?'<?= is_rtl() ? 'إخفاء' : 'Hide' ?>':'<?= is_rtl() ? 'إظهار' : 'Show' ?>';});});</script>
</body>
</html>
