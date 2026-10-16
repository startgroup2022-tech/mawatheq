<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lang/strings.php';
require_once __DIR__ . '/includes/auth_security.php';
$asInput = $_GET['as'] ?? $_POST['as'] ?? 'client';
$as = is_string($asInput) ? $asInput : 'client';
if (!in_array($as, ['client','lawyer'], true)) $as = 'client';
$sent = false;
$error = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !csrf_is_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(403); exit('Invalid request');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $emailInput = $_POST['email'] ?? '';
    $email = is_string($emailInput) ? strtolower(trim($emailInput)) : '';
    if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = is_rtl() ? 'أدخل بريداً إلكترونياً صحيحاً.' : 'Enter a valid email address.';
    } elseif (auth_rate_limit_remaining($pdo, 'resend-' . $as, $email) > 0) {
        $error = is_rtl() ? 'تم طلب رسائل كثيرة. حاول بعد 15 دقيقة.' : 'Too many requests. Please try again in 15 minutes.';
    } else {
        auth_rate_limit_failure($pdo, 'resend-' . $as, $email);
        $table = $as === 'lawyer' ? 'lawyers' : 'users';
        $stmt = $pdo->prepare("SELECT id,full_name,email,email_verified FROM {$table} WHERE email=? LIMIT 1");
        $stmt->execute([$email]);
        $account = $stmt->fetch();
        if ($account && !(int)$account['email_verified']) {
            create_email_verification($pdo, $as, (int)$account['id'], $account['email'], $account['full_name']);
        }
        // Same response whether the account exists, is verified, or mail delivery fails.
        $sent = true;
    }
}
?>
<!DOCTYPE html><html lang="<?= current_lang() ?>" dir="<?= is_rtl() ? 'rtl' : 'ltr' ?>"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="/assets/css/style.css">
<title><?= is_rtl() ? 'إعادة إرسال التفعيل' : 'Resend verification' ?> — <?= SITE_NAME_AR ?></title></head><body>
<div class="auth-wrap"><div class="auth-card"><a href="/" class="brand"><img src="/assets/img/logo.webp" alt="<?= htmlspecialchars(is_rtl() ? SITE_NAME_AR : SITE_NAME_EN) ?>" style="height:38px"></a>
<h1><?= is_rtl() ? 'إعادة إرسال رابط التفعيل' : 'Resend verification link' ?></h1>
<?php if ($error): ?><div class="form-alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($sent): ?><div class="form-alert success"><?= is_rtl() ? 'إذا كان البريد مسجلاً وغير مفعّل، ستصلك رسالة التفعيل. تحقق أيضاً من مجلد الرسائل غير المرغوبة.' : 'If the address is registered and unverified, a verification email will arrive. Check your spam folder too.' ?></div><?php else: ?>
<form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="as" value="<?= htmlspecialchars($as) ?>"><div class="field"><label><?= is_rtl() ? 'نوع الحساب' : 'Account type' ?></label><select name="as"><option value="client" <?= $as==='client'?'selected':'' ?>><?= is_rtl() ? 'عميل' : 'Client' ?></option><option value="lawyer" <?= $as==='lawyer'?'selected':'' ?>><?= is_rtl() ? 'محامٍ' : 'Lawyer' ?></option></select></div><div class="field"><label><?= is_rtl() ? 'البريد الإلكتروني' : 'Email' ?></label><input type="email" name="email" autocomplete="email" required></div><button class="btn btn-primary btn-block" type="submit"><?= is_rtl() ? 'إرسال' : 'Send' ?></button></form><?php endif; ?>
</div></div></body></html>
