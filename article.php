<?php
require_once __DIR__ . '/includes/header.php';
$slug = $_GET['slug'] ?? '';
$stmt = $pdo->prepare("SELECT * FROM articles WHERE slug=? AND status='published'");
$stmt->execute([$slug]);
$article = $stmt->fetch();
?>
<section class="section">
  <div class="container" style="max-width:720px">
    <?php if (!$article): ?>
      <p><?= is_rtl() ? 'المقال غير موجود.' : 'Article not found.' ?></p>
    <?php else: ?>
      <h1><?= htmlspecialchars(is_rtl() ? $article['title_ar'] : $article['title_en']) ?></h1>
      <div style="color:var(--text-muted);margin-bottom:24px"><?= date('Y-m-d', strtotime($article['published_at'])) ?></div>
      <div style="line-height:1.9"><?= nl2br(htmlspecialchars(is_rtl() ? $article['content_ar'] : $article['content_en'])) ?></div>
    <?php endif; ?>
  </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
