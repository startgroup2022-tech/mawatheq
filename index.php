<?php
/**
 * MAWATHEQ — Landing Page 2.0
 *
 * Presents the platform as a complete legal workspace (cases, documents, consultations,
 * firm management, billing) rather than an AI chatbot. Everything factual here is read from
 * the live database (plans, plan_features, testimonials) or from verified configuration
 * (jurisdiction_options()). No counters, prices or testimonials are hardcoded.
 */
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/jurisdiction.php';

$rtl = is_rtl();

/* ---- Real data -------------------------------------------------------- */
$plans = [];
$planFeats = [];
try {
    $plans = $pdo->query("SELECT * FROM plans WHERE status='active' ORDER BY sort_order")->fetchAll();
    $featStmt = $pdo->prepare("SELECT * FROM plan_features WHERE plan_id=? ORDER BY sort_order");
} catch (Throwable $e) { /* tables not present yet — the page still renders */ }

$testimonials = [];
try { $testimonials = $pdo->query("SELECT * FROM testimonials WHERE status='active' ORDER BY sort_order")->fetchAll(); } catch (Throwable $e) {}

$jurisdictions = jurisdiction_options();

/* ---- Feature cards (each maps to a verified module) -------------------- */
$features = [];
$featureIcons = ['✦','§','⚖','📄','🔍','⇄','✍','🏛','👥','🔑','💬','🗓','🔔','💳','📊','🛡'];
for ($i = 1; $i <= 16; $i++) {
    $features[] = ['icon' => $featureIcons[$i - 1], 't' => t("l2_f{$i}_t"), 'b' => t("l2_f{$i}_b")];
}

/* ---- Role solutions ---------------------------------------------------- */
$roles = [];
for ($i = 1; $i <= 5; $i++) {
    $roles[] = ['t' => t("l2_sol{$i}_t"), 'b' => t("l2_sol{$i}_b")];
}

/* ---- AI capabilities --------------------------------------------------- */
$aiPoints = [];
for ($i = 1; $i <= 6; $i++) { $aiPoints[] = t("l2_ai_p{$i}"); }

/* ---- Security points --------------------------------------------------- */
$secPoints = [];
for ($i = 1; $i <= 6; $i++) { $secPoints[] = t("l2_sec_b{$i}"); }

/* ---- Onboarding steps -------------------------------------------------- */
$steps = [];
for ($i = 1; $i <= 4; $i++) { $steps[] = ['t' => t("l2_how{$i}_t"), 'b' => t("l2_how{$i}_b")]; }

/* ---- Mobile capabilities ---------------------------------------------- */
$mobPoints = [t('l2_mob_b1'), t('l2_mob_b2'), t('l2_mob_b3'), t('l2_mob_b4')];
?>

