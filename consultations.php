<?php
/**
 * استشاراتي + دليل المحامين — the client's consultations page.
 *
 * Two jobs on one page: the client's own bookings (with pay / join actions) and the
 * professional lawyer directory they book from.
 *
 * The directory filters are the SAME set the mobile app and the journey use — search,
 * specialty, country, city (dependent on country), experience band, fee range and real
 * availability — and they run through `legal_journey_directory_search()`, the one shared
 * implementation, so the website and the app cannot return different lawyers for the
 * same filters. Every filter is applied server-side with bound parameters, and a lawyer
 * with no open slot is removed rather than shown with an empty availability line.
 */
$activePage = 'consultations';
require_once __DIR__ . '/includes/app_header.php';
require_once __DIR__ . '/includes/legal_journey.php';
require_once __DIR__ . '/includes/journey_ui.php';
require_once __DIR__ . '/includes/currency.php';
require_once __DIR__ . '/includes/consultation_payment.php';
require_once __DIR__ . '/includes/specialization_field.php';

$rtl = is_rtl();

// The professional filters, normalised by the shared normaliser the API and journey use.
$filters = legal_journey_filter_params($_GET);
$lawyers = legal_journey_directory_search($pdo, $_GET);
$resultCount = count($lawyers);
$filtersActive = $filters['jurisdiction'] !== '' || $filters['specialization'] !== ''
    || $filters['country'] !== '' || $filters['city'] !== ''
    || $filters['experience'] !== '' || $filters['min_fee'] !== null
    || $filters['max_fee'] !== null || $filters['available_only'] || $filters['q'] !== '';
$filterOptions = legal_journey_filter_options($pdo);

$myBookings = $pdo->prepare("SELECT c.*, l.full_name AS lawyer_name FROM consultations c JOIN lawyers l ON l.id=c.lawyer_id WHERE c.user_id=? ORDER BY c.scheduled_at DESC");
$myBookings->execute([$_SESSION['user_id']]);
$myBookings = $myBookings->fetchAll();

