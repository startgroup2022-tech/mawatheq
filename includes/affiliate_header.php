<?php
/**
 * Affiliate portal shell (Execution 31).
 *
 * A separate shell from the client and lawyer panels because an affiliate is a
 * separate identity: they must not see a client's navigation, and a client must not
 * be able to reach an affiliate page by guessing a URL. The guard below re-reads the
 * affiliate row on every request, so a suspension takes effect immediately rather
 * than at the next login.
 *
 * Deliberately does NOT include auth_guard.php (client) or the lawyer guard: those
 * would redirect an affiliate to /login.php or /lawyer-login.php.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/affiliate.php';
require_once __DIR__ . '/affiliate_service.php';
// affiliate_auth.php carries the session-facing helpers the portal pages use
// (affiliate_has_accepted(), affiliate_accept_terms()). Loaded here so every page that
// includes this shell can call them; it only pulls in auth_security.php, which the
// guard already depends on, so there is no new coupling.
require_once __DIR__ . '/affiliate_auth.php';
require_once __DIR__ . '/ui.php';

if (isset($_GET['lang']) && in_array($_GET['lang'], ['ar', 'en'], true)) {
    $_SESSION['lang'] = $_GET['lang'];
}

$affiliate = affiliate_current($pdo);
if (!$affiliate) {
    header('Location: /affiliate/login.php');
    exit;
}

// A suspended affiliate may sign in and see their history — they may be owed money —
// but every page tells them the account is suspended.
if (!affiliate_can_login($affiliate)) {
    affiliate_logout();
    header('Location: /affiliate/login.php?status=' . urlencode((string)$affiliate['status']));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_once __DIR__ . '/csrf.php';
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) { http_response_code(403); exit('Invalid request'); }
}

$lang = current_lang();
$dir = is_rtl() ? 'rtl' : 'ltr';
$activeAffiliate = $activeAffiliate ?? '';
$affiliateCanEarn = affiliate_can_earn($affiliate);
$affiliateUnread = 0;
try {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM affiliate_notification_log WHERE affiliate_id=? AND channel=?');
    $stmt->execute([(int)$affiliate['id'], 'in_app']);
    $affiliateUnread = (int)$stmt->fetchColumn();
} catch (Throwable $e) { /* pre-v57 database */ }

/**
 * Affiliate navigation. Grouped like the other panels and filtered by state: the
 * link builder is hidden from an affiliate who cannot earn, because offering it
 * would be offering something that does not work.
 */
$affiliateNavGroups = [
    ['t' => is_rtl() ? 'نظرة عامة' : 'Overview', 'items' => [
        'dashboard' => ['i' => 'dashboard', 'label' => is_rtl() ? 'لوحة المسوّق' : 'Affiliate dashboard', 'href' => '/affiliate/dashboard.php'],
    ]],
    ['t' => is_rtl() ? 'التسويق' : 'Marketing', 'items' => array_filter([
        'links' => $affiliateCanEarn ? ['i' => 'external', 'label' => is_rtl() ? 'روابط الإحالة' : 'Referral links', 'href' => '/affiliate/links.php'] : null,
        'campaigns' => $affiliateCanEarn ? ['i' => 'chart', 'label' => is_rtl() ? 'الحملات' : 'Campaigns', 'href' => '/affiliate/campaigns.php'] : null,
        'materials' => ['i' => 'folder', 'label' => is_rtl() ? 'المواد التسويقية' : 'Marketing materials', 'href' => '/affiliate/materials.php'],
    ])],
    ['t' => is_rtl() ? 'الأرباح' : 'Earnings', 'items' => [
        'earnings' => ['i' => 'wallet', 'label' => is_rtl() ? 'العمولات' : 'Commissions', 'href' => '/affiliate/earnings.php'],
        'conversions' => ['i' => 'paid', 'label' => is_rtl() ? 'التحويلات' : 'Conversions', 'href' => '/affiliate/conversions.php'],
        'payouts' => ['i' => 'receipt', 'label' => is_rtl() ? 'المدفوعات' : 'Payouts', 'href' => '/affiliate/payouts.php'],
        'reports' => ['i' => 'chart', 'label' => is_rtl() ? 'التقارير' : 'Reports', 'href' => '/affiliate/reports.php'],
    ]],
    ['t' => is_rtl() ? 'الحساب' : 'Account', 'items' => [
        'profile' => ['i' => 'user', 'label' => is_rtl() ? 'الملف الشخصي' : 'Profile', 'href' => '/affiliate/profile.php'],
        'notifications' => ['i' => 'bell', 'label' => is_rtl() ? 'الإشعارات' : 'Notifications', 'href' => '/affiliate/notifications.php'],
        'terms' => ['i' => 'shield', 'label' => is_rtl() ? 'الشروط والالتزام' : 'Terms & compliance', 'href' => '/affiliate/terms.php'],
    ]],
];