<!-- ================= HERO ================= -->
<section class="l2-hero">
  <div class="container l2-hero-grid">
    <div class="l2-hero-copy">
      <span class="l2-eyebrow"><?= t('l2_hero_badge') ?></span>
      <h1><?= t('l2_hero_title') ?></h1>
      <p class="l2-lead"><?= t('l2_hero_sub') ?></p>
      <div class="l2-hero-actions">
        <a href="/register.php" class="btn btn-primary btn-lg"><?= t('l2_hero_cta1') ?></a>
        <a href="#features" class="btn btn-outline btn-lg"><?= t('l2_hero_cta2') ?></a>
      </div>
      <p class="l2-hero-note">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
        <?= t('l2_hero_note') ?>
      </p>

      <div class="l2-stats">
        <div class="l2-stat"><b><?= count($jurisdictions) ?></b><span><?= t('l2_stat_jur') ?></span></div>
        <div class="l2-stat"><b>5</b><span><?= t('l2_stat_roles') ?></span></div>
        <div class="l2-stat"><b><?= t('l2_stat_ai') ?></b><span><?= $rtl ? 'مكتبة الأنظمة' : 'Laws library' ?></span></div>
        <div class="l2-stat"><b><?= t('l2_stat_secure') ?></b><span><?= $rtl ? 'صلاحيات دقيقة' : 'Granular RBAC' ?></span></div>
      </div>
    </div>

    <!-- Product preview: the real workspace layout, no fake records -->
    <div class="l2-preview" role="img"
         aria-label="<?= $rtl ? 'معاينة لواجهة المنصة: القضايا والمستندات والمواعيد' : 'Preview of the platform interface: cases, documents and deadlines' ?>">
      <div class="l2-preview-bar">
        <span class="dot"></span><span class="dot"></span><span class="dot"></span>
        <span class="title"><?= $rtl ? 'مساحة العمل' : 'Workspace' ?></span>
      </div>
      <div class="l2-preview-body">
        <div class="l2-preview-hero">
          <b><?= t('l2_ov_title') ?></b>
          <span><?= $rtl ? 'نظرة سريعة على عملك القانوني اليوم' : 'A quick view of your legal work today' ?></span>
        </div>
        <div class="l2-preview-grid">
          <div class="l2-preview-tile"><i>⚖</i><span><?= t('l2_f3_t') ?></span></div>
          <div class="l2-preview-tile"><i>📄</i><span><?= t('l2_f4_t') ?></span></div>
          <div class="l2-preview-tile"><i>💬</i><span><?= t('l2_f11_t') ?></span></div>
          <div class="l2-preview-tile"><i>🗓</i><span><?= t('l2_f12_t') ?></span></div>
        </div>
        <div class="l2-preview-row">
          <div><div class="t"><?= t('l2_f1_t') ?></div><div class="s"><?= t('l2_ai_p3') ?></div></div>
          <span class="l2-ref">✦</span>
        </div>
        <div class="l2-preview-row">
          <div><div class="t"><?= t('l2_f14_t') ?></div><div class="s"><?= $rtl ? 'مدفوعات واشتراكات' : 'Payments & subscriptions' ?></div></div>
          <span class="l2-ref">✓</span>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ================= PLATFORM OVERVIEW ================= -->
<section id="platform" class="l2-section l2-section--alt">
  <div class="container">
    <div class="l2-head">
      <span class="l2-eyebrow"><?= t('l2_ov_eyebrow') ?></span>
      <h2><?= t('l2_ov_title') ?></h2>
      <p><?= t('l2_ov_body') ?></p>
    </div>
    <div class="l2-split">
      <ul class="l2-bullets">
        <li><?= $rtl ? 'مساحة واحدة للقضايا والمستندات والمواعيد والاستشارات.' : 'One workspace for cases, documents, deadlines and consultations.' ?></li>
        <li><?= $rtl ? 'أدوار وصلاحيات مضبوطة لكل عضو في الفريق.' : 'Roles and permissions configured for every team member.' ?></li>
        <li><?= $rtl ? 'بوابة مستقلة للعميل لمتابعة ملفه بنفسه.' : 'A separate client portal to follow their own file.' ?></li>
        <li><?= $rtl ? 'أنظمة قانونية مدعومة:' : 'Supported jurisdictions:' ?> <?= htmlspecialchars(implode('، ', $jurisdictions)) ?></li>
      </ul>
      <div class="l2-ai-card" style="background:var(--surface-2);border-color:var(--border)">
        <div class="who">
          <span class="av">م</span>
          <div><b style="color:var(--navy)"><?= t('l2_f1_t') ?></b><span style="color:var(--text-subtle)"><?= t('l2_ai_p2') ?></span></div>
        </div>
        <div class="l2-msg user" style="background:var(--info-bg);color:var(--text)"><?= t('l2_ai_demo_q1') ?></div>
        <div class="l2-msg bot" style="background:#fff;color:var(--text-muted);border:1px solid var(--border)"><?= t('l2_ai_demo_a1') ?></div>
        <span class="l2-ref" style="color:var(--teal);background:var(--info-bg);border-color:#CFEAF1"><?= t('l2_ai_demo_r1') ?></span>
      </div>
    </div>
  </div>
</section>

