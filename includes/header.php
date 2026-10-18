<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/site_social.php';

if (isset($_GET['lang']) && in_array($_GET['lang'], ['ar','en'])) {
    $_SESSION['lang'] = $_GET['lang'];
}
$lang = current_lang();
$dir  = is_rtl() ? 'rtl' : 'ltr';
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $dir ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= SITE_NAME_AR ?> | <?= t('l2_hero_badge') ?></title>
<meta name="description" content="<?= t('l2_hero_sub') ?>">
<meta name="theme-color" content="#1E3A5F">
<link rel="canonical" href="<?= rtrim(SITE_URL, '/') ?>/">
<link rel="icon" href="<?= SITE_URL ?>/assets/img/logo.webp">
<link rel="apple-touch-icon" href="<?= SITE_URL ?>/assets/img/logo.webp">

<!-- Open Graph / social -->
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= SITE_NAME_AR ?>">
<meta property="og:title" content="<?= SITE_NAME_AR ?> — <?= t('l2_hero_badge') ?>">
<meta property="og:description" content="<?= t('l2_hero_sub') ?>">
<meta property="og:url" content="<?= rtrim(SITE_URL, '/') ?>/">
<meta property="og:image" content="<?= SITE_URL ?>/assets/img/logo.webp">
<meta property="og:locale" content="<?= $lang === 'ar' ? 'ar_SA' : 'en_US' ?>">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= SITE_NAME_AR ?> — <?= t('l2_hero_badge') ?>">
<meta name="twitter:description" content="<?= t('l2_hero_sub') ?>">
<meta name="twitter:image" content="<?= SITE_URL ?>/assets/img/logo.webp">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@600;700;800&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="stylesheet" href="/assets/css/enterprise.css">
<link rel="stylesheet" href="/assets/css/landing.css">
<script type="application/ld+json">
<?= json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'SoftwareApplication',
    'name' => SITE_NAME_AR,
    'alternateName' => SITE_NAME_EN,
    'applicationCategory' => 'BusinessApplication',
    'operatingSystem' => 'Web, Android, iOS',
    'description' => t('l2_hero_sub'),
    'inLanguage' => ['ar', 'en'],
    'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'SAR'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
</script>
</head>
<body class="lang-<?= $lang ?>">

<header class="site-header">
  <div class="container">
    <a href="/" class="brand" aria-label="<?= htmlspecialchars(is_rtl() ? SITE_NAME_AR : SITE_NAME_EN) ?>">
      <img src="/assets/img/logo.webp" width="457" height="813" alt="" decoding="async">
      <span><?= is_rtl() ? SITE_NAME_AR : SITE_NAME_EN ?></span>
    </a>
    <button type="button" class="nav-toggle" data-nav-toggle aria-controls="mainNav"
            aria-expanded="false" aria-label="<?= is_rtl() ? 'فتح القائمة' : 'Open menu' ?>">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
    </button>
    <nav class="main-nav" id="mainNav" data-nav-spy aria-label="<?= is_rtl() ? 'القائمة الرئيسية' : 'Main navigation' ?>">
      <a href="/#platform"><?= t('nav_platform') ?></a>
      <a href="/#features"><?= t('nav_features') ?></a>
      <a href="/#solutions"><?= t('nav_solutions') ?></a>
      <a href="/#legal-ai"><?= t('nav_legal_ai') ?></a>
      <a href="/#mobile"><?= t('nav_mobile') ?></a>
      <a href="/#how"><?= t('nav_how') ?></a>
      <a href="/#pricing"><?= t('nav_pricing') ?></a>
      <a href="/#faq"><?= t('nav_faq') ?></a>
    </nav>
    <div class="header-actions">
      <?php render_social_row('light', 14); ?>
      <a class="lang-switch" href="?lang=<?= $lang === 'ar' ? 'en' : 'ar' ?>"
         aria-label="<?= $lang === 'ar' ? 'Switch to English' : 'التبديل إلى العربية' ?>"><?= $lang === 'ar' ? 'EN' : 'عربي' ?></a>
      <a href="/login.php" class="btn btn-outline"><?= t('nav_login') ?></a>
      <a href="/register.php" class="btn btn-primary"><?= t('nav_get_started') ?></a>
    </div>
  </div>
</header>
