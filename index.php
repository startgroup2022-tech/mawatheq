<?php require_once __DIR__ . '/includes/header.php'; ?>

<!-- ================= HERO ================= -->
<section class="hero">
  <div class="container hero-grid">
    <div class="hero-copy">
      <span class="badge-live"><?= t('nav_ready_badge') ?></span>
      <h1><?= t('hero_title') ?></h1>
      <p class="lead"><?= t('hero_subtitle') ?></p>
      <div class="hero-actions">
        <a href="/ai-assistant.php" class="btn btn-primary"><?= t('hero_cta_ai') ?> ↩</a>
        <a href="/consultations.php" class="btn btn-outline"><?= t('hero_cta_book') ?></a>
      </div>
    </div>
    <div class="chat-mock">
      <span class="badge-live"><?= t('hero_live_badge') ?></span>
      <div style="height:16px"></div>
      <div class="chat-row user"><div class="chat-bubble"><?= is_rtl() ? 'ما شروط إنهاء عقد العمل في السعودية؟' : 'What are the conditions for ending an employment contract in Saudi Arabia?' ?></div></div>
      <div class="chat-row bot"><div class="chat-bubble"><?= is_rtl()
        ? 'تنص المادة ٨٠ من نظام العمل على حالات الإنهاء المقبولة. يجب على صاحب العمل تقديم إشعار خطي وتسوية نهائية ما لم يثبت سوء سلوك جسيم.'
        : 'Article 80 of the Labor Law outlines the accepted termination cases. The employer must provide written notice and final settlement unless serious misconduct is proven.' ?></div></div>
      <div class="chat-row user"><div class="chat-bubble"><?= is_rtl() ? 'لخّص عقد التوريد هذا واذكر بنود المخاطر.' : 'Summarize this supply contract and list the risk clauses.' ?></div></div>
      <div class="chat-row bot"><div class="chat-bubble"><?= is_rtl()
        ? 'يسمح البند الخامس بتغيير الأسعار من طرف واحد. يُنصح بإضافة مهلة ٣٠ يوماً وموافقة متبادلة لأي تعديل مستقبلي.'
        : 'Clause 5 allows unilateral price changes. We recommend adding a 30-day notice and mutual agreement for future changes.' ?></div></div>
    </div>
  </div>
</section>

<!-- ================= ABOUT ================= -->
<section id="about" class="section section-alt">
  <div class="container">
    <div class="split">
      <div>
        <span class="section-head eyebrow" style="display:block;text-align:start;margin:0 0 12px"><?= t('about_eyebrow') ?></span>
        <h2><?= t('about_title') ?></h2>
        <p><?= t('about_body') ?></p>
      </div>
      <div class="mini-card-grid" style="grid-template-columns:1fr 1fr;display:grid;">
        <div class="mini-card"><h3>+100K</h3><p><?= is_rtl() ? 'حكم قضائي مفهرس' : 'Indexed rulings' ?></p></div>
        <div class="mini-card"><h3>+18K</h3><p><?= is_rtl() ? 'مادة نظامية' : 'Regulatory articles' ?></p></div>
        <div class="mini-card"><h3>24/7</h3><p><?= is_rtl() ? 'مساعد قانوني متاح' : 'AI assistant availability' ?></p></div>
        <div class="mini-card"><h3><?= is_rtl() ? 'السعودية' : 'KSA' ?></h3><p><?= is_rtl() ? 'بيانات مخزّنة محلياً' : 'Data stored locally' ?></p></div>
      </div>
    </div>
  </div>
</section>