<!-- ================= FEATURES ================= -->
<section id="features" class="l2-section">
  <div class="container">
    <div class="l2-head">
      <h2><?= t('l2_feat_title') ?></h2>
      <p><?= t('l2_feat_sub') ?></p>
    </div>
    <div class="l2-features">
      <?php foreach ($features as $f): ?>
        <article class="l2-feature">
          <div class="ic" aria-hidden="true"><?= $f['icon'] ?></div>
          <h3><?= htmlspecialchars($f['t']) ?></h3>
          <p><?= htmlspecialchars($f['b']) ?></p>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ================= SOLUTIONS BY ROLE ================= -->
<section id="solutions" class="l2-section l2-section--alt">
  <div class="container">
    <div class="l2-head">
      <h2><?= t('l2_sol_title') ?></h2>
      <p><?= t('l2_sol_sub') ?></p>
    </div>
    <div class="l2-roles">
      <?php foreach ($roles as $r): ?>
        <article class="l2-role">
          <h3><?= htmlspecialchars($r['t']) ?></h3>
          <p><?= htmlspecialchars($r['b']) ?></p>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ================= LEGAL AI ================= -->
<section id="legal-ai" class="l2-section l2-section--dark">
  <div class="container">
    <div class="l2-split">
      <div>
        <span class="l2-eyebrow"><?= t('l2_ai_eyebrow') ?></span>
        <h2><?= t('l2_ai_title') ?></h2>
        <p class="l2-lead"><?= t('l2_ai_body') ?></p>
        <ul class="l2-bullets">
          <?php foreach ($aiPoints as $p): ?><li><?= htmlspecialchars($p) ?></li><?php endforeach; ?>
        </ul>
      </div>
      <div class="l2-ai-card">
        <span class="l2-demo-tag"><?= t('l2_demo_label') ?></span>
        <div class="who">
          <span class="av">م</span>
          <div><b>مواثيق</b><span><?= t('l2_ai_p2') ?></span></div>
        </div>
        <div class="l2-msg user"><?= t('l2_ai_demo_q1') ?></div>
        <div class="l2-msg bot">
          <?= t('l2_ai_demo_a1') ?>
          <div><span class="l2-ref">§ <?= t('l2_ai_demo_r1') ?></span></div>
        </div>
        <div class="l2-msg user"><?= t('l2_ai_demo_q2') ?></div>
        <div class="l2-msg bot">
          <?= t('l2_ai_demo_a2') ?>
          <div><span class="l2-ref">✦ <?= t('l2_ai_demo_r2') ?></span></div>
        </div>
        <p class="l2-ai-note"><?= t('l2_ai_note') ?></p>
      </div>
    </div>
  </div>
</section>

<!-- ================= CASE MANAGEMENT ================= -->
<section id="cases" class="l2-section">
  <div class="container">
    <div class="l2-split">
      <div>
        <span class="l2-eyebrow"><?= t('l2_case_eyebrow') ?></span>
        <h2><?= t('l2_case_title') ?></h2>
        <p class="l2-lead"><?= t('l2_case_body') ?></p>
        <ul class="l2-bullets">
          <li><?= t('l2_case_b1') ?></li><li><?= t('l2_case_b2') ?></li>
          <li><?= t('l2_case_b3') ?></li><li><?= t('l2_case_b4') ?></li>
        </ul>
      </div>
      <div class="l2-preview">
        <div class="l2-preview-bar"><span class="dot"></span><span class="dot"></span><span class="dot"></span><span class="title"><?= t('l2_f3_t') ?></span></div>
        <div class="l2-preview-body">
          <div class="l2-preview-row"><div><div class="t"><?= t('l2_case_b1') ?></div><div class="s"><?= $rtl ? 'مفتوحة · قيد المراجعة · مغلقة' : 'Open · In review · Closed' ?></div></div></div>
          <div class="l2-preview-row"><div><div class="t"><?= t('l2_case_b2') ?></div><div class="s"><?= $rtl ? 'جلسة · موعد حكومي · انتهاء عقد' : 'Hearing · Government · Contract expiry' ?></div></div></div>
          <div class="l2-preview-row"><div><div class="t"><?= t('l2_case_b3') ?></div><div class="s"><?= $rtl ? 'مهام ومخاطر مرتبطة بالملف' : 'Tasks and risks linked to the file' ?></div></div></div>
          <div class="l2-preview-row"><div><div class="t"><?= t('l2_case_b4') ?></div><div class="s"><?= $rtl ? 'حسب صلاحيات كل عضو' : 'Per member permissions' ?></div></div></div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ================= DOCUMENTS ================= -->
