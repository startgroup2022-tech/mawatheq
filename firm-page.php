<?php
/**
 * Public firm content page (v50).
 *
 * Route: /firm-page.php?firm=<slug>&page=<page_slug>
 * Serves any published firm_pages row. The (firm_id, slug) lookup plus status='published'
 * is what prevents one firm's draft page being read through another firm's site.
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lang/strings.php';
require_once __DIR__ . '/includes/ui.php';
require_once __DIR__ . '/includes/firm_site.php';

$slug = trim((string)($_GET['firm'] ?? ''));
$pageSlug = trim((string)($_GET['page'] ?? ''));
$firm = firm_site_public_firm($pdo, $slug);
if (!$firm) { http_response_code(404); require __DIR__ . '/includes/firm_not_found.php'; exit; }

$firmId = (int)$firm['id'];
$stmt = $pdo->prepare("SELECT * FROM firm_pages WHERE firm_id=? AND slug=? AND status='published' LIMIT 1");
$stmt->execute([$firmId, $pageSlug]);
$page = $stmt->fetch();
if (!$page) { http_response_code(404); require __DIR__ . '/includes/firm_not_found.php'; exit; }

$firmPagesNav = firm_site_pages($pdo, $firmId, true);
$title = is_rtl() ? $page['title_ar'] : ($page['title_en'] ?: $page['title_ar']);
$body = is_rtl() ? (string)$page['content_ar'] : (string)($page['content_en'] ?: $page['content_ar']);

require __DIR__ . '/includes/firm_header.php';
?>
<section class="section">
  <div class="container">
    <div class="section-head"><h1><?= ui_e($title) ?></h1></div>
    <div class="firm-prose"><?= nl2br(ui_e($body)) ?></div>
  </div>
</section>
<?php require __DIR__ . '/includes/firm_footer.php'; ?>
