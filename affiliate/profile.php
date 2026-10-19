<?php
/**
 * Affiliate profile (Execution 31).
 *
 * Contact details, payout preference, and a password change. The email is not
 * editable here: it is the account identifier and the destination for payout
 * correspondence, so changing it is an administrative action with an audit trail.
 *
 * A password change requires the current password and ends every other session,
 * because the reason to change a password is usually that somebody else may have it.
 */
$activeAffiliate = 'profile';
require_once __DIR__ . '/../includes/affiliate_header.php';
require_once __DIR__ . '/../includes/affiliate_auth.php';
require_once __DIR__ . '/../includes/affiliate_payout.php';


$affiliateId = (int)$affiliate['id'];
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $string = static function (string $key, string $default = ''): string {
        $value = $_POST[$key] ?? $default;
        return is_string($value) ? trim($value) : $default;
    };
    $action = $string('action');

    if ($action === 'profile') {
        try {
            $pdo->prepare('UPDATE affiliates SET full_name=?, phone=?, country=?, city=?, website=?, social_links=?, company_name=?, commercial_registration=?, marketing_channels=?, promotion_methods=? WHERE id=?')
                ->execute([
                    mb_substr($string('full_name', (string)$affiliate['full_name']), 0, 180, 'UTF-8') ?: (string)$affiliate['full_name'],
                    affiliate_normalize_phone($string('phone')),
                    strtoupper(substr($string('country', (string)$affiliate['country']), 0, 2)) ?: (string)$affiliate['country'],
                    mb_substr($string('city'), 0, 80, 'UTF-8') ?: null,
                    mb_substr($string('website'), 0, 255, 'UTF-8') ?: null,
                    mb_substr($string('social_links'), 0, 1000, 'UTF-8') ?: null,
                    mb_substr($string('company_name'), 0, 190, 'UTF-8') ?: null,
                    mb_substr($string('commercial_registration'), 0, 60, 'UTF-8') ?: null,
                    mb_substr($string('marketing_channels'), 0, 1000, 'UTF-8') ?: null,
                    mb_substr($string('promotion_methods'), 0, 1000, 'UTF-8') ?: null,
                    $affiliateId,
                ]);
            $success = is_rtl() ? 'تم حفظ البيانات.' : 'Your details were saved.';
            $affiliate = affiliate_find($pdo, $affiliateId) ?? $affiliate;
            affiliate_audit($pdo, 'profile_updated', ['affiliate_id' => $affiliateId, 'subject_type' => 'affiliate', 'subject_id' => $affiliateId]);
        } catch (Throwable $e) {
            error_log('affiliate profile update: ' . $e->getMessage());
            $error = is_rtl() ? 'تعذّر حفظ البيانات.' : 'Your details could not be saved.';
        }
    } elseif ($action === 'password') {
        $current = is_string($_POST['current_password'] ?? null) ? $_POST['current_password'] : '';
        $new = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
        $confirm = is_string($_POST['new_password_confirm'] ?? null) ? $_POST['new_password_confirm'] : '';

        if (!password_verify($current, (string)$affiliate['password_hash'])) {
            $error = is_rtl() ? 'كلمة المرور الحالية غير صحيحة.' : 'Your current password is incorrect.';
        } else {
            $check = affiliate_validate_password($new, $confirm);
            if (empty($check['ok'])) {
                $error = match ((string)$check['error']) {
                    'password_too_short' => is_rtl() ? 'كلمة المرور يجب أن تكون 8 أحرف على الأقل.' : 'The password must be at least 8 characters.',
                    'password_too_long' => is_rtl() ? 'كلمة المرور طويلة جداً.' : 'The password is too long.',
                    default => is_rtl() ? 'كلمتا المرور غير متطابقتين.' : 'The passwords do not match.',
                };
            } else {
                try {
                    $pdo->prepare('UPDATE affiliates SET password_hash=?, password_changed_at=NOW() WHERE id=?')
                        ->execute([affiliate_hash_password($new), $affiliateId]);
                    // Keep this session, end the others: the affiliate is present, so
                    // they are the one who asked for the change.
                    affiliate_establish_session($affiliate);
                    $success = is_rtl() ? 'تم تغيير كلمة المرور، وتم إنهاء الجلسات الأخرى.' : 'Your password was changed and other sessions were ended.';
                    affiliate_audit($pdo, 'password_changed', ['affiliate_id' => $affiliateId, 'subject_type' => 'affiliate', 'subject_id' => $affiliateId]);
                } catch (Throwable $e) {
                    error_log('affiliate password change: ' . $e->getMessage());
                    $error = is_rtl() ? 'تعذّر تغيير كلمة المرور.' : 'Your password could not be changed.';
                }
            }
        }
    }
}