<section id="documents" class="l2-section l2-section--alt">
  <div class="container">
    <div class="l2-split l2-split--reverse">
      <div>
        <span class="l2-eyebrow"><?= t('l2_f4_t') ?></span>
        <h2><?= t('l2_doc_title') ?></h2>
        <p class="l2-lead"><?= t('l2_doc_body') ?></p>
        <ul class="l2-bullets">
          <li><?= t('l2_doc_b1') ?></li><li><?= t('l2_doc_b2') ?></li>
          <li><?= t('l2_doc_b3') ?></li><li><?= t('l2_doc_b4') ?></li>
        </ul>
      </div>
      <div class="l2-preview">
        <div class="l2-preview-bar"><span class="dot"></span><span class="dot"></span><span class="dot"></span><span class="title"><?= t('l2_f5_t') ?></span></div>
        <div class="l2-preview-body">
          <div class="l2-preview-hero" style="background:linear-gradient(135deg,#EAF6F9,#DCEFF8)">
            <b style="color:var(--navy)"><?= t('l2_f5_t') ?></b>
            <span style="color:var(--text-muted)"><?= $rtl ? 'ملخص · مخاطر · توصيات · كيانات' : 'Summary · Risks · Recommendations · Entities' ?></span>
          </div>
          <div class="l2-preview-row"><div><div class="t"><?= t('l2_doc_b1') ?></div><div class="s"><?= $rtl ? 'PDF · صور' : 'PDF · Images' ?></div></div></div>
          <div class="l2-preview-row"><div><div class="t"><?= t('l2_f6_t') ?></div><div class="s"><?= $rtl ? 'رصد الفروق بين عقدين' : 'Surface differences between two contracts' ?></div></div></div>
          <div class="l2-preview-row"><div><div class="t"><?= t('l2_doc_b3') ?></div><div class="s"><?= $rtl ? 'لا روابط ملفات مباشرة' : 'No direct file paths exposed' ?></div></div></div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ================= CONSULTATIONS ================= -->
<section id="consultations" class="l2-section">
  <div class="container">
    <div class="l2-split">
      <div>
        <span class="l2-eyebrow"><?= t('l2_f11_t') ?></span>
        <h2><?= t('l2_cons_title') ?></h2>
        <p class="l2-lead"><?= t('l2_cons_body') ?></p>
        <ul class="l2-bullets">
          <li><?= t('l2_cons_b1') ?></li><li><?= t('l2_cons_b2') ?></li>
          <li><?= t('l2_cons_b3') ?></li><li><?= t('l2_cons_b4') ?></li>
        </ul>
        <div class="l2-hero-actions" style="margin-top:22px">
          <a href="/consultations.php" class="btn btn-primary"><?= t('hero_cta_book') ?></a>
          <a href="/pricing.php" class="btn btn-outline"><?= t('nav_pricing') ?></a>
        </div>
      </div>
      <div class="l2-preview">
        <div class="l2-preview-bar"><span class="dot"></span><span class="dot"></span><span class="dot"></span><span class="title"><?= t('l2_f11_t') ?></span></div>
        <div class="l2-preview-body">
          <div class="l2-preview-row"><div><div class="t"><?= $rtl ? 'نص' : 'Text' ?></div><div class="s"><?= t('l2_cons_b4') ?></div></div><span class="l2-ref">💬</span></div>
          <div class="l2-preview-row"><div><div class="t"><?= $rtl ? 'صوت' : 'Voice' ?></div><div class="s"><?= t('l2_cons_b2') ?></div></div><span class="l2-ref">☎</span></div>
          <div class="l2-preview-row"><div><div class="t"><?= $rtl ? 'فيديو' : 'Video' ?></div><div class="s"><?= t('l2_cons_b1') ?></div></div><span class="l2-ref">🎥</span></div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ================= SECURITY ================= -->
