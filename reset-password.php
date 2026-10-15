<?php
require_once __DIR__ . '/config/config.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !csrf_is_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(403); exit('Invalid request');
}
require_once __DIR__ . '/lang/strings.php';
require_once __DIR__ . '/includes/auth_security.php';

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
    // The stored value is the SHA-256 of the emailed token, so match on the hash.
    // Only well-formed tokens are looked up, keeping junk out of the query entirely.
    if (preg_match('/^[a-f0-9]{64}$/', $token)) {
        $stmt = $pdo->prepare("SELECT id FROM {$cfg['table']} WHERE reset_token = ? AND reset_expires > NOW()");
        $stmt->execute([password_reset_token_hash($token)]);
        $account = $stmt->fetch();
    }
}

// Consuming a token runs bcrypt, so an unauthenticated caller must not be able to drive
// it in a loop. The bucket is per-IP because a request carries no account identity, and
// the threshold is higher than login's so a user fumbling their own link is not locked out.
$resetRateKey = auth_rate_limit_ip_key('password-reset-consume');
$resetThrottled = auth_rate_limit_remaining_key($pdo, $resetRateKey) > 0;

if (!$token || !$account) {
    $error = is_rtl() ? 'رابط إعادة التعيين غير صالح أو منتهي الصلاحية. اطلب رابطاً جديداً.' : 'This reset link is invalid or has expired. Please request a new one.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $resetThrottled) {
    $error = is_rtl() ? 'محاولات كثيرة. انتظر 15 دقيقة ثم حاول مجدداً.' : 'Too many attempts. Please wait 15 minutes and try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $confirm = is_string($_POST['password_confirm'] ?? null) ? $_POST['password_confirm'] : '';
    $result = consume_password_reset($pdo, $as, $token, $password, $confirm);
    if ($result['ok']) {
        $done = true;
        auth_rate_limit_clear_key($pdo, $resetRateKey);
    } else {
        if ($result['error'] === 'invalid_token') {
            auth_rate_limit_failure_key($pdo, $resetRateKey, 10);
            $account = null;
        }
        $error = is_rtl()
            ? ['weak_password' => 'كلمة المرور يجب أن تكون 8 أحرف على الأقل.', 'too_long' => 'كلمة المرور يجب ألا تتجاوز 72 بايتاً.', 'mismatch' => 'كلمتا المرور غير متطابقتين.'][$result['error']] ?? 'رابط إعادة التعيين غير صالح.'
            : ['weak_password' => 'Password must be at least 8 characters.', 'too_long' => 'Password must not exceed 72 bytes.', 'mismatch' => 'Passwords do not match.'][$result['error']] ?? 'This reset link is invalid.';
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
    <a href="/" class="brand"><img src="/assets/img/logo.webp" alt="<?= htmlspecialchars(is_rtl() ? SITE_NAME_AR : SITE_NAME_EN) ?>" style="height:38px"></a>
    <h1 style="text-align:center;font-size:1.4rem;margin-top:10px"><?= is_rtl() ? 'إعادة تعيين كلمة المرور' : 'Reset your password' ?></h1>

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
