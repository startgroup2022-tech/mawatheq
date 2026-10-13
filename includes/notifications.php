<?php
/**
 * إشعارات داخل المنصة (Communications) — إشعار فوري يظهر للعميل أو المحامي
 * عند حدث مهم (فاتورة جديدة، حجز استشارة، تحديث قضية، اعتماد حساب...).
 * لا يوجد بريد/واتساب/SMS خارجي هنا — فقط إشعارات داخلية حقيقية ومخزّنة.
 */
function notify(PDO $pdo, string $recipientType, int $recipientId, string $title, ?string $message = null, ?string $link = null): void {
    if ($recipientId <= 0) return;
    $pdo->prepare('INSERT INTO notifications (recipient_type, recipient_id, title, message, link) VALUES (?,?,?,?,?)')
        ->execute([$recipientType, $recipientId, $title, $message, $link]);
}

/**
 * Notify the firm tenant of an event, skipping the lawyer who caused it (nobody needs a
 * notification about their own action). Solo lawyers are notified directly; firm members
 * are notified through their firm-wide feed.
 */
function notify_tenant_except(PDO $pdo, ?int $firmId, int $lawyerId, int $exceptLawyerId, string $title, ?string $message = null, ?string $link = null): void {
    if ($firmId) {
        $stmt = $pdo->prepare("SELECT lawyer_id FROM law_firm_members WHERE firm_id=? AND status='active' AND lawyer_id<>?");
        $stmt->execute([$firmId, $exceptLawyerId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $memberId) {
            notify($pdo, 'lawyer', (int)$memberId, $title, $message, $link);
        }
    } elseif ($lawyerId > 0 && $lawyerId !== $exceptLawyerId) {
        notify($pdo, 'lawyer', $lawyerId, $title, $message, $link);
    }
}

function unread_notifications_count(PDO $pdo, string $recipientType, int $recipientId): int {
    $stmt = $pdo->prepare('SELECT COUNT(*) c FROM notifications WHERE recipient_type=? AND recipient_id=? AND is_read=0');
    $stmt->execute([$recipientType, $recipientId]);
    return (int)$stmt->fetch()['c'];
}

function get_notifications(PDO $pdo, string $recipientType, int $recipientId, int $limit = 20): array {
    $stmt = $pdo->prepare('SELECT * FROM notifications WHERE recipient_type=? AND recipient_id=? ORDER BY created_at DESC LIMIT ?');
    $stmt->bindValue(1, $recipientType);
    $stmt->bindValue(2, $recipientId, PDO::PARAM_INT);
    $stmt->bindValue(3, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function mark_all_notifications_read(PDO $pdo, string $recipientType, int $recipientId): void {
    $pdo->prepare('UPDATE notifications SET is_read=1 WHERE recipient_type=? AND recipient_id=?')->execute([$recipientType, $recipientId]);
}

/** يطبع أيقونة جرس + قائمة منسدلة بالإشعارات، تُستخدم بهيدر لوحة العميل/المحامي. */
function render_notification_bell(PDO $pdo, string $recipientType, int $recipientId): void {
    $rtl = is_rtl();
    $unread = unread_notifications_count($pdo, $recipientType, $recipientId);
    $items = get_notifications($pdo, $recipientType, $recipientId, 8);
    // Unique per instance so two bells on one page cannot clash over the id.
    static $seq = 0;
    $seq++;
    $dropdownId = 'notifDropdown' . $seq;
    $label = $rtl ? 'الإشعارات' : 'Notifications';
    ?>
    <div class="notif-bell-wrap" data-dropdown>
      <button type="button" class="notif-bell-btn" data-dropdown-toggle
              aria-haspopup="true" aria-expanded="false" aria-controls="<?= $dropdownId ?>"
              aria-label="<?= $label ?><?= $unread > 0 ? ' — ' . $unread . ' ' . ($rtl ? 'غير مقروء' : 'unread') : '' ?>">
        <?php if (function_exists('ui_icon')): ?><?= ui_icon('bell') ?><?php else: ?>🔔<?php endif; ?>
        <?php if ($unread > 0): ?><span class="notif-count" aria-hidden="true"><?= $unread > 9 ? '9+' : (int)$unread ?></span><?php endif; ?>
      </button>
      <div class="notif-dropdown" id="<?= $dropdownId ?>" data-dropdown-panel>
        <div class="notif-dropdown-head">
          <strong><?= $label ?></strong>
          <?php if ($unread > 0): ?>
            <form method="post" action="/mark-notifications-read.php" style="margin:0"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><button type="submit" class="btn btn-ghost btn-sm"><?= $rtl ? 'تحديد الكل كمقروء' : 'Mark all read' ?></button></form>
          <?php endif; ?>
        </div>
        <?php if (empty($items)): ?>
          <p class="muted-sm" style="padding:20px;text-align:center"><?= $rtl ? 'لا توجد إشعارات بعد.' : 'No notifications yet.' ?></p>
        <?php else: foreach ($items as $n): ?>
          <a href="<?= htmlspecialchars($n['link'] ?: '#') ?>" class="notif-item <?= $n['is_read'] ? '' : 'is-unread' ?>">
            <strong><?= htmlspecialchars($n['title']) ?></strong>
            <?php if ($n['message']): ?><p><?= htmlspecialchars($n['message']) ?></p><?php endif; ?>
            <span><?= date('Y-m-d H:i', strtotime($n['created_at'])) ?></span>
          </a>
        <?php endforeach; endif; ?>
      </div>
    </div>
    <?php
}
