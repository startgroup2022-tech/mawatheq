<?php
/**
 * Affiliate account registration (Execution 32).
 *
 * This is the account-based path: it creates a real `affiliates` row with a password
 * hash and signs the applicant in, so they can watch their own application move
 * through review. It is deliberately not the same thing as the public application
 * form on /affiliates.php — that one only queues a request.
 *
 * The account is created `pending`. Being able to sign in and being able to earn are
 * separate decisions, and nothing here grants the second: commission eligibility is
 * decided later by an administrator, and `affiliate_can_earn()` additionally requires
 * an approved compliance review. So a fresh registrant reaches their dashboard and
 * nothing else.
 *
 * No session is established until the account exists, and the password is hashed
 * before it reaches the database — see affiliate_register() in includes/affiliate_auth.php.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/../includes/affiliate.php';
require_once __DIR__ . '/../includes/affiliate_auth.php';
require_once __DIR__ . '/../includes/ui.php';

if (isset($_GET['lang']) && in_array($_GET['lang'], ['ar', 'en'], true)) {
    $_SESSION['lang'] = $_GET['lang'];
}

// Already signed in: the account exists, so send them to it rather than letting them
// create a second one.
if (affiliate_current($pdo)) {
    header('Location: /affiliate/dashboard.php');
    exit;
}

$settings = affiliate_settings($pdo);
$programEnabled = $settings['program_enabled'];
$error = null;
$fieldErrors = [];
$old = [];

$countryLabels = [
    'BH' => is_rtl() ? 'البحرين' : 'Bahrain',
    'SA' => is_rtl() ? 'السعودية' : 'Saudi Arabia',
    'AE' => is_rtl() ? 'الإمارات' : 'UAE',
    'KW' => is_rtl() ? 'الكويت' : 'Kuwait',
    'QA' => is_rtl() ? 'قطر' : 'Qatar',
    'OM' => is_rtl() ? 'عمان' : 'Oman',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) { http_response_code(403); exit('Invalid request'); }

    // Only scalars are read. An array in a text field would otherwise reach trim()
    // and the mb_* functions and produce a confusing failure.
    $postString = static function (string $key, string $default = ''): string {
        $value = $_POST[$key] ?? $default;
        return is_string($value) ? $value : $default;
    };

    $old = [
        'full_name' => trim($postString('full_name')),
        'email' => strtolower(trim($postString('email'))),
        'phone' => trim($postString('phone')),
        'country' => $postString('country', 'BH'),
        'city' => trim($postString('city')),
        'affiliate_type' => $postString('affiliate_type', 'individual'),
        'company_name' => trim($postString('company_name')),
        'website' => trim($postString('website')),
        'social_links' => trim($postString('social_links')),
        'marketing_channels' => trim($postString('marketing_channels')),
        'payout_method' => $postString('payout_method', 'none'),
    ];

    if (!$programEnabled) {
        $error = is_rtl() ? 'برنامج الشركاء غير مفعّل حالياً.' : 'The partner programme is not currently open.';
    } else {
        $result = affiliate_register($pdo, array_merge($old, [
            'password' => is_string($_POST['password'] ?? null) ? $_POST['password'] : '',
            'password_confirm' => is_string($_POST['password_confirm'] ?? null) ? $_POST['password_confirm'] : '',
            'accepted_terms' => !empty($_POST['accepted_terms']),
            'accepted_privacy' => !empty($_POST['accepted_privacy']),
        ]));

        if (!empty($result['ok'])) {
            // The session is already established by affiliate_register().
            header('Location: /affiliate/dashboard.php?registered=1');
            exit;
        }

        $code = (string)($result['error'] ?? '');
        $error = match ($code) {
            'invalid_email' => is_rtl() ? 'صيغة البريد الإلكتروني غير صحيحة.' : 'Invalid email format.',
            'invalid_name' => is_rtl() ? 'الاسم غير صالح.' : 'Invalid name.',
            'consent_required' => is_rtl() ? 'يجب الموافقة على الشروط وسياسة الخصوصية.' : 'You must accept the terms and the privacy policy.',
            'password_too_short' => is_rtl() ? 'كلمة المرور يجب أن تكون 8 أحرف على الأقل.' : 'Your password must be at least 8 characters.',
            'password_too_long' => is_rtl() ? 'كلمة المرور طويلة جداً (الحد 72 حرفاً).' : 'That password is too long (72 characters maximum).',
            'password_mismatch' => is_rtl() ? 'كلمتا المرور غير متطابقتين.' : 'The two passwords do not match.',
            'rate_limited' => is_rtl() ? 'طلبات كثيرة من هذا الاتصال. حاول بعد 15 دقيقة.' : 'Too many attempts from this connection. Try again in 15 minutes.',
            // Deliberately the same message as the generic failure: telling an
            // anonymous visitor "this email is registered" turns the form into an
            // account-existence oracle.
            'email_exists', 'create_failed' => is_rtl()
                ? 'تعذّر إنشاء الحساب. تحقّق من بياناتك وحاول مرة أخرى، أو سجّل الدخول إذا كان لديك حساب.'
                : 'The account could not be created. Check your details and try again, or sign in if you already have an account.',
            default => is_rtl() ? 'تعذّر إنشاء الحساب. حاول مرة أخرى.' : 'The account could not be created. Please try again.',
        };
    }
}

$lang = current_lang();
$dir = is_rtl() ? 'rtl' : 'ltr';
$rtl = is_rtl();
?>
<!DOCTYPE html>
<html lang="<?= ui_e($lang) ?>" dir="<?= $dir ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= ui_e($rtl ? 'إنشاء حساب مسوّق' : 'Create an affiliate account') ?> · <?= ui_e(SITE_NAME_AR) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@700;800&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="stylesheet" href="/assets/css/app.css">
<link rel="stylesheet" href="/assets/css/enterprise.css">
<link rel="stylesheet" href="/assets/css/affiliate.css">
</head>
<body class="aff-auth-body">
<div class="aff-auth aff-auth-wide">
  <div class="aff-auth-card">
    <a href="/" class="aff-auth-brand"><img src="/assets/img/logo.webp" width="457" height="813" alt=""><span><?= ui_e(SITE_NAME_AR) ?></span></a>
    <h1><?= ui_e($rtl ? 'إنشاء حساب مسوّق' : 'Create an affiliate account') ?></h1>
    <p class="aff-auth-sub"><?= ui_e($rtl ? 'برنامج الشركاء' : 'Partner programme') ?></p>

    <?php if (!$programEnabled): ?>
      <div class="form-alert"><?= ui_e($rtl
        ? 'برنامج الشركاء غير مفعّل حالياً. يمكنك التواصل معنا لمعرفة الموعد المتوقع.'
        : 'The partner programme is not currently open. Contact us to hear when it will be.') ?></div>
      <a class="btn btn-primary btn-block" href="/contact.php"><?= ui_e($rtl ? 'تواصل معنا' : 'Contact us') ?></a>
    <?php else: ?>

      <?php if ($error): ?><div class="form-alert error"><?= ui_e($error) ?></div><?php endif; ?>

      <p class="aff-auth-note"><?= ui_e($rtl
        ? 'أنشئ حسابك الآن وادخل مباشرة. سيبقى حسابك قيد المراجعة حتى يعتمده فريق مواثيق، وبعد الاعتماد يمكنك قبول الشروط وتفعيل روابط الإحالة.'
        : 'Create your account and sign in straight away. Your account stays under review until the Mawatheq team approves it; once approved you can accept the terms and activate your referral links.') ?></p>

      <form method="post" class="aff-form" novalidate>
        <input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>">

        <fieldset>
          <legend><?= ui_e($rtl ? 'بيانات أساسية' : 'Basic details') ?></legend>
          <div class="aff-grid">
            <label class="field"><span><?= ui_e($rtl ? 'الاسم الكامل' : 'Full name') ?><span class="req">*</span></span>
              <input type="text" name="full_name" required maxlength="180" autocomplete="name" value="<?= ui_e($old['full_name'] ?? '') ?>"></label>
            <label class="field"><span><?= ui_e($rtl ? 'البريد الإلكتروني' : 'Email address') ?><span class="req">*</span></span>
              <input type="email" name="email" required maxlength="190" autocomplete="username" value="<?= ui_e($old['email'] ?? '') ?>"></label>
            <label class="field"><span><?= ui_e($rtl ? 'رقم الجوال' : 'Mobile number') ?></span>
              <input type="tel" name="phone" maxlength="20" autocomplete="tel" placeholder="+973" value="<?= ui_e($old['phone'] ?? '') ?>"></label>
            <label class="field"><span><?= ui_e($rtl ? 'الدولة' : 'Country') ?></span>
              <select name="country">
                <?php foreach ($countryLabels as $code => $label): ?>
                  <option value="<?= ui_e($code) ?>" <?= ($old['country'] ?? 'BH') === $code ? 'selected' : '' ?>><?= ui_e($label) ?></option>
                <?php endforeach; ?>
              </select></label>
            <label class="field"><span><?= ui_e($rtl ? 'المدينة' : 'City') ?></span>
              <input type="text" name="city" maxlength="80" autocomplete="address-level2" value="<?= ui_e($old['city'] ?? '') ?>"></label>
            <label class="field"><span><?= ui_e($rtl ? 'نوع المسوّق' : 'Affiliate type') ?></span>
              <select name="affiliate_type">
                <?php foreach (['individual' => $rtl ? 'فرد' : 'Individual', 'company' => $rtl ? 'شركة' : 'Company', 'partner' => $rtl ? 'شريك' : 'Partner'] as $k => $label): ?>
                  <option value="<?= ui_e($k) ?>" <?= ($old['affiliate_type'] ?? 'individual') === $k ? 'selected' : '' ?>><?= ui_e($label) ?></option>
                <?php endforeach; ?>
              </select></label>
          </div>
        </fieldset>

        <fieldset>
          <legend><?= ui_e($rtl ? 'كلمة المرور' : 'Password') ?></legend>
          <div class="aff-grid">
            <label class="field"><span><?= ui_e($rtl ? 'كلمة المرور' : 'Password') ?><span class="req">*</span></span>
              <span class="password-input">
                <input type="password" name="password" id="affRegPassword" required minlength="8" maxlength="72" autocomplete="new-password">
                <button type="button" class="password-toggle" data-password-toggle="affRegPassword"
                        aria-label="<?= ui_e($rtl ? 'إظهار كلمة المرور' : 'Show password') ?>"><?= ui_e($rtl ? 'إظهار' : 'Show') ?></button>
              </span>
              <small class="muted"><?= ui_e($rtl ? '8 أحرف على الأقل.' : 'At least 8 characters.') ?></small></label>
            <label class="field"><span><?= ui_e($rtl ? 'تأكيد كلمة المرور' : 'Confirm password') ?><span class="req">*</span></span>
              <span class="password-input">
                <input type="password" name="password_confirm" id="affRegPasswordConfirm" required minlength="8" maxlength="72" autocomplete="new-password">
                <button type="button" class="password-toggle" data-password-toggle="affRegPasswordConfirm"
                        aria-label="<?= ui_e($rtl ? 'إظهار كلمة المرور' : 'Show password') ?>"><?= ui_e($rtl ? 'إظهار' : 'Show') ?></button>
              </span></label>
          </div>
        </fieldset>

        <fieldset>
          <legend><?= ui_e($rtl ? 'بيانات النشاط (اختياري)' : 'Business details (optional)') ?></legend>
          <div class="aff-grid">
            <label class="field"><span><?= ui_e($rtl ? 'اسم الشركة' : 'Company name') ?></span>
              <input type="text" name="company_name" maxlength="190" autocomplete="organization" value="<?= ui_e($old['company_name'] ?? '') ?>"></label>
            <label class="field"><span><?= ui_e($rtl ? 'الموقع الإلكتروني' : 'Website') ?></span>
              <input type="url" name="website" maxlength="255" placeholder="https://" value="<?= ui_e($old['website'] ?? '') ?>"></label>
            <label class="field"><span><?= ui_e($rtl ? 'روابط التواصل' : 'Social media accounts') ?></span>
              <input type="text" name="social_links" maxlength="1000" placeholder="<?= ui_e($rtl ? 'إنستغرام، لينكدإن…' : 'Instagram, LinkedIn…') ?>" value="<?= ui_e($old['social_links'] ?? '') ?>"></label>
          </div>
          <label class="field"><span><?= ui_e($rtl ? 'القنوات التسويقية' : 'Marketing channels') ?></span>
            <input type="text" name="marketing_channels" maxlength="1000" placeholder="<?= ui_e($rtl ? 'مثال: إنستغرام، لينكدإن، نشرة بريدية' : 'e.g. Instagram, LinkedIn, newsletter') ?>" value="<?= ui_e($old['marketing_channels'] ?? '') ?>"></label>
          <label class="field"><span><?= ui_e($rtl ? 'طريقة الصرف المفضّلة' : 'Preferred payout method') ?></span>
            <select name="payout_method">
              <option value="none" <?= ($old['payout_method'] ?? 'none') === 'none' ? 'selected' : '' ?>><?= ui_e($rtl ? 'لاحقاً' : 'Decide later') ?></option>
              <option value="bank_transfer" <?= ($old['payout_method'] ?? '') === 'bank_transfer' ? 'selected' : '' ?>><?= ui_e($rtl ? 'تحويل بنكي' : 'Bank transfer') ?></option>
              <option value="cheque" <?= ($old['payout_method'] ?? '') === 'cheque' ? 'selected' : '' ?>><?= ui_e($rtl ? 'شيك' : 'Cheque') ?></option>
            </select></label>
        </fieldset>

        <fieldset>
          <legend><?= ui_e($rtl ? 'الموافقات' : 'Consent') ?></legend>
          <label class="field-check">
            <input type="checkbox" name="accepted_terms" value="1" required>
            <span><?= $rtl
              ? 'أوافق على <a href="/affiliate/terms.php" target="_blank" rel="noopener">شروط برنامج الشركاء</a>.'
              : 'I accept the <a href="/affiliate/terms.php" target="_blank" rel="noopener">partner programme terms</a>.' ?></span>
          </label>
          <label class="field-check">
            <input type="checkbox" name="accepted_privacy" value="1" required>
            <span><?= $rtl
              ? 'أوافق على <a href="/privacy.php" target="_blank" rel="noopener">سياسة الخصوصية</a> ومعالجة بياناتي لغرض إدارة البرنامج.'
              : 'I accept the <a href="/privacy.php" target="_blank" rel="noopener">privacy policy</a> and the processing of my data to administer the programme.' ?></span>
          </label>
        </fieldset>

        <button class="btn btn-primary btn-block btn-lg" type="submit"><?= ui_e($rtl ? 'إنشاء الحساب' : 'Create account') ?></button>
      </form>

      <div class="aff-auth-links">
        <a href="/affiliate/login.php"><?= ui_e($rtl ? 'لديك حساب؟ سجّل الدخول' : 'Already have an account? Sign in') ?></a>
        <a href="/affiliates.php"><?= ui_e($rtl ? 'عن البرنامج' : 'About the programme') ?></a>
      </div>
    <?php endif; ?>
  </div>
</div>

<script src="/assets/js/main.js"></script>
<script src="/assets/js/enterprise.js" defer></script>
</body>
</html>
