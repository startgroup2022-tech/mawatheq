<?php
/**
 * المكتبة القانونية والقضائية — البحث والتصفّح (Execution 74).
 * Legal & judicial library — search & browse.
 *
 * Server-rendered search with real filters (source, type, court, city, Hijri year,
 * category, file type) and pagination. Every filter is an ordinary GET parameter, so
 * a result URL is shareable and the page works without JavaScript. The filter options
 * come from the data actually imported (legal_library_facets), so an empty database
 * shows an honest empty state rather than invented categories.
 *
 * Public shell, like the hub: the catalogue is public legal content.
 */
$pageStyles = ['/assets/css/legal-library.css'];
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/legal_library.php';

$f = [
    'q'         => trim((string)($_GET['q'] ?? '')),
    'source_key'=> trim((string)($_GET['source'] ?? '')),
    'item_type' => trim((string)($_GET['type'] ?? '')),
    'court_name'=> trim((string)($_GET['court'] ?? '')),
    'city'      => trim((string)($_GET['city'] ?? '')),
    'category'  => trim((string)($_GET['category'] ?? '')),
    'file_type' => trim((string)($_GET['file_type'] ?? '')),
    'year'      => (int)($_GET['year'] ?? 0),
];
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$res = legal_library_search($f, $page, $perPage);
$facets = legal_library_facets();
$types = legal_library_item_types();
$userId = (int)($_SESSION['user_id'] ?? 0);

