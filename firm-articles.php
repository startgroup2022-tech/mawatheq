<?php
/**
 * Public firm publications list (v50). Only this firm's own published articles.
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lang/strings.php';
require_once __DIR__ . '/includes/ui.php';
require_once __DIR__ . '/includes/firm_site.php';

$slug = trim((string)($_GET['firm'] ?? ''));
$firm = firm_site_public_firm($pdo, $slug);
if (!$firm) { http_response_code(404); require __DIR__ . '/includes/firm_not_found.php'; exit; }

$firmId = (int)$firm['id'];
$firmPagesNav = firm_site_pages($pdo, $firmId, true);
$articles = firm_site_articles($pdo, $firmId, true);

require __DIR__ . '/includes/firm_header.php';
?>
<section class="section">
  <div class="container">
    <div class="section-head"><h1><?= is_rtl() ? 'المقالات والمنشورات' : 'Articles & publications' ?></h1></div>
    <?php if (!$articles): ?>
      <p class="firm-empty"><?= is_rtl() ? 'لا توجد مقالات منشورة بعد.' : 'No published articles yet.' ?></p>
    <?php else: ?>
      <div class="firm-article-list">
        <?php foreach ($articles as $art): ?>
          <article class="firm-article">
            <h3><a href="/article.php?slug=<?= urlencode((string)$art['slug']) ?>"><?= ui_e(is_rtl() ? $art['title_ar'] : ($art['title_en'] ?: $art['title_ar'])) ?></a></h3>
            <?php if (!empty($art['published_at'])): ?><time datetime="<?= ui_e($art['published_at']) ?>"><?= ui_e(ui_date($art['published_at'])) ?></time><?php endif; ?>
            <p><?= ui_e(is_rtl() ? (string)$art['excerpt_ar'] : (string)($art['excerpt_en'] ?: $art['excerpt_ar'])) ?></p>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>
<?php require __DIR__ . '/includes/firm_footer.php'; ?>
