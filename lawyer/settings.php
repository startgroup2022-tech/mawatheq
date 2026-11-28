<?php
/**
 * Lawyer settings — account, password, session security, notification preferences,
 * privacy and account deletion (Execution 56).
 *
 * The POST handlers run before the shell is included: `lawyer_header.php` prints the
 * whole <head> immediately with no output buffering, so a header() redirect issued
 * afterwards is silently dropped (see the "POST-redirect pages" note in AGENTS.md).
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/lawyer_guard.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/account_deletion.php';
require_once __DIR__ . '/../includes/auth_security.php';

$lawyerId = (int)$_SESSION['lawyer_id'];
$rtl = is_rtl();
$tab = in_array(($_GET['tab'] ?? ''), ['account','security','notifications','privacy','delete'], true)
    ? (string)$_GET['tab'] : 'account';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'change_password') {
        $current = (string)($_POST['current_password'] ?? '');
        $new     = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        $stmt = $pdo->prepare('SELECT password_hash FROM lawyers WHERE id=?');
        $stmt->execute([$lawyerId]);
        $hash = (string)($stmt->fetchColumn() ?: '');
        $error = null;
        if (!password_verify($current, $hash)) {
            $error = 'wrong_current';
        } elseif (strlen($new) < 8) {
            $error = 'weak';
        } elseif ($new !== $confirm) {
            $error = 'mismatch';
        }
        if ($error) {
            header('Location: /lawyer/settings.php?tab=security&err=' . $error . '&t=' . time());
            exit;
        }
        $pdo->prepare('UPDATE lawyers SET password_hash=?, session_invalidated_at=NOW() WHERE id=?')
            ->execute([password_hash($new, PASSWORD_BCRYPT), $lawyerId]);
        // Revoke every API token except this session's own device: a password change
        // must end the sessions an attacker may hold, not the one in front of the user.
        $pdo->prepare('UPDATE api_tokens SET revoked_at=NOW() WHERE user_type=? AND user_id=? AND revoked_at IS NULL')
            ->execute(['lawyer', $lawyerId]);
        log_action($pdo, 'تغيير كلمة مرور المحامي', 'lawyer', $lawyerId);
        $_SESSION = [];
        session_destroy();
        header('Location: /lawyer-login.php?password_changed=1');
        exit;
    }

    if ($action === 'save_notifications') {
        $prefs = [
            'in_app' => isset($_POST['in_app']),
            'email'  => isset($_POST['email']),
        ];
        foreach ($prefs as $channel => $enabled) {
            $pdo->prepare('INSERT INTO notification_preferences (subject_type,subject_id,channel,event_key,enabled)
                VALUES (?,?,?,\'all\',?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled)')
                ->execute(['lawyer', $lawyerId, $channel, $enabled ? 1 : 0]);
        }
        log_action($pdo, 'تحديث تفضيلات الإشعارات', 'lawyer', $lawyerId);
        header('Location: /lawyer/settings.php?tab=notifications&saved=1&t=' . time());
        exit;
    }

    if ($action === 'save_privacy') {
        $granted = isset($_POST['profile_visible']) ? 1 : 0;
        $pdo->prepare('INSERT INTO privacy_consents (subject_type,subject_id,consent_type,policy_version,granted,granted_at,ip)
            VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE granted=VALUES(granted), granted_at=VALUES(granted_at)')
            ->execute(['lawyer', $lawyerId, 'profile_visible', 'v1', $granted, $granted ? date('Y-m-d H:i:s') : null, $_SERVER['REMOTE_ADDR'] ?? null]);
        log_action($pdo, 'تحديث تفضيلات الخصوصية', 'lawyer', $lawyerId);
        header('Location: /lawyer/settings.php?tab=privacy&saved=1&t=' . time());
        exit;
    }

    if ($action === 'delete_account') {
        if (strtoupper(trim((string)($_POST['confirm'] ?? ''))) !== 'DELETE') {
            header('Location: /lawyer/settings.php?tab=delete&err=confirm&t=' . time());
            exit;
        }
        $result = account_delete($pdo, 'lawyer', $lawyerId, (string)($_POST['password'] ?? ''));
        if ($result['ok']) {
            $_SESSION = [];
            session_destroy();
            header('Location: /lawyer-login.php?deleted=1');
            exit;
        }
        $_SESSION['lawyer_delete_error'] = $result['error'];
        $_SESSION['lawyer_delete_blockers'] = $result['blockers'] ?? [];
        header('Location: /lawyer/settings.php?tab=delete&err=' . $result['error'] . '&t=' . time());
        exit;
    }

    if ($action === 'sign_out_all') {
        $pdo->prepare('UPDATE api_tokens SET revoked_at=NOW() WHERE user_type=? AND user_id=? AND revoked_at IS NULL')
            ->execute(['lawyer', $lawyerId]);
        $pdo->prepare('UPDATE lawyers SET session_invalidated_at=NOW() WHERE id=?')->execute([$lawyerId]);
        log_action($pdo, 'إنهاء جميع الجلسات', 'lawyer', $lawyerId);
        $_SESSION = [];
        session_destroy();
        header('Location: /lawyer-login.php?sessions_ended=1');
        exit;
    }
}

$err = (string)($_GET['err'] ?? '');
$saved = isset($_GET['saved']);

// Current preferences. A missing row means the default, which is "on".
$prefStmt = $pdo->prepare('SELECT channel, enabled FROM notification_preferences
    WHERE subject_type=? AND subject_id=? AND event_key=\'all\'');
$prefStmt->execute(['lawyer', $lawyerId]);
$prefs = ['in_app' => true, 'email' => true];
foreach ($prefStmt->fetchAll() as $p) { $prefs[(string)$p['channel']] = ((int)$p['enabled'] === 1); }

$privacyStmt = $pdo->prepare('SELECT granted FROM privacy_consents WHERE subject_type=? AND subject_id=? AND consent_type=? AND policy_version=?');
$privacyStmt->execute(['lawyer', $lawyerId, 'profile_visible', 'v1']);
$privacyRow = $privacyStmt->fetch();
$profileVisible = $privacyRow ? ((int)$privacyRow['granted'] === 1) : true;

$lawyerStmt = $pdo->prepare('SELECT email, full_name, created_at FROM lawyers WHERE id=?');
$lawyerStmt->execute([$lawyerId]);
$account = $lawyerStmt->fetch() ?: [];

$deleteError = (string)($_SESSION['lawyer_delete_error'] ?? '');
$deleteBlockers = (array)($_SESSION['lawyer_delete_blockers'] ?? []);
unset($_SESSION['lawyer_delete_error'], $_SESSION['lawyer_delete_blockers']);

$deleteErrorText = match ($deleteError) {
    'wrong_password'   => $rtl ? 'كلمة المرور غير صحيحة.' : 'The password is incorrect.',
    'missing_password' => $rtl ? 'أدخل كلمة المرور للتأكيد.' : 'Enter your password to confirm.',
    'has_open_work'    => $rtl ? 'لا يمكن حذف الحساب لوجود أعمال قائمة:' : 'The account cannot be deleted while work is open:',
    'confirm_required' => $rtl ? 'اكتب كلمة DELETE للتأكيد.' : 'Type DELETE to confirm.',
    default            => $deleteError !== '' ? ($rtl ? 'تعذّر حذف الحساب. حاول لاحقاً.' : 'The account could not be deleted. Try again later.') : '',
};

$activeLawyer = 'settings';
require_once __DIR__ . '/includes/lawyer_header.php';
?>
<div class="app-content">
  <div class="toolbar"><h2><?= $rtl ? 'الإعدادات' : 'Settings' ?></h2></div>

  <nav class="tabs" role="tablist" style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px">
    <?php foreach ([
        'account'       => [$rtl ? 'الحساب' : 'Account', '/lawyer/settings.php?tab=account'],
        'security'      => [$rtl ? 'الأمان' : 'Security', '/lawyer/settings.php?tab=security'],
        'notifications' => [$rtl ? 'الإشعارات' : 'Notifications', '/lawyer/settings.php?tab=notifications'],
        'privacy'       => [$rtl ? 'الخصوصية' : 'Privacy', '/lawyer/settings.php?tab=privacy'],
        'delete'        => [$rtl ? 'حذف الحساب' : 'Delete account', '/lawyer/settings.php?tab=delete'],
    ] as $key => [$label, $href]): ?>
      <a class="lang-switch" href="<?= htmlspecialchars($href) ?>"
         <?= $tab === $key ? 'style="background:var(--navy);color:#fff;border-color:var(--navy)"' : '' ?>><?= htmlspecialchars($label) ?></a>
    <?php endforeach; ?>
  </nav>

  <?php if ($saved): ?><div class="form-alert success"><?= $rtl ? 'تم حفظ الإعدادات.' : 'Settings saved.' ?></div><?php endif; ?>
  <?php if ($err === 'weak'): ?><div class="form-alert error"><?= $rtl ? 'كلمة المرور الجديدة قصيرة (8 أحرف على الأقل).' : 'The new password is too short (8 characters minimum).' ?></div><?php endif; ?>
  <?php if ($err === 'mismatch'): ?><div class="form-alert error"><?= $rtl ? 'كلمتا المرور غير متطابقتين.' : 'The passwords do not match.' ?></div><?php endif; ?>
  <?php if ($err === 'wrong_current'): ?><div class="form-alert error"><?= $rtl ? 'كلمة المرور الحالية غير صحيحة.' : 'The current password is incorrect.' ?></div><?php endif; ?>

  <?php if ($tab === 'account'): ?>
    <section class="dash-card">
      <h3><?= $rtl ? 'بيانات الحساب' : 'Account details' ?></h3>
      <p><strong><?= $rtl ? 'الاسم' : 'Name' ?>:</strong> <?= htmlspecialchars((string)($account['full_name'] ?? '')) ?></p>
      <p><strong><?= $rtl ? 'البريد الإلكتروني' : 'Email' ?>:</strong> <span class="num-ltr"><?= htmlspecialchars((string)($account['email'] ?? '')) ?></span></p>
      <p><strong><?= $rtl ? 'تاريخ الانضمام' : 'Joined' ?>:</strong> <span class="num-ltr"><?= htmlspecialchars(date('Y-m-d', strtotime((string)($account['created_at'] ?? 'now')))) ?></span></p>
      <p class="muted-sm"><?= $rtl
        ? 'لتعديل الاسم والتخصص والسيرة المهنية، استخدم صفحة الملف الشخصي.'
        : 'To change your name, specialisation and professional bio, use the profile page.' ?></p>
      <a class="btn btn-outline" href="/lawyer/profile.php"><?= $rtl ? 'فتح الملف الشخصي' : 'Open profile' ?></a>
    </section>
  <?php endif; ?>

  <?php if ($tab === 'security'): ?>
    <section class="dash-card">
      <h3><?= $rtl ? 'تغيير كلمة المرور' : 'Change password' ?></h3>
      <p class="muted-sm"><?= $rtl
        ? 'تغيير كلمة المرور ينهي جميع الجلسات الأخرى ويلغي جميع رموز الوصول.'
        : 'Changing the password ends every other session and revokes all access tokens.' ?></p>
      <form method="post" class="app-form" style="max-width:520px">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="action" value="change_password">
        <div class="form-row"><label for="cp-current"><?= $rtl ? 'كلمة المرور الحالية' : 'Current password' ?></label>
          <input type="password" id="cp-current" name="current_password" required autocomplete="current-password"></div>
        <div class="form-row"><label for="cp-new"><?= $rtl ? 'كلمة المرور الجديدة' : 'New password' ?></label>
          <input type="password" id="cp-new" name="new_password" minlength="8" required autocomplete="new-password"></div>
        <div class="form-row"><label for="cp-confirm"><?= $rtl ? 'تأكيد كلمة المرور' : 'Confirm password' ?></label>
          <input type="password" id="cp-confirm" name="confirm_password" minlength="8" required autocomplete="new-password"></div>
        <button class="btn btn-primary" type="submit"><?= $rtl ? 'تغيير كلمة المرور' : 'Change password' ?></button>
      </form>
    </section>

    <section class="dash-card">
      <h3><?= $rtl ? 'أمان الجلسات' : 'Session security' ?></h3>
      <p class="muted-sm"><?= $rtl
        ? 'إنهاء جميع الجلسات يسجّل الخروج من كل الأجهزة، بما فيها هذا الجهاز.'
        : 'Ending all sessions signs you out everywhere, including this device.' ?></p>
      <form method="post" onsubmit="return confirm('<?= $rtl ? 'إنهاء جميع الجلسات؟' : 'End all sessions?' ?>')">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="action" value="sign_out_all">
        <button class="btn btn-outline" type="submit"><?= $rtl ? 'إنهاء جميع الجلسات' : 'Sign out of all devices' ?></button>
      </form>
    </section>
  <?php endif; ?>

  <?php if ($tab === 'notifications'): ?>
    <section class="dash-card">
      <h3><?= $rtl ? 'تفضيلات الإشعارات' : 'Notification preferences' ?></h3>
      <form method="post" class="app-form" style="max-width:520px">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_notifications">
        <label style="display:flex;align-items:center;gap:10px;min-height:44px">
          <input type="checkbox" name="in_app" value="1" <?= $prefs['in_app'] ? 'checked' : '' ?> style="min-height:24px;width:24px">
          <span><?= $rtl ? 'إشعارات داخل التطبيق (جرس التنبيهات)' : 'In-app notifications (the bell)' ?></span>
        </label>
        <label style="display:flex;align-items:center;gap:10px;min-height:44px">
          <input type="checkbox" name="email" value="1" <?= $prefs['email'] ? 'checked' : '' ?> style="min-height:24px;width:24px">
          <span><?= $rtl ? 'إشعارات البريد الإلكتروني' : 'Email notifications' ?></span>
        </label>
        <button class="btn btn-primary" type="submit"><?= $rtl ? 'حفظ' : 'Save' ?></button>
      </form>
    </section>
  <?php endif; ?>

  <?php if ($tab === 'privacy'): ?>
    <section class="dash-card">
      <h3><?= $rtl ? 'الخصوصية' : 'Privacy' ?></h3>
      <form method="post" class="app-form" style="max-width:520px">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_privacy">
        <label style="display:flex;align-items:center;gap:10px;min-height:44px">
          <input type="checkbox" name="profile_visible" value="1" <?= $profileVisible ? 'checked' : '' ?> style="min-height:24px;width:24px">
          <span><?= $rtl ? 'إظهار ملفي المهني للعملاء المؤهلين' : 'Show my professional profile to eligible clients' ?></span>
        </label>
        <p class="muted-sm"><?= $rtl
          ? 'بيانات القضايا والاستشارات لا تظهر أبداً للعملاء الآخرين، بصرف النظر عن هذا الخيار.'
          : 'Case and consultation data is never shown to other clients, regardless of this setting.' ?></p>
        <button class="btn btn-primary" type="submit"><?= $rtl ? 'حفظ' : 'Save' ?></button>
      </form>
      <p><a href="/privacy.php" target="_blank" rel="noopener"><?= $rtl ? 'سياسة الخصوصية الكاملة ↗' : 'Full privacy policy ↗' ?></a></p>
    </section>
  <?php endif; ?>

  <?php if ($tab === 'delete'): ?>
    <section class="dash-card" style="border-color:#F3C9BC">
      <h3><?= $rtl ? 'حذف الحساب' : 'Delete account' ?></h3>
      <?php if ($deleteErrorText !== ''): ?>
        <div class="form-alert error">
          <?= htmlspecialchars($deleteErrorText) ?>
          <?php if ($deleteBlockers): ?><ul><?php foreach ($deleteBlockers as $b): ?><li><?= htmlspecialchars($b) ?></li><?php endforeach; ?></ul><?php endif; ?>
        </div>
      <?php endif; ?>
      <p class="muted-sm"><?= $rtl
        ? 'يُعطّل الحساب وتُحذف بياناتك الشخصية ويلغى الوصول بالكامل. تبقى الفواتير والسجلات المالية والقانونية المرتبطة بأطراف أخرى، لأن حذفها يضر بسجلات غيرك ويلزم الاحتفاظ بها نظاماً.'
        : 'Your account is deactivated, your personal data is erased and access is revoked. Invoices and financial or legal records that involve other parties are retained, because removing them would damage another party\'s records and they are required to be kept.' ?></p>
      <form method="post" class="app-form" style="max-width:520px"
            onsubmit="return confirm('<?= $rtl ? 'هل أنت متأكد من حذف الحساب؟ لا يمكن التراجع.' : 'Delete the account? This cannot be undone.' ?>')">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="action" value="delete_account">
        <div class="form-row"><label for="del-pass"><?= $rtl ? 'كلمة المرور' : 'Password' ?></label>
          <input type="password" id="del-pass" name="password" required autocomplete="current-password"></div>
        <div class="form-row"><label for="del-confirm"><?= $rtl ? 'اكتب DELETE للتأكيد' : 'Type DELETE to confirm' ?></label>
          <input type="text" id="del-confirm" name="confirm" pattern="DELETE" required dir="ltr"></div>
        <button class="btn" type="submit" style="background:var(--danger);color:#fff"><?= $rtl ? 'حذف حسابي نهائياً' : 'Delete my account permanently' ?></button>
      </form>
    </section>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/lawyer_footer.php'; ?>
