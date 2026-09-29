<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../lang/strings.php';
require_once __DIR__ . '/admin_guard.php';

// Language switch (shared session key with the rest of the platform)
if (isset($_GET['lang']) && in_array($_GET['lang'], ['ar', 'en'], true)) { $_SESSION['lang'] = $_GET['lang']; }
$admLang = current_lang(); $admDir = $admLang === 'ar' ? 'rtl' : 'ltr';
if (!function_exists('adm')) { function adm(string $ar, string $en): string { return is_rtl() ? $ar : $en; } }
if (!function_exists('h')) { function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('adm_icon')) {
    function adm_icon(string $n): string {
        static $p = [
            'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
            'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
            'scale' => '<path d="m16 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"/><path d="m2 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"/><path d="M7 21h10M12 3v18M3 7h2c2 0 5-1 7-2 2 1 5 2 7 2h2"/>',
            'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
            'file' => '<path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/>',
            'card' => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
            'receipt' => '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8M12 17.5v-11"/>',
            'chart' => '<path d="M3 3v18h18M18 17V9M13 17V5M8 17v-3"/>',
            'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
            'spark' => '<path d="m12 3-1.9 5.8a2 2 0 0 1-1.3 1.3L3 12l5.8 1.9a2 2 0 0 1 1.3 1.3L12 21l1.9-5.8a2 2 0 0 1 1.3-1.3L21 12l-5.8-1.9a2 2 0 0 1-1.3-1.3Z"/>',
            'bot' => '<rect x="3" y="11" width="18" height="10" rx="2"/><circle cx="12" cy="5" r="2"/><path d="M12 7v4M8 16h.01M16 16h.01"/>',
            'book' => '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2zM22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>',
            'landmark' => '<path d="M3 22h18M6 18v-7M10 18v-7M14 18v-7M18 18v-7M12 2 3 7h18z"/>',
            'inbox' => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
            'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
            'bell' => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
            'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
            'plus' => '<path d="M12 5v14M5 12h14"/>', 'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
            'target' => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>',
            'briefcase' => '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
            'building' => '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M9 22v-4h6v4M8 6h.01M12 6h.01M16 6h.01M8 10h.01M12 10h.01M16 10h.01M8 14h.01M12 14h.01M16 14h.01"/>',
            'check' => '<path d="m9 11 3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
            'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
            'external' => '<path d="M15 3h6v6M10 14 21 3M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
            'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
            'globe' => '<circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20"/>',
            'phone' => '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2z"/>',
            'mail' => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/>',
            'clock' => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
            'arrow' => '<path d="M7 17 17 7M7 7h10v10"/>', 'message' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
            'user' => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
            'edit' => '<path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
            'funnel' => '<path d="M22 3H2l8 9.46V19l4 2v-8.54z"/>',
        ];
        return '<svg class="i" viewBox="0 0 24 24" aria-hidden="true">' . ($p[$n] ?? $p['dashboard']) . '</svg>';
    }
}

