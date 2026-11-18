<?php
/**
 * تذكرة دعم — admin ticket detail, reply and status.
 *
 * The only place a support ticket is written from the console. Every write is
 * CSRF-checked and audit-logged; the ticket id is validated to exist before any
 * action runs, so a forged id changes nothing.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';
require_once __DIR__ . '/includes/admin_ui.php'; // adm() is used above the header include
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/support.php';

$ticketId = (int)($_GET['id'] ?? ($_POST['ticket_id'] ?? 0));
$error = null;
$success = null;

$ticket = null;
if ($ticketId > 0) {
    $stmt = $pdo->prepare('SELECT t.*, c.name_ar AS category_ar, c.name_en AS category_en
        FROM support_tickets t
        LEFT JOIN support_ticket_categories c ON c.id = t.category_id
        WHERE t.id = ?');
    $stmt->execute([$ticketId]);
    $ticket = $stmt->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ticket) {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) { http_response_code(403); exit('Invalid request'); }
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'reply') {
            $adminName = (string)($_SESSION['admin_name'] ?? 'Support');
            if (support_admin_reply($pdo, $ticketId, (int)($_SESSION['admin_id'] ?? 0), $adminName, (string)($_POST['body'] ?? ''))) {
                log_action($pdo, 'support_ticket_reply', 'support_ticket', $ticketId);
                $success = adm('تم إرسال الرد.', 'Reply sent.');
            } else {
                $error = adm('تعذّر إرسال الرد. تحقّق من النص.', 'Could not send the reply. Check the text.');
            }
        } elseif ($action === 'set_status') {
            $status = (string)($_POST['status'] ?? '');
            if (in_array($status, ['open', 'pending', 'answered', 'closed'], true)) {
                $pdo->prepare('UPDATE support_tickets SET status=?, closed_at = CASE WHEN ?=\'closed\' THEN NOW() ELSE NULL END, unread_for_support=0 WHERE id=?')
                    ->execute([$status, $status, $ticketId]);
                log_action($pdo, 'support_ticket_status', 'support_ticket', $ticketId);
                $success = adm('تم تحديث الحالة.', 'Status updated.');
            }
        } elseif ($action === 'assign') {
            $assignee = (int)($_POST['assigned_admin_id'] ?? 0);
            $pdo->prepare('UPDATE support_tickets SET assigned_admin_id = ? WHERE id=?')
                ->execute([$assignee > 0 ? $assignee : null, $ticketId]);
            log_action($pdo, 'support_ticket_assign', 'support_ticket', $ticketId);
            $success = adm('تم تحديث المسؤول.', 'Assignee updated.');
        }
        // Reload the ticket so the page reflects the write without a redirect.
        $stmt->execute([$ticketId]);
        $ticket = $stmt->fetch() ?: $ticket;
    } catch (Throwable $e) {
        error_log('admin support-ticket write: ' . $e->getMessage());
        $error = adm('حدث خطأ غير متوقع.', 'An unexpected error occurred.');
    }
}

$messages = $ticket ? support_ticket_messages($pdo, $ticketId) : [];
$attachments = $ticket ? support_attachment_list($pdo, $ticketId) : [];
$admins = [];
try {
    $admins = $pdo->query("SELECT id, name FROM admins WHERE role IN ('super_admin','support') ORDER BY name")->fetchAll();
} catch (Throwable $e) {
    // The admins table shape differs on very old installs; the assign picker simply
    // stays empty rather than breaking the page.
}

$statusLabels = ['open' => adm('مفتوحة', 'Open'), 'pending' => adm('قيد الانتظار', 'Pending'),
    'answered' => adm('تم الرد', 'Answered'), 'closed' => adm('مغلقة', 'Closed')];

$activeAdmin = 'support-tickets';
require_once __DIR__ . '/includes/admin_header.php';
?>
<div class="app-content">
  <div class="toolbar">
    <h2><?= adm('تذكرة', 'Ticket') ?> <?= $ticket ? h($ticket['ticket_number']) : '' ?></h2>
    <a class="btn btn-outline" href="/admin/support-tickets.php"><?= adm('رجوع', 'Back') ?></a>
  </div>

  <?php if ($success): ?><div class="form-alert success"><?= h($success) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="form-alert error"><?= h($error) ?></div><?php endif; ?>

  <?php if (!$ticket): ?>
    <div class="dash-card"><p style="color:var(--text-muted)"><?= adm('التذكرة غير موجودة.', 'Ticket not found.') ?></p></div>
  <?php else: ?>
    <div class="dash-card" style="margin-bottom:16px">
      <h3 style="margin-bottom:6px"><?= h($ticket['subject']) ?></h3>
      <p style="color:var(--text-muted);font-size:.85rem">
        <?= h(is_rtl() ? ($ticket['category_ar'] ?? '') : ($ticket['category_en'] ?? '')) ?>
        · <?= h($ticket['created_at']) ?>
        · <?= $ticket['user_type'] === 'lawyer' ? adm('محامٍ', 'Lawyer') : adm('عميل', 'Client') ?>
      </p>
      <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:end;margin-top:12px">
        <form method="post" style="display:flex;gap:8px;align-items:end">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="ticket_id" value="<?= (int)$ticket['id'] ?>">
          <input type="hidden" name="action" value="set_status">
          <div class="field">
            <label><?= adm('الحالة', 'Status') ?></label>
            <select name="status">
              <?php foreach ($statusLabels as $k => $lbl): ?>
                <option value="<?= h($k) ?>" <?= $ticket['status'] === $k ? 'selected' : '' ?>><?= h($lbl) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button class="btn btn-outline btn-sm" type="submit"><?= adm('حفظ', 'Save') ?></button>
        </form>
        <?php if ($admins): ?>
        <form method="post" style="display:flex;gap:8px;align-items:end">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="ticket_id" value="<?= (int)$ticket['id'] ?>">
          <input type="hidden" name="action" value="assign">
          <div class="field">
            <label><?= adm('المسؤول', 'Assignee') ?></label>
            <select name="assigned_admin_id">
              <option value="0"><?= adm('غير مُعيَّن', 'Unassigned') ?></option>
              <?php foreach ($admins as $a): ?>
                <option value="<?= (int)$a['id'] ?>" <?= (int)($ticket['assigned_admin_id'] ?? 0) === (int)$a['id'] ? 'selected' : '' ?>><?= h($a['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button class="btn btn-outline btn-sm" type="submit"><?= adm('تعيين', 'Assign') ?></button>
        </form>
        <?php endif; ?>
      </div>
    </div>

    <div class="dash-card" style="margin-bottom:16px">
      <h3><?= adm('المحادثة', 'Conversation') ?></h3>
      <?php foreach ($messages as $m): $isSupport = $m['author_type'] === 'support'; ?>
        <div style="padding:12px;border-radius:var(--r-md,8px);margin:10px 0;background:<?= $isSupport ? 'var(--surface-2,#eef2f7)' : 'transparent' ?>;border:1px solid var(--border,#e2e8f0)">
          <div style="font-weight:600;font-size:.85rem">
            <?= $isSupport ? adm('الدعم', 'Support') : adm('العميل', 'Customer') ?>
            <?php if (!empty($m['author_name'])): ?> · <?= h($m['author_name']) ?><?php endif; ?>
            <span style="color:var(--text-muted);font-weight:400">· <?= h($m['created_at']) ?></span>
          </div>
          <div style="margin-top:6px;white-space:pre-wrap"><?= nl2br(h($m['body'])) ?></div>
        </div>
      <?php endforeach; ?>

      <?php if ($attachments): ?>
        <p style="margin-top:12px"><strong><?= adm('المرفقات', 'Attachments') ?></strong></p>
        <ul style="margin:6px 0;padding-inline-start:18px">
          <?php foreach ($attachments as $a): ?>
            <li><?= h($a['original_name']) ?> <span style="color:var(--text-muted)">(<?= h(consultation_attachment_format_size((int)$a['file_size'])) ?>)</span></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <form method="post" style="margin-top:16px">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="ticket_id" value="<?= (int)$ticket['id'] ?>">
        <input type="hidden" name="action" value="reply">
        <div class="field">
          <label><?= adm('الرد', 'Reply') ?></label>
          <textarea name="body" rows="4" required maxlength="5000" style="width:100%"></textarea>
        </div>
        <button class="btn btn-primary" type="submit" style="margin-top:10px"><?= adm('إرسال الرد', 'Send reply') ?></button>
      </form>
    </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
