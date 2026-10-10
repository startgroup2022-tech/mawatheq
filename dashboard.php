<?php
/**
 * Client workspace (Phase 4 UX rework).
 *
 * The client's home. It is ordered to answer, in the first screen, the questions a
 * client actually has: who am I, what is my plan, what can I use, what are the AI
 * services, what have I consumed, what needs an action, and where do I go next.
 *
 * Every figure is a live query scoped to this user; every AI state comes from
 * includes/workspace.php, which in turn reads the shared catalog and the same
 * usage counters the server-side guard uses. Nothing here decides access — the
 * endpoints do — so a service shown as available really is, and one shown as
 * blocked would be refused if it were called directly.
 */
$activePage = 'dashboard';
require_once __DIR__ . '/includes/app_header.php';
require_once __DIR__ . '/includes/finance.php';
require_once __DIR__ . '/includes/workspace.php';
require_once __DIR__ . '/includes/dashboard_panels.php';

$userId = (int)$_SESSION['user_id'];
$rtl = is_rtl();
$userName = (string)($_SESSION['user_name'] ?? '');

/* ------------------------------------------------------------- Plan ---- */
$planUsage = ai_plan_usage($pdo, $userId);
$plan = $planUsage['plan'];
$planName = $plan ? ($rtl ? $plan['name_ar'] : $plan['name_en']) : null;

$subStmt = $pdo->prepare('SELECT * FROM subscriptions WHERE user_id=? ORDER BY id DESC LIMIT 1');
$subStmt->execute([$userId]);
$subscription = $subStmt->fetch() ?: null;

/* ------------------------------------------------------- Counts ---- */
$count = function (string $sql, array $params = []) use ($pdo): int {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
};

$upcomingCount = $count("SELECT COUNT(*) FROM consultations WHERE user_id=? AND status IN ('pending','confirmed') AND scheduled_at >= NOW()", [$userId]);
$activeCases   = $count("SELECT COUNT(*) FROM cases WHERE user_id=? AND status IN ('open','in_review')", [$userId]);
$totalCases    = $count('SELECT COUNT(*) FROM cases WHERE user_id=?', [$userId]);
$pendingTasks  = $count("SELECT COUNT(*) FROM case_tasks t JOIN cases c ON c.id=t.case_id WHERE c.user_id=? AND t.client_visible=1 AND t.status='pending'", [$userId]);
$draftsToReview = $count("SELECT COUNT(*) FROM draft_documents d JOIN cases c ON c.id=d.case_id WHERE c.user_id=? AND d.client_visible=1 AND d.approval_status IN ('submitted','changes_requested')", [$userId]);
$docCount      = $count('SELECT COUNT(*) FROM documents WHERE user_id=?', [$userId]);

$invStmt = $pdo->prepare('SELECT * FROM invoices WHERE user_id=? AND is_draft=0 ORDER BY created_at DESC');
$invStmt->execute([$userId]);
$allInvoices = $invStmt->fetchAll();
$outstanding = 0.0;
$overdueCount = 0;
foreach ($allInvoices as $inv) {
    $display = finance_display_status($inv);
    if ($display !== 'paid' && $display !== 'cancelled') {
        $outstanding += finance_invoice_balance($inv);
    }
    if ($display === 'overdue') $overdueCount++;
}

/* --------------------------------------------------------- Lists ---- */
$upcoming = $pdo->prepare("SELECT co.*, l.full_name AS lawyer_name FROM consultations co JOIN lawyers l ON l.id=co.lawyer_id WHERE co.user_id=? AND co.status IN ('pending','confirmed') AND co.scheduled_at >= NOW() ORDER BY co.scheduled_at ASC LIMIT 4");
$upcoming->execute([$userId]);
$upcoming = $upcoming->fetchAll();

$tasks = $pdo->prepare("SELECT t.*, c.title AS case_title FROM case_tasks t JOIN cases c ON c.id=t.case_id WHERE c.user_id=? AND t.client_visible=1 AND t.status='pending' ORDER BY (t.due_date IS NULL), t.due_date ASC, t.id DESC LIMIT 4");
$tasks->execute([$userId]);
$tasks = $tasks->fetchAll();

$cases = $pdo->prepare('SELECT c.*, l.full_name AS lawyer_name FROM cases c JOIN lawyers l ON l.id=c.lawyer_id WHERE c.user_id=? ORDER BY c.updated_at DESC, c.id DESC LIMIT 4');
$cases->execute([$userId]);
$cases = $cases->fetchAll();

  $recentInvoices = array_slice($allInvoices, 0, 4);

  /* ------------------------------------------------------------ Panels ---- */
  // Shared read models (includes/dashboard_panels.php) — same module the lawyer
  // home uses, so both screens describe the same rows the same way.
  $panelLawyers   = dash_panel_client_lawyers($pdo, $userId, 4);
  $panelDocuments = dash_panel_client_documents($pdo, $userId, 5);
  $panelAlerts    = dash_panel_client_alerts($pdo, $userId, 5);
  $unreadAlerts   = dash_panel_client_unread_count($pdo, $userId);