$activeAdmin = $activeAdmin ?? '';
$adminRole = $_SESSION['admin_role'] ?? '';
$adminNavGroups = [
    ['t' => adm('نظرة عامة', 'Overview'), 'items' => [
        'dashboard' => ['i' => 'dashboard', 'ar' => 'لوحة القيادة', 'en' => 'Dashboard', 'href' => '/admin/index.php'],
        'calendar' => ['i' => 'calendar', 'ar' => 'التقويم والمواعيد', 'en' => 'Calendar', 'href' => '/admin/calendar.php'],
    ]],
    ['t' => 'CRM', 'items' => [
        'crm' => ['i' => 'target', 'ar' => 'الفرص والـ Pipeline', 'en' => 'Pipeline & CRM', 'href' => '/admin/crm.php'],
        'leads' => ['i' => 'inbox', 'ar' => 'الطلبات الواردة', 'en' => 'Inbound leads', 'href' => '/admin/leads.php', 'badge' => 'leads'],
        'users' => ['i' => 'users', 'ar' => 'العملاء', 'en' => 'Clients', 'href' => '/admin/users.php'],
    ]],
    ['t' => adm('العمليات القانونية', 'Legal operations'), 'items' => [
        'lawyers' => ['i' => 'scale', 'ar' => 'المحامون', 'en' => 'Lawyers', 'href' => '/admin/lawyers.php', 'badge' => 'lawyers'],
        'consultations' => ['i' => 'message', 'ar' => 'الاستشارات', 'en' => 'Consultations', 'href' => '/admin/consultations.php'],
        'documents' => ['i' => 'file', 'ar' => 'المستندات', 'en' => 'Documents', 'href' => '/admin/documents.php'],
    ]],
    ['t' => adm('المالية والتحليلات', 'Finance & insights'), 'items' => [
        'invoices' => ['i' => 'receipt', 'ar' => 'الفواتير والإيرادات', 'en' => 'Invoices & revenue', 'href' => '/admin/invoices.php'],
        'plans' => ['i' => 'card', 'ar' => 'الباقات', 'en' => 'Plans', 'href' => '/admin/plans.php'],
        'reports' => ['i' => 'chart', 'ar' => 'التقارير والتحليلات', 'en' => 'Reports', 'href' => '/admin/reports.php'],
    ]],
    ['t' => adm('المعرفة والذكاء الاصطناعي', 'Knowledge & AI'), 'items' => [
        'ai-knowledge' => ['i' => 'spark', 'ar' => 'مركز معرفة AI', 'en' => 'AI knowledge', 'href' => '/admin/ai-knowledge.php'],
        'ai-orchestration' => ['i' => 'bot', 'ar' => 'وحدات ووكلاء AI', 'en' => 'AI agents', 'href' => '/admin/ai-orchestration.php'],
        'ai-workflows' => ['i' => 'check', 'ar' => 'سير عمل الوكلاء والموافقات', 'en' => 'Agent workflows', 'href' => '/admin/ai-workflows.php', 'badge' => 'wf'],
        'articles' => ['i' => 'book', 'ar' => 'مركز المعرفة', 'en' => 'Articles', 'href' => '/admin/articles.php'],
        'laws' => ['i' => 'landmark', 'ar' => 'مكتبة الأنظمة', 'en' => 'Law library', 'href' => '/admin/laws.php'],
    ]],
    ['t' => adm('النظام', 'System'), 'items' => [
        'audit-log' => ['i' => 'shield', 'ar' => 'سجل التدقيق', 'en' => 'Audit log', 'href' => '/admin/audit-log.php'],
        'settings' => ['i' => 'settings', 'ar' => 'الإعدادات', 'en' => 'Settings', 'href' => '/admin/settings.php'],
    ]],
];
// legacy variable kept for compatibility with older includes
$adminNav = [];
foreach ($adminNavGroups as $g) foreach ($g['items'] as $k => $it) $adminNav[$k] = ['icon' => '', 'label' => $it['ar'], 'href' => $it['href']];

$navBadges = ['leads' => 0, 'lawyers' => 0, 'wf' => 0]; $notifUnread = 0; $notifList = [];
try { $navBadges['leads'] = (int)$pdo->query("SELECT COUNT(*) FROM contact_leads WHERE status='new'")->fetchColumn(); } catch (Throwable $e) {}
try { $navBadges['wf'] = (int)$pdo->query("SELECT COUNT(*) FROM ai_workflow_runs WHERE status='pending_approval'")->fetchColumn(); } catch (Throwable $e) {}
try { $navBadges['lawyers'] = (int)$pdo->query("SELECT COUNT(*) FROM lawyers WHERE status='pending'")->fetchColumn(); } catch (Throwable $e) {}
try {
    $nq = $pdo->prepare("SELECT title,message,link,is_read,created_at FROM notifications WHERE recipient_type='admin' AND recipient_id=? ORDER BY id DESC LIMIT 6");
    $nq->execute([(int)$_SESSION['admin_id']]); $notifList = $nq->fetchAll();
    $nc = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE recipient_type='admin' AND recipient_id=? AND is_read=0");
    $nc->execute([(int)$_SESSION['admin_id']]); $notifUnread = (int)$nc->fetchColumn();
} catch (Throwable $e) {}

