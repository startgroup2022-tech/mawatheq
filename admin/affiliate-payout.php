<?php
/**
 * Payout request review (Execution 31).
 *
 * The one page that can move money. Everything that guards a payout is shown here
 * before the button is pressed: the amount against the dual-approval threshold, who
 * has already approved, the affiliate's payout details, and any open fraud flag on
 * the request. The transitions themselves are made by affiliate_payout_transition(),
 * which enforces the state machine, the second-approver rule and the evidence
 * requirement — this page only decides what to ask for.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/affiliate.php';
require_once __DIR__ . '/../includes/affiliate_payout.php';
require_once __DIR__ . '/../includes/affiliate_fraud.php';

$error = null;
$success = null;
$requestId = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $requestId = (int)($_POST['request_id'] ?? 0);
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $note = is_string($_POST['note'] ?? null) ? trim($_POST['note']) : '';
    $evidence = is_string($_POST['evidence_reference'] ?? null) ? trim($_POST['evidence_reference']) : '';

    $options = ['actor_id' => (int)$_SESSION['admin_id'], 'note' => $note];
    if ($evidence !== '') $options['evidence_reference'] = $evidence;

    $result = affiliate_payout_transition($pdo, $requestId, $action, $options);
    if (!empty($result['ok'])) {
        log_action($pdo, 'طلب صرف #' . $requestId . ' → ' . $action, 'affiliate_payout', $requestId);
        $success = ($result['error'] ?? '') === 'awaiting_second_approval'
            ? 'تم تسجيل الاعتماد الأول. يلزم اعتماد مسؤول آخر لإتمام الصرف.'
            : 'تم تحديث حالة الطلب.';
    } else {
        $error = match ((string)($result['error'] ?? '')) {
            'illegal_transition' => 'لا يمكن تنفيذ هذا الإجراء من الحالة الحالية (' . (string)($result['from'] ?? '') . ').',
            'second_approver_required' => 'يلزم معتمد ثانٍ مختلف عن المعتمد الأول.',
            'evidence_required' => 'لا يمكن تسجيل الصرف كمدفوع دون مرجع إثبات (رقم الحوالة).',
            'not_found' => 'الطلب غير موجود.',
            default => 'تعذّر تحديث الطلب.',
        };
    }
}

$request = affiliate_payout_request_find($pdo, $requestId);
if (!$request) {
    http_response_code(404);
    $activeAdmin = 'affiliate-payouts';
    require_once __DIR__ . '/includes/admin_header.php';
    echo '<div class="app-content"><div class="form-alert error">طلب الصرف غير موجود.</div></div>';
    require_once __DIR__ . '/includes/admin_footer.php';
    exit;
}

$affiliate = affiliate_find($pdo, (int)$request['affiliate_id']);
$settings = affiliate_settings($pdo);
$currency = (string)$request['currency'];
$thresholdMinor = affiliate_to_minor($settings['dual_approval_threshold'], $currency);
$amountMinor = affiliate_to_minor($request['amount'], $currency);
$needsDual = $thresholdMinor > 0 && $amountMinor >= $thresholdMinor;

$flags = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM affiliate_fraud_flags WHERE payout_request_id=? OR affiliate_id=? ORDER BY id DESC LIMIT 20");
    $stmt->execute([$requestId, (int)$request['affiliate_id']]);
    $flags = $stmt->fetchAll();
} catch (Throwable $e) {}

$reserved = [];
try {
    $stmt = $pdo->prepare('SELECT * FROM affiliate_commissions WHERE payout_id=? ORDER BY id');
    $stmt->execute([$requestId]);
    $reserved = $stmt->fetchAll();
} catch (Throwable $e) {}

$allowedNext = [
    'requested' => ['under_review', 'approved', 'rejected', 'cancelled'],
    'under_review' => ['approved', 'rejected', 'cancelled'],
    'approved' => ['processing', 'rejected', 'cancelled'],
    'processing' => ['paid', 'failed'],
    'failed' => ['processing', 'cancelled'],
];
$nextStates = $allowedNext[(string)$request['status']] ?? [];

$activeAdmin = 'affiliate-payouts';
require_once __DIR__ . '/includes/admin_header.php';
?>
<div class="app-content">
  <div class="toolbar">
    <h2>طلب صرف <span style="font-family:monospace"><?= htmlspecialchars((string)$request['reference']) ?></span></h2>
    <a class="btn btn-outline" href="/admin/affiliate-payouts.php">رجوع إلى القائمة</a>
  </div>

  <?php if ($error): ?><div class="form-alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="form-alert success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

  <div class="grid g-2">
    <div class="dash-card">
      <h3>الطلب</h3>
      <table class="admin-table">
        <tbody>
          <tr><th>المبلغ</th><td style="font-family:monospace"><?= htmlspecialchars((string)$request['amount']) ?> <?= htmlspecialchars($currency) ?></td></tr>
          <tr><th>الحالة</th><td><span class="status-pill status-pending"><?= htmlspecialchars((string)$request['status']) ?></span></td></tr>
          <tr><th>المسوّق</th><td><a href="/admin/affiliate-detail.php?id=<?= (int)$request['affiliate_id'] ?>"><?= htmlspecialchars((string)($affiliate['full_name'] ?? '—')) ?></a></td></tr>
          <tr><th>طريقة الصرف</th><td><?= htmlspecialchars((string)($request['method'] ?? '—')) ?></td></tr>
          <tr><th>تفاصيل الصرف</th><td style="font-family:monospace"><?= htmlspecialchars((string)($request['payout_details_mask'] ?? '—')) ?></td></tr>
          <?php
            // The operator has to make the transfer, so the full account is shown —
            // but only to the super_admin, who is the only role that reaches this
            // page at all. The masked form is what every other surface displays.
            $details = affiliate_read_payout_details($request['payout_details_enc'] ?? null);
          ?>
          <?php if ($details): ?>
            <tr><th>البنك</th><td><?= htmlspecialchars((string)($details['bank_name'] ?? '—')) ?></td></tr>
            <tr><th>IBAN</th><td style="font-family:monospace"><?= htmlspecialchars((string)($details['iban'] ?? $details['account_number'] ?? '—')) ?></td></tr>
            <?php if (!empty($details['account_holder'])): ?><tr><th>صاحب الحساب</th><td><?= htmlspecialchars((string)$details['account_holder']) ?></td></tr><?php endif; ?>
          <?php endif; ?>
          <tr><th>طُلب في</th><td><?= htmlspecialchars((string)$request['created_at']) ?></td></tr>
          <tr><th>اعتماد أول</th><td><?= !empty($request['approved_by']) ? '#' . (int)$request['approved_by'] . ' — ' . htmlspecialchars((string)($request['approved_at'] ?? '')) : '—' ?></td></tr>
          <tr><th>اعتماد ثانٍ</th><td><?= !empty($request['second_approved_by']) ? '#' . (int)$request['second_approved_by'] . ' — ' . htmlspecialchars((string)($request['second_approved_at'] ?? '')) : '—' ?></td></tr>
          <tr><th>مرجع الإثبات</th><td style="font-family:monospace"><?= htmlspecialchars((string)($request['evidence_reference'] ?? '—')) ?></td></tr>
        </tbody>
      </table>
    </div>

    <div class="dash-card">
      <h3>ضوابط الصرف</h3>
      <p style="font-size:13px;color:var(--muted);margin-top:0">
        حد الاعتماد المزدوج: <strong><?= htmlspecialchars((string)$settings['dual_approval_threshold']) ?> <?= htmlspecialchars($currency) ?></strong>.
        <?= $needsDual ? 'هذا الطلب يتجاوز الحد، فيلزم معتمدان مختلفان.' : 'هذا الطلب دون الحد، فيكفي اعتماد واحد.' ?>
      </p>

      <?php if ($needsDual && !empty($request['approved_by']) && empty($request['second_approved_by'])): ?>
        <div class="form-alert">بانتظار اعتماد ثانٍ من مسؤول مختلف عن #<?= (int)$request['approved_by'] ?>.</div>
      <?php endif; ?>

      <?php if (!$nextStates): ?>
        <p style="font-size:13px;color:var(--muted)">لا توجد إجراءات متاحة من هذه الحالة.</p>
      <?php else: ?>
        <form method="post" style="display:flex;flex-direction:column;gap:10px">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
          <input type="hidden" name="request_id" value="<?= (int)$requestId ?>">
          <div class="grid-2">
            <label>الإجراء
              <select name="action">
                <?php foreach ($nextStates as $s): ?>
                  <option value="<?= htmlspecialchars($s) ?>"><?= htmlspecialchars($s) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>مرجع الإثبات (مطلوب لـ paid)
              <input type="text" name="evidence_reference" value="<?= htmlspecialchars((string)($request['evidence_reference'] ?? '')) ?>" placeholder="رقم الحوالة البنكية">
            </label>
          </div>
          <label>ملاحظة
            <input type="text" name="note" placeholder="سبب الرفض أو ملاحظة التنفيذ">
          </label>
          <button class="btn btn-primary" type="submit">تنفيذ الإجراء</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="dash-card">
    <h3>العمولات المحجوزة لهذا الطلب</h3>
    <div class="table-wrap">
      <table class="admin-table">
        <thead><tr><th>#</th><th>الحالة</th><th>العمولة</th><th>العملة</th><th>أُفرج عنها</th></tr></thead>
        <tbody>
          <?php if (!$reserved): ?>
            <tr><td colspan="5" style="text-align:center;color:var(--muted)">لا توجد عمولات مرتبطة بهذا الطلب.</td></tr>
          <?php else: foreach ($reserved as $c): ?>
            <tr>
              <td><?= (int)$c['id'] ?></td>
              <td><?= htmlspecialchars((string)$c['status']) ?></td>
              <td style="font-family:monospace"><?= htmlspecialchars((string)$c['commission_amount']) ?></td>
              <td><?= htmlspecialchars((string)$c['currency']) ?></td>
              <td><?= htmlspecialchars((string)($c['paid_out_at'] ?? '—')) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="dash-card">
    <h3>علامات الاحتيال المرتبطة</h3>
    <div class="table-wrap">
      <table class="admin-table">
        <thead><tr><th>#</th><th>النوع</th><th>الخطورة</th><th>الحالة</th><th>الوصف</th></tr></thead>
        <tbody>
          <?php if (!$flags): ?>
            <tr><td colspan="5" style="text-align:center;color:var(--muted)">لا توجد علامات مرتبطة.</td></tr>
          <?php else: foreach ($flags as $f): ?>
            <tr>
              <td><?= (int)$f['id'] ?></td>
              <td><?= htmlspecialchars((string)$f['flag_type']) ?></td>
              <td><span class="status-pill <?= (string)$f['severity'] === 'high' ? 'status-cancelled' : 'status-pending' ?>"><?= htmlspecialchars((string)$f['severity']) ?></span></td>
              <td><?= htmlspecialchars((string)$f['status']) ?></td>
              <td><?= htmlspecialchars((string)($f['description'] ?? '')) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
