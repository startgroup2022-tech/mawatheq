<?php
$activePage = 'consultations';
require_once __DIR__ . '/includes/app_header.php';
require_once __DIR__ . '/includes/jurisdiction.php';
require_once __DIR__ . '/includes/consultation_payment.php';

$filterJurisdiction = $_GET['jurisdiction'] ?? '';
$filterSpecialization = $_GET['specialization'] ?? '';

$sql = "SELECT * FROM lawyers WHERE status='approved'";
$params = [];
if ($filterJurisdiction) { $sql .= " AND jurisdiction = ?"; $params[] = $filterJurisdiction; }
if ($filterSpecialization) { $sql .= " AND specialization = ?"; $params[] = $filterSpecialization; }
$sql .= " ORDER BY rating DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$lawyers = $stmt->fetchAll();

require_once __DIR__ . '/includes/specialization_field.php';

$myBookings = $pdo->prepare("SELECT c.*, l.full_name AS lawyer_name FROM consultations c JOIN lawyers l ON l.id=c.lawyer_id WHERE c.user_id=? ORDER BY c.scheduled_at DESC");
$myBookings->execute([$_SESSION['user_id']]);
$myBookings = $myBookings->fetchAll();
?>
<div class="app-content">
  <h2><?= t('feat4_title') ?></h2>
  <p style="margin-bottom:20px"><?= t('feat4_body') ?></p>

  <?php if (isset($_GET['payment_error'])): ?>
    <div class="form-alert error"><?= is_rtl() ? 'تعذّر بدء الدفع الإلكتروني حالياً. لم يتم خصم أي مبلغ. حاول مجدداً لاحقاً.' : 'Online payment could not be started. No amount was charged. Please try again later.' ?><?php $ref = preg_replace('/[^A-Fa-f0-9]/', '', (string)($_GET['ref'] ?? '')); if ($ref !== '') echo ' ' . (is_rtl() ? 'الرقم المرجعي:' : 'Reference:') . ' ' . htmlspecialchars(strtoupper(substr($ref, 0, 8))); ?></div>
  <?php endif; ?>
  <?php if ($myBookings): ?>
  <h3 style="margin-bottom:12px"><?= is_rtl() ? 'حجوزاتي' : 'My bookings' ?></h3>
  <?php foreach ($myBookings as $b): ?>
    <?php $needsPayment = consultation_payment_required($b) && ($b['payment_status'] ?? 'unpaid') !== 'paid'; ?>
    <div class="dash-card" style="display:flex;justify-content:space-between;align-items:center">
      <div>
        <strong><?= htmlspecialchars($b['lawyer_name']) ?></strong>
        <div style="font-size:.82rem;color:var(--text-muted)"><?= date('Y-m-d H:i', strtotime($b['scheduled_at'])) ?> — <?= htmlspecialchars($b['consultation_type']) ?></div>
        <?php if (consultation_payment_required($b)): ?>
          <div style="font-size:.82rem;margin-top:4px">
            <span class="badge <?= ($b['payment_status'] ?? 'unpaid') === 'paid' ? 'badge-success' : 'badge-neutral' ?>">
              <?= ($b['payment_status'] ?? 'unpaid') === 'paid' ? (is_rtl() ? 'مدفوعة' : 'Paid') : (is_rtl() ? 'بانتظار الدفع' : 'Payment due') ?>
            </span>
            <span style="color:var(--text-muted)"><?= money((float)$b['price']) ?></span>
          </div>
        <?php endif; ?>
      </div>
      <div style="display:flex;align-items:center;gap:10px">
        <span class="status-pill status-<?= $b['status'] ?>"><?= htmlspecialchars($b['status']) ?></span>
        <?php if ($needsPayment): ?>
          <form method="post" action="/consultation-pay.php" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="consultation_id" value="<?= (int)$b['id'] ?>">
            <button class="btn btn-primary btn-sm" type="submit"><?= is_rtl() ? 'ادفع الآن' : 'Pay now' ?></button>
          </form>
        <?php elseif (in_array($b['status'], ['pending','confirmed'])): ?>
          <a href="/consultation-room.php?id=<?= $b['id'] ?>" class="btn btn-primary btn-sm"><?= is_rtl() ? 'الدخول للاستشارة' : 'Join' ?></a>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; endif; ?>

  <h3 style="margin:24px 0 12px"><?= is_rtl() ? 'نخبة المحامين' : 'Top lawyers' ?></h3>
  <form method="get" class="dash-card" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
    <div class="field" style="margin:0;min-width:180px">
      <label style="font-size:.8rem"><?= is_rtl() ? 'الدولة' : 'Country' ?></label>
      <?php render_jurisdiction_select('jurisdiction', $filterJurisdiction, true); ?>
    </div>
    <div class="field" style="margin:0;min-width:200px">
      <label style="font-size:.8rem"><?= is_rtl() ? 'التخصص' : 'Specialization' ?></label>
      <div class="select-wrap"><select name="specialization" class="pro-select">
        <option value=""><?= is_rtl() ? 'كل التخصصات' : 'All specializations' ?></option>
        <?php foreach (specialization_options() as $opt): ?>
          <option value="<?= htmlspecialchars($opt) ?>" <?= $filterSpecialization === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
        <?php endforeach; ?>
      </select></div>
    </div>
    <button class="btn btn-primary btn-sm" type="submit"><?= is_rtl() ? 'تصفية' : 'Filter' ?></button>
    <?php if ($filterJurisdiction || $filterSpecialization): ?><a href="/consultations.php" class="btn btn-outline btn-sm"><?= is_rtl() ? 'إعادة تعيين' : 'Reset' ?></a><?php endif; ?>
  </form>
  <?php if (empty($lawyers)): ?>
    <p style="color:var(--text-muted)"><?= is_rtl() ? 'لا يوجد محامون يطابقون هذا البحث.' : 'No lawyers match this search.' ?></p>
  <?php endif; ?>
  <?php foreach ($lawyers as $lw): ?>
    <div class="lawyer-card">
      <div class="lawyer-avatar"><?= mb_substr($lw['full_name'],0,1) ?></div>
      <div style="flex:1">
        <strong><?= htmlspecialchars($lw['full_name']) ?></strong>
        <div style="font-size:.85rem;color:var(--text-muted)"><?= htmlspecialchars($lw['specialization'] ?? '') ?> · <?= $lw['years_experience'] ?> <?= is_rtl() ? 'سنة خبرة' : 'yrs exp.' ?> · 🌍 <?= htmlspecialchars($lw['jurisdiction'] ?? 'السعودية') ?></div>
      </div>
      <a href="/booking.php?lawyer_id=<?= $lw['id'] ?>" class="btn btn-primary"><?= t('hero_cta_book') ?></a>
    </div>
  <?php endforeach; ?>
</div>
<?php require_once __DIR__ . '/includes/app_footer.php'; ?>