$pageTitle = $pageTitle ?? '';
foreach ($adminNavGroups as $g) if (isset($g['items'][$activeAdmin]) && $pageTitle === '') $pageTitle = adm($g['items'][$activeAdmin]['ar'], $g['items'][$activeAdmin]['en']);
$canSee = function (string $href) use ($routeRoles, $adminRole): bool {
    $r = $routeRoles[basename($href)] ?? ['super_admin']; return in_array($adminRole, $r, true);
};
$initial = mb_strtoupper(mb_substr((string)($_SESSION['admin_name'] ?? 'A'), 0, 1, 'UTF-8'), 'UTF-8');
$roleLabels = ['super_admin' => adm('مدير عام', 'Super admin'), 'editor' => adm('محرر', 'Editor'), 'support' => adm('دعم', 'Support')];
$langToggleUrl = '?' . http_build_query(array_merge($_GET, ['lang' => $admLang === 'ar' ? 'en' : 'ar']));
?>
<!DOCTYPE html>
<html lang="<?= h($admLang) ?>" dir="<?= $admDir ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="/assets/img/logo.webp">
<title><?= h($pageTitle ? $pageTitle . ' · ' : '') ?><?= h(adm('لوحة تحكم مواثيق', 'Mawatheq Admin')) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@600;700;800&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="stylesheet" href="/assets/css/app.css">
<link rel="stylesheet" href="/admin/assets/admin.css">
<?php require __DIR__ . '/admin_design_system.php'; ?>
<link rel="stylesheet" href="/admin/assets/admin-ui.css?v=40">
</head>
<body class="adm" data-theme="light">
<script>(function(){try{var t=localStorage.getItem('adm_theme');if(!t&&window.matchMedia&&matchMedia('(prefers-color-scheme: dark)').matches)t='dark';document.body.setAttribute('data-theme',t||'light');if(localStorage.getItem('adm_sb')==='1'&&innerWidth>1024)document.body.classList.add('sb-collapsed')}catch(e){}})();</script>
<div class="adm-shell">
  <aside class="adm-sidebar" id="admSidebar">
    <a href="/admin/index.php" class="adm-brand"><img src="/assets/img/logo.webp" alt=""><div><b><?= h(adm('مواثيق', 'Mawatheq')) ?></b><small>LegalTech Admin</small></div></a>
    <nav class="adm-nav">
      <?php foreach ($adminNavGroups as $g):
          $vis = array_filter($g['items'], fn($it) => $canSee($it['href']));
          if (!$vis) continue; ?>
        <div class="adm-nav-group">
          <div class="adm-nav-title"><?= h($g['t']) ?></div>
          <?php foreach ($vis as $key => $it): $bd = isset($it['badge']) ? ($navBadges[$it['badge']] ?? 0) : 0; ?>
            <a href="<?= h($it['href']) ?>" class="<?= $activeAdmin === $key ? 'active' : '' ?>" title="<?= h(adm($it['ar'], $it['en'])) ?>"><?= adm_icon($it['i']) ?><span class="lbl"><?= h(adm($it['ar'], $it['en'])) ?></span><?php if ($bd > 0): ?><span class="badge"><?= $bd ?></span><?php endif; ?></a>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </nav>
    <div class="adm-sidebar-foot">
      <form method="post" action="/admin/logout.php"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><button type="submit"><?= adm_icon('logout') ?><span><?= h(adm('تسجيل الخروج', 'Sign out')) ?></span></button></form>
    </div>
  </aside>
  <div class="adm-scrim" data-act="scrim"></div>
  <main class="adm-main">
    <header class="adm-topbar">
      <button class="adm-iconbtn" data-act="sidebar" aria-label="Menu"><?= adm_icon('menu') ?></button>
      <div class="adm-crumbs"><small><?= h(adm('لوحة الإدارة', 'Admin console')) ?></small><b><?= h($pageTitle ?: adm('لوحة الإدارة', 'Admin')) ?></b></div>
      <?php if (in_array($adminRole, ['super_admin', 'support'], true)): ?>
      <form class="adm-search" action="/admin/search.php" method="get" role="search"><?= adm_icon('search') ?><input type="search" name="q" placeholder="<?= h(adm('ابحث عن عميل، محامٍ، قضية، فاتورة…  (Ctrl+K)', 'Search clients, lawyers, cases, invoices…  (Ctrl+K)')) ?>" autocomplete="off"></form>
      <?php endif; ?>
      <div class="adm-actions">
        <?php if (in_array($adminRole, ['super_admin', 'support'], true)): ?>
        <div class="adm-menu"><button class="adm-iconbtn" data-menu aria-label="Create"><?= adm_icon('plus') ?></button>
          <div class="adm-pop"><div class="pop-head"><?= h(adm('إنشاء سريع', 'Quick create')) ?></div>
            <a href="/admin/crm.php?new=opportunity#opportunities"><?= adm_icon('target') ?><?= h(adm('فرصة جديدة', 'New opportunity')) ?></a>
            <a href="/admin/crm.php?new=task#tasks"><?= adm_icon('check') ?><?= h(adm('مهمة / متابعة', 'Task / follow-up')) ?></a>
            <a href="/admin/crm.php?new=company#companies"><?= adm_icon('building') ?><?= h(adm('شركة جديدة', 'New company')) ?></a>
            <a href="/admin/lawyers.php"><?= adm_icon('scale') ?><?= h(adm('إضافة محامٍ', 'Add lawyer')) ?></a>
          </div></div>
        <?php endif; ?>
        <a class="adm-iconbtn lang-pill" href="<?= h($langToggleUrl) ?>" title="Language"><?= $admLang === 'ar' ? 'EN' : 'ع' ?></a>
        <button class="adm-iconbtn" data-act="theme" title="Theme"><?= adm_icon('sun') ?></button>
        <div class="adm-menu"><button class="adm-iconbtn" data-menu aria-label="Notifications"><?= adm_icon('bell') ?><?php if ($notifUnread): ?><span class="dot"><?= $notifUnread > 9 ? '9+' : $notifUnread ?></span><?php endif; ?></button>
          <div class="adm-pop" style="width:340px;max-width:92vw"><div class="pop-head" style="display:flex;justify-content:space-between"><span><?= h(adm('الإشعارات', 'Notifications')) ?></span><a href="/mark-notifications-read.php" style="padding:0;color:var(--primary)"><?= h(adm('تحديد الكل كمقروء', 'Mark all read')) ?></a></div>
            <?php foreach ($notifList as $n): ?><a class="adm-notif-item <?= $n['is_read'] ? '' : 'unread' ?>" href="<?= h($n['link'] ?: '/admin/notifications.php') ?>"><b><?= h($n['title']) ?></b><span><?= h(mb_substr((string)$n['message'], 0, 90, 'UTF-8')) ?></span></a><?php endforeach; ?>
            <?php if (!$notifList): ?><div class="empty" style="padding:22px 8px"><?= h(adm('لا توجد إشعارات', 'No notifications')) ?></div><?php endif; ?>
            <hr><a href="/admin/notifications.php"><?= h(adm('عرض كل الإشعارات', 'View all notifications')) ?></a></div></div>
        <div class="adm-menu"><button class="adm-userbtn" data-menu><span class="adm-avatar"><?= h($initial) ?></span><div><b><?= h($_SESSION['admin_name'] ?? '') ?></b><small><?= h($roleLabels[$adminRole] ?? $adminRole) ?></small></div></button>
          <div class="adm-pop"><a href="/admin/two-factor.php"><?= adm_icon('shield') ?><?= h(adm('الأمان والمصادقة الثنائية', 'Security & 2FA')) ?></a><a href="/" target="_blank" rel="noopener"><?= adm_icon('external') ?><?= h(adm('عرض الموقع', 'View website')) ?></a><hr>
            <form method="post" action="/admin/logout.php"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><button class="item" type="submit"><?= adm_icon('logout') ?><?= h(adm('تسجيل الخروج', 'Sign out')) ?></button></form></div></div>
      </div>
    </header>
    <div class="adm-content">
