<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lang/strings.php';
$verified = false;
$token = trim($_GET['token'] ?? '');
if ($token !== '' && preg_match('/^[a-f0-9]{64}$/', $token)) {
    $hash = hash('sha256', $token);
    foreach (['users', 'lawyers'] as $table) {
        $stmt = $pdo->prepare("SELECT id FROM {$table} WHERE email_verify_token_hash=? AND email_verify_expires>NOW() LIMIT 1");
        $stmt->execute([$hash]);
        $account = $stmt->fetch();
        if ($account) {
            $pdo->prepare("UPDATE {$table} SET email_verified=1,email_verify_token_hash=NULL,email_verify_expires=NULL WHERE id=?")
                ->execute([$account['id']]);
            $verified = true;
            break;
        }
    }
}
?>
<!DOCTYPE html><html lang="<?= current_lang() ?>" dir="<?= is_rtl() ? 'rtl' : 'ltr' ?>"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="/assets/css/style.css">
<title><?= is_rtl() ? 'تأكيد البريد الإلكتروني' : 'Email verification' ?> — <?= SITE_NAME_AR ?></title></head><body>
<div class="auth-wrap"><div class="auth-card"><a href="/" class="brand"><img src="/assets/img/logo.webp" alt="<?= htmlspecialchars(is_rtl() ? SITE_NAME_AR : SITE_NAME_EN) ?>" style="height:38px"></a>
<h1><?= is_rtl() ? 'تأكيد البريد الإلكتروني' : 'Email verification' ?></h1>
<?php if ($verified): ?><div class="form-alert success"><?= is_rtl() ? 'تم تأكيد بريدك الإلكتروني. يمكنك الآن تسجيل الدخول.' : 'Your email is verified. You can now sign in.' ?></div>
<a class="btn btn-primary btn-block" href="/login.php"><?= is_rtl() ? 'دخول العملاء' : 'Client sign in' ?></a><a class="btn btn-outline btn-block" href="/lawyer-login.php"><?= is_rtl() ? 'دخول المحامين' : 'Lawyer sign in' ?></a>
<?php else: ?><div class="form-alert error"><?= is_rtl() ? 'رابط التفعيل غير صالح أو انتهت صلاحيته.' : 'This verification link is invalid or has expired.' ?></div><a class="btn btn-primary btn-block" href="/resend-verification.php"><?= is_rtl() ? 'إرسال رابط جديد' : 'Send a new link' ?></a><?php endif; ?>
</div></div></body></html>
