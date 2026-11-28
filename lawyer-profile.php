<?php
/**
 * Public lawyer profile (Execution 72).
 *
 * The card links on the directory and the journey point here; before this page existed
 * they answered 404. It is the anchor for the customer features: favourite, verified
 * ratings, the consultation modes, the office address, the contact request and the
 * "notify me when a slot opens" alert.
 *
 * Every write is a POST handled ABOVE the shell include, because includes/app_header.php
 * prints the <head> immediately with no output buffering — a header() redirect issued
 * after it is dropped and the form silently does nothing (see AGENTS.md).
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lang/strings.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/legal_journey.php';
require_once __DIR__ . '/includes/journey_ui.php';
require_once __DIR__ . '/includes/customer_features.php';
require_once __DIR__ . '/includes/currency.php';
require_once __DIR__ . '/includes/specialization_field.php';
require_once __DIR__ . '/includes/cities.php';

$clientId = (int)$_SESSION['user_id'];
$lawyerId = (int)($_GET['lawyer_id'] ?? $_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? '')) { http_response_code(403); exit('Invalid CSRF token'); }
    $action = (string)($_POST['action'] ?? '');
    $redirect = '/lawyer-profile.php?id=' . $lawyerId;

    if ($action === 'toggle_favorite') {
        favorite_toggle($pdo, $clientId, $lawyerId);
        header('Location: ' . $redirect . '&saved=1');
        exit;
    }
    if ($action === 'subscribe_alert') {
        $res = availability_alert_subscribe($pdo, $clientId, $lawyerId);
        header('Location: ' . $redirect . ($res['ok'] ? '&alert=on' : '&alert=error'));
        exit;
    }
    if ($action === 'cancel_alert') {
        availability_alert_cancel($pdo, $clientId, $lawyerId);
        header('Location: ' . $redirect . '&alert=off');
        exit;
    }
    if ($action === 'contact') {
        $res = contact_request_create($pdo, $clientId, $lawyerId, $_POST['subject'] ?? '', (string)($_POST['message'] ?? ''));
        header('Location: ' . $redirect . '&contact=' . ($res['ok'] ? 'sent' : 'error'));
        exit;
    }
    if ($action === 'review') {
        $res = review_create($pdo, $clientId, (int)($_POST['consultation_id'] ?? 0), (int)($_POST['rating'] ?? 0), $_POST['comment'] ?? '');
        header('Location: ' . $redirect . '&review=' . ($res['ok'] ? 'saved' : $res['error']));
        exit;
    }
    header('Location: ' . $redirect);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM lawyers WHERE id=? AND status='approved'");
$stmt->execute([$lawyerId]);
$lawyer = $stmt->fetch();
if (!$lawyer) { http_response_code(404); require __DIR__ . '/includes/firm_not_found.php'; exit; }

$activePage = '';
require_once __DIR__ . '/includes/app_header.php';

$lw = legal_journey_hydrate_lawyer($pdo, $lawyer);
$isFavorite = favorite_exists($pdo, $clientId, $lawyerId);
$alert = availability_alert_for($pdo, $clientId, $lawyerId);
$alertActive = $alert && $alert['status'] === 'active';
$ratingSummary = review_summary_for_lawyer($pdo, $lawyerId);
$reviews = reviews_for_lawyer($pdo, $lawyerId, 20);
$favCount = favorite_count_for_lawyer($pdo, $lawyerId);
$modes = lawyer_consultation_modes($lawyer);
$languages = lawyer_languages_labels($lawyer['languages'] ?? null);
$reviewable = reviewable_consultations($pdo, $clientId);
// Only this lawyer's completed consultations can be rated from this page.
$reviewableHere = array_values(array_filter($reviewable, fn($c) => (int)$c['lawyer_id'] === $lawyerId));
$csrf = csrf_token();
$flash = [
    'saved'   => isset($_GET['saved']) ? (is_rtl() ? 'تم التحديث.' : 'Updated.') : null,
    'alert'   => ['on' => is_rtl() ? 'سنُشعرك عند توفّر موعد.' : "We'll notify you when a slot opens.", 'off' => is_rtl() ? 'تم إلغاء التنبيه.' : 'Alert cancelled.', 'error' => is_rtl() ? 'تعذّر حفظ التنبيه.' : 'Could not save the alert.'][$_GET['alert'] ?? ''] ?? null,
    'contact' => ['sent' => is_rtl() ? 'تم إرسال طلب التواصل.' : 'Contact request sent.', 'error' => is_rtl() ? 'تعذّر إرسال الطلب.' : 'Could not send the request.'][$_GET['contact'] ?? ''] ?? null,
    'review'  => ['saved' => is_rtl() ? 'شكراً لتقييمك.' : 'Thank you for your rating.', 'not_completed' => is_rtl() ? 'لا يمكن التقييم إلا بعد اكتمال الاستشارة.' : 'You can only rate a completed consultation.', 'already_reviewed' => is_rtl() ? 'تم تقييم هذه الاستشارة مسبقًا.' : 'This consultation is already rated.', 'not_found' => is_rtl() ? 'الاستشارة غير موجودة.' : 'Consultation not found.'][$_GET['review'] ?? ''] ?? null,
];
?>
<main class="app-main" id="main">
  <div class="container">

    <?php foreach ($flash as $msg): if ($msg): ?>
      <div class="form-alert success" role="status"><?= htmlspecialchars($msg) ?></div>
    <?php endif; endforeach; ?>

    <div class="lp-hero">
      <img class="lp-avatar" src="<?= journey_avatar($lawyer['photo_path']) ?>" alt="<?= htmlspecialchars($lawyer['full_name']) ?>" onerror="this.src='/assets/img/avatar-placeholder.svg'">
      <div class="lp-who">
        <h1><?= htmlspecialchars($lawyer['full_name']) ?>
          <?php if ($ratingSummary['count'] > 0): ?><span class="lp-verified" title="<?= is_rtl() ? 'تقييمات من استشارات مكتملة' : 'Ratings from completed consultations' ?>">✓ <?= is_rtl() ? 'موثّق' : 'Verified' ?></span><?php endif; ?>
        </h1>
        <div class="lp-sub">
          <?php $spec = lawyer_specialization_display($lawyer); if ($spec !== ''): ?><?= htmlspecialchars($spec) ?> · <?php endif; ?>
          <?= (int)$lawyer['years_experience'] ?> <?= is_rtl() ? 'سنة خبرة' : 'yrs experience' ?>
        </div>
        <?php if (!empty($lw['location'])): ?><div class="lp-sub"><span aria-hidden="true">📍</span> <?= htmlspecialchars($lw['location']) ?></div><?php endif; ?>
        <div class="lp-badges">
          <?php if ($ratingSummary['count'] > 0): ?>
            <span class="lp-rating" aria-label="<?= is_rtl() ? 'متوسط التقييم' : 'Average rating' ?>">
              ★ <?= number_format((float)$ratingSummary['average'], 1) ?> <small>(<?= (int)$ratingSummary['count'] ?>)</small>
            </span>
          <?php else: ?>
            <span class="lp-rating none"><?= is_rtl() ? 'لا توجد تقييمات بعد' : 'No ratings yet' ?></span>
          <?php endif; ?>
          <?php foreach ($modes as $m): ?>
            <span class="lp-chip"><?= htmlspecialchars(is_rtl() ? $m['label_ar'] : $m['label_en']) ?></span>
          <?php endforeach; ?>
          <?php if ($favCount > 0): ?><span class="lp-chip subtle">♡ <?= (int)$favCount ?></span><?php endif; ?>
        </div>
        <?php if ($languages): ?>
          <div class="lp-sub"><span aria-hidden="true">🗣️</span> <?= htmlspecialchars(implode('، ', $languages)) ?></div>
        <?php endif; ?>
      </div>

      <div class="lp-actions">
        <a href="/booking.php?lawyer_id=<?= (int)$lawyerId ?>" class="btn btn-primary"><?= is_rtl() ? 'احجز استشارة' : 'Book consultation' ?></a>
        <form method="post" style="display:inline">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="action" value="toggle_favorite">
          <button type="submit" class="btn btn-outline" aria-pressed="<?= $isFavorite ? 'true' : 'false' ?>">
            <?= $isFavorite ? '♥ ' . (is_rtl() ? 'في المفضلة' : 'Favourite') : '♡ ' . (is_rtl() ? 'أضف للمفضلة' : 'Add to favourites') ?>
          </button>
        </form>
        <button type="button" class="btn btn-outline" data-contact-toggle><?= is_rtl() ? 'تواصل' : 'Contact' ?></button>
        <?php if ($alertActive): ?>
          <form method="post" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="cancel_alert">
            <button type="submit" class="btn btn-ghost"><?= is_rtl() ? 'إلغاء التنبيه' : 'Cancel alert' ?></button>
          </form>
        <?php else: ?>
          <button type="button" class="btn btn-ghost" data-alert-toggle><?= is_rtl() ? 'أبلغني عند إتاحة المواعيد' : 'Notify me when slots open' ?></button>
        <?php endif; ?>
      </div>
    </div>

    <div class="lp-grid">
      <section class="lp-card">
        <h2><?= is_rtl() ? 'نبذة' : 'About' ?></h2>
        <p><?= nl2br(htmlspecialchars((string)($lawyer['bio'] ?: (is_rtl() ? 'لا توجد نبذة منشورة.' : 'No bio published.')))) ?></p>
        <?php if ($lw['availability']['has_published_hours']): ?>
          <h3><?= is_rtl() ? 'أقرب الأوقات المتاحة' : 'Next available times' ?></h3>
          <?php if (!empty($lw['availability']['next_slots'])): ?>
            <div class="lp-slots">
              <?php foreach ($lw['availability']['next_slots'] as $s): ?>
                <span class="lp-chip ltr"><?= htmlspecialchars($s['date']) ?> <?= htmlspecialchars($s['time']) ?></span>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <p class="muted-sm"><?= is_rtl() ? 'لا توجد أوقات متاحة قريبًا — استخدم زر التنبيه.' : 'No slots open soon — use the alert button.' ?></p>
          <?php endif; ?>
        <?php endif; ?>

        <h3><?= is_rtl() ? 'طريقة الاستشارة' : 'How to consult' ?></h3>
        <ul class="lp-modes">
          <?php foreach ($modes as $m): ?>
            <li>
              <strong><?= htmlspecialchars(is_rtl() ? $m['label_ar'] : $m['label_en']) ?></strong>
              <?php if ($m['key'] === 'office' && !empty($lw['office_address'])): ?>
                — <span><?= htmlspecialchars($lw['office_address']) ?></span>
              <?php elseif ($m['key'] === 'office'): ?>
                — <span class="muted-sm"><?= is_rtl() ? 'يُحدَّد العنوان عند التأكيد.' : 'Address confirmed on booking.' ?></span>
              <?php else: ?>
                — <span class="muted-sm"><?= is_rtl() ? 'جلسة مرئية/صوتية عبر المنصة.' : 'Video/voice session on the platform.' ?></span>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
        <p class="muted-sm"><?= is_rtl() ? 'لأمان الطرفين، تبقى بيانات التواصل داخل المنصة ولا تُنشر.' : 'For both parties’ safety, contact details stay inside the platform.' ?></p>
      </section>

      <section class="lp-card">
        <h2><?= is_rtl() ? 'التقييمات' : 'Ratings' ?></h2>
        <?php if ($ratingSummary['count'] === 0): ?>
          <p class="muted-sm"><?= is_rtl() ? 'لا توجد تقييمات بعد. تُنشر التقييمات من استشارات مكتملة فقط.' : 'No ratings yet. Ratings come only from completed consultations.' ?></p>
        <?php else: ?>
          <div class="lp-rating-summary">
            <div class="lp-rating-big"><?= number_format((float)$ratingSummary['average'], 1) ?><small>/5</small></div>
            <div class="lp-rating-bars">
              <?php foreach ([5,4,3,2,1] as $star): $c = $ratingSummary['distribution'][$star]; $pct = $ratingSummary['count'] > 0 ? round($c / $ratingSummary['count'] * 100) : 0; ?>
                <div class="lp-bar"><span><?= $star ?>★</span><span class="lp-bar-track"><span class="lp-bar-fill" style="width:<?= (int)$pct ?>%"></span></span><span class="lp-bar-count"><?= (int)$c ?></span></div>
              <?php endforeach; ?>
            </div>
          </div>
          <ul class="lp-reviews">
            <?php foreach ($reviews as $rv): ?>
              <li>
                <div class="lp-review-head"><strong><?= htmlspecialchars($rv['display_name']) ?></strong> <span class="lp-stars" aria-label="<?= (int)$rv['rating'] ?> / 5"><?= str_repeat('★', (int)$rv['rating']) . str_repeat('☆', 5 - (int)$rv['rating']) ?></span> <span class="muted-sm"><?= date('Y-m-d', strtotime($rv['created_at'])) ?></span></div>
                <?php if (!empty($rv['comment'])): ?><p><?= nl2br(htmlspecialchars($rv['comment'])) ?></p><?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>
    </div>

    <?php if ($reviewableHere): ?>
      <section class="lp-card" id="rate">
        <h2><?= is_rtl() ? 'قيّم استشارة مكتملة' : 'Rate a completed consultation' ?></h2>
        <p class="muted-sm"><?= is_rtl() ? 'يمكنك تقييم كل استشارة مكتملة مرة واحدة.' : 'Each completed consultation can be rated once.' ?></p>
        <?php foreach ($reviewableHere as $c): ?>
          <form method="post" class="lp-review-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="review">
            <input type="hidden" name="consultation_id" value="<?= (int)$c['id'] ?>">
            <div class="lp-review-target"><?= htmlspecialchars((string)($c['topic'] ?: (is_rtl() ? 'استشارة' : 'Consultation'))) ?> · <?= htmlspecialchars((string)$c['lawyer_name']) ?></div>
            <label class="lp-rate-label"><?= is_rtl() ? 'التقييم' : 'Rating' ?>
              <select name="rating" required>
                <?php for ($i = 5; $i >= 1; $i--): ?><option value="<?= $i ?>"><?= str_repeat('★', $i) ?></option><?php endfor; ?>
              </select>
            </label>
            <textarea name="comment" rows="2" maxlength="2000" placeholder="<?= is_rtl() ? 'تعليق (اختياري)' : 'Comment (optional)' ?>"></textarea>
            <button type="submit" class="btn btn-primary btn-sm"><?= is_rtl() ? 'إرسال التقييم' : 'Submit rating' ?></button>
          </form>
        <?php endforeach; ?>
      </section>
    <?php endif; ?>
  </div>

  <!-- Contact sheet (progressive enhancement; the form is a normal POST) -->
  <div class="lp-modal" data-contact-modal hidden>
    <div class="lp-modal-panel" role="dialog" aria-modal="true" aria-label="<?= is_rtl() ? 'تواصل مع المحامي' : 'Contact the lawyer' ?>">
      <button type="button" class="lp-modal-close" data-contact-close aria-label="<?= is_rtl() ? 'إغلاق' : 'Close' ?>">×</button>
      <h2><?= is_rtl() ? 'تواصل مع ' . htmlspecialchars($lawyer['full_name']) : 'Contact ' . htmlspecialchars($lawyer['full_name']) ?></h2>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="contact">
        <label><?= is_rtl() ? 'الموضوع' : 'Subject' ?>
          <input type="text" name="subject" maxlength="150" value="">
        </label>
        <label><?= is_rtl() ? 'الرسالة' : 'Message' ?>
          <textarea name="message" rows="5" required minlength="5" maxlength="4000"></textarea>
        </label>
        <button type="submit" class="btn btn-primary"><?= is_rtl() ? 'إرسال الطلب' : 'Send request' ?></button>
      </form>
    </div>
  </div>

  <!-- Alert confirmation (an explicit confirm, not an immediate silent toggle) -->
  <div class="lp-modal" data-alert-modal hidden>
    <div class="lp-modal-panel" role="dialog" aria-modal="true" aria-label="<?= is_rtl() ? 'تنبيه توفر المواعيد' : 'Availability alert' ?>">
      <button type="button" class="lp-modal-close" data-alert-close aria-label="<?= is_rtl() ? 'إغلاق' : 'Close' ?>">×</button>
      <h2><?= is_rtl() ? 'إشعار عند توفّر موعد' : 'Notify me when a slot opens' ?></h2>
      <p><?= is_rtl() ? 'سنرسل لك إشعارًا داخل المنصة عند إتاحة موعد لدى هذا المحامي. لن يتم حجز أي موعد تلقائيًا.' : 'We will send you an in-app notification when this lawyer opens a slot. Nothing is booked automatically.' ?></p>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="subscribe_alert">
        <button type="submit" class="btn btn-primary"><?= is_rtl() ? 'تأكيد التنبيه' : 'Confirm alert' ?></button>
      </form>
    </div>
  </div>
</main>
<script src="<?= asset_url('/assets/js/lawyer-profile.js') ?>" defer></script>
<?php require_once __DIR__ . '/includes/app_footer.php'; ?>
