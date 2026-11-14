<?php
/**
 * Lawyer wallet — balances, ledger, statements, payouts and payout account
 * (Execution 56).
 *
 * Every figure on this page comes from includes/wallet.php, which computes from the
 * ledger. Nothing here invents a number or stores a running total.
 *
 * The POST handlers run before the shell is included: `lawyer_header.php` prints the
 * whole <head> immediately with no output buffering, so a header() redirect issued
 * afterwards is silently dropped (see the "POST-redirect pages" note in AGENTS.md).
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/lawyer_guard.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/wallet.php';

$lawyerId = (int)$_SESSION['lawyer_id'];
$rtl = is_rtl();
$wallet = wallet_ensure($pdo, $lawyerId);
$walletId = (int)$wallet['id'];
$currency = (string)$wallet['currency'];

$flash = null;
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'request_withdrawal') {
        // Parse through the currency's own precision rather than a float: a submitted
        // "100.00" and "100.009" must both become the same integer, and a raw
        // multiplication would round differently for each.
        $amountRaw = is_string($_POST['amount'] ?? null) ? trim($_POST['amount']) : '';
        $amountMinor = is_numeric($amountRaw) ? wallet_to_minor($amountRaw, $currency) : 0;
        $result = wallet_request_withdrawal($pdo, $lawyerId, $amountMinor, [
            'method' => (string)($_POST['method'] ?? 'bank_transfer'),
            'note'   => is_string($_POST['note'] ?? null) ? $_POST['note'] : '',
        ]);
        if ($result['ok']) {
            $flash = $rtl ? 'تم إرسال طلب السحب وسيراجعه فريق المنصة.' : 'Your withdrawal request was sent and will be reviewed.';
        } else {
            $flashType = 'error';
            $flash = match ($result['error'] ?? '') {
                'insufficient_balance' => $rtl
                    ? 'المبلغ المطلوب يتجاوز الرصيد المتاح (' . wallet_money_text((int)($result['available_minor'] ?? 0), $currency) . ').'
                    : 'The requested amount exceeds your available balance (' . wallet_money_text((int)($result['available_minor'] ?? 0), $currency) . ').',
                'amount_invalid'  => $rtl ? 'أدخل مبلغاً صحيحاً أكبر من صفر.' : 'Enter a valid amount greater than zero.',
                'wallet_not_active' => $rtl ? 'المحفظة غير نشطة حالياً. تواصل مع الدعم.' : 'Your wallet is not active. Contact support.',
                default => $rtl ? 'تعذّر إنشاء طلب السحب. حاول مرة أخرى.' : 'Could not create the withdrawal request. Try again.',
            };
        }
        header('Location: /lawyer/wallet.php?flash=' . ($result['ok'] ? 'sent' : 'error') . '&t=' . time());
        exit;
    }

    if ($action === 'save_payout_account') {
        $holder = is_string($_POST['holder_name'] ?? null) ? trim($_POST['holder_name']) : '';
        $iban   = is_string($_POST['iban'] ?? null) ? trim($_POST['iban']) : '';
        $bank   = is_string($_POST['bank_name'] ?? null) ? trim($_POST['bank_name']) : '';
        $details = array_filter(['bank' => $bank, 'iban' => $iban], fn($v) => $v !== '');
        if ($holder === '' || !$details) {
            $flashType = 'error';
            $flash = $rtl ? 'أدخل اسم المستفيد ورقم الحساب.' : 'Enter the beneficiary name and the account number.';
        } elseif (wallet_store_payout_details($pdo, $walletId, $details, (string)($_POST['method'] ?? 'bank_transfer'), $holder)) {
            log_action($pdo, 'تحديث حساب صرف المحفظة', 'lawyer_wallet', $walletId);
            $flash = $rtl ? 'تم حفظ حساب الصرف.' : 'Payout account saved.';
        } else {
            $flashType = 'error';
            $flash = $rtl ? 'تعذّر حفظ بيانات الحساب بأمان.' : 'Could not store the account details securely.';
        }
        header('Location: /lawyer/wallet.php?flash=' . ($flashType === 'error' ? 'error' : 'saved') . '&t=' . time());
        exit;
    }
}

$flashCode = (string)($_GET['flash'] ?? '');
if ($flashCode === 'sent')  { $flash = $rtl ? 'تم إرسال طلب السحب وسيراجعه فريق المنصة.' : 'Your withdrawal request was sent and will be reviewed.'; }
if ($flashCode === 'saved') { $flash = $rtl ? 'تم حفظ حساب الصرف.' : 'Payout account saved.'; }
if ($flashCode === 'error') { $flash = $rtl ? 'تعذّر تنفيذ الطلب. حاول مرة أخرى.' : 'The request could not be completed. Try again.'; $flashType = 'error'; }

// Statement range. Default: the current month.
$from = is_string($_GET['from'] ?? null) && strtotime((string)$_GET['from']) ? (string)$_GET['from'] : date('Y-m-01');
$to   = is_string($_GET['to'] ?? null) && strtotime((string)$_GET['to']) ? (string)$_GET['to'] : date('Y-m-d');

$balances = wallet_balances($pdo, $walletId, $currency);
$totals   = wallet_totals($pdo, $walletId);
$statement = wallet_statement($pdo, $walletId, $from, $to);
$withdrawals = wallet_withdrawals($pdo, $walletId);
$payoutDetails = wallet_payout_details($pdo, $wallet);
$maskedAccount = wallet_mask_payout_details($payoutDetails);
$commissionRate = wallet_commission_rate($pdo, $wallet['firm_id'] !== null ? (int)$wallet['firm_id'] : null);

$activeLawyer = 'wallet';
require_once __DIR__ . '/includes/lawyer_header.php';
?>
<div class="app-content">
  <div class="toolbar">
    <h2><?= $rtl ? 'محفظتي المالية' : 'My wallet' ?></h2>
    <span class="muted-sm"><?= $rtl ? 'العملة' : 'Currency' ?>: <strong><?= htmlspecialchars($currency) ?></strong></span>
  </div>

  <?php if ($flash): ?>
    <div class="form-alert <?= $flashType === 'error' ? 'error' : 'success' ?>"><?= htmlspecialchars($flash) ?></div>
  <?php endif; ?>

  <!-- ================= Balances ================= -->
  <div class="kpi-grid">
    <div class="kpi kpi-success">
      <span class="kpi-ico"><?= ui_icon('wallet') ?></span>
      <span class="kpi-body">
        <span class="kpi-value"><?= wallet_money($balances['available_minor'], $currency) ?></span>
        <span class="kpi-label"><?= $rtl ? 'الرصيد المتاح' : 'Available balance' ?></span>
        <span class="kpi-hint"><?= $rtl ? 'قابل لطلب السحب' : 'Available to withdraw' ?></span>
      </span>
    </div>
    <div class="kpi kpi-navy">
      <span class="kpi-ico"><?= ui_icon('clock') ?></span>
      <span class="kpi-body">
        <span class="kpi-value"><?= wallet_money($balances['pending_minor'], $currency) ?></span>
        <span class="kpi-label"><?= $rtl ? 'رصيد معلّق' : 'Pending balance' ?></span>
        <span class="kpi-hint"><?= $rtl ? 'لم يُحتسب بعد' : 'Not yet cleared' ?></span>
      </span>
    </div>
    <div class="kpi kpi-teal">
      <span class="kpi-ico"><?= ui_icon('paid') ?></span>
      <span class="kpi-body">
        <span class="kpi-value"><?= wallet_money($totals['net_earned_minor'], $currency) ?></span>
        <span class="kpi-label"><?= $rtl ? 'إجمالي الأرباح' : 'Total earnings' ?></span>
        <span class="kpi-hint"><?= $rtl ? 'من إجمالي' : 'of' ?> <?= wallet_money($totals['gross_earned_minor'], $currency) ?></span>
      </span>
    </div>
    <div class="kpi kpi-gold">
      <span class="kpi-ico"><?= ui_icon('receipt') ?></span>
      <span class="kpi-body">
        <span class="kpi-value"><?= wallet_money($totals['commission_minor'], $currency) ?></span>
        <span class="kpi-label"><?= $rtl ? 'عمولة المنصة' : 'Platform commission' ?></span>
        <span class="kpi-hint">
          <?= $rtl ? 'النسبة المطبّقة' : 'Applied rate' ?>:
          <span class="num-ltr"><?= number_format($commissionRate, 3) ?>%</span>
        </span>
      </span>
    </div>
    <div class="kpi kpi-navy">
      <span class="kpi-ico"><?= ui_icon('external') ?></span>
      <span class="kpi-body">
        <span class="kpi-value"><?= wallet_money($totals['withdrawn_minor'] - $totals['reversed_minor'], $currency) ?></span>
        <span class="kpi-label"><?= $rtl ? 'إجمالي المسحوب' : 'Total withdrawn' ?></span>
        <span class="kpi-hint"><?= $rtl ? 'صافي بعد الإرجاع' : 'Net of reversals' ?></span>
      </span>
    </div>
    <div class="kpi <?= $balances['available_minor'] > 0 ? 'kpi-success' : 'kpi-navy' ?>">
      <span class="kpi-ico"><?= ui_icon('receipt') ?></span>
      <span class="kpi-body">
        <span class="kpi-value"><?= wallet_money($balances['total_minor'], $currency) ?></span>
        <span class="kpi-label"><?= $rtl ? 'صافي الرصيد' : 'Net balance' ?></span>
        <span class="kpi-hint"><?= $rtl ? 'متاح + معلّق' : 'Available + pending' ?></span>
      </span>
    </div>
  </div>

  <!-- ================= Withdrawal request ================= -->
  <section class="ws-section">
    <div class="ws-section-head">
      <div>
        <h3><?= $rtl ? 'طلب سحب' : 'Request a withdrawal' ?></h3>
        <p><?= $rtl
              ? 'يُراجع الطلب من فريق المنصة قبل الصرف. لا يُحجز المبلغ إلا بعد الموافقة.'
              : 'The platform team reviews each request before payout. Money is reserved only on approval.' ?></p>
      </div>
    </div>

    <?php if ($balances['available_minor'] <= 0): ?>
      <div class="ws-empty-inline"><?= $rtl
        ? 'لا يوجد رصيد متاح للسحب حالياً. يظهر الرصيد هنا بعد تسجيل دفعة على إحدى فواتيرك.'
        : 'No available balance to withdraw yet. It appears here once a payment is recorded on one of your invoices.' ?></div>
    <?php else: ?>
      <form method="post" class="app-form" style="max-width:640px">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="action" value="request_withdrawal">
        <div class="form-row">
          <label for="wd-amount"><?= $rtl ? 'المبلغ' : 'Amount' ?> (<?= htmlspecialchars($currency) ?>)</label>
          <input type="number" id="wd-amount" name="amount" step="<?= htmlspecialchars(currency_step($currency)) ?>"
                 min="<?= htmlspecialchars(currency_step($currency)) ?>"
                 max="<?= htmlspecialchars(wallet_from_minor($balances['available_minor'], $currency)) ?>"
                 value="<?= htmlspecialchars(wallet_from_minor($balances['available_minor'], $currency)) ?>" required>
          <small class="muted-sm"><?= $rtl ? 'المتاح:' : 'Available:' ?> <?= wallet_money($balances['available_minor'], $currency) ?></small>
        </div>
        <div class="form-row">
          <label for="wd-method"><?= $rtl ? 'طريقة الصرف' : 'Payout method' ?></label>
          <select id="wd-method" name="method">
            <option value="bank_transfer"><?= $rtl ? 'تحويل بنكي' : 'Bank transfer' ?></option>
            <option value="cash"><?= $rtl ? 'نقداً' : 'Cash' ?></option>
            <option value="other"><?= $rtl ? 'أخرى' : 'Other' ?></option>
          </select>
        </div>
        <div class="form-row">
          <label for="wd-note"><?= $rtl ? 'ملاحظة (اختياري)' : 'Note (optional)' ?></label>
          <input type="text" id="wd-note" name="note" maxlength="500">
        </div>
        <?php if ($maskedAccount === ''): ?>
          <p class="muted-sm"><?= $rtl
            ? 'لم تسجّل حساب صرف بعد. أضفه بالأسفل ليتضمنه الطلب.'
            : 'You have not saved a payout account yet. Add one below so the request can carry it.' ?></p>
        <?php else: ?>
          <p class="muted-sm"><?= $rtl ? 'سيُصرف إلى:' : 'Will be paid to:' ?> <span class="num-ltr"><?= htmlspecialchars($maskedAccount) ?></span></p>
        <?php endif; ?>
        <button class="btn btn-primary" type="submit"><?= $rtl ? 'إرسال طلب السحب' : 'Send withdrawal request' ?></button>
      </form>
    <?php endif; ?>
  </section>

  <!-- ================= Payout account ================= -->
  <section class="ws-section">
    <div class="ws-section-head">
      <div>
        <h3><?= $rtl ? 'حساب الصرف' : 'Payout account' ?></h3>
        <p><?= $rtl
              ? 'تُخزَّن بيانات الحساب مشفّرة، ولا تظهر كاملة في أي شاشة أو واجهة برمجية.'
              : 'Account details are stored encrypted and are never shown in full on any screen or API.' ?></p>
      </div>
    </div>
    <?php if ($maskedAccount !== ''): ?>
      <p class="muted-sm">
        <?= $rtl ? 'المستفيد:' : 'Beneficiary:' ?> <strong><?= htmlspecialchars((string)$wallet['payout_holder_name']) ?></strong>
        · <span class="num-ltr"><?= htmlspecialchars($maskedAccount) ?></span>
      </p>
    <?php endif; ?>
    <form method="post" class="app-form" style="max-width:640px">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
      <input type="hidden" name="action" value="save_payout_account">
      <div class="form-row">
        <label for="po-holder"><?= $rtl ? 'اسم المستفيد' : 'Beneficiary name' ?></label>
        <input type="text" id="po-holder" name="holder_name" maxlength="160" required
               value="<?= htmlspecialchars((string)$wallet['payout_holder_name']) ?>">
      </div>
      <div class="form-row">
        <label for="po-bank"><?= $rtl ? 'البنك' : 'Bank' ?></label>
        <input type="text" id="po-bank" name="bank_name" maxlength="120">
      </div>
      <div class="form-row">
        <label for="po-iban"><?= $rtl ? 'رقم الحساب / الآيبان' : 'Account number / IBAN' ?></label>
        <input type="text" id="po-iban" name="iban" maxlength="60" dir="ltr" required>
      </div>
      <button class="btn btn-outline" type="submit"><?= $rtl ? 'حفظ حساب الصرف' : 'Save payout account' ?></button>
    </form>
  </section>

  <!-- ================= Withdrawal history ================= -->
  <section class="ws-section">
    <div class="ws-section-head">
      <div><h3><?= $rtl ? 'سجل طلبات السحب' : 'Withdrawal history' ?></h3></div>
    </div>
    <div class="table-wrap">
      <table class="admin-table">
        <thead><tr>
          <th><?= $rtl ? 'التاريخ' : 'Date' ?></th>
          <th><?= $rtl ? 'المبلغ' : 'Amount' ?></th>
          <th><?= $rtl ? 'الحالة' : 'Status' ?></th>
          <th><?= $rtl ? 'المرجع' : 'Reference' ?></th>
          <th><?= $rtl ? 'ملاحظة المراجعة' : 'Review note' ?></th>
        </tr></thead>
        <tbody>
          <?php if (!$withdrawals): ?>
            <tr><td colspan="5"><?= $rtl ? 'لا توجد طلبات سحب بعد.' : 'No withdrawal requests yet.' ?></td></tr>
          <?php else: foreach ($withdrawals as $w): ?>
            <tr>
              <td data-label="<?= $rtl ? 'التاريخ' : 'Date' ?>"><?= htmlspecialchars(date('Y-m-d', strtotime((string)$w['created_at']))) ?></td>
              <td data-label="<?= $rtl ? 'المبلغ' : 'Amount' ?>"><?= wallet_money((int)$w['amount_minor'], (string)$w['currency']) ?></td>
              <td data-label="<?= $rtl ? 'الحالة' : 'Status' ?>">
                <span class="status-pill status-<?= htmlspecialchars((string)$w['status']) ?>"><?= htmlspecialchars(wallet_withdrawal_status_label((string)$w['status'])) ?></span>
              </td>
              <td data-label="<?= $rtl ? 'المرجع' : 'Reference' ?>"><span class="num-ltr"><?= htmlspecialchars((string)($w['transfer_reference'] ?? '—')) ?></span></td>
              <td data-label="<?= $rtl ? 'ملاحظة المراجعة' : 'Review note' ?>"><?= htmlspecialchars((string)($w['review_note'] ?? '—')) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <!-- ================= Statement ================= -->
  <section class="ws-section">
    <div class="ws-section-head">
      <div>
        <h3><?= $rtl ? 'كشف الحساب' : 'Statement' ?></h3>
        <p><?= $rtl
              ? 'الرصيد الافتتاحي + الحركات = الرصيد الختامي، بالبناء لا بالتقدير.'
              : 'Opening + movements = closing, by construction rather than by estimate.' ?></p>
      </div>
      <form method="get" class="toolbar" style="gap:8px">
        <label class="muted-sm" for="st-from"><?= $rtl ? 'من' : 'From' ?></label>
        <input type="date" id="st-from" name="from" value="<?= htmlspecialchars($from) ?>">
        <label class="muted-sm" for="st-to"><?= $rtl ? 'إلى' : 'To' ?></label>
        <input type="date" id="st-to" name="to" value="<?= htmlspecialchars($to) ?>">
        <button class="btn btn-outline" type="submit"><?= $rtl ? 'عرض' : 'View' ?></button>
      </form>
    </div>

    <div class="kpi-grid">
      <div class="kpi kpi-navy"><span class="kpi-body">
        <span class="kpi-value"><?= wallet_money($statement['opening_minor'], $currency) ?></span>
        <span class="kpi-label"><?= $rtl ? 'الرصيد الافتتاحي' : 'Opening balance' ?></span></span></div>
      <div class="kpi kpi-teal"><span class="kpi-body">
        <span class="kpi-value"><?= wallet_money($statement['movement_minor'], $currency) ?></span>
        <span class="kpi-label"><?= $rtl ? 'صافي الحركة' : 'Net movement' ?></span></span></div>
      <div class="kpi kpi-success"><span class="kpi-body">
        <span class="kpi-value"><?= wallet_money($statement['closing_minor'], $currency) ?></span>
        <span class="kpi-label"><?= $rtl ? 'الرصيد الختامي' : 'Closing balance' ?></span></span></div>
    </div>

    <div class="table-wrap">
      <table class="admin-table">
        <thead><tr>
          <th><?= $rtl ? 'التاريخ' : 'Date' ?></th>
          <th><?= $rtl ? 'النوع' : 'Kind' ?></th>
          <th><?= $rtl ? 'الوصف' : 'Description' ?></th>
          <th><?= $rtl ? 'المبلغ' : 'Amount' ?></th>
          <th><?= $rtl ? 'الحالة' : 'State' ?></th>
        </tr></thead>
        <tbody>
          <?php if (!$statement['rows']): ?>
            <tr><td colspan="5"><?= $rtl ? 'لا توجد حركات في هذه الفترة.' : 'No movements in this period.' ?></td></tr>
          <?php else: foreach ($statement['rows'] as $t):
            $amount = (int)$t['amount_minor']; ?>
            <tr>
              <td data-label="<?= $rtl ? 'التاريخ' : 'Date' ?>"><?= htmlspecialchars(date('Y-m-d H:i', strtotime((string)$t['created_at']))) ?></td>
              <td data-label="<?= $rtl ? 'النوع' : 'Kind' ?>"><?= htmlspecialchars(wallet_kind_label((string)$t['kind'])) ?></td>
              <td data-label="<?= $rtl ? 'الوصف' : 'Description' ?>"><?= htmlspecialchars((string)($t['description'] ?? '—')) ?></td>
              <td data-label="<?= $rtl ? 'المبلغ' : 'Amount' ?>" class="<?= $amount < 0 ? 'text-danger' : '' ?>" style="color:<?= $amount < 0 ? 'var(--danger)' : 'var(--teal)' ?>;font-weight:700">
                <span class="num-ltr"><?= $amount < 0 ? '−' : '+' ?><?= wallet_money(abs($amount), $currency) ?></span>
              </td>
              <td data-label="<?= $rtl ? 'الحالة' : 'State' ?>"><?= $rtl ? ($t['state'] === 'available' ? 'متاح' : 'معلّق') : ucfirst((string)$t['state']) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>
<?php require_once __DIR__ . '/includes/lawyer_footer.php'; ?>
