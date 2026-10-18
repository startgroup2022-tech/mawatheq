<?php
/**
 * Terms and compliance (Execution 31).
 *
 * Where an affiliate accepts the current agreement and records their payout/tax
 * details. Acceptance is what moves the account from `approved` to `active`, because
 * only an affiliate who has agreed to the current terms may earn.
 *
 * Acceptance records the version accepted and the IP it came from. A later dispute
 * is then resolvable against the exact text agreed to at that moment, rather than
 * against whatever the terms say today.
 */
$activeAffiliate = 'terms';
require_once __DIR__ . '/../includes/affiliate_header.php';
require_once __DIR__ . '/../includes/affiliate_auth.php';


$affiliateId = (int)$affiliate['id'];
$error = null;
$success = null;

$acceptedTerms = affiliate_has_accepted($pdo, $affiliateId, 'terms');
$acceptedPrivacy = affiliate_has_accepted($pdo, $affiliateId, 'privacy');
$complianceSummary = affiliate_service_compliance_summary($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $string = static function (string $key, string $default = ''): string {
        $value = $_POST[$key] ?? $default;
        return is_string($value) ? trim($value) : $default;
    };
    if ($string('action') === 'accept') {
        if (empty($_POST['accepted_terms']) || empty($_POST['accepted_privacy'])) {
            $error = is_rtl() ? 'يجب الموافقة على الشروط وسياسة الخصوصية.' : 'You must accept the terms and the privacy policy.';
        } else {
            $okTerms = affiliate_accept_terms($pdo, $affiliateId, 'terms');
            $okPrivacy = affiliate_accept_terms($pdo, $affiliateId, 'privacy');
            if ($okTerms && $okPrivacy) {
                $acceptedTerms = true;
                $acceptedPrivacy = true;
                // An approved affiliate who has now accepted the terms can be activated.
                if ((string)$affiliate['status'] === 'approved') {
                    $activation = affiliate_activate($pdo, $affiliateId);
                    if (!empty($activation['ok'])) {
                        $success = is_rtl() ? 'تم اعتماد الشروط وتفعيل حسابك. روابط الإحالة أصبحت فعّالة.' : 'Terms accepted and your account is active. Your referral links now work.';
                        $affiliate = affiliate_find($pdo, $affiliateId) ?? $affiliate;
                        $affiliateCanEarn = affiliate_can_earn($affiliate);
                    } else {
                        $success = is_rtl() ? 'تم اعتماد الشروط.' : 'Your acceptance was recorded.';
                    }
                } else {
                    $success = is_rtl() ? 'تم اعتماد الشروط.' : 'Your acceptance was recorded.';
                }
            } else {
                $error = is_rtl() ? 'تعذّر تسجيل الموافقة. حاول مرة أخرى.' : 'Your acceptance could not be recorded. Please try again.';
            }
        }
    }
}

