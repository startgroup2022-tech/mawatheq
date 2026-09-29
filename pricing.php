<?php
require_once __DIR__ . '/includes/header.php';
?>
<section class="section" id="corporate">
  <div class="container">
    <div class="section-head"><h2><?= t('pricing_title') ?></h2></div>
    <div class="pricing-grid">
      <?php
      $plans = $pdo->query("SELECT * FROM plans WHERE status='active' ORDER BY sort_order")->fetchAll();
      $featsStmt = $pdo->prepare("SELECT * FROM plan_features WHERE plan_id=? ORDER BY sort_order");
      foreach ($plans as $plan):
        $name = is_rtl() ? $plan['name_ar'] : $plan['name_en'];
        $desc = is_rtl() ? $plan['description_ar'] : $plan['description_en'];
        $featsStmt->execute([$plan['id']]);
        $feats = $featsStmt->fetchAll();
      ?>
      <div class="price-card <?= $plan['is_featured'] ? 'featured' : '' ?>">
        <?php if ($plan['is_featured']): ?><span class="pop-tag"><?= t('pricing_popular') ?></span><?php endif; ?>
        <h3><?= htmlspecialchars($name) ?></h3>
        <p style="font-size:.88rem"><?= htmlspecialchars($desc) ?></p>
        <div class="price-amount">
          <?= $plan['price_monthly'] > 0 ? number_format($plan['price_monthly'], 3) . ' ' . (is_rtl() ? 'د.ب' : 'BHD') : (is_rtl() ? 'مجاناً' : 'Free') ?>
          <?php if ($plan['price_monthly'] > 0): ?><span><?= t('pricing_monthly') ?></span><?php endif; ?>
        </div>
        <ul class="price-feats">
          <?php foreach ($feats as $f): ?><li><?= htmlspecialchars(is_rtl() ? $f['feature_ar'] : $f['feature_en']) ?></li><?php endforeach; ?>
        </ul>
        <a href="/register.php?plan=<?= $plan['id'] ?>" class="btn <?= $plan['is_featured'] ? 'btn-primary' : 'btn-outline' ?> btn-block"><?= t('pricing_choose') ?></a>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
