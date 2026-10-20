<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../lang/strings.php';
require_once __DIR__ . '/admin_guard.php';

// Language switch (shared session key with the rest of the platform)
if (isset($_GET['lang']) && in_array($_GET['lang'], ['ar', 'en'], true)) { $_SESSION['lang'] = $_GET['lang']; }
$admLang = current_lang(); $admDir = $admLang === 'ar' ? 'rtl' : 'ltr';
require_once __DIR__ . '/admin_ui.php';

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
        'firms' => ['i' => 'building', 'ar' => 'المكاتب القانونية', 'en' => 'Law firms', 'href' => '/admin/firms.php'],
        'consultations' => ['i' => 'message', 'ar' => 'الاستشارات', 'en' => 'Consultations', 'href' => '/admin/consultations.php'],
        'consultation-attachments' => ['i' => 'file', 'ar' => 'مرفقات الاستشارات', 'en' => 'Consultation attachments', 'href' => '/admin/consultation-attachments.php'],
        'documents' => ['i' => 'file', 'ar' => 'المستندات', 'en' => 'Documents', 'href' => '/admin/documents.php'],
        'classification' => ['i' => 'scale', 'ar' => 'تصنيفات ناجز', 'en' => 'Najiz classifications', 'href' => '/admin/classification.php'],
    ]],
    ['t' => adm('المالية والتحليلات', 'Finance & insights'), 'items' => [
        'invoices' => ['i' => 'receipt', 'ar' => 'الفواتير والإيرادات', 'en' => 'Invoices & revenue', 'href' => '/admin/invoices.php'],
        'gateways' => ['i' => 'card', 'ar' => 'بوابات الدفع', 'en' => 'Payment gateways', 'href' => '/admin/gateways.php'],
        'gateway-transactions' => ['i' => 'receipt', 'ar' => 'عمليات الدفع', 'en' => 'Gateway transactions', 'href' => '/admin/gateway-transactions.php'],
        'plans' => ['i' => 'card', 'ar' => 'الباقات', 'en' => 'Plans', 'href' => '/admin/plans.php'],
        'reports' => ['i' => 'chart', 'ar' => 'التقارير والتحليلات', 'en' => 'Reports', 'href' => '/admin/reports.php'],
    ]],
    ['t' => adm('برنامج الشركاء', 'Affiliate programme'), 'items' => [
        'affiliates' => ['i' => 'users', 'ar' => 'المسوّقون', 'en' => 'Affiliates', 'href' => '/admin/affiliates.php'],
        'affiliate-applications' => ['i' => 'inbox', 'ar' => 'طلبات الانضمام', 'en' => 'Applications', 'href' => '/admin/affiliate-applications.php'],
        'affiliate-commissions' => ['i' => 'wallet', 'ar' => 'العمولات', 'en' => 'Commissions', 'href' => '/admin/affiliate-commissions.php'],
        'affiliate-payouts' => ['i' => 'receipt', 'ar' => 'طلبات الصرف', 'en' => 'Payout requests', 'href' => '/admin/affiliate-payouts.php'],
        'affiliate-campaigns' => ['i' => 'target', 'ar' => 'الحملات', 'en' => 'Campaigns', 'href' => '/admin/affiliate-campaigns.php'],
        'affiliate-fraud' => ['i' => 'shield', 'ar' => 'مكافحة الاحتيال', 'en' => 'Fraud review', 'href' => '/admin/affiliate-fraud.php'],
        'affiliate-settings' => ['i' => 'settings', 'ar' => 'إعدادات البرنامج', 'en' => 'Programme settings', 'href' => '/admin/affiliate-settings.php'],
    ]],
    ['t' => adm('المعرفة والذكاء الاصطناعي', 'Knowledge & AI'), 'items' => [
        'ai-control' => ['i' => 'shield', 'ar' => 'التحكم في AI والخصوصية', 'en' => 'AI control & privacy', 'href' => '/admin/ai-control.php'],
        'ai-knowledge' => ['i' => 'spark', 'ar' => 'مركز معرفة AI', 'en' => 'AI knowledge', 'href' => '/admin/ai-knowledge.php'],
        'ai-orchestration' => ['i' => 'bot', 'ar' => 'وحدات ووكلاء AI', 'en' => 'AI agents', 'href' => '/admin/ai-orchestration.php'],
          'ai-operations' => ['i' => 'settings', 'ar' => 'عمليات وحكامة AI', 'en' => 'AI operations', 'href' => '/admin/ai-operations.php'],
        'ai-workflows' => ['i' => 'check', 'ar' => 'سير عمل الوكلاء والموافقات', 'en' => 'Agent workflows', 'href' => '/admin/ai-workflows.php', 'badge' => 'wf'],
        'articles' => ['i' => 'book', 'ar' => 'مركز المعرفة', 'en' => 'Articles', 'href' => '/admin/articles.php'],
        'banners' => ['i' => 'image', 'ar' => 'بانرات الصفحة الرئيسية', 'en' => 'Home banners', 'href' => '/admin/banners.php'],
        'laws' => ['i' => 'landmark', 'ar' => 'مكتبة الأنظمة', 'en' => 'Law library', 'href' => '/admin/laws.php'],
    ]],
    ['t' => adm('النظام', 'System'), 'items' => [
        'integrations' => ['i' => 'external', 'ar' => 'التكاملات الخارجية', 'en' => 'External integrations', 'href' => '/admin/integrations.php'],
        'audit-log' => ['i' => 'shield', 'ar' => 'سجل التدقيق', 'en' => 'Audit log', 'href' => '/admin/audit-log.php'],
        'staff' => ['i' => 'users', 'ar' => 'الموظفون والصلاحيات', 'en' => 'Staff & permissions', 'href' => '/admin/staff.php'],
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
// Menu visibility. For the legacy roles this is the route map, unchanged. For a `staff`
// account it is the same permission check the guard applies, so a link is shown only when
// following it would actually be allowed. Hiding a link is a convenience, not the control:
// the guard refuses the route regardless of what the menu renders.
$canSee = function (string $href) use ($adminRole, $pdo): bool {
    $route = basename($href);
    if ($adminRole === 'staff') {
        return admin_staff_can_view_route($pdo, $route, (int)($_SESSION['admin_id'] ?? 0));
    }
    return admin_role_allows($route, $adminRole);
};
$initial = mb_strtoupper(mb_substr((string)($_SESSION['admin_name'] ?? 'A'), 0, 1, 'UTF-8'), 'UTF-8');
$roleLabels = ['super_admin' => adm('مدير عام', 'Super admin'), 'editor' => adm('محرر', 'Editor'), 'support' => adm('دعم', 'Support'), 'staff' => adm('موظف بصلاحيات محددة', 'Restricted staff')];
$langToggleUrl = '?' . http_build_query(array_merge($_GET, ['lang' => $admLang === 'ar' ? 'en' : 'ar']));
?>
<!DOCTYPE html>
<html lang="<?= h($admLang) ?>" dir="<?= $admDir ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<link rel="icon" href="/assets/img/logo.webp">
<title><?= h($pageTitle ? $pageTitle . ' · ' : '') ?><?= h(adm('لوحة تحكم مواثيق', 'Mawatheq Admin')) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@600;700;800&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="stylesheet" href="/assets/css/app.css">
<link rel="stylesheet" href="/assets/css/enterprise.css">
<link rel="stylesheet" href="/assets/css/dashboard.css">
<link rel="stylesheet" href="/admin/assets/admin-ui.css?v=41">
</head>
<body class="adm" data-theme="light">
<a class="skip-link" href="#adm-content"><?= h(adm('تخطَّ إلى المحتوى', 'Skip to content')) ?></a>
<script>(function(){try{var t=localStorage.getItem('adm_theme');if(!t&&window.matchMedia&&matchMedia('(prefers-color-scheme: dark)').matches)t='dark';document.body.setAttribute('data-theme',t||'light');if(localStorage.getItem('adm_sb')==='1'&&innerWidth>1024)document.body.classList.add('sb-collapsed')}catch(e){}})();</script>
<div class="adm-shell">
  <aside class="adm-sidebar" id="admSidebar">
    <a href="/admin/index.php" class="adm-brand"><img src="/assets/img/logo.webp" alt="<?= h(SITE_NAME_AR) ?>"><div><b><?= h(adm('مواثيق', 'Mawatheq')) ?></b><small>LegalTech Admin</small></div></a>
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
    <div class="adm-content" id="adm-content">
