<?php
/**
 * رحلة العميل — اختيار التخصص ونوع المشكلة.
 *
 * Step 1 of the customer consultation journey: pick a legal specialty, then a plain
 * description of the problem. The chosen specialty and issue type travel to
 * consultation-journey-lawyers.php, which lists the lawyers who provide them.
 *
 * Everything shown here comes from the real classification via includes/legal_journey.php,
 * and every string is emitted through legal_journey_customer_text(), so the internal
 * classification's name and vocabulary never reach the customer.
 */
$activePage = 'consultations';
require_once __DIR__ . '/includes/app_header.php';
require_once __DIR__ . '/includes/legal_journey.php';
require_once __DIR__ . '/includes/journey_ui.php';

$q = trim((string)($_GET['q'] ?? ''));
$categoryId = (int)($_GET['category_id'] ?? 0);
$issueTypeId = (int)($_GET['issue_type_id'] ?? 0);

$ready = legal_journey_ready($pdo);
$specialties = $ready ? legal_journey_specialties($pdo, $q) : [];

// The selected specialty, when the customer is choosing an issue type.
$selectedCategory = null;
if ($categoryId > 0 && $ready) {
    foreach (legal_journey_specialties($pdo) as $s) {
        if ((int)$s['id'] === $categoryId) { $selectedCategory = $s; break; }
    }
}
$issueTypes = $selectedCategory ? legal_journey_issue_types($pdo, $categoryId, $q) : [];
$notSure = legal_journey_not_sure_option();

// Group the issue types by their real grouping label, preserving order.
$grouped = [];
foreach ($issueTypes as $t) {
    $grouped[$t['group']][] = $t;
}

