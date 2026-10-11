<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lang/strings.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/auth_security.php';

$as = $_GET['as'] ?? ($_POST['as'] ?? 'client');
if (!in_array($as, ['client', 'lawyer', 'admin'], true)) $as = 'client';

$tables = [
    'client' => ['table' => 'users',   'login' => '/login.php',        'label' => is_rtl() ? 'العميل' : 'Client'],
    'lawyer' => ['table' => 'lawyers', 'login' => '/lawyer-login.php', 'label' => is_rtl() ? 'المحامي' : 'Lawyer'],
    'admin'  => ['table' => 'admins',  'login' => '/admin/login.php',  'label' => is_rtl() ? 'الإدارة' : 'Admin'],
];
$cfg = $tables[$as];
$sent = false;
$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !csrf_is_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(403); exit('Invalid request');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $emailInput = $_POST['email'] ?? '';
    $email = is_string($emailInput) ? strtolower(trim($emailInput)) : '';
    if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = is_rtl() ? 'يرجى إدخال بريد إلكتروني صحيح.' : 'Please enter a valid email.';
    } elseif (auth_rate_limit_remaining($pdo, 'password-reset-' . $as, $email) > 0) {
        $error = is_rtl() ? 'طلبات كثيرة. انتظر 15 دقيقة ثم حاول مجدداً.' : 'Too many requests. Please wait 15 minutes and try again.';
    } else {
        auth_rate_limit_failure($pdo, 'password-reset-' . $as, $email);
        $stmt = $pdo->prepare("SELECT id, full_name FROM {$cfg['table']} WHERE email = ?");
        $stmt->execute([$email]);
        $account = $stmt->fetch();

        if ($account) {
            $token = bin2hex(random_bytes(32));
            $pdo->prepare("UPDATE {$cfg['table']} SET reset_token = ?, reset_expires = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id = ?")
                ->execute([$token, $account['id']]);

            $resetUrl = rtrim(SITE_URL, '/') . '/reset-password.php?as=' . $as . '&token=' . $token;
            $bodyHtml = is_rtl()
                ? '<p>مرحباً ' . htmlspecialchars($account['full_name']) . '،</p><p>وصلنا طلب لإعادة تعيين كلمة مرور حسابك في منصة مواثيق. الرابط صالح لمدة ساعة واحدة فقط.</p><p>إن لم تطلب ذلك، تجاهل هذه الرسالة ولن يتغيّر شيء.</p>'
                : '<p>Hello ' . htmlspecialchars($account['full_name']) . ',</p><p>We received a request to reset your Mawatheq account password. This link is valid for 1 hour only.</p><p>If you did not request this, you can safely ignore this email.</p>';
            $html = render_mail_template(
                is_rtl() ? 'إعادة تعيين كلمة المرور' : 'Reset your password',
                $bodyHtml,
                is_rtl() ? 'إعادة تعيين كلمة المرور' : 'Reset password',
                $resetUrl
            );
            send_mail($email, is_rtl() ? 'إعادة تعيين كلمة المرور - مواثيق' : 'Reset your Mawatheq password', $html);
        }
        // نعرض نفس الرسالة سواء كان البريد موجوداً أم لا، لحماية خصوصية الحسابات
        $sent = true;
    }
}
?>
<!DOCTYPE html>
<html lang="<?= current_lang() ?>" dir="<?= is_rtl() ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="/assets/img/logo.webp">
<title><?= is_rtl() ? 'نسيت كلمة المرور' : 'Forgot password' ?> — <?= SITE_NAME_AR ?></title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@700;800&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div class="auth-wrap">
  <div class="auth-card">
    <a href="/" class="brand"><img src="/assets/img/logo.webp" alt="<?= htmlspecialchars(is_rtl() ? SITE_NAME_AR : SITE_NAME_EN) ?>" style="height:38px"></a>
    <h1 style="text-align:center;font-size:1.4rem;margin-top:10px"><?= is_rtl() ? 'نسيت كلمة المرور؟' : 'Forgot your password?' ?></h1>
    <p style="text-align:center;color:var(--text-muted);font-size:.88rem;margin-bottom:10px"><?= is_rtl() ? 'أدخل بريدك الإلكتروني (حساب ' . $cfg['label'] . ') وسنرسل لك رابط إعادة التعيين.' : 'Enter your ' . $cfg['label'] . ' account email and we will send you a reset link.' ?></p>

    <?php if ($error): ?><div class="form-alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if ($sent): ?>
      <div class="form-alert success"><?= is_rtl() ? 'إذا كان البريد الإلكتروني مسجّلاً لدينا، ستصلك رسالة تحتوي رابط إعادة التعيين خلال دقائق. تفقّد مجلد الرسائل غير المرغوبة (Spam) إن لم تصل.' : "If that email is registered with us, you'll receive a reset link shortly. Check your spam folder if it doesn't arrive." ?></div>
    <?php else: ?>
      <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="as" value="<?= htmlspecialchars($as) ?>">
        <div class="field"><label><?= is_rtl() ? 'البريد الإلكتروني' : 'Email' ?></label><input type="email" name="email" required autofocus></div>
        <button class="btn btn-primary btn-block" type="submit"><?= is_rtl() ? 'إرسال رابط إعادة التعيين' : 'Send reset link' ?></button>
      </form>
    <?php endif; ?>

    <div class="muted-link"><a href="<?= $cfg['login'] ?>">← <?= is_rtl() ? 'العودة لتسجيل الدخول' : 'Back to login' ?></a></div>
  </div>
</div>
</body>
</html>
