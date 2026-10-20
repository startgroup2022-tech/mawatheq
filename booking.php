<?php
$activePage = 'consultations';
require_once __DIR__ . '/includes/app_header.php';
require_once __DIR__ . '/includes/availability.php';
require_once __DIR__ . '/includes/notifications.php';
require_once __DIR__ . '/includes/consultation_attachments.php';
require_once __DIR__ . '/includes/tenant.php';
require_once __DIR__ . '/includes/legal_journey.php';

$lawyerId = (int)($_GET['lawyer_id'] ?? 0);

// The journey (consultation-journey.php) hands the chosen specialty and issue type to
// this form through the query string, so the booking keeps the routing the customer
// chose. Both are re-validated server-side below; a value that does not exist is
// dropped rather than stored.
//
// The parameter is `issue_type_id`, the customer's own vocabulary. The legacy
// `najiz_case_type_id` is still accepted so an older mobile build or a saved link keeps
// working, but nothing the customer sees is named after the internal source.
$journeyCategoryId = (int)($_GET['specialty_category_id'] ?? 0);
$journeyIssueTypeId = (int)($_GET['issue_type_id'] ?? $_GET['najiz_case_type_id'] ?? 0);

// The customer-facing summary of that choice. Resolved to names on the server, so the
// page never renders an internal id and never needs the customer's browser to look the
// classification up. Null when the choice does not exist or the journey was skipped.
$journeyChoice = legal_journey_resolve_choice($pdo, $journeyCategoryId, $journeyIssueTypeId);
$journeyCategoryName = legal_journey_specialty_name($pdo, (int)$journeyChoice['specialty_category_id']);
$journeyIssueTypeName = legal_journey_issue_type_name($pdo, (int)$journeyChoice['najiz_case_type_id']);

$stmt = $pdo->prepare("SELECT * FROM lawyers WHERE id=? AND status='approved'");
$stmt->execute([$lawyerId]);
$lawyer = $stmt->fetch();
if (!$lawyer) { echo '<div class="app-content"><p>' . (is_rtl() ? 'المحامي غير موجود.' : 'Lawyer not found.') . '</p></div>'; require __DIR__.'/includes/app_footer.php'; exit; }

$availCount = $pdo->prepare('SELECT COUNT(*) c FROM lawyer_availability WHERE lawyer_id=?');
$availCount->execute([$lawyerId]);
$hasAvailability = (int)$availCount->fetch()['c'] > 0;

// The attachment section uploads as the user picks files, before the consultation row
// exists. A per-form draft token is what later lets this exact submission claim them.
// It is stored in the session so a form redisplayed after a validation error keeps the
// same token — and therefore the files already uploaded — instead of orphaning them.
if (empty($_SESSION['booking_draft_tokens'][$lawyerId])) {
    $_SESSION['booking_draft_tokens'][$lawyerId] = consultation_attachment_new_draft_token();
}
$draftToken = $_SESSION['booking_draft_tokens'][$lawyerId];

