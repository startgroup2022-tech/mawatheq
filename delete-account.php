<?php
/**
 * Public account-deletion page — /delete-account.php
 *
 * Google Play requires a publicly reachable URL that explains how to delete an
 * account, and it must work without signing in. This page therefore has two faces:
 *
 *   - Signed out: the policy. What deletion means, what is erased, what is kept and
 *     why, and how to do it (in the app or on the site). No form, because deletion
 *     needs the account's own password.
 *   - Signed in as a client or a lawyer: the same policy plus the real form, which
 *     calls the same `account_delete()` the in-app route and `/account.php` call, so
 *     the three surfaces cannot drift.
 *
 * The POST handler runs *above* the header include: `includes/header.php` prints the
 * whole <head> immediately and there is no output buffering, so a redirect issued
 * after it would be dropped ("headers already sent") and the form would appear to do
 * nothing. See the POST-redirect note in AGENTS.md.
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lang/strings.php';
require_once __DIR__ . '/includes/account_deletion.php';

$rtl = is_rtl();

// Which role is signed in, if any. The two portals keep separate session keys and
// neither accepts a role from request input, so this is a read of server state only.
$viewerType = null;
$viewerId = 0;
if (!empty($_SESSION['lawyer_id'])) {
    $viewerType = 'lawyer';
    $viewerId = (int)$_SESSION['lawyer_id'];
} elseif (!empty($_SESSION['user_id'])) {
    $viewerType = 'client';
    $viewerId = (int)$_SESSION['user_id'];
}

$error = null;
$blockers = [];
$done = false;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Invalid request');
    }

    // Deletion is only ever performed on the account that owns this session; the
    // posted id is never trusted, so a crafted form cannot delete someone else.
    if ($viewerType === null) {
        $error = $rtl ? 'يجب تسجيل الدخول أولاً لحذف الحساب.' : 'Please sign in first to delete your account.';
    } else {
        $confirm = strtoupper(trim((string)($_POST['confirm'] ?? '')));
        $password = (string)($_POST['password'] ?? '');

        if ($confirm !== 'DELETE') {
            $error = $rtl ? 'يرجى كتابة كلمة DELETE لتأكيد الحذف.' : 'Type DELETE to confirm.';
        } elseif ($password === '') {
            $error = $rtl ? 'يرجى إدخال كلمة المرور.' : 'Please enter your password.';
        } else {
            $result = account_delete($pdo, $viewerType, $viewerId, $password);
            if ($result['ok']) {
                // The session belongs to an account that no longer exists. Clear it
                // and rotate the id so the destroyed session cannot be reused.
                $_SESSION = [];
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_regenerate_id(true);
                }
                $done = true;
            } elseif ($result['error'] === 'has_open_work') {
                // The engine's key is `has_open_work`; `account.php` maps the same one.
                // Guessing `has_blockers` here silently downgraded a named, actionable
                // blocker list to the generic "try again later" message.
                $blockers = $result['blockers'] ?? [];
                $error = $rtl ? 'لا يمكن حذف الحساب الآن لوجود أعمال قائمة.' : 'The account cannot be deleted while work is in progress.';
            } elseif ($result['error'] === 'wrong_password') {
                $error = $rtl ? 'كلمة المرور غير صحيحة.' : 'Incorrect password.';
            } else {
                $error = $rtl ? 'تعذّر إتمام الحذف. يرجى المحاولة لاحقاً أو التواصل مع الدعم.' : 'Deletion could not be completed. Please try again or contact support.';
            }
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>
<section class="section">
  <div class="container" style="max-width:820px">
    <h1><?= $rtl ? 'حذف الحساب' : 'Delete account' ?></h1>
    <p class="lead"><?= $rtl
      ? 'يمكنك حذف حسابك في مواثيق بنفسك، في أي وقت، دون التواصل مع الدعم.'
      : 'You can delete your Mawathq account yourself, at any time, without contacting support.' ?></p>

    <?php if ($done): ?>
      <div class="form-alert success" role="status">
        <strong><?= $rtl ? 'تم حذف الحساب.' : 'Account deleted.' ?></strong>
        <p><?= $rtl
          ? 'تم إلغاء تفعيل حسابك وإزالة بياناتك الشخصية، وأُبطلت جميع جلسات الدخول والأجهزة. لم تعد كلمة المرور صالحة.'
          : 'Your account has been deactivated and your personal data removed. Every session and signed-in device has been revoked, and your password no longer works.' ?></p>
      </div>

    <?php else: ?>

      <?php if ($error !== null): ?>
        <div class="form-alert warning" role="alert"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <?php if ($blockers): ?>
        <div class="form-alert warning" role="alert">
          <strong><?= $rtl ? 'أعمال قائمة يجب إغلاقها أولاً:' : 'Work in progress must be closed first:' ?></strong>
          <ul>
            <?php foreach ($blockers as $b): ?>
              <li><?= htmlspecialchars((string)$b) ?></li>
            <?php endforeach; ?>
          </ul>
          <p><?= $rtl
            ? 'حذف الحساب في هذه الحالة سيأخذ معه ملف قضية أو استشارة يخص طرفاً آخر، لذلك نطلب إغلاقها أولاً.'
            : 'Deleting now would take a case file or consultation belonging to another party with it, so we ask you to close it first.' ?></p>
        </div>
      <?php endif; ?>

      <h2><?= $rtl ? 'ماذا يحدث عند الحذف؟' : 'What happens when you delete' ?></h2>
      <p><?= $rtl
        ? 'نلغي تفعيل الحساب ونستبدل بياناتك الشخصية — الاسم والبريد الإلكتروني والجوال وكلمة المرور ورموز التحقق والاستعادة — فلا يعود الحساب قابلاً للدخول. ونُبطل جميع أجهزة الدخول والرموز الصادرة للتطبيق.'
        : 'We deactivate the account and overwrite your personal data — name, email, phone, password, and verification and reset tokens — so the account can no longer be signed into. Every signed-in device and every token issued to the app is revoked.' ?></p>
      <p><?= $rtl
        ? 'تبقى السجلات المالية والقانونية المرتبطة بأطراف أخرى — فاتورة صدرت لك، أو استشارة دفع أحدهم مقابلها — محفوظة كما يقتضي النظام، لكن دون بياناتك الشخصية. لا يمكن حذف هذه السجلات لأنها سجلات طرف آخر أيضاً، وحذفها يُفسد دفاتر المكتب.'
        : 'Financial and legal records tied to other parties — an invoice issued to you, a consultation someone paid for — are retained as the law requires, but without your personal data. These cannot be removed because they are the other party’s records too, and deleting them would corrupt the firm’s books.' ?></p>

      <h2><?= $rtl ? 'كيف أحذف حسابي؟' : 'How to delete your account' ?></h2>
      <?php if ($viewerType === null): ?>
        <ol>
          <li><?= $rtl
            ? 'سجّل الدخول إلى حسابك من <a href="/login.php">صفحة دخول العملاء</a> أو <a href="/lawyer-login.php">صفحة دخول المحامين</a>.'
            : 'Sign in from the <a href="/login.php">client login</a> or the <a href="/lawyer-login.php">lawyer login</a>.' ?></li>
          <li><?= $rtl
            ? 'افتح صفحة «حسابي» واختر «حذف الحساب»، أو عد إلى هذه الصفحة وأكمل النموذج الذي سيظهر هنا.'
            : 'Open “My Account” and choose “Delete account”, or return to this page and complete the form that will appear here.' ?></li>
          <li><?= $rtl
            ? 'أكّد كلمة المرور واكتب كلمة DELETE. نطلب كلمة المرور لأن وجود جلسة مفتوحة على جهاز مشترك لا يكفي لإنهاء الحساب.'
            : 'Confirm your password and type DELETE. We ask for the password because an open session on a shared device is not enough to destroy an account.' ?></li>
        </ol>
        <p><?= $rtl
          ? 'الحذف متاح أيضاً داخل تطبيق مواثيق من تبويب «حسابي»، ومن صفحة «حسابي» في موقع الويب للعملاء والمحامين.'
          : 'Deletion is also available inside the Mawathq app from the Account tab, and from “My Account” on the website for both clients and lawyers.' ?></p>
        <p><a class="btn btn-primary" href="/login.php"><?= $rtl ? 'تسجيل الدخول' : 'Sign in' ?></a></p>
      <?php else: ?>
        <p><?= $rtl
          ? 'أنت مسجّل الدخول الآن. لإتمام الحذف أدخل كلمة المرور واكتب كلمة DELETE.'
          : 'You are signed in. To complete the deletion, enter your password and type DELETE.' ?></p>
        <form method="post" class="dash-card" style="max-width:560px">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
          <p style="margin-bottom:var(--s-2,10px)">
            <label for="da-password"><?= $rtl ? 'كلمة المرور' : 'Password' ?></label>
            <input type="password" id="da-password" name="password" required autocomplete="current-password"
                   style="width:100%">
          </p>
          <p style="margin-bottom:var(--s-2,10px)">
            <label for="da-confirm"><?= $rtl ? 'اكتب DELETE للتأكيد' : 'Type DELETE to confirm' ?></label>
            <input type="text" id="da-confirm" name="confirm" required autocomplete="off" autocapitalize="characters"
                   style="width:100%">
          </p>
          <button type="submit" class="btn btn-primary"><?= $rtl ? 'حذف حسابي نهائياً' : 'Delete my account permanently' ?></button>
        </form>
        <p style="color:var(--text-muted);font-size:.85rem"><?= $rtl
          ? 'هذا الإجراء لا رجعة فيه. لا يمكن استعادة الحساب بعد الحذف.'
          : 'This cannot be undone. The account cannot be restored after deletion.' ?></p>
      <?php endif; ?>

      <h2><?= $rtl ? 'ماذا لو كان لديّ عمل قائم؟' : 'What if I have work in progress?' ?></h2>
      <p><?= $rtl
        ? 'إذا كان لديك قضية مفتوحة أو استشارة قادمة، أو فاتورة غير مسددة (للمحامين)، فسنخبرك بها بالاسم ونطلب إغلاقها أولاً. هذا ليس رفضاً للحذف، بل حماية لملف طرف آخر.'
        : 'If you have an open case, an upcoming consultation, or an unpaid invoice (for lawyers), we will name it and ask you to close it first. This is not a refusal to delete — it protects the other party’s file.' ?></p>

      <h2><?= $rtl ? 'أسئلة أخرى' : 'Other questions' ?></h2>
      <p><?= $rtl
        ? 'لمزيد من التفاصيل عن البيانات التي نجمعها وكيفية معالجتها، راجع <a href="/privacy.php">سياسة الخصوصية</a>. ويمكنك طلب تصدير بياناتك من صفحة «حسابي» قبل الحذف.'
        : 'For more on the data we collect and how it is processed, see our <a href="/privacy.php">privacy policy</a>. You may export your data from “My Account” before deleting.' ?></p>
    <?php endif; ?>
  </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
