<?php
/**
 * المكتبة القانونية والقضائية — الصفحة الرئيسية (Execution 74).
 * Legal & judicial library — hub.
 *
 * Public, like the content it indexes: the material is public legal content from
 * official open sources, so a guest, a client and a lawyer all reach the same page.
 * It sits in the public shell (not the client portal) precisely so it is reachable
 * from every role and from a search engine; saving an item to "مكتبتي" is the only
 * action that needs a signed-in client.
 */
$pageStyles = ['/assets/css/legal-library.css'];
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/legal_library.php';

$sources = legal_library_sources(true);
$stats = legal_library_stats();
$types = legal_library_item_types();
$q = trim((string)($_GET['q'] ?? ''));
$userId = (int)($_SESSION['user_id'] ?? 0);
$favCount = $userId > 0 ? count(legal_library_favorites($pdo, $userId, 1000)) : 0;
?>
<section class="section">
  <div class="container">
    <div class="ll-hero">
      <h1><?= is_rtl() ? 'المكتبة القانونية والقضائية الذكية' : 'The smart legal & judicial library' ?></h1>
      <p><?= is_rtl()
        ? 'ابحث في الأحكام القضائية والأنظمة والتشريعات والمجموعات القانونية من مصادر رسمية ومفتوحة. كل عنصر يحمل مصدره ورابطه الرسمي، ويمكنك قراءته هنا أو تنزيله من جهته الأصلية.'
        : 'Search judicial decisions, statutes, regulations and legal collections from official open sources. Every item carries its source and official link; read it here or download it from the authority that published it.' ?></p>
      <div class="ll-hero-stats">
        <div><span class="n"><?= number_format((int)$stats['total']) ?></span><span class="l"><?= is_rtl() ? 'عنصر متاح' : 'items available' ?></span></div>
        <div><span class="n"><?= (int)$stats['sources'] ?></span><span class="l"><?= is_rtl() ? 'مصادر مفعّلة' : 'enabled sources' ?></span></div>
        <?php if ($userId > 0): ?><div><span class="n"><?= (int)$favCount ?></span><span class="l"><?= is_rtl() ? 'في مكتبتي' : 'in my library' ?></span></div><?php endif; ?>
      </div>
    </div>

    <form class="dash-card" method="get" action="/legal-library-search.php" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
      <div class="field" style="margin:0;flex:1;min-width:220px">
        <label style="font-size:.8rem"><?= is_rtl() ? 'ابحث في المكتبة' : 'Search the library' ?></label>
        <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="<?= is_rtl() ? 'مثال: إيداع حكم التحكيم، نظام التوثيق…' : 'e.g. arbitration award, notarisation law…' ?>">
      </div>
      <button class="btn btn-primary" type="submit"><?= is_rtl() ? 'بحث' : 'Search' ?></button>
      <?php if ($userId > 0): ?>
        <a class="btn btn-outline" href="/legal-library-favorites.php"><?= is_rtl() ? 'مكتبتي المحفوظة' : 'My saved items' ?></a>
      <?php endif; ?>
    </form>

    <h2 style="margin:24px 0 12px"><?= is_rtl() ? 'المصادر' : 'Sources' ?></h2>
    <?php if (empty($sources)): ?>
      <div class="ll-empty"><span class="ll-empty-icon">📚</span><?= is_rtl() ? 'لم يتم تفعيل أي مصدر بعد. طبّق ترقية قاعدة البيانات upgrade_v78.sql ثم شغّل المزامنة من لوحة التحكم.' : 'No source is enabled yet. Apply the database migration upgrade_v78.sql and run a sync from the console.' ?></div>
    <?php else: ?>
      <div class="ll-source-grid">
        <?php foreach ($sources as $s): ?>
          <a class="ll-source-card" href="/legal-library-search.php?source=<?= urlencode((string)$s['source_key']) ?>">
            <span class="ll-src-name"><?= htmlspecialchars(is_rtl() ? $s['name_ar'] : $s['name_en']) ?></span>
            <span class="ll-src-meta"><?= htmlspecialchars(is_rtl() ? $s['publisher_ar'] : $s['publisher_en']) ?></span>
            <span class="ll-src-meta"><?= (int)$s['item_count'] ?> <?= is_rtl() ? 'عنصر' : 'items' ?></span>
            <span class="ll-src-badge <?= htmlspecialchars((string)$s['redistribution']) ?>">
              <?= $s['redistribution'] === 'hosted'
                ? (is_rtl() ? 'قراءة كاملة هنا' : 'Read in full here')
                : (is_rtl() ? 'بيانات ووصف ورابط رسمي' : 'Metadata + official link') ?>
            </span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($stats['by_type'])): ?>
      <h2 style="margin:24px 0 12px"><?= is_rtl() ? 'تصفّح حسب النوع' : 'Browse by type' ?></h2>
      <div class="ll-source-grid">
        <?php foreach ($types as $key => $t): if (empty($stats['by_type'][$key])) continue; ?>
          <a class="ll-source-card" href="/legal-library-search.php?type=<?= urlencode($key) ?>">
            <span class="ll-src-name"><?= htmlspecialchars(is_rtl() ? $t['ar'] : $t['en']) ?></span>
            <span class="ll-src-meta"><?= (int)$stats['by_type'][$key] ?> <?= is_rtl() ? 'عنصر' : 'items' ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($stats['latest'])): ?>
      <h2 style="margin:24px 0 12px"><?= is_rtl() ? 'أحدث الإضافات' : 'Recently added' ?></h2>
      <div class="ll-list">
        <?php foreach ($stats['latest'] as $row): ?>
          <div class="ll-item">
            <h3><a href="/legal-library-item.php?id=<?= (int)$row['id'] ?>"><?= htmlspecialchars((string)$row['title']) ?></a></h3>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <p class="ll-banner" style="margin-top:24px"><?= is_rtl()
      ? 'تنبيه: المحتوى المعروض محتوى قانوني عام منشور من الجهات الرسمية أو ناشريه. لا يُعدّ استشارة قانونية، وتبقى النسخة الرسمية لدى الجهة المصدِرة هي المرجع عند أي اختلاف.'
      : 'Note: the content shown is public legal material published by the official authorities or their publishers. It is not legal advice, and the official copy held by the issuing authority governs in case of any difference.' ?></p>
  </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
