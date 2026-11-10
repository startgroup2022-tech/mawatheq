<?php
$activePage = 'consultations';
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lang/strings.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/availability.php';
require_once __DIR__ . '/includes/notifications.php';
require_once __DIR__ . '/includes/consultation_attachments.php';
require_once __DIR__ . '/includes/tenant.php';
require_once __DIR__ . '/includes/legal_journey.php';
require_once __DIR__ . '/includes/consultation_payment.php';
require_once __DIR__ . '/includes/booking_slots.php';
require_once __DIR__ . '/includes/consultation_pricing.php';

// A paid booking redirects to the gateway, and a redirect must happen before the shell
// prints anything (there is no output buffering, so a later header() is dropped — see the
// POST-redirect note in AGENTS.md). The payment start therefore runs here, above the
// app_header include, and the page continues only when there is something to render.
$paymentRedirect = null;

$lawyerId = (int)($_GET['lawyer_id'] ?? 0);

// A firm's public website links here as /booking.php?firm=<slug>. Resolve the slug to the
// firm's approved lawyers: one lawyer goes straight to their booking form, several show a
// picker. Without this the firm CTA landed on "Lawyer not found", so a firm could never be
// booked from its own site.
$firmSlug = trim((string)($_GET['firm'] ?? ''));
$firmInfo = null;
$firmLawyers = [];
if ($lawyerId <= 0 && $firmSlug !== '') {
    require_once __DIR__ . '/includes/firm_site.php';
    $firmInfo = firm_site_public_firm($pdo, $firmSlug);
    if ($firmInfo) {
        $firmLawyers = firm_site_team($pdo, (int)$firmInfo['id']);
        if (count($firmLawyers) === 1) $lawyerId = (int)$firmLawyers[0]['id'];
    }
}

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

