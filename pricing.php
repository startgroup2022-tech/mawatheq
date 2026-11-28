<?php
require_once __DIR__ . '/lang/strings.php';
// The page heading is an <h1>: it is the subject of the document. It was an <h2>, so the
// page exposed no top-level heading and a screen reader announced the plan names as if they
// were the page's structure. $pageTitle also gives the tab and the OG title the real name.
$pageTitle = t('pricing_title');
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/plan_features.php';
?>
<section class="section" id="corporate">
  <div class="container">
    <div class="section-head"><h1><?= t('pricing_title') ?></h1></div>
    <div class="pricing-grid">
      <?php
      $plans = $pdo->query("SELECT * FROM plans WHERE status='active' ORDER BY sort_order")->fetchAll();
      $featsStmt = $pdo->prepare("SELECT * FROM plan_features WHERE plan_id=? ORDER BY sort_order");
      $rtl = is_rtl();
      foreach ($plans as $plan):
        $name = $rtl ? $plan['name_ar'] : $plan['name_en'];
        $desc = $rtl ? $plan['description_ar'] : $plan['description_en'];
        $featsStmt->execute([$plan['id']]);
        $feats = $featsStmt->fetchAll();
        // The typed limits the server enforces, described by the same catalogue the gates
        // read. Without this the card listed only the free-text benefits and two AI
        // counters, so a plan's real ceilings were invisible until a customer hit one.
        $benefits = plan_benefit_lines($plan, $rtl);
      ?>
      <div class="price-card <?= $plan['is_featured'] ? 'featured' : '' ?>">
        <?php if ($plan['is_featured']): ?><span class="pop-tag"><?= t('pricing_popular') ?></span><?php endif; ?>
        <h3><?= htmlspecialchars($name) ?></h3>
        <p style="font-size:.88rem"><?= htmlspecialchars($desc) ?></p>
        <div class="price-amount">
          <?= $plan['price_monthly'] > 0 ? money($plan['price_monthly']) : ($rtl ? 'مجاناً' : 'Free') ?>
          <?php if ($plan['price_monthly'] > 0): ?><span><?= t('pricing_monthly') ?></span><?php endif; ?>
        </div>
        <?php if ($plan['price_yearly'] !== null && (float)$plan['price_yearly'] > 0): ?>
          <p style="font-size:.82rem;color:var(--text-muted);margin:-6px 0 10px">
            <?= $rtl ? 'أو' : 'or' ?> <?= money($plan['price_yearly']) ?> <?= $rtl ? '/سنة' : '/year' ?>
          </p>
        <?php endif; ?>
        <ul class="price-feats">
          <?php foreach ($benefits as $b): ?>
            <li><?= htmlspecialchars($b['label']) ?><?php if ($b['value'] !== ''): ?>: <strong><?= htmlspecialchars($b['value']) ?></strong><?php endif; ?></li>
          <?php endforeach; ?>
          <?php foreach ($feats as $f): ?><li><?= htmlspecialchars($rtl ? $f['feature_ar'] : $f['feature_en']) ?></li><?php endforeach; ?>
        </ul>
        <a href="/packages.php#plan-<?= (int)$plan['id'] ?>" class="btn <?= $plan['is_featured'] ? 'btn-primary' : 'btn-outline' ?> btn-block"><?= t('pricing_choose') ?></a>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
