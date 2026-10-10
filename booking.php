<?php
$activePage = 'consultations';
require_once __DIR__ . '/includes/app_header.php';
require_once __DIR__ . '/includes/availability.php';
require_once __DIR__ . '/includes/notifications.php';

$lawyerId = (int)($_GET['lawyer_id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM lawyers WHERE id=? AND status='approved'");
$stmt->execute([$lawyerId]);
$lawyer = $stmt->fetch();
if (!$lawyer) { echo '<div class="app-content"><p>' . (is_rtl() ? 'المحامي غير موجود.' : 'Lawyer not found.') . '</p></div>'; require __DIR__.'/includes/app_footer.php'; exit; }

$availCount = $pdo->prepare('SELECT COUNT(*) c FROM lawyer_availability WHERE lawyer_id=?');
$availCount->execute([$lawyerId]);
$hasAvailability = (int)$availCount->fetch()['c'] > 0;

$error = null;
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = $_POST['consultation_type'] ?? 'text';
    $date = $_POST['date'] ?? '';
    $time = $_POST['time'] ?? '';
    $topic = trim($_POST['topic'] ?? '');
    $scheduledAt = $date . ' ' . $time . ':00';

    $validSlots = ($date && $hasAvailability) ? get_available_slots($pdo, $lawyerId, $date) : [];
    if (!$date || !$time) {
        $error = is_rtl() ? 'يرجى اختيار التاريخ والوقت.' : 'Please choose a date and time.';
    } elseif ($hasAvailability && !in_array($time, $validSlots, true)) {
        $error = is_rtl() ? 'هذا الموعد لم يعد متاحاً، يرجى اختيار وقت آخر.' : 'This slot is no longer available, please pick another.';
    } else {
        $pdo->prepare('INSERT INTO consultations (user_id, lawyer_id, consultation_type, scheduled_at, topic, price) VALUES (?,?,?,?,?,?)')
            ->execute([$_SESSION['user_id'], $lawyerId, $type, $scheduledAt, $topic, $lawyer['hourly_rate']]);
        notify($pdo, 'lawyer', $lawyerId, is_rtl() ? 'طلب حجز استشارة جديد' : 'New consultation request', ($topic ?: '') . ' — ' . $scheduledAt, '/lawyer/consultations.php');
        require_once __DIR__ . '/includes/integrations.php';
        integration_emit_for_lawyer($pdo, $lawyerId, 'consultation.booked', [
            'consultation_id' => (int)$pdo->lastInsertId(), 'lawyer_id' => (int)$lawyerId,
            'client_id' => (int)$_SESSION['user_id'], 'consultation_type' => (string)$type, 'scheduled_at' => (string)$scheduledAt,
        ]);
        $success = true;
    }
}

$minDate = date('Y-m-d');
$maxDate = date('Y-m-d', strtotime('+30 days'));
?>
<div class="app-content">
  <?php if ($success): ?>
    <div class="dash-card" style="text-align:center;padding:48px 24px">
      <div style="font-size:2.4rem;margin-bottom:10px">✅</div>
      <h3><?= is_rtl() ? 'تم إرسال طلب الحجز' : 'Booking request sent' ?></h3>
      <p><?= is_rtl() ? 'سيتواصل معك المحامي لتأكيد الموعد.' : 'The lawyer will contact you to confirm the appointment.' ?></p>
      <a href="/consultations.php" class="btn btn-primary"><?= is_rtl() ? 'العودة للاستشارات' : 'Back to consultations' ?></a>
    </div>
  <?php else: ?>
    <div class="dash-card" style="display:flex;align-items:center;gap:16px">
      <img src="<?= !empty($lawyer['photo_path']) ? '/' . htmlspecialchars($lawyer['photo_path']) : '/assets/img/avatar-placeholder.svg' ?>" style="width:64px;height:64px;border-radius:50%;object-fit:cover;background:#EAEFF3" onerror="this.src='/assets/img/avatar-placeholder.svg'">
      <div>
        <h2 style="margin:0"><?= htmlspecialchars($lawyer['full_name']) ?></h2>
        <p style="margin:4px 0 0;color:var(--text-muted);font-size:.88rem"><?= htmlspecialchars($lawyer['specialization'] ?? '') ?> · ⭐ <?= $lawyer['rating'] ?> · <?= currency_number($lawyer['hourly_rate']) ?> <?= currency_symbol() ?><?= is_rtl() ? '/ساعة' : '/hr' ?></p>
      </div>
    </div>

    <?php if ($error): ?><div class="form-alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if (!$hasAvailability): ?>
      <div class="form-alert" style="background:#FFF8E6;color:#8A6D1D"><?= is_rtl() ? 'هذا المحامي لم يحدد أوقات دوام بعد — اختر تاريخاً ووقتاً مبدئياً وسيتواصل معك لتأكيد الموعد.' : "This lawyer hasn't set booking hours yet — pick a tentative date/time and they'll confirm with you." ?></div>
    <?php endif; ?>

    <form class="dash-card" method="post" id="bookingForm"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
      <div class="field">
        <label><?= is_rtl() ? 'نوع الاستشارة' : 'Consultation type' ?></label>
        <div class="select-wrap"><select name="consultation_type" class="pro-select">
          <option value="text"><?= is_rtl() ? 'نصية' : 'Text' ?></option>
          <option value="voice"><?= is_rtl() ? 'صوتية' : 'Voice' ?></option>
          <option value="video"><?= is_rtl() ? 'فيديو' : 'Video' ?></option>
        </select></div>
      </div>
      <div class="field">
        <label><?= is_rtl() ? 'التاريخ' : 'Date' ?></label>
        <input type="date" name="date" id="dateInput" min="<?= $minDate ?>" max="<?= $maxDate ?>" required <?= $hasAvailability ? 'onchange="loadSlots()"' : '' ?>>
      </div>

      <?php if ($hasAvailability): ?>
        <div class="field">
          <label><?= is_rtl() ? 'الوقت المتاح' : 'Available time' ?></label>
          <div id="slotsBox" class="slot-grid"><p class="field-hint"><?= is_rtl() ? 'اختر تاريخاً أولاً لعرض الأوقات المتاحة.' : 'Choose a date first to see available times.' ?></p></div>
          <input type="hidden" name="time" id="timeInput" required>
        </div>
      <?php else: ?>
        <div class="field"><label><?= is_rtl() ? 'الوقت' : 'Time' ?></label><input type="time" name="time" required></div>
      <?php endif; ?>

      <div class="field"><label><?= is_rtl() ? 'موضوع الاستشارة' : 'Topic' ?></label><textarea name="topic" rows="3"></textarea></div>
      <button class="btn btn-primary btn-block btn-lg" type="submit"><?= t('hero_cta_book') ?></button>
    </form>
  <?php endif; ?>
</div>

<?php if ($hasAvailability && !$success): ?>
<script>
async function loadSlots(){
  const date = document.getElementById('dateInput').value;
  const box = document.getElementById('slotsBox');
  const timeInput = document.getElementById('timeInput');
  timeInput.value = '';
  if (!date) return;
  box.innerHTML = '<p class="field-hint"><?= is_rtl() ? "جارٍ التحميل..." : "Loading..." ?></p>';
  try {
    const res = await fetch('/api/available_slots.php?lawyer_id=<?= $lawyerId ?>&date=' + date);
    const data = await res.json();
    if (!data.slots || data.slots.length === 0) {
      box.innerHTML = '<p class="field-hint"><?= is_rtl() ? "لا توجد أوقات متاحة في هذا اليوم، جرّب تاريخاً آخر." : "No slots available this day, try another date." ?></p>';
      return;
    }
    box.innerHTML = '';
    data.slots.forEach(function(slot){
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'slot-btn';
      btn.textContent = slot;
      btn.onclick = function(){
        document.querySelectorAll('.slot-btn').forEach(function(b){ b.classList.remove('selected'); });
        btn.classList.add('selected');
        timeInput.value = slot;
      };
      box.appendChild(btn);
    });
  } catch(e) {
    box.innerHTML = '<p class="field-hint"><?= is_rtl() ? "تعذّر تحميل الأوقات، حاول مجدداً." : "Could not load slots, try again." ?></p>';
  }
}
document.getElementById('bookingForm').addEventListener('submit', function(e){
  if (!document.getElementById('timeInput').value) {
    e.preventDefault();
    alert('<?= is_rtl() ? "يرجى اختيار وقت من الأوقات المتاحة." : "Please choose an available time." ?>');
  }
});
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/app_footer.php'; ?>
