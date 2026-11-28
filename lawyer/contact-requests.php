<?php
/**
 * Lawyer inbox for customer contact requests (Execution 72).
 *
 * A customer can press «تواصل مع المحامي» on a public profile and leave a short message.
 * The message stays inside the platform — the customer's own email and phone are shown
 * (they chose to make contact), and the lawyer replies through the platform or the
 * linked consultation, so no private contact detail is exposed either way.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/lawyer_guard.php';
require_once __DIR__ . '/../includes/customer_features.php';

$lawyerId = (int)$_SESSION['lawyer_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? '')) { http_response_code(403); exit('Invalid CSRF token'); }
    if (($_POST['action'] ?? '') === 'mark_read') {
        contact_request_mark_read($pdo, $lawyerId, (int)($_POST['request_id'] ?? 0));
    }
    header('Location: /lawyer/contact-requests.php');
    exit;
}

$activeLawyer = 'contact-requests';
require_once __DIR__ . '/includes/lawyer_header.php';

$requests = contact_requests_for_lawyer($pdo, $lawyerId);
$unread = contact_requests_unread_count($pdo, $lawyerId);
$csrf = csrf_token();
?>
<div class="app-content">
  <h1><?= is_rtl() ? 'طلبات التواصل' : 'Contact requests' ?></h1>
  <p style="margin-bottom:20px">
    <?= is_rtl()
      ? 'رسائل العملاء من صفحتك العامة. ردّ عبر المنصة أو حوّل الطلب إلى استشارة.'
      : 'Messages from clients on your public profile. Reply in-platform or turn the request into a consultation.' ?>
    <?php if ($unread > 0): ?>
      <span class="status-pending" style="margin-inline-start:8px"><?= (int)$unread ?> <?= is_rtl() ? 'جديد' : 'new' ?></span>
    <?php endif; ?>
  </p>

  <?php if (empty($requests)): ?>
    <div class="dash-card" style="text-align:center;padding:40px 24px">
      <div style="font-size:2.2rem;margin-bottom:8px">📥</div>
      <h3 style="margin:0 0 6px"><?= is_rtl() ? 'لا توجد طلبات تواصل بعد' : 'No contact requests yet' ?></h3>
      <p><?= is_rtl() ? 'ستظهر هنا رسائل العملاء من صفحتك العامة.' : 'Customer messages from your public profile appear here.' ?></p>
    </div>
  <?php else: ?>
    <div class="cr-list">
      <?php foreach ($requests as $r): ?>
        <div class="cr-item<?= $r['status'] === 'new' ? ' unread' : '' ?>">
          <div class="cr-head">
            <div>
              <strong><?= htmlspecialchars((string)$r['client_name']) ?></strong>
              <?php if ($r['status'] === 'new'): ?><span class="cr-dot" aria-label="<?= is_rtl() ? 'جديد' : 'new' ?>"></span><?php endif; ?>
              <div class="muted-sm"><?= htmlspecialchars((string)$r['created_at']) ?></div>
            </div>
            <div class="cr-actions">
              <a class="btn btn-outline btn-sm" href="/lawyer/consultations.php"><?= is_rtl() ? 'الاستشارات' : 'Consultations' ?></a>
              <?php if ($r['status'] === 'new'): ?>
                <form method="post" style="display:inline">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                  <input type="hidden" name="action" value="mark_read">
                  <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                  <button class="btn btn-ghost btn-sm" type="submit"><?= is_rtl() ? 'تعليم كمقروء' : 'Mark read' ?></button>
                </form>
              <?php endif; ?>
            </div>
          </div>
          <?php if (!empty($r['subject'])): ?>
            <div class="cr-subject"><?= htmlspecialchars((string)$r['subject']) ?></div>
          <?php endif; ?>
          <p class="cr-body"><?= nl2br(htmlspecialchars((string)$r['message'])) ?></p>
          <div class="cr-contact muted-sm">
            <?php if (!empty($r['client_email'])): ?><span>✉️ <?= htmlspecialchars((string)$r['client_email']) ?></span><?php endif; ?>
            <?php if (!empty($r['client_phone'])): ?><span>📱 <span class="ltr"><?= htmlspecialchars((string)$r['client_phone']) ?></span></span><?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/lawyer_footer.php'; ?>
