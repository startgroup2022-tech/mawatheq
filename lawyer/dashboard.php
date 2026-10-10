<?php
/**
 * Lawyer workspace (Phase 4 UX rework).
 *
 * The lawyer's home, ordered by what a lawyer actually needs during a working day:
 * what is happening today, what needs a decision, what the work looks like, and
 * which services are available — rather than by the shape of the database.
 *
 * Every figure is a live, tenant-scoped count (the tenant layer decides whether
 * that means the firm or the individual lawyer), so a firm member sees the firm's
 * work and a solo lawyer sees their own. Firm-only destinations come from
 * includes/workspace.php, which omits them for a solo lawyer — the same rule the
 * sidebar follows — so this page never offers a route that answers 403.
 *
 * Note on plans: lawyers have no subscription entity in this product, so this page
 * does not invent a plan card. It reports the real account state (profile
 * completion, AI provider readiness) instead.
 */
$activeLawyer = 'dashboard';
require_once __DIR__ . '/includes/lawyer_header.php';
require_once __DIR__ . '/../includes/profile_completion.php';
require_once __DIR__ . '/../includes/tenant.php';
require_once __DIR__ . '/../includes/finance.php';
require_once __DIR__ . '/../includes/ai_provider.php';
require_once __DIR__ . '/../includes/workspace.php';
require_once __DIR__ . '/../includes/dashboard_panels.php';

$rtl = is_rtl();
$lawyerId = (int)$_SESSION['lawyer_id'];
$lawyerName = (string)($_SESSION['lawyer_name'] ?? '');

$lwStmt = $pdo->prepare('SELECT * FROM lawyers WHERE id=?');
$lwStmt->execute([$lawyerId]);
$lwProfile = $lwStmt->fetch() ?: [];
$completion = lawyer_completion_percent($lwProfile);
$isFirm = tenant_is_firm($pdo);

$caseScope    = tenant_owner_where($pdo, 'cases');
$caseScopeC   = tenant_owner_where($pdo, 'c');
$consultScope = tenant_owner_where($pdo, 'consultations');
$consultScopeCo = tenant_owner_where($pdo, 'co');
$eventScope   = tenant_owner_where($pdo, 'legal_calendar_events');
$eventScopeE  = tenant_owner_where($pdo, 'e');

/** Run a COUNT with the given tenant scope and return the integer. */
$count = function (string $sql, array $scope) use ($pdo): int {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($scope['params']);
    return (int)$stmt->fetchColumn();
};

$openCases   = $count("SELECT COUNT(*) FROM cases WHERE {$caseScope['sql']} AND status IN ('open','in_review')", $caseScope);
$totalCases  = $count("SELECT COUNT(*) FROM cases WHERE {$caseScope['sql']}", $caseScope);
$clientCount = $count("SELECT COUNT(DISTINCT user_id) FROM cases WHERE {$caseScope['sql']}", $caseScope);
$pendingConsults = $count("SELECT COUNT(*) FROM consultations WHERE {$consultScope['sql']} AND status='pending'", $consultScope);

$todaySessions = $count("SELECT COUNT(*) FROM legal_calendar_events WHERE {$eventScope['sql']} AND status='upcoming' AND DATE(event_at)=CURDATE()", $eventScope);
$todayConsults = $count("SELECT COUNT(*) FROM consultations WHERE {$consultScope['sql']} AND status IN ('pending','confirmed') AND DATE(scheduled_at)=CURDATE()", $consultScope);

$invScope = tenant_owner_where($pdo, 'invoices');
$overdue = $count("SELECT COUNT(*) FROM invoices WHERE {$invScope['sql']} AND is_draft=0 AND status IN ('unpaid','partially_paid','overdue') AND due_date IS NOT NULL AND due_date < CURDATE()", $invScope);

$finScope = tenant_owner_where($pdo, 'i');
$finSummary = finance_summary($pdo, $finScope);