<section id="security" class="l2-section l2-section--alt">
  <div class="container">
    <div class="l2-head">
      <h2><?= t('l2_sec_title') ?></h2>
      <p><?= t('l2_sec_body') ?></p>
    </div>
    <div class="l2-sec-grid">
      <?php foreach ($secPoints as $p): ?>
        <div class="l2-sec-item">
          <span class="ic" aria-hidden="true">🛡</span>
          <div><b><?= htmlspecialchars($p) ?></b></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ================= HOW IT WORKS ================= -->
<section id="how" class="l2-section">
  <div class="container">
    <div class="l2-head"><h2><?= t('l2_how_title') ?></h2></div>
    <div class="l2-steps">
      <?php foreach ($steps as $i => $s): ?>
        <div class="l2-step">
          <div class="n"><?= $i + 1 ?></div>
          <h3><?= htmlspecialchars($s['t']) ?></h3>
          <p><?= htmlspecialchars($s['b']) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ================= MOBILE APP ================= -->
<section id="mobile" class="l2-section l2-section--dark">
  <div class="container">
    <div class="l2-split">
      <div>
        <span class="l2-eyebrow"><?= t('l2_mob_eyebrow') ?></span>
        <h2><?= t('l2_mob_title') ?></h2>
        <p class="l2-lead"><?= t('l2_mob_body') ?></p>
        <ul class="l2-bullets">
          <?php foreach ($mobPoints as $p): ?><li><?= htmlspecialchars($p) ?></li><?php endforeach; ?>
        </ul>
        <div class="l2-platform-badges">
          <span class="l2-platform-badge">🤖 <?= t('l2_mob_android') ?></span>
          <span class="l2-platform-badge">🍎 <?= t('l2_mob_ios') ?></span>
        </div>
        <p class="l2-mob-status"><?= t('l2_mob_status') ?></p>
      </div>

      <!-- Mobile preview: mirrors the Flutter home composition (real widgets live in apps/mobile) -->
      <div class="l2-phone" role="img"
           aria-label="<?= $rtl ? 'معاينة الصفحة الرئيسية لتطبيق الجوال' : 'Preview of the mobile app home screen' ?>">
        <div class="l2-phone-screen">
          <div class="l2-phone-notch"><i></i></div>
          <div class="l2-phone-inner">
            <div class="l2-phone-head">
              <div class="b"><img src="/assets/img/logo.webp" alt=""><b><?= $rtl ? SITE_NAME_AR : SITE_NAME_EN ?></b></div>
              <div class="ic">🔔</div>
            </div>
            <div class="l2-phone-hero">
              <b><?= $rtl ? 'منصتك القانونية المتكاملة' : 'Your complete legal platform' ?></b>
              <span><?= $rtl ? 'قضايا · مستندات · استشارات · ذكاء اصطناعي' : 'Cases · Documents · Consultations · AI' ?></span>
              <span class="cta"><?= t('l2_hero_cta1') ?></span>
            </div>
            <div class="l2-phone-grid">
              <div class="l2-phone-tile"><i>🔍</i><span><?= t('l2_f2_t') ?></span></div>
              <div class="l2-phone-tile"><i>⚖</i><span><?= t('l2_f3_t') ?></span></div>
              <div class="l2-phone-tile"><i>📄</i><span><?= t('l2_f4_t') ?></span></div>
              <div class="l2-phone-tile"><i>💬</i><span><?= t('l2_f11_t') ?></span></div>
            </div>
          </div>
          <div class="l2-phone-nav">
            <div class="on"><i>⌂</i><?= $rtl ? 'الرئيسية' : 'Home' ?></div>
            <div><i>✦</i><?= $rtl ? 'المساعد' : 'Assistant' ?></div>
            <div><i>⚖</i><?= $rtl ? 'القضايا' : 'Cases' ?></div>
            <div><i>📄</i><?= $rtl ? 'المستندات' : 'Documents' ?></div>
            <div><i>👤</i><?= $rtl ? 'حسابي' : 'Account' ?></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ================= PRICING (real plans from the database) ================= -->