<!-- ================= FEATURES ================= -->
<section id="features" class="section">
  <div class="container">
    <div class="section-head">
      <h2><?= t('features_title') ?></h2>
    </div>
    <div class="features-grid">
      <div class="feature-card"><div class="f-icon">⚖️</div><h3><?= t('feat1_title') ?></h3><p><?= t('feat1_body') ?></p></div>
      <div class="feature-card"><div class="f-icon">📄</div><h3><?= t('feat2_title') ?></h3><p><?= t('feat2_body') ?></p></div>
      <div class="feature-card"><div class="f-icon">📁</div><h3><?= t('feat3_title') ?></h3><p><?= t('feat3_body') ?></p></div>
      <div class="feature-card"><div class="f-icon">🗓️</div><h3><?= t('feat4_title') ?></h3><p><?= t('feat4_body') ?></p></div>
      <div class="feature-card"><div class="f-icon">🏢</div><h3><?= t('feat5_title') ?></h3><p><?= t('feat5_body') ?></p></div>
      <div class="feature-card"><div class="f-icon">📚</div><h3><?= t('feat6_title') ?></h3><p><?= t('feat6_body') ?></p></div>
      <div class="feature-card"><div class="f-icon">💬</div><h3><?= t('feat7_title') ?></h3><p><?= t('feat7_body') ?></p></div>
      <div class="feature-card"><div class="f-icon">✍️</div><h3><?= t('feat8_title') ?></h3><p><?= t('feat8_body') ?></p></div>
    </div>
  </div>
</section>

<!-- ================= HOW IT WORKS ================= -->
<section id="how" class="section section-alt">
  <div class="container">
    <div class="section-head"><h2><?= t('how_title') ?></h2></div>
    <div class="steps">
      <div class="step"><div class="num">1</div><h3><?= t('how1_title') ?></h3><p><?= t('how1_body') ?></p></div>
      <div class="step"><div class="num">2</div><h3><?= t('how2_title') ?></h3><p><?= t('how2_body') ?></p></div>
      <div class="step"><div class="num">3</div><h3><?= t('how3_title') ?></h3><p><?= t('how3_body') ?></p></div>
      <div class="step"><div class="num">4</div><h3><?= t('how4_title') ?></h3><p><?= t('how4_body') ?></p></div>
    </div>
  </div>
</section>

<!-- ================= LAWYERS ================= -->
<section class="section">
  <div class="container">
    <div class="split">
      <div>
        <h2><?= t('lawyers_title') ?></h2>
        <p><?= t('lawyers_body') ?></p>
        <ul class="check-list">
          <li><?= t('lawyers_p1') ?></li>
          <li><?= t('lawyers_p2') ?></li>
          <li><?= t('lawyers_p3') ?></li>
          <li><?= t('lawyers_p4') ?></li>
        </ul>
        <a href="/join-lawyer.php" class="btn btn-primary"><?= t('lawyers_cta') ?></a>
      </div>
      <div class="chat-mock" style="padding:32px">
        <h3 style="margin-bottom:18px"><?= is_rtl() ? 'لوحة أداء المحامي' : "Lawyer's dashboard" ?></h3>
        <div class="mini-card" style="margin-bottom:12px"><h3><?= is_rtl() ? '٤٢ قضية نشطة' : '42 active cases' ?></h3><p><?= is_rtl() ? 'موزعة على ٦ عملاء مؤسسيين' : 'Across 6 corporate clients' ?></p></div>
        <div class="mini-card"><h3><?= is_rtl() ? '٪٨٧ نسبة رضا العملاء' : '87% client satisfaction' ?></h3><p><?= is_rtl() ? 'بناءً على آخر ٣٠ استشارة' : 'Based on the last 30 consultations' ?></p></div>
      </div>
    </div>
  </div>
</section>

<!-- ================= CORPORATE ================= -->
<section class="section section-dark">
  <div class="container">
    <div class="split">
      <div class="mini-card-grid">
        <div class="mini-card" style="background:rgba(255,255,255,.04);border-color:rgba(255,255,255,.12)">
          <h3 style="color:#fff"><?= t('corp1_title') ?></h3><p style="color:#9FB1C1"><?= t('corp1_body') ?></p>
        </div>
        <div class="mini-card" style="background:rgba(255,255,255,.04);border-color:rgba(255,255,255,.12)">
          <h3 style="color:#fff"><?= t('corp2_title') ?></h3><p style="color:#9FB1C1"><?= t('corp2_body') ?></p>
        </div>
        <div class="mini-card" style="background:rgba(255,255,255,.04);border-color:rgba(255,255,255,.12)">
          <h3 style="color:#fff"><?= t('corp3_title') ?></h3><p style="color:#9FB1C1"><?= t('corp3_body') ?></p>
        </div>
      </div>
      <div>
        <h2><?= t('corp_title') ?></h2>
        <p><?= t('corp_body') ?></p>
        <a href="/pricing.php#corporate" class="btn btn-gold"><?= t('corp_cta') ?></a>
      </div>
    </div>
  </div>