$timeScope = tenant_owner_where($pdo, 'case_time_entries');
$timeStmt = $pdo->prepare("SELECT COALESCE(SUM(minutes),0) FROM case_time_entries WHERE {$timeScope['sql']} AND work_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
$timeStmt->execute($timeScope['params']);
$minutes30 = (int)$timeStmt->fetchColumn();

$settings = ai_load_settings($pdo);
$aiReady = !empty($settings['ai_provider']) && !empty($settings['ai_api_key']) && ($settings['ai_enabled'] ?? '1') !== '0';

/* ------------------------------------------------------------- Lists ---- */
$todayEvents = $pdo->prepare("SELECT e.*, c.title AS case_title FROM legal_calendar_events e LEFT JOIN cases c ON c.id=e.case_id WHERE {$eventScopeE['sql']} AND e.status='upcoming' AND e.event_at >= CURDATE() ORDER BY e.event_at ASC LIMIT 5");
$todayEvents->execute($eventScopeE['params']);
$todayEvents = $todayEvents->fetchAll();

$pendingList = $pdo->prepare("SELECT co.*, u.full_name AS client_name FROM consultations co JOIN users u ON u.id=co.user_id WHERE {$consultScopeCo['sql']} AND co.status='pending' ORDER BY co.scheduled_at ASC LIMIT 5");
$pendingList->execute($consultScopeCo['params']);
$pendingList = $pendingList->fetchAll();

$recentCases = $pdo->prepare("SELECT c.*, u.full_name AS client_name FROM cases c JOIN users u ON u.id=c.user_id WHERE {$caseScopeC['sql']} ORDER BY c.updated_at DESC, c.id DESC LIMIT 5");
$recentCases->execute($caseScopeC['params']);
$recentCases = $recentCases->fetchAll();

$topDebtors = finance_client_balances($pdo, $finScope, 5);
$topDebtors = array_values(array_filter($topDebtors, fn($r) => (float)$r['outstanding'] > 0));

/* ------------------------------------------------------------ Panels ---- */
// Shared read models (includes/dashboard_panels.php) — tenant-scoped and reused by
// the client home, so the two dashboards cannot describe the same data differently.
$panelClients   = dash_panel_lawyer_clients($pdo, 5);
$panelTasks     = dash_panel_lawyer_tasks($pdo, 6);
$panelDocs      = dash_panel_lawyer_documents($pdo, 5);
$panelDocsCount = dash_panel_lawyer_documents_count($pdo);
$panelDrafts    = dash_panel_lawyer_drafts($pdo, 5);
$draftsPending  = dash_panel_lawyer_drafts_pending_count($pdo);
$taskCount = $count("SELECT COUNT(*) FROM case_tasks t JOIN cases c ON c.id=t.case_id WHERE {$caseScopeC['sql']} AND t.status='pending'", $caseScopeC);
$overdueTasks = 0;
foreach ($panelTasks as $t) { if ($t['overdue']) { $overdueTasks++; } }

/* --------------------------------------------------- Needs attention ---- */
$attention = [];
if ($overdue) {
    $attention[] = ['sev' => 'danger', 'icon' => 'receipt', 'title' => $rtl ? 'فواتير متأخرة عن الاستحقاق' : 'Invoices past their due date',
                    'meta' => $rtl ? $overdue . ' فاتورة تحتاج متابعة تحصيل' : $overdue . ' invoice(s) need collection follow-up',
                    'href' => '/lawyer/invoices.php', 'cta' => $rtl ? 'متابعة التحصيل' : 'Follow up'];
}
if ($pendingConsults) {
    $attention[] = ['sev' => 'warning', 'icon' => 'clock', 'title' => $rtl ? 'استشارات بانتظار تأكيدك' : 'Consultations awaiting your confirmation',
                    'meta' => $rtl ? $pendingConsults . ' طلب استشارة من عملائك' : $pendingConsults . ' request(s) from your clients',
                    'href' => '/lawyer/consultations.php', 'cta' => $rtl ? 'مراجعة الطلبات' : 'Review'];
}
if ($todaySessions || $todayConsults) {
    $attention[] = ['sev' => 'info', 'icon' => 'calendar', 'title' => $rtl ? 'التزامات اليوم' : 'Today’s commitments',
                    'meta' => $rtl ? $todaySessions . ' موعد في التقويم و' . $todayConsults . ' استشارة' : $todaySessions . ' calendar item(s) and ' . $todayConsults . ' consultation(s)',
                    'href' => '/lawyer/calendar.php', 'cta' => $rtl ? 'فتح التقويم' : 'Open calendar'];
}
if ($completion < 100) {
    $attention[] = ['sev' => 'info', 'icon' => 'user', 'title' => $rtl ? 'ملفك المهني غير مكتمل' : 'Your professional profile is incomplete',
                    'meta' => $rtl ? 'اكتمال الملف ' . (int)$completion . '% — إكماله يزيد ظهورك للعملاء' : 'Profile is ' . (int)$completion . '% complete — finishing it improves client visibility',
                    'href' => '/lawyer/profile.php', 'cta' => $rtl ? 'أكمل الملف' : 'Complete profile'];
  }
  if ($overdueTasks) {
    $attention[] = ['sev' => 'danger', 'icon' => 'check', 'title' => $rtl ? 'مهام تجاوزت موعدها' : 'Tasks past their due date',
                    'meta' => $rtl ? $overdueTasks . ' مهمة تحتاج إجراءً عاجلاً' : $overdueTasks . ' task(s) need immediate action',
                    'href' => '/lawyer/cases.php', 'cta' => $rtl ? 'مراجعة المهام' : 'Review tasks'];
  }
  if ($draftsPending) {
    $attention[] = ['sev' => 'info', 'icon' => 'edit', 'title' => $rtl ? 'مسودات بانتظار قرار' : 'Drafts awaiting a decision',
                    'meta' => $rtl ? $draftsPending . ' مسودة تحتاج اعتماداً أو إرسالاً للعميل' : $draftsPending . ' draft(s) need approval or client review',
                    'href' => '/lawyer/drafting.php', 'cta' => $rtl ? 'فتح الصياغة' : 'Open drafting'];
  }
if (!$aiReady) {
    $attention[] = ['sev' => 'info', 'icon' => 'spark', 'title' => $rtl ? 'مزود الذكاء الاصطناعي غير مهيأ' : 'AI provider is not configured',
                    'meta' => $rtl ? 'اطلب من مدير المنصة تهيئته لتفعيل الصياغة الذكية' : 'Ask a platform administrator to configure it to enable AI drafting',
                    'href' => '/lawyer/drafting.php', 'cta' => $rtl ? 'التفاصيل' : 'Details'];
}

$serviceGroups = ws_lawyer_service_groups($isFirm);
$hasAnything = $totalCases || $clientCount || $todayEvents || $pendingList || $panelDrafts;
?>
<div class="app-content">
<div class="ws-content">

  <!-- ============================ Welcome ============================ -->
  <section class="ws-hero">
    <div class="ws-hero-main">
      <span class="ws-hero-eyebrow"><?= ui_icon('scale') ?> <?= $rtl ? 'مواثيق — مساحة العمل القانونية' : 'Mawatheq — legal workspace' ?></span>
      <h1><?= $rtl ? 'مرحباً، ' : 'Welcome, ' ?><?= ui_e($lawyerName) ?></h1>
      <p>
        <?php if ($todaySessions || $todayConsults): ?>
          <?= $rtl
              ? 'لديك اليوم ' . $todaySessions . ' موعداً في التقويم و' . $todayConsults . ' استشارة.'
              : 'You have ' . $todaySessions . ' calendar item(s) and ' . $todayConsults . ' consultation(s) today.' ?>
        <?php else: ?>
          <?= $rtl ? 'لا مواعيد مجدولة اليوم — وقت مناسب للمراجعة والصياغة.' : 'Nothing scheduled today — a good time for review and drafting.' ?>
        <?php endif; ?>
      </p>
      <div class="ws-hero-chips">
        <span class="ws-chip <?= $aiReady ? 'is-ok' : 'is-warn' ?>">
          <?= ui_icon($aiReady ? 'spark' : 'alert') ?>
          <?= $aiReady ? ($rtl ? 'الذكاء الاصطناعي مهيأ' : 'AI configured') : ($rtl ? 'الذكاء الاصطناعي غير مهيأ' : 'AI not configured') ?>
        </span>
        <span class="ws-chip"><?= ui_icon('briefcase') ?> <?= $rtl ? 'قضايا نشطة: ' : 'Active cases: ' ?><span class="num-ltr"><?= $openCases ?></span></span>
        <?php if ($isFirm): ?>
          <span class="ws-chip"><?= ui_icon('landmark') ?> <?= $rtl ? 'مساحة المكتب' : 'Firm workspace' ?></span>
        <?php endif; ?>
      </div>
    </div>
    <div class="ws-hero-actions">
      <a href="/lawyer/cases.php" class="btn btn-gold btn-sm"><?= ui_icon('plus') ?> <?= $rtl ? 'قضية جديدة' : 'New case' ?></a>
      <a href="/lawyer/invoices.php" class="btn btn-outline btn-sm"><?= ui_icon('receipt') ?> <?= $rtl ? 'فاتورة جديدة' : 'New invoice' ?></a>
    </div>
  </section>

  <?php if ($completion < 100): ?>
    <?php render_completion_card($lwProfile); ?>
  <?php endif; ?>

  <?php if (!$hasAnything): ?>
    <?= ui_empty_state(
        $rtl ? 'ابدأ من هنا' : 'Start here',
        $rtl ? 'لم تُسجّل أي قضية بعد. أضف أول قضية لعميلك ثم تابع مواعيدها وفواتيرها من هذه اللوحة.' : 'No cases recorded yet. Add your first client case and then track its schedule and invoices from this workspace.',
        'briefcase',
        [
          '<a class="btn btn-primary" href="/lawyer/cases.php">' . ($rtl ? 'أضف قضية' : 'Add a case') . '</a>',
          '<a class="btn btn-outline" href="/lawyer/profile.php">' . ($rtl ? 'أكمل ملفك' : 'Complete your profile') . '</a>',
        ]
    ) ?>
  <?php endif; ?>

  <!-- ======================= Needs attention ======================= -->
  <?php if ($attention): ?>
    <section class="ws-section">
      <div class="ws-section-head">
        <div>
          <h2><?= $rtl ? 'يحتاج انتباهك' : 'Needs your attention' ?></h2>
          <p><?= $rtl ? 'ما يستحق إجراءً الآن، مرتّباً حسب الأهمية.' : 'What deserves an action now, ordered by urgency.' ?></p>
        </div>
      </div>
      <div class="list-rows ws-attention">
        <?php foreach ($attention as $a): ?>
          <a class="list-row sev-<?= ui_e($a['sev']) ?>" href="<?= ui_e($a['href']) ?>">
            <span class="list-row-ico"><?= ui_icon($a['icon']) ?></span>
            <span class="list-row-main">
              <span class="list-row-title"><?= ui_e($a['title']) ?></span>
              <span class="list-row-meta"><?= ui_e($a['meta']) ?></span>
            </span>
            <span class="list-row-side"><span class="btn btn-outline btn-sm"><?= ui_e($a['cta']) ?></span></span>
          </a>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <!-- ========================= Today ========================= -->
  <section class="ws-section">
    <div class="ws-section-head">
      <div>
        <h2><?= $rtl ? 'اليوم' : 'Today' ?></h2>
        <p><?= $rtl ? 'مواعيدك القادمة وطلبات الاستشارة التي تنتظر قرارك.' : 'Your upcoming schedule and the consultation requests awaiting your decision.' ?></p>
      </div>
      <div class="ws-head-actions">
        <a class="btn btn-outline btn-sm" href="/lawyer/calendar.php"><?= $rtl ? 'التقويم' : 'Calendar' ?></a>
        <a class="btn btn-outline btn-sm" href="/lawyer/consultations.php"><?= $rtl ? 'الاستشارات' : 'Consultations' ?></a>
      </div>
    </div>

    <div class="grid-2">
      <div class="ws-group">
        <h3 class="ws-group-title"><?= $rtl ? 'القادم في تقويمك' : 'Coming up in your calendar' ?></h3>
        <?php if (!$todayEvents): ?>
          <div class="ws-empty-inline"><?= $rtl ? 'لا مواعيد قادمة. أضف جلسة أو موعداً من صفحة التقويم.' : 'No upcoming items. Add a session or deadline from the calendar page.' ?></div>
        <?php else: ?>
          <div class="list-rows">
            <?php foreach ($todayEvents as $ev):
              $typeLabels = [
                'court_session' => [$rtl ? 'جلسة محكمة' : 'Court session', 'landmark'],
                'appeal_deadline' => [$rtl ? 'موعد استئناف' : 'Appeal deadline', 'clock'],
                'contract_expiry' => [$rtl ? 'انتهاء عقد' : 'Contract expiry', 'file'],
                'contract_renewal' => [$rtl ? 'تجديد عقد' : 'Contract renewal', 'file'],
                'government_deadline' => [$rtl ? 'موعد حكومي' : 'Government deadline', 'shield'],
                'other' => [$rtl ? 'موعد آخر' : 'Other', 'calendar'],
              ];
              [$typeLabel, $typeIcon] = $typeLabels[$ev['event_type']] ?? [$ev['event_type'], 'calendar'];
              $isToday = date('Y-m-d', strtotime($ev['event_at'])) === date('Y-m-d');
            ?>
              <div class="list-row">
                <span class="list-row-ico"><?= ui_icon($typeIcon) ?></span>
                <span class="list-row-main">
                  <span class="list-row-title"><?= ui_e($ev['title']) ?></span>
                  <span class="list-row-meta">
                    <?= ui_e($typeLabel) ?> · <?= ui_date($ev['event_at'], true) ?>
                    <?php if (!empty($ev['case_title'])): ?> · <?= ui_e($ev['case_title']) ?><?php endif; ?>
                  </span>
                </span>
                <span class="list-row-side">
                  <?php if ($isToday): ?><span class="status-pill status-pending"><?= $rtl ? 'اليوم' : 'Today' ?></span><?php endif; ?>
                </span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="ws-group">
        <h3 class="ws-group-title"><?= $rtl ? 'استشارات بحاجة لتأكيد' : 'Consultations to confirm' ?></h3>
        <?php if (!$pendingList): ?>
          <div class="ws-empty-inline"><?= $rtl ? 'لا استشارات بانتظار التأكيد.' : 'No consultations awaiting confirmation.' ?></div>
        <?php else: ?>
          <div class="list-rows">
            <?php foreach ($pendingList as $co): ?>
              <div class="list-row">
                <span class="list-row-ico"><?= ui_icon('clock') ?></span>
                <span class="list-row-main">
                  <span class="list-row-title"><?= ui_e($co['client_name']) ?></span>
                  <span class="list-row-meta">
                    <?= ui_date($co['scheduled_at'], true) ?> ·
                    <span class="num-ltr"><?= (int)$co['duration_minutes'] ?></span> <?= $rtl ? 'دقيقة' : 'min' ?>
                    <?php if (!empty($co['topic'])): ?> · <?= ui_e($co['topic']) ?><?php endif; ?>
                  </span>
                </span>
                <span class="list-row-side">
                  <form method="post" action="/lawyer/consultations.php">
                    <input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="change_status">
                    <input type="hidden" name="consultation_id" value="<?= (int)$co['id'] ?>">
                    <input type="hidden" name="status" value="confirmed">
                    <button class="btn btn-primary btn-sm" type="submit"><?= $rtl ? 'تأكيد' : 'Confirm' ?></button>
                  </form>
                </span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <!-- ====================== Quick actions ====================== -->
  <section class="ws-section">
    <div class="ws-section-head">
      <div>
        <h3><?= $rtl ? 'إجراءات سريعة' : 'Quick actions' ?></h3>
        <p><?= $rtl ? 'أكثر ما تحتاجه أثناء يوم العمل.' : 'The things you reach for most during a working day.' ?></p>
      </div>
    </div>
    <div class="quick-actions">
      <a class="quick-action is-primary" href="/lawyer/cases.php">
        <span class="qa-ico"><?= ui_icon('plus') ?></span>
        <span><?= $rtl ? 'إضافة قضية' : 'Add a case' ?></span>
      </a>
      <a class="quick-action" href="/lawyer/calendar.php">
        <span class="qa-ico"><?= ui_icon('calendar') ?></span>
        <span><?= $rtl ? 'إضافة موعد' : 'Add an event' ?></span>
      </a>
      <a class="quick-action" href="/lawyer/drafting.php">
        <span class="qa-ico"><?= ui_icon('edit') ?></span>
        <span><?= $rtl ? 'صياغة مستند' : 'Draft a document' ?></span>
      </a>
      <a class="quick-action" href="/lawyer/invoices.php">
        <span class="qa-ico"><?= ui_icon('receipt') ?></span>
        <span><?= $rtl ? 'إنشاء فاتورة' : 'Create an invoice' ?></span>
      </a>
      <a class="quick-action" href="/lawyer/availability.php">
        <span class="qa-ico"><?= ui_icon('clock') ?></span>
        <span><?= $rtl ? 'ضبط أوقات الدوام' : 'Set availability' ?></span>
      </a>
      <a class="quick-action" href="/lawyer/profile.php">
        <span class="qa-ico"><?= ui_icon('user') ?></span>
        <span><?= $rtl ? 'تحديث ملفي' : 'Update my profile' ?></span>
      </a>
    </div>
  </section>

  <!-- ===================== Work overview ===================== -->
  <section class="ws-section">
    <div class="ws-section-head">
      <div>
        <h2><?= $rtl ? 'العمل القانوني' : 'Legal work' ?></h2>
        <p><?= $rtl ? 'نظرة سريعة على حجم العمل والتحصيل.' : 'A quick view of your workload and collections.' ?></p>
      </div>
    </div>

    <div class="kpi-grid">
      <a class="kpi kpi-teal" href="/lawyer/cases.php">
        <span class="kpi-ico"><?= ui_icon('briefcase') ?></span>
        <span class="kpi-body">
          <span class="kpi-value"><?= $openCases ?></span>
          <span class="kpi-label"><?= $rtl ? 'قضايا نشطة' : 'Active cases' ?></span>
          <span class="kpi-hint"><?= $rtl ? 'من إجمالي' : 'of' ?> <span class="num-ltr"><?= $totalCases ?></span></span>
        </span>
      </a>
      <a class="kpi kpi-navy" href="/lawyer/consultations.php">
        <span class="kpi-ico"><?= ui_icon('calendar') ?></span>
        <span class="kpi-body">
          <span class="kpi-value"><?= $pendingConsults ?></span>
          <span class="kpi-label"><?= $rtl ? 'استشارات بحاجة لتأكيد' : 'Consultations to confirm' ?></span>
          <span class="kpi-hint"><?= $pendingConsults ? ($rtl ? 'بانتظار قرارك' : 'Awaiting your decision') : ($rtl ? 'لا شيء معلّق' : 'Nothing pending') ?></span>
        </span>
      </a>
      <a class="kpi <?= $todaySessions || $todayConsults ? 'kpi-gold' : 'kpi-teal' ?>" href="/lawyer/calendar.php">
        <span class="kpi-ico"><?= ui_icon('clock') ?></span>
        <span class="kpi-body">
          <span class="kpi-value"><?= $todaySessions + $todayConsults ?></span>
          <span class="kpi-label"><?= $rtl ? 'مواعيد اليوم' : 'Today’s schedule' ?></span>
          <span class="kpi-hint"><span class="num-ltr"><?= $todaySessions ?></span> <?= $rtl ? 'تقويم' : 'calendar' ?> · <span class="num-ltr"><?= $todayConsults ?></span> <?= $rtl ? 'استشارة' : 'consult' ?></span>
        </span>
      </a>
      <a class="kpi kpi-navy" href="/lawyer/cases.php">
        <span class="kpi-ico"><?= ui_icon('users') ?></span>
        <span class="kpi-body">
          <span class="kpi-value"><?= $clientCount ?></span>
          <span class="kpi-label"><?= $rtl ? 'العملاء' : 'Clients' ?></span>
          <span class="kpi-hint"><?= $rtl ? 'عملاء لديهم قضايا مسجّلة' : 'Clients with recorded cases' ?></span>
        </span>
      </a>
      <a class="kpi <?= $overdue ? 'kpi-danger' : 'kpi-success' ?>" href="/lawyer/invoices.php">
        <span class="kpi-ico"><?= ui_icon('receipt') ?></span>
        <span class="kpi-body">
          <span class="kpi-value"><?= money($finSummary['outstanding']) ?></span>
          <span class="kpi-label"><?= $rtl ? 'مبالغ مستحقة التحصيل' : 'Outstanding receivables' ?></span>
          <span class="kpi-hint">
            <?php if ($overdue): ?>
              <span class="kpi-delta down"><span class="num-ltr"><?= $overdue ?></span></span> <?= $rtl ? 'فاتورة متأخرة' : 'overdue invoices' ?>
            <?php else: ?>
              <?= $rtl ? 'لا فواتير متأخرة' : 'No overdue invoices' ?>
            <?php endif; ?>
          </span>
        </span>
      </a>
      <a class="kpi kpi-success" href="/lawyer/invoices.php">
        <span class="kpi-ico"><?= ui_icon('paid') ?></span>
        <span class="kpi-body">
          <span class="kpi-value"><?= money($finSummary['collected']) ?></span>
          <span class="kpi-label"><?= $rtl ? 'المحصّل' : 'Collected' ?></span>
          <span class="kpi-hint"><?= $rtl ? 'من إجمالي' : 'of' ?> <?= money($finSummary['invoiced']) ?></span>
        </span>
      </a>
    </div>
  </section>

  <!-- ========================= Services ========================= -->
  <section class="ws-section">
    <div class="ws-section-head">
      <div>
        <h2><?= $rtl ? 'خدمات مواثيق' : 'Mawatheq services' ?></h2>
        <p><?= $rtl ? 'كل خدمة متاحة لك في مساحة عملك، مع وصف وزر مباشر.' : 'Every service available in your workspace, with a description and a direct action.' ?></p>
      </div>
    </div>
    <?= ws_render_service_groups($serviceGroups) ?>
  </section>

  <!-- ================== Drafting & AI center ================== -->
  <section class="ws-section">
    <div class="ws-ai">
      <div class="ws-ai-head">
        <div>
          <span class="ws-ai-badge"><?= ui_icon('spark') ?> <?= $rtl ? 'الصياغة والذكاء الاصطناعي' : 'Drafting & AI' ?></span>
          <h2><?= $rtl ? 'مركز الصياغة الذكية' : 'AI drafting center' ?></h2>
          <p><?= $rtl ? 'الصياغة الذكية مسنودة بمصادر نظامية، وتخضع لسياسة الخصوصية على مستوى المكتب.' : 'AI drafting is grounded in statutory sources and governed by your firm-level privacy policy.' ?></p>
        </div>
        <a class="btn btn-primary btn-sm" href="/lawyer/drafting.php"><?= $rtl ? 'افتح الصياغة الذكية' : 'Open AI drafting' ?></a>
      </div>

      <?php if ($aiReady): ?>
        <div class="ws-alert is-success">
          <?= ui_icon('check-circle') ?>
          <div><?= $rtl ? 'الذكاء الاصطناعي مهيأ وجاهز — يمكنك توليد المسودات وتحليل المستندات.' : 'AI is configured and ready — you can generate drafts and analyze documents.' ?></div>
        </div>
      <?php else: ?>
        <div class="ws-ai-empty">
          <?= ui_icon('alert') ?>
          <div>
            <?= $rtl
                ? 'لم يُهيأ مزود الذكاء الاصطناعي بعد، لذلك الصياغة الذكية غير مفعّلة. اطلب من مدير المنصة تهيئته من الإعدادات.'
                : 'The AI provider is not configured yet, so AI drafting is inactive. Ask a platform administrator to configure it in settings.' ?>
          </div>
        </div>
      <?php endif; ?>

      <div class="kpi-grid">
        <div class="kpi kpi-navy">
          <span class="kpi-ico"><?= ui_icon('clock') ?></span>
          <span class="kpi-body">
            <span class="kpi-value"><span class="num-ltr"><?= round($minutes30 / 60, 1) ?></span></span>
            <span class="kpi-label"><?= $rtl ? 'ساعات قابلة للفوترة (٣٠ يوماً)' : 'Billable hours (30 days)' ?></span>
            <span class="kpi-hint"><?= $rtl ? 'من سجل الوقت المفوتر' : 'From logged billable time' ?></span>
          </span>
        </div>
        <div class="kpi kpi-gold">
          <span class="kpi-ico"><?= ui_icon('alert') ?></span>
          <span class="kpi-body">
            <span class="kpi-value"><?= money($finSummary['overdue']) ?></span>
            <span class="kpi-label"><?= $rtl ? 'متأخرات التحصيل' : 'Overdue receivables' ?></span>
            <span class="kpi-hint"><span class="num-ltr"><?= $overdue ?></span> <?= $rtl ? 'فاتورة تجاوزت الاستحقاق' : 'invoices past due' ?></span>
          </span>
        </div>
        <div class="kpi kpi-teal">
          <span class="kpi-ico"><?= ui_icon('chart') ?></span>
          <span class="kpi-body">
            <span class="kpi-value"><?= money($finSummary['partially_paid_outstanding']) ?></span>
            <span class="kpi-label"><?= $rtl ? 'متبقٍّ من فواتير مدفوعة جزئياً' : 'Remaining on partially paid' ?></span>
            <span class="kpi-hint"><?= $rtl ? 'بحاجة لمتابعة تحصيل' : 'Needs follow-up' ?></span>
          </span>
        </div>
      </div>
    </div>
  </section>

  <div class="grid-2">
    <section class="ws-section">
      <div class="ws-section-head">
        <h3><?= $rtl ? 'آخر القضايا' : 'Recent cases' ?></h3>
        <a class="btn btn-outline btn-sm" href="/lawyer/cases.php"><?= $rtl ? 'كل القضايا' : 'All cases' ?></a>
      </div>
      <?php if (!$recentCases): ?>
        <div class="ws-empty-inline"><?= $rtl ? 'لا توجد قضايا بعد. ابدأ بإضافة أول قضية.' : 'No cases yet. Start by adding your first case.' ?></div>
      <?php else: ?>
        <div class="list-rows">
          <?php foreach ($recentCases as $c): ?>
            <a class="list-row" href="/lawyer/case-detail.php?id=<?= (int)$c['id'] ?>">
              <span class="list-row-ico"><?= ui_icon('briefcase') ?></span>
              <span class="list-row-main">
                <span class="list-row-title"><?= ui_e($c['title']) ?></span>
                <span class="list-row-meta">
                  <?= ui_e($c['client_name']) ?>
                  <?php if (!empty($c['case_type'])): ?> · <?= ui_e($c['case_type']) ?><?php endif; ?>
                  <?php if (!empty($c['updated_at'])): ?> · <?= $rtl ? 'آخر تحديث' : 'Updated' ?> <?= ui_date($c['updated_at']) ?><?php endif; ?>
                </span>
              </span>
              <span class="list-row-side">
                <?php if ($c['win_probability'] !== null): ?>
                  <span class="badge badge-neutral"><span class="num-ltr"><?= (int)$c['win_probability'] ?>%</span> <?= $rtl ? 'فوز' : 'win' ?></span>
                <?php endif; ?>
                <?= ui_badge($c['status'], ui_status_label($c['status'])) ?>
              </span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <section class="ws-section is-tertiary">
      <div class="ws-section-head">
        <h3><?= $rtl ? 'أكبر الأرصدة المستحقة' : 'Largest outstanding balances' ?></h3>
        <a class="btn btn-outline btn-sm" href="/lawyer/invoices.php"><?= $rtl ? 'الفواتير' : 'Invoices' ?></a>
      </div>
      <?php if (!$topDebtors): ?>
        <div class="ws-empty-inline"><?= $rtl ? 'لا توجد أرصدة مستحقة على العملاء.' : 'No outstanding client balances.' ?></div>
      <?php else: ?>
        <div class="list-rows">
          <?php foreach ($topDebtors as $d): ?>
            <div class="list-row">
              <span class="list-row-ico"><?= ui_icon('users') ?></span>
              <span class="list-row-main">
                <span class="list-row-title"><?= ui_e($d['full_name']) ?></span>
                <span class="list-row-meta">
                  <span class="num-ltr"><?= (int)$d['invoice_count'] ?></span> <?= $rtl ? 'فاتورة' : 'invoices' ?>
                  · <?= $rtl ? 'المحصّل' : 'Paid' ?> <?= money($d['paid']) ?>
                </span>
              </span>
              <span class="list-row-side">
                <span style="font-weight:800;color:var(--danger)"><?= money($d['outstanding']) ?></span>
              </span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
    <!-- ====================== Clients ====================== -->
    <section class="ws-section">
      <div class="ws-section-head">
        <div>
          <h2><?= $rtl ? 'عملائي' : 'My clients' ?></h2>
          <p><?= $rtl ? 'العميل صاحب أعلى رصيد مستحق يتصدّر القائمة — اضغط على أي عميل لعرض قضاياه.' : 'Clients ordered by outstanding balance — click a client to see their cases.' ?></p>
        </div>
        <div class="ws-head-actions">
          <a class="btn btn-outline btn-sm" href="/lawyer/cases.php"><?= $rtl ? 'كل القضايا' : 'All cases' ?></a>
        </div>
      </div>
      <?php if (!$panelClients): ?>
        <div class="ws-empty-inline"><?= $rtl ? 'لم يُسجَّل لك عملاء بعد. افتح قضية لعميل ليظهر هنا.' : 'No clients yet. Open a case for a client and they will appear here.' ?></div>
      <?php else: ?>
        <div class="list-rows">
          <?php foreach ($panelClients as $cl): ?>
            <a class="list-row" href="/lawyer/cases.php?q=<?= urlencode($cl['name']) ?>">
              <span class="list-row-ico"><?= ui_icon('users') ?></span>
              <span class="list-row-main">
                <span class="list-row-title"><?= ui_e($cl['name']) ?></span>
                <span class="list-row-meta">
                  <span class="num-ltr"><?= (int)$cl['open_cases'] ?></span> <?= $rtl ? 'قضية نشطة' : 'active' ?>
                  · <?= $rtl ? 'من' : 'of' ?> <span class="num-ltr"><?= (int)$cl['cases'] ?></span> <?= $rtl ? 'قضية' : 'cases' ?>
                </span>
              </span>
              <span class="list-row-side">
                <?php if ($cl['outstanding'] > 0): ?>
                  <span class="badge badge-danger"><?= $rtl ? 'مستحق' : 'Due' ?> <?= money($cl['outstanding']) ?></span>
                <?php else: ?>
                  <span class="badge badge-success"><?= $rtl ? 'لا مستحقات' : 'Settled' ?></span>
                <?php endif; ?>
              </span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <div class="grid-2">
      <!-- ====================== Tasks ====================== -->
      <section class="ws-section">
        <div class="ws-section-head">
          <h3><?= $rtl ? 'المهام المفتوحة' : 'Open tasks' ?></h3>
          <a class="btn btn-outline btn-sm" href="/lawyer/cases.php"><?= $rtl ? 'القضايا' : 'Cases' ?></a>
        </div>
        <?php if (!$panelTasks): ?>
          <div class="ws-empty-inline"><?= $rtl ? 'لا مهام مفتوحة على قضاياك.' : 'No open tasks on your cases.' ?></div>
        <?php else: ?>
          <div class="list-rows">
            <?php foreach ($panelTasks as $t): ?>
              <a class="list-row" href="/lawyer/case-detail.php?id=<?= (int)$t['case_id'] ?>">
                <span class="list-row-ico"><?= ui_icon('check') ?></span>
                <span class="list-row-main">
                  <span class="list-row-title"><?= ui_e($t['title']) ?></span>
                  <span class="list-row-meta">
                    <?= ui_e($t['case_title']) ?>
                    <?php if ($t['due_date']): ?> · <?= $rtl ? 'الاستحقاق' : 'Due' ?> <?= ui_date($t['due_date']) ?><?php endif; ?>
                  </span>
                </span>
                <span class="list-row-side">
                  <?php if ($t['overdue']): ?>
                    <span class="badge badge-danger"><?= $rtl ? 'متأخرة' : 'Overdue' ?></span>
                  <?php else: ?>
                    <span class="badge badge-info"><?= $rtl ? 'مفتوحة' : 'Open' ?></span>
                  <?php endif; ?>
                </span>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <!-- ====================== Documents ====================== -->
      <section class="ws-section is-tertiary">
        <div class="ws-section-head">
          <h3><?= $rtl ? 'مستندات العملاء' : 'Client documents' ?></h3>
          <?php if ($panelDocsCount > 0): ?>
            <span class="muted-sm"><?= $rtl ? 'الإجمالي' : 'Total' ?> <span class="num-ltr"><?= (int)$panelDocsCount ?></span></span>
          <?php endif; ?>
        </div>
        <?php if (!$panelDocs): ?>
          <div class="ws-empty-inline"><?= $rtl ? 'لا مستندات مرفوعة من عملائك بعد.' : 'Your clients have not uploaded any documents yet.' ?></div>
        <?php else: ?>
          <div class="list-rows">
            <?php foreach ($panelDocs as $doc):
              $st = dash_panel_document_state($doc['status']); ?>
              <div class="list-row">
                <span class="list-row-ico"><?= ui_icon('file') ?></span>
                <span class="list-row-main">
                  <span class="list-row-title"><?= ui_e($doc['name']) ?></span>
                  <span class="list-row-meta">
                    <?= ui_date($doc['created_at']) ?>
                    <?php if (!empty($doc['case_title'])): ?> · <?= ui_e($doc['case_title']) ?><?php endif; ?>
                  </span>
                </span>
                <span class="list-row-side">
                  <span class="badge badge-<?= ui_e($st['sev']) ?>"><?= ui_e($rtl ? $st['ar'] : $st['en']) ?></span>
                </span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    </div>
  </div>

    <!-- ===================== Drafts & reviews ===================== -->
    <section class="ws-section">
      <div class="ws-section-head">
        <div>
          <h2><?= $rtl ? 'المسودات والمراجعات' : 'Drafts & reviews' ?></h2>
          <p><?= $rtl ? 'أحدث المسودات وحالة اعتمادها، ومنتظرة قرارك.' : 'Your latest drafts and their approval state, and which ones await a decision.' ?></p>
        </div>
        <div class="ws-head-actions">
          <a class="btn btn-outline btn-sm" href="/lawyer/drafting.php"><?= $rtl ? 'كل المسودات' : 'All drafts' ?></a>
          <?php if ($isFirm): ?>
            <a class="btn btn-outline btn-sm" href="/lawyer/review-queue.php"><?= $rtl ? 'قائمة المراجعة' : 'Review queue' ?></a>
          <?php endif; ?>
        </div>
      </div>
      <?php if (!$panelDrafts): ?>
        <div class="ws-empty-inline"><?= $rtl ? 'لا مسودات بعد. ابدأ من الصياغة الذكية لإنشاء أول مسودة.' : 'No drafts yet. Start in AI drafting to create your first one.' ?></div>
      <?php else: ?>
        <div class="list-rows">
          <?php foreach ($panelDrafts as $d):
            $st = dash_panel_draft_state($d['approval_status']); ?>
            <a class="list-row" href="/lawyer/drafting.php#draft-<?= (int)$d['id'] ?>">
              <span class="list-row-ico"><?= ui_icon('edit') ?></span>
              <span class="list-row-main">
                <span class="list-row-title"><?= ui_e($d['title']) ?></span>
                <span class="list-row-meta">
                  <?php if (!empty($d['case_title'])): ?><?= ui_e($d['case_title']) ?> · <?php endif; ?>
                  <?= ui_date($d['created_at']) ?>
                  <?php if ($d['client_visible']): ?> · <?= $rtl ? 'مُرسلة للعميل' : 'Shared with client' ?><?php endif; ?>
                </span>
              </span>
              <span class="list-row-side">
                <span class="badge badge-<?= ui_e($st['sev']) ?>"><?= ui_e($rtl ? $st['ar'] : $st['en']) ?></span>
              </span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>


</div>
</div>
<?php require_once __DIR__ . '/includes/lawyer_footer.php'; ?>
