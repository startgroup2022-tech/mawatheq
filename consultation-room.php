<?php
$activePage = 'consultations';
require_once __DIR__ . '/includes/app_header.php';
require_once __DIR__ . '/includes/consultation_attachments.php';
$role = 'client';

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM consultations WHERE id=? AND user_id=?');
$stmt->execute([$id, $_SESSION['user_id']]);
$consultation = $stmt->fetch();

if ($consultation) {
    $extraPageAssets = [
        '<link rel="stylesheet" href="/assets/css/consultation-attachments.css">',
        '<script src="/assets/js/consultation-attachments.js" defer></script>',
    ];
}
?>
<div class="app-content">
  <?php if (!$consultation): ?>
    <p><?= is_rtl() ? 'الاستشارة غير موجودة.' : 'Consultation not found.' ?></p>
  <?php else: ?>
    <?php require __DIR__ . '/includes/consultation_room.php'; ?>
    <?php
    // The client sees every attachment on their own consultation and may add more while it
    // is still pending or confirmed. The panel enforces that itself, so the page does not
    // have to duplicate the rule.
    $actor = ['type' => 'client', 'id' => (int)$_SESSION['user_id']];
    require __DIR__ . '/includes/consultation_attachments_panel.php';
    ?>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/app_footer.php'; ?>