// A firm with several approved lawyers, or a firm slug that resolved to no lawyer, needs a
// picker or an honest "not available" page rather than the bare "Lawyer not found" line the
// firm CTA used to hit.
if (!$lawyer && ($firmInfo || $firmSlug !== '')) {
    require_once __DIR__ . '/includes/app_header.php';
    ?>
    <div class="app-content">
      <?php if ($firmInfo && $firmLawyers): ?>
        <div class="toolbar"><div>
          <h1><?= is_rtl() ? 'احجز استشارة' : 'Book a consultation' ?></h1>
          <p><?= ui_e(is_rtl() ? ($firmInfo['display_name'] ?: $firmInfo['name']) : ($firmInfo['display_name'] ?: $firmInfo['name'])) ?></p>
        </div></div>
        <div class="grid-2">
          <?php foreach ($firmLawyers as $fl): ?>
            <div class="dash-card" style="display:flex;align-items:center;gap:14px">
              <img src="<?= !empty($fl['photo_path']) ? '/' . htmlspecialchars($fl['photo_path']) : '/assets/img/avatar-placeholder.svg' ?>" alt="<?= htmlspecialchars($fl['full_name']) ?>" style="width:56px;height:56px;border-radius:50%;object-fit:cover;background:#EAEFF3" onerror="this.src='/assets/img/avatar-placeholder.svg'">
              <div style="flex:1">
                <strong style="color:var(--navy-dark)"><?= htmlspecialchars($fl['full_name']) ?></strong>
                <div class="muted-sm"><?= htmlspecialchars($fl['specialization'] ?? '') ?></div>
              </div>
              <a href="/booking.php?lawyer_id=<?= (int)$fl['id'] ?>" class="btn btn-primary btn-sm"><?= is_rtl() ? 'احجز' : 'Book' ?></a>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="dash-card" style="text-align:center;padding:40px 24px">
          <div style="font-size:2.2rem;margin-bottom:8px">🔎</div>
          <h3 style="margin:0 0 6px"><?= is_rtl() ? 'المحامي غير متاح للحجز حالياً' : 'This lawyer is not available for booking' ?></h3>
          <p><?= is_rtl() ? 'يمكنك اختيار محامٍ آخر من قائمة المحامين المعتمدين.' : 'You can choose another approved lawyer from the list.' ?></p>
          <a href="/consultations.php" class="btn btn-primary" style="margin-top:14px"><?= is_rtl() ? 'عرض المحامين' : 'View lawyers' ?></a>
        </div>
      <?php endif; ?>
    </div>
    <?php
    require __DIR__ . '/includes/app_footer.php';
    exit;
}
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
$paymentRequired = false;
$paymentStarted = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = in_array($_POST['consultation_type'] ?? '', ['text', 'voice', 'video'], true) ? $_POST['consultation_type'] : 'text';
    $date = (string)($_POST['date'] ?? '');
    $time = (string)($_POST['time'] ?? '');
    $topic = trim((string)($_POST['topic'] ?? ''));
    $scheduledAt = $date . ' ' . $time . ':00';

    // A token the browser invented is not accepted: only the one this session was issued
    // can be claimed, so a crafted POST cannot adopt another client's staged uploads.
    $postedToken = (string)($_POST['attachment_draft_token'] ?? '');
    if ($postedToken !== $draftToken) $postedToken = '';

    // The authoritative price. The customer is charged the breakdown total, and that same
    // number is what the card advertised and what the gateway is asked for — one calculation.
    $pricing = consultation_pricing_for_lawyer($pdo, $lawyer);

    if (!$date || !$time) {
        $error = is_rtl() ? 'يرجى اختيار التاريخ والوقت.' : 'Please choose a date and time.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
        $error = is_rtl() ? 'التاريخ أو الوقت غير صالح.' : 'The date or time is invalid.';
    } elseif (strtotime($scheduledAt) <= time()) {
        $error = is_rtl() ? 'لا يمكن الحجز في وقت ماضٍ. اختر موعداً قادماً.' : 'You cannot book a time in the past. Please choose an upcoming slot.';
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
        $specialtyCol = legal_journey_specialty_column_ready($pdo);
        $durationMinutes = (int)$pricing['duration_minutes'];
        $payable = (float)$pricing['payable'];

        // The insert is handed to booking_create_locked(), which re-checks the slot while
        // holding the (lawyer, slot) lock, so two simultaneous requests cannot both take the
        // same time. Nothing between the check and the INSERT can interleave.
        $insertFn = function () use ($pdo, $lawyerId, $firmId, $type, $scheduledAt, $topic, $payable, $durationMinutes, $najizTypeId, $specialtyCategoryId, $specialtyCol) {
            if ($specialtyCol) {
                $pdo->prepare('INSERT INTO consultations (user_id, lawyer_id, firm_id, consultation_type, scheduled_at, duration_minutes, topic, price, najiz_case_type_id, specialty_category_id) VALUES (?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$_SESSION['user_id'], $lawyerId, $firmId, $type, $scheduledAt, $durationMinutes, $topic, $payable, $najizTypeId, $specialtyCategoryId]);
            } else {
                $pdo->prepare('INSERT INTO consultations (user_id, lawyer_id, firm_id, consultation_type, scheduled_at, duration_minutes, topic, price, najiz_case_type_id) VALUES (?,?,?,?,?,?,?,?,?)')
                    ->execute([$_SESSION['user_id'], $lawyerId, $firmId, $type, $scheduledAt, $durationMinutes, $topic, $payable, $najizTypeId]);
            }
            return (int)$pdo->lastInsertId();
        };

        $created = booking_create_locked($pdo, $lawyerId, $scheduledAt, $insertFn);
        if (!$created['ok']) {
            $error = $created['error'] === 'slot_taken'
                ? (is_rtl() ? 'هذا الموعد لم يعد متاحاً، يرجى اختيار وقت آخر.' : 'This slot is no longer available, please pick another.')
                : (is_rtl() ? 'تعذّر إتمام الحجز، يرجى المحاولة مرة أخرى.' : 'The booking could not be completed, please try again.');
        } else {
            $consultationId = $created['id'];

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

            // A consultation with a price must be paid before it is confirmed. The booking is
            // created 'pending' either way, so the slot is reserved; when payment is required we
            // send the customer to the gateway and only confirm on an independently verified
            // capture (consultation-payment-return.php). A lawyer with no rate keeps the free
            // request-and-confirm flow exactly as before.
            $consultationRow = ['id' => $consultationId, 'price' => $payable, 'lawyer_id' => $lawyerId];
            if (consultation_payment_schema_ready($pdo) && consultation_payment_required($consultationRow)) {
                $paymentRequired = true;
                $custStmt = $pdo->prepare('SELECT full_name, email FROM users WHERE id=?');
                $custStmt->execute([$_SESSION['user_id']]);
                $customer = $custStmt->fetch() ?: [];
                $started = consultation_payment_start($pdo, $consultationRow, [
                    'customer_name' => (string)($customer['full_name'] ?? ''),
                    'customer_email' => (string)($customer['email'] ?? ''),
                    'language' => current_lang(),
                ]);
                if (!empty($started['ok']) && !empty($started['redirect_url'])) {
                    $paymentRedirect = $started['redirect_url'];
                } else {
                    // The booking exists and the lawyer was notified; only the online payment
                    // could not start. This is NOT a confirmed booking — the customer must be
                    // told the appointment is unconfirmed and given a way to pay, or they will
                    // believe the consultation is booked (the defect this execution fixes).
                    $error = is_rtl()
                        ? 'تم حفظ طلب الحجز، لكن تعذّر بدء الدفع الإلكتروني، لذلك لم يتم تأكيد الموعد بعد. يمكنك إكمال الدفع من صفحة الاستشارات.'
                        : 'Your booking request was saved, but online payment could not start, so the appointment is not confirmed yet. You can complete payment from your consultations page.';
                    $corr = (string)($started['correlation_id'] ?? '');
                    if ($corr !== '') $error .= ' ' . (is_rtl() ? 'الرقم المرجعي:' : 'Reference:') . ' ' . $corr;
                }
            }
        }
    }
}

