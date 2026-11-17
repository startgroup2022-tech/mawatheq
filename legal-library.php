<?php
/**
 * المكتبة القانونية والقضائية — الصفحة الرئيسية (Execution 68 redesign).
 * Legal & judicial library — hub.
 *
 * Public, like the content it indexes: the material is public legal content from
 * official open sources, so a guest, a client and a lawyer all reach the same page.
 * It sits in the public shell (not the client portal) precisely so it is reachable
 * from every role and from a search engine; saving an item to "مكتبتي" is the only
 * action that needs a signed-in client.
 *
 * Every number on this page is read from the database. Nothing is hard-coded, so an
 * empty catalogue shows an honest empty state rather than an invented statistic.
 */
$pageStyles = ['/assets/css/legal-library.css'];
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/legal_library.php';
require_once __DIR__ . '/includes/legal_library_ui.php';

$sources = legal_library_sources(true);
$stats = legal_library_stats();
$browse = legal_library_browse();
$types = legal_library_item_types();
$q = trim((string)($_GET['q'] ?? ''));
$userId = (int)($_SESSION['user_id'] ?? 0);
$favCount = $userId > 0 ? count(legal_library_favorites($pdo, $userId, 1000)) : 0;
$rtl = is_rtl();
$hasData = (int)$stats['total'] > 0;