<section id="pricing" class="l2-section">
  <div class="container">
    <div class="l2-head"><h2><?= t('pricing_title') ?></h2></div>
    <div class="pricing-grid">
      <?php foreach ($plans as $plan):
        $name = $rtl ? $plan['name_ar'] : $plan['name_en'];
        $feats = [];
        if (isset($featStmt)) { $featStmt->execute([$plan['id']]); $feats = $featStmt->fetchAll(); }
      ?>
      <div class="price-card <?= $plan['is_featured'] ? 'featured' : '' ?>">
        <?php if ($plan['is_featured']): ?><span class="pop-tag"><?= t('pricing_popular') ?></span><?php endif; ?>
        <h3><?= htmlspecialchars($name) ?></h3>
        <div class="price-amount">
          <?= $plan['price_monthly'] > 0 ? money($plan['price_monthly']) : t('l2_price_free') ?>
          <?php if ($plan['price_monthly'] > 0): ?><span><?= t('l2_price_month') ?></span><?php endif; ?>
        </div>
        <ul class="price-feats">
          <?php foreach ($feats as $f): ?>
            <li><?= htmlspecialchars($rtl ? $f['feature_ar'] : $f['feature_en']) ?></li>
          <?php endforeach; ?>
        </ul>
        <a href="/register.php?plan=<?= (int)$plan['id'] ?>" class="btn <?= $plan['is_featured'] ? 'btn-primary' : 'btn-outline' ?> btn-block"><?= t('pricing_choose') ?></a>
      </div>
      <?php endforeach; ?>
    </div>
    <p class="l2-price-note"><?= t('l2_price_note') ?></p>
  </div>
</section>

<?php if ($testimonials): ?>
<!-- ================= TESTIMONIALS (real rows only) ================= -->
<section class="l2-section l2-section--alt">
  <div class="container">
    <div class="l2-head"><h2><?= t('testi_title') ?></h2></div>
    <div class="testi-grid">
      <?php foreach ($testimonials as $tst):
        $name = $rtl ? $tst['name_ar'] : $tst['name_en'];
        $role = $rtl ? $tst['role_ar'] : $tst['role_en'];
        $quote = $rtl ? $tst['quote_ar'] : $tst['quote_en'];
      ?>
      <div class="testi-card">
        <span class="quote-mark">”</span>
        <p class="q"><?= htmlspecialchars($quote) ?></p>
        <div class="testi-who">
          <div class="testi-avatar"><?= mb_substr($name, 0, 1) ?></div>
          <div><div class="testi-name"><?= htmlspecialchars($name) ?></div><div class="testi-role"><?= htmlspecialchars($role) ?></div></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ================= FAQ ================= -->
<section id="faq" class="l2-section">
  <div class="container">
    <div class="l2-head"><h2><?= t('faq_title') ?></h2></div>
    <div class="faq-list l2-faq">
      <details class="faq-item" open><summary><?= t('faq1_q') ?></summary><p class="faq-a"><?= t('faq1_a') ?></p></details>
      <details class="faq-item"><summary><?= t('faq2_q') ?></summary><p class="faq-a"><?= t('faq2_a') ?></p></details>
      <details class="faq-item"><summary><?= t('faq3_q') ?></summary><p class="faq-a"><?= t('faq3_a') ?></p></details>
      <details class="faq-item"><summary><?= t('faq4_q') ?></summary><p class="faq-a"><?= t('faq4_a') ?></p></details>
    </div>
  </div>
</section>

<!-- ================= FINAL CTA ================= -->
<section class="final-cta l2-section--dark">
  <div class="container">
    <h2><?= t('l2_final_title') ?></h2>
    <p><?= t('l2_final_body') ?></p>
    <div class="l2-hero-actions" style="justify-content:center">
      <a href="/register.php" class="btn btn-gold btn-lg"><?= t('final_cta') ?></a>
      <a href="/contact.php" class="btn btn-outline-light btn-lg"><?= t('nav_contact') ?></a>
    </div>
  </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