/* --------------------------------------------- Services + AI cards ---- */
$serviceGroups = ws_client_service_groups();
$aiCards = ws_ai_service_cards($pdo, $userId);
$allCards = array_merge(ws_flatten_cards($serviceGroups), $aiCards);
$blockedCards = array_values(array_filter($allCards, fn($c) => in_array($c['state'], ['locked', 'limit_reached', 'firm_only'], true)));

/* --------------------------------------------------- Needs attention ---- */
// Built only from real records: an overdue invoice, a shared task, a draft to
// review, or a consultation that has already started. Nothing is fabricated.
$attention = [];
if ($overdueCount) {
    $attention[] = ['sev' => 'danger', 'icon' => 'receipt', 'title' => $rtl ? 'لديك فاتورة متأخرة' : 'You have an overdue invoice',
                    'meta' => $rtl ? 'يرجى السداد لتجنّب توقف الخدمة' : 'Please settle it to avoid service interruption',
                    'href' => '/invoices.php', 'cta' => $rtl ? 'عرض الفاتورة' : 'View invoice'];
}
if ($pendingTasks) {
    $attention[] = ['sev' => 'warning', 'icon' => 'check', 'title' => $rtl ? 'مهام بانتظارك' : 'Tasks awaiting you',
                    'meta' => $rtl ? $pendingTasks . ' مهمة تحتاج إجراءً منك' : $pendingTasks . ' task(s) need an action from you',
                    'href' => '/case-tasks.php', 'cta' => $rtl ? 'عرض المهام' : 'View tasks'];
}
if ($draftsToReview) {
    $attention[] = ['sev' => 'info', 'icon' => 'edit', 'title' => $rtl ? 'مسودات بانتظار مراجعتك' : 'Drafts awaiting your review',
                    'meta' => $rtl ? $draftsToReview . ' مسودة جاهزة للاعتماد' : $draftsToReview . ' draft(s) ready for approval',
                    'href' => '/draft-reviews.php', 'cta' => $rtl ? 'فتح المراجعة' : 'Open review'];
}
if ($upcomingCount) {
    $next = $upcoming[0] ?? null;
    $attention[] = ['sev' => 'info', 'icon' => 'calendar', 'title' => $rtl ? 'استشارة قادمة' : 'Upcoming consultation',
                    'meta' => $next ? ($rtl ? 'أقرب موعد: ' : 'Next: ') . date('Y/m/d H:i', strtotime($next['scheduled_at'])) : '',
                    'href' => '/consultations.php', 'cta' => $rtl ? 'عرض المواعيد' : 'View schedule'];
}
foreach ($aiCards as $c) {
    if ($c['state'] === 'limit_reached') {
        $attention[] = ['sev' => 'warning', 'icon' => 'spark', 'title' => $rtl ? 'استهلكت حد ' . $c['title'] : 'Limit reached for ' . $c['title'],
                        'meta' => $rtl ? 'تواصل معنا لترقية الباقة لمتابعة الاستخدام' : 'Contact us to upgrade and continue',
                        'href' => '/contact.php?topic=plan', 'cta' => $rtl ? 'ترقية الباقة' : 'Upgrade plan'];
    }
}

