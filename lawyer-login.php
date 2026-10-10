<?php
require_once __DIR__ . '/config/config.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !csrf_is_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(403); exit('Invalid request');
}
require_once __DIR__ . '/lang/strings.php';
require_once __DIR__ . '/includes/auth_security.php';
$error = null;
// Separate channel for states that are not credential failures (unverified email,
// pending approval). Showing these as a plain error made lawyers believe their
// password was wrong and that sign-in was broken.
$notice = null;
$showResend = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $emailInput = $_POST['email'] ?? '';
    $passwordInput = $_POST['password'] ?? '';
    $email = is_string($emailInput) ? strtolower(trim($emailInput)) : '';
    $password = is_string($passwordInput) ? $passwordInput : '';
    if (strlen($email) > 254 || strlen($password) > 4096 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = is_rtl() ? 'صيغة البريد الإلكتروني غير صحيحة.' : 'Invalid email format.';
    } elseif (auth_rate_limit_remaining($pdo, 'lawyer', $email) > 0) {
        $error = is_rtl() ? 'محاولات كثيرة. يرجى الانتظار 15 دقيقة قبل المحاولة مجدداً.' : 'Too many attempts. Please wait 15 minutes before trying again.';
    } else {
        $stmt = $pdo->prepare('SELECT * FROM lawyers WHERE email=?');
        $stmt->execute([$email]);
        $lawyer = $stmt->fetch();
        // Wrong credentials stay deliberately generic so the form cannot be used to
        // discover which lawyer emails exist. The specific reasons below are only
        // shown once the password has already been verified.
        if (!$lawyer || !password_verify($password, $lawyer['password_hash'])) {
            auth_rate_limit_failure($pdo, 'lawyer', $email);
            $error = is_rtl() ? 'البريد الإلكتروني أو كلمة المرور غير صحيحة.' : 'Incorrect email or password.';
        } elseif (empty($lawyer['email_verified'])) {
            $notice = is_rtl()
                ? 'لم يتم تأكيد بريدك الإلكتروني بعد. افتح رابط التأكيد الذي أرسلناه إلى بريدك، ثم حاول مرة أخرى.'
                : 'Your email is not confirmed yet. Open the confirmation link we sent you, then try again.';
            $showResend = true;
        } elseif ($lawyer['status'] === 'pending') {
            $notice = is_rtl()
                ? 'تم تأكيد بريدك، وحسابك الآن قيد المراجعة من إدارة مواثيق. ستتمكن من الدخول مباشرة بعد اعتماده.'
                : 'Your email is confirmed and your account is awaiting review by the Mawatheq team. You can sign in as soon as it is approved.';
        } elseif ($lawyer['status'] === 'suspended') {
            $notice = is_rtl()
                ? 'حسابك موقوف حالياً. يرجى التواصل مع دعم مواثيق لإعادة تفعيله.'
                : 'Your account is currently suspended. Please contact Mawatheq support to reactivate it.';
        } else {
            auth_rate_limit_clear($pdo, 'lawyer', $email);
            session_regenerate_id(true);
            $_SESSION['lawyer_id'] = $lawyer['id'];
            $_SESSION['lawyer_name'] = $lawyer['full_name'];
            require_once __DIR__ . '/includes/tenant.php';
            tenant_sync_session($pdo);
            header('Location: /lawyer/dashboard.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= current_lang() ?>" dir="<?= is_rtl() ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="/assets/img/logo.webp">
<title><?= is_rtl() ? 'دخول المحامين' : 'Lawyer Login' ?> — <?= SITE_NAME_AR ?></title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@700;800&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div class="auth-wrap">
  <div class="auth-card">
    <a href="/" class="brand"><img src="/assets/img/logo.webp" style="height:38px"></a>
    <h2 style="text-align:center;font-size:1.3rem;margin-top:10px"><?= is_rtl() ? 'دخول المحامين' : 'Lawyer Portal' ?></h2>
    <p class="auth-role-note"><?= is_rtl() ? 'هذه الصفحة لمكاتب المحاماة والمحامين المستقلين. للعملاء استخدم بوابة العملاء.' : 'This page is for law firms and independent lawyers. Clients should use the client portal.' ?></p>
    <?php if ($notice): ?><div class="form-alert notice"><?= htmlspecialchars($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="form-alert error"><?= $error === strip_tags($error) ? htmlspecialchars($error) : $error ?></div><?php endif; ?>
    <?php if ($showResend): ?><a class="btn btn-outline btn-block" href="/resend-verification.php?as=lawyer"><?= is_rtl() ? 'إعادة إرسال رابط التأكيد' : 'Resend confirmation link' ?></a><?php endif; ?>
    <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
      <div class="field"><label><?= is_rtl() ? 'البريد الإلكتروني' : 'Email' ?></label><input type="email" name="email" required></div>
      <div class="field"><label><?= is_rtl() ? 'كلمة المرور' : 'Password' ?></label><div class="password-input"><input type="password" name="password" id="lawyerLoginPassword" autocomplete="current-password" required><button type="button" class="password-toggle" data-password-toggle="lawyerLoginPassword"><?= is_rtl() ? 'إظهار' : 'Show' ?></button></div></div>
      <div class="auth-inline-link"><a href="/forgot-password.php?as=lawyer"><?= is_rtl() ? 'نسيت كلمة المرور؟' : 'Forgot password?' ?></a></div>
      <button class="btn btn-primary btn-block" type="submit"><?= is_rtl() ? 'دخول' : 'Login' ?></button>
    </form>
    <?php
    // Shown only when at least one firm has actually enabled SSO, so the link never leads to a
    // dead end. The email field is forwarded so the firm can be resolved by its domain.
    $ssoFirms = 0;
    try { $ssoFirms = (int)$pdo->query("SELECT COUNT(*) FROM sso_configs WHERE enabled=1")->fetchColumn(); } catch (Throwable $e) { $ssoFirms = 0; }
    if ($ssoFirms > 0):
    ?>
      <a id="ssoEntry" class="btn btn-outline btn-block" href="/sso/login.php"><?= is_rtl() ? 'الدخول عبر مزوّد هوية المؤسسة (SSO)' : 'Sign in with your organisation (SSO)' ?></a>
      <script>document.getElementById('ssoEntry').addEventListener('click',function(e){var f=document.querySelector('input[name=email]');if(f&&f.value){e.preventDefault();location.href='/sso/login.php?email='+encodeURIComponent(f.value);}});</script>
    <?php endif; ?>
    <div class="muted-link"><?= is_rtl() ? 'حساب المحامي يُنشأ من قِبل إدارة مواثيق. للانضمام كمحامٍ جديد،' : 'Lawyer accounts are created by the Mawatheq team. To join as a new lawyer,' ?> <a href="/join-lawyer.php" style="color:var(--teal);font-weight:700"><?= t('lawyers_cta') ?></a></div>
    <div class="auth-switch">
      <span><?= is_rtl() ? 'هل أنت عميل؟' : 'Are you a client?' ?></span>
      <a href="/login.php"><?= is_rtl() ? 'دخول العملاء' : 'Client sign in' ?></a>
    </div>
  </div>
</div>
<script>document.querySelectorAll('[data-password-toggle]').forEach(function(button){button.addEventListener('click',function(){var input=document.getElementById(button.dataset.passwordToggle),show=input.type==='password';input.type=show?'text':'password';button.textContent=show?'<?= is_rtl() ? 'إخفاء' : 'Hide' ?>':'<?= is_rtl() ? 'إظهار' : 'Show' ?>';});});</script>
</body>
</html>
