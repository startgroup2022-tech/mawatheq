<?php
/**
 * Plan & usage (client).
 *
 * Shows the client their current plan, live usage against every limit that is
 * actually enforced (assistant queries and document analyses, plus consultations
 * included), and the full plan comparison from the database. Plan changes are
 * arranged with the team — there is deliberately no self-service switch, because
 * entitlement is granted by an administrator, never by a request parameter.
 */
$activePage = 'subscription';
require_once __DIR__ . '/includes/app_header.php';
require_once __DIR__ . '/includes/ai_services.php';

$userId = (int)$_SESSION['user_id'];
$rtl = is_rtl();

$usage = ai_plan_usage($pdo, $userId);
$plan = $usage['plan'];

// Latest subscription row, if the account has one.
$subStmt = $pdo->prepare('SELECT * FROM subscriptions WHERE user_id=? ORDER BY id DESC LIMIT 1');
$subStmt->execute([$userId]);
$subscription = $subStmt->fetch() ?: null;

// Every active plan with its feature list, for the comparison grid.
$plans = $pdo->query("SELECT * FROM plans WHERE status='active' ORDER BY sort_order, price_monthly")->fetchAll();
$featStmt = $pdo->prepare('SELECT * FROM plan_features WHERE plan_id=? ORDER BY sort_order, id');

