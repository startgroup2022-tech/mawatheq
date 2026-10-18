<?php
/**
 * Invitation acceptance (Execution 31).
 *
 * The private onboarding channel: an administrator invites a partner, the partner
 * sets their own password here. The administrator never knows the password, which is
 * why this page exists rather than the admin form creating the credentials.
 *
 * The token is a 64-character hex string. It is looked up by hash, single-use, and
 * expiring — a forwarded invitation stops working once accepted.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/../includes/affiliate.php';
require_once __DIR__ . '/../includes/affiliate_auth.php';
require_once __DIR__ . '/../includes/ui.php';

if (isset($_GET['lang']) && in_array($_GET['lang'], ['ar', 'en'], true)) {
    $_SESSION['lang'] = $_GET['lang'];
}

$token = is_string($_GET['token'] ?? null) ? trim($_GET['token']) : '';
$invitation = $token !== '' ? affiliate_find_invitation($pdo, $token) : null;
$error = null;
$done = false;
$code = null;

$old = [
    'full_name' => $invitation ? (string)($invitation['full_name'] ?? '') : '',
    'email' => $invitation ? (string)($invitation['email'] ?? '') : '',
    'phone' => '',
    'city' => '',
    'company_name' => '',
    'commercial_registration' => '',
    'payout_method' => 'bank_transfer',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) { http_response_code(403); exit('Invalid request'); }

    $postString = static function (string $key, string $default = ''): string {
        $value = $_POST[$key] ?? $default;
        return is_string($value) ? $value : $default;
    };
    $token = trim($postString('token'));
    $invitation = $token !== '' ? affiliate_find_invitation($pdo, $token) : null;

    $old = [
        'full_name' => trim($postString('full_name', (string)($invitation['full_name'] ?? ''))),
        'email' => strtolower(trim($postString('email', (string)($invitation['email'] ?? '')))),
        'phone' => trim($postString('phone')),
        'city' => trim($postString('city')),
        'company_name' => trim($postString('company_name')),
        'commercial_registration' => trim($postString('commercial_registration')),
        'payout_method' => $postString('payout_method', 'bank_transfer'),
    ];

    if (!$invitation) {
        $error = is_rtl() ? 'رابط الدعوة غير صالح أو منتهي الصلاحية.' : 'This invitation link is invalid or has expired.';
    } elseif (empty($_POST['accepted_terms'])) {
        $error = is_rtl() ? 'يجب الموافقة على شروط البرنامج.' : 'You must accept the programme terms.';
    } else {
        $result = affiliate_accept_invitation($pdo, $token, array_merge($old, [
            'password' => $postString('password'),
            'password_confirm' => $postString('password_confirm'),
            'accepted_terms' => true,
            'affiliate_type' => $postString('affiliate_type', (string)($invitation['invite_type'] ?? 'individual')),
            'country' => $postString('country', 'BH'),
        ]));
        if (!empty($result['ok'])) {
            $done = true;
            $code = (string)$result['code'];
        } else {
            $error = match ((string)($result['error'] ?? '')) {
                'invalid_invitation' => is_rtl() ? 'رابط الدعوة غير صالح أو منتهي الصلاحية.' : 'This invitation link is invalid or has expired.',
                'invitation_used' => is_rtl() ? 'تم استخدام هذه الدعوة بالفعل.' : 'This invitation has already been used.',
                'invitation_email_mismatch' => is_rtl() ? 'هذه الدعوة مخصّصة لبريد آخر.' : 'This invitation was issued to a different email address.',
                'invalid_email' => is_rtl() ? 'صيغة البريد الإلكتروني غير صحيحة.' : 'Invalid email format.',
                'email_exists' => is_rtl() ? 'هذا البريد مسجّل مسبقاً.' : 'This email is already registered.',
                'password_too_short' => is_rtl() ? 'كلمة المرور يجب أن تكون 8 أحرف على الأقل.' : 'The password must be at least 8 characters.',
                'password_too_long' => is_rtl() ? 'كلمة المرور طويلة جداً.' : 'The password is too long.',
                'password_mismatch' => is_rtl() ? 'كلمتا المرور غير متطابقتين.' : 'The passwords do not match.',
                'consent_required' => is_rtl() ? 'يجب الموافقة على الشروط.' : 'You must accept the terms.',
                'invalid_name' => is_rtl() ? 'الاسم غير صالح.' : 'Invalid name.',
                default => is_rtl() ? 'تعذّر إنشاء الحساب. حاول مرة أخرى.' : 'The account could not be created. Please try again.',
            };
        }
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
<title><?= ui_e(is_rtl() ? 'قبول دعوة الشريك' : 'Accept your partner invitation') ?> · <?= ui_e(SITE_NAME_AR) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@700;800&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="stylesheet" href="/assets/css/app.css">
<link rel="stylesheet" href="/assets/css/enterprise.css">
<link rel="stylesheet" href="/assets/css/affiliate.css">
</head>
<body class="aff-auth-body">
<div class="aff-auth aff-auth-wide">
  <div class="aff-auth-card">
    <a href="/" class="aff-auth-brand"><img src="/assets/img/logo.webp" alt=""><span><?= ui_e(SITE_NAME_AR) ?></span></a>

    <?php if ($done): ?>
      <h1><?= ui_e(is_rtl() ? 'تم إنشاء حسابك' : 'Your account is ready') ?></h1>
      <div class="form-alert success">
        <?= ui_e(is_rtl() ? 'رمز الإحالة الخاص بك:' : 'Your referral code:') ?> <strong class="aff-mono"><?= ui_e((string)$code) ?></strong>
      </div>
      <p><?= ui_e(is_rtl()
        ? 'سجّل الدخول لاعتماد الشروط وتفعيل روابط الإحالة.'
        : 'Sign in to accept the terms and activate your referral links.') ?></p>
      <a class="btn btn-primary btn-block" href="/affiliate/login.php"><?= ui_e(is_rtl() ? 'الدخول الآن' : 'Sign in now') ?></a>
    <?php elseif (!$invitation): ?>
      <h1><?= ui_e(is_rtl() ? 'دعوة غير صالحة' : 'Invitation not valid') ?></h1>
      <div class="form-alert error"><?= ui_e(is_rtl()
        ? 'رابط الدعوة غير صالح أو منتهي الصلاحية أو مستخدم مسبقاً. اطلب دعوة جديدة من فريق البرنامج.'
        : 'This invitation link is invalid, expired, or already used. Ask the programme team for a new one.') ?></div>
      <a class="btn btn-outline btn-block" href="/affiliates.php"><?= ui_e(is_rtl() ? 'صفحة البرنامج' : 'Programme page') ?></a>
    <?php else: ?>
      <h1><?= ui_e(is_rtl() ? 'أكمل إنشاء حسابك' : 'Complete your account') ?></h1>
      <p class="aff-auth-sub"><?= ui_e(is_rtl() ? 'دعوة للانضمام إلى برنامج الشركاء' : 'Invitation to join the partner programme') ?></p>

      <?php if ($error): ?><div class="form-alert error"><?= ui_e($error) ?></div><?php endif; ?>

      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>">
        <input type="hidden" name="token" value="<?= ui_e($token) ?>">

        <div class="aff-grid">
          <label class="field"><span><?= ui_e(is_rtl() ? 'الاسم الكامل' : 'Full name') ?><span class="req">*</span></span>
            <input type="text" name="full_name" required maxlength="180" value="<?= ui_e($old['full_name']) ?>"></label>
          <label class="field"><span><?= ui_e(is_rtl() ? 'البريد الإلكتروني' : 'Email') ?><span class="req">*</span></span>
            <input type="email" name="email" required maxlength="190" value="<?= ui_e($old['email']) ?>" <?= !empty($invitation['email']) ? 'readonly' : '' ?>></label>
          <label class="field"><span><?= ui_e(is_rtl() ? 'كلمة المرور' : 'Password') ?><span class="req">*</span></span>
            <input type="password" name="password" required minlength="8" maxlength="72" autocomplete="new-password"></label>
          <label class="field"><span><?= ui_e(is_rtl() ? 'تأكيد كلمة المرور' : 'Confirm password') ?><span class="req">*</span></span>
            <input type="password" name="password_confirm" required minlength="8" maxlength="72" autocomplete="new-password"></label>
          <label class="field"><span><?= ui_e(is_rtl() ? 'رقم الجوال' : 'Phone') ?></span>
            <input type="tel" name="phone" maxlength="20" value="<?= ui_e($old['phone']) ?>"></label>
          <label class="field"><span><?= ui_e(is_rtl() ? 'نوع الحساب' : 'Account type') ?></span>
            <select name="affiliate_type">
              <?php foreach (['individual' => is_rtl() ? 'فرد' : 'Individual', 'company' => is_rtl() ? 'شركة' : 'Company', 'partner' => is_rtl() ? 'شريك' : 'Partner'] as $k => $label): ?>
                <option value="<?= ui_e($k) ?>" <?= (string)($invitation['invite_type'] ?? 'individual') === $k ? 'selected' : '' ?>><?= ui_e($label) ?></option>
              <?php endforeach; ?>
            </select></label>
          <label class="field"><span><?= ui_e(is_rtl() ? 'اسم الشركة' : 'Company name') ?></span>
            <input type="text" name="company_name" maxlength="190" value="<?= ui_e($old['company_name']) ?>"></label>
          <label class="field"><span><?= ui_e(is_rtl() ? 'السجل التجاري' : 'Commercial registration') ?></span>
            <input type="text" name="commercial_registration" maxlength="60" value="<?= ui_e($old['commercial_registration']) ?>"></label>
          <label class="field"><span><?= ui_e(is_rtl() ? 'المدينة' : 'City') ?></span>
            <input type="text" name="city" maxlength="80" value="<?= ui_e($old['city']) ?>"></label>
          <label class="field"><span><?= ui_e(is_rtl() ? 'طريقة الصرف' : 'Payout method') ?></span>
            <select name="payout_method">
              <option value="bank_transfer"><?= ui_e(is_rtl() ? 'تحويل بنكي' : 'Bank transfer') ?></option>
              <option value="cheque"><?= ui_e(is_rtl() ? 'شيك' : 'Cheque') ?></option>
              <option value="none"><?= ui_e(is_rtl() ? 'لاحقاً' : 'Decide later') ?></option>
            </select></label>
        </div>

        <?php if (!empty($invitation['personal_message'])): ?>
          <div class="aff-invite-note">
            <strong><?= ui_e(is_rtl() ? 'رسالة من فريق البرنامج' : 'Message from the programme team') ?></strong>
            <p><?= ui_e((string)$invitation['personal_message']) ?></p>
          </div>
        <?php endif; ?>

        <label class="field-check">
          <input type="checkbox" name="accepted_terms" value="1" required>
          <span><?= ui_e(is_rtl() ? 'أوافق على شروط برنامج الشركاء وسياسة الخصوصية.' : 'I accept the partner programme terms and the privacy policy.') ?></span>
        </label>

        <button class="btn btn-primary btn-block" type="submit"><?= ui_e(is_rtl() ? 'إنشاء الحساب' : 'Create account') ?></button>
      </form>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
