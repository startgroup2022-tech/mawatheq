<?php
// The POST handlers run before the shell is included. `includes/app_header.php`
// prints the whole <head> and sidebar immediately with no output buffering, so a
// header() redirect issued afterwards is dropped and the form silently does nothing
// (see the "POST-redirect pages" note in AGENTS.md).
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/account_deletion.php';
require_once __DIR__ . '/includes/phone.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_account') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }
    // A typed confirmation is a second, independent guard: the `pattern` attribute
    // is client-side only, so the same check has to run here.
    if (strtoupper(trim((string)($_POST['confirm'] ?? ''))) !== 'DELETE') {
        $_SESSION['account_delete_error'] = 'confirm_required';
        header('Location: /account.php');
        exit;
    }
    $result = account_delete($pdo, 'client', (int)$_SESSION['user_id'], (string)($_POST['password'] ?? ''));
    if ($result['ok']) {
        // The account is anonymised and every token revoked; drop the session too.
        $_SESSION = [];
        session_destroy();
        header('Location: /login.php?deleted=1');
        exit;
    }
    $_SESSION['account_delete_error'] = $result['error'];
    $_SESSION['account_delete_blockers'] = $result['blockers'] ?? [];
    header('Location: /account.php');
    exit;
}

$activePage = 'profile';
require_once __DIR__ . '/includes/app_header.php';

$stmt = $pdo->prepare('SELECT * FROM users WHERE id=?');
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();
$saved = false;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['full_name'] ?? '');
    $phoneResult = validate_phone($_POST['phone_dial'] ?? '+966', $_POST['phone_local'] ?? '');
    if (!$phoneResult['ok']) {
        $error = $phoneResult['error'];
    } else {
        $pdo->prepare('UPDATE users SET full_name=?, phone=? WHERE id=?')->execute([$name, $phoneResult['value'], $user['id']]);
        $_SESSION['user_name'] = $name;
        $user['full_name'] = $name; $user['phone'] = $phoneResult['value'];
        $saved = true;
    }
}
$phoneParts = split_phone($user['phone'] ?? '');

$deleteError = $_SESSION['account_delete_error'] ?? null;
$deleteBlockers = $_SESSION['account_delete_blockers'] ?? [];
unset($_SESSION['account_delete_error'], $_SESSION['account_delete_blockers']);
$deleteMessages = [
    'wrong_password'   => is_rtl() ? 'كلمة المرور غير صحيحة.' : 'That password is not correct.',
    'has_open_work'    => is_rtl() ? 'لا يمكن حذف الحساب الآن لوجود أعمال قائمة.' : 'The account cannot be deleted while work is in progress.',
    'account_not_found' => is_rtl() ? 'الحساب غير موجود.' : 'Account not found.',
    'delete_failed'    => is_rtl() ? 'تعذّر حذف الحساب. حاول لاحقاً.' : 'The account could not be deleted. Try again later.',
    'missing_password' => is_rtl() ? 'يرجى إدخال كلمة المرور.' : 'Please enter your password.',
    'confirm_required' => is_rtl() ? 'يرجى كتابة كلمة DELETE للتأكيد.' : 'Please type DELETE to confirm.',
];
?>
<div class="app-content">
  <h2><?= is_rtl() ? 'حسابي' : 'My Account' ?></h2>
  <?php if ($saved): ?><div class="form-alert success"><?= is_rtl() ? 'تم الحفظ بنجاح.' : 'Saved successfully.' ?></div><?php endif; ?>
  <?php if ($error): ?><div class="form-alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <form class="dash-card" method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
    <div class="field"><label><?= is_rtl() ? 'الاسم الكامل' : 'Full name' ?></label><input type="text" name="full_name" value="<?= htmlspecialchars($user['full_name']) ?>"></div>
    <div class="field"><label><?= is_rtl() ? 'البريد الإلكتروني' : 'Email' ?></label><input type="email" value="<?= htmlspecialchars($user['email']) ?>" disabled></div>
    <div class="field">
      <label><?= is_rtl() ? 'رقم الجوال' : 'Phone' ?></label>
      <?php render_phone_field('phone', $phoneParts['dial'], $phoneParts['local']); ?>
    </div>
    <div class="field"><label><?= is_rtl() ? 'نوع الحساب' : 'Account type' ?></label><input type="text" value="<?= htmlspecialchars($user['account_type']) ?>" disabled></div>
    <button class="btn btn-primary" type="submit"><?= is_rtl() ? 'حفظ التغييرات' : 'Save changes' ?></button>
  </form>

  <!-- Account deletion. Google Play requires an in-app route, and privacy.php
       promises one. It is deliberately behind a password prompt and a typed
       confirmation: a stray click must not destroy an account. -->
  <div class="dash-card" style="margin-top:22px;border:1px solid var(--danger,#c0392b)">
    <h3 style="margin-top:0"><?= is_rtl() ? 'حذف الحساب' : 'Delete account' ?></h3>
    <?php if ($deleteError && isset($deleteMessages[$deleteError])): ?>
      <div class="form-alert error"><?= htmlspecialchars($deleteMessages[$deleteError]) ?></div>
      <?php if ($deleteError === 'has_open_work' && $deleteBlockers): ?>
        <ul style="margin:8px 0;padding-inline-start:20px;color:var(--text-muted)">
          <?php foreach ($deleteBlockers as $b): ?><li><?= htmlspecialchars($b) ?></li><?php endforeach; ?>
        </ul>
      <?php endif; ?>
    <?php endif; ?>
    <p style="color:var(--text-muted);font-size:.88rem">
      <?= is_rtl()
        ? 'سيتم إلغاء تفعيل حسابك وإزالة بياناتك الشخصية (الاسم والبريد والجوال وكلمة المرور) وإبطال جميع أجهزة الدخول. تُحفظ السجلات المالية والقانونية المرتبطة بأطراف أخرى كما يقتضي النظام. لا يمكن التراجع عن هذا الإجراء.'
        : 'Your account will be deactivated and your personal data (name, email, phone, password) removed, and every signed-in device revoked. Financial and legal records tied to other parties are retained as the law requires. This cannot be undone.' ?>
    </p>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
      <input type="hidden" name="action" value="delete_account">
      <div class="field">
        <label for="delete_password"><?= is_rtl() ? 'كلمة المرور للتأكيد' : 'Password to confirm' ?></label>
        <input type="password" id="delete_password" name="password" autocomplete="current-password" required>
      </div>
      <div class="field">
        <!-- A typed confirmation instead of an inline onsubmit(): it is the same
             protection, needs no JavaScript, and keeps the page CSP-safe. -->
        <label for="delete_confirm"><?= is_rtl() ? 'اكتب كلمة DELETE للتأكيد' : 'Type DELETE to confirm' ?></label>
        <input type="text" id="delete_confirm" name="confirm" pattern="DELETE|delete" required
               autocomplete="off" spellcheck="false" placeholder="DELETE">
      </div>
      <button class="btn" type="submit" style="background:var(--danger,#c0392b);color:#fff">
        <?= is_rtl() ? 'حذف حسابي نهائياً' : 'Delete my account' ?>
      </button>
    </form>
  </div>
</div>
<?php require_once __DIR__ . '/includes/app_footer.php'; ?>
