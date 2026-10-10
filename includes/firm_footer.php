<?php
/**
 * Public footer for a law firm's own website (v50). Pairs with includes/firm_header.php.
 */
if (!isset($firm) || !is_array($firm)) { return; }
$firmName = is_rtl() ? ($firm['display_name'] ?: $firm['name']) : ($firm['display_name'] ?: $firm['name']);
$firmSlug = (string)$firm['public_slug'];
$social = [];
if (!empty($firm['social_json'])) { $social = json_decode((string)$firm['social_json'], true) ?: []; }
?>
</main>
<footer class="site-footer firm-footer">
  <div class="container">
    <div class="footer-grid">
      <div class="footer-brand">
        <span class="firm-footer-name"><?= ui_e($firmName) ?></span>
        <?php if (!empty($firm['tagline_ar']) || !empty($firm['tagline_en'])): ?>
          <p class="firm-footer-tagline"><?= ui_e(is_rtl() ? ($firm['tagline_ar'] ?? '') : ($firm['tagline_en'] ?: ($firm['tagline_ar'] ?? ''))) ?></p>
        <?php endif; ?>
        <div class="firm-social">
          <?php if (!empty($social['linkedin'])): ?><a href="<?= ui_e($social['linkedin']) ?>" target="_blank" rel="noopener">LinkedIn</a><?php endif; ?>
          <?php if (!empty($social['twitter'])): ?><a href="<?= ui_e($social['twitter']) ?>" target="_blank" rel="noopener">X</a><?php endif; ?>
          <?php if (!empty($firm['website_url'])): ?><a href="<?= ui_e($firm['website_url']) ?>" target="_blank" rel="noopener"><?= is_rtl() ? 'الموقع' : 'Website' ?></a><?php endif; ?>
        </div>
      </div>
      <div class="footer-links">
        <a href="/firm.php?firm=<?= urlencode($firmSlug) ?>"><?= is_rtl() ? 'الرئيسية' : 'Home' ?></a>
        <a href="/firm-team.php?firm=<?= urlencode($firmSlug) ?>"><?= is_rtl() ? 'الفريق' : 'Team' ?></a>
        <a href="/firm-articles.php?firm=<?= urlencode($firmSlug) ?>"><?= is_rtl() ? 'المقالات' : 'Articles' ?></a>
        <a href="/privacy.php"><?= is_rtl() ? 'سياسة الخصوصية' : 'Privacy policy' ?></a>
        <a href="/terms.php"><?= is_rtl() ? 'الشروط والإشعارات' : 'Terms & notices' ?></a>
        <a href="/contact.php"><?= is_rtl() ? 'تواصل معنا' : 'Contact' ?></a>
      </div>
    </div>
    <div class="footer-bottom">
      <span>&copy; <?= date('Y') ?> <?= ui_e($firmName) ?></span>
      <span><?= is_rtl() ? 'مدعوم بمنصة مواثيق' : 'Powered by Mawatheq' ?></span>
    </div>
  </div>
</footer>
<script src="/assets/js/main.js"></script>
</body>
</html>
