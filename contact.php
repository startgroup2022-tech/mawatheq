<?php
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/phone.php';
$leadType = $_GET['type'] ?? 'contact';
$sent = false;
$error = null;
$old = ['full_name' => '', 'email' => '', 'phone_dial' => '+966', 'phone_local' => '', 'message' => ''];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !csrf_is_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(403); exit('Invalid request');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = [
        'full_name' => trim($_POST['full_name'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'phone_dial' => $_POST['phone_dial'] ?? '+966',
        'phone_local' => $_POST['phone_local'] ?? '',
        'message' => trim($_POST['message'] ?? ''),
    ];
    $phoneResult = validate_phone($old['phone_dial'], $old['phone_local']);
    if (!$old['full_name'] || !$old['email'] || !$old['message']) {
        $error = is_rtl() ? 'يرجى تعبئة جميع الحقول.' : 'Please fill all fields.';
    } elseif (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $error = is_rtl() ? 'صيغة البريد الإلكتروني غير صحيحة.' : 'Invalid email format.';
    } elseif (!$phoneResult['ok']) {
        $error = $phoneResult['error'];
    } else {
        $src = get_lead_source();
        $stmt = $pdo->prepare('INSERT INTO contact_leads (lead_type, full_name, email, phone, message, utm_source, utm_medium, utm_campaign, source_page) VALUES (?,?,?,?,?,?,?,?,?)');
        $stmt->execute([
            $_POST['lead_type'] ?? 'contact', $old['full_name'], $old['email'], $phoneResult['value'], $old['message'],
            $src['utm_source'], $src['utm_medium'], $src['utm_campaign'], $src['source_page'],
        ]);
        $sent = true;
    }
}
?>
<section class="section">
  <div class="container" style="max-width:560px">
    <div class="section-head">
      <h2><?= t('footer_contact') ?></h2>
      <p><?= is_rtl() ? 'راسلنا وسنعود إليك خلال ٢٤ ساعة.' : "Reach out and we'll get back to you within 24 hours." ?></p>
    </div>
    <?php if ($sent): ?>
      <div class="form-alert success"><?= is_rtl() ? 'تم إرسال رسالتك بنجاح، شكراً لتواصلك معنا.' : 'Your message was sent successfully. Thank you.' ?></div>
    <?php else: ?>
    <?php if ($error): ?><div class="form-alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form class="dash-card" method="post" style="background:#fff;border:1px solid var(--border);border-radius:12px;padding:28px"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
      <input type="hidden" name="lead_type" value="<?= htmlspecialchars($leadType) ?>">
      <div class="field"><label><?= is_rtl() ? 'الاسم الكامل' : 'Full name' ?></label><input type="text" name="full_name" value="<?= htmlspecialchars($old['full_name']) ?>" required></div>
      <div class="field"><label><?= is_rtl() ? 'البريد الإلكتروني' : 'Email' ?></label><input type="email" name="email" value="<?= htmlspecialchars($old['email']) ?>" required></div>
      <div class="field">
        <label><?= is_rtl() ? 'رقم الجوال' : 'Phone' ?></label>
        <?php render_phone_field('phone', $old['phone_dial'], $old['phone_local']); ?>
      </div>
      <div class="field"><label><?= is_rtl() ? 'رسالتك' : 'Message' ?></label><textarea name="message" rows="4" required><?= htmlspecialchars($old['message']) ?></textarea></div>
      <button class="btn btn-primary btn-block" type="submit"><?= is_rtl() ? 'إرسال' : 'Send' ?></button>
    </form>
    <?php endif; ?>
  </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
