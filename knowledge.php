<?php
require_once __DIR__ . '/includes/header.php';
$articles = $pdo->query("SELECT * FROM articles WHERE status='published' ORDER BY published_at DESC")->fetchAll();
?>
<section class="section">
  <div class="container">
    <div class="section-head"><h2><?= t('feat6_title') ?></h2><p><?= t('feat6_body') ?></p></div>
    <?php if (empty($articles)): ?>
      <p style="text-align:center;color:var(--text-muted)"><?= is_rtl() ? 'لا توجد مقالات منشورة بعد — أضفها من لوحة التحكم.' : 'No published articles yet — add them from the admin panel.' ?></p>
    <?php endif; ?>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:20px">
      <?php foreach ($articles as $a): ?>
        <a href="/article.php?slug=<?= urlencode($a['slug']) ?>" class="mini-card" style="text-decoration:none">
          <h3><?= htmlspecialchars(is_rtl() ? $a['title_ar'] : $a['title_en']) ?></h3>
          <p><?= htmlspecialchars(is_rtl() ? $a['excerpt_ar'] : $a['excerpt_en']) ?></p>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
