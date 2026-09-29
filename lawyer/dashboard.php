<?php
$activeLawyer = 'dashboard';
require_once __DIR__ . '/includes/lawyer_header.php';
require_once __DIR__ . '/../includes/profile_completion.php';

$lawyerId = $_SESSION['lawyer_id'];
$lwStmt = $pdo->prepare('SELECT * FROM lawyers WHERE id=?');
$lwStmt->execute([$lawyerId]);
$lwProfile = $lwStmt->fetch();
$completion = lawyer_completion_percent($lwProfile);
$stats = [
    'open_cases' => $pdo->prepare("SELECT COUNT(*) c FROM cases WHERE lawyer_id=? AND status IN ('open','in_review')"),
    'total_cases' => $pdo->prepare("SELECT COUNT(*) c FROM cases WHERE lawyer_id=?"),
    'upcoming' => $pdo->prepare("SELECT COUNT(*) c FROM consultations WHERE lawyer_id=? AND status IN ('pending','confirmed') AND scheduled_at >= NOW()"),
    'completed' => $pdo->prepare("SELECT COUNT(*) c FROM consultations WHERE lawyer_id=? AND status='completed'"),
];
foreach ($stats as $k => $stmt) { $stmt->execute([$lawyerId]); $stats[$k] = $stmt->fetch()['c']; }

$recentCases = $pdo->prepare('SELECT c.*, u.full_name AS client_name FROM cases c JOIN users u ON u.id=c.user_id WHERE c.lawyer_id=? ORDER BY c.updated_at DESC LIMIT 5');
$recentCases->execute([$lawyerId]);
$recentCases = $recentCases->fetchAll();
?>
<div class="app-content">
  <div class="lawyer-hero">
    <div>
      <h2 style="margin-bottom:4px"><?= is_rtl() ? 'مرحباً' : 'Welcome' ?>, <?= htmlspecialchars($_SESSION['lawyer_name'] ?? '') ?> 👋</h2>
      <p style="margin:0;color:var(--text-muted)"><?= is_rtl() ? 'هذه نظرة سريعة على نشاطك اليوم.' : "Here's a quick look at your activity today." ?></p>
    </div>
    <div class="lawyer-hero-actions">
      <a href="/lawyer/cases.php" class="btn btn-outline btn-sm">📁 <?= is_rtl() ? 'قضية جديدة' : 'New case' ?></a>
      <a href="/lawyer/invoices.php" class="btn btn-primary btn-sm">🧾 <?= is_rtl() ? 'فاتورة جديدة' : 'New invoice' ?></a>
    </div>
  </div>

  <?php if ($completion < 100): ?>
    <?php render_completion_card($lwProfile); ?>
  <?php endif; ?>

  <div class="stat-grid" style="grid-template-columns:repeat(4,1fr)">
    <div class="stat-tile stat-tile-teal"><span class="stat-tile-icon">📁</span><div><div class="num"><?= $stats['open_cases'] ?></div><div class="label"><?= is_rtl() ? 'قضايا نشطة' : 'Active cases' ?></div></div></div>
    <div class="stat-tile stat-tile-navy"><span class="stat-tile-icon">📚</span><div><div class="num"><?= $stats['total_cases'] ?></div><div class="label"><?= is_rtl() ? 'إجمالي القضايا' : 'Total cases' ?></div></div></div>
    <div class="stat-tile stat-tile-gold"><span class="stat-tile-icon">🗓️</span><div><div class="num"><?= $stats['upcoming'] ?></div><div class="label"><?= is_rtl() ? 'استشارات قادمة' : 'Upcoming consultations' ?></div></div></div>
    <div class="stat-tile stat-tile-success"><span class="stat-tile-icon">✅</span><div><div class="num"><?= $stats['completed'] ?></div><div class="label"><?= is_rtl() ? 'استشارات مكتملة' : 'Completed consultations' ?></div></div></div>
  </div>

  <div class="toolbar"><h2><?= is_rtl() ? 'آخر القضايا' : 'Recent cases' ?></h2><a href="/lawyer/cases.php" class="btn btn-outline btn-sm"><?= is_rtl() ? 'إدارة القضايا' : 'Manage cases' ?></a></div>
  <?php if (empty($recentCases)): ?>
    <div class="dash-card" style="text-align:center;color:var(--text-muted)"><?= is_rtl() ? 'لا توجد قضايا بعد. ابدأ بإضافة أول قضية لك.' : 'No cases yet. Start by adding your first case.' ?></div>
  <?php endif; ?>
  <?php foreach ($recentCases as $c): ?>
    <a href="/lawyer/case-detail.php?id=<?= $c['id'] ?>" class="dash-card case-row">
      <div>
        <strong><?= htmlspecialchars($c['title']) ?></strong>
        <div style="font-size:.82rem;color:var(--text-muted);margin-top:4px"><?= htmlspecialchars($c['client_name']) ?> · <?= htmlspecialchars($c['case_type']) ?></div>
      </div>
      <div style="text-align:end">
        <?php if ($c['win_probability'] !== null): ?><div style="font-weight:700;color:var(--navy)"><?= $c['win_probability'] ?>% <?= is_rtl() ? 'فوز' : 'win' ?></div><?php endif; ?>
        <span class="status-pill status-<?= str_starts_with($c['status'], 'closed') ? 'confirmed' : 'pending' ?>"><?= htmlspecialchars($c['status']) ?></span>
      </div>
    </a>
  <?php endforeach; ?>
</div>
<?php require_once __DIR__ . '/includes/lawyer_footer.php'; ?>