$agreements = [];
try {
    $stmt = $pdo->prepare('SELECT * FROM affiliate_agreements WHERE affiliate_id=? ORDER BY id DESC LIMIT 20');
    $stmt->execute([$affiliateId]);
    $agreements = $stmt->fetchAll();
} catch (Throwable $e) { /* pre-v57 database */ }
?>
<div class="app-page">
  <div class="app-page-head">
    <div>
      <h1><?= ui_e(is_rtl() ? 'الملف الشخصي' : 'Profile') ?></h1>
      <p class="muted"><?= ui_e(is_rtl() ? 'بياناتك ورابط الحساب وكلمة المرور.' : 'Your details, your account code, and your password.') ?></p>
    </div>
    <span class="aff-code-pill aff-code-lg"><?= ui_e((string)$affiliate['code']) ?></span>
  </div>

  <?php if ($error): ?><div class="form-alert error"><?= ui_e($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="form-alert success"><?= ui_e($success) ?></div><?php endif; ?>

  <section class="app-card">
    <div class="app-card-head"><h2><?= ui_e(is_rtl() ? 'البيانات' : 'Details') ?></h2></div>
    <form method="post" class="aff-form">
      <input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>">
      <input type="hidden" name="action" value="profile">
      <div class="aff-grid">
        <label class="field"><span><?= ui_e(is_rtl() ? 'الاسم الكامل' : 'Full name') ?></span>
          <input type="text" name="full_name" maxlength="180" value="<?= ui_e((string)$affiliate['full_name']) ?>"></label>
        <label class="field"><span><?= ui_e(is_rtl() ? 'البريد الإلكتروني' : 'Email') ?></span>
          <input type="email" value="<?= ui_e((string)$affiliate['email']) ?>" readonly disabled>
          <small><?= ui_e(is_rtl() ? 'لتغيير البريد تواصل مع فريق البرنامج.' : 'Contact the programme team to change your email.') ?></small></label>
        <label class="field"><span><?= ui_e(is_rtl() ? 'رقم الجوال' : 'Phone') ?></span>
          <input type="tel" name="phone" maxlength="20" value="<?= ui_e((string)($affiliate['phone'] ?? '')) ?>"></label>
        <label class="field"><span><?= ui_e(is_rtl() ? 'الدولة' : 'Country') ?></span>
          <?php render_country_select('country', (string)($affiliate['country'] ?? '')); ?></label>
        <label class="field"><span><?= ui_e(is_rtl() ? 'المدينة' : 'City') ?></span>
          <input type="text" name="city" maxlength="80" value="<?= ui_e((string)($affiliate['city'] ?? '')) ?>"></label>
        <label class="field"><span><?= ui_e(is_rtl() ? 'الموقع الإلكتروني' : 'Website') ?></span>
          <input type="url" name="website" maxlength="255" value="<?= ui_e((string)($affiliate['website'] ?? '')) ?>"></label>
        <label class="field"><span><?= ui_e(is_rtl() ? 'اسم الشركة' : 'Company name') ?></span>
          <input type="text" name="company_name" maxlength="190" value="<?= ui_e((string)($affiliate['company_name'] ?? '')) ?>"></label>
        <label class="field"><span><?= ui_e(is_rtl() ? 'السجل التجاري' : 'Commercial registration') ?></span>
          <input type="text" name="commercial_registration" maxlength="60" value="<?= ui_e((string)($affiliate['commercial_registration'] ?? '')) ?>"></label>
      </div>
      <label class="field"><span><?= ui_e(is_rtl() ? 'روابط التواصل' : 'Social links') ?></span>
        <input type="text" name="social_links" maxlength="1000" value="<?= ui_e((string)($affiliate['social_links'] ?? '')) ?>"></label>
      <label class="field"><span><?= ui_e(is_rtl() ? 'القنوات التسويقية' : 'Marketing channels') ?></span>
        <input type="text" name="marketing_channels" maxlength="1000" value="<?= ui_e((string)($affiliate['marketing_channels'] ?? '')) ?>"></label>
      <label class="field"><span><?= ui_e(is_rtl() ? 'أساليب الترويج' : 'Promotion methods') ?></span>
        <textarea name="promotion_methods" rows="3" maxlength="1000"><?= ui_e((string)($affiliate['promotion_methods'] ?? '')) ?></textarea></label>
      <button class="btn btn-primary" type="submit"><?= ui_e(is_rtl() ? 'حفظ' : 'Save') ?></button>
    </form>
  </section>

  <section class="app-card">
    <div class="app-card-head"><h2><?= ui_e(is_rtl() ? 'تغيير كلمة المرور' : 'Change password') ?></h2></div>
    <form method="post" class="aff-form">
      <input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>">
      <input type="hidden" name="action" value="password">
      <div class="aff-grid">
        <label class="field"><span><?= ui_e(is_rtl() ? 'كلمة المرور الحالية' : 'Current password') ?></span>
          <input type="password" name="current_password" required autocomplete="current-password"></label>
        <label class="field"><span><?= ui_e(is_rtl() ? 'كلمة المرور الجديدة' : 'New password') ?></span>
          <input type="password" name="new_password" required minlength="8" maxlength="72" autocomplete="new-password"></label>
        <label class="field"><span><?= ui_e(is_rtl() ? 'تأكيد كلمة المرور' : 'Confirm new password') ?></span>
          <input type="password" name="new_password_confirm" required minlength="8" maxlength="72" autocomplete="new-password"></label>
      </div>
      <button class="btn btn-primary" type="submit"><?= ui_e(is_rtl() ? 'تغيير كلمة المرور' : 'Change password') ?></button>
    </form>
  </section>

  <section class="app-card">
    <div class="app-card-head"><h2><?= ui_e(is_rtl() ? 'الموافقات' : 'Agreements') ?></h2></div>
    <?php if (!$agreements): ?>
      <?= ui_empty_state(is_rtl() ? 'لا توجد موافقات مسجّلة' : 'No agreements recorded', '', 'shield') ?>
    <?php else: ?>
      <div class="table-wrap">
        <table class="admin-table">
          <thead><tr>
            <th><?= ui_e(is_rtl() ? 'النوع' : 'Type') ?></th>
            <th><?= ui_e(is_rtl() ? 'الإصدار' : 'Version') ?></th>
            <th><?= ui_e(is_rtl() ? 'تاريخ القبول' : 'Accepted') ?></th>
          </tr></thead>
          <tbody>
            <?php foreach ($agreements as $row): ?>
              <tr>
                <td><?= ui_e($row['agreement_type'] === 'privacy' ? (is_rtl() ? 'سياسة الخصوصية' : 'Privacy policy') : (is_rtl() ? 'شروط البرنامج' : 'Programme terms')) ?></td>
                <td class="aff-mono"><?= ui_e((string)$row['version']) ?></td>
                <td><?= ui_e(date('Y-m-d', strtotime((string)$row['accepted_at']))) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>
<?php require_once __DIR__ . '/../includes/affiliate_footer.php'; ?>
