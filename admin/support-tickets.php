<?php
/**
 * تذاكر الدعم — admin support ticket list.
 *
 * Lists every ticket with filters and links to the detail page. Read-only: replies
 * and status changes happen on support-ticket.php so there is one place that writes.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';
require_once __DIR__ . '/includes/admin_ui.php'; // adm() is used above the header include
require_once __DIR__ . '/../includes/support.php';

$filters = [
    'status'      => (string)($_GET['status'] ?? ''),
    'category_id' => (int)($_GET['category_id'] ?? 0),
    'q'           => trim((string)($_GET['q'] ?? '')),
];
$tickets = support_admin_tickets($pdo, $filters);
$categories = support_categories($pdo, false);

// Reuse the console's existing status-pill palette rather than inventing colours:
// open→active (green), pending→pending, answered→processing, closed→completed.
$statusLabels = ['open' => adm('مفتوحة', 'Open'), 'pending' => adm('قيد الانتظار', 'Pending'),
    'answered' => adm('تم الرد', 'Answered'), 'closed' => adm('مغلقة', 'Closed')];
$statusClass = ['open' => 'status-active', 'pending' => 'status-pending',
    'answered' => 'status-processing', 'closed' => 'status-completed'];

$activeAdmin = 'support-tickets';
require_once __DIR__ . '/includes/admin_header.php';
?>
<div class="app-content">
  <div class="toolbar">
    <h2><?= adm('تذاكر الدعم', 'Support tickets') ?> <span class="count-badge"><?= count($tickets) ?></span></h2>
  </div>

  <form method="get" class="dash-card" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;margin-bottom:16px">
    <div class="field" style="min-width:150px">
      <label><?= adm('الحالة', 'Status') ?></label>
      <select name="status">
        <option value=""><?= adm('الكل', 'All') ?></option>
        <?php foreach ($statusLabels as $k => $lbl): ?>
          <option value="<?= h($k) ?>" <?= $filters['status'] === $k ? 'selected' : '' ?>><?= h($lbl) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field" style="min-width:170px">
      <label><?= adm('الفئة', 'Category') ?></label>
      <select name="category_id">
        <option value="0"><?= adm('الكل', 'All') ?></option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $filters['category_id'] === (int)$c['id'] ? 'selected' : '' ?>>
            <?= h(is_rtl() ? $c['name_ar'] : $c['name_en']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field" style="min-width:200px">
      <label><?= adm('بحث', 'Search') ?></label>
      <input type="text" name="q" value="<?= h($filters['q']) ?>" placeholder="<?= adm('الرقم أو الموضوع', 'Number or subject') ?>">
    </div>
    <button class="btn btn-primary" type="submit"><?= adm('تصفية', 'Filter') ?></button>
    <a class="btn btn-outline" href="/admin/support-tickets.php"><?= adm('مسح', 'Clear') ?></a>
  </form>

  <div class="dash-card">
    <?php if (!$tickets): ?>
      <p style="color:var(--text-muted)"><?= adm('لا توجد تذاكر مطابقة.', 'No matching tickets.') ?></p>
    <?php else: ?>
      <div class="table-wrap table-cards">
        <table class="admin-table">
          <thead><tr>
            <th><?= adm('الرقم', 'Number') ?></th>
            <th><?= adm('الموضوع', 'Subject') ?></th>
            <th><?= adm('الفئة', 'Category') ?></th>
            <th><?= adm('الحالة', 'Status') ?></th>
            <th><?= adm('آخر تحديث', 'Updated') ?></th>
            <th></th>
          </tr></thead>
          <tbody>
            <?php foreach ($tickets as $t): ?>
              <tr>
                <td data-label="<?= adm('الرقم', 'Number') ?>"><?= h($t['ticket_number']) ?></td>
                <td data-label="<?= adm('الموضوع', 'Subject') ?>"><?= h($t['subject']) ?></td>
                <td data-label="<?= adm('الفئة', 'Category') ?>"><?= h(is_rtl() ? ($t['category_ar'] ?? '') : ($t['category_en'] ?? '')) ?></td>
                <td data-label="<?= adm('الحالة', 'Status') ?>">
                  <span class="status-pill <?= h($statusClass[$t['status']] ?? 'status-pending') ?>"><?= h($statusLabels[$t['status']] ?? $t['status']) ?></span>
                  <?php if ((int)$t['unread_for_support'] === 1): ?>
                    <span class="badge badge-warning"><?= adm('جديد', 'New') ?></span>
                  <?php endif; ?>
                </td>
                <td data-label="<?= adm('آخر تحديث', 'Updated') ?>"><?= h($t['updated_at']) ?></td>
                <td data-label="">
                  <a class="btn btn-outline btn-sm" href="/admin/support-ticket.php?id=<?= (int)$t['id'] ?>"><?= adm('عرض', 'Open') ?></a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
