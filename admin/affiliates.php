<?php
/**
 * Affiliates console (Execution 31).
 *
 * The register of everyone in the partner programme, with the counts an operator
 * needs to triage: state, compliance state, whether the current agreement is signed,
 * how many conversions there are, and any open fraud flag.
 *
 * State changes go through affiliate_set_status() / affiliate_set_compliance() rather
 * than an inline UPDATE, so the transition rules and the audit entry live in one
 * place instead of being re-implemented behind each button.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/affiliate.php';
require_once __DIR__ . '/../includes/affiliate_auth.php';
require_once __DIR__ . '/../includes/affiliate_fraud.php';
require_once __DIR__ . '/../includes/affiliate_admin.php';
require_once __DIR__ . '/../includes/affiliate_payout.php';
require_once __DIR__ . '/../includes/affiliate_conversion.php';

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $affiliateId = (int)($_POST['affiliate_id'] ?? 0);
    $note = is_string($_POST['note'] ?? null) ? trim($_POST['note']) : '';

    if ($affiliateId <= 0) {
        $error = 'معرّف المسوّق غير صالح.';
    } elseif ($action === 'status') {
        $to = is_string($_POST['status'] ?? null) ? $_POST['status'] : '';
        $result = affiliate_set_status($pdo, $affiliateId, $to, ['actor_id' => (int)$_SESSION['admin_id'], 'note' => $note]);
        if (!empty($result['ok'])) {
            log_action($pdo, 'تغيير حالة مسوّق #' . $affiliateId . ' إلى ' . $to, 'affiliate', $affiliateId);
            $success = 'تم تحديث حالة المسوّق.';
        } else {
            $error = match ((string)($result['error'] ?? '')) {
                'illegal_transition' => 'لا يمكن الانتقال إلى هذه الحالة من الحالة الحالية.',
                'not_found' => 'المسوّق غير موجود.',
                'reason_required' => 'يجب كتابة سبب للإيقاف أو الرفض.',
                'invalid_status' => 'الحالة غير معروفة.',
                default => 'تعذّر تحديث الحالة.',
            };
        }
    } elseif ($action === 'compliance') {
        $to = is_string($_POST['compliance_status'] ?? null) ? $_POST['compliance_status'] : '';
        $result = affiliate_set_compliance($pdo, $affiliateId, $to, ['actor_id' => (int)$_SESSION['admin_id'], 'note' => $note]);
        if (!empty($result['ok'])) {
            log_action($pdo, 'تحديث حالة الالتزام لمسوّق #' . $affiliateId . ' إلى ' . $to, 'affiliate', $affiliateId);
            $success = 'تم تحديث حالة الالتزام.';
        } else {
            $error = 'تعذّر تحديث حالة الالتزام.';
        }
    }
}

$filters = [];
foreach (['status', 'compliance_status'] as $key) {
    if (is_string($_GET[$key] ?? null) && $_GET[$key] !== '') $filters[$key] = $_GET[$key];
}
if (is_string($_GET['q'] ?? null) && trim($_GET['q']) !== '') $filters['q'] = trim($_GET['q']);

$affiliates = affiliate_admin_list($pdo, $filters + ['limit' => 200]);
$counts = affiliate_admin_counts($pdo);
$flagCounts = affiliate_flag_counts($pdo);

$complianceLabels = [
    'not_reviewed' => 'لم تُراجع',
    'in_review' => 'تحت المراجعة',
    'approved' => 'معتمدة',
    'rejected' => 'مرفوضة',
    'needs_action' => 'تحتاج إجراء',
];

$activeAdmin = 'affiliates';
require_once __DIR__ . '/includes/admin_header.php';
?>
<div class="app-content">
  <div class="toolbar">
    <h2>المسوّقون <span class="count-badge"><?= number_format($counts['total']) ?></span></h2>
  </div>

  <?php if ($error): ?><div class="form-alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="form-alert success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

  <div class="grid g-4" style="margin-bottom:18px">
    <div class="stat-card"><span class="label">نشط</span><div class="num"><?= number_format($counts['active']) ?></div></div>
    <div class="stat-card"><span class="label">قيد المراجعة</span><div class="num"><?= number_format($counts['pending']) ?></div></div>
    <div class="stat-card"><span class="label">موقوف</span><div class="num"><?= number_format($counts['suspended']) ?></div></div>
    <div class="stat-card"><span class="label">بانتظار الالتزام</span><div class="num"><?= number_format($counts['awaiting_compliance']) ?></div></div>
    <div class="stat-card"><span class="label">علامات احتيال مفتوحة</span><div class="num"><?= number_format((int)($flagCounts['open'] ?? 0)) ?></div></div>
  </div>

  <form method="get" class="dash-card" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <input type="text" name="q" value="<?= htmlspecialchars((string)($filters['q'] ?? '')) ?>" placeholder="بحث بالاسم أو البريد أو الكود" style="flex:1 1 240px">
    <select name="status">
      <option value="">كل الحالات</option>
      <?php foreach (affiliate_statuses() as $s): ?>
        <option value="<?= htmlspecialchars($s) ?>" <?= ($filters['status'] ?? '') === $s ? 'selected' : '' ?>><?= htmlspecialchars(affiliate_status_label($s)) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="compliance_status">
      <option value="">كل حالات الالتزام</option>
      <?php foreach ($complianceLabels as $k => $label): ?>
        <option value="<?= htmlspecialchars($k) ?>" <?= ($filters['compliance_status'] ?? '') === $k ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-primary" type="submit">تصفية</button>
    <a class="btn btn-outline" href="/admin/affiliates.php">إعادة تعيين</a>
  </form>

  <div class="table-wrap table-cards">
    <table class="admin-table">
      <thead><tr>
        <th>الكود</th>
        <th>الاسم</th>
        <th>البريد</th>
        <th>الحالة</th>
        <th>الالتزام</th>
        <th>الشروط</th>
        <th>التحويلات</th>
        <th>الرصيد المتاح</th>
        <th>علامات</th>
        <th></th>
      </tr></thead>
      <tbody>
        <?php if (!$affiliates): ?>
          <tr><td colspan="10" style="text-align:center;color:var(--muted);padding:28px">لا يوجد مسوّقون مطابقون.</td></tr>
        <?php else: foreach ($affiliates as $row):
            $rowCurrency = (string)($row['payout_currency'] ?: 'BHD');
            $payable = affiliate_payable_minor($pdo, (int)$row['id'], $rowCurrency);
            $openFlags = (int)($row['open_flags'] ?? 0);
        ?>
        <tr>
          <td data-label="الكود" style="font-family:monospace"><?= htmlspecialchars((string)$row['code']) ?></td>
          <td data-label="الاسم"><?= htmlspecialchars((string)$row['full_name']) ?></td>
          <td data-label="البريد" style="font-family:monospace;font-size:12px"><?= htmlspecialchars((string)$row['email']) ?></td>
          <td data-label="الحالة"><span class="status-pill <?= htmlspecialchars(affiliate_status_class((string)$row['status'])) ?>"><?= htmlspecialchars(affiliate_status_label((string)$row['status'])) ?></span></td>
          <td data-label="الالتزام">
            <span class="status-pill <?= (string)$row['compliance_status'] === 'approved' ? 'status-confirmed' : ((string)$row['compliance_status'] === 'rejected' ? 'status-cancelled' : 'status-pending') ?>">
              <?= htmlspecialchars($complianceLabels[(string)$row['compliance_status']] ?? (string)$row['compliance_status']) ?>
            </span>
          </td>
          <td data-label="الشروط"><?= !empty($row['terms_accepted_at']) ? htmlspecialchars(date('Y-m-d', strtotime((string)$row['terms_accepted_at']))) : '—' ?></td>
          <td data-label="التحويلات"><?= number_format((int)$row['conversion_count']) ?></td>
          <td data-label="الرصيد المتاح" style="font-family:monospace"><?= htmlspecialchars(affiliate_format_money(affiliate_from_minor($payable, $rowCurrency), $rowCurrency)) ?></td>
          <td data-label="علامات"><?= $openFlags > 0 ? '<span class="status-pill status-cancelled">' . (int)$openFlags . '</span>' : '—' ?></td>
          <td><?php if (admin_role_allows('affiliate-detail.php', $adminRole)): ?><a class="btn btn-outline btn-sm" href="/admin/affiliate-detail.php?id=<?= (int)$row['id'] ?>">تفاصيل</a><?php else: ?><span style="color:var(--muted)">—</span><?php endif; ?></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