</section>

<!-- ================= TESTIMONIALS ================= -->
<section class="section">
  <div class="container">
    <div class="section-head"><h2><?= t('testi_title') ?></h2></div>
    <div class="testi-grid">
      <?php
      $testimonials = [];
      try { $testimonials = $pdo->query("SELECT * FROM testimonials WHERE status='active' ORDER BY sort_order")->fetchAll(); } catch (Throwable $e) {}
      foreach ($testimonials as $tst):
        $name = is_rtl() ? $tst['name_ar'] : $tst['name_en'];
        $role = is_rtl() ? $tst['role_ar'] : $tst['role_en'];
        $quote = is_rtl() ? $tst['quote_ar'] : $tst['quote_en'];
      ?>
      <div class="testi-card">
        <span class="quote-mark">”</span>
        <p class="q"><?= htmlspecialchars($quote) ?></p>
        <div class="testi-who">
          <div class="testi-avatar"><?= mb_substr($name,0,1) ?></div>
          <div><div class="testi-name"><?= htmlspecialchars($name) ?></div><div class="testi-role"><?= htmlspecialchars($role) ?></div></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ================= PRICING ================= -->
<section id="pricing" class="section section-alt">
  <div class="container">
    <div class="section-head"><h2><?= t('pricing_title') ?></h2></div>
    <div class="pricing-grid">
      <?php
      $plans = [];
      try {
        $plans = $pdo->query("SELECT * FROM plans WHERE status='active' ORDER BY sort_order")->fetchAll();
        $featsStmt = $pdo->prepare("SELECT * FROM plan_features WHERE plan_id=? ORDER BY sort_order");
      } catch (Throwable $e) {}
      foreach ($plans as $plan):
        $name = is_rtl() ? $plan['name_ar'] : $plan['name_en'];
        $feats = [];
        if (isset($featsStmt)) { $featsStmt->execute([$plan['id']]); $feats = $featsStmt->fetchAll(); }
      ?>
      <div class="price-card <?= $plan['is_featured'] ? 'featured' : '' ?>">
        <?php if ($plan['is_featured']): ?><span class="pop-tag"><?= t('pricing_popular') ?></span><?php endif; ?>
        <h3><?= htmlspecialchars($name) ?></h3>
        <div class="price-amount">
          <?= $plan['price_monthly'] > 0 ? number_format($plan['price_monthly'], 3) . ' ' . (is_rtl() ? 'د.ب' : 'BHD') : (is_rtl() ? 'مجاناً' : 'Free') ?>
          <?php if ($plan['price_monthly'] > 0): ?><span><?= t('pricing_monthly') ?></span><?php endif; ?>
        </div>
        <ul class="price-feats">
          <?php foreach ($feats as $f): ?>
            <li><?= htmlspecialchars(is_rtl() ? $f['feature_ar'] : $f['feature_en']) ?></li>
          <?php endforeach; ?>
        </ul>
        <a href="/register.php?plan=<?= $plan['id'] ?>" class="btn <?= $plan['is_featured'] ? 'btn-primary' : 'btn-outline' ?> btn-block"><?= t('pricing_choose') ?></a>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ================= FAQ ================= -->
<section id="faq" class="section">
  <div class="container">
    <div class="section-head"><h2><?= t('faq_title') ?></h2></div>
    <div class="faq-list">
      <details class="faq-item" open><summary><?= t('faq1_q') ?></summary><p class="faq-a"><?= t('faq1_a') ?></p></details>
      <details class="faq-item"><summary><?= t('faq2_q') ?></summary><p class="faq-a"><?= t('faq2_a') ?></p></details>
      <details class="faq-item"><summary><?= t('faq3_q') ?></summary><p class="faq-a"><?= t('faq3_a') ?></p></details>
      <details class="faq-item"><summary><?= t('faq4_q') ?></summary><p class="faq-a"><?= t('faq4_a') ?></p></details>
    </div>
  </div>
</section>

<!-- ================= FINAL CTA ================= -->
<section class="final-cta section-dark">
  <div class="container">
    <h2><?= t('final_title') ?></h2>
    <p><?= t('final_body') ?></p>
    <a href="/register.php" class="btn btn-gold"><?= t('final_cta') ?></a>
  </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
