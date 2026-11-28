<?php
/** أيقونات SVG مضمّنة (CSP-safe، بلا مكتبات خارجية) + هيكل اللوحة المشترك. */
function ico(string $name): string {
    $p = [
        'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
        'spark'     => '<path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/><path d="M18.5 15.5l.8 2.2 2.2.8-2.2.8-.8 2.2-.8-2.2-2.2-.8 2.2-.8z"/>',
        'book'      => '<path d="M4 5.5A2.5 2.5 0 016.5 3H19v16H6.5A2.5 2.5 0 004 21.5z"/><path d="M4 19V5.5"/>',
        'plus'      => '<path d="M12 5v14M5 12h14"/>',
        'save'      => '<path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><path d="M17 21v-8H7v8M7 3v5h8"/>',
        'send'      => '<path d="M22 2L11 13M22 2l-7 20-4-9-9-4z"/>',
        'alert'     => '<path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z"/>',
        'file'      => '<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6"/>',
        'image'     => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/>',
        'paperclip' => '<path d="M21.4 11.05l-9.19 9.19a6 6 0 01-8.49-8.49l9.19-9.19a4 4 0 015.66 5.66l-9.2 9.19a2 2 0 01-2.83-2.83l8.49-8.48"/>',
        'folder'    => '<path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/>',
        'edit'      => '<path d="M17 3a2.8 2.8 0 014 4L7.5 20.5 2 22l1.5-5.5z"/>',
        'users'     => '<path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/>',
        'briefcase' => '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/>',
        'calendar'  => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'check'     => '<path d="M20 6L9 17l-5-5"/>',
        'clock'     => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'receipt'   => '<path d="M4 2v20l3-2 3 2 3-2 3 2 3-2V2l-3 2-3-2-3 2-3-2z"/><path d="M8 9h8M8 13h8"/>',
        'chart'     => '<path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/>',
        'bot'       => '<rect x="4" y="8" width="16" height="12" rx="3"/><path d="M12 8V4M9 14h.01M15 14h.01"/><circle cx="12" cy="4" r="1.4"/>',
        'settings'  => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 00.3 1.9l.1.1a2 2 0 11-2.8 2.8l-.1-.1a1.7 1.7 0 00-2.9 1.2 2 2 0 11-4 0 1.7 1.7 0 00-2.9-1.2l-.1.1a2 2 0 11-2.8-2.8l.1-.1A1.7 1.7 0 004 15a2 2 0 010-4 1.7 1.7 0 001.2-2.9l-.1-.1a2 2 0 112.8-2.8l.1.1A1.7 1.7 0 0011 4a2 2 0 014 0 1.7 1.7 0 002.9 1.2l.1-.1a2 2 0 112.8 2.8l-.1.1A1.7 1.7 0 0020 11a2 2 0 010 4z"/>',
        'search'    => '<circle cx="11" cy="11" r="8"/><path d="M21 21l-4.3-4.3"/>',
        'bell'      => '<path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 01-3.4 0"/>',
        'download'  => '<path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><path d="M7 10l5 5 5-5M12 15V3"/>',
        'shield'    => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'wallet'    => '<path d="M21 12V7H5a2 2 0 010-4h14v4"/><path d="M3 5v14a2 2 0 002 2h16v-5"/><path d="M18 12a2 2 0 000 4h4v-4z"/>',
        'layers'    => '<path d="M12 2L2 7l10 5 10-5z"/><path d="M2 17l10 5 10-5M2 12l10 5 10-5"/>',
    ];
    $d = $p[$name] ?? $p['file'];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
      stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
}
$navGroups = [
    'مساحة العمل' => [
        ['dashboard', 'لوحة التحكم', 'dashboard', false],
        ['ai-workspace', 'المساعد الذكي', 'spark', true],
        ['templates', 'مكتبة القوالب', 'book', false],
        ['editor', 'محرر المستندات', 'edit', false],
    ],
    'إدارة المكتب' => [
        ['clients', 'الموكلون', 'users', false],
        ['cases', 'القضايا', 'briefcase', false],
        ['calendar', 'الجلسات والمواعيد', 'calendar', false],
        ['tasks', 'المهام', 'check', false],
        ['finance', 'المالية', 'receipt', false],
    ],
    'المكتب' => [
        ['chatbot', 'الشات بوت', 'bot', false],
        ['team', 'الفريق والصلاحيات', 'shield', false],
        ['reports', 'التقارير', 'chart', false],
    ],
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($pageTitle ?? 'مواثيق') ?> — مواثيق</title>
<link rel="stylesheet" href="preview.css">
</head>
<body>
<div class="shell">
  <aside class="sidebar">
    <div class="brand">
      <span class="brand-mark">م</span>
      <span>مواثيق<small>مساحة عمل المحامي</small></span>
    </div>
    <?php foreach ($navGroups as $label => $items): ?>
      <div class="nav-group">
        <div class="nav-label"><?= htmlspecialchars($label) ?></div>
        <?php foreach ($items as [$key, $text, $icon, $badge]): ?>
          <div class="nav-item<?= ($active ?? '') === $key ? ' active' : '' ?>">
            <?= ico($icon) ?><span><?= htmlspecialchars($text) ?></span>
            <?php if ($badge): ?><span class="nav-badge">جديد</span><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </aside>

  <div class="main">
    <header class="topbar">
      <div class="tb-title"><?= htmlspecialchars($pageTitle ?? 'مواثيق') ?></div>
      <div class="spacer"></div>
      <div class="searchbox"><?= ico('search') ?> <span>بحث في القضايا والموكلين والمستندات…</span></div>
      <button class="iconbtn"><?= ico('bell') ?><span class="dot"></span></button>
      <div class="avatar">ع م</div>
    </header>
    <div class="content">
