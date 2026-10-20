<?php
/**
 * Public Najiz classification guide — دليل تصنيفات ناجز.
 *
 * A searchable, browsable view of the imported hierarchy. Search runs server-side on
 * the Arabic-normalised text and falls back to a no-JavaScript form, so the page is
 * usable with scripts disabled. The classification data shown is exactly what the
 * importer read from the source document; nothing is generated here.
 */
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/najiz_import.php';

$q = trim((string)($_GET['q'] ?? ''));
$catFilter = (int)($_GET['cat'] ?? 0);

// Guard: the guide must render before the migration has been applied. Uses the same
// probe as api/najiz_classify.php so the page and the API cannot disagree.
$hasTables = najiz_tables_ready($pdo);

$results = [];
$suggested = [];
$cats = [];
$stats = ['categories'=>0,'subcategories'=>0,'lawsuit_types'=>0,'judicial_requests'=>0];
$tree = [];

if ($hasTables) {
    $cats = $pdo->query('SELECT id, name_ar, name_en, sort_order FROM najiz_categories WHERE is_active=1 ORDER BY sort_order, id')->fetchAll();
    $stats = [
        'categories' => (int)$pdo->query('SELECT COUNT(*) FROM najiz_categories WHERE is_active=1')->fetchColumn(),
        'subcategories' => (int)$pdo->query('SELECT COUNT(*) FROM najiz_subcategories WHERE is_active=1')->fetchColumn(),
        'lawsuit_types' => (int)$pdo->query('SELECT COUNT(*) FROM najiz_case_types WHERE is_active=1')->fetchColumn(),
        'judicial_requests' => (int)$pdo->query('SELECT COUNT(*) FROM najiz_judicial_requests WHERE is_active=1')->fetchColumn(),
    ];
    if ($q !== '' && mb_strlen($q) >= 2) {
        $results = najiz_search($pdo, $q, 60);
        $suggested = najiz_suggest_from_text($pdo, $q, 5);
    } elseif ($catFilter > 0) {
        $s = $pdo->prepare('SELECT ct.id, ct.name_ar, ct.definition, ct.definition_missing, ct.requests_declared_absent,
                                   sc.name_ar AS sub_name, sc.id AS sub_id, ca.name_ar AS cat_name, ca.id AS cat_id,
                                   (SELECT GROUP_CONCAT(jr.name_ar ORDER BY jr.sort_order SEPARATOR " • ")
                                      FROM najiz_judicial_requests jr WHERE jr.case_type_id=ct.id AND jr.is_active=1) AS requests
                              FROM najiz_case_types ct
                              JOIN najiz_subcategories sc ON sc.id=ct.subcategory_id
                              JOIN najiz_categories ca ON ca.id=sc.category_id
                             WHERE ca.id=? AND ct.is_active=1 AND sc.is_active=1
                             ORDER BY sc.sort_order, ct.sort_order LIMIT 400');
        $s->execute([$catFilter]);
        $results = $s->fetchAll();
    } else {
        $s = $pdo->query('SELECT ct.id, ct.name_ar, ct.definition, ct.definition_missing, ct.requests_declared_absent,
                                 sc.name_ar AS sub_name, sc.id AS sub_id, ca.name_ar AS cat_name, ca.id AS cat_id,
                                 (SELECT GROUP_CONCAT(jr.name_ar ORDER BY jr.sort_order SEPARATOR " • ")
                                    FROM najiz_judicial_requests jr WHERE jr.case_type_id=ct.id AND jr.is_active=1) AS requests
                            FROM najiz_case_types ct
                            JOIN najiz_subcategories sc ON sc.id=ct.subcategory_id
                            JOIN najiz_categories ca ON ca.id=sc.category_id
                           WHERE ct.is_active=1 AND sc.is_active=1 AND ca.is_active=1
                           ORDER BY ca.sort_order, sc.sort_order, ct.sort_order LIMIT 400');
        $results = $s->fetchAll();
    }
}
?>
<section class="section">
  <div class="container">
    <div class="section-head">
      <h1><?= is_rtl() ? 'دليل تصنيفات ناجز' : 'Najiz classification guide' ?></h1>
      <p><?= is_rtl()
        ? 'ابحث بالكلمة التي تصف حالتك، لتظهر لك أنواع الدعاوى والطلبات القضائية المتاحة وتصنيفها في ناجز.'
        : 'Search in your own words to see the lawsuit types, their Najiz classification, and the judicial requests available for each.' ?></p>
    </div>

    <?php if (!$hasTables): ?>
      <div class="form-alert error"><?= is_rtl()
        ? 'لم يتم تفعيل دليل التصنيفات بعد على هذا النظام. يرجى تطبيق ترقية قاعدة البيانات upgrade_v59.sql.'
        : 'The classification guide is not enabled on this system yet. Apply database upgrade_v59.sql.' ?></div>
    <?php else: ?>

    <div class="dash-card" style="background:#fff;border:1px solid var(--border);border-radius:12px;padding:20px;margin-bottom:20px">
      <form method="get" action="/classification-guide.php" role="search">
        <div class="field" style="margin-bottom:12px">
          <label for="q"><?= is_rtl() ? 'صف حالتك أو ابحث عن نوع الدعوى' : 'Describe your situation or search a lawsuit type' ?></label>
          <input type="search" id="q" name="q" value="<?= htmlspecialchars($q) ?>"
                 placeholder="<?= is_rtl() ? 'مثال: خلاف على أجرة عقار' : 'e.g. rent dispute' ?>" autocomplete="off">
        </div>
        <button class="btn btn-primary" type="submit"><?= is_rtl() ? 'ابحث' : 'Search' ?></button>
        <?php if ($q !== '' || $catFilter): ?>
          <a class="btn" href="/classification-guide.php"><?= is_rtl() ? 'إعادة الضبط' : 'Reset' ?></a>
        <?php endif; ?>
      </form>

      <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:16px">
        <a class="btn<?= $catFilter === 0 && $q === '' ? ' btn-primary' : '' ?>" href="/classification-guide.php"><?= is_rtl() ? 'الكل' : 'All' ?></a>
        <?php foreach ($cats as $c): ?>
          <a class="btn<?= $catFilter === (int)$c['id'] ? ' btn-primary' : '' ?>"
             href="/classification-guide.php?cat=<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name_ar']) ?></a>
        <?php endforeach; ?>
      </div>

      <p style="margin:14px 0 0;font-size:13px;color:#64748b">
        <?= is_rtl()
          ? 'إجمالي أنواع الدعاوى المصنّفة: ' . (int)$stats['lawsuit_types'] . ' — التصنيفات الفرعية: ' . (int)$stats['subcategories'] . ' — الطلبات القضائية: ' . (int)$stats['judicial_requests']
          : 'Classified lawsuit types: ' . (int)$stats['lawsuit_types'] . ' — subcategories: ' . (int)$stats['subcategories'] . ' — judicial requests: ' . (int)$stats['judicial_requests'] ?>
      </p>
    </div>

    <?php if ($suggested): ?>
      <div class="dash-card" style="background:#f8fafc;border:1px solid var(--border);border-radius:12px;padding:16px;margin-bottom:18px">
        <strong><?= is_rtl() ? 'تصنيفات مقترحة لحالتك' : 'Suggested classifications' ?></strong>
        <ul style="margin:10px 0 0;padding-inline-start:20px">
          <?php foreach ($suggested as $s): ?>
            <li style="margin-bottom:6px"><?= htmlspecialchars($s['name_ar']) ?>
              <span style="color:#64748b;font-size:13px">— <?= htmlspecialchars($s['cat_name']) ?> / <?= htmlspecialchars($s['sub_name']) ?></span></li>
          <?php endforeach; ?>
        </ul>
        <p style="margin:10px 0 0;font-size:12px;color:#64748b"><?= is_rtl()
          ? 'الاقتراح إرشادي مبني على مطابقة كلمات وصفك مع تصنيفات ناجز، وليس رأيًا قانونيًا.'
          : 'Suggestions match your words against the Najiz classifications. They are not legal advice.' ?></p>
      </div>
    <?php endif; ?>

    <?php if ($q !== ''): ?>
      <p style="margin:0 0 14px;color:#475569"><?= is_rtl()
        ? 'نتائج البحث عن «' . htmlspecialchars($q) . '»: ' . count($results) . ' نوع دعوى.'
        : 'Results for “' . htmlspecialchars($q) . '”: ' . count($results) . ' lawsuit type(s).' ?></p>
    <?php endif; ?>

    <?php if (!$results): ?>
      <div class="form-alert"><?= is_rtl()
        ? 'لا توجد نتائج مطابقة. جرّب كلمات أخرى أو تصفّح التصنيفات.'
        : 'No matching results. Try other words or browse the categories.' ?></div>
    <?php else: ?>
      <div class="najiz-results">
      <?php foreach ($results as $r): ?>
        <article class="dash-card najiz-card" style="background:#fff;border:1px solid var(--border);border-radius:12px;padding:18px;margin-bottom:12px">
          <h3 style="margin:0 0 6px"><?= htmlspecialchars($r['name_ar']) ?></h3>
          <div style="font-size:13px;color:#64748b;margin-bottom:10px">
            <?= htmlspecialchars($r['cat_name']) ?> › <?= htmlspecialchars($r['sub_name']) ?>
          </div>
          <?php if (!empty($r['definition'])): ?>
            <p style="margin:0 0 10px;line-height:1.8"><?= htmlspecialchars($r['definition']) ?></p>
          <?php elseif (!empty($r['definition_missing'])): ?>
            <p style="margin:0 0 10px;color:#94a3b8;font-size:13px"><?= is_rtl()
              ? 'لا يوجد تعريف مسجّل لهذا النوع في المصدر.' : 'No definition recorded for this type in the source.' ?></p>
          <?php endif; ?>
          <?php if (!empty($r['requests'])): ?>
            <div style="font-size:13px"><strong><?= is_rtl() ? 'الطلبات المتاحة:' : 'Available requests:' ?></strong>
              <span style="color:#334155"><?= htmlspecialchars($r['requests']) ?></span></div>
          <?php elseif (!empty($r['requests_declared_absent'])): ?>
            <div style="font-size:13px;color:#94a3b8"><?= is_rtl()
              ? 'لم تُحدَّد طلبات في المصدر لهذا النوع.' : 'No requests specified for this type in the source.' ?></div>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php endif; ?>
  </div>
</section>
<style>
.najiz-results{display:grid;gap:0}
@media(min-width:900px){.najiz-results{grid-template-columns:repeat(2,1fr);gap:12px}.najiz-results .najiz-card{margin-bottom:0}}
</style>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
