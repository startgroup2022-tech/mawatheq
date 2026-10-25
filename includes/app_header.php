<?php
/**
 * Client portal shell (Phase 4).
 *
 * Same contract as before — a page sets $activePage and includes this file —
 * but the chrome is now the shared enterprise shell: grouped navigation with
 * icons, an off-canvas drawer and bottom bar on small screens, a skip link,
 * and a page title area. The notification bell and language switch are kept.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/ui.php';
require_once __DIR__ . '/nav.php';

// Honour the language switch before anything reads current_lang(). The public
// site header already does this; without it the switcher in this shell was inert.
if (isset($_GET['lang']) && in_array($_GET['lang'], ['ar', 'en'], true)) {
    $_SESSION['lang'] = $_GET['lang'];
}

$lang = current_lang();
$dir = is_rtl() ? 'rtl' : 'ltr';
$activePage = $activePage ?? '';
$nav = nav_client($activePage);

// A page may set $pageTitle / $pageSubtitle / $breadcrumbs before including the
// header; otherwise the title comes from the active navigation entry.
$pageTitle = $pageTitle ?? '';
if ($pageTitle === '') {
    foreach ($nav['groups'] as $g) {
        foreach ($g['items'] as $k => $it) { if ($k === $activePage) { $pageTitle = $it['label']; break 2; } }
    }
}
$pageSubtitle = $pageSubtitle ?? '';
$breadcrumbs = $breadcrumbs ?? [];
$userName = $_SESSION['user_name'] ?? '';

// The topbar shows the client's plan next to their name so the current entitlement
// is always one glance away. Read through the shared helper so it cannot disagree
// with the dashboard or the server-side gate.
require_once __DIR__ . '/ai_services.php';
$topbarPlanName = '';
try {
    $topbarUsage = ai_plan_usage($pdo, (int)$_SESSION['user_id']);
    if ($topbarUsage['plan']) {
        $topbarPlanName = is_rtl() ? $topbarUsage['plan']['name_ar'] : $topbarUsage['plan']['name_en'];
    }
} catch (Throwable $e) {
    error_log('Client topbar plan unavailable: ' . $e->getMessage());
}
$topbarInitial = $userName !== '' ? mb_substr($userName, 0, 1, 'UTF-8') : '؟';
?>
<!DOCTYPE html>
<html lang="<?= ui_e($lang) ?>" dir="<?= $dir ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0F1F33">
<link rel="icon" href="/assets/img/logo.webp">
<title><?= ui_e($pageTitle !== '' ? $pageTitle . ' · ' . SITE_NAME_AR : SITE_NAME_AR) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@700;800&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>">
<link rel="stylesheet" href="<?= asset_url('/assets/css/app.css') ?>">
<link rel="stylesheet" href="<?= asset_url('/assets/css/enterprise.css') ?>">
<link rel="stylesheet" href="<?= asset_url('/assets/css/dashboard.css') ?>">
</head>
<body class="app-body">
<a class="skip-link" href="#main-content"><?= is_rtl() ? 'تخطَّ إلى المحتوى' : 'Skip to content' ?></a>

<div class="app-shell">
  <aside class="app-sidebar" id="appSidebar">
    <a href="/" class="brand"><img src="/assets/img/logo.webp" alt=""><span><?= is_rtl() ? SITE_NAME_AR : SITE_NAME_EN ?></span></a>
    <?= nav_render_sidebar($nav) ?>
    <div class="app-sidebar-foot">
      <form method="post" action="/logout.php" class="logout-form">
        <input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>">
        <button class="app-logout" type="submit"><?= ui_icon('logout') ?><span><?= is_rtl() ? 'تسجيل الخروج' : 'Log out' ?></span></button>
      </form>
    </div>
  </aside>
  <div class="app-scrim" aria-hidden="true"></div>

  <main class="app-main" id="main-content">
    <header class="app-topbar">
      <button type="button" class="app-navtoggle" data-app-nav-toggle
              aria-controls="appSidebar" aria-label="<?= is_rtl() ? 'فتح القائمة' : 'Open menu' ?>">
        <?= ui_icon('menu') ?>
      </button>
      <span class="app-topbar-title"><?= ui_e($pageTitle) ?></span>
      <span class="app-topbar-spacer"></span>
      <div class="app-topbar-actions">
        <?php render_notification_bell($pdo, 'client', (int)$_SESSION['user_id']); ?>
        <a class="lang-switch" href="?lang=<?= $lang === 'ar' ? 'en' : 'ar' ?>"
           aria-label="<?= is_rtl() ? 'Switch to English' : 'التبديل إلى العربية' ?>"><?= $lang === 'ar' ? 'EN' : 'عربي' ?></a>
        <a href="/" target="_blank" rel="noopener" class="lang-switch site-link"><?= is_rtl() ? 'الموقع ↗' : 'Site ↗' ?></a>
        <?php if ($topbarPlanName !== ''): ?>
          <a class="topbar-plan" href="/subscription.php" title="<?= ui_e(is_rtl() ? 'الباقة والاستخدام' : 'Plan & usage') ?>">
            <?= ui_icon('card') ?><span><?= ui_e($topbarPlanName) ?></span>
          </a>
        <?php else: ?>
          <a class="topbar-plan is-warn" href="/contact.php?topic=plan" title="<?= ui_e(is_rtl() ? 'لا توجد باقة مفعّلة' : 'No active plan') ?>">
            <?= ui_icon('alert') ?><span><?= is_rtl() ? 'بدون باقة' : 'No plan' ?></span>
          </a>
        <?php endif; ?>
        <a class="topbar-user" href="/account.php" title="<?= ui_e(is_rtl() ? 'الملف الشخصي' : 'Profile') ?>">
          <span class="tu-avatar" aria-hidden="true"><?= ui_e($topbarInitial) ?></span>
          <span class="tu-name"><?= ui_e($userName) ?></span>
        </a>
      </div>
    </header>
