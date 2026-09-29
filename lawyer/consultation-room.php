<?php
$activeLawyer = 'consultations';
require_once __DIR__ . '/includes/lawyer_header.php';
$role = 'lawyer';

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM consultations WHERE id=? AND lawyer_id=?');
$stmt->execute([$id, $_SESSION['lawyer_id']]);
$consultation = $stmt->fetch();
?>
<div class="app-content">
  <?php if (!$consultation): ?>
    <p><?= is_rtl() ? 'الاستشارة غير موجودة.' : 'Consultation not found.' ?></p>
  <?php else: ?>
    <?php require __DIR__ . '/../includes/consultation_room.php'; ?>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/lawyer_footer.php'; ?>
