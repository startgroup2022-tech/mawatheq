<?php
/**
 * Affiliate sign-in (Execution 31).
 *
 * Separate from the client and lawyer logins because an affiliate is a separate
 * identity. Reuses the platform's rate limiter so the affiliate login cannot be used
 * as an unthrottled way into the same account namespace.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/../includes/affiliate.php';
require_once __DIR__ . '/../includes/affiliate_auth.php';
require_once __DIR__ . '/../includes/ui.php';

if (isset($_GET['lang']) && in_array($_GET['lang'], ['ar', 'en'], true)) {
    $_SESSION['lang'] = $_GET['lang'];
}

// Already signed in: go straight to the dashboard.
if (affiliate_current($pdo)) {
    header('Location: /affiliate/dashboard.php');
    exit;
}

$error = null;
$notice = null;
$email = '';

$statusParam = (string)($_GET['status'] ?? '');
if ($statusParam !== '') {
    $notice = match ($statusParam) {
        'suspended' => is_rtl() ? 'حسابك موقوف مؤقتاً. تواصل مع فريق البرنامج.' : 'Your account is suspended. Please contact the programme team.',
        'rejected' => is_rtl() ? 'لم يُعتمد طلبك. راجع بريدك لمعرفة السبب.' : 'Your application was not approved. Check your email for the reason.',
        'pending' => is_rtl() ? 'طلبك قيد المراجعة.' : 'Your application is still under review.',
        default => null,
    };
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) { http_response_code(403); exit('Invalid request'); }
    $email = is_string($_POST['email'] ?? null) ? strtolower(trim($_POST['email'])) : '';
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

    if ($email === '' || $password === '') {
        $error = is_rtl() ? 'يرجى إدخال البريد الإلكتروني وكلمة المرور.' : 'Please enter your email and password.';
    } else {
        $result = affiliate_login($pdo, $email, $password);
        if (!empty($result['ok'])) {
            header('Location: /affiliate/dashboard.php');
            exit;
        }
        $error = match ((string)($result['error'] ?? '')) {
            'rate_limited' => is_rtl() ? 'محاولات دخول كثيرة. حاول بعد 15 دقيقة.' : 'Too many sign-in attempts. Try again in 15 minutes.',
            'not_active' => is_rtl() ? 'حسابك غير نشط بعد.' : 'Your account is not active yet.',
            default => is_rtl() ? 'بيانات الدخول غير صحيحة.' : 'Invalid email or password.',
        };
    }
}

$lang = current_lang();
$dir = is_rtl() ? 'rtl' : 'ltr';
?>
<!DOCTYPE html>
<html lang="<?= ui_e($lang) ?>" dir="<?= $dir ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= ui_e(is_rtl() ? 'دخول المسوّقين' : 'Affiliate sign-in') ?> · <?= ui_e(SITE_NAME_AR) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@700;800&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="stylesheet" href="/assets/css/app.css">
<link rel="stylesheet" href="/assets/css/enterprise.css">
<link rel="stylesheet" href="/assets/css/affiliate.css">
</head>
<body class="aff-auth-body">
<div class="aff-auth">
  <div class="aff-auth-card">
    <a href="/" class="aff-auth-brand"><img src="/assets/img/logo.webp" alt=""><span><?= ui_e(SITE_NAME_AR) ?></span></a>
    <h1><?= ui_e(is_rtl() ? 'دخول المسوّقين' : 'Affiliate sign-in') ?></h1>
    <p class="aff-auth-sub"><?= ui_e(is_rtl() ? 'برنامج الشركاء' : 'Partner programme') ?></p>

    <?php if ($notice): ?><div class="form-alert"><?= ui_e($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="form-alert error"><?= ui_e($error) ?></div><?php endif; ?>

    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>">
      <label class="field"><span><?= ui_e(is_rtl() ? 'البريد الإلكتروني' : 'Email') ?></span>
        <input type="email" name="email" required autofocus autocomplete="username" value="<?= ui_e($email) ?>"></label>
      <label class="field"><span><?= ui_e(is_rtl() ? 'كلمة المرور' : 'Password') ?></span>
        <input type="password" name="password" required autocomplete="current-password"></label>
      <button class="btn btn-primary btn-block" type="submit"><?= ui_e(is_rtl() ? 'دخول' : 'Sign in') ?></button>
    </form>

    <div class="aff-auth-links">
      <a href="/affiliates.php#apply"><?= ui_e(is_rtl() ? 'تقدّم بطلب انضمام' : 'Apply to join') ?></a>
      <a href="/"><?= ui_e(is_rtl() ? 'الموقع الرئيسي' : 'Main site') ?></a>
    </div>
  </div>
</div>
</body>
</html>
