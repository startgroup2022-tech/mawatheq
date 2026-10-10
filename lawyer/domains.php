<?php
/**
 * Custom domain management for the firm's public website.
 *
 * Add a hostname, publish the TXT token, run the check, activate. HTTPS is a manual step
 * and the page says so plainly rather than implying automatic certificates.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/lawyer_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/tenant.php';
require_once __DIR__ . '/../includes/firm_domains.php';
require_once __DIR__ . '/../includes/domain_ssl.php';

$lawyerId = (int)$_SESSION['lawyer_id'];
$firmId = (int)(tenant_current($pdo)['firm_id'] ?? 0);
$role = (string)(tenant_current($pdo)['role'] ?? '');
$canManage = in_array($role, ['owner', 'partner'], true);
$error = null; $success = null; $verifyResult = null;

if ($firmId && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) { http_response_code(403); exit('Invalid request'); }
    if (!$canManage) { http_response_code(403); $error = is_rtl() ? 'هذه العملية متاحة لمالك المكتب أو شريك فقط.' : 'Only an owner or partner may do this.'; }
    else {
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'add') {
                $r = firm_domain_add($pdo, (string)($_POST['domain'] ?? ''));
                if (empty($r['ok'])) {
                    $msgs = [
                        'invalid_domain' => is_rtl() ? 'صيغة النطاق غير صحيحة.' : 'Invalid domain format.',
                        'domain_taken' => is_rtl() ? 'هذا النطاق مسجّل لمكتب آخر.' : 'This domain belongs to another firm.',
                        'already_yours' => is_rtl() ? 'هذا النطاق مسجّل لديك مسبقاً.' : 'You already registered this domain.',
                        'no_tenant' => is_rtl() ? 'لا يوجد مكتب.' : 'No firm.',
                    ];
                    throw new RuntimeException($msgs[$r['error']] ?? (is_rtl() ? 'تعذّر الإضافة.' : 'Could not add.'));
                }
                $success = is_rtl() ? 'تمت إضافة النطاق. أنشئ سجل TXT ثم اضغط «فحص».' : 'Domain added. Create the TXT record then press Check.';
            } elseif ($action === 'verify') {
                $verifyResult = firm_domain_verify($pdo, (int)$_POST['domain_id']);
                $success = !empty($verifyResult['verified'])
                    ? (is_rtl() ? 'تم التحقق من ملكية النطاق.' : 'Domain ownership verified.')
                    : (is_rtl() ? 'لم يتم العثور على سجل TXT مطابق بعد.' : 'No matching TXT record found yet.');
            } elseif ($action === 'activate') {
                $r = firm_domain_activate($pdo, (int)$_POST['domain_id']);
                if (empty($r['ok'])) throw new RuntimeException(is_rtl() ? 'يجب التحقق من النطاق قبل التفعيل.' : 'Verify the domain before activating.');
                $q = domain_ssl_queue($pdo, (int)$_POST['domain_id']);
                $success = !empty($q['ok'])
                    ? (is_rtl() ? 'تم تفعيل النطاق، وأُضيفت مهمة شهادة SSL.' : 'Domain activated and a TLS job was queued.')
                    : (is_rtl() ? 'تم تفعيل النطاق، لكن لم تُضَف مهمة SSL.' : 'Domain activated, but the TLS job could not be queued.');
            } elseif ($action === 'recheck_ssl') {
                $q = domain_ssl_queue($pdo, (int)$_POST['domain_id']);
                $run = domain_ssl_run($pdo, 1);
                $success = $run['issued'] > 0
                    ? (is_rtl() ? 'تم التحقق من الشهادة: تعمل حالياً.' : 'Certificate verified: HTTPS is live.')
                    : (is_rtl() ? 'لم تُصدر الشهادة تلقائياً؛ التثبيت خطوة على السيرفر.' : 'The certificate was not issued automatically; installation is a server step.');
            } elseif ($action === 'primary') {
                firm_domain_set_primary($pdo, (int)$_POST['domain_id']);
                $success = is_rtl() ? 'تم تعيين النطاق الأساسي.' : 'Primary domain set.';
            } elseif ($action === 'disable') {
                firm_domain_disable($pdo, (int)$_POST['domain_id']);
                $success = is_rtl() ? 'تم تعطيل النطاق.' : 'Domain disabled.';
            } elseif ($action === 'delete') {
                firm_domain_delete($pdo, (int)$_POST['domain_id']);
                $success = is_rtl() ? 'تم حذف النطاق.' : 'Domain deleted.';
            }
        } catch (Throwable $e) { $error = $e->getMessage(); }
    }
}

$activeLawyer = 'domains';
require_once __DIR__ . '/includes/lawyer_header.php';

if (!$firmId) {
    echo '<div class="app-content">' . ui_page_head(is_rtl() ? 'النطاقات المخصصة' : 'Custom domains') . ui_empty_state(is_rtl() ? 'لا يوجد مكتب' : 'No firm') . '</div>';
    require_once __DIR__ . '/includes/lawyer_footer.php';
    exit;
}
$domains = firm_domain_list($pdo);
?>
<div class="app-content">
  <?= ui_page_head(
      is_rtl() ? 'النطاقات المخصصة' : 'Custom domains',
      is_rtl() ? 'اربط نطاق المكتب الخاص بموقع المكتب على مواثيق.' : 'Point the firm\'s own hostname at its Mawatheq website.',
      [], 'h1') ?>

  <?php if ($error): ?><div class="form-alert error"><?= ui_e($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="form-alert success"><?= ui_e($success) ?></div><?php endif; ?>
  <div class="form-alert"><?= is_rtl() ? 'ملاحظة: شهادة HTTPS تُثبَّت يدوياً على السيرفر؛ لا يوجد إصدار تلقائي للشهادات. لن يعمل النطاق عبر HTTPS قبل تثبيتها.' : 'Note: the HTTPS certificate is installed manually on the server; there is no automatic certificate issuance. The domain will not serve over HTTPS until it is installed.' ?></div>

  <section class="dash-card">
    <h2><?= is_rtl() ? 'نطاقات المكتب' : 'Firm domains' ?></h2>
    <?php if ($domains): ?>
      <table class="admin-table">
        <thead><tr><th><?= is_rtl() ? 'النطاق' : 'Domain' ?></th><th><?= is_rtl() ? 'الحالة' : 'Status' ?></th><th><?= is_rtl() ? 'SSL' : 'SSL' ?></th><th><?= is_rtl() ? 'سجل TXT' : 'TXT record' ?></th><th></th></tr></thead>
        <tbody>
          <?php foreach ($domains as $d): $rec = firm_domain_expected_record($d); ?>
            <tr>
              <td><?= ui_e($d['domain']) ?> <?= !empty($d['is_primary']) ? '<span class="badge">' . (is_rtl() ? 'أساسي' : 'primary') . '</span>' : '' ?></td>
              <td><?= ui_badge(in_array($d['status'], ['verified', 'active'], true) ? 'success' : ($d['status'] === 'failed' ? 'danger' : 'neutral'), firm_domain_status_label((string)$d['status'], is_rtl())) ?></td>
              <td>
                <?php $sslJob = domain_ssl_latest($pdo, (int)$d['id']); ?>
                <?= ui_e((string)($sslJob['status'] ?? $d['ssl_status'])) ?>
                <?php if ($sslJob && !empty($sslJob['detail'])): ?><div class="muted"><?= ui_e((string)$sslJob['detail']) ?></div><?php endif; ?>
              </td>
              <td>
                <?php if (in_array($d['status'], ['verification_pending', 'failed'], true)): ?>
                  <code><?= ui_e($rec['host']) ?> TXT <?= ui_e($rec['value']) ?></code>
                <?php else: ?>—<?php endif; ?>
              </td>
              <td>
                <?php if ($canManage): ?>
                  <?php if (in_array($d['status'], ['verification_pending', 'failed', 'verified'], true)): ?>
                    <form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>"><input type="hidden" name="action" value="verify"><input type="hidden" name="domain_id" value="<?= (int)$d['id'] ?>"><button class="btn btn-outline btn-sm" type="submit"><?= is_rtl() ? 'فحص' : 'Check' ?></button></form>
                  <?php endif; ?>
                  <?php if ($d['status'] === 'verified'): ?>
                    <form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>"><input type="hidden" name="action" value="activate"><input type="hidden" name="domain_id" value="<?= (int)$d['id'] ?>"><button class="btn btn-primary btn-sm" type="submit"><?= is_rtl() ? 'تفعيل' : 'Activate' ?></button></form>
                  <?php endif; ?>
                  <?php if ($d['status'] === 'active' && empty($d['is_primary'])): ?>
                    <form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>"><input type="hidden" name="action" value="primary"><input type="hidden" name="domain_id" value="<?= (int)$d['id'] ?>"><button class="btn btn-outline btn-sm" type="submit"><?= is_rtl() ? 'تعيين أساسي' : 'Set primary' ?></button></form>
                    <form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>"><input type="hidden" name="action" value="disable"><input type="hidden" name="domain_id" value="<?= (int)$d['id'] ?>"><button class="btn btn-outline btn-sm" type="submit"><?= is_rtl() ? 'تعطيل' : 'Disable' ?></button></form>
                  <?php endif; ?>
                  <?php if (in_array($d['status'], ['verified', 'active'], true)): ?>
                    <form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>"><input type="hidden" name="action" value="recheck_ssl"><input type="hidden" name="domain_id" value="<?= (int)$d['id'] ?>"><button class="btn btn-outline btn-sm" type="submit"><?= is_rtl() ? 'فحص SSL' : 'Check SSL' ?></button></form>
                  <?php endif; ?>
                  <form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="domain_id" value="<?= (int)$d['id'] ?>"><button class="btn btn-danger btn-sm" type="submit"><?= is_rtl() ? 'حذف' : 'Delete' ?></button></form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?><?= ui_empty_state(is_rtl() ? 'لا توجد نطاقات' : 'No domains') ?><?php endif; ?>

    <?php if ($canManage): ?>
      <h3 class="firm-subhead"><?= is_rtl() ? 'إضافة نطاق' : 'Add a domain' ?></h3>
      <form method="post" class="stack-form">
        <input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>">
        <input type="hidden" name="action" value="add">
        <label><?= is_rtl() ? 'النطاق' : 'Domain' ?><input type="text" name="domain" placeholder="firm.example.com" maxlength="255" required></label>
        <button type="submit" class="btn btn-primary"><?= is_rtl() ? 'إضافة' : 'Add' ?></button>
      </form>
    <?php endif; ?>
  </section>
</div>
<?php require_once __DIR__ . '/includes/lawyer_footer.php';