$error = null;
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = $_POST['consultation_type'] ?? 'text';
    $date = $_POST['date'] ?? '';
    $time = $_POST['time'] ?? '';
    $topic = trim($_POST['topic'] ?? '');
    $scheduledAt = $date . ' ' . $time . ':00';

    // A token the browser invented is not accepted: only the one this session was issued
    // can be claimed, so a crafted POST cannot adopt another client's staged uploads.
    $postedToken = (string)($_POST['attachment_draft_token'] ?? '');
    if ($postedToken !== $draftToken) $postedToken = '';

    $validSlots = ($date && $hasAvailability) ? get_available_slots($pdo, $lawyerId, $date) : [];
    if (!$date || !$time) {
        $error = is_rtl() ? 'يرجى اختيار التاريخ والوقت.' : 'Please choose a date and time.';
    } elseif ($hasAvailability && !in_array($time, $validSlots, true)) {
        $error = is_rtl() ? 'هذا الموعد لم يعد متاحاً، يرجى اختيار وقت آخر.' : 'This slot is no longer available, please pick another.';
    } else {
        // Resolve the journey choice against the real classification. A specialty or
        // issue type that does not exist — or an issue type that belongs to a different
        // specialty — is dropped rather than stored, so the consultation can only carry
        // a relationship that is true.
        $choice = legal_journey_resolve_choice(
            $pdo,
            (int)($_POST['specialty_category_id'] ?? $journeyCategoryId),
            (int)($_POST['issue_type_id'] ?? $_POST['najiz_case_type_id'] ?? $journeyIssueTypeId)
        );
        $najizTypeId = $choice['najiz_case_type_id'];
        $specialtyCategoryId = $choice['specialty_category_id'];

        // Tag the booking with the lawyer's firm, for the same reason the REST API does:
        // firm-scoped lawyers are authorized through consultations.firm_id, and nothing else
        // sets it. A solo lawyer resolves to NULL, which is the correct "no firm" marker.
        $firmId = tenant_context_for_lawyer($pdo, $lawyerId)['firm_id'];

        // The specialty column is written only when upgrade_v63.sql has run. Building the
        // statement around the real schema keeps a not-yet-migrated database bookable.
        if (legal_journey_specialty_column_ready($pdo)) {
            $pdo->prepare('INSERT INTO consultations (user_id, lawyer_id, firm_id, consultation_type, scheduled_at, topic, price, najiz_case_type_id, specialty_category_id) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$_SESSION['user_id'], $lawyerId, $firmId, $type, $scheduledAt, $topic, $lawyer['hourly_rate'], $najizTypeId, $specialtyCategoryId]);
        } else {
            $pdo->prepare('INSERT INTO consultations (user_id, lawyer_id, firm_id, consultation_type, scheduled_at, topic, price, najiz_case_type_id) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$_SESSION['user_id'], $lawyerId, $firmId, $type, $scheduledAt, $topic, $lawyer['hourly_rate'], $najizTypeId]);
        }
        $consultationId = (int)$pdo->lastInsertId();

        // Optional judicial requests. Re-validated against the chosen type so a posted id
        // cannot attach a request that belongs to a different classification.
        if ($najizTypeId && !empty($_POST['najiz_request_ids']) && is_array($_POST['najiz_request_ids'])) {
            try {
                $rc = $pdo->prepare('SELECT id FROM najiz_judicial_requests WHERE id=? AND case_type_id=? AND is_active=1');
                $rl = $pdo->prepare('INSERT IGNORE INTO consultation_judicial_requests (consultation_id, request_id) VALUES (?,?)');
                foreach (array_slice($_POST['najiz_request_ids'], 0, 50) as $rid) {
                    $rc->execute([(int)$rid, $najizTypeId]);
                    if ($rc->fetchColumn()) $rl->execute([$consultationId, (int)$rid]);
                }
            } catch (Throwable $e) {
                error_log('booking najiz requests: ' . $e->getMessage());
            }
        }

        // Bind the files the client attached while filling the form. A failure here must not
        // lose the booking — the consultation exists and the lawyer will be notified — so the
        // result is reported rather than thrown, and unclaimed files stay staged for the
        // sweeper instead of being silently attached to the wrong place.
        $claimed = 0;
        if ($postedToken !== '') {
            $claimed = consultation_attachment_claim($pdo, $postedToken, $consultationId, null, (int)$_SESSION['user_id']);
            unset($_SESSION['booking_draft_tokens'][$lawyerId]);
        }

        require_once __DIR__ . '/includes/audit_log.php';
        if ($claimed > 0) {
            log_action($pdo, 'حجز استشارة مع ' . $claimed . ' مرفق', 'consultation', $consultationId);
        }

        notify($pdo, 'lawyer', $lawyerId, is_rtl() ? 'طلب حجز استشارة جديد' : 'New consultation request', ($topic ?: '') . ' — ' . $scheduledAt, '/lawyer/consultations.php');
        require_once __DIR__ . '/includes/integrations.php';
        integration_emit_for_lawyer($pdo, $lawyerId, 'consultation.booked', [
            'consultation_id' => $consultationId, 'lawyer_id' => (int)$lawyerId,
            'client_id' => (int)$_SESSION['user_id'], 'consultation_type' => (string)$type, 'scheduled_at' => (string)$scheduledAt,
            'attachments' => $claimed,
        ]);
        $success = true;
    }
}

$minDate = date('Y-m-d');
$maxDate = date('Y-m-d', strtotime('+30 days'));
$attachmentLimits = consultation_attachment_limits($pdo);
$extraPageAssets = [
    '<link rel="stylesheet" href="/assets/css/consultation-attachments.css">',
    '<script src="/assets/js/consultation-attachments.js" defer></script>',
];
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
      <img src="<?= !empty($lawyer['photo_path']) ? '/' . htmlspecialchars($lawyer['photo_path']) : '/assets/img/avatar-placeholder.svg' ?>" alt="<?= htmlspecialchars($lawyer['full_name']) ?>" style="width:64px;height:64px;border-radius:50%;object-fit:cover;background:#EAEFF3" onerror="this.src='/assets/img/avatar-placeholder.svg'">

      <div>
        <h2 style="margin:0"><?= htmlspecialchars($lawyer['full_name']) ?></h2>
        <p style="margin:4px 0 0;color:var(--text-muted);font-size:.88rem"><?= htmlspecialchars($lawyer['specialization'] ?? '') ?> · ⭐ <?= $lawyer['rating'] ?> · <?= currency_number($lawyer['hourly_rate']) ?> <?= currency_symbol() ?><?= is_rtl() ? '/ساعة' : '/hr' ?></p>
      </div>
    </div>

    <?php if ($error): ?><div class="form-alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if (!$hasAvailability): ?>
      <div class="form-alert" style="background:#FFF8E6;color:#8A6D1D"><?= is_rtl() ? 'هذا المحامي لم يحدد أوقات دوام بعد — اختر تاريخاً ووقتاً مبدئياً وسيتواصل معك لتأكيد الموعد.' : "This lawyer hasn't set booking hours yet — pick a tentative date/time and they'll confirm with you." ?></div>
    <?php endif; ?>

    <form class="dash-card" method="post" id="bookingForm" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
      <input type="hidden" name="attachment_draft_token" value="<?= htmlspecialchars($draftToken) ?>" data-ca-draft-token>
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

      <?php
      /*
       * The customer's chosen specialty and issue type, carried from the journey.
       *
       * These are hidden fields, not a picker: the customer already made the choice in
       * plain language (consultation-journey.php), and this form must never show the
       * internal classification's name or taxonomy — that is an internal reference the
       * lawyer sees, not the customer. The values are re-validated in the POST handler,
       * so a crafted request cannot store a relationship that does not exist. When the
       * customer arrives here directly (no journey), both are 0 and the booking is simply
       * unclassified — the honest outcome.
       */
      ?>
      <input type="hidden" name="specialty_category_id" value="<?= (int)$journeyCategoryId ?>">
      <input type="hidden" name="issue_type_id" value="<?= (int)$journeyIssueTypeId ?>">
      <?php if ($journeyCategoryName !== null): ?>
        <div class="field">
          <div class="dash-card" style="margin:0;padding:14px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
            <div>
              <div style="font-size:.76rem;color:var(--text-muted);font-weight:700"><?= is_rtl() ? 'موضوع الاستشارة المختار' : 'Selected consultation topic' ?></div>
              <strong style="color:var(--navy-dark)"><?= htmlspecialchars($journeyCategoryName) ?><?php if ($journeyIssueTypeName !== null): ?> — <?= htmlspecialchars($journeyIssueTypeName) ?><?php endif; ?></strong>
            </div>
            <a href="/consultation-journey.php?category_id=<?= (int)$journeyChoice['specialty_category_id'] ?>" class="btn btn-outline btn-sm"><?= is_rtl() ? 'تعديل' : 'Edit' ?></a>
          </div>
        </div>
      <?php endif; ?>

      <?php
      /*
       * المرفقات والمستندات — sits after the topic and before the submit button, as the
       * brief specifies. Rendered through the shared include so the booking form and the
       * consultation pages cannot drift apart; only the mode differs (draft staging here,
       * direct attach there). Attachments are optional: nothing in this section is required
       * and the form submits with an empty list.
       */
      $consultation = null;
      $attachments = [];
      $actor = ['type' => 'client', 'id' => (int)$_SESSION['user_id']];
      require __DIR__ . '/includes/consultation_attachments_panel.php';
      ?>
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