$hasAnything = $upcomingCount || $totalCases || $pendingTasks || $draftsToReview || $docCount || $allInvoices;
?>
<div class="app-content">
<div class="ws-content">

  <!-- ============================ Welcome ============================ -->
  <section class="ws-hero">
    <div class="ws-hero-main">
      <span class="ws-hero-eyebrow"><?= ui_icon('scale') ?> <?= $rtl ? 'مواثيق — منصة الخدمات والتقنية القانونية' : 'Mawatheq — legal services & technology platform' ?></span>
      <h1><?= $rtl ? 'مرحباً، ' : 'Welcome, ' ?><?= ui_e($userName) ?></h1>
      <p><?= $rtl ? 'كيف يمكن لمواثيق مساعدتك اليوم؟ هذه لوحة خدماتك وما يحتاج انتباهك.' : 'How can Mawatheq help you today? This is your services workspace and what needs your attention.' ?></p>
      <div class="ws-hero-chips">
        <?php if ($planName): ?>
          <a class="ws-chip is-ok" href="/subscription.php"><?= ui_icon('card') ?> <?= $rtl ? 'باقتك: ' : 'Plan: ' ?><?= ui_e($planName) ?></a>
        <?php else: ?>
          <a class="ws-chip is-warn" href="/contact.php?topic=plan"><?= ui_icon('alert') ?> <?= $rtl ? 'لا توجد باقة مفعّلة' : 'No active plan' ?></a>
        <?php endif; ?>
        <?php if ($attention): ?>
          <span class="ws-chip is-warn"><?= ui_icon('bell') ?> <?= count($attention) ?> <?= $rtl ? 'بحاجة انتباهك' : 'need attention' ?></span>
        <?php else: ?>
          <span class="ws-chip"><?= ui_icon('check-circle') ?> <?= $rtl ? 'لا شيء معلّق' : 'Nothing pending' ?></span>
        <?php endif; ?>
      </div>
    </div>
    <div class="ws-hero-actions">
      <a class="btn btn-gold btn-sm" href="/consultations.php"><?= ui_icon('calendar') ?> <?= $rtl ? 'احجز استشارة' : 'Book a consultation' ?></a>
      <a class="btn btn-outline btn-sm" href="/ai-assistant.php"><?= ui_icon('spark') ?> <?= $rtl ? 'اسأل المساعد' : 'Ask the assistant' ?></a>
    </div>
  </section>

  <?php if (!$hasAnything): ?>
    <?= ui_empty_state(
        $rtl ? 'أهلاً بك في مواثيق' : 'Welcome to Mawatheq',
        $rtl ? 'ابدأ بحجز استشارة مع محامٍ، أو اطرح سؤالاً على المساعد القانوني، أو ارفع مستنداً لتحليله. كل ما تفعله سيظهر في لوحتك.' : 'Start by booking a consultation, asking the legal assistant a question, or uploading a document to analyze. Everything you do will appear on your workspace.',
        'spark',
        [
          '<a class="btn btn-primary" href="/consultations.php">' . ($rtl ? 'احجز استشارة' : 'Book a consultation') . '</a>',
          '<a class="btn btn-outline" href="/documents.php">' . ($rtl ? 'ارفع مستنداً' : 'Upload a document') . '</a>',
        ]
    ) ?>
  <?php endif; ?>

  <!-- ======================= Needs attention ======================= -->
  <?php if ($attention): ?>
    <section class="ws-section">
      <div class="ws-section-head">
        <div>
          <h2><?= $rtl ? 'يحتاج انتباهك' : 'Needs your attention' ?></h2>
          <p><?= $rtl ? 'إجراءات مرتبطة بحسابك وتحتاج قراراً أو متابعة.' : 'Items on your account that need a decision or follow-up.' ?></p>
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
            <span class="list-row-side">
              <span class="btn btn-outline btn-sm"><?= ui_e($a['cta']) ?></span>
            </span>
          </a>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <!-- ====================== Plan + usage ====================== -->
  <section class="ws-section">
    <div class="ws-section-head">
      <div>
        <h2><?= $rtl ? 'باقتك' : 'Your plan' ?></h2>
        <p><?= $rtl ? 'ما هي باقتك الحالية وما الذي استهلكته هذا الشهر.' : 'Your current plan and what you have consumed this month.' ?></p>
      </div>
      <div class="ws-head-actions">
        <a class="btn btn-outline btn-sm" href="/subscription.php"><?= $rtl ? 'تفاصيل الباقة' : 'Plan details' ?></a>
      </div>
    </div>

    <div class="plan-card <?= $plan ? '' : 'is-empty' ?>">
      <div class="plan-card-main">
        <div class="plan-name">
          <span class="pn-ico"><?= ui_icon($plan && !empty($plan['is_corporate']) ? 'landmark' : 'card') ?></span>
          <div>
            <h3><?= $planName ? ui_e($planName) : ($rtl ? 'لا توجد باقة مفعّلة' : 'No active plan') ?></h3>
            <?php if ($plan): ?>
              <span class="pn-price"><?= (float)$plan['price_monthly'] > 0 ? money($plan['price_monthly']) . ' / ' . ($rtl ? 'شهر' : 'mo') : ($rtl ? 'مجاناً' : 'Free') ?></span>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($plan): ?>
          <div class="plan-facts">
            <div class="plan-fact">
              <span class="pf-label"><?= $rtl ? 'الحالة' : 'Status' ?></span>
              <span class="pf-value"><?= $subscription ? ui_e(ui_status_label($subscription['status'])) : ($rtl ? 'مفعّلة' : 'Active') ?></span>
            </div>
            <?php if ($subscription && !empty($subscription['ends_at'])): ?>
              <div class="plan-fact">
                <span class="pf-label"><?= $rtl ? 'الانتهاء / التجديد' : 'Ends / renews' ?></span>
                <span class="pf-value"><?= ui_date($subscription['ends_at']) ?></span>
              </div>
            <?php endif; ?>
            <div class="plan-fact">
              <span class="pf-label"><?= $rtl ? 'الدورة' : 'Cycle' ?></span>
              <span class="pf-value"><?= $subscription ? ($subscription['billing_cycle'] === 'yearly' ? ($rtl ? 'سنوي' : 'Yearly') : ($rtl ? 'شهري' : 'Monthly')) : ($rtl ? 'شهري' : 'Monthly') ?></span>
            </div>
            <?php if ($plan['consultations_included'] !== null): ?>
              <div class="plan-fact">
                <span class="pf-label"><?= $rtl ? 'استشارات مشمولة' : 'Consultations' ?></span>
                <span class="pf-value"><span class="num-ltr"><?= (int)$plan['consultations_included'] ?></span> / <?= $rtl ? 'شهر' : 'mo' ?></span>
              </div>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <p class="muted-sm" style="margin:0">
            <?= $rtl ? 'لا توجد باقة مرتبطة بحسابك. الخدمات الأساسية متاحة، ويمكنك طلب تفعيل باقة للاستفادة من حدود أعلى وخدمات إضافية.' : 'No plan is linked to your account. Core services are available; request a plan to unlock higher limits and extra services.' ?>
          </p>
          <div class="ws-head-actions">
            <a class="btn btn-primary btn-sm" href="/contact.php?topic=plan"><?= $rtl ? 'اطلب تفعيل باقة' : 'Request a plan' ?></a>
            <a class="btn btn-outline btn-sm" href="/pricing.php"><?= $rtl ? 'مقارنة الباقات' : 'Compare plans' ?></a>
          </div>
        <?php endif; ?>
      </div>

      <div class="plan-card-side">
        <?php if (!$planUsage['has_plan']): ?>
          <div class="ws-alert is-info">
            <?= ui_icon('alert') ?>
            <div><?= $rtl ? 'لا تُطبَّق حدود شهرية على حسابك حالياً لعدم وجود باقة. تفعيل باقة يمنحك حدوداً واضحة وخدمات إضافية.' : 'No monthly limits are applied to your account because no plan is active. Activating a plan gives you clear limits and extra services.' ?></div>
          </div>
        <?php else: ?>
        <?php foreach (['ai_queries' => [$rtl ? 'استفسارات المساعد' : 'Assistant queries', 'spark'],
                        'document_analysis' => [$rtl ? 'تحليلات المستندات' : 'Document analyses', 'file']] as $gateKey => $meta):
          $g = $planUsage[$gateKey];
          $pct = ai_usage_percent($g);
          $fillClass = $pct === null ? '' : ($pct >= 100 ? 'is-full' : ($pct >= 80 ? 'is-warn' : ''));
        ?>
          <div class="meter">
            <div class="meter-head">
              <span class="meter-label"><?= ui_icon($meta[1]) ?> <?= ui_e($meta[0]) ?></span>
              <span class="meter-value">
                <?php if ($g['limit'] === null): ?>
                  <?= $rtl ? 'غير محدود' : 'Unlimited' ?>
                <?php else: ?>
                  <span class="num-ltr"><?= (int)$g['used'] ?></span> / <span class="num-ltr"><?= (int)$g['limit'] ?></span>
                <?php endif; ?>
              </span>
            </div>
            <?php if ($g['limit'] !== null): ?>
              <span class="meter-track"><span class="meter-fill <?= $fillClass ?>" style="width:<?= (int)$pct ?>%"></span></span>
              <div class="meter-note">
                <?php if ($g['remaining'] > 0): ?>
                  <?= $rtl ? 'المتبقي هذا الشهر:' : 'Remaining this month:' ?> <span class="num-ltr"><?= (int)$g['remaining'] ?></span>
                <?php else: ?>
                  <?= $rtl ? 'استنفدت الحد — تواصل معنا لترقية الباقة.' : 'Limit reached — contact us to upgrade.' ?>
                <?php endif; ?>
              </div>
            <?php else: ?>
              <div class="meter-note"><?= $rtl ? 'باقتك لا تفرض حداً شهرياً على هذه الخدمة.' : 'Your plan does not cap this service monthly.' ?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <!-- ===================== What can I use ===================== -->
  <section class="ws-section">
    <div class="ws-section-head">
      <div>
        <h2><?= $rtl ? 'ماذا أستطيع استخدامه؟' : 'What can I use?' ?></h2>
        <p><?= $rtl ? 'حالة كل خدمة على حسابك بلمحة — المتاح والمقفل بسبب الباقة.' : 'Every service on your account at a glance — available and plan-locked.' ?></p>
      </div>
    </div>
    <?= ws_render_feature_checklist($allCards) ?>
    <?php if ($blockedCards): ?>
      <div class="ws-alert is-warning">
        <?= ui_icon('lock') ?>
        <div>
          <?= $rtl
              ? 'بعض الخدمات غير متاحة في باقتك الحالية. يمكنك طلب ترقية وسنفعّلها على حسابك.'
              : 'Some services are not available on your current plan. You can request an upgrade and we will activate it on your account.' ?>
          <a href="/contact.php?topic=plan"><?= $rtl ? 'تواصل لترقية الباقة' : 'Contact to upgrade' ?></a>
        </div>
      </div>
    <?php endif; ?>
  </section>

  <!-- ======================== My services ======================== -->
  <section class="ws-section">
    <div class="ws-section-head">
      <div>
        <h2><?= $rtl ? 'خدماتي' : 'My services' ?></h2>
        <p><?= $rtl ? 'كل خدمة قانونية متاحة لك، مع وصف مختصر وزر مباشر.' : 'Every legal service available to you, with a short description and a direct action.' ?></p>
      </div>
    </div>
    <?= ws_render_service_groups($serviceGroups) ?>
  </section>

  <!-- ===================== AI command center ===================== -->
  <section class="ws-section">
    <div class="ws-ai">
      <div class="ws-ai-head">
        <div>
          <span class="ws-ai-badge"><?= ui_icon('spark') ?> <?= $rtl ? 'مركز الذكاء الاصطناعي' : 'AI command center' ?></span>
          <h2><?= $rtl ? 'أدوات الذكاء الاصطناعي لأعمالك القانونية' : 'AI tools for your legal work' ?></h2>
          <p><?= $rtl ? 'استخدم هذه الأدوات ضمن حدود باقتك. تظهر حالة كل أداة واستهلاكك الفعلي قبل الدخول إليها.' : 'Use these tools within your plan limits. Each tool shows its real state and usage before you enter it.' ?></p>
        </div>
        <a class="btn btn-outline btn-sm" href="/subscription.php"><?= $rtl ? 'إدارة الباقة' : 'Manage plan' ?></a>
      </div>

      <div class="ws-ai-meters">
        <?php foreach (['ai_queries' => [$rtl ? 'استفسارات المساعد' : 'Assistant queries'],
                        'document_analysis' => [$rtl ? 'تحليلات المستندات' : 'Document analyses']] as $gateKey => $meta):
          $g = $planUsage[$gateKey];
          $pct = ai_usage_percent($g);
          $fillClass = $pct === null ? '' : ($pct >= 100 ? 'is-full' : ($pct >= 80 ? 'is-warn' : ''));
        ?>
          <div class="meter">
            <div class="meter-head">
              <span class="meter-label"><?= ui_e($meta[0]) ?></span>
              <span class="meter-value">
                <?php if ($g['limit'] === null): ?>
                  <?= $rtl ? 'غير محدود' : 'Unlimited' ?>
                <?php else: ?>
                  <span class="num-ltr"><?= (int)$g['used'] ?></span> / <span class="num-ltr"><?= (int)$g['limit'] ?></span>
                <?php endif; ?>
              </span>
            </div>
            <?php if ($g['limit'] !== null): ?>
              <span class="meter-track"><span class="meter-fill <?= $fillClass ?>" style="width:<?= (int)$pct ?>%"></span></span>
              <div class="meter-note">
                <?= $g['remaining'] > 0
                    ? ($rtl ? 'المتبقي هذا الشهر: ' : 'Remaining this month: ') . '<span class="num-ltr">' . (int)$g['remaining'] . '</span>'
                    : ($rtl ? 'وصلت إلى الحد المسموح لهذه الباقة.' : 'You have reached this plan’s limit.') ?>
              </div>
            <?php else: ?>
              <div class="meter-note"><?= $rtl ? 'لا حد شهري على هذه الخدمة في باقتك.' : 'No monthly cap on this service in your plan.' ?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="service-grid">
        <?php foreach ($aiCards as $card): ?>
          <?= ws_render_service_card($card) ?>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <!-- ======================= Work overview ======================= -->
  <section class="ws-section">
    <div class="ws-section-head">
      <div>
        <h2><?= $rtl ? 'العمل القانوني' : 'Legal work' ?></h2>
        <p><?= $rtl ? 'ملخص سريع لقضاياك ومواعيدك ومهامك.' : 'A quick summary of your cases, appointments and tasks.' ?></p>
      </div>
    </div>

    <div class="kpi-grid">
      <a class="kpi kpi-teal" href="/consultations.php">
        <span class="kpi-ico"><?= ui_icon('calendar') ?></span>
        <span class="kpi-body">
          <span class="kpi-value"><?= $upcomingCount ?></span>
          <span class="kpi-label"><?= $rtl ? 'استشارات قادمة' : 'Upcoming consultations' ?></span>
          <span class="kpi-hint"><?= $rtl ? 'المواعيد المجدولة القادمة' : 'Scheduled ahead' ?></span>
        </span>
      </a>
      <a class="kpi kpi-navy" href="/my-cases.php">
        <span class="kpi-ico"><?= ui_icon('briefcase') ?></span>
        <span class="kpi-body">
          <span class="kpi-value"><?= $activeCases ?></span>
          <span class="kpi-label"><?= $rtl ? 'قضايا نشطة' : 'Active cases' ?></span>
          <span class="kpi-hint"><?= $rtl ? 'من إجمالي' : 'of' ?> <span class="num-ltr"><?= $totalCases ?></span></span>
        </span>
      </a>
      <a class="kpi <?= $pendingTasks ? 'kpi-gold' : 'kpi-teal' ?>" href="/case-tasks.php">
        <span class="kpi-ico"><?= ui_icon('check') ?></span>
        <span class="kpi-body">
          <span class="kpi-value"><?= $pendingTasks ?></span>
          <span class="kpi-label"><?= $rtl ? 'مهام بانتظارك' : 'Tasks awaiting you' ?></span>
          <span class="kpi-hint"><?= $pendingTasks ? ($rtl ? 'تحتاج إجراءً منك' : 'Need your action') : ($rtl ? 'لا شيء مطلوب' : 'Nothing required') ?></span>
        </span>
      </a>
      <a class="kpi <?= $outstanding > 0 ? 'kpi-danger' : 'kpi-success' ?>" href="/invoices.php">
        <span class="kpi-ico"><?= ui_icon('receipt') ?></span>
        <span class="kpi-body">
          <span class="kpi-value"><?= money($outstanding) ?></span>
          <span class="kpi-label"><?= $rtl ? 'مبالغ مستحقة' : 'Outstanding balance' ?></span>
          <span class="kpi-hint"><?= $outstanding > 0 ? ($rtl ? 'عرض الفواتير والسداد' : 'View and pay') : ($rtl ? 'لا مستحقات' : 'Nothing due') ?></span>
        </span>
      </a>
      <a class="kpi kpi-navy" href="/documents.php">
        <span class="kpi-ico"><?= ui_icon('file') ?></span>
        <span class="kpi-body">
          <span class="kpi-value"><?= $docCount ?></span>
          <span class="kpi-label"><?= $rtl ? 'مستندات مرفوعة' : 'Documents uploaded' ?></span>
          <span class="kpi-hint"><?= $rtl ? 'محلَّلة أو قيد التحليل' : 'Analyzed or in progress' ?></span>
        </span>
      </a>
      <a class="kpi <?= $draftsToReview ? 'kpi-gold' : 'kpi-navy' ?>" href="/draft-reviews.php">
        <span class="kpi-ico"><?= ui_icon('edit') ?></span>
        <span class="kpi-body">
          <span class="kpi-value"><?= $draftsToReview ?></span>
          <span class="kpi-label"><?= $rtl ? 'مسودات بانتظار مراجعتك' : 'Drafts awaiting review' ?></span>
          <span class="kpi-hint"><?= $draftsToReview ? ($rtl ? 'اعتمدها أو اطلب تعديلات' : 'Approve or request changes') : ($rtl ? 'لا مراجعات معلّقة' : 'No pending reviews') ?></span>
        </span>
      </a>
    </div>
  </section>

  <section class="ws-section">
    <div class="ws-section-head">
      <h3><?= $rtl ? 'إجراءات سريعة' : 'Quick actions' ?></h3>
    </div>
    <div class="quick-actions">
      <a class="quick-action is-primary" href="/consultations.php">
        <span class="qa-ico"><?= ui_icon('calendar') ?></span>
        <span><?= $rtl ? 'احجز استشارة' : 'Book a consultation' ?></span>
      </a>
      <a class="quick-action" href="/documents.php">
        <span class="qa-ico"><?= ui_icon('file') ?></span>
        <span><?= $rtl ? 'ارفع مستنداً' : 'Upload a document' ?></span>
      </a>
      <a class="quick-action" href="/my-cases.php">
        <span class="qa-ico"><?= ui_icon('briefcase') ?></span>
        <span><?= $rtl ? 'تابع قضاياي' : 'Track my cases' ?></span>
      </a>
      <a class="quick-action" href="/case-tasks.php">
        <span class="qa-ico"><?= ui_icon('check') ?></span>
        <span><?= $rtl ? 'مهامي' : 'My tasks' ?><?= $pendingTasks ? ' (' . (int)$pendingTasks . ')' : '' ?></span>
      </a>
      <?php
        // The AI shortcut mirrors the real gate: it opens the assistant only when the
        // plan allows it, and otherwise routes to the upgrade surface instead of a page
        // that would immediately refuse the request.
        $aiGate = $planUsage['ai_queries'];
        $aiQuickAllowed = $aiGate['limit'] === null || $aiGate['used'] < $aiGate['limit'];
      ?>
      <a class="quick-action<?= $aiQuickAllowed ? '' : ' is-locked' ?>" href="<?= $aiQuickAllowed ? '/ai-assistant.php' : '/contact.php?topic=plan' ?>">
        <span class="qa-ico"><?= ui_icon($aiQuickAllowed ? 'spark' : 'lock') ?></span>
        <span><?= $aiQuickAllowed ? ($rtl ? 'اسأل المساعد' : 'Ask the assistant') : ($rtl ? 'ترقية لاستخدام المساعد' : 'Upgrade for assistant') ?></span>
      </a>
      <?php if ($outstanding > 0): ?>
        <a class="quick-action<?= $overdueCount ? ' is-attention' : '' ?>" href="/invoices.php">
          <span class="qa-ico"><?= ui_icon('receipt') ?></span>
          <span><?= $rtl ? 'ادفع فاتورة' : 'Pay an invoice' ?></span>
        </a>
      <?php endif; ?>
      <a class="quick-action" href="/laws.php">
        <span class="qa-ico"><?= ui_icon('book') ?></span>
        <span><?= $rtl ? 'مكتبة الأنظمة' : 'Laws library' ?></span>
      </a>
    </div>
  </section>

  <div class="grid-2">
    <section class="ws-section">
      <div class="ws-section-head">
        <h3><?= $rtl ? 'استشاراتك القادمة' : 'Upcoming consultations' ?></h3>
        <a class="btn btn-outline btn-sm" href="/consultations.php"><?= $rtl ? 'الكل' : 'All' ?></a>
      </div>
      <?php if (!$upcoming): ?>
        <div class="ws-empty-inline"><?= $rtl ? 'لا توجد استشارات مجدولة. احجز موعدك القادم من صفحة الاستشارات.' : 'No consultations scheduled. Book your next appointment from the consultations page.' ?></div>
      <?php else: ?>
        <div class="list-rows">
          <?php foreach ($upcoming as $co): ?>
            <div class="list-row">
              <span class="list-row-ico"><?= ui_icon('calendar') ?></span>
              <span class="list-row-main">
                <span class="list-row-title"><?= ui_e($co['topic'] ?: ($rtl ? 'استشارة' : 'Consultation')) ?></span>
                <span class="list-row-meta">
                  <?= ui_e($co['lawyer_name']) ?> · <?= ui_date($co['scheduled_at'], true) ?> ·
                  <span class="num-ltr"><?= (int)$co['duration_minutes'] ?></span> <?= $rtl ? 'دقيقة' : 'min' ?>
                </span>
              </span>
              <span class="list-row-side">
                <?= ui_badge($co['status'], ui_status_label($co['status'])) ?>
                <a class="btn btn-primary btn-sm" href="/consultation-room.php?id=<?= (int)$co['id'] ?>"><?= $rtl ? 'دخول' : 'Join' ?></a>
              </span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <section class="ws-section">
      <div class="ws-section-head">
        <h3><?= $rtl ? 'مهام بانتظارك' : 'Tasks awaiting you' ?></h3>
        <a class="btn btn-outline btn-sm" href="/case-tasks.php"><?= $rtl ? 'الكل' : 'All' ?></a>
      </div>
      <?php if (!$tasks): ?>
        <div class="ws-empty-inline"><?= $rtl ? 'لا توجد مهام مشاركة معك حالياً.' : 'No tasks have been shared with you yet.' ?></div>
      <?php else: ?>
        <div class="list-rows">
          <?php foreach ($tasks as $t): ?>
            <div class="list-row">
              <span class="list-row-ico"><?= ui_icon('check') ?></span>
              <span class="list-row-main">
                <span class="list-row-title"><?= ui_e($t['title']) ?></span>
                <span class="list-row-meta">
                  <?= ui_e($t['case_title']) ?>
                  <?php if (!empty($t['due_date'])): ?> · <?= $rtl ? 'الاستحقاق' : 'Due' ?> <?= ui_date($t['due_date']) ?><?php endif; ?>
                </span>
              </span>
              <span class="list-row-side">
                <form method="post" action="/case-tasks.php">
                  <input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>">
                  <input type="hidden" name="task_id" value="<?= (int)$t['id'] ?>">
                  <button class="btn btn-primary btn-sm" type="submit"><?= $rtl ? 'إنجاز' : 'Complete' ?></button>
                </form>
              </span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </div>

  <div class="grid-2">
    <section class="ws-section">
      <div class="ws-section-head">
        <h3><?= $rtl ? 'قضاياك' : 'Your cases' ?></h3>
        <a class="btn btn-outline btn-sm" href="/my-cases.php"><?= $rtl ? 'كل القضايا' : 'All cases' ?></a>
      </div>
      <?php if (!$cases): ?>
        <div class="ws-empty-inline"><?= $rtl ? 'لا توجد قضايا بعد. عند فتح محاميك قضية باسمك ستظهر هنا.' : 'No cases yet. Once your lawyer opens a case for you it will appear here.' ?></div>
      <?php else: ?>
        <div class="list-rows">
          <?php foreach ($cases as $c): ?>
            <a class="list-row" href="/my-cases.php">
              <span class="list-row-ico"><?= ui_icon('briefcase') ?></span>
              <span class="list-row-main">
                <span class="list-row-title"><?= ui_e($c['title']) ?></span>
                <span class="list-row-meta">
                  <?= $rtl ? 'المحامي:' : 'Lawyer:' ?> <?= ui_e($c['lawyer_name']) ?>
                  <?php if (!empty($c['case_type'])): ?> · <?= ui_e($c['case_type']) ?><?php endif; ?>
                </span>
              </span>
              <span class="list-row-side"><?= ui_badge($c['status'], ui_status_label($c['status'])) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <section class="ws-section is-tertiary">
      <div class="ws-section-head">
        <h3><?= $rtl ? 'آخر الفواتير' : 'Recent invoices' ?></h3>
        <a class="btn btn-outline btn-sm" href="/invoices.php"><?= $rtl ? 'كل الفواتير' : 'All invoices' ?></a>
      </div>
      <?php if (!$recentInvoices): ?>
        <div class="ws-empty-inline"><?= $rtl ? 'لا توجد فواتير حالياً.' : 'No invoices yet.' ?></div>
      <?php else: ?>
        <div class="list-rows">
          <?php foreach ($recentInvoices as $inv):
            $displayStatus = finance_display_status($inv);
            $balance = finance_invoice_balance($inv);
          ?>
            <a class="list-row" href="/invoices.php">
              <span class="list-row-ico"><?= ui_icon('receipt') ?></span>
              <span class="list-row-main">
                <span class="list-row-title"><?= ui_e($inv['description']) ?></span>
                <span class="list-row-meta">
                  <?= money($inv['amount']) ?>
                  <?php if ($balance > 0 && $displayStatus !== 'cancelled'): ?> · <?= $rtl ? 'المتبقي' : 'Balance' ?> <?= money($balance) ?><?php endif; ?>
                  <?php if (!empty($inv['due_date'])): ?> · <?= $rtl ? 'الاستحقاق' : 'Due' ?> <?= ui_date($inv['due_date']) ?><?php endif; ?>
                </span>
              </span>
              <span class="list-row-side"><?= ui_badge($displayStatus, ui_status_label($displayStatus)) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </div>

  <!-- ======================= My lawyers ======================= -->
  <section class="ws-section">
      <div class="ws-section-head">
        <div>
          <h2><?= $rtl ? 'محاموني' : 'My lawyers' ?></h2>
          <p><?= $rtl ? 'المحامون الذين تتعامل معهم فعلياً، مع قضاياك النشطة واستشاراتك القادمة مع كل محامٍ.' : 'The lawyers you actually work with, with your active cases and upcoming consultations for each.' ?></p>
        </div>
        <div class="ws-head-actions">
          <a class="btn btn-outline btn-sm" href="/consultations.php"><?= $rtl ? 'حجز استشارة' : 'Book a consultation' ?></a>
        </div>
      </div>
      <?php if (!$panelLawyers): ?>
        <div class="ws-empty-inline"><?= $rtl ? 'لا يوجد محامٍ مرتبط بحسابك بعد. احجز استشارة لتبدأ.' : 'No lawyer is linked to your account yet. Book a consultation to begin.' ?></div>
      <?php else: ?>
        <div class="list-rows">
          <?php foreach ($panelLawyers as $lw): ?>
            <div class="list-row">
              <span class="list-row-ico"><?= ui_icon('scale') ?></span>
              <span class="list-row-main">
                <span class="list-row-title"><?= ui_e($lw['name']) ?></span>
                <span class="list-row-meta">
                  <?php if ($lw['specialization'] !== ''): ?><?= ui_e($lw['specialization']) ?> · <?php endif; ?>
                  <span class="num-ltr"><?= (int)$lw['active_cases'] ?></span> <?= $rtl ? 'قضية نشطة' : 'active cases' ?>
                  <?php if ($lw['upcoming'] > 0): ?>
                    · <span class="num-ltr"><?= (int)$lw['upcoming'] ?></span> <?= $rtl ? 'استشارة قادمة' : 'upcoming' ?>
                  <?php endif; ?>
                </span>
              </span>
              <span class="list-row-side">
                <a class="btn btn-primary btn-sm" href="/booking.php?lawyer_id=<?= (int)$lw['id'] ?>"><?= $rtl ? 'احجز' : 'Book' ?></a>
              </span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <div class="grid-2">
      <!-- ======================= My documents ======================= -->
      <section class="ws-section">
        <div class="ws-section-head">
          <h3><?= $rtl ? 'مستنداتي' : 'My documents' ?></h3>
          <a class="btn btn-outline btn-sm" href="/documents.php"><?= $rtl ? 'كل المستندات' : 'All documents' ?></a>
        </div>
        <?php if (!$panelDocuments): ?>
          <div class="ws-empty-inline"><?= $rtl ? 'لم ترفع أي مستند بعد. ارفع عقداً لتحليله بالذكاء الاصطناعي.' : 'You have not uploaded a document yet. Upload a contract to analyse it with AI.' ?></div>
        <?php else: ?>
          <div class="list-rows">
            <?php foreach ($panelDocuments as $doc):
              $st = dash_panel_document_state($doc['status']); ?>
              <a class="list-row" href="/documents.php">
                <span class="list-row-ico"><?= ui_icon('file') ?></span>
                <span class="list-row-main">
                  <span class="list-row-title"><?= ui_e($doc['name']) ?></span>
                  <span class="list-row-meta">
                    <?php if (!empty($doc['case_title'])): ?><?= ui_e($doc['case_title']) ?> · <?php elseif ($doc['case_id'] === null): ?><?= $rtl ? 'بدون قضية' : 'No case' ?> · <?php endif; ?>
                    <?= ui_date($doc['created_at']) ?>
                    <?php if ($doc['has_analysis']): ?> · <?= $rtl ? 'التحليل متاح' : 'Analysis ready' ?><?php endif; ?>
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

      <!-- ======================= Alerts / follow-up ======================= -->
      <section class="ws-section is-tertiary">
        <div class="ws-section-head">
          <h3><?= $rtl ? 'الإشعارات والمتابعة' : 'Alerts & follow-up' ?></h3>
          <?php if ($unreadAlerts > 0): ?>
            <span class="badge badge-danger"><?= $rtl ? 'غير مقروء' : 'Unread' ?> <span class="num-ltr"><?= (int)$unreadAlerts ?></span></span>
          <?php endif; ?>
        </div>
        <?php if (!$panelAlerts): ?>
          <div class="ws-empty-inline"><?= $rtl ? 'لا إشعارات بعد. ستظهر هنا تحديثات قضاياك وفواتيرك واستشاراتك.' : 'No notifications yet. Updates to your cases, invoices and consultations will appear here.' ?></div>
        <?php else: ?>
          <div class="list-rows">
            <?php foreach ($panelAlerts as $n): ?>
              <a class="list-row" href="<?= ui_e($n['link'] ?: '/dashboard.php') ?>">
                <span class="list-row-ico"><?= ui_icon('bell') ?></span>
                <span class="list-row-main">
                  <span class="list-row-title"><?= ui_e($n['title']) ?></span>
                  <span class="list-row-meta">
                    <?php if ($n['message']): ?><?= ui_e($n['message']) ?> · <?php endif; ?>
                    <?= ui_date($n['created_at']) ?>
                  </span>
                </span>
                <span class="list-row-side">
                  <?php if (!$n['is_read']): ?>
                    <span class="badge badge-info"><?= $rtl ? 'جديد' : 'New' ?></span>
                  <?php endif; ?>
                </span>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    </div>


</div>
</div>
<?php require_once __DIR__ . '/includes/app_footer.php'; ?>