$step = $selectedCategory ? 2 : 1;
$extraPageAssets = ['<link rel="stylesheet" href="/assets/css/journey.css">'];
?>
<div class="app-content">
  <div class="toolbar">
    <div>
      <h2><?= is_rtl() ? 'احجز استشارتك' : 'Book your consultation' ?></h2>
      <p><?= is_rtl() ? 'اختر تخصصك القانوني ثم صف مشكلتك، وسنعرض لك المحامين المتاحين.' : 'Choose your legal specialty, describe your issue, and we will show you the available lawyers.' ?></p>
    </div>
  </div>

  <ol class="journey-steps">
    <li class="<?= $step === 1 ? 'is-current' : 'is-done' ?>"><span class="n">1</span><?= is_rtl() ? 'التخصص' : 'Specialty' ?></li>
    <li class="<?= $step === 2 ? 'is-current' : '' ?>"><span class="n">2</span><?= is_rtl() ? 'نوع المشكلة' : 'Issue type' ?></li>
    <li><span class="n">3</span><?= is_rtl() ? 'اختيار المحامي' : 'Choose lawyer' ?></li>
    <li><span class="n">4</span><?= is_rtl() ? 'إرسال الطلب' : 'Submit' ?></li>
  </ol>

  <?php if (!$ready): ?>
    <div class="journey-empty">
      <div class="ico">⚙️</div>
      <h3><?= is_rtl() ? 'الخدمة قيد التهيئة' : 'Service is being set up' ?></h3>
      <p><?= is_rtl() ? 'لم يتم تفعيل دليل التخصصات بعد. يمكنك تصفح المحامين مباشرة.' : 'The specialty guide is not enabled yet. You can browse lawyers directly.' ?></p>
      <a href="/consultations.php" class="btn btn-primary"><?= is_rtl() ? 'تصفح المحامين' : 'Browse lawyers' ?></a>
    </div>
  <?php elseif (!$selectedCategory): ?>

    <!-- ============ STEP 1: legal specialties ============ -->
    <form method="get" class="journey-search" role="search">
      <div class="field">
        <label for="q"><?= is_rtl() ? 'ابحث عن تخصصك القانوني' : 'Search your legal specialty' ?></label>
        <input type="search" id="q" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="<?= is_rtl() ? 'مثال: العمل، الأسرة، العقود…' : 'e.g. labour, family, contracts…' ?>">
      </div>
      <button class="btn btn-primary" type="submit"><?= is_rtl() ? 'بحث' : 'Search' ?></button>
      <?php if ($q !== ''): ?><a href="/consultation-journey.php" class="btn btn-outline"><?= is_rtl() ? 'إعادة تعيين' : 'Reset' ?></a><?php endif; ?>
    </form>

    <?php if (empty($specialties)): ?>
      <div class="journey-empty">
        <div class="ico">🔍</div>
        <h3><?= is_rtl() ? 'لا توجد نتائج مطابقة' : 'No matching results' ?></h3>
        <p><?= is_rtl() ? 'جرّب كلمة أخرى، أو ابدأ من قائمة المحامين.' : 'Try another word, or start from the lawyer list.' ?></p>
        <a href="/consultations.php" class="btn btn-primary"><?= is_rtl() ? 'تصفح كل المحامين' : 'Browse all lawyers' ?></a>
      </div>
    <?php else: ?>
      <div class="specialty-grid">
        <?php foreach ($specialties as $s): ?>
          <a class="specialty-card" href="/consultation-journey.php?category_id=<?= (int)$s['id'] ?>">
            <span class="ico" aria-hidden="true"><?= journey_icon_svg($s['icon_key']) ?></span>
            <h3><?= htmlspecialchars($s['name']) ?></h3>
            <p><?= htmlspecialchars($s['description']) ?></p>
            <span class="meta">
              <span class="count">
                <?php if ((int)$s['lawyer_count'] > 0): ?>
                  <?= (int)$s['lawyer_count'] ?> <?= is_rtl() ? 'محامٍ متاح' : 'available lawyers' ?>
                <?php else: ?>
                  <?= is_rtl() ? 'سيتم ترشيح محامٍ لك' : 'A lawyer will be assigned' ?>
                <?php endif; ?>
              </span>
              <span><?= is_rtl() ? 'اختيار' : 'Select' ?> ›</span>
            </span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  <?php else: ?>

    <!-- ============ STEP 2: plain-language issue types ============ -->
    <div class="dash-card" style="display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap">
      <div>
        <div style="font-size:.78rem;color:var(--text-muted);font-weight:700"><?= is_rtl() ? 'التخصص المختار' : 'Selected specialty' ?></div>
        <strong style="font-size:1.05rem;color:var(--navy-dark)"><?= htmlspecialchars($selectedCategory['name']) ?></strong>
      </div>
      <a href="/consultation-journey.php" class="btn btn-outline btn-sm"><?= is_rtl() ? 'تغيير التخصص' : 'Change specialty' ?></a>
    </div>

    <form method="get" class="journey-search" role="search">
      <input type="hidden" name="category_id" value="<?= (int)$categoryId ?>">
      <div class="field">
        <label for="q"><?= is_rtl() ? 'ابحث في نوع المشكلة' : 'Search the issue type' ?></label>
        <input type="search" id="q" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="<?= is_rtl() ? 'اكتب وصفًا لمشكلتك…' : 'describe your issue…' ?>">
      </div>
      <button class="btn btn-primary" type="submit"><?= is_rtl() ? 'بحث' : 'Search' ?></button>
    </form>

    <form method="get" action="/consultation-journey-lawyers.php" id="issueForm">
      <input type="hidden" name="category_id" value="<?= (int)$categoryId ?>">
      <input type="hidden" name="issue_type_id" id="issueTypeInput" value="<?= (int)$issueTypeId ?>">

      <?php if (empty($issueTypes)): ?>
        <div class="journey-empty">
          <div class="ico">🔍</div>
          <h3><?= is_rtl() ? 'لا توجد نتائج مطابقة' : 'No matching results' ?></h3>
          <p><?= is_rtl() ? 'لا تقلق — يمكنك المتابعة دون تحديد نوع المشكلة.' : 'No problem — you can continue without picking an issue type.' ?></p>
        </div>
      <?php else: ?>
        <?php foreach ($grouped as $groupName => $items): ?>
          <div class="issue-group">
            <h4><?= htmlspecialchars($groupName) ?></h4>
            <div class="issue-list">
              <?php foreach ($items as $t): ?>
                <label class="issue-option" data-issue="<?= (int)$t['id'] ?>">
                  <input type="radio" name="issue_radio" value="<?= (int)$t['id'] ?>" style="position:absolute;opacity:0;pointer-events:none" <?= $issueTypeId === (int)$t['id'] ? 'checked' : '' ?>>
                  <span class="dot" aria-hidden="true"></span>
                  <span class="body">
                    <strong><?= htmlspecialchars($t['name']) ?></strong>
                    <?php if (!empty($t['definition'])): ?><span><?= htmlspecialchars($t['definition']) ?></span><?php endif; ?>
                  </span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <!-- Requirement 10: always available, and never blocks the customer. -->
      <div class="issue-group">
        <div class="issue-list">
          <label class="issue-option not-sure" data-issue="0">
            <input type="radio" name="issue_radio" value="0" style="position:absolute;opacity:0;pointer-events:none" <?= $issueTypeId === 0 ? 'checked' : '' ?>>
            <span class="dot" aria-hidden="true"></span>
            <span class="body">
              <strong><?= htmlspecialchars($notSure['name']) ?></strong>
              <span><?= is_rtl() ? 'سيساعدك المحامي على تحديد نوع المشكلة أثناء الاستشارة.' : 'The lawyer will help identify the issue type during the consultation.' ?></span>
            </span>
          </label>
        </div>
      </div>

      <button class="btn btn-primary btn-lg" type="submit"><?= is_rtl() ? 'عرض المحامين المتاحين' : 'Show available lawyers' ?></button>
    </form>
  <?php endif; ?>
</div>

<script>
(function(){
  var form = document.getElementById('issueForm');
  if (!form) return;
  var hidden = document.getElementById('issueTypeInput');
  form.querySelectorAll('.issue-option').forEach(function(opt){
    opt.addEventListener('click', function(){
      form.querySelectorAll('.issue-option').forEach(function(o){ o.classList.remove('is-selected'); });
      opt.classList.add('is-selected');
      hidden.value = opt.dataset.issue || '0';
    });
  });
  // Reflect a preselected type (returning to this step) in the markup.
  var pre = form.querySelector('input[name="issue_radio"]:checked');
  if (pre) { var lbl = pre.closest('.issue-option'); if (lbl) lbl.classList.add('is-selected'); }
})();
</script>
<?php require_once __DIR__ . '/includes/app_footer.php'; ?>
