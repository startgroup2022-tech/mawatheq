<?php
/**
 * Lawyer workspace shell (Phase 4).
 *
 * Same contract as before — a page sets $activeLawyer and includes this file.
 * The sidebar now groups the existing destinations, is a drawer on small
 * screens, and a bottom bar gives quick access to the primary areas. The firm
 * entry only appears when the lawyer has an active firm membership.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../lang/strings.php';
require_once __DIR__ . '/lawyer_guard.php';
require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/ui.php';
require_once __DIR__ . '/../../includes/nav.php';

$lang = current_lang();
$activeLawyer = $activeLawyer ?? '';
$nav = nav_lawyer($activeLawyer);

$pageTitle = $pageTitle ?? '';
if ($pageTitle === '') {
    foreach ($nav['groups'] as $g) {
        foreach ($g['items'] as $k => $it) { if ($k === $activeLawyer) { $pageTitle = $it['label']; break 2; } }
    }
}
$pageSubtitle = $pageSubtitle ?? '';
$breadcrumbs = $breadcrumbs ?? [];
// Older sessions (or a session synced by the tenant layer) may not carry the
// display name; fall back to the database so the topbar never shows a blank.
$lawyerName = (string)($_SESSION['lawyer_name'] ?? '');
if ($lawyerName === '') {
    try {
        $nq = $pdo->prepare('SELECT full_name FROM lawyers WHERE id=?');
        $nq->execute([(int)$_SESSION['lawyer_id']]);
        $lawyerName = (string)$nq->fetchColumn();
    } catch (Throwable $e) { $lawyerName = ''; }
}
?>
<!DOCTYPE html>
<html lang="<?= ui_e($lang) ?>" dir="<?= is_rtl() ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0F1F33">
<link rel="icon" href="/assets/img/logo.webp">
<title><?= ui_e($pageTitle !== '' ? $pageTitle . ' · ' . SITE_NAME_AR : SITE_NAME_AR) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@700;800&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="stylesheet" href="/assets/css/app.css">
<link rel="stylesheet" href="/assets/css/enterprise.css">
</head>
<body class="app-body">
<a class="skip-link" href="#main-content"><?= is_rtl() ? 'تخطَّ إلى المحتوى' : 'Skip to content' ?></a>

<div class="app-shell">
  <aside class="app-sidebar" id="appSidebar">
    <a href="/lawyer/dashboard.php" class="brand"><img src="/assets/img/logo.webp" alt=""><span><?= is_rtl() ? SITE_NAME_AR : SITE_NAME_EN ?></span></a>
    <?= nav_render_sidebar($nav) ?>
    <div class="app-sidebar-foot">
      <form method="post" action="/lawyer/logout.php" class="logout-form">
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
        <?php
        try {
            render_notification_bell($pdo, 'lawyer', (int)$_SESSION['lawyer_id']);
        } catch (Throwable $e) {
            // A missing notification table on an older cPanel database must not
            // prevent the rest of every lawyer page from rendering.
            error_log('Lawyer notification bell unavailable: ' . $e->getMessage());
        }
        ?>
        <a href="/" target="_blank" rel="noopener" class="lang-switch"><?= is_rtl() ? 'عرض الموقع ↗' : 'View site ↗' ?></a>
        <span class="app-topbar-user"><?= is_rtl() ? 'مرحباً' : 'Hello' ?>, <?= ui_e($lawyerName) ?></span>
      </div>
    </header>
