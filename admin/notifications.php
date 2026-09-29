<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';
$activeAdmin = 'dashboard'; $pageTitle = '';
require_once __DIR__ . '/includes/admin_header.php';
$pageFilter = ($_GET['f'] ?? '') === 'unread' ? 'unread' : 'all';
$sql = "SELECT * FROM notifications WHERE recipient_type='admin' AND recipient_id=?" . ($pageFilter === 'unread' ? ' AND is_read=0' : '') . ' ORDER BY id DESC LIMIT 100';
try { $s = $pdo->prepare($sql); $s->execute([(int)$_SESSION['admin_id']]); $items = $s->fetchAll(); } catch (Throwable $e) { $items = []; }
?>
<div class="app-content">
  <div class="pg-head"><div><h1><?= h(adm('الإشعارات', 'Notifications')) ?></h1><p><?= h(adm('كل تنبيهات المنصة الموجّهة إليك', 'Every platform alert addressed to you')) ?></p></div>
    <div class="pg-tools"><div class="chips"><a class="chip <?= $pageFilter === 'all' ? 'on' : '' ?>" href="?f=all"><?= h(adm('الكل', 'All')) ?></a><a class="chip <?= $pageFilter === 'unread' ? 'on' : '' ?>" href="?f=unread"><?= h(adm('غير المقروءة', 'Unread')) ?></a></div><a class="btn btn-outline" href="/mark-notifications-read.php"><?= h(adm('تحديد الكل كمقروء', 'Mark all read')) ?></a></div></div>
  <div class="card"><ul class="rowlist">
    <?php foreach ($items as $n): ?><li><span class="kpi-ic <?= $n['is_read'] ? '' : 'gold' ?>"><?= adm_icon('bell') ?></span><div class="grow"><b><?= h($n['title']) ?></b><small><?= h($n['message']) ?></small></div><small style="color:var(--muted);white-space:nowrap"><?= h(date('Y-m-d H:i', strtotime($n['created_at']))) ?></small><?php if ($n['link']): ?><a class="btn btn-outline btn-sm" href="<?= h($n['link']) ?>"><?= h(adm('فتح', 'Open')) ?></a><?php endif; ?></li><?php endforeach; ?>
    <?php if (!$items): ?><li class="empty" style="justify-content:center"><?= h(adm('لا توجد إشعارات', 'No notifications')) ?></li><?php endif; ?></ul></div>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
