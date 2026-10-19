<?php
/**
 * Affiliate dashboard (Execution 31).
 *
 * The overview an affiliate lands on: what they have earned, what is still in hold,
 * what they can request, and the one action they are most likely to want — copy
 * their link.
 *
 * Every figure is derived from the commission rows at render time rather than read
 * from a stored total, so the dashboard cannot disagree with the ledger.
 */
$activeAffiliate = 'dashboard';
require_once __DIR__ . '/../includes/affiliate_header.php';
require_once __DIR__ . '/../includes/affiliate_conversion.php';
require_once __DIR__ . '/../includes/affiliate_payout.php';


$affiliateId = (int)$affiliate['id'];
$currency = (string)$affiliate['payout_currency'];
$summary = affiliate_earnings_summary($pdo, $affiliateId, ['currency' => $currency]);
$payableMinor = affiliate_payable_minor($pdo, $affiliateId, $currency);
$openRequest = affiliate_open_payout_request($pdo, $affiliateId);
$settings = affiliate_settings($pdo);

// Clicks and unique visitors over the last 30 days, from the daily aggregates.
$stats = ['clicks' => 0, 'unique_visitors' => 0, 'conversions' => 0, 'qualified_conversions' => 0];
try {
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(clicks),0) clicks, COALESCE(SUM(unique_visitors),0) uv,
            COALESCE(SUM(conversions),0) conv, COALESCE(SUM(qualified_conversions),0) qconv
        FROM affiliate_daily_stats WHERE affiliate_id=? AND stat_date >= (CURDATE() - INTERVAL 30 DAY)');
    $stmt->execute([$affiliateId]);
    $stats = $stmt->fetch() ?: $stats;
} catch (Throwable $e) { /* pre-v57 database */ }

// Conversion rate over the same window. Guarded against a zero denominator.
$clickCount = (int)$stats['clicks'];
$convRate = $clickCount > 0 ? round(((int)$stats['qualified_conversions'] / $clickCount) * 100, 1) : 0.0;