$extraPageAssets = ['<link rel="stylesheet" href="/assets/css/journey.css">'];
?>
<div class="app-content">
  <h1><?= t('feat4_title') ?></h1>
  <p style="margin-bottom:20px"><?= t('feat4_body') ?></p>

  <?php if (isset($_GET['payment_error'])): ?>
    <div class="form-alert error"><?= is_rtl() ? 'تعذّر بدء الدفع الإلكتروني حالياً. لم يتم خصم أي مبلغ. حاول مجدداً لاحقاً.' : 'Online payment could not be started. No amount was charged. Please try again later.' ?><?php $ref = preg_replace('/[^A-Fa-f0-9]/', '', (string)($_GET['ref'] ?? '')); if ($ref !== '') echo ' ' . (is_rtl() ? 'الرقم المرجعي:' : 'Reference:') . ' ' . htmlspecialchars(strtoupper(substr($ref, 0, 8))); ?></div>
  <?php endif; ?>

  <?php if ($myBookings): ?>
  <h2 style="margin-bottom:12px"><?= is_rtl() ? 'حجوزاتي' : 'My bookings' ?></h2>
  <?php foreach ($myBookings as $b): ?>
    <?php $needsPayment = consultation_payment_required($b) && ($b['payment_status'] ?? 'unpaid') !== 'paid'; ?>
    <div class="dash-card" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
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
        <span class="status-pill status-<?= htmlspecialchars($b['status']) ?>"><?= htmlspecialchars($b['status']) ?></span>
        <?php if ($needsPayment): ?>
          <form method="post" action="/consultation-pay.php" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="consultation_id" value="<?= (int)$b['id'] ?>">
            <button class="btn btn-primary btn-sm" type="submit"><?= is_rtl() ? 'ادفع الآن' : 'Pay now' ?></button>
          </form>
        <?php elseif (in_array($b['status'], ['pending','confirmed'], true)): ?>
          <a href="/consultation-room.php?id=<?= (int)$b['id'] ?>" class="btn btn-primary btn-sm"><?= is_rtl() ? 'الدخول للاستشارة' : 'Join' ?></a>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
  <?php endif; ?>

  <h2 style="margin:24px 0 12px"><?= is_rtl() ? 'ابحث عن محامٍ' : 'Find a lawyer' ?></h2>

  <?php
  $cityJson = [];
  foreach ($filterOptions['cities'] as $code => $list) $cityJson[$code] = array_values($list);
  ?>
  <script type="application/json" id="mawatheq-city-data"><?= json_encode($cityJson, JSON_UNESCAPED_UNICODE) ?></script>

  <form method="get" class="journey-search" data-lawyer-form>
    <div class="field">
      <label for="q"><?= is_rtl() ? 'ابحث باسم المحامي' : 'Search by lawyer name' ?></label>
      <input type="search" id="q" name="q" value="<?= htmlspecialchars($filters['q']) ?>">
    </div>

    <div class="field">
      <label for="f_specialization"><?= is_rtl() ? 'التخصص' : 'Specialization' ?></label>
      <div class="select-wrap"><select id="f_specialization" name="specialization" class="pro-select">
        <option value=""><?= is_rtl() ? 'كل التخصصات' : 'All specializations' ?></option>
        <?php foreach (specialization_options() as $opt): ?>
          <option value="<?= htmlspecialchars($opt) ?>" <?= $filters['specialization'] === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
        <?php endforeach; ?>
      </select></div>
    </div>

    <div class="field">
      <label for="f_country"><?= is_rtl() ? 'الدولة' : 'Country' ?></label>
      <div class="select-wrap"><select id="f_country" name="country" class="pro-select" data-country-select>
        <option value=""><?= is_rtl() ? 'كل الدول' : 'All countries' ?></option>
        <?php foreach ($filterOptions['countries'] as $c): ?>
          <option value="<?= htmlspecialchars($c['code']) ?>" <?= $filters['country'] === $c['code'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
        <?php endforeach; ?>
      </select></div>
    </div>

    <div class="field">
      <label for="f_city"><?= is_rtl() ? 'المدينة' : 'City' ?></label>
      <?php render_city_select('city', $filters['country'] !== '' ? $filters['country'] : null, $filters['city'] !== '' ? $filters['city'] : null, false, 'f_city'); ?>
    </div>

    <div class="field">
      <label for="f_experience"><?= is_rtl() ? 'سنوات الخبرة' : 'Experience' ?></label>
      <div class="select-wrap"><select id="f_experience" name="experience" class="pro-select">
        <option value=""><?= is_rtl() ? 'كل مستويات الخبرة' : 'All experience levels' ?></option>
        <?php foreach ($filterOptions['experience'] as $band): ?>
          <option value="<?= htmlspecialchars($band['key']) ?>" <?= $filters['experience'] === $band['key'] ? 'selected' : '' ?>><?= htmlspecialchars($band['label']) ?></option>
        <?php endforeach; ?>
      </select></div>
    </div>

    <div class="field">
      <label for="min_fee"><?= is_rtl() ? 'أقل سعر استشارة' : 'Min consultation price' ?></label>
      <input type="number" step="<?= currency_step() ?>" min="0" id="min_fee" name="min_fee" value="<?= $filters['min_fee'] !== null ? htmlspecialchars((string)$filters['min_fee']) : '' ?>" class="ltr">
    </div>
    <div class="field">
      <label for="max_fee"><?= is_rtl() ? 'أعلى سعر استشارة' : 'Max consultation price' ?></label>
      <input type="number" step="<?= currency_step() ?>" min="0" id="max_fee" name="max_fee" value="<?= $filters['max_fee'] !== null ? htmlspecialchars((string)$filters['max_fee']) : '' ?>" class="ltr">
    </div>

    <div class="field" style="align-self:end">
      <label class="check-row" for="f_available" style="display:flex;align-items:center;gap:8px;min-height:44px">
        <input type="checkbox" id="f_available" name="available_only" value="1" <?= $filters['available_only'] ? 'checked' : '' ?>>
        <span><?= is_rtl() ? 'المتاحون بمواعيد فقط' : 'Only with open slots' ?></span>
      </label>
    </div>

    <div class="journey-filter-actions">
      <button class="btn btn-primary" type="submit"><?= is_rtl() ? 'تصفية' : 'Filter' ?></button>
      <?php if ($filtersActive): ?>
        <a class="btn btn-outline" href="/consultations.php"><?= is_rtl() ? 'مسح الفلاتر' : 'Clear filters' ?></a>
      <?php endif; ?>
    </div>
  </form>

  <div class="journey-result-count" role="status">
    <?= is_rtl()
        ? 'عدد المحامين المطابقين: <strong>' . (int)$resultCount . '</strong>'
        : '<strong>' . (int)$resultCount . '</strong> matching lawyers' ?>
  </div>

  <?php if (empty($lawyers)): ?>
    <div class="journey-empty">
      <div class="ico">🔍</div>
      <h3><?= is_rtl() ? 'لا يوجد محامٍ يطابق البحث' : 'No lawyer matches this search' ?></h3>
      <p><?= is_rtl() ? 'جرّب توسيع نطاق البحث أو مسح الفلاتر لعرض جميع المحامين.' : 'Try widening the search or clearing the filters to see all lawyers.' ?></p>
      <a href="/consultations.php" class="btn btn-primary"><?= is_rtl() ? 'مسح الفلاتر' : 'Clear filters' ?></a>
    </div>
  <?php else: ?>
    <div class="compare-grid">
      <?php foreach ($lawyers as $lw): $av = $lw['availability']; ?>
        <div class="compare-card">
          <div class="compare-head">
            <img src="<?= journey_avatar($lw['photo_path']) ?>" alt="<?= htmlspecialchars($lw['full_name']) ?>" onerror="this.src='/assets/img/avatar-placeholder.svg'">
            <div class="who">
              <strong><?= htmlspecialchars($lw['full_name']) ?></strong>
              <div class="sub">
                <?php if (!empty($lw['specialization'])): ?><?= htmlspecialchars($lw['specialization']) ?> · <?php endif; ?>
                <?= (int)$lw['years_experience'] ?> <?= is_rtl() ? 'سنة خبرة' : 'yrs experience' ?>
              </div>
              <?php if (!empty($lw['location'])): ?>
                <div class="sub loc"><span aria-hidden="true">📍</span> <?= htmlspecialchars($lw['location']) ?></div>
              <?php endif; ?>
            </div>
          </div>

          <div class="compare-facts">
            <div class="fact">
              <div class="k"><?= is_rtl() ? 'سعر الاستشارة' : 'Consultation price' ?></div>
              <div class="v ltr"><?php
                if (!empty($lw['pricing']['has_price'])) echo htmlspecialchars(money_text($lw['pricing']['total']));
                else echo is_rtl() ? 'حسب الاتفاق' : 'On request';
              ?></div>
            </div>
            <div class="fact">
              <div class="k"><?= is_rtl() ? 'المدة' : 'Duration' ?></div>
              <div class="v ltr"><?= (int)($lw['duration_minutes'] ?? 30) ?> <?= is_rtl() ? 'دقيقة' : 'min' ?></div>
            </div>
          </div>

          <div class="avail-line">
            <?php if (!$av['has_published_hours']): ?>
              <span class="avail-chip none"><?= is_rtl() ? 'لم يحدد أوقات دوام — سيؤكد الموعد' : 'No published hours — will confirm' ?></span>
            <?php elseif (empty($av['next_slots'])): ?>
              <span class="avail-chip none"><?= is_rtl() ? 'لا توجد أوقات متاحة قريبًا' : 'No slots open soon' ?></span>
            <?php else: ?>
              <span><?= is_rtl() ? 'أقرب الأوقات:' : 'Next available:' ?></span>
              <?php foreach ($av['next_slots'] as $slot): ?>
                <span class="avail-chip ltr"><?= htmlspecialchars($slot['date']) ?> <?= htmlspecialchars($slot['time']) ?></span>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>

          <div class="compare-actions">
            <a href="/lawyer-profile.php?id=<?= (int)$lw['id'] ?>" class="btn btn-outline" style="flex:1"><?= is_rtl() ? 'عرض الملف' : 'View profile' ?></a>
            <a href="/booking.php?lawyer_id=<?= (int)$lw['id'] ?>" class="btn btn-primary" style="flex:1"><?= is_rtl() ? 'حجز استشارة' : 'Book consultation' ?></a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<script src="<?= asset_url('/assets/js/lawyer-form.js') ?>"></script>
<?php require_once __DIR__ . '/includes/app_footer.php'; ?>
