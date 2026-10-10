<?php
/**
 * Public firm team page (v50). Professional profiles only — name, specialisation, bio,
 * qualifications, public social links. No email, phone, or client-related data.
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
$team = firm_site_team($pdo, $firmId);

require __DIR__ . '/includes/firm_header.php';
?>
<section class="section">
  <div class="container">
    <div class="section-head"><h1><?= is_rtl() ? 'فريق المحامين' : 'Our lawyers' ?></h1></div>
    <?php if (!$team): ?>
      <p class="firm-empty"><?= is_rtl() ? 'لا يوجد أعضاء منشورون بعد.' : 'No published members yet.' ?></p>
    <?php else: ?>
      <div class="firm-team-grid">
        <?php foreach ($team as $m): ?>
          <article class="firm-person">
            <?php if (!empty($m['photo_path'])): ?><img src="<?= ui_e($m['photo_path']) ?>" alt="<?= ui_e($m['full_name']) ?>" loading="lazy"><?php endif; ?>
            <h3><?= ui_e($m['full_name']) ?></h3>
            <?php if (!empty($m['specialization'])): ?><p class="firm-role"><?= ui_e($m['specialization']) ?></p><?php endif; ?>
            <?php if (!empty($m['years_experience'])): ?><p class="firm-meta"><?= (int)$m['years_experience'] ?> <?= is_rtl() ? 'سنة خبرة' : 'years experience' ?></p><?php endif; ?>
            <?php if (!empty($m['bio'])): ?><p class="firm-bio"><?= ui_e((string)$m['bio']) ?></p><?php endif; ?>
            <?php if (!empty($m['qualifications'])): ?><p class="firm-qual"><?= ui_e((string)$m['qualifications']) ?></p><?php endif; ?>
            <div class="firm-social">
              <?php if (!empty($m['social_linkedin'])): ?><a href="<?= ui_e($m['social_linkedin']) ?>" target="_blank" rel="noopener">LinkedIn</a><?php endif; ?>
              <?php if (!empty($m['social_twitter'])): ?><a href="<?= ui_e($m['social_twitter']) ?>" target="_blank" rel="noopener">X</a><?php endif; ?>
              <?php if (!empty($m['social_website'])): ?><a href="<?= ui_e($m['social_website']) ?>" target="_blank" rel="noopener"><?= is_rtl() ? 'الموقع' : 'Website' ?></a><?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>
<?php require __DIR__ . '/includes/firm_footer.php'; ?>
