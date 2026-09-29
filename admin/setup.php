<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth_security.php';
require_once __DIR__ . '/../includes/csrf.php';
$adminCount = (int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
$error = null;
$done = false;
if ($adminCount > 0) {
    http_response_code(404);
    exit('Not found');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $name = trim($_POST['full_name'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['password_confirm'] ?? '';
    $key = $_POST['setup_key'] ?? '';
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(403); exit('Invalid request');
    } elseif (auth_rate_limit_remaining($pdo, 'admin-setup', $email) > 0) {
        $error = 'محاولات كثيرة. انتظر 15 دقيقة ثم حاول مجدداً.';
    } elseif (!defined('ADMIN_SETUP_KEY') || strlen(ADMIN_SETUP_KEY) < 32 || !hash_equals(ADMIN_SETUP_KEY, $key)) {
        auth_rate_limit_failure($pdo, 'admin-setup', $email);
        $error = 'مفتاح التهيئة غير صحيح.';
    } elseif (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12 || $password !== $confirm) {
        auth_rate_limit_failure($pdo, 'admin-setup', $email);
        $error = 'أدخل اسماً وبريداً صحيحاً، وكلمة مرور من 12 حرفاً على الأقل متطابقة.';
    } else {
        $pdo->prepare('INSERT INTO admins (full_name,email,password_hash,role) VALUES (?,?,?,?)')
            ->execute([$name,$email,password_hash($password, PASSWORD_DEFAULT),'super_admin']);
        $done = true;
    }
}
?>
<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/css/style.css"><title>تهيئة المدير — مواثيق</title></head><body>
<div class="auth-wrap"><div class="auth-card"><h2>إنشاء حساب المدير الأول</h2>
<p class="field-hint">هذه الصفحة تعمل فقط قبل إنشاء أي حساب مدير. ضع مفتاح التهيئة الخاص في config/secrets.php، ثم احذف admin/setup.php بعد الإنشاء.</p>
<?php if ($error): ?><div class="form-alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($done): ?><div class="form-alert success">تم إنشاء حساب المدير. احذف هذه الصفحة من الخادم الآن.</div><a class="btn btn-primary btn-block" href="/admin/login.php">تسجيل الدخول</a><?php else: ?>
<form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><div class="field"><label>مفتاح التهيئة</label><input type="password" name="setup_key" required autocomplete="off"></div><div class="field"><label>الاسم الكامل</label><input name="full_name" required></div><div class="field"><label>البريد الإلكتروني</label><input type="email" name="email" required autocomplete="email"></div>
<div class="field"><label>كلمة المرور (12 حرفاً على الأقل)</label><div class="password-input"><input type="password" id="setupPassword" name="password" minlength="12" required autocomplete="new-password"><button type="button" class="password-toggle" data-password-toggle="setupPassword">إظهار</button></div></div>
<div class="field"><label>تأكيد كلمة المرور</label><div class="password-input"><input type="password" id="setupPasswordConfirm" name="password_confirm" minlength="12" required autocomplete="new-password"><button type="button" class="password-toggle" data-password-toggle="setupPasswordConfirm">إظهار</button></div></div><button class="btn btn-primary btn-block">إنشاء المدير</button></form><?php endif; ?>
</div></div><script>document.querySelectorAll('[data-password-toggle]').forEach(function(button){button.addEventListener('click',function(){var input=document.getElementById(button.dataset.passwordToggle),show=input.type==='password';input.type=show?'text':'password';button.textContent=show?'إخفاء':'إظهار';});});</script></body></html>
