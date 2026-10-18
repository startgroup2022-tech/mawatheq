<?php
/**
 * Public law firm homepage (v50).
 *
 * Route: /firm.php?firm=<public_slug>
 * Shows only published marketing content for one firm. Returns 404 for an unknown slug or
 * a firm that has not enabled its public site, so a disabled site is indistinguishable from
 * a non-existent one. No client, case, or document data is read on this page.
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
$branches = firm_site_branches($pdo, $firmId, true);
$team = firm_site_team($pdo, $firmId);
$articles = firm_site_articles($pdo, $firmId, true);

/** Practice areas are stored newline- or comma-separated; render as chips. */
$areas = [];
foreach (preg_split('/[\r\n,]+/u', (string)($firm['practice_areas'] ?? '')) as $a) {
    $a = trim($a);
    if ($a !== '') $areas[] = $a;
}
$aboutText = is_rtl() ? ($firm['about_ar'] ?? '') : ($firm['about_en'] ?: ($firm['about_ar'] ?? ''));
$tagline = is_rtl() ? ($firm['tagline_ar'] ?? '') : ($firm['tagline_en'] ?: ($firm['tagline_ar'] ?? ''));

require __DIR__ . '/includes/firm_header.php';
?>
<section class="firm-hero">
  <div class="container">
    <h1><?= ui_e(is_rtl() ? ($firm['display_name'] ?: $firm['name']) : ($firm['display_name'] ?: $firm['name'])) ?></h1>
    <?php if ($tagline !== ''): ?><p class="firm-tagline"><?= ui_e($tagline) ?></p><?php endif; ?>
    <div class="firm-hero-actions">
      <a href="/booking.php?firm=<?= urlencode((string)$firm['public_slug']) ?>" class="btn btn-primary"><?= is_rtl() ? 'احجز استشارة' : 'Book a consultation' ?></a>
      <a href="#firm-contact" class="btn btn-outline"><?= is_rtl() ? 'تواصل معنا' : 'Contact us' ?></a>
    </div>
  </div>
</section>

<?php if ($aboutText !== ''): ?>
<section class="section">
  <div class="container">
    <div class="section-head"><h2><?= is_rtl() ? 'عن المكتب' : 'About the firm' ?></h2></div>
    <div class="firm-prose"><?= nl2br(ui_e($aboutText)) ?></div>
  </div>
</section>
<?php endif; ?>

<?php if ($areas): ?>
<section class="section section-alt">
  <div class="container">
    <div class="section-head"><h2><?= is_rtl() ? 'مجالات الممارسة' : 'Practice areas' ?></h2></div>
    <div class="firm-chips">
      <?php foreach ($areas as $a): ?><span class="firm-chip"><?= ui_e($a) ?></span><?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($team): ?>
<section class="section">
  <div class="container">
    <div class="section-head"><h2><?= is_rtl() ? 'فريق المحامين' : 'Our lawyers' ?></h2></div>
    <div class="firm-team-grid">
      <?php foreach (array_slice($team, 0, 6) as $m): ?>
        <article class="firm-person">
          <?php if (!empty($m['photo_path'])): ?><img src="<?= ui_e($m['photo_path']) ?>" alt="<?= ui_e($m['full_name']) ?>" loading="lazy"><?php endif; ?>
          <h3><?= ui_e($m['full_name']) ?></h3>
          <?php if (!empty($m['specialization'])): ?><p class="firm-role"><?= ui_e($m['specialization']) ?></p><?php endif; ?>
          <?php if (!empty($m['bio'])): ?><p class="firm-bio"><?= ui_e(mb_substr((string)$m['bio'], 0, 160, 'UTF-8')) ?></p><?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
    <?php if (count($team) > 6): ?>
      <p class="firm-more"><a class="btn btn-outline" href="/firm-team.php?firm=<?= urlencode((string)$firm['public_slug']) ?>"><?= is_rtl() ? 'كل الفريق' : 'Full team' ?></a></p>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<?php if ($articles): ?>
<section class="section section-alt">
  <div class="container">
    <div class="section-head"><h2><?= is_rtl() ? 'مقالات ومنشورات' : 'Articles & publications' ?></h2></div>
    <div class="firm-article-list">
      <?php foreach (array_slice($articles, 0, 3) as $art): ?>
        <article class="firm-article">
          <h3><a href="/article.php?slug=<?= urlencode((string)$art['slug']) ?>"><?= ui_e(is_rtl() ? $art['title_ar'] : ($art['title_en'] ?: $art['title_ar'])) ?></a></h3>
          <p><?= ui_e(is_rtl() ? (string)$art['excerpt_ar'] : (string)($art['excerpt_en'] ?: $art['excerpt_ar'])) ?></p>
        </article>
      <?php endforeach; ?>
    </div>
    <p class="firm-more"><a class="btn btn-outline" href="/firm-articles.php?firm=<?= urlencode((string)$firm['public_slug']) ?>"><?= is_rtl() ? 'كل المقالات' : 'All articles' ?></a></p>
  </div>
</section>
<?php endif; ?>

<section class="section" id="firm-contact">
  <div class="container">
    <div class="section-head"><h2><?= is_rtl() ? 'معلومات التواصل' : 'Contact details' ?></h2></div>
    <div class="firm-contact-grid">
      <?php if (!empty($firm['phone'])): ?><div><strong><?= is_rtl() ? 'الهاتف' : 'Phone' ?></strong><span><?= ui_e($firm['phone']) ?></span></div><?php endif; ?>
      <?php if (!empty($firm['email'])): ?><div><strong><?= is_rtl() ? 'البريد' : 'Email' ?></strong><span><?= ui_e($firm['email']) ?></span></div><?php endif; ?>
      <?php if (!empty($firm['address']) || !empty($firm['city'])): ?><div><strong><?= is_rtl() ? 'العنوان' : 'Address' ?></strong><span><?= ui_e(trim(($firm['address'] ?? '') . ' · ' . ($firm['city'] ?? ''), ' ·')) ?></span></div><?php endif; ?>
      <?php if (!empty($firm['working_hours'])): ?><div><strong><?= is_rtl() ? 'ساعات العمل' : 'Working hours' ?></strong><span><?= ui_e($firm['working_hours']) ?></span></div><?php endif; ?>
    </div>
    <?php if ($branches): ?>
      <h3 class="firm-subhead"><?= is_rtl() ? 'الفروع' : 'Branches' ?></h3>
      <div class="firm-branches">
        <?php foreach ($branches as $b): ?>
          <div class="firm-branch">
            <strong><?= ui_e(is_rtl() ? $b['name_ar'] : ($b['name_en'] ?: $b['name_ar'])) ?></strong>
            <?php if (!empty($b['address']) || !empty($b['city'])): ?><span><?= ui_e(trim(($b['address'] ?? '') . ' · ' . ($b['city'] ?? ''), ' ·')) ?></span><?php endif; ?>
            <?php if (!empty($b['phone'])): ?><span><?= ui_e($b['phone']) ?></span><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <p class="firm-more"><a class="btn btn-primary" href="/contact.php"><?= is_rtl() ? 'أرسل استفساراً' : 'Send an inquiry' ?></a></p>
  </div>
</section>
<?php require __DIR__ . '/includes/firm_footer.php'; ?>
