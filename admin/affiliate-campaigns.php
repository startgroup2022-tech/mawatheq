<?php
/**
 * Campaigns console (Execution 31).
 *
 * Campaigns gate what may be promoted: a campaign carries the allowed services and,
 * where the service is regulated, the compliance sign-off that makes commission
 * lawful. Toggling `compliance_approved` here is therefore a legal act, not a
 * cosmetic one, so it is restricted to super_admin and recorded in the audit trail.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/affiliate.php';

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $campaignId = (int)($_POST['campaign_id'] ?? 0);

    if ($campaignId <= 0) {
        $error = 'معرّف الحملة غير صالح.';
    } elseif ($action === 'status') {
        $to = is_string($_POST['status'] ?? null) ? $_POST['status'] : '';
        if (!in_array($to, ['draft', 'active', 'paused', 'ended', 'archived'], true)) {
            $error = 'حالة غير معروفة.';
        } else {
            try {
                $pdo->prepare('UPDATE affiliate_campaigns SET status=? WHERE id=?')->execute([$to, $campaignId]);
                affiliate_audit($pdo, 'campaign_status_changed', ['subject_type' => 'campaign', 'subject_id' => $campaignId, 'to_state' => $to]);
                log_action($pdo, 'تغيير حالة حملة #' . $campaignId . ' إلى ' . $to, 'affiliate_campaign', $campaignId);
                $success = 'تم تحديث حالة الحملة.';
            } catch (Throwable $e) { $error = 'تعذّر تحديث الحملة.'; }
        }
    } elseif ($action === 'compliance') {
        $approved = (int)($_POST['compliance_approved'] ?? 0) === 1 ? 1 : 0;
        $note = is_string($_POST['compliance_note'] ?? null) ? trim($_POST['compliance_note']) : '';
        if ($approved === 1 && $note === '') {
            $error = 'لا يمكن اعتماد حملة خدمة منظّمة دون ملاحظة الامتثال.';
        } else {
            try {
                $pdo->prepare('UPDATE affiliate_campaigns SET compliance_approved=?, compliance_approved_by=?, compliance_approved_at=NOW(), compliance_note=? WHERE id=?')
                    ->execute([$approved, $approved ? (int)$_SESSION['admin_id'] : null, $note !== '' ? mb_substr($note, 0, 500, 'UTF-8') : null, $campaignId]);
                affiliate_audit($pdo, 'campaign_compliance_changed', ['subject_type' => 'campaign', 'subject_id' => $campaignId, 'to_state' => $approved ? 'approved' : 'withdrawn', 'detail' => $note]);
                log_action($pdo, ($approved ? 'اعتماد' : 'سحب اعتماد') . ' امتثال حملة #' . $campaignId, 'affiliate_campaign', $campaignId);
                $success = 'تم تحديث حالة الامتثال.';
            } catch (Throwable $e) { $error = 'تعذّر تحديث الامتثال.'; }
        }
    }
}

$campaigns = [];
$plans = [];
try {
    $campaigns = $pdo->query('SELECT c.*, (SELECT COUNT(*) FROM affiliate_conversions v WHERE v.campaign_id = c.id) AS conversion_count,
            (SELECT COUNT(*) FROM affiliate_links l WHERE l.campaign_id = c.id) AS link_count
        FROM affiliate_campaigns c ORDER BY c.id DESC LIMIT 200')->fetchAll();
    $plans = $pdo->query('SELECT id,name,currency FROM affiliate_commission_plans ORDER BY id DESC')->fetchAll();
} catch (Throwable $e) {}

$activeAdmin = 'affiliate-campaigns';
require_once __DIR__ . '/includes/admin_header.php';
?>
<div class="app-content">
  <div class="toolbar">
    <h2>حملات الشركاء <span class="count-badge"><?= number_format(count($campaigns)) ?></span></h2>
  </div>

  <?php if ($error): ?><div class="form-alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="form-alert success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

  <div class="table-wrap table-cards">
    <table class="admin-table">
      <thead><tr>
        <th>#</th><th>الاسم</th><th>النوع</th><th>الحالة</th><th>الامتثال</th>
        <th>الروابط</th><th>التحويلات</th><th>تبدأ</th><th>تنتهي</th><th>إجراء</th>
      </tr></thead>
      <tbody>
        <?php if (!$campaigns): ?>
          <tr><td colspan="10" style="text-align:center;color:var(--muted);padding:28px">لا توجد حملات.</td></tr>
        <?php else: foreach ($campaigns as $c): ?>
          <tr>
            <td data-label="#"><?= (int)$c['id'] ?></td>
            <td data-label="الاسم"><?= htmlspecialchars((string)$c['name']) ?><br><span style="font-family:monospace;font-size:11px;color:var(--muted)"><?= htmlspecialchars((string)$c['slug']) ?></span></td>
            <td data-label="النوع"><?= htmlspecialchars((string)$c['campaign_type']) ?></td>
            <td data-label="الحالة"><span class="status-pill <?= (string)$c['status'] === 'active' ? 'status-confirmed' : 'status-pending' ?>"><?= htmlspecialchars((string)$c['status']) ?></span></td>
            <td data-label="الامتثال"><?= !empty($c['compliance_approved']) ? '<span class="status-pill status-confirmed">معتمد</span>' : '<span class="status-pill status-pending">غير معتمد</span>' ?></td>
            <td data-label="الروابط"><?= number_format((int)$c['link_count']) ?></td>
            <td data-label="التحويلات"><?= number_format((int)$c['conversion_count']) ?></td>
            <td data-label="تبدأ"><?= htmlspecialchars((string)($c['starts_at'] ?? '—')) ?></td>
            <td data-label="تنتهي"><?= htmlspecialchars((string)($c['ends_at'] ?? '—')) ?></td>
            <td data-label="إجراء">
              <form method="post" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="campaign_id" value="<?= (int)$c['id'] ?>">
                <select name="status">
                  <?php foreach (['draft', 'active', 'paused', 'ended', 'archived'] as $s): ?>
                    <option value="<?= htmlspecialchars($s) ?>" <?= (string)$c['status'] === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
                  <?php endforeach; ?>
                </select>
                <button class="btn btn-outline btn-sm" type="submit" name="action" value="status">حفظ</button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <div class="dash-card">
    <h3>اعتماد الامتثال للحملات</h3>
    <p style="font-size:13px;color:var(--muted);margin-top:0">
      اعتماد حملة خدمة منظّمة إجراء قانوني: بدون الاعتماد لا تُنشأ عمولة لهذه الحملة على الخدمات المنظّمة.
    </p>
    <?php if (!$campaigns): ?>
      <p style="color:var(--muted)">لا توجد حملات لاعتمادها.</p>
    <?php else: foreach ($campaigns as $c): ?>
      <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;padding:8px 0;border-bottom:1px solid var(--line-2)">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="campaign_id" value="<?= (int)$c['id'] ?>">
        <input type="hidden" name="action" value="compliance">
        <strong style="min-width:160px"><?= htmlspecialchars((string)$c['name']) ?></strong>
        <select name="compliance_approved">
          <option value="0" <?= empty($c['compliance_approved']) ? 'selected' : '' ?>>غير معتمد</option>
          <option value="1" <?= !empty($c['compliance_approved']) ? 'selected' : '' ?>>معتمد</option>
        </select>
        <input type="text" name="compliance_note" value="<?= htmlspecialchars((string)($c['compliance_note'] ?? '')) ?>" placeholder="ملاحظة الامتثال" style="flex:1 1 240px">
        <button class="btn btn-primary btn-sm" type="submit">حفظ الامتثال</button>
      </form>
    <?php endforeach; endif; ?>
  </div>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
