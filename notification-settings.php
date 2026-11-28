<?php
/**
 * Notification settings (Execution 72).
 *
 * The three channels — in-app, email and push — with a real, enforced toggle: turning
 * one off stops that channel's send (see notification_dispatch()), it does not merely
 * repaint the switch. Transactional account mail (verification, password reset) is
 * exempt by design, so a customer can never silence a security message by accident.
 *
 * The POST handler runs above the shell include: app_header.php prints <head>
 * immediately with no buffering, so a header() redirect issued after it is dropped.
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lang/strings.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/customer_features.php';

$clientId = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? '')) { http_response_code(403); exit('Invalid CSRF token'); }
    $channels = ['in_app', 'email', 'push'];
    foreach ($channels as $ch) {
        // A checkbox that is absent means "off" — that is how an unchecked box submits.
        notification_preference_set($pdo, 'client', $clientId, $ch, !empty($_POST['ch_' . $ch]));
    }
    header('Location: /notification-settings.php?saved=1');
    exit;
}

$activePage = 'notification-settings';
require_once __DIR__ . '/includes/app_header.php';

$channels = notification_preferences_payload($pdo, 'client', $clientId);
$deviceCount = device_token_count($pdo, 'client', $clientId);
?>
<div class="app-content">
  <h1><?= is_rtl() ? 'إعدادات الإشعارات' : 'Notification settings' ?></h1>
  <p style="margin-bottom:20px"><?= is_rtl() ? 'اختر كيف تصلك التحديثات. الإشعارات الأمنية للحساب تُرسل دائمًا ولا يمكن إيقافها.' : 'Choose how updates reach you. Account-security messages are always sent and cannot be turned off.' ?></p>

  <?php if (isset($_GET['saved'])): ?><div class="form-alert success"><?= is_rtl() ? 'تم حفظ الإعدادات.' : 'Settings saved.' ?></div><?php endif; ?>

  <form class="dash-card" method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
    <?php foreach ($channels as $ch): ?>
      <label class="notif-row<?= !empty($ch['locked']) ? ' locked' : '' ?>">
        <input type="checkbox" name="ch_<?= htmlspecialchars($ch['key']) ?>" value="1"
               <?= $ch['enabled'] ? 'checked' : '' ?> <?= !empty($ch['locked']) ? 'disabled' : '' ?>>
        <span class="notif-row-text">
          <strong><?= htmlspecialchars(is_rtl() ? $ch['label_ar'] : $ch['label_en']) ?>
            <?php if (!empty($ch['locked'])): ?><small class="notif-locked"><?= is_rtl() ? '(دائم)' : '(always on)' ?></small><?php endif; ?>
          </strong>
          <small><?= htmlspecialchars(is_rtl() ? $ch['description_ar'] : $ch['description_en']) ?></small>
        </span>
      </label>
    <?php endforeach; ?>
    <p class="muted-sm">
      <?= is_rtl()
        ? 'الأجهزة المسجّلة للإشعارات الفورية: ' . (int)$deviceCount
        : 'Registered push devices: ' . (int)$deviceCount ?>
    </p>
    <button class="btn btn-primary" type="submit"><?= is_rtl() ? 'حفظ الإعدادات' : 'Save settings' ?></button>
  </form>
</div>
<?php require_once __DIR__ . '/includes/app_footer.php'; ?>
