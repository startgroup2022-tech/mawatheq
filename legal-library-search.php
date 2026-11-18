<?php
/**
 * المكتبة القانونية والقضائية — البحث والتصفّح (Execution 68 redesign).
 * Legal & judicial library — search & browse.
 *
 * Server-rendered search with real filters (source, type, court, city, Hijri year,
 * category, file type), a sort control and pagination. Every filter is an ordinary
 * GET parameter, so a result URL is shareable and the page works without JavaScript.
 * The filter options come from the data actually imported (legal_library_facets), so
 * an empty database shows an honest empty state rather than invented categories.
 *
 * The keyword search is Arabic-normalised (see legal_library_search): the stored
 * original text is never altered, only an extra `*_norm` column is matched.
 *
 * Public shell, like the hub: the catalogue is public legal content.
 */
$pageStyles = ['/assets/css/legal-library.css'];
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/legal_library.php';
require_once __DIR__ . '/includes/legal_library_ui.php';

$f = [
    'q'         => trim((string)($_GET['q'] ?? '')),
    'source_key'=> trim((string)($_GET['source'] ?? '')),
    'item_type' => trim((string)($_GET['type'] ?? '')),
    'court_name'=> trim((string)($_GET['court'] ?? '')),
    'city'      => trim((string)($_GET['city'] ?? '')),
    'category'  => trim((string)($_GET['category'] ?? '')),
    'file_type' => trim((string)($_GET['file_type'] ?? '')),
    'year'      => (int)($_GET['year'] ?? 0),
    'sort'      => trim((string)($_GET['sort'] ?? 'newest')),
];
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$res = legal_library_search($f, $page, $perPage);
$facets = legal_library_facets();
$types = legal_library_item_types();
$userId = (int)($_SESSION['user_id'] ?? 0);
$rtl = is_rtl();

/** Preserve the active filters while changing one parameter, for the pager. */
function ll_page_url(array $f, int $p): string {
    $q = array_filter([
        'q' => $f['q'], 'source' => $f['source_key'], 'type' => $f['item_type'],
        'court' => $f['court_name'], 'city' => $f['city'], 'category' => $f['category'],
        'file_type' => $f['file_type'], 'year' => $f['year'] ?: '',
        'sort' => ($f['sort'] ?? 'newest') !== 'newest' ? $f['sort'] : '',
    ], fn($v) => $v !== '' && $v !== null);
    if ($p > 1) $q['page'] = $p;
    return '/legal-library-search.php' . ($q ? ('?' . http_build_query($q)) : '');
}
$hasFilters = $f['q'] !== '' || $f['source_key'] !== '' || $f['item_type'] !== '' || $f['court_name'] !== ''
    || $f['city'] !== '' || $f['category'] !== '' || $f['file_type'] !== '' || $f['year'] > 0;
