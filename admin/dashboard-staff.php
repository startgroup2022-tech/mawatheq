<?php
/**
 * The restricted dashboard shown to `staff` accounts (Execution 34).
 *
 * The main dashboard is built around confidential material: collected revenue, outstanding
 * invoices, client counts, case counts, the opportunity pipeline, lawyer performance and the
 * audit trail. None of that may be shown to a restricted employee, and the brief is explicit
 * that dashboard access must not imply access to it.
 *
 * Rather than hiding panels with CSS — which leaves the figures in the HTML for anyone who
 * opens the page source — this is a separate page. It renders one tile per module the
 * account actually holds, and nothing else. A staff account with no grants sees the
 * "no modules assigned" notice and no data at all.
 *
 * Expected variables: $pdo, and the helpers loaded by admin_header.php.
 */

// This file is a fragment that `index.php` includes for a `staff` account. Reaching it
// directly would run its body with no session, no `$pdo` and none of the header's helpers,
// which is a fatal error — an HTTP 500 for a URL nobody is meant to type. Guarding on the
// include instead of assuming it, so a direct request is redirected to the console rather
// than crashing. `index.php` defines the constant just before it includes this file.
if (!defined('MAWATHEQ_ADMIN_STAFF_DASHBOARD')) {
    require_once __DIR__ . '/includes/admin_guard.php';
    header('Location: /admin/index.php');
    exit;
}

$staffId = (int)($_SESSION['admin_id'] ?? 0);
$grants = admin_staff_permissions($pdo, $staffId);

/** A count, or null when the table is unavailable. Never fatal. */
$countOrNull = function (string $sql) use ($pdo): ?int {
    try { return (int)$pdo->query($sql)->fetchColumn(); } catch (Throwable $e) { return null; }
};

// Each tile is keyed by the module it belongs to, so the list below is built by filtering on
// what the account holds rather than by hiding things after the fact. Every query reads a
// table belonging to a grantable module only — no case, client, consultation or finance
// table is touched, so the restricted dashboard has no way to leak those figures even by
// accident.
$tiles = [
    'blog' => [
        'href' => '/admin/articles.php', 'icon' => 'book',
        'ar' => 'المقالات', 'en' => 'Articles',
        'count' => fn() => $countOrNull("SELECT COUNT(*) FROM articles"),
        'ar_sub' => 'مقالات مركز المعرفة', 'en_sub' => 'Knowledge-base articles',
    ],
    'laws' => [
        'href' => '/admin/laws.php', 'icon' => 'landmark',
        'ar' => 'مكتبة الأنظمة', 'en' => 'Law library',
        'count' => fn() => $countOrNull("SELECT COUNT(*) FROM law_articles"),
        'ar_sub' => 'المواد النظامية', 'en_sub' => 'Statutory articles',
    ],
    'classification' => [
        'href' => '/admin/classification.php', 'icon' => 'scale',
        'ar' => 'تصنيفات ناجز', 'en' => 'Najiz classifications',
        'count' => fn() => $countOrNull("SELECT COUNT(*) FROM najiz_case_types"),
        'ar_sub' => 'أنواع الدعاوى', 'en_sub' => 'Lawsuit types',
    ],
    'leads' => [
        'href' => '/admin/leads.php', 'icon' => 'inbox',
        'ar' => 'الطلبات الواردة', 'en' => 'Inbound leads',
        'count' => fn() => $countOrNull("SELECT COUNT(*) FROM contact_leads WHERE status='new'"),
        'ar_sub' => 'طلبات جديدة', 'en_sub' => 'New inquiries',
    ],
    'crm' => [
        'href' => '/admin/crm.php', 'icon' => 'target',
        'ar' => 'الفرص والمتابعات', 'en' => 'Opportunities & follow-ups',
        'count' => fn() => $countOrNull("SELECT COUNT(*) FROM crm_opportunities WHERE stage NOT IN ('won','lost')"),
        'ar_sub' => 'فرص مفتوحة', 'en_sub' => 'Open opportunities',
    ],
    'marketing' => [
        'href' => '/admin/affiliate-campaigns.php', 'icon' => 'target',
        'ar' => 'الحملات التسويقية', 'en' => 'Marketing campaigns',
        'count' => null,
        'ar_sub' => 'حملات برنامج الشركاء', 'en_sub' => 'Affiliate programme campaigns',
    ],
    'affiliate-applications' => [
        'href' => '/admin/affiliate-applications.php', 'icon' => 'inbox',
        'ar' => 'طلبات المسوّقين', 'en' => 'Affiliate applications',
        'count' => fn() => $countOrNull("SELECT COUNT(*) FROM affiliate_applications WHERE status='pending'"),
        'ar_sub' => 'طلبات قيد المراجعة', 'en_sub' => 'Awaiting review',
    ],
    'calendar' => [
        'href' => '/admin/calendar.php', 'icon' => 'calendar',
        'ar' => 'التقويم', 'en' => 'Calendar',
        'count' => fn() => $countOrNull("SELECT COUNT(*) FROM legal_calendar_events WHERE event_at >= NOW() AND status='upcoming'"),
        'ar_sub' => 'مواعيد قادمة', 'en_sub' => 'Upcoming events',
    ],
    'notifications' => [
        'href' => '/admin/notifications.php', 'icon' => 'bell',
        'ar' => 'الإشعارات', 'en' => 'Notifications',
        'count' => null,
        'ar_sub' => 'تنبيهات موجّهة إليك', 'en_sub' => 'Alerts addressed to you',
    ],
    'reports' => [
        'href' => '/admin/reports.php', 'icon' => 'chart',
        'ar' => 'التقارير التشغيلية', 'en' => 'Operational reports',
        'count' => null,
        'ar_sub' => 'تقارير غير مالية', 'en_sub' => 'Non-financial reporting',
    ],
];

