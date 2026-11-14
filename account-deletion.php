<?php
/**
 * Public account-deletion page — /account-deletion.php (and the /account-deletion URL).
 *
 * Google Play's Data safety form requires a *publicly reachable* account-deletion route
 * that works without signing in. This page is that route: it explains the process, the
 * data that is erased, the data that is retained and why, and offers a real request form
 * that starts a tracked deletion request (`includes/account_deletion_requests.php`).
 *
 * It must never require a login and must never redirect a visitor to the home page or to
 * a sign-in screen. It also honours the emailed verification link (`?verify=…`) and a
 * reference lookup (`?ref=…`), so a visitor can confirm ownership and check status here.
 *
 * The POST handler and every redirect run *above* the header include: `includes/header.php`
 * prints the whole <head> immediately and there is no output buffering, so a redirect
 * issued after it would be dropped ("headers already sent"). See the POST-redirect note
 * in AGENTS.md.
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lang/strings.php';
require_once __DIR__ . '/includes/auth_security.php';
require_once __DIR__ . '/includes/account_deletion.php';
require_once __DIR__ . '/includes/account_deletion_requests.php';

// Apply the language switch here, before anything reads the active direction. The
// header would apply it too, but this page computes its own copy (the form labels, the
// title) *above* the header include, so the switch has to happen first or ?lang=en
// would render Arabic copy on an LTR page.
if (isset($_GET['lang']) && in_array($_GET['lang'], ['ar', 'en'], true)) {
    $_SESSION['lang'] = $_GET['lang'];
}

$rtl = is_rtl();
$lang = $rtl ? 'ar' : 'en';
$T = static function (string $ar, string $en) use ($rtl): string {
    return $rtl ? $ar : $en;
};

// ---- Signed-in role (read of server state only; never from request input) ----------
$viewerType = null;
$viewerId = 0;
if (!empty($_SESSION['lawyer_id'])) {
    $viewerType = 'lawyer';
    $viewerId = (int)$_SESSION['lawyer_id'];
} elseif (!empty($_SESSION['user_id'])) {
    $viewerType = 'client';
    $viewerId = (int)$_SESSION['user_id'];
}

$requestsReady = account_deletion_requests_ready($pdo);

// ---- Verification link (?verify=token) ---------------------------------------------
$verifiedRef = null;
$verifyError = null;
if (isset($_GET['verify'])) {
    $verifyToken = (string)$_GET['verify'];
    $rateKey = auth_rate_limit_ip_key('account-deletion-verify');
    if (auth_rate_limit_remaining_key($pdo, $rateKey) > 0) {
        $verifyError = 'rate_limited';
    } else {
        $vres = account_deletion_verify($pdo, $verifyToken);
        if ($vres['ok']) {
            $verifiedRef = (string)$vres['reference'];
        } else {
            $verifyError = (string)($vres['error'] ?? 'invalid_token');
            // A guessed token is the only reason a valid one fails repeatedly, so count
            // the failure against the IP bucket.
            auth_rate_limit_failure_key($pdo, $rateKey, 10);
        }
    }
}

// ---- POST: submit a request, or the signed-in direct deletion ----------------------
$postError = null;
$postReference = null;
$postDelivered = false;
$postMatched = false;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Invalid request');
    }

    $action = (string)($_POST['action'] ?? 'request');

    if ($action === 'request') {
        // The public request form. Rate-limited by IP so the endpoint cannot be used to
        // flood the queue or enumerate accounts, and answered identically whether or not
        // the identifier matches an account.
        $rateKey = auth_rate_limit_ip_key('account-deletion-request');
        if (auth_rate_limit_remaining_key($pdo, $rateKey) > 0) {
            $postError = 'rate_limited';
        } elseif (!$requestsReady) {
            $postError = 'migration_required';
        } else {
            $identifier = trim((string)($_POST['identifier'] ?? ''));
            $reason = trim((string)($_POST['reason'] ?? ''));
            $res = account_deletion_request_start($pdo, $identifier, $reason);
            if (!$res['ok']) {
                $postError = (string)($res['error'] ?? 'create_failed');
                auth_rate_limit_failure_key($pdo, $rateKey, 8);
            } else {
                $postReference = (string)$res['reference'];
                $postDelivered = (bool)$res['delivered'];
                $postMatched = (bool)$res['matched'];
                // Clear the bucket on success so a genuine user is not blocked by their
                // own first request, while the threshold still stops a script.
                auth_rate_limit_clear_key($pdo, $rateKey);
            }
        }
    } elseif ($action === 'delete_now') {
        // The signed-in direct path. Reuses the same anonymisation as /delete-account.php,
        // /account.php and the mobile API, so all four surfaces cannot drift. The posted
        // id is never trusted: deletion targets the session's own account only.
        if ($viewerType === null) {
            $postError = 'login_required';
        } else {
            $confirm = strtoupper(trim((string)($_POST['confirm'] ?? '')));
            $password = (string)($_POST['password'] ?? '');
            if ($confirm !== 'DELETE') {
                $postError = 'confirm_required';
            } elseif ($password === '') {
                $postError = 'password_required';
            } else {
                $result = account_delete($pdo, $viewerType, $viewerId, $password);
                if ($result['ok']) {
                    header('Location: /login.php?deleted=1');
                    exit;
                }
                $postError = (string)($result['error'] ?? 'delete_failed');
                $GLOBALS['adr_blockers'] = $result['blockers'] ?? [];
            }
        }
    }
}

// ---- Reference lookup (?ref=DEL-…) -------------------------------------------------
$lookupRef = null;
$lookupRow = null;
if (isset($_GET['ref'])) {
    $lookupRef = trim((string)$_GET['ref']);
    if ($lookupRef !== '' && $requestsReady) {
        $lookupRow = account_deletion_request_get($pdo, $lookupRef);
    }
}

// ---- Error messages ----------------------------------------------------------------
$msg = static function (string $code) use ($T): string {
    switch ($code) {
        case 'rate_limited':
            return $T('تم استلام طلبات كثيرة من هذا الجهاز. يرجى المحاولة بعد ١٥ دقيقة أو مراسلة الدعم.',
                      'Too many requests from this device. Please try again in 15 minutes or contact support.');
        case 'migration_required':
            return $T('خدمة طلبات الحذف غير مفعّلة حالياً. يرجى مراسلة الدعم لإتمام الحذف.',
                      'The deletion-request service is not enabled yet. Please contact support to complete deletion.');
        case 'identifier_required':
            return $T('يرجى إدخال بريدك الإلكتروني أو رقم جوالك المسجّل.', 'Enter your registered email or phone number.');
        case 'invalid_identifier':
            return $T('صيغة البريد الإلكتروني أو رقم الجوال غير صحيحة.', 'The email or phone format is not valid.');
        case 'login_required':
            return $T('يجب تسجيل الدخول أولاً لحذف الحساب مباشرة.', 'Please sign in first to delete your account directly.');
        case 'confirm_required':
            return $T('يرجى كتابة كلمة DELETE لتأكيد الحذف.', 'Type DELETE to confirm deletion.');
        case 'password_required':
            return $T('يرجى إدخال كلمة المرور.', 'Please enter your password.');
        case 'wrong_password':
            return $T('كلمة المرور غير صحيحة.', 'Incorrect password.');
        case 'has_open_work':
            return $T('لا يمكن إتمام الحذف الآن لوجود أعمال قائمة.', 'Deletion cannot be completed while work is in progress.');
        case 'delete_failed':
            return $T('تعذّر حذف الحساب. يرجى المحاولة لاحقاً أو مراسلة الدعم.', 'Could not delete the account. Try again later or contact support.');
        case 'account_not_found':
            return $T('الحساب غير موجود.', 'Account not found.');
        default:
            return $T('تعذّر إنشاء الطلب. يرجى المحاولة لاحقاً.', 'Could not create the request. Please try again later.');
    }
};

$pageTitle = $T('حذف الحساب', 'Delete your account');
$pageStyles = ['/assets/css/account-deletion.css'];
require_once __DIR__ . '/includes/header.php';
?>
<section class="section">
  <div class="container adr-wrap">

    <div class="adr-hero">
      <span class="adr-badge"><?= htmlspecialchars($T('متوافق مع متطلبات Google Play', 'Google Play compliant'), ENT_QUOTES) ?></span>
      <h1><?= htmlspecialchars($T('حذف الحساب والبيانات — منصة مواثيق', 'Delete your account and data — Mawatheq'), ENT_QUOTES) ?></h1>
      <p><?= htmlspecialchars($T(
        'توضّح هذه الصفحة كيفية حذف حسابك في تطبيق ومنصة مواثيق، وما سيحدث لبياناتك. لا تحتاج إلى تسجيل الدخول لبدء الطلب.',
        'This page explains how to delete your Mawatheq app and platform account, and what happens to your data. You do not need to sign in to start a request.'
      ), ENT_QUOTES) ?></p>
    </div>

    <?php if ($verifiedRef !== null): ?>
      <div class="adr-card" style="border-color:var(--teal)">
        <div class="form-alert success" style="margin-bottom:12px">
          <?= htmlspecialchars($T('تم تأكيد ملكية الحساب بنجاح. سيتولى فريقنا تنفيذ الحذف وفق سياسة الاحتفاظ بالبيانات، وسنبلغك عند الاكتمال.',
                                  'Account ownership confirmed. Our team will process the deletion under the data-retention policy and notify you when it is complete.'), ENT_QUOTES) ?>
        </div>
        <p><?= htmlspecialchars($T('رقم طلبك', 'Your request number'), ENT_QUOTES) ?></p>
        <span class="adr-ref" dir="ltr"><?= htmlspecialchars($verifiedRef, ENT_QUOTES) ?></span>
        <p class="adr-note"><a href="/account-deletion.php?ref=<?= urlencode($verifiedRef) ?>"><?= htmlspecialchars($T('عرض حالة الطلب', 'View request status'), ENT_QUOTES) ?></a></p>
      </div>
    <?php elseif ($verifyError !== null): ?>
      <div class="adr-card">
        <div class="form-alert error">
          <?= htmlspecialchars($verifyError === 'rate_limited'
            ? $T('محاولات تحقق كثيرة. يرجى المحاولة لاحقاً.', 'Too many verification attempts. Please try again later.')
            : $T('رابط التحقق غير صالح أو منتهي الصلاحية. يمكنك بدء طلب جديد من النموذج بالأسفل.', 'The verification link is invalid or has expired. You can start a new request with the form below.'), ENT_QUOTES) ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($postReference !== null): ?>
      <div class="adr-card" style="border-color:var(--teal)">
        <div class="form-alert success" style="margin-bottom:12px">
          <?= htmlspecialchars($T('تم استلام طلبك. احتفظ برقم الطلب التالي للمتابعة.', 'Your request was received. Keep the request number below to follow up.'), ENT_QUOTES) ?>
        </div>
        <p><?= htmlspecialchars($T('رقم المتابعة', 'Tracking number'), ENT_QUOTES) ?></p>
        <span class="adr-ref" dir="ltr"><?= htmlspecialchars($postReference, ENT_QUOTES) ?></span>
        <p class="adr-note">
          <?= htmlspecialchars($postMatched
            ? ($postDelivered
              ? $T('أرسلنا رابط تأكيد إلى البريد المسجّل على حسابك. افتح الرابط لإثبات ملكية الحساب وتابع الحذف.', 'We sent a confirmation link to the email registered on your account. Open it to prove ownership and continue.')
              : $T('سجّلنا طلبك. إن كان الحساب موجوداً فسنرسل رابط التأكيد إلى البريد المسجّل عليه. إن لم تصلك رسالة، تواصل مع الدعم مع رقم الطلب.', 'Your request was recorded. If the account exists we will send the confirmation link to its registered email. If nothing arrives, contact support with the request number.'))
            : $T('لم نجد حساباً مطابقاً لهذا المُعرّف. إن كنت متأكداً من بياناتك، تواصل مع الدعم مع رقم الطلب.', 'No account matched this identifier. If you are sure of your details, contact support with the request number.'), ENT_QUOTES) ?>
        </p>
      </div>
    <?php endif; ?>

    <?php if ($lookupRef !== null): ?>
      <div class="adr-card">
        <h2><?= htmlspecialchars($T('حالة الطلب', 'Request status'), ENT_QUOTES) ?></h2>
        <?php if ($lookupRow === null): ?>
          <div class="form-alert error"><?= htmlspecialchars($T('لم نجد طلباً بهذا الرقم.', 'No request found with that number.'), ENT_QUOTES) ?></div>
        <?php else: $st = account_deletion_public_status($lookupRow); ?>
          <p><?= htmlspecialchars($T('رقم الطلب', 'Request number'), ENT_QUOTES) ?>: <strong dir="ltr"><?= htmlspecialchars((string)$lookupRow['reference'], ENT_QUOTES) ?></strong></p>
          <p><?= htmlspecialchars($T('الحالة', 'Status'), ENT_QUOTES) ?>: <strong><?= htmlspecialchars($st[$lang], ENT_QUOTES) ?></strong></p>
          <p class="adr-note"><?= htmlspecialchars($T('تاريخ الطلب', 'Requested at'), ENT_QUOTES) ?>: <?= htmlspecialchars((string)$lookupRow['requested_at'], ENT_QUOTES) ?></p>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($postError !== null): ?>
      <div class="adr-card">
        <div class="form-alert error"><?= htmlspecialchars($msg($postError), ENT_QUOTES) ?></div>
        <?php if ($postError === 'has_open_work' && !empty($GLOBALS['adr_blockers'])): ?>
          <ul class="adr-list"><?php foreach ($GLOBALS['adr_blockers'] as $b): ?><li><?= htmlspecialchars((string)$b, ENT_QUOTES) ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <!-- What deletion means -->
    <div class="adr-card">
      <h2><?= htmlspecialchars($T('كيف تحذف حسابك', 'How to delete your account'), ENT_QUOTES) ?></h2>
      <ol class="adr-steps">
        <li><?= htmlspecialchars($T('من داخل التطبيق: «حسابي ← الإعدادات ← حذف حسابي».', 'In the app: “My Account → Settings → Delete my account”.'), ENT_QUOTES) ?></li>
        <li><?= htmlspecialchars($T('من الموقع: سجّل الدخول ثم استخدم نموذج «حذف فوري» بالأسفل.', 'On the website: sign in and use the “Delete now” form below.'), ENT_QUOTES) ?></li>
        <li><?= htmlspecialchars($T('بدون تسجيل دخول: املأ نموذج طلب الحذف بالأسفل؛ سنرسل رابط تأكيد إلى بريدك المسجّل.', 'Without signing in: fill in the deletion-request form below; we email a confirmation link to your registered address.'), ENT_QUOTES) ?></li>
        <li><?= htmlspecialchars($T('بعد تأكيد الملكية يُنفَّذ الحذف وفق سياسة الاحتفاظ بالبيانات، ويصلك إشعار عند الاكتمال.', 'After ownership is confirmed, deletion is carried out under the data-retention policy and you are notified on completion.'), ENT_QUOTES) ?></li>
      </ol>
    </div>

    <!-- Data erased vs retained -->
    <div class="adr-card">
      <h2><?= htmlspecialchars($T('البيانات التي تُحذف والبيانات التي تُحفظ', 'Data erased and data retained'), ENT_QUOTES) ?></h2>
      <div class="adr-cols">
        <div>
          <h3><?= htmlspecialchars($T('يُحذف نهائياً', 'Erased permanently'), ENT_QUOTES) ?></h3>
          <ul class="adr-list">
            <li><?= htmlspecialchars($T('الاسم والبريد الإلكتروني ورقم الجوال.', 'Name, email address and phone number.'), ENT_QUOTES) ?></li>
            <li><?= htmlspecialchars($T('كلمة المرور ومفاتيح الدخول.', 'Password and sign-in credentials.'), ENT_QUOTES) ?></li>
            <li><?= htmlspecialchars($T('جلسات الدخول وكل رموز الأجهزة.', 'Sessions and every device token.'), ENT_QUOTES) ?></li>
            <li><?= htmlspecialchars($T('الإشعارات والتفضيلات الشخصية.', 'Notifications and personal preferences.'), ENT_QUOTES) ?></li>
          </ul>
        </div>
        <div>
          <h3><?= htmlspecialchars($T('يُحفظ لأسباب ملزمة', 'Retained for binding reasons'), ENT_QUOTES) ?></h3>
          <ul class="adr-list keep">
            <li><?= htmlspecialchars($T('الفواتير والسجلات المالية — التزام مالي وضريبي (حتى ١٠ سنوات).', 'Invoices and financial records — accounting and tax obligation (up to 10 years).'), ENT_QUOTES) ?></li>
            <li><?= htmlspecialchars($T('سجلات الاستشارات والقضايا المكتملة التي يعتمد عليها طرف آخر — التزام مهني (حتى ١٠ سنوات).', 'Completed consultations and case records another party relies on — professional duty (up to 10 years).'), ENT_QUOTES) ?></li>
            <li><?= htmlspecialchars($T('سجل الطلب نفسه — إثبات تنفيذ الحذف.', 'The request record itself — proof the deletion was carried out.'), ENT_QUOTES) ?></li>
          </ul>
        </div>
      </div>
      <p class="adr-note"><?= htmlspecialchars($T('تُخزَّن السجلات المحفوظة بدون بياناتك الشخصية. حذف الحساب لا يعني حذف السجلات المالية الملزمة قانوناً، ولا يُعتبر تعطيل الحساب وحده تنفيذاً كاملاً للطلب.',
        'Retained records are stored without your personal data. Deleting an account does not erase records the law requires us to keep, and disabling an account alone is not a completed deletion.'), ENT_QUOTES) ?></p>
    </div>

    <!-- Request form (no login) -->
    <div class="adr-card">
      <h2><?= htmlspecialchars($T('طلب حذف الحساب (بدون تسجيل دخول)', 'Request account deletion (no sign-in)'), ENT_QUOTES) ?></h2>
      <?php if (!$requestsReady): ?>
        <div class="form-alert error"><?= htmlspecialchars($msg('migration_required'), ENT_QUOTES) ?></div>
      <?php else: ?>
      <form method="post" action="/account-deletion.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
        <input type="hidden" name="action" value="request">
        <div class="adr-field">
          <label for="identifier"><?= htmlspecialchars($T('البريد الإلكتروني أو رقم الجوال المسجّل', 'Registered email or phone number'), ENT_QUOTES) ?></label>
          <input type="text" id="identifier" name="identifier" required autocomplete="email"
                 placeholder="<?= htmlspecialchars($T('example@mail.com أو 05xxxxxxxx', 'example@mail.com or 05xxxxxxxx'), ENT_QUOTES) ?>">
        </div>
        <div class="adr-field">
          <label for="reason"><?= htmlspecialchars($T('سبب الحذف (اختياري)', 'Reason for deletion (optional)'), ENT_QUOTES) ?></label>
          <textarea id="reason" name="reason" maxlength="255"></textarea>
        </div>
        <label class="adr-check">
          <input type="checkbox" name="ack" value="1" required>
          <span><?= htmlspecialchars($T('أُقرّ بأنني صاحب الحساب أو مفوَّض به، وأفهم أن الحذف نهائي وأن بعض السجلات المالية والقانونية تُحفظ لأسباب ملزمة.',
            'I confirm I own this account or am authorised for it, and I understand deletion is permanent and that some financial and legal records are retained for binding reasons.'), ENT_QUOTES) ?></span>
        </label>
        <button class="btn btn-primary" type="submit"><?= htmlspecialchars($T('إرسال طلب الحذف', 'Submit deletion request'), ENT_QUOTES) ?></button>
      </form>
      <?php endif; ?>
    </div>

    <!-- Signed-in immediate deletion -->
    <?php if ($viewerType !== null): ?>
      <div class="adr-card" style="border-color:var(--border)">
        <h2><?= htmlspecialchars($T('حذف فوري (أنت مسجّل الدخول)', 'Delete now (you are signed in)'), ENT_QUOTES) ?></h2>
        <p class="adr-note"><?= htmlspecialchars($T('إن كنت مسجّلاً الآن، يمكنك تنفيذ الحذف مباشرة بإدخال كلمة المرور. لا يمكن التراجع عن هذه الخطوة.',
          'Since you are signed in, you can delete immediately by entering your password. This step cannot be undone.'), ENT_QUOTES) ?></p>
        <form method="post" action="/account-deletion.php">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
          <input type="hidden" name="action" value="delete_now">
          <div class="adr-field">
            <label for="password"><?= htmlspecialchars($T('كلمة المرور', 'Password'), ENT_QUOTES) ?></label>
            <input type="password" id="password" name="password" required autocomplete="current-password">
          </div>
          <div class="adr-field">
            <label for="confirm"><?= htmlspecialchars($T('اكتب DELETE للتأكيد', 'Type DELETE to confirm'), ENT_QUOTES) ?></label>
            <input type="text" id="confirm" name="confirm" required autocomplete="off" dir="ltr">
          </div>
          <button class="btn btn-primary" type="submit" style="background:#b91c1c;border-color:#b91c1c"><?= htmlspecialchars($T('حذف حسابي نهائياً', 'Delete my account permanently'), ENT_QUOTES) ?></button>
        </form>
      </div>
    <?php endif; ?>

    <!-- Status lookup -->
    <div class="adr-card">
      <h2><?= htmlspecialchars($T('متابعة طلب', 'Check a request'), ENT_QUOTES) ?></h2>
      <form method="get" action="/account-deletion.php" class="adr-actions" style="align-items:flex-end">
        <div class="adr-field" style="flex:1;min-width:220px;margin-bottom:0">
          <label for="ref"><?= htmlspecialchars($T('رقم الطلب', 'Request number'), ENT_QUOTES) ?></label>
          <input type="text" id="ref" name="ref" placeholder="DEL-XXXXXX" dir="ltr">
        </div>
        <button class="btn btn-outline" type="submit"><?= htmlspecialchars($T('عرض الحالة', 'View status'), ENT_QUOTES) ?></button>
      </form>
    </div>

    <p class="adr-note" style="text-align:center;margin:8px 0 40px">
      <?= htmlspecialchars(SITE_NAME_AR, ENT_QUOTES) ?> · <?= htmlspecialchars(SITE_NAME_EN, ENT_QUOTES) ?> —
      <a href="/privacy.php"><?= htmlspecialchars($T('سياسة الخصوصية', 'Privacy policy'), ENT_QUOTES) ?></a>
    </p>

  </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
