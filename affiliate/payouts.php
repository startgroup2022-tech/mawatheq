<?php
/**
 * Payouts (Execution 31).
 *
 * Request a payout and see the history. The page states the rules before the form
 * rather than letting an affiliate discover them from a rejection: the minimum, the
 * one-open-request limit, and the dual-approval threshold.
 */
$activeAffiliate = 'payouts';
require_once __DIR__ . '/../includes/affiliate_header.php';
require_once __DIR__ . '/../includes/affiliate_payout.php';
require_once __DIR__ . '/../includes/affiliate_fraud.php';


$affiliateId = (int)$affiliate['id'];
$currency = (string)$affiliate['payout_currency'];
$settings = affiliate_settings($pdo);
$payableMinor = affiliate_payable_minor($pdo, $affiliateId, $currency);
$openRequest = affiliate_open_payout_request($pdo, $affiliateId);
$minPayoutMinor = affiliate_to_minor($settings['min_payout'], $currency);
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $string = static function (string $key, string $default = ''): string {
        $value = $_POST[$key] ?? $default;
        return is_string($value) ? trim($value) : $default;
    };

    if ($action === 'request') {
        $amount = $string('amount');
        $details = [];
        // Bank details are only collected for a bank transfer; a cheque needs a payee
        // name, which comes from the affiliate record.
        if ($string('method', 'bank_transfer') === 'bank_transfer') {
            $details = array_filter([
                'bank_name' => mb_substr($string('bank_name'), 0, 120, 'UTF-8'),
                'account_name' => mb_substr($string('account_name'), 0, 120, 'UTF-8'),
                'iban' => mb_substr($string('iban'), 0, 60, 'UTF-8'),
                'account_number' => mb_substr($string('account_number'), 0, 60, 'UTF-8'),
                'swift' => mb_substr($string('swift'), 0, 30, 'UTF-8'),
            ], static fn($v) => $v !== '');
        }
        $result = affiliate_request_payout($pdo, $affiliateId, $amount === '' ? null : $amount, [
            'method' => $string('method', 'bank_transfer'),
            'note' => $string('note'),
            'details' => $details,
        ]);
        if (!empty($result['ok'])) {
            $success = is_rtl()
                ? 'تم استلام طلب الصرف. المرجع: ' . (string)$result['reference']
                : 'Payout request received. Reference: ' . (string)$result['reference'];
            $openRequest = affiliate_open_payout_request($pdo, $affiliateId);
            $payableMinor = affiliate_payable_minor($pdo, $affiliateId, $currency);
        } else {
            $error = match ((string)($result['error'] ?? '')) {
                'nothing_payable' => is_rtl() ? 'لا يوجد رصيد متاح للصرف.' : 'There is no balance available to pay out.',
                'open_request_exists' => is_rtl() ? 'لديك طلب صرف قيد المعالجة.' : 'You already have a payout request in progress.',
                'amount_exceeds_payable' => is_rtl() ? 'المبلغ يتجاوز الرصيد المتاح.' : 'The amount exceeds your available balance.',
                'below_minimum' => is_rtl() ? 'المبلغ أقل من الحد الأدنى للصرف.' : 'The amount is below the minimum payout.',
                'amount_invalid' => is_rtl() ? 'المبلغ غير صالح.' : 'Invalid amount.',
                'affiliate_not_active' => is_rtl() ? 'حسابك غير نشط.' : 'Your account is not active.',
                default => is_rtl() ? 'تعذّر إنشاء الطلب. حاول مرة أخرى.' : 'The request could not be created. Please try again.',
            };
        }
    } elseif ($action === 'cancel') {
        // An affiliate may cancel their own pending request, which releases the
        // reserved commissions back to approved.
        $requestId = (int)$string('request_id');
        $request = affiliate_payout_request_find($pdo, $requestId);
        if (!$request || (int)$request['affiliate_id'] !== $affiliateId) {
            $error = is_rtl() ? 'الطلب غير موجود.' : 'That request does not exist.';
        } elseif (!in_array((string)$request['status'], ['requested', 'under_review'], true)) {
            $error = is_rtl() ? 'لا يمكن إلغاء الطلب في حالته الحالية.' : 'The request cannot be cancelled in its current state.';
        } else {
            $cancel = affiliate_payout_transition($pdo, $requestId, 'cancelled', ['note' => is_rtl() ? 'أُلغي بواسطة المسوّق' : 'Cancelled by the affiliate']);
            if (!empty($cancel['ok'])) {
                $success = is_rtl() ? 'تم إلغاء الطلب.' : 'The request was cancelled.';
                $openRequest = null;
                $payableMinor = affiliate_payable_minor($pdo, $affiliateId, $currency);
            } else {
                $error = is_rtl() ? 'تعذّر إلغاء الطلب.' : 'The request could not be cancelled.';
            }
        }
    }
}

