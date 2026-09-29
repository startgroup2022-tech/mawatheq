<?php
$activePage = 'laws';
require_once __DIR__ . '/includes/app_header.php';
require_once __DIR__ . '/includes/law_search.php';
require_once __DIR__ . '/includes/jurisdiction.php';

$filterJurisdiction = $_GET['jurisdiction'] ?? '';
$catSql = 'SELECT * FROM law_categories';
$catParams = [];
if ($filterJurisdiction) { $catSql .= ' WHERE jurisdiction = ?'; $catParams[] = $filterJurisdiction; }
$catSql .= ' ORDER BY sort_order';
$catStmt = $pdo->prepare($catSql);
$catStmt->execute($catParams);
$categories = $catStmt->fetchAll();

$q = trim($_GET['q'] ?? '');
$categoryId = !empty($_GET['category']) ? (int)$_GET['category'] : null;
$articleId = !empty($_GET['article']) ? (int)$_GET['article'] : null;

$results = [];
$activeCategory = null;
$singleArticle = null;

if ($articleId) {
    $stmt = $pdo->prepare('SELECT la.*, lc.name_ar AS cat_name_ar, lc.name_en AS cat_name_en FROM law_articles la JOIN law_categories lc ON lc.id=la.category_id WHERE la.id=?');
    $stmt->execute([$articleId]);
    $singleArticle = $stmt->fetch();
} elseif ($q !== '') {
    $results = search_law_articles($q, 20);
} elseif ($categoryId) {
    foreach ($categories as $c) { if ($c['id'] == $categoryId) { $activeCategory = $c; break; } }
    $stmt = $pdo->prepare('SELECT la.*, lc.name_ar AS cat_name_ar, lc.name_en AS cat_name_en FROM law_articles la JOIN law_categories lc ON lc.id=la.category_id WHERE la.category_id=? ORDER BY la.id');
    $stmt->execute([$categoryId]);
    $results = $stmt->fetchAll();
}

function render_article_card(array $r): void {
    $rtl = is_rtl();
    ?>
    <div class="dash-card" id="art-<?= $r['id'] ?>" style="scroll-margin-top:20px">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap">
        <strong><?= htmlspecialchars($rtl ? $r['law_name_ar'] : $r['law_name_en']) ?> <?= $r['article_number'] ? '— ' . htmlspecialchars($r['article_number']) : '' ?></strong>
        <span style="font-size:.78rem;color:var(--text-muted);background:#F0F4F7;padding:3px 10px;border-radius:20px"><?= htmlspecialchars($rtl ? $r['cat_name_ar'] : $r['cat_name_en']) ?></span>
      </div>
      <p style="margin:10px 0 0;line-height:1.9"><?= htmlspecialchars($rtl ? $r['content_ar'] : $r['content_en']) ?></p>
      <a href="/laws.php?article=<?= $r['id'] ?>" class="field-hint" style="display:inline-block;margin-top:10px;color:var(--teal);font-weight:600">🔗 <?= $rtl ? 'رابط دائم لهذه المادة' : 'Permanent link to this article' ?></a>
    </div>
    <?php
}
?>
<div class="app-content">
  <h2><?= is_rtl() ? 'مكتبة الأنظمة واللوائح' : 'Laws & Regulations Library' ?></h2>
  <form class="dash-card" method="get">
    <div class="field" style="margin:0">
      <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="<?= is_rtl() ? 'ابحث في الأنظمة... (مثال: إنهاء عقد العمل)' : 'Search laws... (e.g. ending employment contract)' ?>">
    </div>
  </form>

  <?php if ($singleArticle): ?>
    <div class="toolbar"><a href="/laws.php" class="btn btn-outline btn-sm">← <?= is_rtl() ? 'رجوع للمكتبة' : 'Back to library' ?></a></div>
    <?php render_article_card($singleArticle); ?>

  <?php elseif ($q !== ''): ?>
    <h3 style="margin:20px 0 12px"><?= is_rtl() ? 'نتائج البحث' : 'Search results' ?> (<?= count($results) ?>)</h3>
    <?php if (empty($results)): ?><p style="color:var(--text-muted)"><?= is_rtl() ? 'لا توجد نتائج مطابقة. جرّب كلمات أخرى، أو تصفّح التصنيفات بالأسفل.' : 'No matching results. Try different keywords, or browse categories below.' ?></p><?php endif; ?>
    <?php foreach ($results as $r): render_article_card($r); endforeach; ?>

  <?php elseif ($activeCategory): ?>
    <div class="toolbar">
      <h3><?= htmlspecialchars(is_rtl() ? $activeCategory['name_ar'] : $activeCategory['name_en']) ?></h3>
      <a href="/laws.php" class="btn btn-outline btn-sm">← <?= is_rtl() ? 'كل التصنيفات' : 'All categories' ?></a>
    </div>
    <?php if (empty($results)): ?><p style="color:var(--text-muted)"><?= is_rtl() ? 'لا توجد مواد في هذا التصنيف بعد.' : 'No articles in this category yet.' ?></p><?php endif; ?>
    <?php foreach ($results as $r): render_article_card($r); endforeach; ?>

  <?php else: ?>
    <h3 style="margin:20px 0 12px"><?= is_rtl() ? 'التصنيفات' : 'Categories' ?></h3>
    <form method="get" class="dash-card" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
      <div class="field" style="margin:0;min-width:180px">
        <label style="font-size:.8rem"><?= is_rtl() ? 'الدولة' : 'Country' ?></label>
        <?php render_jurisdiction_select('jurisdiction', $filterJurisdiction, true); ?>
      </div>
      <button class="btn btn-primary btn-sm" type="submit"><?= is_rtl() ? 'تصفية' : 'Filter' ?></button>
      <?php if ($filterJurisdiction): ?><a href="/laws.php" class="btn btn-outline btn-sm"><?= is_rtl() ? 'إعادة تعيين' : 'Reset' ?></a><?php endif; ?>
    </form>
    <div class="law-category-grid">
      <?php foreach ($categories as $c): ?>
        <a href="/laws.php?category=<?= $c['id'] ?>" class="dash-card law-category-card">
          <span class="law-category-icon"><?= htmlspecialchars($c['icon'] ?: '📚') ?></span>
          <strong><?= htmlspecialchars(is_rtl() ? $c['name_ar'] : $c['name_en']) ?></strong>
          <span style="display:block;font-size:.72rem;color:var(--text-muted);margin-top:4px">🌍 <?= htmlspecialchars($c['jurisdiction'] ?? 'السعودية') ?></span>
        </a>
      <?php endforeach; ?>
      <?php if (empty($categories)): ?><p style="color:var(--text-muted)"><?= is_rtl() ? 'لا توجد تصنيفات تطابق هذا الفلتر.' : 'No categories match this filter.' ?></p><?php endif; ?>
    </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/app_footer.php'; ?>