?>
<div class="ll-page">
<section class="section">
  <div class="container">
    <nav class="muted-sm" style="margin-bottom:12px">
      <a href="/legal-library.php" style="color:var(--ll-teal);text-decoration:none"><?= $rtl ? 'المكتبة القانونية' : 'Legal library' ?></a>
      <span> / <?= $rtl ? 'البحث' : 'Search' ?></span>
    </nav>

    <div class="ll-results-head">
      <h1 style="margin:0;font-size:1.4rem"><?= $rtl ? 'البحث في المكتبة القانونية' : 'Search the legal library' ?></h1>
    </div>

    <form method="get" action="/legal-library-search.php" style="margin-bottom:16px">
      <div class="ll-search" style="max-width:100%;box-shadow:var(--ll-shadow);border:1px solid var(--ll-line)">
        <span class="ll-search-ico"><?= ui_icon('search', 'll-ico') ?></span>
        <input type="text" name="q" value="<?= ui_e($f['q']) ?>" aria-label="<?= $rtl ? 'كلمة البحث' : 'Keywords' ?>"
               placeholder="<?= $rtl ? 'ابحث في العنوان والنص والرقم…' : 'Search titles, text and numbers…' ?>">
        <button class="btn btn-primary" type="submit"><?= $rtl ? 'بحث' : 'Search' ?></button>
      </div>
      <?php foreach (['source' => $f['source_key'], 'type' => $f['item_type'], 'court' => $f['court_name'],
                      'city' => $f['city'], 'category' => $f['category'], 'file_type' => $f['file_type']] as $k => $v): ?>
        <?php if ($v !== ''): ?><input type="hidden" name="<?= ui_e($k) ?>" value="<?= ui_e($v) ?>"><?php endif; ?>
      <?php endforeach; ?>
      <?php if ($f['year'] > 0): ?><input type="hidden" name="year" value="<?= (int)$f['year'] ?>"><?php endif; ?>
      <?php if (($f['sort'] ?? 'newest') !== 'newest'): ?><input type="hidden" name="sort" value="<?= ui_e($f['sort']) ?>"><?php endif; ?>
    </form>

    <button type="button" class="btn btn-outline ll-filter-toggle" data-ll-toggle="#llFilters">
      <?= ui_icon('filter', 'll-ico ll-ico-sm') ?> <?= $rtl ? 'الفلاتر' : 'Filters' ?>
    </button>

    <div class="ll-search-layout">
      <!-- --------------------------------------------------- filters -->
      <aside class="ll-sidebar collapsed" id="llFilters">
        <h3><?= ui_icon('filter', 'll-ico ll-ico-sm') ?> <?= $rtl ? 'تصفية النتائج' : 'Filter results' ?></h3>
        <form method="get" action="/legal-library-search.php">
          <?php if ($f['q'] !== ''): ?><input type="hidden" name="q" value="<?= ui_e($f['q']) ?>"><?php endif; ?>

          <div class="ll-filter-group">
            <label><?= $rtl ? 'نوع المحتوى' : 'Content type' ?></label>
            <select name="type">
              <option value=""><?= $rtl ? 'الكل' : 'All' ?></option>
              <?php foreach ($types as $k => $t): ?>
                <option value="<?= ui_e($k) ?>" <?= $f['item_type'] === $k ? 'selected' : '' ?>><?= ui_e($rtl ? $t['ar'] : $t['en']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="ll-filter-group">
            <label><?= $rtl ? 'المصدر' : 'Source' ?></label>
            <select name="source">
              <option value=""><?= $rtl ? 'الكل' : 'All' ?></option>
              <?php foreach ($facets['sources'] as $s): ?>
                <option value="<?= ui_e((string)$s['source_key']) ?>" <?= $f['source_key'] === $s['source_key'] ? 'selected' : '' ?>><?= ui_e($rtl ? $s['name_ar'] : $s['name_en']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <?php if ($facets['courts']): ?>
          <div class="ll-filter-group">
            <label><?= $rtl ? 'الجهة القضائية' : 'Court' ?></label>
            <select name="court">
              <option value=""><?= $rtl ? 'الكل' : 'All' ?></option>
              <?php foreach ($facets['courts'] as $c): ?>
                <option value="<?= ui_e((string)$c) ?>" <?= $f['court_name'] === $c ? 'selected' : '' ?>><?= ui_e((string)$c) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>

          <?php if ($facets['cities']): ?>
          <div class="ll-filter-group">
            <label><?= $rtl ? 'المنطقة / المدينة' : 'Region / city' ?></label>
            <select name="city">
              <option value=""><?= $rtl ? 'الكل' : 'All' ?></option>
              <?php foreach ($facets['cities'] as $c): ?>
                <option value="<?= ui_e((string)$c) ?>" <?= $f['city'] === $c ? 'selected' : '' ?>><?= ui_e((string)$c) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>

          <?php if ($facets['years']): ?>
          <div class="ll-filter-group">
            <label><?= $rtl ? 'السنة الهجرية' : 'Hijri year' ?></label>
            <select name="year">
              <option value=""><?= $rtl ? 'الكل' : 'All' ?></option>
              <?php foreach ($facets['years'] as $y): ?>
                <option value="<?= (int)$y ?>" <?= (int)$f['year'] === (int)$y ? 'selected' : '' ?>><?= (int)$y ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>

          <?php if ($facets['categories']): ?>
          <div class="ll-filter-group">
            <label><?= $rtl ? 'التصنيف' : 'Category' ?></label>
            <select name="category">
              <option value=""><?= $rtl ? 'الكل' : 'All' ?></option>
              <?php foreach ($facets['categories'] as $c): ?>
                <option value="<?= ui_e((string)$c) ?>" <?= $f['category'] === $c ? 'selected' : '' ?>><?= ui_e((string)$c) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>

          <div class="ll-filter-group">
            <label><?= $rtl ? 'نوع الملف' : 'File type' ?></label>
            <select name="file_type">
              <option value=""><?= $rtl ? 'الكل' : 'All' ?></option>
              <?php foreach (['pdf' => 'PDF', 'txt' => 'TXT', 'doc' => 'DOC', 'docx' => 'DOCX'] as $k => $lab): ?>
                <option value="<?= ui_e($k) ?>" <?= $f['file_type'] === $k ? 'selected' : '' ?>><?= ui_e($lab) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <input type="hidden" name="sort" value="<?= ui_e($f['sort']) ?>">
          <div style="display:flex;gap:9px">
            <button class="btn btn-primary btn-sm" type="submit" style="flex:1"><?= $rtl ? 'تطبيق' : 'Apply' ?></button>
            <a class="btn btn-outline btn-sm" href="/legal-library-search.php"><?= $rtl ? 'إعادة تعيين' : 'Reset' ?></a>
          </div>
        </form>
      </aside>

      <!-- --------------------------------------------------- results -->
      <div>
        <div class="ll-results-head">
          <p class="ll-count"><?= $rtl ? 'النتائج:' : 'Results:' ?> <strong><?= number_format((int)$res['total']) ?></strong>
            <?php if ($res['pages'] > 1): ?> — <?= $rtl ? 'صفحة' : 'page' ?> <?= (int)$res['page'] ?>/<?= (int)$res['pages'] ?><?php endif; ?>
          </p>
          <?php if ($res['total'] > 0): ?>
          <form method="get" action="/legal-library-search.php" style="margin:0">
            <?php foreach (['q' => $f['q'], 'source' => $f['source_key'], 'type' => $f['item_type'],
                            'court' => $f['court_name'], 'city' => $f['city'], 'category' => $f['category'],
                            'file_type' => $f['file_type'], 'year' => $f['year'] ?: ''] as $k => $v): ?>
              <?php if ($v !== '' && $v !== null): ?><input type="hidden" name="<?= ui_e($k) ?>" value="<?= ui_e((string)$v) ?>"><?php endif; ?>
            <?php endforeach; ?>
            <label class="muted-sm" style="margin-inline-end:6px" for="llSort"><?= $rtl ? 'ترتيب:' : 'Sort:' ?></label>
            <select name="sort" id="llSort" data-ll-sort style="padding:8px 12px;border:1.5px solid var(--ll-line);border-radius:10px;font-family:inherit">
              <option value="newest" <?= $f['sort'] === 'newest' ? 'selected' : '' ?>><?= $rtl ? 'الأحدث' : 'Newest' ?></option>
              <option value="oldest" <?= $f['sort'] === 'oldest' ? 'selected' : '' ?>><?= $rtl ? 'الأقدم' : 'Oldest' ?></option>
              <option value="court" <?= $f['sort'] === 'court' ? 'selected' : '' ?>><?= $rtl ? 'حسب الجهة' : 'By court' ?></option>
            </select>
          </form>
          <?php endif; ?>
        </div>

        <?php if (empty($res['items'])): ?>
          <div class="ll-empty">
            <div class="ll-empty-icon"><?= ui_icon('search', 'll-ico-lg') ?></div>
            <h3><?= $rtl ? 'لا توجد نتائج مطابقة' : 'No matching results' ?></h3>
            <p><?= $hasFilters
              ? ($rtl ? 'جرّب كلمات أقل أو أزل بعض الفلاتر.' : 'Try fewer keywords or remove some filters.')
              : ($rtl ? 'لم تُستورد أي عناصر بعد.' : 'No items have been imported yet.') ?></p>
          </div>
        <?php else: ?>
          <div class="ll-list">
            <?php foreach ($res['items'] as $row): echo ll_item_card(legal_library_public_item($row, $userId)); endforeach; ?>
          </div>
          <?= ll_pager((int)$res['page'], (int)$res['pages'], fn($p) => ll_page_url($f, $p)) ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>
</div>
<script src="/assets/js/legal-library.js" defer></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