// The obligations an affiliate is agreeing to. Stated as concrete prohibitions
// rather than generic undertakings, so the page is usable as an actual reference.
$obligations = is_rtl() ? [
    'لا يجوز للمسوّق تقديم استشارة قانونية أو الانتحال بصفة محامٍ أو مستشار قانوني.',
    'لا يجوز الوعد بنتيجة قضائية أو مبلغ أو ضمان قبول أي طلب.',
    'العمولة مستحقة على إيرادات المنصة المؤهلة فقط، ولا تُحتسب على الأتعاب المهنية المنظّمة.',
    'يجب الإفصاح عن الصفة التسويقية بالعمولة في كل محتوى ترويجي.',
    'يجب الالتزام بقواعد الترويج المنشورة في صفحة المواد التسويقية.',
    'تحتفظ المنصة بحق إيقاف الحساب أو حجب عمولة مرتبطة بمخالفة موثّقة.',
] : [
    'An affiliate may not give legal advice or present themselves as a lawyer or legal consultant.',
    'An affiliate may not promise a case outcome, an amount, or that a matter will be accepted.',
    'Commission applies to eligible platform revenue only and is never charged on a regulated professional fee.',
    'An affiliate must disclose their commission-based status in all promotional content.',
    'An affiliate must follow the promotion rules published on the marketing materials page.',
    'The platform may suspend an account or withhold commission connected to a documented breach.',
];
?>
<div class="app-page">
  <div class="app-page-head">
    <div>
      <h1><?= ui_e(is_rtl() ? 'الشروط والالتزام' : 'Terms & compliance') ?></h1>
      <p class="muted"><?= ui_e(is_rtl() ? 'اعتماد الشروط وما يجوز وما لا يجوز ترويجه.' : 'Accept the terms and see what may and may not be promoted.') ?></p>
    </div>
  </div>

  <?php if ($error): ?><div class="form-alert error"><?= ui_e($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="form-alert success"><?= ui_e($success) ?></div><?php endif; ?>

  <div class="aff-terms-status">
    <div class="aff-term <?= $acceptedTerms ? 'done' : 'todo' ?>">
      <span class="aff-term-ico"><?= ui_icon($acceptedTerms ? 'check-circle' : 'alert') ?></span>
      <div>
        <strong><?= ui_e(is_rtl() ? 'شروط برنامج الشركاء' : 'Partner programme terms') ?></strong>
        <span><?= ui_e($acceptedTerms ? (is_rtl() ? 'معتمدة — الإصدار ' : 'Accepted — version ') . AFFILIATE_TERMS_VERSION : (is_rtl() ? 'غير معتمدة' : 'Not accepted')) ?></span>
      </div>
    </div>
    <div class="aff-term <?= $acceptedPrivacy ? 'done' : 'todo' ?>">
      <span class="aff-term-ico"><?= ui_icon($acceptedPrivacy ? 'check-circle' : 'alert') ?></span>
      <div>
        <strong><?= ui_e(is_rtl() ? 'سياسة الخصوصية' : 'Privacy policy') ?></strong>
        <span><?= ui_e($acceptedPrivacy ? (is_rtl() ? 'معتمدة — الإصدار ' : 'Accepted — version ') . AFFILIATE_PRIVACY_VERSION : (is_rtl() ? 'غير معتمدة' : 'Not accepted')) ?></span>
      </div>
    </div>
  </div>

  <section class="app-card">
    <div class="app-card-head"><h2><?= ui_e(is_rtl() ? 'التزامات المسوّق' : 'Your obligations') ?></h2></div>
    <ul class="aff-obligations">
      <?php foreach ($obligations as $item): ?><li><?= ui_e($item) ?></li><?php endforeach; ?>
    </ul>
  </section>

  <section class="app-card">
    <div class="app-card-head"><h2><?= ui_e(is_rtl() ? 'حالة الخدمات من ناحية الالتزام' : 'Service compliance status') ?></h2></div>
    <p class="muted"><?= ui_e(is_rtl()
      ? 'تُحدَّد أهلية كل خدمة للعمولة من قبل فريق الالتزام. الأتعاب المهنية المنظّمة لا تُحتسب عليها عمولة.'
      : 'Each service is cleared for commission by the compliance team. Regulated professional fees never carry commission.') ?></p>
    <div class="table-wrap">
      <table class="admin-table">
        <thead><tr>
          <th><?= ui_e(is_rtl() ? 'الخدمة' : 'Service') ?></th>
          <th><?= ui_e(is_rtl() ? 'نوع الإيراد' : 'Revenue type') ?></th>
          <th><?= ui_e(is_rtl() ? 'الحالة' : 'Status') ?></th>
        </tr></thead>
        <tbody>
          <?php foreach (affiliate_service_catalog($pdo) as $key => $service):
              $mode = affiliate_service_effective_mode($service)['mode'];
              $label = match ($mode) {
                  'commission' => is_rtl() ? 'مؤهلة للعمولة' : 'Commissionable',
                  'attribution_only' => is_rtl() ? 'تتبع بدون عمولة' : 'Tracked, no commission',
                  'analytics_only' => is_rtl() ? 'تحليلات فقط' : 'Analytics only',
                  'prohibited' => is_rtl() ? 'غير مسموح' : 'Not permitted',
                  default => is_rtl() ? 'غير مفعّلة' : 'Not enabled',
              };
          ?>
          <tr>
            <td><?= ui_e(affiliate_service_label($service, $key)) ?></td>
            <td><?= ui_e((string)$service['revenue_source']) ?></td>
            <td><span class="status-pill <?= $mode === 'commission' ? 'status-confirmed' : ($mode === 'prohibited' ? 'status-cancelled' : 'status-pending') ?>"><?= ui_e($label) ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <?php if (!$acceptedTerms || !$acceptedPrivacy): ?>
  <section class="app-card">
    <div class="app-card-head"><h2><?= ui_e(is_rtl() ? 'اعتماد الشروط' : 'Accept the terms') ?></h2></div>
    <form method="post" class="aff-form">
      <input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>">
      <input type="hidden" name="action" value="accept">
      <label class="field-check">
        <input type="checkbox" name="accepted_terms" value="1" required>
        <span><?= ui_e(is_rtl() ? 'أوافق على شروط برنامج الشركاء وأقرّ بالالتزامات المذكورة أعلاه.' : 'I accept the partner programme terms and the obligations listed above.') ?></span>
      </label>
      <label class="field-check">
        <input type="checkbox" name="accepted_privacy" value="1" required>
        <span><?= ui_e(is_rtl() ? 'أوافق على سياسة الخصوصية ومعالجة بياناتي لإدارة البرنامج.' : 'I accept the privacy policy and the processing of my data to administer the programme.') ?></span>
      </label>
      <button class="btn btn-primary" type="submit"><?= ui_e(is_rtl() ? 'اعتماد وتفعيل الحساب' : 'Accept and activate my account') ?></button>
    </form>
  </section>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/affiliate_footer.php'; ?>
