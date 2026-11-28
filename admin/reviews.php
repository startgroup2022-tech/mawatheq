<?php
/**
 * Admin → Lawyer reviews (Execution 72).
 *
 * The moderation queue for published ratings. Nothing here is automatic: a rating is
 * only ever written by a verified consultation (in the service layer), and a human
 * decides whether a flagged review is hidden or removed. Removing keeps the row for the
 * audit trail but excludes it from the lawyer's public average.
 */
$activeAdmin = 'reviews';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/customer_features.php';
$pageTitle = is_rtl() ? 'تقييمات المحامين' : 'Lawyer reviews';
require_once __DIR__ . '/includes/admin_header.php';

$saved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? '')) { http_response_code(403); exit('Invalid CSRF token'); }
    if (($_POST['action'] ?? '') === 'set_status') {
        $status = (string)($_POST['status'] ?? '');
        $note = trim((string)($_POST['admin_note'] ?? ''));
        if (review_set_status($pdo, (int)($_POST['review_id'] ?? 0), $status, $note !== '' ? $note : null)) {
            log_action($pdo, 'تعديل حالة تقييم إلى ' . $status, 'lawyer_review', (int)$_POST['review_id']);
            $saved = true;
        }
    }
}

$statusFilter = (string)($_GET['status'] ?? '');
$reviews = reviews_for_admin($pdo, $statusFilter);
$csrf = csrf_token();
$statusLabels = [
    'published' => is_rtl() ? 'منشور' : 'Published',
    'pending'   => is_rtl() ? 'قيد المراجعة' : 'Pending',
    'hidden'    => is_rtl() ? 'مخفي' : 'Hidden',
    'removed'   => is_rtl() ? 'محذوف' : 'Removed',
];
?>
<div class="app-content">
  <h1><?= is_rtl() ? 'تقييمات المحامين' : 'Lawyer reviews' ?></h1>
  <p style="margin-bottom:16px"><?= is_rtl() ? 'التقييمات موثّقة: لا تُقبل إلا من استشارة مكتملة، مرة واحدة لكل استشارة.' : 'Ratings are verified: only a completed consultation can be rated, once per consultation.' ?></p>

  <?php if ($saved): ?><div class="form-alert success"><?= is_rtl() ? 'تم تحديث التقييم.' : 'Review updated.' ?></div><?php endif; ?>

  <div class="toolbar" style="margin-bottom:14px">
    <?php foreach (['' => is_rtl() ? 'الكل' : 'All'] + $statusLabels as $key => $lbl): ?>
      <a class="btn <?= $statusFilter === (string)$key ? 'btn-primary' : 'btn-outline' ?> btn-sm"
         href="/admin/reviews.php<?= $key !== '' ? '?status=' . urlencode((string)$key) : '' ?>"><?= htmlspecialchars($lbl) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if (empty($reviews)): ?>
    <div class="dash-card" style="text-align:center;padding:40px 24px">
      <div style="font-size:2.2rem;margin-bottom:8px">⭐</div>
      <h3 style="margin:0 0 6px"><?= is_rtl() ? 'لا توجد تقييمات' : 'No reviews' ?></h3>
      <p><?= is_rtl() ? 'ستظهر التقييمات هنا بعد أن يقيّم العملاء استشاراتهم المكتملة.' : 'Reviews appear here once clients rate their completed consultations.' ?></p>
    </div>
  <?php else: ?>
    <div class="table-wrap table-cards">
      <table class="admin-table">
        <thead>
          <tr>
            <th><?= is_rtl() ? 'المحامي' : 'Lawyer' ?></th>
            <th><?= is_rtl() ? 'العميل' : 'Client' ?></th>
            <th><?= is_rtl() ? 'التقييم' : 'Rating' ?></th>
            <th><?= is_rtl() ? 'التعليق' : 'Comment' ?></th>
            <th><?= is_rtl() ? 'الحالة' : 'Status' ?></th>
            <th><?= is_rtl() ? 'إجراء' : 'Action' ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($reviews as $r): ?>
            <tr>
              <td data-label="<?= is_rtl() ? 'المحامي' : 'Lawyer' ?>"><?= htmlspecialchars((string)$r['lawyer_name']) ?></td>
              <td data-label="<?= is_rtl() ? 'العميل' : 'Client' ?>"><?= htmlspecialchars((string)$r['client_name']) ?></td>
              <td data-label="<?= is_rtl() ? 'التقييم' : 'Rating' ?>"><span class="ltr"><?= str_repeat('★', (int)$r['rating']) ?><?= str_repeat('☆', 5 - (int)$r['rating']) ?></span></td>
              <td data-label="<?= is_rtl() ? 'التعليق' : 'Comment' ?>"><?= htmlspecialchars((string)($r['comment'] ?? '')) ?></td>
              <td data-label="<?= is_rtl() ? 'الحالة' : 'Status' ?>">
                <span class="status-<?= htmlspecialchars($r['status']) ?>"><?= htmlspecialchars($statusLabels[$r['status']] ?? $r['status']) ?></span>
                <?php if ((int)$r['report_count'] > 0): ?><small class="muted-sm"> · <?= (int)$r['report_count'] ?> <?= is_rtl() ? 'إبلاغ' : 'reports' ?></small><?php endif; ?>
              </td>
              <td data-label="<?= is_rtl() ? 'إجراء' : 'Action' ?>">
                <form method="post" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                  <input type="hidden" name="action" value="set_status">
                  <input type="hidden" name="review_id" value="<?= (int)$r['id'] ?>">
                  <select name="status" class="pro-select" style="min-height:40px">
                    <?php foreach ($statusLabels as $k => $lbl): ?>
                      <option value="<?= $k ?>" <?= $r['status'] === $k ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <input type="text" name="admin_note" placeholder="<?= is_rtl() ? 'ملاحظة' : 'Note' ?>" value="<?= htmlspecialchars((string)($r['admin_note'] ?? '')) ?>" style="max-width:140px">
                  <button class="btn btn-primary btn-sm" type="submit"><?= is_rtl() ? 'حفظ' : 'Save' ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
