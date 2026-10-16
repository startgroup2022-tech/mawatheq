<?php
/**
 * Public shell for a law firm's own website (v50).
 *
 * Mirrors includes/header.php's contract but renders the *firm's* identity instead of the
 * platform's. A page includes this after it has resolved $firm (via firm_site_public_firm())
 * and must have returned 404 already if $firm is null. Nothing private is rendered here:
 * this shell only knows the firm's public profile and its published navigation.
 *
 * Reuses the platform stylesheets so the firm site inherits the approved Mawatheq visual
 * identity and the Arabic-first RTL behaviour without a second design system.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/firm_site.php';

if (!isset($firm) || !is_array($firm)) { http_response_code(404); exit('Not found'); }

if (isset($_GET['lang']) && in_array($_GET['lang'], ['ar', 'en'], true)) {
    $_SESSION['lang'] = $_GET['lang'];
}
$lang = current_lang();
$dir  = is_rtl() ? 'rtl' : 'ltr';

/** Firm display name, language-aware. */
$firmName = is_rtl()
    ? ($firm['display_name'] ?: $firm['name'])
    : ($firm['display_name'] ?: ($firm['name'] ?? ''));
$firmSlug = (string)$firm['public_slug'];
$firmBase = '/firm.php?firm=' . rawurlencode($firmSlug);
$firmLogo = !empty($firm['logo_path']) ? (string)$firm['logo_path'] : '/assets/img/logo.webp';
$metaTitle = is_rtl()
    ? ($firm['meta_title_ar'] ?: $firmName)
    : ($firm['meta_title_en'] ?: $firmName);
$metaDesc = is_rtl()
    ? ($firm['meta_description_ar'] ?: ($firm['tagline_ar'] ?? ''))
    : ($firm['meta_description_en'] ?: ($firm['tagline_en'] ?? ''));
?>
<!DOCTYPE html>
<html lang="<?= ui_e($lang) ?>" dir="<?= $dir ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= ui_e($metaTitle) ?></title>
<?php if ($metaDesc !== ''): ?><meta name="description" content="<?= ui_e($metaDesc) ?>"><?php endif; ?>
<link rel="canonical" href="<?= ui_e(rtrim(SITE_URL, '/') . $firmBase) ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="<?= ui_e($metaTitle) ?>">
<?php if ($metaDesc !== ''): ?><meta property="og:description" content="<?= ui_e($metaDesc) ?>"><?php endif; ?>
<link rel="icon" href="/assets/img/logo.webp">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@600;700;800&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="stylesheet" href="/assets/css/enterprise.css">
<link rel="stylesheet" href="/assets/css/firm-site.css">
<script type="application/ld+json">
<?= json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'LegalService',
    'name' => $firmName,
    'url' => rtrim(SITE_URL, '/') . $firmBase,
    'areaServed' => $firm['country'] ?? null,
    'address' => array_filter([
        '@type' => 'PostalAddress',
        'streetAddress' => $firm['address'] ?? null,
        'addressLocality' => $firm['city'] ?? null,
        'addressCountry' => $firm['country'] ?? null,
    ]),
    'telephone' => $firm['phone'] ?? null,
    'email' => $firm['email'] ?? null,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
</script>
</head>
<body class="lang-<?= ui_e($lang) ?> firm-site">

<header class="site-header firm-header">
  <div class="container">
    <a href="<?= ui_e($firmBase) ?>" class="brand">
      <img src="<?= ui_e($firmLogo) ?>" alt="<?= ui_e($firmName) ?>">
      <span><?= ui_e($firmName) ?></span>
    </a>
    <button type="button" class="nav-toggle" data-nav-toggle aria-controls="firmNav"
            aria-expanded="false" aria-label="<?= is_rtl() ? 'فتح القائمة' : 'Open menu' ?>">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
    </button>
    <nav class="main-nav" id="firmNav" aria-label="<?= is_rtl() ? 'قائمة الموقع' : 'Site navigation' ?>">
      <a href="<?= ui_e($firmBase) ?>"><?= is_rtl() ? 'الرئيسية' : 'Home' ?></a>
      <?php foreach (($firmPagesNav ?? []) as $pg): ?>
        <a href="/firm-page.php?firm=<?= urlencode($firmSlug) ?>&page=<?= urlencode($pg['slug']) ?>"><?= ui_e(is_rtl() ? $pg['title_ar'] : ($pg['title_en'] ?: $pg['title_ar'])) ?></a>
      <?php endforeach; ?>
      <a href="/firm-team.php?firm=<?= urlencode($firmSlug) ?>"><?= is_rtl() ? 'فريق المحامين' : 'Our team' ?></a>
      <a href="/firm-articles.php?firm=<?= urlencode($firmSlug) ?>"><?= is_rtl() ? 'المقالات' : 'Articles' ?></a>
    </nav>
    <div class="header-actions">
      <a class="lang-switch" href="?firm=<?= urlencode($firmSlug) ?>&amp;lang=<?= $lang === 'ar' ? 'en' : 'ar' ?>"><?= $lang === 'ar' ? 'EN' : 'عربي' ?></a>
      <a href="/booking.php?firm=<?= urlencode($firmSlug) ?>" class="btn btn-primary"><?= is_rtl() ? 'احجز استشارة' : 'Book a consultation' ?></a>
    </div>
  </div>
</header>
<main id="main-content">
