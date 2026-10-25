<?php
require_once __DIR__ . '/config/config.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !csrf_is_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(403); exit('Invalid request');
}
require_once __DIR__ . '/lang/strings.php';
require_once __DIR__ . '/includes/phone.php';
require_once __DIR__ . '/includes/specialization_field.php';
require_once __DIR__ . '/includes/plan_limits.php';
require_once __DIR__ . '/includes/auth_security.php';
require_once __DIR__ . '/includes/mailer.php';

$error = null;
$lawyerSuccess = false;
$accountCreated = false;
$postedWho = $_POST['who'] ?? null;
$queryWho = $_GET['as'] ?? null;
$who = is_string($postedWho) ? $postedWho : (is_string($queryWho) ? $queryWho : 'client');
if (!in_array($who, ['client','lawyer'])) $who = 'client';

// Reject non-scalar POST values instead of passing attacker-controlled arrays
// into trim(), htmlspecialchars(), or the typed phone validator.
$postString = static function (string $key, string $default = ''): string {
    $value = $_POST[$key] ?? $default;
    return is_string($value) ? $value : $default;
};

// قيم يُعاد تعبئتها في النموذج عند وجود خطأ
$old = [
    'full_name' => trim($postString('full_name')),
    'email' => strtolower(trim($postString('email'))),
    'phone_dial' => $postString('phone_dial', '+966'),
    'phone_local' => $postString('phone_local'),
    'account_type' => $postString('account_type', 'individual'),
    'specialization' => $postString('specialization'),
];

// تسجيل = إنشاء الحساب الأساسي فقط. باقي بيانات المحامي (الخبرة، الترخيص،
// الصورة، المؤهلات...) تُستكمل لاحقاً من داخل حسابه بعد الدخول (نسبة الاكتمال).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $old['full_name'];
    $email = $old['email'];
    $password = $postString('password');
    $passwordConfirm = $postString('password_confirm');

    $phoneResult = validate_phone($old['phone_dial'], $old['phone_local']);

    if (!$name || !$email || strlen($password) < 8) {
        $error = is_rtl() ? 'يرجى تعبئة جميع الحقول (كلمة المرور 8 أحرف على الأقل).' : 'Please fill all fields (password min 8 characters).';
    } elseif (strlen($name) > 180 || strlen($email) > 254) {
        $error = is_rtl() ? 'الاسم أو البريد الإلكتروني أطول من المسموح.' : 'Name or email is too long.';
    } elseif (strlen($password) > 72) {
        $error = is_rtl() ? 'كلمة المرور يجب ألا تتجاوز 72 بايتاً.' : 'Password must not exceed 72 bytes.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = is_rtl() ? 'صيغة البريد الإلكتروني غير صحيحة.' : 'Invalid email format.';
    } elseif ($who === 'client' && !in_array($old['account_type'], ['individual', 'company'], true)) {
        $error = is_rtl() ? 'نوع الحساب غير صالح.' : 'Invalid account type.';
    } elseif ($password !== $passwordConfirm) {
        $error = is_rtl() ? 'كلمتا المرور غير متطابقتين.' : 'Passwords do not match.';
    } elseif (!$phoneResult['ok']) {
        $error = $phoneResult['error'];
    } elseif ($who === 'lawyer' && !in_array($old['specialization'], specialization_options(), true)) {
        $error = is_rtl() ? 'يرجى اختيار تخصص قانوني من القائمة.' : 'Please choose a legal specialization from the list.';
    } elseif (auth_rate_limit_remaining($pdo, 'register-' . $who, $email) > 0) {
        $error = is_rtl() ? 'طلبات تسجيل كثيرة من هذا الاتصال. حاول بعد 15 دقيقة.' : 'Too many sign-up requests from this connection. Try again in 15 minutes.';
    } else {
        $stmt = $pdo->prepare('SELECT email FROM users WHERE email=? UNION SELECT email FROM lawyers WHERE email=? LIMIT 1');
        $stmt->execute([$email, $email]);
        if ($stmt->fetch()) {
            $error = is_rtl() ? 'هذا البريد الإلكتروني مسجّل مسبقاً.' : 'This email is already registered.';
        } elseif ($who === 'client') {
            $accountType = $old['account_type'];
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $src = get_lead_source();
            $planId = default_plan_id($pdo);
            $stmt = $pdo->prepare('INSERT INTO users (full_name,email,phone,password_hash,account_type,preferred_lang,plan_id,email_verified,utm_source,utm_medium,utm_campaign,source_page) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([
                $name, $email, $phoneResult['value'], $hash, $accountType, current_lang(), $planId, 0,
                $src['utm_source'], $src['utm_medium'], $src['utm_campaign'], $src['source_page'],
            ]);
            $accountCreated = true;
            $emailSent = create_email_verification($pdo, 'client', (int)$pdo->lastInsertId(), $email, $name);
            auth_rate_limit_failure($pdo, 'register-' . $who, $email);
            $lawyerSuccess = $emailSent;
            if (!$emailSent) $error = is_rtl() ? 'تم إنشاء الحساب لكن تعذّر إرسال رسالة التفعيل. استخدم صفحة إعادة إرسال رسالة التفعيل.' : 'Account created, but the verification email could not be sent. Use the resend verification page.';
        } else {
            // lawyer self-registration -> pending admin approval, with email verification
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $src = get_lead_source();
            $stmt = $pdo->prepare('INSERT INTO lawyers (full_name,email,phone,password_hash,specialization,status,email_verified,utm_source,utm_medium,utm_campaign,source_page) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([
                $name, $email, $phoneResult['value'], $hash, $old['specialization'], 'pending', 0,
                $src['utm_source'], $src['utm_medium'], $src['utm_campaign'], $src['source_page'],
            ]);
            $accountCreated = true;
            $emailSent = create_email_verification($pdo, 'lawyer', (int)$pdo->lastInsertId(), $email, $name);
            auth_rate_limit_failure($pdo, 'register-' . $who, $email);
            $lawyerSuccess = $emailSent;
            if (!$emailSent) $error = is_rtl() ? 'تم إنشاء طلبك لكن تعذّر إرسال رسالة التفعيل. استخدم صفحة إعادة إرسال رسالة التفعيل.' : 'Application created, but the verification email could not be sent. Use the resend verification page.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= current_lang() ?>" dir="<?= is_rtl() ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="/assets/img/logo.webp">
