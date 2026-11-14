<?php
/**
 * Admin → Account deletion requests (Execution 73).
 *
 * The queue behind the public /account-deletion page. Google Play requires a documented
 * process for a deletion request that arrives outside the app, and a human decision at the
 * end of it — nothing is executed automatically. Every request is anonymised through the
 * central `account_deletion_execute()` service, which delegates to `account_delete()`, so
 * the retention rules and session/token revocation are the same ones the in-app path uses.
 *
 * The list and the detail view are the same page: `?ref=DEL-…` opens one request. The
 * actions are POST-only and CSRF-checked, and both re-read the request from the database
 * so a stale page cannot act on a request that already moved.
 */
$activeAdmin = 'account-deletions';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/account_deletion.php';
require_once __DIR__ . '/../includes/account_deletion_requests.php';
$pageTitle = is_rtl() ? 'طلبات حذف الحسابات' : 'Account deletion requests';
require_once __DIR__ . '/includes/admin_header.php';

$ready = account_deletion_requests_ready($pdo);
$notice = null;
$noticeType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? '')) { http_response_code(403); exit('Invalid CSRF token'); }
    $ref = trim((string)($_POST['reference'] ?? ''));
    $note = trim((string)($_POST['note'] ?? ''));
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'execute') {
        $res = account_deletion_execute($pdo, $ref, (int)($_SESSION['admin_id'] ?? 0), $note);
        if ($res['ok']) {
            $notice = is_rtl() ? 'تم تنفيذ الحذف وإخفاء بيانات الحساب الشخصية وفق سياسة الاحتفاظ.' : 'Deletion executed; the account\'s personal data was erased under the retention policy.';
        } else {
            $noticeType = 'error';
            $err = (string)($res['error'] ?? 'delete_failed');
            if ($err === 'has_open_work') {
                $notice = is_rtl() ? 'تعذّر التنفيذ لوجود أعمال قائمة:' : 'Cannot execute while work is in progress:';
                $notice .= ' ' . implode(' | ', $res['blockers'] ?? []);
            } elseif ($err === 'not_verified') {
                $notice = is_rtl() ? 'لا يمكن التنفيذ قبل تأكيد ملكية الحساب.' : 'Cannot execute before ownership is verified.';
            } elseif ($err === 'no_account') {
                $notice = is_rtl() ? 'لا يوجد حساب مطابق مرتبط بالطلب.' : 'No matching account is linked to this request.';
            } else {
                $notice = is_rtl() ? 'تعذّر تنفيذ الحذف.' : 'Could not execute the deletion.';
            }
        }
    } elseif ($action === 'reject') {
        $res = account_deletion_reject($pdo, $ref, (int)($_SESSION['admin_id'] ?? 0), $note);
        $notice = $res['ok']
            ? (is_rtl() ? 'تم رفض الطلب وتسجيل السبب.' : 'Request rejected and the reason recorded.')
            : (is_rtl() ? 'تعذّر رفض الطلب.' : 'Could not reject the request.');
        if (!$res['ok']) $noticeType = 'error';
    }
}

$filter = (string)($_GET['status'] ?? '');
$ref = trim((string)($_GET['ref'] ?? ''));
$detail = null;
if ($ref !== '') {
    $detail = account_deletion_request_get($pdo, $ref);
}

$requests = [];
$counts = ['pending' => 0, 'processing' => 0, 'completed' => 0, 'rejected' => 0];
if ($ready) {
    try {
        $where = in_array($filter, ['pending', 'processing', 'completed', 'rejected'], true) ? 'WHERE status = ?' : '';
        $stmt = $pdo->prepare("SELECT * FROM account_deletion_requests $where ORDER BY id DESC LIMIT 300");
        $stmt->execute($where ? [$filter] : []);
        $requests = $stmt->fetchAll();
        foreach ($pdo->query('SELECT status, COUNT(*) c FROM account_deletion_requests GROUP BY status') as $row) {
            $counts[$row['status']] = (int)$row['c'];
        }
    } catch (Throwable $e) { $requests = []; }
}

