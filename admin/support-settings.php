<?php
/**
 * إعدادات الدعم — admin support channel configuration.
 *
 * The single place the support channels are configured. The values are read by
 * api/v1/support.php and rendered in the app's Account → Support screen, so a change
 * here takes effect everywhere without a redeploy. An empty email/WhatsApp value
 * reads as "channel not configured" and the app omits it rather than showing a dead
 * button — which is why the form warns when a channel is enabled but empty.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';
require_once __DIR__ . '/includes/admin_ui.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/support.php';

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) { http_response_code(403); exit('Invalid request'); }
    try {
        $email = trim((string)($_POST['support_email'] ?? ''));
        $wa = preg_replace('/\D+/', '', (string)($_POST['support_whatsapp'] ?? '')) ?? '';

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = adm('البريد الإلكتروني غير صالح.', 'The support email is not valid.');
        } else {
            $settings = [
                'support_email'                 => $email,
                'support_whatsapp'              => $wa,
                'support_email_enabled'         => isset($_POST['support_email_enabled']) ? '1' : '0',
                'support_whatsapp_enabled'      => isset($_POST['support_whatsapp_enabled']) ? '1' : '0',
                'support_tickets_enabled'       => isset($_POST['support_tickets_enabled']) ? '1' : '0',
                'support_appointments_enabled'  => isset($_POST['support_appointments_enabled']) ? '1' : '0',
                'support_hours_ar'              => trim((string)($_POST['support_hours_ar'] ?? '')),
                'support_hours_en'              => trim((string)($_POST['support_hours_en'] ?? '')),
            ];
            $stmt = $pdo->prepare('INSERT INTO site_settings (setting_key, setting_value) VALUES (?,?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
            foreach ($settings as $k => $v) $stmt->execute([$k, $v]);
            support_settings_forget();
            log_action($pdo, 'support_settings_save', 'site_settings', null);
            $success = adm('تم حفظ إعدادات الدعم.', 'Support settings saved.');
        }
    } catch (Throwable $e) {
        error_log('admin support-settings save: ' . $e->getMessage());
        $error = adm('تعذّر حفظ الإعدادات.', 'Could not save the settings.');
    }
}

$s = support_settings($pdo);
$val = fn(string $k) => htmlspecialchars((string)($s[$k] ?? ''), ENT_QUOTES, 'UTF-8');
$on = fn(string $k) => ($s[$k] ?? '1') === '1';

$activeAdmin = 'support-settings';
require_once __DIR__ . '/includes/admin_header.php';
?>
<div class="app-content">
  <div class="toolbar"><h2><?= adm('إعدادات الدعم', 'Support settings') ?></h2></div>

  <?php if ($success): ?><div class="form-alert success"><?= h($success) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="form-alert error"><?= h($error) ?></div><?php endif; ?>

  <form method="post" class="dash-card">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">

    <h3><?= adm('قنوات التواصل', 'Contact channels') ?></h3>
    <p style="color:var(--text-muted);font-size:.85rem;margin-bottom:12px">
      <?= adm('القناة تظهر في التطبيق فقط عندما تكون مفعّلة ولها قيمة. اترك القيمة فارغة لإخفائها.', 'A channel shows in the app only when it is enabled and has a value. Leave the value empty to hide it.') ?>
    </p>

    <div class="field" style="margin-bottom:10px">
      <label><input type="checkbox" name="support_email_enabled" <?= $on('support_email_enabled') ? 'checked' : '' ?>> <?= adm('تفعيل البريد الإلكتروني', 'Enable email') ?></label>
      <input type="email" name="support_email" value="<?= $val('support_email') ?>" placeholder="support@example.com">
    </div>

    <div class="field" style="margin-bottom:10px">
      <label><input type="checkbox" name="support_whatsapp_enabled" <?= $on('support_whatsapp_enabled') ? 'checked' : '' ?>> <?= adm('تفعيل واتساب', 'Enable WhatsApp') ?></label>
      <input type="text" name="support_whatsapp" value="<?= $val('support_whatsapp') ?>" placeholder="9665xxxxxxxx" inputmode="numeric">
      <small style="color:var(--text-muted)"><?= adm('بصيغة دولية كاملة بدون + (مثال: 9665xxxxxxxx).', 'Full international format without + (e.g. 9665xxxxxxxx).') ?></small>
    </div>

    <h3 style="margin-top:20px"><?= adm('مركز الدعم داخل التطبيق', 'In-app support centre') ?></h3>
    <div class="field" style="margin-bottom:10px">
      <label><input type="checkbox" name="support_tickets_enabled" <?= $on('support_tickets_enabled') ? 'checked' : '' ?>> <?= adm('تفعيل تذاكر الدعم', 'Enable support tickets') ?></label>
    </div>
    <div class="field" style="margin-bottom:10px">
      <label><input type="checkbox" name="support_appointments_enabled" <?= $on('support_appointments_enabled') ? 'checked' : '' ?>> <?= adm('تفعيل مواعيد الدعم', 'Enable support appointments') ?></label>
    </div>

    <h3 style="margin-top:20px"><?= adm('ساعات العمل', 'Working hours') ?></h3>
    <div class="field" style="margin-bottom:10px">
      <label><?= adm('بالعربية', 'In Arabic') ?></label>
      <input type="text" name="support_hours_ar" value="<?= $val('support_hours_ar') ?>" maxlength="255">
    </div>
    <div class="field" style="margin-bottom:10px">
      <label><?= adm('بالإنجليزية', 'In English') ?></label>
      <input type="text" name="support_hours_en" value="<?= $val('support_hours_en') ?>" maxlength="255">
    </div>

    <button class="btn btn-primary" type="submit" style="margin-top:14px"><?= adm('حفظ', 'Save') ?></button>
  </form>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
