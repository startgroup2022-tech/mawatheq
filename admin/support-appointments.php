<?php
/**
 * مواعيد الدعم — admin support appointment slots and requests.
 *
 * The operator opens slots (a date/time and a capacity); customers request them from
 * the app; the operator confirms or cancels. Capacity and the per-customer unique key
 * are enforced in support_appointment_request(), so this page only manages the
 * operator side.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';
require_once __DIR__ . '/includes/admin_ui.php'; // adm() is used above the header include
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/support.php';

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) { http_response_code(403); exit('Invalid request'); }
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'create_slot') {
            $starts = trim((string)($_POST['starts_at'] ?? ''));
            $minutes = max(15, min(240, (int)($_POST['minutes'] ?? 30)));
            $capacity = max(1, min(50, (int)($_POST['capacity'] ?? 1)));
            $note = trim((string)($_POST['note'] ?? ''));
            $ts = strtotime($starts);
            if ($ts === false || $ts < time()) {
                $error = adm('يرجى إدخال تاريخ ووقت صالحين في المستقبل.', 'Enter a valid future date and time.');
            } else {
                $startsAt = date('Y-m-d H:i:s', $ts);
                $endsAt = date('Y-m-d H:i:s', $ts + $minutes * 60);
                $pdo->prepare('INSERT INTO support_appointment_slots (starts_at, ends_at, capacity, note) VALUES (?,?,?,?)
                    ON DUPLICATE KEY UPDATE capacity=VALUES(capacity), note=VALUES(note), ends_at=VALUES(ends_at), is_active=1')
                    ->execute([$startsAt, $endsAt, $capacity, $note !== '' ? $note : null]);
                log_action($pdo, 'support_slot_create', 'support_slot', (int)$pdo->lastInsertId());
                $success = adm('تم حفظ الموعد.', 'Slot saved.');
            }
        } elseif ($action === 'toggle_slot') {
            $slotId = (int)($_POST['slot_id'] ?? 0);
            $pdo->prepare('UPDATE support_appointment_slots SET is_active = 1 - is_active WHERE id=?')->execute([$slotId]);
            $success = adm('تم تحديث الموعد.', 'Slot updated.');
        } elseif ($action === 'decide') {
            $apptId = (int)($_POST['appointment_id'] ?? 0);
            $decision = (string)($_POST['decision'] ?? '');
            if (in_array($decision, ['confirmed', 'cancelled', 'completed'], true)) {
                $pdo->prepare('UPDATE support_appointments SET status=?, decided_by_admin_id=?, decided_at=NOW(), admin_note=? WHERE id=?')
                    ->execute([$decision, (int)($_SESSION['admin_id'] ?? 0), trim((string)($_POST['admin_note'] ?? '')) ?: null, $apptId]);
                log_action($pdo, 'support_appointment_' . $decision, 'support_appointment', $apptId);

                // Tell the customer the outcome so the decision is not silent.
                $row = $pdo->prepare('SELECT user_type, user_id, reference FROM support_appointments WHERE id=?');
                $row->execute([$apptId]);
                if ($r = $row->fetch()) {
                    $label = ['confirmed' => 'تم تأكيد موعد الدعم', 'cancelled' => 'تم إلغاء موعد الدعم', 'completed' => 'اكتمل موعد الدعم'][$decision];
                    notify($pdo, $r['user_type'] === 'lawyer' ? 'lawyer' : 'client', (int)$r['user_id'],
                        $label . ' ' . $r['reference'], null, 'support.php');
                }
                $success = adm('تم تحديث الطلب.', 'Request updated.');
            }
        }
    } catch (Throwable $e) {
        error_log('admin support-appointments write: ' . $e->getMessage());
        $error = adm('حدث خطأ غير متوقع.', 'An unexpected error occurred.');
    }
}

$slots = $pdo->query("SELECT s.*, (SELECT COUNT(*) FROM support_appointments a WHERE a.slot_id=s.id AND a.status IN ('pending','confirmed')) AS taken
    FROM support_appointment_slots s WHERE s.starts_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY s.starts_at DESC LIMIT 200")->fetchAll();

$requests = $pdo->query("SELECT a.*, s.starts_at, s.ends_at,
        COALESCE(u.full_name, l.full_name) AS party_name
    FROM support_appointments a
    JOIN support_appointment_slots s ON s.id = a.slot_id
    LEFT JOIN users u ON a.user_type='client' AND u.id = a.user_id
    LEFT JOIN lawyers l ON a.user_type='lawyer' AND l.id = a.user_id
    ORDER BY a.created_at DESC LIMIT 200")->fetchAll();

$statusClass = ['pending' => 'status-pending', 'confirmed' => 'status-confirmed',
    'cancelled' => 'status-cancelled', 'completed' => 'status-completed'];

$activeAdmin = 'support-appointments';
require_once __DIR__ . '/includes/admin_header.php';
?>
<div class="app-content">
  <div class="toolbar">
    <h2><?= adm('مواعيد الدعم', 'Support appointments') ?></h2>
  </div>

  <?php if ($success): ?><div class="form-alert success"><?= h($success) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="form-alert error"><?= h($error) ?></div><?php endif; ?>

  <div class="dash-card" style="margin-bottom:16px">
    <h3><?= adm('إضافة موعد متاح', 'Open a slot') ?></h3>
    <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="create_slot">
      <div class="field" style="min-width:220px">
        <label><?= adm('التاريخ والوقت', 'Date & time') ?></label>
        <input type="datetime-local" name="starts_at" required>
      </div>
      <div class="field" style="min-width:120px">
        <label><?= adm('المدة (دقيقة)', 'Minutes') ?></label>
        <input type="number" name="minutes" value="30" min="15" max="240">
      </div>
      <div class="field" style="min-width:120px">
        <label><?= adm('السعة', 'Capacity') ?></label>
        <input type="number" name="capacity" value="1" min="1" max="50">
      </div>
      <div class="field" style="min-width:200px">
        <label><?= adm('ملاحظة', 'Note') ?></label>
        <input type="text" name="note" maxlength="255">
      </div>
      <button class="btn btn-primary" type="submit"><?= adm('إضافة', 'Add') ?></button>
    </form>
  </div>

  <div class="dash-card" style="margin-bottom:16px">
    <h3><?= adm('المواعيد المتاحة', 'Slots') ?></h3>
    <?php if (!$slots): ?>
      <p style="color:var(--text-muted)"><?= adm('لا توجد مواعيد بعد.', 'No slots yet.') ?></p>
    <?php else: ?>
      <div class="table-wrap table-cards">
        <table class="admin-table">
          <thead><tr>
            <th><?= adm('الوقت', 'When') ?></th>
            <th><?= adm('السعة', 'Capacity') ?></th>
            <th><?= adm('محجوز', 'Taken') ?></th>
            <th><?= adm('الحالة', 'State') ?></th>
            <th></th>
          </tr></thead>
          <tbody>
            <?php foreach ($slots as $s): ?>
              <tr>
                <td data-label="<?= adm('الوقت', 'When') ?>"><?= h($s['starts_at']) ?></td>
                <td data-label="<?= adm('السعة', 'Capacity') ?>"><?= (int)$s['capacity'] ?></td>
                <td data-label="<?= adm('محجوز', 'Taken') ?>"><?= (int)$s['taken'] ?></td>
                <td data-label="<?= adm('الحالة', 'State') ?>">
                  <span class="status-pill <?= (int)$s['is_active'] ? 'status-active' : 'status-suspended' ?>">
                    <?= (int)$s['is_active'] ? adm('مفعّل', 'Active') : adm('معطّل', 'Inactive') ?>
                  </span>
                </td>
                <td data-label="">
                  <form method="post" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="toggle_slot">
                    <input type="hidden" name="slot_id" value="<?= (int)$s['id'] ?>">
                    <button class="btn btn-outline btn-sm" type="submit"><?= adm('تبديل', 'Toggle') ?></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="dash-card">
    <h3><?= adm('طلبات المواعيد', 'Requests') ?></h3>
    <?php if (!$requests): ?>
      <p style="color:var(--text-muted)"><?= adm('لا توجد طلبات بعد.', 'No requests yet.') ?></p>
    <?php else: ?>
      <div class="table-wrap table-cards">
        <table class="admin-table">
          <thead><tr>
            <th><?= adm('المرجع', 'Reference') ?></th>
            <th><?= adm('الطرف', 'Party') ?></th>
            <th><?= adm('الموضوع', 'Subject') ?></th>
            <th><?= adm('الوقت', 'When') ?></th>
            <th><?= adm('الحالة', 'Status') ?></th>
            <th></th>
          </tr></thead>
          <tbody>
            <?php foreach ($requests as $r): ?>
              <tr>
                <td data-label="<?= adm('المرجع', 'Reference') ?>"><?= h($r['reference']) ?></td>
                <td data-label="<?= adm('الطرف', 'Party') ?>"><?= h($r['party_name'] ?? '') ?></td>
                <td data-label="<?= adm('الموضوع', 'Subject') ?>"><?= h($r['subject']) ?></td>
                <td data-label="<?= adm('الوقت', 'When') ?>"><?= h($r['starts_at']) ?></td>
                <td data-label="<?= adm('الحالة', 'Status') ?>">
                  <span class="status-pill <?= h($statusClass[$r['status']] ?? 'status-pending') ?>"><?= h($r['status']) ?></span>
                </td>
                <td data-label="">
                  <?php if (in_array($r['status'], ['pending', 'confirmed'], true)): ?>
                    <?php foreach (['confirmed' => adm('تأكيد', 'Confirm'), 'cancelled' => adm('إلغاء', 'Cancel')] as $dec => $lbl): ?>
                      <form method="post" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="action" value="decide">
                        <input type="hidden" name="appointment_id" value="<?= (int)$r['id'] ?>">
                        <input type="hidden" name="decision" value="<?= h($dec) ?>">
                        <button class="btn btn-outline btn-sm" type="submit"><?= h($lbl) ?></button>
                      </form>
                    <?php endforeach; ?>
                  <?php endif; ?>
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