// Quick-search chips map to the real content types present in the data.
$quick = [];
foreach ($types as $key => $t) {
    if (!empty($stats['by_type'][$key])) $quick[$key] = $t;
}
?>
<div class="ll-page">
<section class="section">
  <div class="container">

    <!-- ---------------------------------------------------------- hero -->
    <div class="ll-hero">
      <div class="ll-hero-inner">
        <span class="ll-kicker"><?= ui_icon('scale', 'll-ico ll-ico-sm') ?> <?= $rtl ? 'مصادر قضائية رسمية' : 'Official judicial sources' ?></span>
        <h1><?= $rtl ? 'المكتبة القانونية والقضائية' : 'The legal & judicial library' ?></h1>
        <p class="ll-sub"><?= $rtl
          ? 'ابحث في الأحكام القضائية والأنظمة والتشريعات والمجموعات القانونية المنشورة من جهات رسمية ومفتوحة. كل عنصر يحمل عنوانه ومصدره ورابطه الرسمي، مع بياناته الكاملة عند توفّرها.'
          : 'Search judicial decisions, statutes, regulations and legal collections published by official open sources. Every item carries its title, its source and its official link, with its full metadata where available.' ?></p>

        <form class="ll-search" method="get" action="/legal-library-search.php" role="search">
          <span class="ll-search-ico"><?= ui_icon('search', 'll-ico') ?></span>
          <input type="text" name="q" value="<?= ui_e($q) ?>" aria-label="<?= $rtl ? 'البحث في المكتبة' : 'Search the library' ?>"
                 placeholder="<?= $rtl ? 'ابحث بعنوان الحكم أو رقمه أو المحكمة أو الموضوع…' : 'Search by ruling title, number, court or subject…' ?>">
          <button class="btn btn-primary" type="submit"><?= $rtl ? 'بحث' : 'Search' ?></button>
        </form>

        <?php if ($quick): ?>
        <div class="ll-quickfilters">
          <?php foreach ($quick as $key => $t): ?>
            <a class="ll-quick" href="/legal-library-search.php?type=<?= urlencode($key) ?>">
              <?= ui_icon(ll_type_icon($key), 'll-ico ll-ico-sm') ?> <?= ui_e($rtl ? $t['ar'] : $t['en']) ?>
            </a>
          <?php endforeach; ?>
          <a class="ll-quick" href="/legal-library-files.php"><?= ui_icon('download', 'll-ico ll-ico-sm') ?> <?= $rtl ? 'الملفات القابلة للتنزيل' : 'Downloadable files' ?></a>
        </div>
        <?php endif; ?>

        <div class="ll-hero-stats">
          <div class="st"><span class="n"><?= number_format((int)$stats['total']) ?></span><span class="l"><?= $rtl ? 'عنصر متاح' : 'items available' ?></span></div>
          <?php if ((int)$stats['courts'] > 0): ?><div class="st"><span class="n"><?= number_format((int)$stats['courts']) ?></span><span class="l"><?= $rtl ? 'جهة قضائية' : 'courts' ?></span></div><?php endif; ?>
          <div class="st"><span class="n"><?= (int)$stats['sources'] ?></span><span class="l"><?= $rtl ? 'مصادر مفعّلة' : 'enabled sources' ?></span></div>
          <?php if ((int)$stats['files'] > 0): ?><div class="st"><span class="n"><?= number_format((int)$stats['files']) ?></span><span class="l"><?= $rtl ? 'ملف منشور' : 'published files' ?></span></div><?php endif; ?>
          <?php if ($userId > 0): ?><div class="st"><span class="n"><?= (int)$favCount ?></span><span class="l"><?= $rtl ? 'في مكتبتي' : 'in my library' ?></span></div><?php endif; ?>
        </div>
      </div>
    </div>

    <?php if (!$hasData): ?>
      <div class="ll-empty">
        <div class="ll-empty-icon"><?= ui_icon('book', 'll-ico-lg') ?></div>
        <h3><?= $rtl ? 'لم تُستورد أي عناصر بعد' : 'No items imported yet' ?></h3>
        <p><?= $rtl
          ? 'طبّق ترقية قاعدة البيانات ثم شغّل المزامنة من لوحة التحكم (admin/legal-library.php) لاستيراد الأحكام والأنظمة من المصادر الرسمية.'
          : 'Apply the database migrations and run a sync from the console (admin/legal-library.php) to import judgments and statutes from the official sources.' ?></p>
      </div>
    <?php else: ?>

      <!-- ---------------------------------------------- content types -->
      <?php if ($quick): ?>
      <div class="ll-section">
        <div class="ll-section-head">
          <h2><?= ui_icon('book', 'll-ico') ?> <?= $rtl ? 'تصفّح حسب نوع المحتوى' : 'Browse by content type' ?></h2>
        </div>
        <div class="ll-types">
          <?php foreach ($quick as $key => $t): ?>
            <a class="ll-type-card" href="/legal-library-search.php?type=<?= urlencode($key) ?>">
              <span class="ll-type-ico"><?= ui_icon(ll_type_icon($key), 'll-ico') ?></span>
              <span>
                <span class="ll-type-name"><?= ui_e($rtl ? $t['ar'] : $t['en']) ?></span>
                <span class="ll-type-count"><?= number_format((int)$stats['by_type'][$key]) ?> <?= $rtl ? 'عنصر' : 'items' ?></span>
              </span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- ---------------------------------------------------- latest -->
      <?php if (!empty($stats['latest'])): ?>
      <div class="ll-section">
        <div class="ll-section-head">
          <h2><?= ui_icon('clock', 'll-ico') ?> <?= $rtl ? 'أحدث الأحكام والقرارات المنشورة' : 'Latest published rulings' ?></h2>
          <a class="ll-more" href="/legal-library-search.php"><?= $rtl ? 'عرض الكل' : 'View all' ?> ←</a>
        </div>
        <div class="ll-mini-list">
          <?php foreach ($stats['latest'] as $row): echo ll_mini_card($row); endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- ---------------------------------------------------- browse -->
      <?php if (!empty($browse['courts']) || !empty($browse['cities']) || !empty($browse['years'])): ?>
      <div class="ll-section">
        <div class="ll-section-head"><h2><?= ui_icon('landmark', 'll-ico') ?> <?= $rtl ? 'تصفّح حسب الجهة القضائية والمنطقة والسنة' : 'Browse by court, region and year' ?></h2></div>

        <?php if (!empty($browse['courts'])): ?>
          <p class="muted-sm" style="margin:0 0 8px"><?= $rtl ? 'الجهة القضائية' : 'Court' ?></p>
          <div class="ll-browse-row" style="margin-bottom:16px">
            <?php foreach ($browse['courts'] as $c): ?>
              <a class="ll-browse" href="/legal-library-search.php?court=<?= urlencode((string)$c['name']) ?>">
                <?= ui_icon('landmark', 'll-ico ll-ico-sm') ?> <?= ui_e((string)$c['name']) ?> <span class="n"><?= number_format((int)$c['n']) ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php if (!empty($browse['cities'])): ?>
          <p class="muted-sm" style="margin:0 0 8px"><?= $rtl ? 'المنطقة' : 'Region' ?></p>
          <div class="ll-browse-row" style="margin-bottom:16px">
            <?php foreach ($browse['cities'] as $c): ?>
              <a class="ll-browse" href="/legal-library-search.php?city=<?= urlencode((string)$c['name']) ?>">
                <?= ui_icon('map', 'll-ico ll-ico-sm') ?> <?= ui_e((string)$c['name']) ?> <span class="n"><?= number_format((int)$c['n']) ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php if (!empty($browse['years'])): ?>
          <p class="muted-sm" style="margin:0 0 8px"><?= $rtl ? 'السنة الهجرية' : 'Hijri year' ?></p>
          <div class="ll-browse-row">
            <?php foreach ($browse['years'] as $y): ?>
              <a class="ll-browse" href="/legal-library-search.php?year=<?= (int)$y['name'] ?>">
                <?= ui_icon('calendar', 'll-ico ll-ico-sm') ?> <?= (int)$y['name'] ?> <?= $rtl ? 'هـ' : 'AH' ?> <span class="n"><?= number_format((int)$y['n']) ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- --------------------------------------------- downloadable files -->
      <?php
      $fileRes = legal_library_search(['file_type' => 'pdf'], 1, 6);
      $fileItems = array_map(fn($r) => legal_library_public_item($r), $fileRes['items']);
      if ($fileItems):
      ?>
      <div class="ll-section">
        <div class="ll-section-head">
          <h2><?= ui_icon('download', 'll-ico') ?> <?= $rtl ? 'ملفات قانونية قابلة للتنزيل' : 'Downloadable legal files' ?></h2>
          <a class="ll-more" href="/legal-library-files.php"><?= $rtl ? 'كل الملفات' : 'All files' ?> ←</a>
        </div>
        <div class="ll-files">
          <?php foreach ($fileItems as $it): ?>
            <a class="ll-file" href="/legal-library-item.php?id=<?= (int)$it['id'] ?>">
              <span class="ll-file-ico">PDF</span>
              <span>
                <span class="ll-file-name"><?= ui_e(mb_substr((string)$it['title'], 0, 80)) ?></span>
                <span class="ll-file-meta">
                  <?= ui_e((string)$it['source_name']) ?>
                  <?= $it['file_size_kb'] ? ' · ' . number_format((int)$it['file_size_kb']) . ' KB' : '' ?>
                </span>
              </span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    <?php endif; ?>

    <!-- ---------------------------------------------------- sources -->
    <div class="ll-section">
      <div class="ll-section-head"><h2><?= ui_icon('building', 'll-ico') ?> <?= $rtl ? 'المصادر الحكومية الرسمية' : 'Official government sources' ?></h2></div>
      <?php if (empty($sources)): ?>
        <div class="ll-empty"><div class="ll-empty-icon"><?= ui_icon('building', 'll-ico-lg') ?></div>
          <h3><?= $rtl ? 'لا يوجد مصدر مفعّل' : 'No source enabled' ?></h3>
          <p><?= $rtl ? 'فعّل مصدراً من لوحة التحكم.' : 'Enable a source from the console.' ?></p></div>
      <?php else: ?>
        <div class="ll-sources">
          <?php foreach ($sources as $s): ?>
            <a class="ll-source-card" href="/legal-library-search.php?source=<?= urlencode((string)$s['source_key']) ?>">
              <span class="ll-src-name"><?= ui_e($rtl ? $s['name_ar'] : $s['name_en']) ?></span>
              <span class="ll-src-meta"><?= ui_e($rtl ? $s['publisher_ar'] : $s['publisher_en']) ?></span>
              <span class="ll-src-meta"><?= number_format((int)$s['item_count']) ?> <?= $rtl ? 'عنصر' : 'items' ?></span>
              <span class="ll-src-badge <?= ui_e((string)$s['redistribution']) ?>">
                <?= $s['redistribution'] === 'hosted'
                  ? ($rtl ? 'قراءة كاملة هنا' : 'Read in full here')
                  : ($rtl ? 'بيانات ورابط رسمي' : 'Metadata + official link') ?>
              </span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($userId > 0 && $favCount > 0): ?>
      <div class="ll-section">
        <a class="btn btn-outline" href="/legal-library-favorites.php"><?= ui_icon('star', 'll-ico ll-ico-sm') ?> <?= $rtl ? 'العودة إلى المحتوى المحفوظ (' . (int)$favCount . ')' : 'Return to saved items (' . (int)$favCount . ')' ?></a>
      </div>
    <?php elseif ($userId === 0): ?>
      <div class="ll-section">
        <div class="ll-callout">
          <?= ui_icon('info', 'll-ico') ?>
          <span><?= $rtl ? 'سجّل الدخول لحفظ الأحكام في «مكتبتي» والعودة إليها لاحقاً.' : 'Sign in to save rulings to “My library” and return to them later.' ?>
            <a href="/login.php"><?= $rtl ? 'تسجيل الدخول' : 'Sign in' ?></a></span>
        </div>
      </div>
    <?php endif; ?>

    <p class="ll-banner"><?= $rtl
      ? 'تنبيه: المحتوى المعروض محتوى قانوني عام منشور من الجهات الرسمية أو ناشريه. لا يُعدّ استشارة قانونية، وتبقى النسخة الرسمية لدى الجهة المصدِرة هي المرجع عند أي اختلاف.'
      : 'Note: the content shown is public legal material published by the official authorities or their publishers. It is not legal advice, and the official copy held by the issuing authority governs in case of any difference.' ?></p>
  </div>
</section>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
