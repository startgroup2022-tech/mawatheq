<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/notifications.php';
$lang = current_lang();
$dir = is_rtl() ? 'rtl' : 'ltr';
$activePage = $activePage ?? '';
$navItems = [
    'ai-assistant'  => ['icon' => '💬', 'label' => t('feat1_title'), 'href' => '/ai-assistant.php'],
    'documents'     => ['icon' => '📄', 'label' => t('feat2_title'), 'href' => '/documents.php'],
    'contract-comparison' => ['icon' => '⚖️', 'label' => is_rtl() ? 'مقارنة العقود' : 'Contract comparison', 'href' => '/contract-comparison.php'],
    'draft-reviews' => ['icon' => '✍️', 'label' => is_rtl() ? 'مراجعة المسودات' : 'Draft reviews', 'href' => '/draft-reviews.php'],
    'case-tasks'    => ['icon' => '✅', 'label' => is_rtl() ? 'مهامي' : 'My tasks', 'href' => '/case-tasks.php'],
    'consultations' => ['icon' => '🗓️', 'label' => t('feat4_title'), 'href' => '/consultations.php'],
    'invoices'      => ['icon' => '🧾', 'label' => is_rtl() ? 'الفواتير' : 'Invoices', 'href' => '/invoices.php'],
    'laws'          => ['icon' => '📚', 'label' => is_rtl() ? 'مكتبة الأنظمة' : 'Laws Library', 'href' => '/laws.php'],
    'profile'       => ['icon' => '👤', 'label' => is_rtl() ? 'حسابي' : 'My Account', 'href' => '/account.php'],
];
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $dir ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="/assets/img/logo.webp">
<title><?= SITE_NAME_AR ?> — <?= is_rtl() ? 'لوحة العميل' : 'Client Dashboard' ?></title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@700;800&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="app-body">
<div class="app-shell">
  <aside class="app-sidebar">
    <a href="/" class="brand" style="padding:0 20px;margin-bottom:26px"><img src="/assets/img/logo.webp"><span><?= is_rtl() ? SITE_NAME_AR : SITE_NAME_EN ?></span></a>
    <nav class="app-nav">
      <?php foreach ($navItems as $key => $item): ?>
        <a href="<?= $item['href'] ?>" class="<?= $activePage === $key ? 'active' : '' ?>"><span><?= $item['icon'] ?></span><?= $item['label'] ?></a>
      <?php endforeach; ?>
    </nav>
    <form method="post" action="/logout.php" class="logout-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><button class="app-logout" type="submit">↩ <?= is_rtl() ? 'تسجيل الخروج' : 'Log out' ?></button></form>
  </aside>
  <main class="app-main">
    <div class="app-topbar">
      <span><?= is_rtl() ? 'مرحباً' : 'Hello' ?>, <?= htmlspecialchars($_SESSION['user_name']) ?></span>
      <div style="display:flex;align-items:center;gap:14px">
        <?php render_notification_bell($pdo, 'client', (int)$_SESSION['user_id']); ?>
        <a class="lang-switch" href="?lang=<?= $lang === 'ar' ? 'en' : 'ar' ?>"><?= $lang === 'ar' ? 'EN' : 'عربي' ?></a>
      </div>
    </div>