/** Preserve the active filters while changing one parameter, for the pager. */
function ll_page_url(array $f, int $p): string {
    $q = array_filter([
        'q' => $f['q'], 'source' => $f['source_key'], 'type' => $f['item_type'],
        'court' => $f['court_name'], 'city' => $f['city'], 'category' => $f['category'],
        'file_type' => $f['file_type'], 'year' => $f['year'] ?: '',
    ], fn($v) => $v !== '' && $v !== null);
    if ($p > 1) $q['page'] = $p;
    return '/legal-library-search.php' . ($q ? ('?' . http_build_query($q)) : '');
}
?>
<section class="section">
  <div class="container">
    <div class="toolbar" style="margin-bottom:14px">
      <h1 style="margin:0;font-size:1.3rem"><?= is_rtl() ? 'البحث في المكتبة القانونية' : 'Search the legal library' ?></h1>
      <a href="/legal-library.php" class="btn btn-outline btn-sm">← <?= is_rtl() ? 'رئيسية المكتبة' : 'Library home' ?></a>
    </div>

    <form class="dash-card ll-filters" method="get" action="/legal-library-search.php">
      <div class="field" style="grid-column:1/-1">
        <label><?= is_rtl() ? 'كلمة البحث' : 'Keywords' ?></label>
        <input type="text" name="q" value="<?= htmlspecialchars($f['q']) ?>" placeholder="<?= is_rtl() ? 'ابحث في العنوان والنص…' : 'Search titles and text…' ?>">
      </div>
      <div class="field">
        <label><?= is_rtl() ? 'النوع' : 'Type' ?></label>
        <select name="type">
          <option value=""><?= is_rtl() ? 'الكل' : 'All' ?></option>
          <?php foreach ($types as $k => $t): ?>
            <option value="<?= htmlspecialchars($k) ?>" <?= $f['item_type'] === $k ? 'selected' : '' ?>><?= htmlspecialchars(is_rtl() ? $t['ar'] : $t['en']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label><?= is_rtl() ? 'المصدر' : 'Source' ?></label>
        <select name="source">
          <option value=""><?= is_rtl() ? 'الكل' : 'All' ?></option>
          <?php foreach ($facets['sources'] as $s): ?>
            <option value="<?= htmlspecialchars((string)$s['source_key']) ?>" <?= $f['source_key'] === $s['source_key'] ? 'selected' : '' ?>><?= htmlspecialchars(is_rtl() ? $s['name_ar'] : $s['name_en']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($facets['courts']): ?>
      <div class="field">
        <label><?= is_rtl() ? 'المحكمة' : 'Court' ?></label>
        <select name="court">
          <option value=""><?= is_rtl() ? 'الكل' : 'All' ?></option>
          <?php foreach ($facets['courts'] as $c): ?>
            <option value="<?= htmlspecialchars((string)$c) ?>" <?= $f['court_name'] === $c ? 'selected' : '' ?>><?= htmlspecialchars((string)$c) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <?php if ($facets['cities']): ?>
      <div class="field">
        <label><?= is_rtl() ? 'المدينة / المنطقة' : 'City / region' ?></label>
        <select name="city">
          <option value=""><?= is_rtl() ? 'الكل' : 'All' ?></option>
          <?php foreach ($facets['cities'] as $c): ?>
            <option value="<?= htmlspecialchars((string)$c) ?>" <?= $f['city'] === $c ? 'selected' : '' ?>><?= htmlspecialchars((string)$c) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <?php if ($facets['years']): ?>
      <div class="field">
        <label><?= is_rtl() ? 'السنة الهجرية' : 'Hijri year' ?></label>
        <select name="year">
          <option value=""><?= is_rtl() ? 'الكل' : 'All' ?></option>
          <?php foreach ($facets['years'] as $y): ?>
            <option value="<?= (int)$y ?>" <?= (int)$f['year'] === (int)$y ? 'selected' : '' ?>><?= (int)$y ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="field" style="grid-column:1/-1;display:flex;gap:10px">
        <button class="btn btn-primary" type="submit"><?= is_rtl() ? 'تطبيق' : 'Apply' ?></button>
        <a class="btn btn-outline" href="/legal-library-search.php"><?= is_rtl() ? 'إعادة تعيين' : 'Reset' ?></a>
      </div>
    </form>

    <p class="muted-sm" style="margin-bottom:12px">
      <?= is_rtl() ? 'النتائج:' : 'Results:' ?> <strong><?= number_format((int)$res['total']) ?></strong>
      <?php if ($res['pages'] > 1): ?> — <?= is_rtl() ? 'صفحة' : 'page' ?> <?= (int)$res['page'] ?>/<?= (int)$res['pages'] ?><?php endif; ?>
    </p>

    <?php if (empty($res['items'])): ?>
      <div class="ll-empty"><span class="ll-empty-icon">🔍</span><?= is_rtl() ? 'لا توجد نتائج مطابقة. جرّب كلمات أقل أو أزل بعض الفلاتر.' : 'No matching results. Try fewer keywords or remove some filters.' ?></div>
    <?php else: ?>
      <div class="ll-list">
        <?php foreach ($res['items'] as $row): $it = legal_library_public_item($row, $userId); ?>
          <div class="ll-item">
            <h3><a href="/legal-library-item.php?id=<?= (int)$it['id'] ?>"><?= htmlspecialchars((string)$it['title']) ?></a></h3>
            <?php if ($it['summary']): ?><p class="ll-summary"><?= htmlspecialchars(mb_substr((string)$it['summary'], 0, 240)) ?></p><?php endif; ?>
            <div class="ll-chips">
              <span class="ll-chip type"><?= htmlspecialchars((string)$it['type_label']) ?></span>
              <?php if ($it['source_name']): ?><span class="ll-chip src"><?= htmlspecialchars((string)$it['source_name']) ?></span><?php endif; ?>
              <?php if ($it['court_name']): ?><span class="ll-chip">🏛 <?= htmlspecialchars((string)$it['court_name']) ?></span><?php endif; ?>
              <?php if ($it['city']): ?><span class="ll-chip">📍 <?= htmlspecialchars((string)$it['city']) ?></span><?php endif; ?>
              <?php if ($it['decision_number']): ?><span class="ll-chip"><?= is_rtl() ? 'رقم الحكم' : 'No.' ?> <?= htmlspecialchars((string)$it['decision_number']) ?></span><?php endif; ?>
              <?php if ($it['hijri_year']): ?><span class="ll-chip"><?= (int)$it['hijri_year'] ?> <?= is_rtl() ? 'هـ' : 'AH' ?></span><?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if ($res['pages'] > 1): ?>
        <div class="ll-pager">
          <?php if ($res['page'] > 1): ?><a href="<?= htmlspecialchars(ll_page_url($f, $res['page'] - 1)) ?>"><?= is_rtl() ? 'السابق' : 'Prev' ?></a><?php endif; ?>
          <?php
          $start = max(1, $res['page'] - 2);
          $end = min($res['pages'], $start + 4);
          for ($p = $start; $p <= $end; $p++):
              if ($p === $res['page']): ?><span class="current"><?= $p ?></span><?php else: ?><a href="<?= htmlspecialchars(ll_page_url($f, $p)) ?>"><?= $p ?></a><?php endif;
          endfor; ?>
          <?php if ($res['page'] < $res['pages']): ?><a href="<?= htmlspecialchars(ll_page_url($f, $res['page'] + 1)) ?>"><?= is_rtl() ? 'التالي' : 'Next' ?></a><?php endif; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
