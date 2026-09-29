<?php
$wa = '966501453765';
try {
    $stmt = $pdo->query("SELECT setting_value FROM site_settings WHERE setting_key='whatsapp_number'");
    $row = $stmt->fetch();
    if ($row) $wa = $row['setting_value'];
} catch (Throwable $e) { /* تجاهل إذا الجدول غير موجود بعد */ }
require_once __DIR__ . '/site_social.php';
?>
<footer class="site-footer">
  <div class="container">
    <div class="footer-grid">
      <div class="footer-brand">
        <a href="/" class="brand"><img src="/assets/img/logo.webp" alt="<?= SITE_NAME_AR ?>"><span><?= is_rtl() ? SITE_NAME_AR : SITE_NAME_EN ?></span></a>
        <?php render_social_row('dark', 16); ?>
      </div>
      <div class="footer-links">
        <a href="/privacy.php"><?= t('footer_privacy') ?></a>
        <a href="/terms.php"><?= t('footer_terms') ?></a>
        <a href="/contact.php"><?= t('footer_contact') ?></a>
        <a href="/knowledge.php"><?= t('feat6_title') ?></a>
        <a href="/join-lawyer.php"><?= t('lawyers_cta') ?></a>
        <a href="/lawyer-login.php"><?= is_rtl() ? 'دخول المحامين' : 'Lawyer Login' ?></a>
      </div>
    </div>
    <div class="footer-bottom">
      <span>&copy; <?= date('Y') ?> <?= SITE_NAME_AR ?> — <?= t('footer_rights') ?></span>
      <span>Mawatheq</span>
    </div>
  </div>
</footer>

<a class="wa-float" href="https://wa.me/<?= htmlspecialchars($wa) ?>" target="_blank" aria-label="WhatsApp">
  <svg width="26" height="26" viewBox="0 0 24 24" fill="#fff"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.29-1.39a9.9 9.9 0 0 0 4.75 1.21h.01c5.46 0 9.91-4.45 9.91-9.91C21.96 6.45 17.5 2 12.04 2zm5.8 14.13c-.24.68-1.4 1.32-1.93 1.4-.5.08-1.12.11-1.8-.12-.42-.13-.95-.3-1.64-.6-2.88-1.24-4.76-4.14-4.9-4.33-.14-.19-1.17-1.55-1.17-2.96 0-1.4.74-2.09 1-2.38.26-.28.57-.35.76-.35.19 0 .38 0 .55.01.18.01.42-.07.65.5.24.58.82 2 .89 2.14.08.15.13.32.02.51-.1.19-.16.31-.31.48-.15.17-.32.38-.46.51-.15.14-.31.3-.14.6.18.3.79 1.3 1.7 2.1 1.17 1.04 2.15 1.37 2.45 1.52.3.15.48.13.65-.08.18-.21.76-.89.96-1.19.2-.3.4-.25.67-.15.28.1 1.75.83 2.05.98.3.15.5.22.57.35.08.13.08.72-.16 1.4z"/></svg>
</a>

<script src="/assets/js/main.js"></script>
</body>
</html>
