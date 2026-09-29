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
<title><?= SITE_NAME_AR ?> | <?= t('hero_title') ?></title>
<meta name="description" content="<?= t('hero_subtitle') ?>">
<link rel="icon" href="<?= SITE_URL ?>/assets/img/logo.webp">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@600;700;800&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="lang-<?= $lang ?>">

<header class="site-header">
  <div class="container">
    <a href="/" class="brand"><img src="/assets/img/logo.webp" alt="<?= SITE_NAME_AR ?>"><span><?= is_rtl() ? SITE_NAME_AR : SITE_NAME_EN ?></span></a>
    <nav class="main-nav">
      <a href="/#about"><?= t('nav_about') ?></a>
      <a href="/#features"><?= t('nav_features') ?></a>
      <a href="/#how"><?= t('nav_how') ?></a>
      <a href="/#pricing"><?= t('nav_pricing') ?></a>
      <a href="/#faq"><?= t('nav_faq') ?></a>
    </nav>
    <div class="header-actions">
      <?php render_social_row('light', 14); ?>
      <a class="lang-switch" href="?lang=<?= $lang === 'ar' ? 'en' : 'ar' ?>"><?= $lang === 'ar' ? 'EN' : 'عربي' ?></a>
      <a href="/login.php" class="btn btn-outline"><?= t('nav_login') ?></a>
      <a href="/register.php" class="btn btn-primary"><?= t('nav_get_started') ?></a>
    </div>
  </div>
</header>
