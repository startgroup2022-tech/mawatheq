<?php
/**
 * Admin — lawyer withdrawal review (Execution 56).
 *
 * Every state change goes through wallet_withdrawal_transition(), which owns the
 * transition table, the evidence rule (a `paid` needs a transfer reference) and the
 * ledger effect. An `UPDATE ... SET status=` here would bypass all three.
 *
 * The POST handler runs before the shell is included: `admin_header.php` prints the
 * whole <head> immediately with no output buffering, so a header() redirect issued
 * afterwards is silently dropped (see the "POST-redirect pages" note in AGENTS.md).
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/wallet.php';

$adminId = (int)($_SESSION['admin_id'] ?? 0);
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $withdrawalId = is_string($_POST['withdrawal_id'] ?? null)
        ? (filter_var($_POST['withdrawal_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0)
        : 0;
    $to = (string)($_POST['to'] ?? '');
    if ($withdrawalId > 0 && in_array($to, ['approved','rejected','paid','failed'], true)) {
        $result = wallet_withdrawal_transition($pdo, $withdrawalId, $to, [
            'admin_id'  => $adminId,
            'note'      => (string)($_POST['note'] ?? ''),
            'reference' => (string)($_POST['reference'] ?? ''),
        ]);
        if ($result['ok']) {
            header('Location: /admin/wallet-withdrawals.php?done=' . urlencode($to) . '&t=' . time());
            exit;
        }
        $error = $result['error'] ?? 'failed';
    } else {
        $error = 'invalid_request';
    }
    // Fall through to render with the error rather than redirecting in a loop.
}

$errorText = match ($error) {
    'reference_required' => adm('لا يمكن تعليم الطلب كمدفوع دون رقم مرجع التحويل.', 'A payout cannot be marked paid without a transfer reference.'),
    'reason_required'    => adm('سبب الرفض مطلوب.', 'A rejection reason is required.'),
    'invalid_transition' => adm('لا يمكن الانتقال إلى هذه الحالة من الحالة الحالية.', 'That transition is not allowed from the current status.'),
    'concurrent_change'  => adm('تغيّرت حالة الطلب أثناء المراجعة. أعد تحميل الصفحة.', 'The request changed while you were reviewing it. Reload the page.'),
    'not_found'          => adm('الطلب غير موجود.', 'The request was not found.'),
    default              => $error !== null ? adm('تعذّر تنفيذ العملية.', 'The operation could not be completed.') : null,
};

$done = (string)($_GET['done'] ?? '');
$rows = wallet_pending_withdrawals($pdo, 200);

$activeAdmin = 'wallet-withdrawals';
require_once __DIR__ . '/includes/admin_header.php';
?>
<div class="app-content">
  <div class="pg-head"><div><h1><?= adm('طلبات سحب المحامين', 'Lawyer withdrawal requests') ?></h1><p><?= adm(
      'عند الموافقة يُحجز المبلغ في دفتر المحفظة. لا يُصرف أي مبلغ دون رقم مرجع، ولا يُعاد الاحتياط إلا عند الفشل.',
      'Approving reserves the amount in the wallet ledger. Nothing is paid without a reference, and a reservation is only released on failure.'
    ) ?></p></div></div>

  <?php if ($done !== ''): ?>
    <div class="form-alert success"><?= adm('تم تحديث الطلب إلى الحالة: ', 'Request moved to: ') ?><strong><?= h(wallet_withdrawal_status_label($done)) ?></strong></div>
  <?php endif; ?>
  <?php if ($errorText !== null): ?>
    <div class="form-alert error"><?= h($errorText) ?></div>
  <?php endif; ?>

  <div class="table-wrap table-cards">
    <table class="admin-table">
      <thead><tr>
        <th><?= adm('المحامي', 'Lawyer') ?></th>
        <th><?= adm('المبلغ', 'Amount') ?></th>
        <th><?= adm('الحالة', 'Status') ?></th>
        <th><?= adm('الحساب', 'Account') ?></th>
        <th><?= adm('تاريخ الطلب', 'Requested') ?></th>
        <th><?= adm('إجراء', 'Action') ?></th>
      </tr></thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="6" class="empty"><?= adm('لا توجد طلبات سحب قيد المراجعة.', 'No withdrawal requests awaiting review.') ?></td></tr>
        <?php else: foreach ($rows as $w): ?>
          <tr>
            <td data-label="<?= adm('المحامي', 'Lawyer') ?>">
              <?= h((string)$w['lawyer_name']) ?><br>
              <small class="num-ltr"><?= h((string)$w['lawyer_email']) ?></small>
            </td>
            <td data-label="<?= adm('المبلغ', 'Amount') ?>"><strong><?= wallet_money((int)$w['amount_minor'], (string)$w['currency']) ?></strong></td>
            <td data-label="<?= adm('الحالة', 'Status') ?>"><span class="status-pill status-<?= h((string)$w['status']) ?>"><?= h(wallet_withdrawal_status_label((string)$w['status'])) ?></span></td>
            <td data-label="<?= adm('الحساب', 'Account') ?>"><span class="num-ltr"><?= h((string)($w['account_snapshot'] ?? '—')) ?></span></td>
            <td data-label="<?= adm('تاريخ الطلب', 'Requested') ?>"><?= h(date('Y-m-d', strtotime((string)$w['created_at']))) ?></td>
            <td data-label="<?= adm('إجراء', 'Action') ?>">
              <form method="post" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="withdrawal_id" value="<?= (int)$w['id'] ?>">
                <?php if ((string)$w['status'] === 'pending'): ?>
                  <input type="text" name="note" placeholder="<?= adm('ملاحظة', 'Note') ?>" maxlength="500" style="max-width:150px">
                  <button class="icon-btn" name="to" value="approved" type="submit"><?= adm('موافقة', 'Approve') ?></button>
                  <button class="icon-btn danger" name="to" value="rejected" type="submit"><?= adm('رفض', 'Reject') ?></button>
                <?php elseif ((string)$w['status'] === 'approved'): ?>
                  <input type="text" name="reference" placeholder="<?= adm('رقم مرجع التحويل', 'Transfer reference') ?>" maxlength="120" required style="max-width:170px">
                  <button class="icon-btn" name="to" value="paid" type="submit"><?= adm('تعليم كمدفوع', 'Mark paid') ?></button>
                  <button class="icon-btn danger" name="to" value="failed" type="submit"><?= adm('فشل الصرف', 'Mark failed') ?></button>
                <?php else: ?>
                  <span class="muted"><?= adm('لا إجراء متاح', 'No action available') ?></span>
                <?php endif; ?>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
