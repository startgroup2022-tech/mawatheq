<?php
require_once __DIR__ . '/includes/header.php';
?>
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
      </div>
      <div>
        <div style="background:#fff;border:1px solid var(--border);border-radius:12px;padding:32px;text-align:center">
          <div style="font-size:2rem;margin-bottom:12px">⚖️</div>
          <h3 style="margin-bottom:10px"><?= is_rtl() ? 'أنشئ حساب المحامي الآن' : 'Create your lawyer account now' ?></h3>
          <p style="margin-bottom:20px"><?= is_rtl() ? 'التسجيل مباشر — يبقى حسابك بانتظار مراجعة سريعة من إدارة مواثيق قبل التفعيل.' : 'Direct signup — your account awaits a quick review from the Mawatheq team before activation.' ?></p>
          <a href="/register.php?as=lawyer" class="btn btn-primary btn-block"><?= t('lawyers_cta') ?></a>
        </div>
      </div>
    </div>
  </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
