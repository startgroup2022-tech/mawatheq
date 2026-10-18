<?php
/**
 * Campaigns (Execution 31).
 *
 * Campaigns are created by administrators; an affiliate joins the ones that are open
 * to them and gets a campaign-tagged link. An affiliate cannot create a campaign,
 * because a campaign carries a compliance approval and a plan override that decide
 * what the programme may lawfully pay — that is an administrative decision.
 */
$activeAffiliate = 'campaigns';
require_once __DIR__ . '/../includes/affiliate_header.php';
require_once __DIR__ . '/../includes/affiliate_tracking.php';


$affiliateId = (int)$affiliate['id'];
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $string = static function (string $key, string $default = ''): string {
        $value = $_POST[$key] ?? $default;
        return is_string($value) ? trim($value) : $default;
    };
    $action = $string('action');
    $campaignId = (int)$string('campaign_id');

    if ($action === 'join') {
        // Only a live campaign can be joined, and only by an affiliate who can earn.
        $campaign = affiliate_campaign_find($pdo, $campaignId);
        if (!$campaign || !affiliate_campaign_is_live($campaign)) {
            $error = is_rtl() ? 'الحملة غير متاحة.' : 'That campaign is not available.';
        } elseif (!affiliate_can_earn($affiliate)) {
            $error = is_rtl() ? 'حسابك غير مفعّل بعد.' : 'Your account is not active yet.';
        } else {
            try {
                // One link per affiliate per campaign: the UNIQUE key enforces it, and
                // the insert-ignore makes a repeat click idempotent rather than an error.
                $code = $affiliateId . '-C' . $campaignId . '-' . strtoupper(bin2hex(random_bytes(3)));
                $pdo->prepare('INSERT IGNORE INTO affiliate_links (affiliate_id, campaign_id, code, label, destination_path, utm_source, utm_medium, utm_campaign, is_active)
                    VALUES (?,?,?,?,?,?,?,?,1)')
                    ->execute([
                        $affiliateId, $campaignId, $code,
                        (string)$campaign['name'],
                        affiliate_safe_destination((string)($campaign['landing_path'] ?: '/')),
                        'affiliate', 'referral', (string)$campaign['slug'],
                    ]);
                $success = is_rtl() ? 'تم إنشاء رابط الحملة.' : 'A campaign link was created.';
                affiliate_audit($pdo, 'campaign_joined', [
                    'affiliate_id' => $affiliateId, 'subject_type' => 'campaign', 'subject_id' => $campaignId,
                ]);
            } catch (Throwable $e) {
                error_log('affiliate campaign join: ' . $e->getMessage());
                $error = is_rtl() ? 'تعذّر الانضمام إلى الحملة.' : 'The campaign could not be joined.';
            }
        }
    }
}

$campaigns = [];
try {
    $stmt = $pdo->query("SELECT c.*, p.name AS plan_name FROM affiliate_campaigns c
        LEFT JOIN affiliate_commission_plans p ON p.id = c.commission_plan_id
        WHERE c.status='active' AND (c.starts_at IS NULL OR c.starts_at <= NOW()) AND (c.ends_at IS NULL OR c.ends_at >= NOW())
        ORDER BY c.id DESC LIMIT 100");
    $campaigns = $stmt->fetchAll();
} catch (Throwable $e) { /* pre-v57 database */ }

$myLinks = [];
try {
    $stmt = $pdo->prepare('SELECT campaign_id, code FROM affiliate_links WHERE affiliate_id=? AND campaign_id IS NOT NULL');
    $stmt->execute([$affiliateId]);
    foreach ($stmt->fetchAll() as $row) $myLinks[(int)$row['campaign_id']] = (string)$row['code'];
} catch (Throwable $e) { /* pre-v57 database */ }
?>
<div class="app-page">
  <div class="app-page-head">
    <div>
      <h1><?= ui_e(is_rtl() ? 'الحملات' : 'Campaigns') ?></h1>
      <p class="muted"><?= ui_e(is_rtl() ? 'حملات أطلقها فريق المنصة. انضم إليها للحصول على رابط مخصّص وقياس أدائك.' : 'Campaigns run by the platform team. Join one to get a dedicated link and track your performance.') ?></p>
    </div>
  </div>

  <?php if ($error): ?><div class="form-alert error"><?= ui_e($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="form-alert success"><?= ui_e($success) ?></div><?php endif; ?>

  <?php if (!$campaigns): ?>
    <div class="app-card">
      <?= ui_empty_state(is_rtl() ? 'لا توجد حملات نشطة' : 'No active campaigns', is_rtl() ? 'ستظهر الحملات هنا عند إطلاقها.' : 'Campaigns appear here when the team launches one.', 'chart') ?>
    </div>
  <?php else: ?>
    <div class="aff-campaign-grid">
      <?php foreach ($campaigns as $campaign):
          $joined = isset($myLinks[(int)$campaign['id']]);
          $isLive = affiliate_campaign_is_live($campaign);
      ?>
      <div class="aff-campaign">
        <div class="aff-campaign-head">
          <h3><?= ui_e((string)$campaign['name']) ?></h3>
          <span class="status-pill <?= $isLive ? 'status-confirmed' : 'status-pending' ?>"><?= ui_e($isLive ? (is_rtl() ? 'نشطة' : 'Live') : (is_rtl() ? 'غير نشطة' : 'Not live')) ?></span>
        </div>
        <?php if (!empty($campaign['description'])): ?><p><?= ui_e((string)$campaign['description']) ?></p><?php endif; ?>
        <dl class="aff-campaign-meta">
          <?php if (!empty($campaign['plan_name'])): ?>
            <div><dt><?= ui_e(is_rtl() ? 'خطة العمولة' : 'Commission plan') ?></dt><dd><?= ui_e((string)$campaign['plan_name']) ?></dd></div>
          <?php endif; ?>
          <?php if (!empty($campaign['ends_at'])): ?>
            <div><dt><?= ui_e(is_rtl() ? 'تنتهي' : 'Ends') ?></dt><dd><?= ui_e(date('Y-m-d', strtotime((string)$campaign['ends_at']))) ?></dd></div>
          <?php endif; ?>
          <div><dt><?= ui_e(is_rtl() ? 'الصفحة المقصودة' : 'Landing page') ?></dt><dd class="aff-mono"><?= ui_e((string)($campaign['landing_path'] ?: '/')) ?></dd></div>
        </dl>
        <?php if ($joined): ?>
          <div class="aff-linkbox-row">
            <input type="text" readonly value="<?= ui_e(affiliate_build_link($affiliate, (string)($campaign['landing_path'] ?: '/'), ['utm_source' => 'affiliate', 'utm_medium' => 'referral', 'utm_campaign' => (string)$campaign['slug']])) ?>" id="cmp<?= (int)$campaign['id'] ?>">
            <button type="button" class="btn btn-gold btn-sm" data-copy-target="#cmp<?= (int)$campaign['id'] ?>" data-copy-done="<?= ui_e(is_rtl() ? 'تم' : 'Done') ?>"><?= ui_e(is_rtl() ? 'نسخ' : 'Copy') ?></button>
          </div>
        <?php elseif ($isLive && $affiliateCanEarn): ?>
          <form method="post">
            <input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>">
            <input type="hidden" name="action" value="join">
            <input type="hidden" name="campaign_id" value="<?= (int)$campaign['id'] ?>">
            <button class="btn btn-primary btn-sm" type="submit"><?= ui_e(is_rtl() ? 'انضم إلى الحملة' : 'Join campaign') ?></button>
          </form>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/affiliate_footer.php'; ?>