$affiliatePageTitle = $affiliatePageTitle ?? '';
if ($affiliatePageTitle === '') {
    foreach ($affiliateNavGroups as $g) {
        foreach ($g['items'] as $k => $it) { if ($k === $activeAffiliate) { $affiliatePageTitle = $it['label']; break 2; } }
    }
}
$affiliateInitial = mb_strtoupper(mb_substr((string)$affiliate['full_name'], 0, 1, 'UTF-8'), 'UTF-8');
$affiliateLangUrl = '?' . http_build_query(array_merge($_GET, ['lang' => $lang === 'ar' ? 'en' : 'ar']));
?>
<!DOCTYPE html>
<html lang="<?= ui_e($lang) ?>" dir="<?= $dir ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0F1F33">
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="/assets/img/logo.webp">
<title><?= ui_e($affiliatePageTitle !== '' ? $affiliatePageTitle . ' · ' : '') ?><?= ui_e(is_rtl() ? 'برنامج الشركاء — مواثيق' : 'Partner programme — Mawatheq') ?></title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@700;800&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="stylesheet" href="/assets/css/app.css">
<link rel="stylesheet" href="/assets/css/enterprise.css">
<link rel="stylesheet" href="/assets/css/dashboard.css">
<link rel="stylesheet" href="/assets/css/affiliate.css">
</head>
<body class="app-body aff-body">
<a class="skip-link" href="#aff-content"><?= ui_e(is_rtl() ? 'تخطَّ إلى المحتوى' : 'Skip to content') ?></a>

<div class="app-shell">
  <aside class="app-sidebar" id="affSidebar">
    <a href="/affiliate/dashboard.php" class="brand">
      <img src="/assets/img/logo.webp" alt="">
      <span><?= ui_e(is_rtl() ? 'مواثيق — الشركاء' : 'Mawatheq — Partners') ?></span>
    </a>
    <nav class="app-nav" aria-label="<?= ui_e(is_rtl() ? 'القائمة الرئيسية' : 'Main navigation') ?>">
      <?php foreach ($affiliateNavGroups as $group): if (!$group['items']) continue; ?>
        <div class="app-nav-group">
          <div class="app-nav-title"><?= ui_e($group['t']) ?></div>
          <?php foreach ($group['items'] as $key => $item): $isActive = $activeAffiliate === $key; ?>
            <a href="<?= ui_e($item['href']) ?>" class="<?= $isActive ? 'active' : '' ?>"<?= $isActive ? ' aria-current="page"' : '' ?>>
              <span class="nav-ico"><?= ui_icon($item['i']) ?></span>
              <span><?= ui_e($item['label']) ?></span>
              <?php if ($key === 'notifications' && $affiliateUnread > 0): ?><span class="badge"><?= $affiliateUnread > 9 ? '9+' : (int)$affiliateUnread ?></span><?php endif; ?>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </nav>
    <div class="app-sidebar-foot">
      <form method="post" action="/affiliate/logout.php" class="logout-form">
        <input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>">
        <button class="app-logout" type="submit"><?= ui_icon('logout') ?><span><?= ui_e(is_rtl() ? 'تسجيل الخروج' : 'Log out') ?></span></button>
      </form>
    </div>
  </aside>
  <div class="app-scrim" aria-hidden="true"></div>

  <main class="app-main" id="aff-content">
    <header class="app-topbar">
      <button type="button" class="app-navtoggle" data-app-nav-toggle aria-controls="affSidebar" aria-label="<?= ui_e(is_rtl() ? 'فتح القائمة' : 'Open menu') ?>"><?= ui_icon('menu') ?></button>
      <span class="app-topbar-title"><?= ui_e($affiliatePageTitle) ?></span>
      <span class="app-topbar-spacer"></span>
      <div class="app-topbar-actions">
        <a class="lang-switch" href="<?= ui_e($affiliateLangUrl) ?>"><?= $lang === 'ar' ? 'EN' : 'عربي' ?></a>
        <a href="/" target="_blank" rel="noopener" class="lang-switch site-link"><?= ui_e(is_rtl() ? 'الموقع ↗' : 'Site ↗') ?></a>
        <span class="aff-code-pill" title="<?= ui_e(is_rtl() ? 'رمز الإحالة' : 'Referral code') ?>"><?= ui_e((string)$affiliate['code']) ?></span>
        <a class="topbar-user" href="/affiliate/profile.php">
          <span class="tu-avatar" aria-hidden="true"><?= ui_e($affiliateInitial) ?></span>
          <span class="tu-name"><?= ui_e((string)$affiliate['full_name']) ?></span>
        </a>
      </div>
    </header>

    <?php if ((string)$affiliate['status'] === 'suspended'): ?>
      <div class="alert warn" style="margin:16px 20px 0">
        <?= ui_e(is_rtl()
          ? 'حسابك موقوف مؤقتاً، ولن تُحتسب عمولات جديدة. الأرباح المسجّلة سابقاً محفوظة. راجع الإشعارات لمعرفة السبب.'
          : 'Your account is suspended and no new commission will accrue. Commission already earned is preserved. Check your notifications for the reason.') ?>
      </div>
    <?php elseif (!$affiliateCanEarn): ?>
      <div class="alert warn" style="margin:16px 20px 0">
        <?= ui_e(is_rtl()
          ? 'حسابك قيد الاستكمال: يجب اعتماد الشروط من صفحة «الشروط والالتزام» قبل أن تصبح الروابط فعّالة.'
          : 'Your account is not earning yet: accept the terms on the Terms & compliance page before your links become active.') ?>
      </div>
    <?php endif; ?>