// Redirect to the hosted payment page before the shell prints anything.
if ($paymentRedirect !== null) {
    header('Location: ' . $paymentRedirect);
    exit;
}

require_once __DIR__ . '/includes/app_header.php';

$minDate = date('Y-m-d');
$maxDate = date('Y-m-d', strtotime('+30 days'));
$attachmentLimits = consultation_attachment_limits($pdo);

// The authoritative price breakdown for this lawyer. The wizard, the sticky summary and the
// gateway all read this one object, so the amount shown is the amount charged.
$pricing = consultation_pricing_for_lawyer($pdo, $lawyer);
$priceLabel = $pricing['has_price'] ? money($pricing['total']) : null;
$durationMinutes = (int)$pricing['duration_minutes'];

$extraPageAssets = [
    '<link rel="stylesheet" href="/assets/css/booking.css">',
    '<link rel="stylesheet" href="/assets/css/consultation-attachments.css">',
    '<script src="/assets/js/booking.js" defer></script>',
    '<script src="/assets/js/consultation-attachments.js" defer></script>',
];

// The six wizard steps. Step 4 (review) and 5 (pay) are rendered by the same page; step 6 is
// the confirmation. The indicator is present on every state so the customer always knows
// where they are — the defect the redesign fixes.
$wizardSteps = is_rtl()
    ? ['المحامي', 'النوع', 'الموعد', 'المراجعة', 'الدفع', 'التأكيد']
    : ['Lawyer', 'Type', 'Time', 'Review', 'Payment', 'Confirmation'];
$currentStep = $success ? 6 : 3; // after a successful booking the confirmation is step 6
$typeLabels = is_rtl()
    ? ['text' => 'نصية', 'voice' => 'صوتية', 'video' => 'فيديو']
    : ['text' => 'Text', 'voice' => 'Voice', 'video' => 'Video'];