$statusLabels = [
    'pending'    => is_rtl() ? 'بانتظار المعالجة' : 'Pending',
    'processing' => is_rtl() ? 'قيد المعالجة' : 'Processing',
    'completed'  => is_rtl() ? 'مكتمل' : 'Completed',
    'rejected'   => is_rtl() ? 'مرفوض' : 'Rejected',
    'cancelled'  => is_rtl() ? 'ملغى' : 'Cancelled',
];
$csrf = csrf_token();
// Map our statuses onto the console's existing status pill classes, so a request row
// renders with the same visual language as every other admin table.
$statusClass = ['pending' => 'pending', 'processing' => 'processing', 'completed' => 'completed', 'rejected' => 'cancelled', 'cancelled' => 'cancelled'];
?>
<div class="app-content">
  <h1><?= is_rtl() ? 'طلبات حذف الحسابات' : 'Account deletion requests' ?></h1>
  <p style="margin-bottom:16px"><?= is_rtl()
    ? 'طلبات الحذف الواردة من صفحة /account-deletion العامة. لا يُنفَّذ أي حذف قبل تأكيد ملكية الحساب، ويتم عبر خدمة الحذف المركزية.'
    : 'Deletion requests from the public /account-deletion page. Nothing is executed before ownership is verified, and execution goes through the central deletion service.' ?></p>

  <?php if (!$ready): ?>
    <div class="form-alert error"><?= is_rtl() ? 'جدول الطلبات غير موجود. طبّق ترقية قاعدة البيانات upgrade_v77.sql.' : 'The requests table is missing. Apply the database migration upgrade_v77.sql.' ?></div>
  <?php endif; ?>

  <?php if ($notice): ?><div class="form-alert <?= $noticeType === 'error' ? 'error' : 'success' ?>"><?= htmlspecialchars($notice) ?></div><?php endif; ?>

  <div class="toolbar" style="margin-bottom:14px">
    <a class="btn <?= $filter === '' ? 'btn-primary' : 'btn-outline' ?> btn-sm" href="/admin/account-deletions.php"><?= is_rtl() ? 'الكل' : 'All' ?></a>
    <?php foreach ($statusLabels as $k => $lbl): if (!isset($counts[$k]) && !in_array($k, ['pending','processing','completed','rejected'], true)) continue; ?>
      <a class="btn <?= $filter === $k ? 'btn-primary' : 'btn-outline' ?> btn-sm" href="/admin/account-deletions.php?status=<?= urlencode($k) ?>"><?= htmlspecialchars($lbl) ?> (<?= (int)($counts[$k] ?? 0) ?>)</a>
    <?php endforeach; ?>
  </div>

  <?php if ($detail): $st = account_deletion_public_status($detail); ?>
    <div class="dash-card" style="margin-bottom:18px">
      <h2 style="margin-top:0"><?= is_rtl() ? 'تفاصيل الطلب' : 'Request detail' ?> <span dir="ltr" class="ltr"><?= htmlspecialchars((string)$detail['reference']) ?></span></h2>
      <table class="admin-table" style="margin-bottom:12px">
        <tbody>
          <tr><th><?= is_rtl() ? 'نوع الحساب' : 'Account type' ?></th><td><?= htmlspecialchars((string)$detail['account_type'] === 'lawyer' ? (is_rtl() ? 'محامٍ' : 'Lawyer') : (is_rtl() ? 'عميل' : 'Client')) ?></td></tr>
          <tr><th><?= is_rtl() ? 'الحساب المرتبط' : 'Linked account' ?></th><td dir="ltr"><?= $detail['account_id'] ? '#' . (int)$detail['account_id'] : '—' ?></td></tr>
          <tr><th><?= is_rtl() ? 'المُعرّف المُدخل' : 'Submitted identifier' ?></th><td dir="ltr"><?= htmlspecialchars((string)$detail['identifier']) ?></td></tr>
          <tr><th><?= is_rtl() ? 'تاريخ الطلب' : 'Requested at' ?></th><td><?= htmlspecialchars((string)$detail['requested_at']) ?></td></tr>
          <tr><th><?= is_rtl() ? 'حالة التحقق' : 'Verification' ?></th><td><?= $detail['verification_status'] === 'verified' ? (is_rtl() ? 'مؤكَّد' : 'Verified') : (is_rtl() ? 'غير مؤكَّد' : 'Unverified') ?></td></tr>
          <tr><th><?= is_rtl() ? 'حالة المعالجة' : 'Processing' ?></th><td><span class="status-<?= htmlspecialchars((string)($statusClass[$detail["status"]] ?? $detail["status"])) ?>"><?= htmlspecialchars($statusLabels[$detail['status']] ?? $detail['status']) ?></span></td></tr>
          <?php if (!empty($detail['retention_summary'])): ?>
            <tr><th><?= is_rtl() ? 'البيانات المستثناة من الحذف' : 'Data excluded from deletion' ?></th><td><?= htmlspecialchars((string)$detail['retention_summary']) ?></td></tr>
          <?php endif; ?>
          <?php if (!empty($detail['completion_note'])): ?>
            <tr><th><?= is_rtl() ? 'ملاحظة' : 'Note' ?></th><td><?= htmlspecialchars((string)$detail['completion_note']) ?></td></tr>
          <?php endif; ?>
        </tbody>
      </table>

      <h3><?= is_rtl() ? 'سجل التدقيق' : 'Audit trail' ?></h3>
      <ul class="adr-list">
        <?php foreach (account_deletion_request_events($pdo, (int)$detail['id']) as $ev): ?>
          <li><span class="ltr"><?= htmlspecialchars((string)$ev['created_at']) ?></span> — <?= htmlspecialchars((string)$ev['event']) ?><?= $ev['detail'] !== '' && $ev['detail'] !== null ? ' (' . htmlspecialchars((string)$ev['detail']) . ')' : '' ?> <small class="muted-sm">· <?= htmlspecialchars((string)$ev['actor']) ?></small></li>
        <?php endforeach; ?>
      </ul>

      <?php if (in_array((string)$detail['status'], ['pending', 'processing'], true)): ?>
        <div style="display:flex;gap:14px;flex-wrap:wrap;margin-top:12px">
          <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="execute">
            <input type="hidden" name="reference" value="<?= htmlspecialchars((string)$detail['reference']) ?>">
            <input type="text" name="note" placeholder="<?= is_rtl() ? 'ملاحظة التنفيذ' : 'Execution note' ?>" style="max-width:220px">
            <button class="btn btn-primary" type="submit" <?= $detail['verification_status'] !== 'verified' || empty($detail['account_id']) ? 'disabled' : '' ?>><?= is_rtl() ? 'تنفيذ الحذف النهائي' : 'Execute final deletion' ?></button>
          </form>
          <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="reference" value="<?= htmlspecialchars((string)$detail['reference']) ?>">
            <input type="text" name="note" placeholder="<?= is_rtl() ? 'سبب الرفض' : 'Rejection reason' ?>" style="max-width:220px">
            <button class="btn btn-outline" type="submit"><?= is_rtl() ? 'رفض الطلب' : 'Reject request' ?></button>
          </form>
        </div>
        <?php if ($detail['verification_status'] !== 'verified'): ?>
          <p class="adr-note" style="margin-top:8px"><?= is_rtl() ? 'التنفيذ معطّل حتى يؤكّد صاحب الحساب الملكية عبر رابط البريد.' : 'Execution is disabled until the account holder confirms ownership via the emailed link.' ?></p>
        <?php endif; ?>
      <?php endif; ?>
      <p style="margin-top:12px"><a href="/admin/account-deletions.php"><?= is_rtl() ? '← رجوع إلى القائمة' : '← Back to list' ?></a></p>
    </div>
  <?php endif; ?>

  <?php if (empty($requests)): ?>
    <div class="dash-card" style="text-align:center;padding:40px 24px">
      <div style="font-size:2.2rem;margin-bottom:8px">🗑️</div>
      <h3 style="margin:0 0 6px"><?= is_rtl() ? 'لا توجد طلبات' : 'No requests' ?></h3>
      <p><?= is_rtl() ? 'ستظهر هنا الطلبات الواردة من صفحة حذف الحساب العامة.' : 'Requests from the public account-deletion page appear here.' ?></p>
    </div>
  <?php else: ?>
    <div class="table-wrap table-cards">
      <table class="admin-table">
        <thead>
          <tr>
            <th><?= is_rtl() ? 'رقم الطلب' : 'Reference' ?></th>
            <th><?= is_rtl() ? 'نوع الحساب' : 'Type' ?></th>
            <th><?= is_rtl() ? 'تاريخ الطلب' : 'Requested' ?></th>
            <th><?= is_rtl() ? 'التحقق' : 'Verification' ?></th>
            <th><?= is_rtl() ? 'المعالجة' : 'Processing' ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($requests as $r): ?>
            <tr>
              <td data-label="<?= is_rtl() ? 'رقم الطلب' : 'Reference' ?>" dir="ltr"><?= htmlspecialchars((string)$r['reference']) ?></td>
              <td data-label="<?= is_rtl() ? 'نوع الحساب' : 'Type' ?>"><?= htmlspecialchars((string)$r['account_type'] === 'lawyer' ? (is_rtl() ? 'محامٍ' : 'Lawyer') : (is_rtl() ? 'عميل' : 'Client')) ?></td>
              <td data-label="<?= is_rtl() ? 'تاريخ الطلب' : 'Requested' ?>"><?= htmlspecialchars((string)$r['requested_at']) ?></td>
              <td data-label="<?= is_rtl() ? 'التحقق' : 'Verification' ?>"><?= $r['verification_status'] === 'verified' ? '✔' : '—' ?></td>
              <td data-label="<?= is_rtl() ? 'المعالجة' : 'Processing' ?>"><span class="status-<?= htmlspecialchars((string)($statusClass[$r["status"]] ?? $r["status"])) ?>"><?= htmlspecialchars($statusLabels[$r['status']] ?? $r['status']) ?></span></td>
              <td data-label=""><a class="btn btn-outline btn-sm" href="/admin/account-deletions.php?ref=<?= urlencode((string)$r['reference']) ?>"><?= is_rtl() ? 'عرض' : 'View' ?></a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
