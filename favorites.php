<?php
/**
 * My favourite lawyers (Execution 72).
 *
 * The saved consultants, rendered with the same card the directory uses, plus the
 * availability alerts the customer asked for. Both lists come from the shared service
 * layer, so a card here states the same price, rating and slots as the directory.
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lang/strings.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/legal_journey.php';
require_once __DIR__ . '/includes/journey_ui.php';
require_once __DIR__ . '/includes/currency.php';
require_once __DIR__ . '/includes/customer_features.php';

$clientId = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? '')) { http_response_code(403); exit('Invalid CSRF token'); }
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'remove_favorite') {
        favorite_remove($pdo, $clientId, (int)($_POST['lawyer_id'] ?? 0));
    } elseif ($action === 'cancel_alert') {
        availability_alert_cancel($pdo, $clientId, (int)($_POST['lawyer_id'] ?? 0));
    }
    header('Location: /favorites.php');
    exit;
}

$activePage = 'favorites';
require_once __DIR__ . '/includes/app_header.php';

$lawyers = favorites_for_client($pdo, $clientId);
$favoriteIds = array_fill_keys(array_map(fn($l) => (int)$l['id'], $lawyers), true);
$alerts = availability_alerts_for_client($pdo, $clientId);
$activeAlerts = array_values(array_filter($alerts, fn($a) => $a['status'] === 'active'));
$csrf = csrf_token();
?>
<div class="app-content">
  <h1><?= is_rtl() ? 'المحامون المفضّلون' : 'Favourite lawyers' ?></h1>
  <p style="margin-bottom:20px"><?= is_rtl() ? 'احفظ المحامين الذين تتابعهم، واطلب تنبيهًا عند إتاحة مواعيدهم.' : 'Save the lawyers you follow and ask to be notified when their slots open.' ?></p>

  <?php if (empty($lawyers)): ?>
    <div class="journey-empty">
      <div class="ico">♡</div>
      <h3><?= is_rtl() ? 'لا يوجد محامون في المفضلة بعد' : 'No favourite lawyers yet' ?></h3>
      <p><?= is_rtl() ? 'تصفّح دليل المحامين وأضف من تريد متابعته.' : 'Browse the directory and save the lawyers you want to follow.' ?></p>
      <a href="/consultations.php" class="btn btn-primary"><?= is_rtl() ? 'تصفّح المحامين' : 'Browse lawyers' ?></a>
    </div>
  <?php else: ?>
    <div class="fav-grid">
      <?php foreach ($lawyers as $lw): ?>
        <div class="fav-card-wrap">
          <?= journey_lawyer_card($lw, $favoriteIds) ?>
          <form method="post" class="fav-remove">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="remove_favorite">
            <input type="hidden" name="lawyer_id" value="<?= (int)$lw['id'] ?>">
            <button type="submit" class="btn btn-ghost btn-sm"><?= is_rtl() ? 'إزالة من المفضلة' : 'Remove' ?></button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <h2 style="margin-top:36px"><?= is_rtl() ? 'تنبيهات توفّر المواعيد' : 'Availability alerts' ?></h2>
  <?php if (empty($activeAlerts)): ?>
    <p class="muted-sm"><?= is_rtl() ? 'لا توجد تنبيهات نشطة. افتح ملف المحامي واضغط «أبلغني عند إتاحة المواعيد».' : 'No active alerts. Open a lawyer’s profile and press “Notify me when slots open”.' ?></p>
  <?php else: ?>
    <ul class="alert-list">
      <?php foreach ($activeAlerts as $a): ?>
        <li>
          <a href="/lawyer-profile.php?id=<?= (int)$a['lawyer_id'] ?>"><?= htmlspecialchars((string)$a['lawyer_name']) ?></a>
          <form method="post" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="cancel_alert">
            <input type="hidden" name="lawyer_id" value="<?= (int)$a['lawyer_id'] ?>">
            <button type="submit" class="btn btn-ghost btn-sm"><?= is_rtl() ? 'إلغاء' : 'Cancel' ?></button>
          </form>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/app_footer.php'; ?>
