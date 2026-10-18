<?php
/**
 * Notifications (Execution 31).
 *
 * The in-app log for the affiliate. Marking read is scoped by affiliate_id in the
 * WHERE clause, so an affiliate cannot mark another's notification read by guessing
 * an id.
 */
$activeAffiliate = 'notifications';
require_once __DIR__ . '/../includes/affiliate_header.php';


$affiliateId = (int)$affiliate['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $string = static function (string $key, string $default = ''): string {
        $value = $_POST[$key] ?? $default;
        return is_string($value) ? trim($value) : $default;
    };
    $action = $string('action');
    try {
        if ($action === 'read_all') {
            $pdo->prepare('UPDATE affiliate_notification_log SET read_at=NOW() WHERE affiliate_id=? AND read_at IS NULL')->execute([$affiliateId]);
        } elseif ($action === 'read') {
            $pdo->prepare('UPDATE affiliate_notification_log SET read_at=NOW() WHERE id=? AND affiliate_id=? AND read_at IS NULL')
                ->execute([(int)$string('notification_id'), $affiliateId]);
        }
    } catch (Throwable $e) {
        error_log('affiliate notification update: ' . $e->getMessage());
    }
    header('Location: /affiliate/notifications.php');
    exit;
}

$notifications = [];
$unread = 0;
try {
    $stmt = $pdo->prepare('SELECT * FROM affiliate_notification_log WHERE affiliate_id=? AND channel=? ORDER BY id DESC LIMIT 200');
    $stmt->execute([$affiliateId, 'in_app']);
    $notifications = $stmt->fetchAll();
    foreach ($notifications as $row) { if (empty($row['read_at'])) $unread++; }
} catch (Throwable $e) { /* pre-v57 database */ }

$typeLabels = [
    'commission_created' => is_rtl() ? 'عمولة جديدة' : 'New commission',
    'conversion_recorded' => is_rtl() ? 'تحويل مسجّل' : 'Conversion recorded',
    'commission_refunded' => is_rtl() ? 'تسوية عمولة' : 'Commission adjusted',
    'payout_requested' => is_rtl() ? 'طلب صرف' : 'Payout requested',
    'payout_status' => is_rtl() ? 'حالة الصرف' : 'Payout status',
    'account_activated' => is_rtl() ? 'تفعيل الحساب' : 'Account activated',
    'account_suspended' => is_rtl() ? 'إيقاف الحساب' : 'Account suspended',
];
?>
<div class="app-page">
  <div class="app-page-head">
    <div>
      <h1><?= ui_e(is_rtl() ? 'الإشعارات' : 'Notifications') ?></h1>
      <p class="muted"><?= ui_e($unread > 0
        ? ($unread . ' ' . (is_rtl() ? 'إشعار غير مقروء' : 'unread'))
        : (is_rtl() ? 'كل الإشعارات مقروءة.' : 'All notifications are read.')) ?></p>
    </div>
    <?php if ($unread > 0): ?>
      <form method="post" class="inline-form">
        <input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>">
        <input type="hidden" name="action" value="read_all">
        <button class="btn btn-outline" type="submit"><?= ui_e(is_rtl() ? 'تعليم الكل كمقروء' : 'Mark all read') ?></button>
      </form>
    <?php endif; ?>
  </div>

  <?php if (!$notifications): ?>
    <div class="app-card"><?= ui_empty_state(is_rtl() ? 'لا توجد إشعارات' : 'No notifications', '', 'bell') ?></div>
  <?php else: ?>
    <div class="aff-notif-list">
      <?php foreach ($notifications as $row): $isUnread = empty($row['read_at']); ?>
        <div class="aff-notif <?= $isUnread ? 'unread' : '' ?>">
          <div class="aff-notif-body">
            <div class="aff-notif-head">
              <span class="aff-notif-type"><?= ui_e($typeLabels[(string)$row['notification_type']] ?? (string)$row['notification_type']) ?></span>
              <span class="aff-notif-date"><?= ui_e(date('Y-m-d H:i', strtotime((string)$row['created_at']))) ?></span>
            </div>
            <strong><?= ui_e((string)$row['title']) ?></strong>
            <?php if (!empty($row['body'])): ?><p><?= ui_e((string)$row['body']) ?></p><?php endif; ?>
            <?php if (!empty($row['link'])): ?><a class="link-more" href="<?= ui_e((string)$row['link']) ?>"><?= ui_e(is_rtl() ? 'عرض' : 'View') ?></a><?php endif; ?>
          </div>
          <?php if ($isUnread): ?>
            <form method="post" class="inline-form">
              <input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>">
              <input type="hidden" name="action" value="read">
              <input type="hidden" name="notification_id" value="<?= (int)$row['id'] ?>">
              <button class="btn btn-outline btn-xs" type="submit" aria-label="<?= ui_e(is_rtl() ? 'تعليم كمقروء' : 'Mark as read') ?>"><?= ui_icon('check') ?></button>
            </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/affiliate_footer.php'; ?>
