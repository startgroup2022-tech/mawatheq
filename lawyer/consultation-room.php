<?php
$activeLawyer = 'consultations';
require_once __DIR__ . '/includes/lawyer_header.php';
require_once __DIR__ . '/../includes/consultation_attachments.php';
$role = 'lawyer';

$id = (int)($_GET['id'] ?? 0);
$lawyerScope = tenant_owner_where($pdo, 'consultations');
$stmt = $pdo->prepare('SELECT * FROM consultations WHERE id=? AND ' . $lawyerScope['sql']);
$stmt->execute(array_merge([$id], $lawyerScope['params']));
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
    <?php require __DIR__ . '/../includes/consultation_room.php'; ?>
    <?php
    // The lawyer sees the client's attachments and their own. The tenant scope above already
    // refused the page to a lawyer outside this consultation's tenant, so no unrelated lawyer
    // or firm can reach the files: download-consultation-attachment.php re-applies the same
    // check on every request, so a hand-made link is refused too.
    $actor = ['type' => 'lawyer', 'id' => (int)$_SESSION['lawyer_id']];
    require __DIR__ . '/../includes/consultation_attachments_panel.php';
    ?>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/lawyer_footer.php'; ?>