$recentCommissions = [];
try {
    $stmt = $pdo->prepare('SELECT m.*, c.service_key, c.converted_at FROM affiliate_commissions m
        JOIN affiliate_conversions c ON c.id = m.conversion_id
        WHERE m.affiliate_id=? AND m.is_demo=0 ORDER BY m.id DESC LIMIT 8');
    $stmt->execute([$affiliateId]);
    $recentCommissions = $stmt->fetchAll();
} catch (Throwable $e) { /* pre-v57 database */ }

$mainLink = affiliate_build_link($affiliate, '/');
$canRequest = $payableMinor > 0 && !$openRequest && affiliate_can_earn($affiliate);
$minPayoutMinor = affiliate_to_minor($settings['min_payout'], $currency);
$affStatus = (string)$affiliate['status'];
$affNeedsTerms = in_array($affStatus, ['approved', 'active'], true)
    && !(affiliate_has_accepted($pdo, $affiliateId, 'terms') && affiliate_has_accepted($pdo, $affiliateId, 'privacy'));
?>
<div class="app-page">
  <div class="app-page-head">
    <div>
      <h1><?= ui_e(is_rtl() ? 'مرحباً، ' : 'Welcome, ') ?><?= ui_e((string)$affiliate['full_name']) ?></h1>
      <p class="muted"><?= ui_e(is_rtl() ? 'نظرة سريعة على أداء إحالاتك وأرباحك خلال آخر 30 يوماً.' : 'A quick view of your referrals and earnings over the last 30 days.') ?></p>
    </div>
    <span class="status-pill <?= ui_e(affiliate_status_class((string)$affiliate['status'])) ?>"><?= ui_e(affiliate_status_label((string)$affiliate['status'])) ?></span>
  </div>

  <?php // An account that can sign in but cannot yet earn is told exactly where it stands
        // and what happens next, rather than being shown figures that will stay at zero.
  if ($affStatus === 'pending' || $affStatus === 'under_review'): ?>
    <div class="form-alert aff-state-banner">
      <strong><?= ui_e($affStatus === 'under_review'
        ? (is_rtl() ? 'حسابك تحت المراجعة الآن' : 'Your account is being reviewed')
        : (is_rtl() ? 'حسابك قيد المراجعة' : 'Your account is under review')) ?></strong>
      <p><?= ui_e(is_rtl()
        ? 'سيراجع فريق مواثيق بياناتك ويتواصل معك على بريدك المسجّل. حتى يتم الاعتماد لا تُفعّل روابط الإحالة ولا تُحتسب أي عمولات — لكن يمكنك تسجيل الدخول في أي وقت لمتابعة الحالة.'
        : 'The Mawatheq team will review your details and contact you at the email you registered. Until the review is approved your referral links are not active and no commission can accrue — but you can sign in at any time to follow the status.') ?></p>
      <p class="muted"><?= ui_e(is_rtl() ? 'رقم حسابك: ' : 'Your account number: ') ?><strong class="aff-mono"><?= ui_e((string)$affiliate['code']) ?></strong></p>
    </div>
  <?php elseif ($affStatus === 'approved' && $affNeedsTerms): ?>
    <div class="form-alert aff-state-banner">
      <strong><?= ui_e(is_rtl() ? 'تم اعتماد حسابك — تبقّت خطوة واحدة' : 'Your account is approved — one step left') ?></strong>
      <p><?= ui_e(is_rtl()
        ? 'اعتمد فريق مواثيق حسابك. اقبل شروط البرنامج وسياسة الخصوصية لتفعيل حسابك وبدء استخدام روابط الإحالة.'
        : 'The Mawatheq team approved your account. Accept the programme terms and the privacy policy to activate it and start using your referral links.') ?></p>
      <a class="btn btn-gold" href="/affiliate/terms.php"><?= ui_e(is_rtl() ? 'قبول الشروط والتفعيل' : 'Accept terms and activate') ?></a>
    </div>
  <?php elseif ($affStatus === 'suspended'): ?>
    <div class="form-alert error aff-state-banner">
      <strong><?= ui_e(is_rtl() ? 'حسابك موقوف مؤقتاً' : 'Your account is suspended') ?></strong>
      <p><?= ui_e(is_rtl()
        ? 'لا تُحتسب عمولات جديدة أثناء الإيقاف. سجلّك وأرباحك السابقة محفوظة. تواصل مع فريق البرنامج للمراجعة.'
        : 'No new commission accrues while the account is suspended. Your history and previous earnings are preserved. Contact the programme team to review it.') ?></p>
    </div>
  <?php endif; ?>

  <?php if ($affiliateCanEarn): ?>
  <section class="aff-linkbox">
    <div class="aff-linkbox-main">
      <span class="aff-linkbox-label"><?= ui_e(is_rtl() ? 'رابط الإحالة الرئيسي' : 'Your main referral link') ?></span>
      <div class="aff-linkbox-row">
        <input type="text" readonly value="<?= ui_e($mainLink) ?>" id="affMainLink" aria-label="<?= ui_e(is_rtl() ? 'رابط الإحالة' : 'Referral link') ?>">
        <button type="button" class="btn btn-gold" data-copy-target="#affMainLink" data-copy-done="<?= ui_e(is_rtl() ? 'تم النسخ' : 'Copied') ?>"><?= ui_e(is_rtl() ? 'نسخ' : 'Copy') ?></button>
      </div>
    </div>
    <div class="aff-linkbox-actions">
      <a class="btn btn-outline" href="/affiliate/links.php"><?= ui_e(is_rtl() ? 'إدارة الروابط' : 'Manage links') ?></a>
      <a class="btn btn-outline" href="/affiliate/materials.php"><?= ui_e(is_rtl() ? 'المواد التسويقية' : 'Marketing materials') ?></a>
    </div>
  </section>
  <?php endif; ?>

  <div class="aff-stat-grid">
    <div class="aff-stat">
      <span class="aff-stat-label"><?= ui_e(is_rtl() ? 'النقرات (30 يوماً)' : 'Clicks (30 days)') ?></span>
      <span class="aff-stat-value"><?= number_format($clickCount) ?></span>
    </div>
    <div class="aff-stat">
      <span class="aff-stat-label"><?= ui_e(is_rtl() ? 'زوار فريدون' : 'Unique visitors') ?></span>
      <span class="aff-stat-value"><?= number_format((int)$stats['unique_visitors']) ?></span>
    </div>
    <div class="aff-stat">
      <span class="aff-stat-label"><?= ui_e(is_rtl() ? 'تحويلات مؤهلة' : 'Qualified conversions') ?></span>
      <span class="aff-stat-value"><?= number_format((int)$stats['qualified_conversions']) ?></span>
    </div>
    <div class="aff-stat">
      <span class="aff-stat-label"><?= ui_e(is_rtl() ? 'معدل التحويل' : 'Conversion rate') ?></span>
      <span class="aff-stat-value"><?= ui_e((string)$convRate) ?>%</span>
    </div>
  </div>

  <div class="aff-money-grid">
    <div class="aff-money aff-money-primary">
      <span class="aff-money-label"><?= ui_e(is_rtl() ? 'متاح للصرف' : 'Available to request') ?></span>
      <span class="aff-money-value"><?= ui_e(affiliate_format_money($summary['available'], $currency)) ?></span>
      <?php if ($openRequest): ?>
        <span class="aff-money-note"><?= ui_e(is_rtl() ? 'لديك طلب صرف قيد المعالجة.' : 'You have a payout request in progress.') ?></span>
      <?php elseif ($payableMinor > 0 && $payableMinor < $minPayoutMinor): ?>
        <span class="aff-money-note"><?= ui_e(is_rtl() ? 'الحد الأدنى للصرف: ' : 'Minimum payout: ') ?><?= ui_e(affiliate_format_money($settings['min_payout'], $currency)) ?></span>
      <?php elseif ($canRequest): ?>
        <a class="btn btn-gold btn-sm" href="/affiliate/payouts.php"><?= ui_e(is_rtl() ? 'طلب صرف' : 'Request payout') ?></a>
      <?php endif; ?>
    </div>
    <div class="aff-money">
      <span class="aff-money-label"><?= ui_e(is_rtl() ? 'قيد فترة الانتظار' : 'In hold period') ?></span>
      <span class="aff-money-value"><?= ui_e(affiliate_format_money($summary['holding'], $currency)) ?></span>
      <span class="aff-money-note"><?= ui_e(is_rtl() ? 'تُصبح متاحة بعد انتهاء مدة الانتظار.' : 'Becomes available once the hold period ends.') ?></span>
    </div>
    <div class="aff-money">
      <span class="aff-money-label"><?= ui_e(is_rtl() ? 'إجمالي المصروف' : 'Total paid out') ?></span>
      <span class="aff-money-value"><?= ui_e(affiliate_format_money($summary['paid_out'], $currency)) ?></span>
      <span class="aff-money-note"><?= ui_e((string)$summary['paid_conversions']) ?> <?= ui_e(is_rtl() ? 'تحويل' : 'conversions') ?></span>
    </div>
  </div>

  <section class="app-card">
    <div class="app-card-head">
      <h2><?= ui_e(is_rtl() ? 'أحدث العمولات' : 'Recent commissions') ?></h2>
      <a class="link-more" href="/affiliate/earnings.php"><?= ui_e(is_rtl() ? 'عرض الكل' : 'View all') ?></a>
    </div>
    <?php if (!$recentCommissions): ?>
      <?= ui_empty_state(
        is_rtl() ? 'لا توجد عمولات بعد' : 'No commissions yet',
        $affiliateCanEarn
          ? (is_rtl() ? 'شارك رابط الإحالة لتبدأ بتسجيل التحويلات.' : 'Share your referral link to start recording conversions.')
          : (is_rtl() ? 'اعتمد الشروط لتفعيل حسابك.' : 'Accept the terms to activate your account.'),
        'wallet'
      ) ?>
    <?php else: ?>
      <div class="table-wrap">
        <table class="admin-table">
          <thead><tr>
            <th><?= ui_e(is_rtl() ? 'الخدمة' : 'Service') ?></th>
            <th><?= ui_e(is_rtl() ? 'الأساس' : 'Base') ?></th>
            <th><?= ui_e(is_rtl() ? 'العمولة' : 'Commission') ?></th>
            <th><?= ui_e(is_rtl() ? 'الحالة' : 'Status') ?></th>
            <th><?= ui_e(is_rtl() ? 'التاريخ' : 'Date') ?></th>
          </tr></thead>
          <tbody>
            <?php foreach ($recentCommissions as $row): ?>
              <tr>
                <td><?= ui_e(affiliate_service_label(affiliate_service_find($pdo, (string)$row['service_key']), (string)$row['service_key'])) ?></td>
                <td class="aff-mono"><?= ui_e(affiliate_format_money($row['base_amount'], (string)$row['currency'])) ?></td>
                <td class="aff-mono"><?= ui_e(affiliate_format_money($row['commission_amount'], (string)$row['currency'])) ?></td>
                <td><span class="status-pill <?= ui_e(affiliate_status_class((string)$row['status'])) ?>"><?= ui_e(affiliate_status_label((string)$row['status'])) ?></span></td>
                <td><?= ui_e(date('Y-m-d', strtotime((string)$row['converted_at']))) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>
<?php require_once __DIR__ . '/../includes/affiliate_footer.php'; ?>
