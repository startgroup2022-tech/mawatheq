<?php
/**
 * رحلة العميل — مقارنة المحامين واختيار المحامي.
 *
 * Step 3 of the journey: the approved lawyers who actually provide the chosen
 * specialty, with the facts a customer needs to compare — fee, rating, experience
 * and real availability — and a way to pick one.
 *
 * When no lawyer provides the specialty, this page shows a clear, actionable
 * alternative (the specialties that do have lawyers, plus the general booking route)
 * and never presents an unrelated lawyer as a match.
 */
$activePage = 'consultations';
require_once __DIR__ . '/includes/app_header.php';
require_once __DIR__ . '/includes/legal_journey.php';
require_once __DIR__ . '/includes/journey_ui.php';

$categoryId  = (int)($_GET['category_id'] ?? 0);
$issueTypeId = (int)($_GET['issue_type_id'] ?? $_GET['najiz_case_type_id'] ?? 0);
$jurisdiction = trim((string)($_GET['jurisdiction'] ?? ''));
$maxFee = trim((string)($_GET['max_fee'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));

$ready = legal_journey_ready($pdo);

// Resolve the customer's choice against the real tables; anything unknown is dropped
// rather than carried forward as if it existed.
$choice = $ready ? legal_journey_resolve_choice($pdo, $categoryId, $issueTypeId) : ['specialty_category_id' => null, 'najiz_case_type_id' => null];
$categoryId = (int)($choice['specialty_category_id'] ?? 0);
$issueTypeId = (int)($choice['najiz_case_type_id'] ?? 0);

$category = null;
if ($categoryId > 0) {
    foreach (legal_journey_specialties($pdo) as $s) {
        if ((int)$s['id'] === $categoryId) { $category = $s; break; }
    }
}

$lawyers = $category ? legal_journey_lawyers_for_specialty($pdo, $categoryId, [
    'jurisdiction' => $jurisdiction,
    'max_fee'      => $maxFee,
    'q'            => $q,
]) : [];
$alternative = ($category && empty($lawyers)) ? legal_journey_no_match_alternative($pdo, $categoryId) : null;

// The chosen issue type's customer-facing label, so the summary line reads naturally.
$issueLabel = null;
if ($categoryId > 0 && $issueTypeId > 0) {
    foreach (legal_journey_issue_types($pdo, $categoryId) as $t) {
        if ((int)$t['id'] === $issueTypeId) { $issueLabel = $t['name']; break; }
    }
}
$notSure = legal_journey_not_sure_option();

$extraPageAssets = ['<link rel="stylesheet" href="/assets/css/journey.css">'];

// Keep the journey context on the booking link so booking.php can store it.
$bookQuery = function (int $lawyerId) use ($categoryId, $issueTypeId): string {
    return '/booking.php?lawyer_id=' . $lawyerId
        . '&specialty_category_id=' . $categoryId
        . '&issue_type_id=' . $issueTypeId;
};
?>
<div class="app-content">
  <div class="toolbar">
    <div>
      <h2><?= is_rtl() ? 'المحامون المتاحون' : 'Available lawyers' ?></h2>
      <p><?= is_rtl() ? 'قارن بين المحامين واختر الأنسب لك.' : 'Compare lawyers and pick the one that suits you.' ?></p>
    </div>
  </div>

  <ol class="journey-steps">
    <li class="is-done"><span class="n">1</span><?= is_rtl() ? 'التخصص' : 'Specialty' ?></li>
    <li class="is-done"><span class="n">2</span><?= is_rtl() ? 'نوع المشكلة' : 'Issue type' ?></li>
    <li class="is-current"><span class="n">3</span><?= is_rtl() ? 'اختيار المحامي' : 'Choose lawyer' ?></li>
    <li><span class="n">4</span><?= is_rtl() ? 'إرسال الطلب' : 'Submit' ?></li>
  </ol>

  <?php if (!$category): ?>
    <div class="journey-empty">
      <div class="ico">🤔</div>
      <h3><?= is_rtl() ? 'لم نتمكن من تحديد التخصص' : 'We could not determine the specialty' ?></h3>
      <p><?= is_rtl() ? 'يرجى اختيار التخصص من البداية.' : 'Please choose the specialty from the start.' ?></p>
      <a href="/consultation-journey.php" class="btn btn-primary"><?= is_rtl() ? 'ابدأ من جديد' : 'Start again' ?></a>
    </div>
  <?php else: ?>

    <div class="dash-card" style="display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap">
      <div>
        <div style="font-size:.78rem;color:var(--text-muted);font-weight:700"><?= is_rtl() ? 'اختيارك' : 'Your choice' ?></div>
        <strong style="font-size:1.05rem;color:var(--navy-dark)"><?= htmlspecialchars($category['name']) ?></strong>
        <div style="font-size:.85rem;color:var(--text-muted);margin-top:3px">
          <?= htmlspecialchars($issueLabel ?? $notSure['name']) ?>
        </div>
      </div>
      <a href="/consultation-journey.php?category_id=<?= (int)$categoryId ?>" class="btn btn-outline btn-sm"><?= is_rtl() ? 'تعديل الاختيار' : 'Edit choice' ?></a>
    </div>

    <form method="get" class="journey-search">
      <input type="hidden" name="category_id" value="<?= (int)$categoryId ?>">
      <input type="hidden" name="issue_type_id" value="<?= (int)$issueTypeId ?>">
      <div class="field">
        <label for="q"><?= is_rtl() ? 'ابحث باسم المحامي' : 'Search by lawyer name' ?></label>
        <input type="search" id="q" name="q" value="<?= htmlspecialchars($q) ?>">
      </div>
      <div class="field" style="max-width:180px">
        <label for="max_fee"><?= is_rtl() ? 'أعلى أجر ساعي' : 'Max hourly fee' ?></label>
        <input type="number" id="max_fee" name="max_fee" min="0" step="1" value="<?= htmlspecialchars($maxFee) ?>">
      </div>
      <button class="btn btn-primary" type="submit"><?= is_rtl() ? 'تصفية' : 'Filter' ?></button>
    </form>

    <?php if (empty($lawyers)): ?>
      <!-- Requirement 11: a clear alternative, never an unrelated lawyer. -->
      <div class="journey-empty">
        <div class="ico">📭</div>
        <h3><?= htmlspecialchars($alternative['reason']) ?></h3>
        <p><?= htmlspecialchars($alternative['suggest']) ?></p>
        <a href="/consultation-journey.php" class="btn btn-primary"><?= is_rtl() ? 'اختيار تخصص آخر' : 'Choose another specialty' ?></a>
      </div>
      <?php if (!empty($alternative['specialties'])): ?>
        <h3 style="margin:22px 0 12px"><?= is_rtl() ? 'تخصصات تتوفر بها محامون الآن' : 'Specialties with lawyers available now' ?></h3>
        <div class="specialty-grid">
          <?php foreach ($alternative['specialties'] as $s): ?>
            <a class="specialty-card" href="/consultation-journey.php?category_id=<?= (int)$s['id'] ?>">
              <span class="ico" aria-hidden="true"><?= journey_icon_svg($s['icon_key']) ?></span>
              <h3><?= htmlspecialchars($s['name']) ?></h3>
              <p><?= htmlspecialchars($s['description']) ?></p>
              <span class="meta"><span class="count"><?= (int)$s['lawyer_count'] ?> <?= is_rtl() ? 'محامٍ' : 'lawyers' ?></span><span>›</span></span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
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
              </div>
            </div>

            <div class="compare-facts">
              <div class="fact">
                <div class="k"><?= is_rtl() ? 'الأجر بالساعة' : 'Hourly fee' ?></div>
                <div class="v ltr"><?= $lw['hourly_rate'] !== null ? htmlspecialchars(currency_number($lw['hourly_rate']) . ' ' . currency_symbol()) : (is_rtl() ? 'حسب الاتفاق' : 'On request') ?></div>
              </div>
              <div class="fact">
                <div class="k"><?= is_rtl() ? 'التقييم' : 'Rating' ?></div>
                <div class="v">★ <?= $lw['rating'] !== null ? htmlspecialchars(number_format($lw['rating'], 1)) : '—' ?></div>
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
              <a href="<?= htmlspecialchars($bookQuery((int)$lw['id'])) ?>" class="btn btn-primary" style="flex:1"><?= is_rtl() ? 'اختيار هذا المحامي' : 'Choose this lawyer' ?></a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/app_footer.php'; ?>
