<?php
/**
 * Admin — lawyer wallets overview (Execution 56).
 *
 * Read-only. Moving money lives in wallet-withdrawals.php and wallet-settings.php, so
 * a support operator can see a balance without being able to change one.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';
require_once __DIR__ . '/../includes/wallet.php';

$wallets = wallet_admin_overview($pdo, 300);

// Totals across the platform, in the site currency. Mixed-currency deployments would
// need a per-currency breakdown; this deployment is single-currency by design.
$currency = currency_code();
$totalAvailable = 0; $totalPending = 0;
foreach ($wallets as $w) { $totalAvailable += (int)$w['available_minor']; $totalPending += (int)$w['pending_minor']; }

$activeAdmin = 'wallets';
require_once __DIR__ . '/includes/admin_header.php';
?>
<div class="app-content">
  <div class="pg-head"><div><h1><?= adm('محافظ المحامين', 'Lawyer wallets') ?></h1><p><?= adm(
      'أرصدة محسوبة من دفتر الحركات، لا من عمود مخزَّن. كل رقم قابل للتتبع إلى صف في دفتر المحفظة.',
      'Balances computed from the ledger, never from a stored column. Every figure traces to a wallet_transactions row.'
    ) ?></p></div></div>

  <div class="grid g-3" style="margin-bottom:18px">
    <div class="kpi"><div class="kpi-top"><span class="kpi-ic green"><?= adm_icon('wallet') ?></span></div><div class="kpi-val"><?= h(wallet_money_text($totalAvailable, $currency)) ?></div><div class="kpi-lbl"><?= h(adm('إجمالي المتاح', 'Total available')) ?></div></div>
    <div class="kpi"><div class="kpi-top"><span class="kpi-ic gold"><?= adm_icon('clock') ?></span></div><div class="kpi-val"><?= h(wallet_money_text($totalPending, $currency)) ?></div><div class="kpi-lbl"><?= h(adm('إجمالي المعلّق', 'Total pending')) ?></div></div>
    <div class="kpi"><div class="kpi-top"><span class="kpi-ic"><?= adm_icon('users') ?></span></div><div class="kpi-val"><?= count($wallets) ?></div><div class="kpi-lbl"><?= h(adm('عدد المحافظ', 'Wallets')) ?></div></div>
  </div>

  <div class="table-wrap table-cards">
    <table class="admin-table">
      <thead><tr>
        <th><?= adm('المحامي', 'Lawyer') ?></th>
        <th><?= adm('البريد', 'Email') ?></th>
        <th><?= adm('المتاح', 'Available') ?></th>
        <th><?= adm('المعلّق', 'Pending') ?></th>
        <th><?= adm('الحالة', 'Status') ?></th>
        <th><?= adm('الحساب البنكي', 'Payout account') ?></th>
      </tr></thead>
      <tbody>
        <?php if (!$wallets): ?>
          <tr><td colspan="6" class="empty"><?= adm('لا توجد محافظ بعد. تُنشأ المحفظة تلقائياً عند أول دفعة مسجَّلة على فاتورة محامٍ.', 'No wallets yet. A wallet is created automatically on the first recorded payment against a lawyer invoice.') ?></td></tr>
        <?php else: foreach ($wallets as $w): ?>
          <tr>
            <td data-label="<?= adm('المحامي', 'Lawyer') ?>"><?= h((string)($w['lawyer_name'] ?? '—')) ?></td>
            <td data-label="<?= adm('البريد', 'Email') ?>"><span class="num-ltr"><?= h((string)($w['lawyer_email'] ?? '—')) ?></span></td>
            <td data-label="<?= adm('المتاح', 'Available') ?>"><strong><?= wallet_money((int)$w['available_minor'], (string)$w['currency']) ?></strong></td>
            <td data-label="<?= adm('المعلّق', 'Pending') ?>"><?= wallet_money((int)$w['pending_minor'], (string)$w['currency']) ?></td>
            <td data-label="<?= adm('الحالة', 'Status') ?>"><span class="status-pill status-<?= h((string)$w['status']) ?>"><?= h((string)$w['status']) ?></span></td>
            <td data-label="<?= adm('الحساب البنكي', 'Payout account') ?>">
              <?php $masked = wallet_mask_payout_details(wallet_payout_details($pdo, $w)); ?>
              <span class="num-ltr"><?= h($masked !== '' ? $masked : '—') ?></span>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