$cycleLabels = ['monthly' => [$rtl ? 'شهري' : 'Monthly'], 'yearly' => [$rtl ? 'سنوي' : 'Yearly']];
$statusLabels = [
    'active' => [$rtl ? 'نشط' : 'Active'], 'pending' => [$rtl ? 'قيد الانتظار' : 'Pending'],
    'expired' => [$rtl ? 'منتهٍ' : 'Expired'], 'cancelled' => [$rtl ? 'ملغى' : 'Cancelled'],
];
?>
<div class="app-content">

  <?= ui_page_head(
      $rtl ? 'الباقة والاستخدام' : 'Plan & usage',
      $rtl ? 'تعرّف على باقتك الحالية، واستهلاكك لهذا الشهر، وقارن بين الباقات المتاحة.' : 'See your current plan, this month’s usage, and compare the available plans.'
  ) ?>

  <div class="grid-2">
    <div class="dash-card">
      <div class="card-head">
        <h3 style="margin:0"><?= $rtl ? 'باقتك الحالية' : 'Your current plan' ?></h3>
        <?php if ($plan): ?><span class="badge badge-neutral"><?= $rtl ? 'مفعّلة' : 'Active' ?></span><?php endif; ?>
      </div>

      <?php if ($plan): ?>
        <div class="row-between" style="align-items:flex-start">
          <div>
            <div style="font-family:var(--serif);font-weight:800;font-size:1.35rem;color:var(--navy-dark)">
              <?= ui_e($rtl ? $plan['name_ar'] : $plan['name_en']) ?>
            </div>
            <p class="muted-sm" style="margin:6px 0 0">
              <?= ui_e($rtl ? ($plan['description_ar'] ?? '') : ($plan['description_en'] ?? '')) ?>
            </p>
          </div>
          <div style="text-align:end">
            <div style="font-weight:800;font-size:1.15rem;color:var(--navy)">
              <?= (float)$plan['price_monthly'] > 0 ? money($plan['price_monthly']) : ($rtl ? 'مجاناً' : 'Free') ?>
            </div>
            <?php if ((float)$plan['price_monthly'] > 0): ?>
              <div class="muted-sm"><?= $rtl ? 'شهرياً' : 'per month' ?></div>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($subscription): ?>
          <hr class="divider">
          <div class="list-row-meta" style="gap:16px">
            <span><?= $rtl ? 'الدورة:' : 'Billing cycle:' ?> <strong><?= ui_e(($cycleLabels[$subscription['billing_cycle']] ?? [$subscription['billing_cycle']])[0]) ?></strong></span>
            <span><?= $rtl ? 'الحالة:' : 'Status:' ?> <strong><?= ui_e(($statusLabels[$subscription['status']] ?? [$subscription['status']])[0]) ?></strong></span>
            <?php if (!empty($subscription['ends_at'])): ?>
              <span><?= $rtl ? 'التجديد:' : 'Renews:' ?> <?= ui_date($subscription['ends_at']) ?></span>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      <?php else: ?>
        <p class="muted-sm"><?= $rtl ? 'لا توجد باقة مفعّلة على حسابك حالياً. اختر باقة من الأسفل أو تواصل معنا لتفعيلها.' : 'No plan is active on your account yet. Choose a plan below or contact us to activate one.' ?></p>
      <?php endif; ?>

      <div class="inline-actions" style="margin-top:16px">
        <a class="btn btn-outline btn-sm" href="/pricing.php"><?= $rtl ? 'مقارنة الباقات' : 'Compare plans' ?></a>
        <a class="btn btn-primary btn-sm" href="/contact.php"><?= $rtl ? 'تواصل لتفعيل باقة' : 'Contact us to activate' ?></a>
      </div>
    </div>

    <div class="dash-card">
      <div class="card-head">
        <h3 style="margin:0"><?= $rtl ? 'استهلاكك هذا الشهر' : 'This month’s usage' ?></h3>
      </div>

      <?php foreach (['ai_queries', 'document_analysis'] as $gateKey):
        $g = $usage[$gateKey];
        $pct = ai_usage_percent($g);
        $label = $gateKey === 'ai_queries'
            ? ($rtl ? 'استفسارات المساعد القانوني' : 'Legal assistant queries')
            : ($rtl ? 'تحليلات المستندات' : 'Document analyses');
        $fillClass = $pct === null ? '' : ($pct >= 100 ? 'is-full' : ($pct >= 80 ? 'is-warn' : ''));
      ?>
        <div class="meter">
          <div class="meter-head">
            <span class="meter-label"><?= ui_e($label) ?></span>
            <span class="meter-value">
              <?php if ($g['limit'] === null): ?>
                <?= $rtl ? 'غير محدود' : 'Unlimited' ?>
              <?php else: ?>
                <span class="num-ltr"><?= (int)$g['used'] ?></span> / <span class="num-ltr"><?= (int)$g['limit'] ?></span>
              <?php endif; ?>
            </span>
          </div>
          <?php if ($g['limit'] !== null): ?>
            <div class="meter-track"><div class="meter-fill <?= $fillClass ?>" style="width:<?= (int)$pct ?>%"></div></div>
            <div class="meter-note">
              <?php if ($g['remaining'] > 0): ?>
                <?= $rtl ? 'المتبقي:' : 'Remaining:' ?> <span class="num-ltr"><?= (int)$g['remaining'] ?></span>
              <?php else: ?>
                <?= $rtl ? 'استنفدت الحد — لن تتمكن من استخدام هذه الخدمة حتى ترقية الباقة أو بداية الشهر القادم.' : 'Limit reached — this service is unavailable until you upgrade or next month begins.' ?>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <div class="meter-note"><?= $rtl ? 'لا يوجد حد شهري على هذه الخدمة في باقتك.' : 'No monthly cap on this service in your plan.' ?></div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>

      <?php if ($plan && $plan['consultations_included'] !== null): ?>
        <hr class="divider">
        <div class="row-between">
          <span class="meter-label"><?= $rtl ? 'استشارات مشمولة شهرياً' : 'Consultations included monthly' ?></span>
          <span class="badge badge-neutral"><span class="num-ltr"><?= (int)$plan['consultations_included'] ?></span></span>
        </div>
      <?php endif; ?>

      <p class="meter-note" style="margin-top:16px">
        <?= $rtl ? 'تُحتسب الحدود لكل شهر ميلادي، وتُطبَّق على مستوى الخادم.' : 'Limits reset each calendar month and are enforced on the server.' ?>
      </p>
    </div>
  </div>

  <div class="dash-section" style="margin-top:24px">
    <div class="dash-section-head">
      <div>
        <h2><?= $rtl ? 'مقارنة الباقات' : 'Compare plans' ?></h2>
        <p class="muted-sm"><?= $rtl ? 'لتغيير باقتك، تواصل مع الفريق وسنفعّل الترقية على حسابك.' : 'To change your plan, contact the team and we will activate it on your account.' ?></p>
      </div>
    </div>

    <div class="service-grid">
      <?php foreach ($plans as $p):
        $isCurrent = $plan && (int)$plan['id'] === (int)$p['id'];
        $featStmt->execute([$p['id']]);
        $features = $featStmt->fetchAll();
        $pName = $rtl ? $p['name_ar'] : $p['name_en'];
      ?>
        <div class="service-card" style="<?= $isCurrent ? 'border-color:var(--teal);box-shadow:var(--sh-sm)' : '' ?>">
          <span class="sc-top">
            <span class="sc-ico"><?= ui_icon($p['is_corporate'] ? 'landmark' : 'card') ?></span>
            <span class="sc-title"><?= ui_e($pName) ?></span>
            <?php if ($isCurrent): ?><span class="badge badge-neutral" style="margin-inline-start:auto"><?= $rtl ? 'باقتك' : 'Current' ?></span><?php endif; ?>
          </span>

          <div style="font-family:var(--serif);font-weight:800;font-size:1.2rem;color:var(--navy-dark)">
            <?= (float)$p['price_monthly'] > 0 ? money($p['price_monthly']) : ($rtl ? 'مجاناً' : 'Free') ?>
            <?php if ((float)$p['price_monthly'] > 0): ?><span class="muted-sm" style="font-weight:600">/ <?= $rtl ? 'شهر' : 'mo' ?></span><?php endif; ?>
          </div>

          <?php if ($features): ?>
            <ul style="margin:8px 0 0;padding-inline-start:18px;color:var(--text-muted);font-size:var(--fs-xs);line-height:1.9">
              <?php foreach ($features as $f): ?>
                <li><?= ui_e($rtl ? $f['feature_ar'] : $f['feature_en']) ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>

          <span class="sc-foot" style="margin-top:auto">
            <span class="muted-sm">
              <?= $rtl ? 'حدود:' : 'Limits:' ?>
              <?= $p['ai_queries_limit'] === null ? ($rtl ? 'أسئلة غير محدودة' : 'unlimited queries') : ((int)$p['ai_queries_limit'] . ' ' . ($rtl ? 'سؤال' : 'queries')) ?>
              ·
              <?= $p['document_analysis_limit'] === null ? ($rtl ? 'تحليل غير محدود' : 'unlimited analyses') : ((int)$p['document_analysis_limit'] . ' ' . ($rtl ? 'تحليل' : 'analyses')) ?>
            </span>
          </span>

          <?php if (!$isCurrent): ?>
            <a class="btn btn-primary btn-sm btn-block" href="/contact.php?topic=plan&plan=<?= (int)$p['id'] ?>">
              <?= $rtl ? 'اطلب تفعيل هذه الباقة' : 'Request this plan' ?>
            </a>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

</div>
<?php require_once __DIR__ . '/includes/app_footer.php'; ?>