?>
<div class="app-content bk-has-mobilebar" data-booking-wizard
     data-lawyer-id="<?= $lawyerId ?>" data-endpoint="/api/available_slots.php"
     data-has-hours="<?= $hasAvailability ? '1' : '0' ?>" data-dir="<?= is_rtl() ? 'rtl' : 'ltr' ?>">

  <?php /* The wizard's own title is carried by the progress rail, so the page's one <h1>
           is exposed to assistive tech without adding a heading the design does not show. */ ?>
  <h1 class="sr-only"><?= is_rtl() ? 'احجز استشارة' : 'Book a consultation' ?></h1>

  <nav class="bk-progress" aria-label="<?= is_rtl() ? 'خطوات الحجز' : 'Booking steps' ?>">
    <?php foreach ($wizardSteps as $i => $label): $n = $i + 1;
      $state = $n < $currentStep ? 'is-done' : ($n === $currentStep ? 'is-current' : ''); ?>
      <div class="bk-step <?= $state ?>">
        <span class="bk-step__dot"><?= $n < $currentStep ? '✓' : $n ?></span>
        <span class="bk-step__label"><?= htmlspecialchars($label) ?></span>
      </div>
      <?php if ($n < count($wizardSteps)): ?><span class="bk-step__line"></span><?php endif; ?>
    <?php endforeach; ?>
  </nav>

  <?php if ($success): ?>
    <?php /* ---- Confirmation (step 6) ---- */ ?>
    <div class="dash-card" style="text-align:center;padding:40px 24px">
      <div style="font-size:3rem;line-height:1;margin-bottom:8px"><?= $paymentRequired ? '⏳' : '✅' ?></div>
      <h2 style="margin:0 0 8px"><?= $paymentRequired
        ? (is_rtl() ? 'تم إنشاء الحجز — بانتظار الدفع' : 'Booking created — awaiting payment')
        : (is_rtl() ? 'تم استلام طلب الحجز' : 'Your booking request was received') ?></h2>
      <p style="color:var(--text-muted);max-width:52ch;margin:0 auto 16px">
        <?= $paymentRequired
          ? (is_rtl() ? 'لم يُؤكَّد الموعد بعد. أكمل الدفع لتأكيد الاستشارة.' : 'The appointment is not confirmed yet. Complete payment to confirm.')
          : (is_rtl() ? 'سيتواصل معك المحامي لتأكيد الموعد.' : 'The lawyer will contact you to confirm the appointment.') ?>
      </p>
      <div class="bk-summary" style="max-width:440px;margin:0 auto;text-align:start">
        <div class="bk-summary__row"><span><?= is_rtl() ? 'المحامي' : 'Lawyer' ?></span><span><?= htmlspecialchars($lawyer['full_name']) ?></span></div>
        <div class="bk-summary__row"><span><?= is_rtl() ? 'الموعد' : 'Appointment' ?></span><span class="num"><?= htmlspecialchars($scheduledAt) ?></span></div>
        <div class="bk-summary__row"><span><?= is_rtl() ? 'المدة' : 'Duration' ?></span><span class="num"><?= $durationMinutes ?> <?= is_rtl() ? 'دقيقة' : 'min' ?></span></div>
        <?php if ($priceLabel !== null): ?>
          <div class="bk-summary__row bk-summary__total"><span><?= is_rtl() ? 'المبلغ' : 'Amount' ?></span><span class="num"><?= $priceLabel ?></span></div>
        <?php endif; ?>
      </div>
      <div style="display:flex;gap:12px;justify-content:center;margin-top:20px;flex-wrap:wrap">
        <?php if ($paymentRequired): ?>
          <a class="btn btn-primary" href="/consultation-pay.php?id=<?= (int)$consultationId ?>"><?= is_rtl() ? 'إكمال الدفع' : 'Complete payment' ?></a>
        <?php endif; ?>
        <a class="btn btn-outline" href="/consultations.php"><?= is_rtl() ? 'استشاراتي' : 'My consultations' ?></a>
      </div>
    </div>
  <?php else: ?>
    <div class="bk-layout has-rail">
      <div class="bk-main">

        <div class="form-alert error" data-bk-formerror <?= $error ? '' : 'style="display:none"' ?>><?= $error ? htmlspecialchars($error) : '' ?></div>

        <?php if (consultation_payment_required(['price' => $pricing['payable']])): ?>
          <div class="form-alert" style="background:#E8F1FA;color:#1C4E80">
            <?= is_rtl()
              ? 'هذه استشارة مدفوعة (' . money($pricing['total']) . '). لا يُؤكَّد الموعد إلا بعد نجاح الدفع والتحقق منه على الخادم.'
              : 'This is a paid consultation (' . money($pricing['total']) . '). The appointment is confirmed only after payment is verified on the server.' ?>
          </div>
        <?php endif; ?>
        <?php if (!$hasAvailability): ?>
          <div class="form-alert" style="background:#FFF8E6;color:#8A6D1D"><?= is_rtl() ? 'هذا المحامي لم يحدد أوقات دوام بعد — اختر تاريخاً ووقتاً مبدئياً وسيتواصل معك لتأكيد الموعد.' : "This lawyer hasn't set booking hours yet — pick a tentative date/time and they'll confirm with you." ?></div>
        <?php endif; ?>

        <form method="post" id="bookingForm" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
          <input type="hidden" name="attachment_draft_token" value="<?= htmlspecialchars($draftToken) ?>" data-ca-draft-token>

          <?php /* ---- Step 2: consultation type ---- */ ?>
          <section class="dash-card">
            <h3 class="bk-summary__title"><?= is_rtl() ? 'نوع الاستشارة' : 'Consultation type' ?></h3>
            <div class="bk-types">
              <?php foreach ($typeLabels as $val => $lbl): ?>
                <label class="bk-type">
                  <input type="radio" name="consultation_type" value="<?= $val ?>" <?= $val === 'text' ? 'checked' : '' ?>>
                  <span class="bk-type__ico"><?= $val === 'text' ? '💬' : ($val === 'voice' ? '🎙️' : '🎥') ?></span>
                  <span class="bk-type__name"><?= htmlspecialchars($lbl) ?></span>
                  <span class="bk-type__hint"><?= $val === 'text' ? (is_rtl() ? 'مراسلة مكتوبة' : 'Written') : ($val === 'voice' ? (is_rtl() ? 'مكالمة صوتية' : 'Voice call') : (is_rtl() ? 'مكالمة فيديو' : 'Video call')) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </section>

          <?php /* ---- Step 3: date & time ---- */ ?>
          <section class="dash-card">
            <h3 class="bk-summary__title"><?= is_rtl() ? 'الموعد' : 'Appointment' ?></h3>
            <?php if ($hasAvailability): ?>
              <?php /*
               * The native date/time inputs are the no-JS fallback AND the real form fields.
               * JS turns them into a real calendar + slot grid (see booking.js) and moves the
               * chosen values into them, so the same names post whether or not JS runs. This
               * is why the calendar cannot be "fake": the posted date is the one the server
               * validates against the lawyer's own published hours.
               */ ?>
              <div class="field bk-nojs-only"><label><?= is_rtl() ? 'التاريخ' : 'Date' ?></label>
                <input type="date" name="date" data-bk-date min="<?= $minDate ?>" max="<?= $maxDate ?>" required></div>
              <div class="field bk-nojs-only"><label><?= is_rtl() ? 'الوقت' : 'Time' ?></label>
                <input type="time" name="time" data-bk-time required></div>
              <div class="bk-cal" data-bk-calendar>
                <div class="bk-cal__head">
                  <span class="bk-cal__month" data-bk-month></span>
                  <div class="bk-cal__nav">
                    <button type="button" data-bk-prev aria-label="<?= is_rtl() ? 'الشهر السابق' : 'Previous month' ?>">‹</button>
                    <button type="button" data-bk-next aria-label="<?= is_rtl() ? 'الشهر التالي' : 'Next month' ?>">›</button>
                  </div>
                </div>
                <div class="bk-cal__grid" data-bk-grid></div>
                <div class="bk-cal__legend">
                  <span><i style="background:#EAF6F9;border:1px solid #BFE6EF"></i><?= is_rtl() ? 'متاح' : 'Available' ?></span>
                  <span><i style="background:var(--navy)"></i><?= is_rtl() ? 'المختار' : 'Selected' ?></span>
                </div>
              </div>
              <div class="bk-slots" data-bk-slots>
                <p class="field-hint"><?= is_rtl() ? 'اختر يوماً من التقويم لعرض الأوقات المتاحة.' : 'Pick a day to see available times.' ?></p>
              </div>
            <?php else: ?>
              <?php /* No published hours: a plain date/time the lawyer will confirm. */ ?>
              <div class="field"><label><?= is_rtl() ? 'التاريخ' : 'Date' ?></label>
                <input type="date" name="date" min="<?= $minDate ?>" max="<?= $maxDate ?>" required></div>
              <div class="field"><label><?= is_rtl() ? 'الوقت' : 'Time' ?></label><input type="time" name="time" required></div>
            <?php endif; ?>
          </section>

          <?php /* ---- Details + attachments ---- */ ?>
          <section class="dash-card">
            <h3 class="bk-summary__title"><?= is_rtl() ? 'تفاصيل الاستشارة' : 'Consultation details' ?></h3>
            <div class="field"><label><?= is_rtl() ? 'موضوع الاستشارة' : 'Topic' ?></label><textarea name="topic" rows="3"></textarea></div>
            <?php if ($journeyCategoryName !== null): ?>
              <div class="dash-card" style="margin:0;padding:14px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
                <div>
                  <div style="font-size:.76rem;color:var(--text-muted);font-weight:700"><?= is_rtl() ? 'موضوع الاستشارة المختار' : 'Selected consultation topic' ?></div>
                  <strong style="color:var(--navy-dark)"><?= htmlspecialchars($journeyCategoryName) ?><?php if ($journeyIssueTypeName !== null): ?> — <?= htmlspecialchars($journeyIssueTypeName) ?><?php endif; ?></strong>
                </div>
                <a href="/consultation-journey.php?category_id=<?= (int)$journeyChoice['specialty_category_id'] ?>" class="btn btn-outline btn-sm"><?= is_rtl() ? 'تعديل' : 'Edit' ?></a>
              </div>
            <?php endif; ?>
            <input type="hidden" name="specialty_category_id" value="<?= (int)$journeyCategoryId ?>">
            <input type="hidden" name="issue_type_id" value="<?= (int)$journeyIssueTypeId ?>">
            <?php
            $consultation = null;
            $attachments = [];
            $actor = ['type' => 'client', 'id' => (int)$_SESSION['user_id']];
            require __DIR__ . '/includes/consultation_attachments_panel.php';
            ?>
          </section>

          <button class="btn btn-primary btn-block btn-lg" type="submit"><?= consultation_payment_required(['price' => $pricing['payable']]) ? (is_rtl() ? 'متابعة للدفع' : 'Continue to payment') : t('hero_cta_book') ?></button>
        </form>
      </div>

      <?php /* ---- Sticky summary rail (desktop) / mobile bar ---- */ ?>
      <aside class="bk-summary" aria-label="<?= is_rtl() ? 'ملخّص الحجز' : 'Booking summary' ?>">
        <div class="bk-card__head" style="margin-bottom:12px">
          <img class="bk-card__avatar" src="<?= !empty($lawyer['photo_path']) ? '/' . htmlspecialchars($lawyer['photo_path']) : '/assets/img/avatar-placeholder.svg' ?>" alt="<?= htmlspecialchars($lawyer['full_name']) ?>" onerror="this.src='/assets/img/avatar-placeholder.svg'">
          <div style="min-width:0">
            <h3 class="bk-card__name"><?= htmlspecialchars($lawyer['full_name']) ?></h3>
            <div class="bk-card__spec"><?= htmlspecialchars($lawyer['specialization'] ?? '') ?></div>
          </div>
        </div>
        <h3 class="bk-summary__title"><?= is_rtl() ? 'ملخّص الحجز' : 'Summary' ?></h3>
        <div class="bk-summary__row"><span><?= is_rtl() ? 'التاريخ' : 'Date' ?></span><span class="num" data-bk-summary-date>—</span></div>
        <div class="bk-summary__row"><span><?= is_rtl() ? 'المدة' : 'Duration' ?></span><span class="num"><?= $durationMinutes ?> <?= is_rtl() ? 'دقيقة' : 'min' ?></span></div>
        <?php if ($pricing['has_price']): ?>
          <div class="bk-summary__row"><span><?= is_rtl() ? 'سعر الاستشارة' : 'Consultation price' ?></span><span class="num"><?= money($pricing['base']) ?></span></div>
          <?php if ($pricing['tax'] > 0): ?>
            <div class="bk-summary__row"><span><?= is_rtl() ? 'الضريبة' : 'Tax' ?> (<?= (float)$pricing['tax_percent'] ?>%)</span><span class="num"><?= money($pricing['tax']) ?></span></div>
          <?php endif; ?>
          <div class="bk-summary__row bk-summary__total"><span><?= is_rtl() ? 'الإجمالي' : 'Total' ?></span><span class="num"><?= money($pricing['total']) ?></span></div>
        <?php else: ?>
          <div class="bk-summary__row"><span><?= is_rtl() ? 'السعر' : 'Price' ?></span><span><?= is_rtl() ? 'عند الطلب' : 'On request' ?></span></div>
        <?php endif; ?>
      </aside>
    </div>

    <?php /* Mobile sticky action bar — keeps the CTA visible on small screens. */ ?>
    <div class="bk-mobilebar">
      <div class="bk-mobilebar__amount">
        <small><?= is_rtl() ? 'الإجمالي' : 'Total' ?></small>
        <strong class="num"><?= $priceLabel !== null ? $priceLabel : (is_rtl() ? 'عند الطلب' : 'On request') ?></strong>
      </div>
      <button type="button" class="btn btn-primary" data-bk-mobile-submit><?= consultation_payment_required(['price' => $pricing['payable']]) ? (is_rtl() ? 'متابعة للدفع' : 'Continue') : t('hero_cta_book') ?></button>
    </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/app_footer.php'; ?>
