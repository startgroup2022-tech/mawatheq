<?php
$activePage = 'profile';
require_once __DIR__ . '/includes/app_header.php';
require_once __DIR__ . '/includes/phone.php';

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
</div>
<?php require_once __DIR__ . '/includes/app_footer.php'; ?>