$held = [];
foreach ($tiles as $moduleKey => $tile) {
    if (!admin_staff_can($pdo, $staffId, $moduleKey, 'view')) continue;
    $held[$moduleKey] = $tile;
}

$hour = (int)date('G');
$greet = $hour < 12 ? adm('صباح الخير', 'Good morning') : ($hour < 18 ? adm('مساء الخير', 'Good afternoon') : adm('مساء الخير', 'Good evening'));
$firstName = explode(' ', trim((string)($_SESSION['admin_name'] ?? '')))[0];
?>
<div class="app-content">
  <div class="pg-head">
    <div>
      <h1><?= h($greet) ?><?= $firstName !== '' ? '، ' . h($firstName) : '' ?> 👋</h1>
      <p><?= h(adm('هذه وحدات العمل المسندة إليك.', 'These are the work areas assigned to you.')) ?></p>
    </div>
  </div>

  <?php if (!$held): ?>
    <div class="card">
      <div class="card-h"><h3><?= h(adm('لا توجد وحدات مسندة', 'No modules assigned')) ?></h3></div>
      <p style="line-height:1.9;margin:0">
        <?= h(adm('حسابك نشط، لكنه لا يملك صلاحية الوصول إلى أي وحدة حتى الآن. راجع مدير النظام لإسناد ما تحتاجه.',
                     'Your account is active but has no module access yet. Ask the system administrator to assign what you need.')) ?>
      </p>
    </div>
  <?php else: ?>
    <div class="grid g-4">
      <?php foreach ($held as $moduleKey => $tile):
          $count = $tile['count'] !== null ? ($tile['count'])() : null; ?>
        <a class="kpi" href="<?= h($tile['href']) ?>">
          <div class="kpi-top"><span class="kpi-ic"><?= adm_icon($tile['icon']) ?></span></div>
          <?php if ($count !== null): ?>
            <div class="kpi-val"><?= number_format($count) ?></div>
          <?php endif; ?>
          <div class="kpi-lbl"><?= h(adm($tile['ar'], $tile['en'])) ?></div>
          <small style="color:var(--muted)"><?= h(adm($tile['ar_sub'], $tile['en_sub'])) ?></small>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="card" style="margin-block-start:18px">
    <div class="card-h"><h3><?= h(adm('صلاحياتك', 'Your permissions')) ?></h3></div>
    <p style="margin:0 0 12px;color:var(--muted);font-size:.9rem">
      <?= h(adm('ما يلي هو كل ما يمكنك الوصول إليه. للتوسيع، تواصل مع مدير النظام.',
                   'This is everything you can reach. To extend it, contact the system administrator.')) ?>
    </p>
    <div style="overflow-x:auto">
    <table class="admin-table">
      <thead><tr>
        <th><?= h(adm('الوحدة', 'Module')) ?></th>
        <th><?= h(adm('الإجراءات', 'Actions')) ?></th>
      </tr></thead>
      <tbody>
      <?php $actionLabels = admin_staff_actions(); foreach ($grants as $moduleKey => $actions):
          $module = admin_staff_module($pdo, $moduleKey);
          if ($module === null || (int)$module['grantable'] !== 1) continue;
          $labels = [];
          foreach ($actions as $actionKey) if (isset($actionLabels[$actionKey])) $labels[] = adm($actionLabels[$actionKey]['ar'], $actionLabels[$actionKey]['en']); ?>
        <tr>
          <td><b><?= h(adm($module['label_ar'], $module['label_en'])) ?></b></td>
          <td><?= h(implode('، ', $labels)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>
