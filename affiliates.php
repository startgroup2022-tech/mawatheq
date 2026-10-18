<?php
/**
 * Public affiliate programme page (Execution 31).
 *
 * Two jobs: explain the programme honestly, and take an application. The explanation
 * is deliberately specific about what does and does not earn commission — the
 * platform's revenue is commissionable, a lawyer's professional fee is not, and
 * saying so up front is better than an affiliate discovering it after a month of
 * promotion.
 *
 * Reachable whether or not the programme is enabled: the page explains the programme
 * and the form is only shown when it is accepting applications.
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lang/strings.php';
require_once __DIR__ . '/includes/affiliate.php';
require_once __DIR__ . '/includes/affiliate_service.php';
require_once __DIR__ . '/includes/affiliate_auth.php';
require_once __DIR__ . '/includes/ui.php';
require_once __DIR__ . '/includes/header.php';

$settings = affiliate_settings($pdo);
$programEnabled = $settings['program_enabled'];
$submitted = false;
$reference = null;
$error = null;
$old = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) { http_response_code(403); exit('Invalid request'); }

    // Only scalars are read; an array in a text field would otherwise reach trim()
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
        'applicant_type' => $postString('applicant_type', 'individual'),
        'company_name' => trim($postString('company_name')),
        'commercial_registration' => trim($postString('commercial_registration')),
        'website' => trim($postString('website')),
        'social_links' => trim($postString('social_links')),
        'marketing_channels' => trim($postString('marketing_channels')),
        'promotion_methods' => trim($postString('promotion_methods')),
        'audience_description' => trim($postString('audience_description')),
        'expected_monthly_reach' => trim($postString('expected_monthly_reach')),
        'payout_method' => $postString('payout_method', 'bank_transfer'),
    ];

    if (!$programEnabled) {
        $error = is_rtl() ? 'برنامج الشركاء غير مفعّل حالياً.' : 'The partner programme is not currently open.';
    } else {
        $result = affiliate_submit_application($pdo, array_merge($old, [
            'accepted_terms' => !empty($_POST['accepted_terms']),
            'accepted_privacy' => !empty($_POST['accepted_privacy']),
        ]));
        if (!empty($result['ok'])) {
            $submitted = true;
            $reference = (string)$result['reference'];
        } else {
            $error = match ((string)($result['error'] ?? '')) {
                'invalid_email' => is_rtl() ? 'صيغة البريد الإلكتروني غير صحيحة.' : 'Invalid email format.',
                'invalid_name' => is_rtl() ? 'الاسم غير صالح.' : 'Invalid name.',
                'consent_required' => is_rtl() ? 'يجب الموافقة على الشروط وسياسة الخصوصية.' : 'You must accept the terms and the privacy policy.',
                'rate_limited' => is_rtl() ? 'طلبات كثيرة من هذا الاتصال. حاول بعد 15 دقيقة.' : 'Too many requests from this connection. Try again in 15 minutes.',
                'application_exists' => is_rtl() ? 'لديك طلب قيد المراجعة بالفعل.' : 'You already have an application under review.',
                'email_exists' => is_rtl() ? 'هذا البريد مسجّل مسبقاً.' : 'This email is already registered.',
                default => is_rtl() ? 'تعذّر إرسال الطلب. حاول مرة أخرى.' : 'The application could not be submitted. Please try again.',
            };
        }
    }
}

$pageTitle = is_rtl() ? 'برنامج الشركاء' : 'Partner programme';
$pageSubtitle = is_rtl()
    ? 'اربح مقابل كل عميل تحيله إلى خدمات المنصة.'
    : 'Earn for every customer you refer to the platform\'s services.';
$commissionable = affiliate_service_catalog($pdo);
?>
<div class="aff-public">
  <section class="aff-hero">
    <div class="aff-hero-inner">
      <span class="aff-eyebrow"><?= is_rtl() ? 'برنامج الشركاء' : 'Partner programme' ?></span>
      <h1><?= is_rtl() ? 'اربح من الإحالات، بوضوح وشفافية' : 'Earn from referrals, clearly and transparently' ?></h1>
      <p><?= is_rtl()
        ? 'شارك رابط الإحالة الخاص بك، وتابع كل زيارة وتحويل، واحصل على عمولة على إيرادات المنصة المؤهلة. لا رسوم ولا التزامات.'
        : 'Share your referral link, track every click and conversion, and earn commission on eligible platform revenue. No fees, no obligation.' ?></p>
      <div class="aff-hero-actions">
        <a class="btn btn-gold btn-lg" href="#apply"><?= is_rtl() ? 'تقدّم بطلب' : 'Apply now' ?></a>
        <a class="btn btn-outline btn-lg" href="/affiliate/login.php"><?= is_rtl() ? 'دخول المسوّقين' : 'Affiliate sign-in' ?></a>
      </div>
    </div>
  </section>

  <section class="aff-section">
    <div class="aff-cards">
      <div class="aff-card">
        <div class="aff-card-ico"><?= ui_icon('external') ?></div>
        <h3><?= is_rtl() ? 'رابط إحالة خاص' : 'Your own referral link' ?></h3>
        <p><?= is_rtl() ? 'رابط دائم مع رمز خاص بك، وتتبع تلقائي لكل زيارة وتحويل.' : 'A permanent link with your own code, with automatic click and conversion tracking.' ?></p>
      </div>
      <div class="aff-card">
        <div class="aff-card-ico"><?= ui_icon('chart') ?></div>
        <h3><?= is_rtl() ? 'لوحة تحليلات' : 'Analytics dashboard' ?></h3>
        <p><?= is_rtl() ? 'الزيارات، التحويلات، الإيراد المؤهل، والعمولات — محدّثة لحظياً.' : 'Clicks, conversions, eligible revenue and commission — updated as they happen.' ?></p>
      </div>
      <div class="aff-card">
        <div class="aff-card-ico"><?= ui_icon('wallet') ?></div>
        <h3><?= is_rtl() ? 'مدفوعات واضحة' : 'Clear payouts' ?></h3>
        <p><?= is_rtl() ? 'اطلب الصرف عند بلوغ الحد الأدنى، مع سجل كامل لكل حركة.' : 'Request a payout once you reach the minimum, with a full statement of every movement.' ?></p>
      </div>
      <div class="aff-card">
        <div class="aff-card-ico"><?= ui_icon('shield') ?></div>
        <h3><?= is_rtl() ? 'التزام مهني' : 'Professional compliance' ?></h3>
        <p><?= is_rtl() ? 'العمولة على إيرادات المنصة المؤهلة فقط، وليست على الأتعاب المهنية المنظّمة.' : 'Commission applies to eligible platform revenue only, never to regulated professional fees.' ?></p>
      </div>
    </div>
  </section>

  <section class="aff-section aff-section-alt">
    <div class="aff-section-inner">
      <h2><?= is_rtl() ? 'ما الذي تُحتسب عليه العمولة؟' : 'What earns commission?' ?></h2>
      <p class="aff-lead"><?= is_rtl()
        ? 'نوضّح ذلك مسبقاً بدل أن تكتشفه لاحقاً. الأتعاب المهنية للمحامين منظّمة في مملكة البحرين، ولا يجوز تقاسمها مع طرف ثالث، لذلك تُسجَّل الإحالة لأغراض التتبع والتحليلات دون عمولة.'
        : 'We state this up front rather than letting you discover it later. A lawyer\'s professional fee is regulated in the Kingdom of Bahrain and may not be shared with a third party, so those referrals are recorded for tracking and analytics without commission.' ?></p>
      <div class="table-wrap">
        <table class="admin-table aff-services">
          <thead><tr>
            <th><?= is_rtl() ? 'الخدمة' : 'Service' ?></th>
            <th><?= is_rtl() ? 'نوع الإيراد' : 'Revenue type' ?></th>
            <th><?= is_rtl() ? 'العمولة' : 'Commission' ?></th>
          </tr></thead>
          <tbody>
            <?php foreach ($commissionable as $key => $service):
                $mode = affiliate_service_effective_mode($service)['mode'];
                $label = match ($mode) {
                    'commission' => is_rtl() ? 'مؤهلة للعمولة' : 'Commissionable',
                    'attribution_only' => is_rtl() ? 'تتبع بدون عمولة' : 'Tracked, no commission',
                    'analytics_only' => is_rtl() ? 'تحليلات فقط' : 'Analytics only',
                    'prohibited' => is_rtl() ? 'غير مسموح' : 'Not permitted',
                    default => is_rtl() ? 'غير مفعّلة' : 'Not enabled',
                };
                $revenueLabel = match ((string)$service['revenue_source']) {
                    'subscription' => is_rtl() ? 'اشتراك' : 'Subscription',
                    'consultation' => is_rtl() ? 'استشارة' : 'Consultation',
                    'invoice' => is_rtl() ? 'فاتورة محامٍ' : 'Lawyer invoice',
                    default => is_rtl() ? 'رسوم منصة' : 'Platform fee',
                };
            ?>
            <tr>
              <td><?= ui_e(affiliate_service_label($service, $key)) ?></td>
              <td><?= ui_e($revenueLabel) ?></td>
              <td><span class="status-pill <?= $mode === 'commission' ? 'status-confirmed' : ($mode === 'prohibited' ? 'status-cancelled' : 'status-pending') ?>"><?= ui_e($label) ?></span></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="aff-note"><?= is_rtl()
        ? 'تُحدَّد نسب العمولة في خطة العمولة المطبّقة على حسابك، وتُعرض كاملةً في لوحة المسوّق.'
        : 'Commission rates come from the plan applied to your account and are shown in full on your dashboard.' ?></p>
    </div>
  </section>

  <section class="aff-section" id="apply">
    <div class="aff-section-inner aff-apply-wrap">
      <?php if ($submitted): ?>
        <div class="aff-success">
          <div class="aff-success-ico"><?= ui_icon('check-circle') ?></div>
          <h2><?= is_rtl() ? 'تم استلام طلبك' : 'Your application was received' ?></h2>
          <p><?= is_rtl() ? 'رقم مرجع الطلب:' : 'Your application reference:' ?> <strong class="aff-mono"><?= ui_e((string)$reference) ?></strong></p>
          <p><?= is_rtl()
            ? 'سنراجع طلبك ونتواصل معك على البريد المسجّل. احتفظ برقم المرجع.'
            : 'We will review your application and contact you at the email you provided. Keep the reference number.' ?></p>
          <a class="btn btn-outline" href="/"><?= is_rtl() ? 'العودة للرئيسية' : 'Back to the home page' ?></a>
        </div>
      <?php else: ?>
        <h2><?= is_rtl() ? 'تقدّم بطلب الانضمام' : 'Apply to join' ?></h2>
        <?php if (!$programEnabled): ?>
          <div class="form-alert"><?= is_rtl() ? 'برنامج الشركاء غير مفعّل حالياً. يمكنك التواصل معنا لمعرفة الموعد المتوقع.' : 'The partner programme is not currently open. Contact us to hear when it will be.' ?></div>
          <a class="btn btn-primary" href="/contact.php"><?= is_rtl() ? 'تواصل معنا' : 'Contact us' ?></a>
        <?php else: ?>
          <?php if ($error): ?><div class="form-alert error"><?= ui_e($error) ?></div><?php endif; ?>
          <form method="post" class="aff-form">
            <input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>">

            <fieldset>
              <legend><?= is_rtl() ? 'بيانات أساسية' : 'Basic details' ?></legend>
              <div class="aff-grid">
                <label class="field"><span><?= is_rtl() ? 'الاسم الكامل' : 'Full name' ?><span class="req">*</span></span>
                  <input type="text" name="full_name" required maxlength="180" value="<?= ui_e($old['full_name'] ?? '') ?>"></label>
                <label class="field"><span><?= is_rtl() ? 'البريد الإلكتروني' : 'Email' ?><span class="req">*</span></span>
                  <input type="email" name="email" required maxlength="190" value="<?= ui_e($old['email'] ?? '') ?>"></label>
                <label class="field"><span><?= is_rtl() ? 'رقم الجوال' : 'Phone' ?></span>
                  <input type="tel" name="phone" maxlength="20" value="<?= ui_e($old['phone'] ?? '') ?>"></label>
                <label class="field"><span><?= is_rtl() ? 'الدولة' : 'Country' ?></span>
                  <select name="country">
                    <?php foreach (['BH' => is_rtl() ? 'البحرين' : 'Bahrain', 'SA' => is_rtl() ? 'السعودية' : 'Saudi Arabia', 'AE' => is_rtl() ? 'الإمارات' : 'UAE', 'KW' => is_rtl() ? 'الكويت' : 'Kuwait', 'QA' => is_rtl() ? 'قطر' : 'Qatar', 'OM' => is_rtl() ? 'عمان' : 'Oman'] as $code => $label): ?>
                      <option value="<?= ui_e($code) ?>" <?= ($old['country'] ?? 'BH') === $code ? 'selected' : '' ?>><?= ui_e($label) ?></option>
                    <?php endforeach; ?>
                  </select></label>
                <label class="field"><span><?= is_rtl() ? 'المدينة' : 'City' ?></span>
                  <input type="text" name="city" maxlength="80" value="<?= ui_e($old['city'] ?? '') ?>"></label>
                <label class="field"><span><?= is_rtl() ? 'نوع المسوّق' : 'Affiliate type' ?></span>
                  <select name="applicant_type">
                    <?php foreach (['individual' => is_rtl() ? 'فرد' : 'Individual', 'company' => is_rtl() ? 'شركة' : 'Company', 'partner' => is_rtl() ? 'شريك' : 'Partner'] as $k => $label): ?>
                      <option value="<?= ui_e($k) ?>" <?= ($old['applicant_type'] ?? 'individual') === $k ? 'selected' : '' ?>><?= ui_e($label) ?></option>
                    <?php endforeach; ?>
                  </select></label>
              </div>
            </fieldset>

            <fieldset>
              <legend><?= is_rtl() ? 'بيانات النشاط (اختياري)' : 'Business details (optional)' ?></legend>
              <div class="aff-grid">
                <label class="field"><span><?= is_rtl() ? 'اسم الشركة' : 'Company name' ?></span>
                  <input type="text" name="company_name" maxlength="190" value="<?= ui_e($old['company_name'] ?? '') ?>"></label>
                <label class="field"><span><?= is_rtl() ? 'السجل التجاري' : 'Commercial registration' ?></span>
                  <input type="text" name="commercial_registration" maxlength="60" value="<?= ui_e($old['commercial_registration'] ?? '') ?>"></label>
                <label class="field"><span><?= is_rtl() ? 'الموقع الإلكتروني' : 'Website' ?></span>
                  <input type="url" name="website" maxlength="255" placeholder="https://" value="<?= ui_e($old['website'] ?? '') ?>"></label>
              </div>
            </fieldset>

            <fieldset>
              <legend><?= is_rtl() ? 'كيف ستسوّق؟' : 'How will you promote?' ?></legend>
              <label class="field"><span><?= is_rtl() ? 'القنوات التسويقية' : 'Marketing channels' ?></span>
                <input type="text" name="marketing_channels" maxlength="1000" placeholder="<?= ui_e(is_rtl() ? 'مثال: إنستغرام، لينكدإن، نشرة بريدية' : 'e.g. Instagram, LinkedIn, newsletter') ?>" value="<?= ui_e($old['marketing_channels'] ?? '') ?>"></label>
              <label class="field"><span><?= is_rtl() ? 'أساليب الترويج' : 'Promotion methods' ?></span>
                <textarea name="promotion_methods" maxlength="1000" rows="3" placeholder="<?= ui_e(is_rtl() ? 'صف بإيجاز كيف ستقدّم المنصة لجمهورك.' : 'Briefly describe how you will present the platform to your audience.') ?>"><?= ui_e($old['promotion_methods'] ?? '') ?></textarea></label>
              <label class="field"><span><?= is_rtl() ? 'روابط التواصل' : 'Social links' ?></span>
                <input type="text" name="social_links" maxlength="1000" value="<?= ui_e($old['social_links'] ?? '') ?>"></label>
              <div class="aff-grid">
                <label class="field"><span><?= is_rtl() ? 'وصف الجمهور' : 'Audience description' ?></span>
                  <textarea name="audience_description" maxlength="2000" rows="3"><?= ui_e($old['audience_description'] ?? '') ?></textarea></label>
                <label class="field"><span><?= is_rtl() ? 'الوصول الشهري المتوقع' : 'Expected monthly reach' ?></span>
                  <input type="number" name="expected_monthly_reach" min="0" max="100000000" value="<?= ui_e($old['expected_monthly_reach'] ?? '') ?>"></label>
              </div>
              <label class="field"><span><?= is_rtl() ? 'طريقة الصرف المفضّلة' : 'Preferred payout method' ?></span>
                <select name="payout_method">
                  <option value="bank_transfer"><?= is_rtl() ? 'تحويل بنكي' : 'Bank transfer' ?></option>
                  <option value="cheque"><?= is_rtl() ? 'شيك' : 'Cheque' ?></option>
                  <option value="none"><?= is_rtl() ? 'لاحقاً' : 'Decide later' ?></option>
                </select></label>
            </fieldset>

            <fieldset>
              <legend><?= is_rtl() ? 'الموافقات' : 'Consent' ?></legend>
              <label class="field-check">
                <input type="checkbox" name="accepted_terms" value="1" required>
                <span><?= is_rtl() ? 'أوافق على شروط برنامج الشركاء.' : 'I accept the partner programme terms.' ?></span>
              </label>
              <label class="field-check">
                <input type="checkbox" name="accepted_privacy" value="1" required>
                <span><?= is_rtl() ? 'أوافق على سياسة الخصوصية ومعالجة بياناتي لغرض إدارة البرنامج.' : 'I accept the privacy policy and the processing of my data to administer the programme.' ?></span>
              </label>
            </fieldset>

            <button class="btn btn-primary btn-lg" type="submit"><?= is_rtl() ? 'إرسال الطلب' : 'Submit application' ?></button>
          </form>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </section>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
