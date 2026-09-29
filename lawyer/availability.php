<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/lawyer_guard.php';
require_once __DIR__ . '/../includes/availability.php';
$lawyerId = $_SESSION['lawyer_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo->prepare('DELETE FROM lawyer_availability WHERE lawyer_id=?')->execute([$lawyerId]);
    foreach (range(0, 6) as $day) {
        if (empty($_POST['enabled'][$day])) continue;
        $start = $_POST['start'][$day] ?? '09:00';
        $end = $_POST['end'][$day] ?? '17:00';
        $slot = (int)($_POST['slot'][$day] ?? 30);
        if ($start >= $end) continue;
        $pdo->prepare('INSERT INTO lawyer_availability (lawyer_id, day_of_week, start_time, end_time, slot_minutes) VALUES (?,?,?,?,?)')
            ->execute([$lawyerId, $day, $start, $end, $slot]);
    }
    header('Location: /lawyer/availability.php?saved=1'); exit;
}

$activeLawyer = 'availability';
require_once __DIR__ . '/includes/lawyer_header.php';

$rows = $pdo->prepare('SELECT * FROM lawyer_availability WHERE lawyer_id=?');
$rows->execute([$lawyerId]);
$byDay = [];
foreach ($rows->fetchAll() as $r) { $byDay[(int)$r['day_of_week']] = $r; }
$names = weekday_names();
?>
<div class="app-content">
  <h2><?= is_rtl() ? 'أوقات الدوام المتاحة للحجز' : 'Available booking hours' ?></h2>
  <p style="color:var(--text-muted);font-size:.88rem;margin-bottom:20px"><?= is_rtl() ? 'حدد الأيام والأوقات التي يمكن للعملاء حجز موعد معك فيها. العميل يرى فقط الأوقات الفعلية المتاحة (غير المحجوزة).' : 'Choose the days and hours clients can book with you. Clients only see actually free slots.' ?></p>

  <?php if (isset($_GET['saved'])): ?><div class="form-alert success"><?= is_rtl() ? 'تم حفظ أوقات الدوام بنجاح.' : 'Availability saved successfully.' ?></div><?php endif; ?>

  <form class="dash-card" method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
    <table class="admin-table" style="width:100%">
      <thead><tr>
        <th><?= is_rtl() ? 'اليوم' : 'Day' ?></th><th><?= is_rtl() ? 'متاح؟' : 'Enabled?' ?></th>
        <th><?= is_rtl() ? 'من' : 'From' ?></th><th><?= is_rtl() ? 'إلى' : 'To' ?></th><th><?= is_rtl() ? 'مدة الموعد (دقيقة)' : 'Slot length (min)' ?></th>
      </tr></thead>
      <tbody>
        <?php foreach ($names as $day => $label): $current = $byDay[$day] ?? null; ?>
        <tr>
          <td><strong><?= htmlspecialchars($label) ?></strong></td>
          <td><input type="checkbox" name="enabled[<?= $day ?>]" value="1" <?= $current ? 'checked' : '' ?> style="width:20px;height:20px"></td>
          <td><input type="time" name="start[<?= $day ?>]" value="<?= $current ? substr($current['start_time'],0,5) : '09:00' ?>"></td>
          <td><input type="time" name="end[<?= $day ?>]" value="<?= $current ? substr($current['end_time'],0,5) : '17:00' ?>"></td>
          <td>
            <div class="select-wrap"><select name="slot[<?= $day ?>]" class="pro-select">
              <?php foreach ([15,30,45,60] as $m): ?><option value="<?= $m ?>" <?= ($current && (int)$current['slot_minutes'] === $m) || (!$current && $m === 30) ? 'selected' : '' ?>><?= $m ?></option><?php endforeach; ?>
            </select></div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <button class="btn btn-primary btn-lg" style="margin-top:20px" type="submit"><?= is_rtl() ? 'حفظ أوقات الدوام' : 'Save availability' ?></button>
  </form>
</div>
<?php require_once __DIR__ . '/includes/lawyer_footer.php'; ?>