<title><?= t('nav_get_started') ?> — <?= SITE_NAME_AR ?></title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@700;800&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>">
</head>
<body>
<div class="auth-wrap">
  <div class="auth-card">
    <a href="/" class="brand"><img src="/assets/img/logo.webp" alt="<?= htmlspecialchars(is_rtl() ? SITE_NAME_AR : SITE_NAME_EN) ?>" style="height:38px"></a>
    <h1 style="text-align:center;font-size:1.4rem;margin-top:10px"><?= is_rtl() ? 'إنشاء حساب جديد' : 'Create your account' ?></h1>

    <div class="type-tabs">
      <a href="?as=client" class="<?= $who === 'client' ? 'active' : '' ?>"><?= is_rtl() ? '👤 أنا عميل' : "👤 I'm a client" ?></a>
      <a href="?as=lawyer" class="<?= $who === 'lawyer' ? 'active' : '' ?>"><?= is_rtl() ? '⚖️ أنا محامٍ' : "⚖️ I'm a lawyer" ?></a>
    </div>
    <p class="auth-role-note">
      <?= $who === 'lawyer'
        ? (is_rtl() ? 'تسجيل المحامين: أنشئ حسابك كمحامٍ، وأكّد بريدك، ثم ينتظر الحساب اعتماد الإدارة قبل الدخول من بوابة المحامين.' : 'Lawyer signup: create your lawyer account, confirm your email, then the account awaits admin approval before you can sign in from the lawyer portal.')
        : (is_rtl() ? 'تسجيل العملاء: أنشئ حسابك كعميل أو شركة، وأكّد بريدك، ثم ادخل مباشرة من بوابة العملاء.' : 'Client signup: create your client or company account, confirm your email, then sign in directly from the client portal.') ?>
    </p>

    <?php if ($error): ?><div class="form-alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($accountCreated && !$lawyerSuccess): ?><a class="btn btn-outline btn-block" href="/resend-verification.php?as=<?= urlencode($who) ?>"><?= is_rtl()?'إعادة إرسال رابط التفعيل':'Resend verification link' ?></a><?php endif; ?>

    <?php if ($lawyerSuccess): ?>
      <div class="form-alert success">
        <?= $who === 'lawyer'
          ? (is_rtl() ? 'أرسلنا رابط تأكيد إلى بريدك الإلكتروني. أكّده أولاً، ثم ينتظر حساب المحامي مراجعة الإدارة واعتماده.' : 'We sent an email confirmation link. Confirm your email first; the lawyer account will then await admin approval.')
          : (is_rtl() ? 'أرسلنا رابط تأكيد إلى بريدك الإلكتروني. افتحه لتفعيل حسابك ثم سجّل الدخول.' : 'We sent an email confirmation link. Open it to activate your account, then sign in.') ?>
      </div>
      <a href="/lawyer-login.php" class="btn btn-outline btn-block"><?= is_rtl() ? 'الذهاب لصفحة دخول المحامين' : 'Go to lawyer login' ?></a>
    <?php else: ?>

    <form method="post" id="registerForm"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
      <input type="hidden" name="who" value="<?= $who ?>">
      <?php if ($who === 'client'): ?>
        <div class="field">
          <label for="account_type"><?= is_rtl() ? 'نوع الحساب' : 'Account type' ?></label>
          <select name="account_type" id="account_type">
            <option value="individual" <?= $old['account_type']==='individual'?'selected':'' ?>><?= is_rtl() ? 'فرد' : 'Individual' ?></option>
            <option value="company" <?= $old['account_type']==='company'?'selected':'' ?>><?= is_rtl() ? 'شركة' : 'Company' ?></option>
          </select>
        </div>
      <?php endif; ?>
      <div class="field"><label for="full_name"><?= is_rtl() ? 'الاسم الكامل' : 'Full name' ?></label><input id="full_name" type="text" name="full_name" value="<?= htmlspecialchars($old['full_name']) ?>" required></div>
      <div class="field"><label for="email"><?= is_rtl() ? 'البريد الإلكتروني' : 'Email' ?></label><input id="email" type="email" name="email" value="<?= htmlspecialchars($old['email']) ?>" required></div>
      <div class="field">
        <label><?= is_rtl() ? 'رقم الجوال' : 'Phone' ?></label>
        <?php render_phone_field('phone', $old['phone_dial'], $old['phone_local']); ?>
        <p class="field-hint"><?= is_rtl() ? 'اختر رمز الدولة ثم أدخل الرقم بدون الصفر الأول.' : 'Choose the country code, then enter the number without the leading zero.' ?></p>
      </div>
      <?php if ($who === 'lawyer'): ?>
        <div class="field">
          <label><?= is_rtl() ? 'التخصص القانوني' : 'Legal specialization' ?></label>
          <?php render_specialization_field('specialization', $old['specialization']); ?>
          <p class="field-hint"><?= is_rtl() ? 'يمكنك إضافة تخصصات وخبرات إضافية لاحقاً من ملفك الشخصي بعد الدخول.' : 'You can add more specializations and experience later from your profile.' ?></p>
        </div>
      <?php endif; ?>
      <div class="field"><label for="registerPassword"><?= is_rtl() ? 'كلمة المرور' : 'Password' ?></label><div class="password-input"><input type="password" name="password" id="registerPassword" minlength="8" autocomplete="new-password" required><button type="button" class="password-toggle" data-password-toggle="registerPassword" aria-label="<?= is_rtl() ? 'إظهار كلمة المرور' : 'Show password' ?>"><?= is_rtl() ? 'إظهار' : 'Show' ?></button></div></div>
      <div class="field"><label for="registerPasswordConfirm"><?= is_rtl() ? 'تأكيد كلمة المرور' : 'Confirm password' ?></label><div class="password-input"><input type="password" name="password_confirm" id="registerPasswordConfirm" minlength="8" autocomplete="new-password" required><button type="button" class="password-toggle" data-password-toggle="registerPasswordConfirm" aria-label="<?= is_rtl() ? 'إظهار كلمة المرور' : 'Show password' ?>"><?= is_rtl() ? 'إظهار' : 'Show' ?></button></div></div>
      <button class="btn btn-primary btn-block" type="submit">
        <?= $who === 'lawyer' ? (is_rtl() ? 'إرسال طلب انضمام كمحامٍ' : 'Submit lawyer application') : t('nav_get_started') ?>
      </button>
      <?php if ($who === 'lawyer'): ?>
        <p style="font-size:.78rem;color:var(--text-muted);text-align:center;margin-top:10px"><?= is_rtl() ? 'سيتم تفعيل حسابك بعد مراجعة الإدارة.' : 'Your account will be activated after admin review.' ?></p>
      <?php endif; ?>
    </form>
    <?php endif; ?>

    <div class="muted-link">
      <?= is_rtl() ? 'لديك حساب؟' : 'Already have an account?' ?>
      <a href="<?= $who === 'lawyer' ? '/lawyer-login.php' : '/login.php' ?>" style="color:var(--teal);font-weight:700"><?= t('nav_login') ?></a>
    </div>
  </div>
</div>
<script>
document.querySelectorAll('[data-password-toggle]').forEach(function(button){
  button.addEventListener('click', function(){
    var input=document.getElementById(button.dataset.passwordToggle);
    var show=input.type==='password'; input.type=show?'text':'password';
    button.textContent=show?'<?= is_rtl() ? 'إخفاء' : 'Hide' ?>':'<?= is_rtl() ? 'إظهار' : 'Show' ?>';
    button.setAttribute('aria-label', show?'<?= is_rtl() ? 'إخفاء كلمة المرور' : 'Hide password' ?>':'<?= is_rtl() ? 'إظهار كلمة المرور' : 'Show password' ?>');
  });
});
document.getElementById('registerForm').addEventListener('submit',function(e){
  var p=document.getElementById('registerPassword'), c=document.getElementById('registerPasswordConfirm');
  if(p.value!==c.value){e.preventDefault();c.setCustomValidity('<?= is_rtl() ? 'كلمتا المرور غير متطابقتين' : 'Passwords do not match' ?>');c.reportValidity();}
});
document.getElementById('registerPasswordConfirm').addEventListener('input',function(){this.setCustomValidity('');});
</script>
</body>
</html>