$requests = affiliate_payout_requests($pdo, $affiliateId, 100);
$settled = [];
try {
    $stmt = $pdo->prepare('SELECT * FROM affiliate_payouts WHERE affiliate_id=? ORDER BY id DESC LIMIT 50');
    $stmt->execute([$affiliateId]);
    $settled = $stmt->fetchAll();
} catch (Throwable $e) { /* pre-v57 database */ }
?>
<div class="app-page">
  <div class="app-page-head">
    <div>
      <h1><?= ui_e(is_rtl() ? 'المدفوعات' : 'Payouts') ?></h1>
      <p class="muted"><?= ui_e(is_rtl() ? 'اطلب صرف أرباحك وتابع حالة كل طلب.' : 'Request your earnings and follow each request.') ?></p>
    </div>
  </div>

  <?php if ($error): ?><div class="form-alert error"><?= ui_e($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="form-alert success"><?= ui_e($success) ?></div><?php endif; ?>

  <div class="aff-money-grid">
    <div class="aff-money aff-money-primary">
      <span class="aff-money-label"><?= ui_e(is_rtl() ? 'الرصيد المتاح' : 'Available balance') ?></span>
      <span class="aff-money-value"><?= ui_e(affiliate_format_money(affiliate_from_minor($payableMinor, $currency), $currency)) ?></span>
    </div>
    <div class="aff-money">
      <span class="aff-money-label"><?= ui_e(is_rtl() ? 'الحد الأدنى للصرف' : 'Minimum payout') ?></span>
      <span class="aff-money-value"><?= ui_e(affiliate_format_money($settings['min_payout'], $currency)) ?></span>
    </div>
    <div class="aff-money">
      <span class="aff-money-label"><?= ui_e(is_rtl() ? 'عملة الصرف' : 'Payout currency') ?></span>
      <span class="aff-money-value"><?= ui_e($currency) ?></span>
    </div>
  </div>

  <?php if ($openRequest): ?>
    <div class="form-alert">
      <strong><?= ui_e(is_rtl() ? 'لديك طلب قيد المعالجة' : 'You have a request in progress') ?></strong>
      <p><?= ui_e(is_rtl() ? 'المرجع: ' : 'Reference: ') ?><span class="aff-mono"><?= ui_e((string)$openRequest['reference']) ?></span>
         — <?= ui_e(affiliate_format_money($openRequest['amount'], (string)$openRequest['currency'])) ?>
         (<?= ui_e(affiliate_status_label((string)$openRequest['status'])) ?>)</p>
      <?php if (in_array((string)$openRequest['status'], ['requested', 'under_review'], true)): ?>
        <form method="post" class="inline-form">
          <input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>">
          <input type="hidden" name="action" value="cancel">
          <input type="hidden" name="request_id" value="<?= (int)$openRequest['id'] ?>">
          <button class="btn btn-outline btn-sm" type="submit"><?= ui_e(is_rtl() ? 'إلغاء الطلب' : 'Cancel request') ?></button>
        </form>
      <?php endif; ?>
    </div>
  <?php elseif ($payableMinor > 0 && affiliate_can_earn($affiliate)): ?>
    <section class="app-card">
      <div class="app-card-head"><h2><?= ui_e(is_rtl() ? 'طلب صرف' : 'Request a payout') ?></h2></div>
      <?php if ($payableMinor < $minPayoutMinor): ?>
        <p class="muted"><?= ui_e(is_rtl()
          ? 'رصيدك أقل من الحد الأدنى للصرف. سيصبح الطلب متاحاً عند بلوغ الحد.'
          : 'Your balance is below the minimum. The request becomes available once you reach it.') ?></p>
      <?php else: ?>
        <form method="post" class="aff-form">
          <input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>">
          <input type="hidden" name="action" value="request">
          <div class="aff-grid">
            <label class="field"><span><?= ui_e(is_rtl() ? 'المبلغ' : 'Amount') ?></span>
              <input type="number" name="amount" step="0.001" min="0" max="<?= ui_e(affiliate_from_minor($payableMinor, $currency)) ?>"
                     value="<?= ui_e(affiliate_from_minor($payableMinor, $currency)) ?>" required>
              <small><?= ui_e(is_rtl() ? 'الحد الأقصى: ' : 'Maximum: ') ?><?= ui_e(affiliate_format_money(affiliate_from_minor($payableMinor, $currency), $currency)) ?></small></label>
            <label class="field"><span><?= ui_e(is_rtl() ? 'طريقة الصرف' : 'Method') ?></span>
              <select name="method">
                <option value="bank_transfer"><?= ui_e(is_rtl() ? 'تحويل بنكي' : 'Bank transfer') ?></option>
                <option value="cheque"><?= ui_e(is_rtl() ? 'شيك' : 'Cheque') ?></option>
              </select></label>
          </div>

          <fieldset>
            <legend><?= ui_e(is_rtl() ? 'بيانات الحساب البنكي' : 'Bank account details') ?></legend>
            <p class="muted"><?= ui_e(is_rtl()
              ? 'تُخزَّن هذه البيانات مشفّرة، ولا تظهر كاملةً لأي طرف.'
              : 'These details are stored encrypted and are never displayed in full.') ?></p>
            <div class="aff-grid">
              <label class="field"><span><?= ui_e(is_rtl() ? 'اسم البنك' : 'Bank name') ?></span><input type="text" name="bank_name" maxlength="120"></label>
              <label class="field"><span><?= ui_e(is_rtl() ? 'اسم صاحب الحساب' : 'Account holder') ?></span><input type="text" name="account_name" maxlength="120"></label>
              <label class="field"><span>IBAN</span><input type="text" name="iban" maxlength="60"></label>
              <label class="field"><span><?= ui_e(is_rtl() ? 'رقم الحساب' : 'Account number') ?></span><input type="text" name="account_number" maxlength="60"></label>
              <label class="field"><span>SWIFT</span><input type="text" name="swift" maxlength="30"></label>
            </div>
          </fieldset>

          <label class="field"><span><?= ui_e(is_rtl() ? 'ملاحظة (اختياري)' : 'Note (optional)') ?></span>
            <textarea name="note" rows="2" maxlength="500"></textarea></label>

          <p class="muted"><?= ui_e(is_rtl()
            ? 'تُعالج الطلبات يدوياً. المبالغ التي تتجاوز حد الاعتماد المزدوج تتطلب موافقة اثنين من الفريق.'
            : 'Requests are processed manually. Amounts above the dual-approval threshold require two team approvals.') ?></p>

          <button class="btn btn-primary" type="submit"><?= ui_e(is_rtl() ? 'إرسال الطلب' : 'Submit request') ?></button>
        </form>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <section class="app-card">
    <div class="app-card-head"><h2><?= ui_e(is_rtl() ? 'طلبات الصرف' : 'Payout requests') ?></h2></div>
    <?php if (!$requests): ?>
      <?= ui_empty_state(is_rtl() ? 'لا توجد طلبات' : 'No requests yet', '', 'receipt') ?>
    <?php else: ?>
      <div class="table-wrap">
        <table class="admin-table">
          <thead><tr>
            <th><?= ui_e(is_rtl() ? 'المرجع' : 'Reference') ?></th>
            <th><?= ui_e(is_rtl() ? 'المبلغ' : 'Amount') ?></th>
            <th><?= ui_e(is_rtl() ? 'الطريقة' : 'Method') ?></th>
            <th><?= ui_e(is_rtl() ? 'الحالة' : 'State') ?></th>
            <th><?= ui_e(is_rtl() ? 'تاريخ الطلب' : 'Requested') ?></th>
            <th><?= ui_e(is_rtl() ? 'تاريخ الصرف' : 'Paid') ?></th>
          </tr></thead>
          <tbody>
            <?php foreach ($requests as $row): ?>
              <tr>
                <td class="aff-mono"><?= ui_e((string)$row['reference']) ?></td>
                <td class="aff-mono"><?= ui_e(affiliate_format_money($row['amount'], (string)$row['currency'])) ?></td>
                <td><?= ui_e($row['method'] === 'cheque' ? (is_rtl() ? 'شيك' : 'Cheque') : (is_rtl() ? 'تحويل بنكي' : 'Bank transfer')) ?></td>
                <td><span class="status-pill <?= ui_e(affiliate_status_class((string)$row['status'])) ?>"><?= ui_e(affiliate_status_label((string)$row['status'])) ?></span></td>
                <td><?= ui_e($row['created_at'] ? date('Y-m-d', strtotime((string)$row['created_at'])) : '—') ?></td>
                <td><?= ui_e(!empty($row['paid_at']) ? date('Y-m-d', strtotime((string)$row['paid_at'])) : '—') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>
<?php require_once __DIR__ . '/../includes/affiliate_footer.php'; ?>
