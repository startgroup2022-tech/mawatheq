<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../lang/strings.php';
require_once __DIR__ . '/lawyer_guard.php';
require_once __DIR__ . '/../../includes/notifications.php';
$activeLawyer = $activeLawyer ?? '';
$lawyerNav = [
    'dashboard'     => ['icon' => '📊', 'label' => is_rtl() ? 'نظرة عامة' : 'Overview', 'href' => '/lawyer/dashboard.php'],
    'cases'         => ['icon' => '📁', 'label' => is_rtl() ? 'القضايا' : 'Cases', 'href' => '/lawyer/cases.php'],
    'calendar'      => ['icon' => '📅', 'label' => is_rtl() ? 'التقويم القانوني' : 'Legal calendar', 'href' => '/lawyer/calendar.php'],
    'firm'          => ['icon' => '🏢', 'label' => is_rtl() ? 'المكتب وقاعدة المعرفة' : 'Firm & knowledge base', 'href' => '/lawyer/firm.php'],
    'consultations' => ['icon' => '🗓️', 'label' => is_rtl() ? 'الاستشارات' : 'Consultations', 'href' => '/lawyer/consultations.php'],
    'invoices'      => ['icon' => '🧾', 'label' => is_rtl() ? 'الفواتير' : 'Invoices', 'href' => '/lawyer/invoices.php'],
    'availability'  => ['icon' => '🕐', 'label' => is_rtl() ? 'أوقات الدوام' : 'Availability', 'href' => '/lawyer/availability.php'],
    'drafting'      => ['icon' => '✍️', 'label' => is_rtl() ? 'الصياغة الذكية' : 'AI Drafting', 'href' => '/lawyer/drafting.php'],
    'reviews'       => ['icon' => '✅', 'label' => is_rtl() ? 'مراجعة المسودات' : 'Draft reviews', 'href' => '/lawyer/review-queue.php'],
    'profile'       => ['icon' => '👤', 'label' => is_rtl() ? 'الملف الشخصي' : 'Profile', 'href' => '/lawyer/profile.php'],
];
?>
<!DOCTYPE html>
<html lang="<?= current_lang() ?>" dir="<?= is_rtl() ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="/assets/img/logo.webp">
<title><?= SITE_NAME_AR ?> — <?= is_rtl() ? 'لوحة المحامي' : "Lawyer's Dashboard" ?></title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@700;800&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="app-body">
<div class="app-shell">
  <aside class="app-sidebar">
    <a href="/" class="brand" style="padding:0 20px;margin-bottom:26px"><img src="/assets/img/logo.webp"><span><?= is_rtl() ? SITE_NAME_AR : SITE_NAME_EN ?></span></a>
    <nav class="app-nav">
      <?php foreach ($lawyerNav as $key => $item): ?>
        <a href="<?= $item['href'] ?>" class="<?= $activeLawyer === $key ? 'active' : '' ?>"><span><?= $item['icon'] ?></span><?= $item['label'] ?></a>
      <?php endforeach; ?>
    </nav>
    <form method="post" action="/lawyer/logout.php" class="logout-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><button class="app-logout" type="submit">↩ <?= is_rtl() ? 'تسجيل الخروج' : 'Log out' ?></button></form>
  </aside>
  <main class="app-main">
    <div class="app-topbar">
      <span><?= is_rtl() ? 'مرحباً' : 'Hello' ?>, <?= htmlspecialchars($_SESSION['lawyer_name']) ?></span>
      <div style="display:flex;align-items:center;gap:14px">
        <?php
        try {
            render_notification_bell($pdo, 'lawyer', (int)$_SESSION['lawyer_id']);
        } catch (Throwable $e) {
            // A missing notification table on an older cPanel database must
            // not prevent the rest of every lawyer page from rendering.
            error_log('Lawyer notification bell unavailable: ' . $e->getMessage());
        }
        ?>
        <a href="/" target="_blank" class="lang-switch"><?= is_rtl() ? 'عرض الموقع ↗' : 'View site ↗' ?></a>
      </div>
    </div>
