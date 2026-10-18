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
$slotChoices = [15, 30, 45, 60];
?>
<div class="app-content">
  <?= ui_breadcrumbs([
      ['label' => is_rtl() ? 'لوحة المحامي' : 'Lawyer dashboard', 'href' => '/lawyer/dashboard.php'],
      ['label' => is_rtl() ? 'أوقات الدوام' : 'Availability'],
  ]) ?>
  <?= ui_page_head(
      is_rtl() ? 'أوقات الدوام المتاحة للحجز' : 'Available booking hours',
      is_rtl() ? 'حدد الأيام والأوقات التي يمكن للعملاء حجز موعد معك فيها. العميل يرى فقط الأوقات الفعلية المتاحة (غير المحجوزة).'
               : 'Choose the days and hours clients can book with you. Clients only see actually free slots.'
  ) ?>

  <?php if (isset($_GET['saved'])): ?><div class="form-alert success" role="status"><?= is_rtl() ? 'تم حفظ أوقات الدوام بنجاح.' : 'Availability saved successfully.' ?></div><?php endif; ?>

  <form class="dash-card" method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
    <div class="table-wrap table-cards">
      <table class="admin-table">
        <thead>
          <tr>
            <th><?= is_rtl() ? 'اليوم' : 'Day' ?></th>
            <th><?= is_rtl() ? 'متاح؟' : 'Enabled?' ?></th>
            <th><?= is_rtl() ? 'من' : 'From' ?></th>
            <th><?= is_rtl() ? 'إلى' : 'To' ?></th>
            <th><?= is_rtl() ? 'مدة الموعد (دقيقة)' : 'Slot length (min)' ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($names as $day => $label): $current = $byDay[$day] ?? null; $labelText = htmlspecialchars($label); ?>
          <tr>
            <td data-label="<?= is_rtl() ? 'اليوم' : 'Day' ?>"><strong><?= $labelText ?></strong></td>
            <td data-label="<?= is_rtl() ? 'متاح؟' : 'Enabled?' ?>">
              <label class="field-check" style="margin:0;justify-content:flex-end">
                <input type="checkbox" class="lg" name="enabled[<?= $day ?>]" value="1" <?= $current ? 'checked' : '' ?>>
                <span class="sr-only"><?= is_rtl() ? 'تفعيل' : 'Enable' ?> <?= $labelText ?></span>
              </label>
            </td>
            <td data-label="<?= is_rtl() ? 'من' : 'From' ?>">
              <input type="time" name="start[<?= $day ?>]" aria-label="<?= is_rtl() ? 'وقت البداية' : 'Start time' ?> — <?= $labelText ?>"
                     value="<?= $current ? substr($current['start_time'], 0, 5) : '09:00' ?>">
            </td>
            <td data-label="<?= is_rtl() ? 'إلى' : 'To' ?>">
              <input type="time" name="end[<?= $day ?>]" aria-label="<?= is_rtl() ? 'وقت النهاية' : 'End time' ?> — <?= $labelText ?>"
                     value="<?= $current ? substr($current['end_time'], 0, 5) : '17:00' ?>">
            </td>
            <td data-label="<?= is_rtl() ? 'مدة الموعد' : 'Slot length' ?>">
              <select name="slot[<?= $day ?>]" class="pro-select" aria-label="<?= is_rtl() ? 'مدة الموعد' : 'Slot length' ?> — <?= $labelText ?>">
                <?php foreach ($slotChoices as $m): ?>
                  <option value="<?= $m ?>" <?= ($current && (int)$current['slot_minutes'] === $m) || (!$current && $m === 30) ? 'selected' : '' ?>><?= $m ?></option>
                <?php endforeach; ?>
              </select>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <button class="btn btn-primary btn-lg" type="submit"><?= is_rtl() ? 'حفظ أوقات الدوام' : 'Save availability' ?></button>
  </form>
</div>
<?php require_once __DIR__ . '/includes/lawyer_footer.php'; ?>
