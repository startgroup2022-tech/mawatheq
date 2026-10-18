<?php
/**
 * Referral links (Execution 31).
 *
 * An affiliate can create a link to any page of the site, with an optional campaign
 * label and UTM values. The destination is constrained to a same-site path by
 * affiliate_safe_destination(), so this form cannot be used to build a link that
 * redirects to an external site under the platform's domain.
 */
$activeAffiliate = 'links';
require_once __DIR__ . '/../includes/affiliate_header.php';
require_once __DIR__ . '/../includes/affiliate_tracking.php';


$affiliateId = (int)$affiliate['id'];
$error = null;
$created = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $string = static function (string $key, string $default = ''): string {
        $value = $_POST[$key] ?? $default;
        return is_string($value) ? trim($value) : $default;
    };

    if ($action === 'create') {
        if (!affiliate_can_earn($affiliate)) {
            $error = is_rtl() ? 'حسابك غير مفعّل بعد.' : 'Your account is not active yet.';
        } else {
            $label = mb_substr($string('label'), 0, 120, 'UTF-8');
            $destination = affiliate_safe_destination($string('destination', '/'));
            $utm = [
                'utm_source' => mb_substr($string('utm_source'), 0, 150, 'UTF-8'),
                'utm_medium' => mb_substr($string('utm_medium'), 0, 150, 'UTF-8'),
                'utm_campaign' => mb_substr($string('utm_campaign'), 0, 150, 'UTF-8'),
                'utm_content' => mb_substr($string('utm_content'), 0, 150, 'UTF-8'),
            ];
            $utm = array_filter($utm, static fn($v) => $v !== '');

            try {
                // A short, unguessable code so two links to the same destination are
                // distinguishable in the click log.
                $code = $affiliateId . '-' . strtoupper(bin2hex(random_bytes(4)));
                $pdo->prepare('INSERT INTO affiliate_links (affiliate_id, code, label, destination_path, utm_source, utm_medium, utm_campaign, utm_content, is_active)
                    VALUES (?,?,?,?,?,?,?,?,1)')
                    ->execute([
                        $affiliateId, $code, $label !== '' ? $label : null, $destination,
                        $utm['utm_source'] ?? null, $utm['utm_medium'] ?? null,
                        $utm['utm_campaign'] ?? null, $utm['utm_content'] ?? null,
                    ]);
                $created = affiliate_build_link($affiliate, $destination, $utm);
                affiliate_audit($pdo, 'link_created', [
                    'affiliate_id' => $affiliateId, 'subject_type' => 'link', 'subject_id' => (int)$pdo->lastInsertId(),
                    'detail' => 'destination=' . $destination,
                ]);
            } catch (Throwable $e) {
                error_log('affiliate link create: ' . $e->getMessage());
                $error = is_rtl() ? 'تعذّر إنشاء الرابط.' : 'The link could not be created.';
            }
        }
    } elseif ($action === 'toggle') {
        $linkId = (int)($string('link_id'));
        // The affiliate_id in the WHERE clause is what stops one affiliate toggling
        // another's link by guessing an id.
        try {
            $pdo->prepare('UPDATE affiliate_links SET is_active = 1 - is_active WHERE id=? AND affiliate_id=?')
                ->execute([$linkId, $affiliateId]);
        } catch (Throwable $e) {
            $error = is_rtl() ? 'تعذّر تحديث الرابط.' : 'The link could not be updated.';
        }
    }
}

$links = [];
try {
    $stmt = $pdo->prepare('SELECT * FROM affiliate_links WHERE affiliate_id=? ORDER BY id DESC LIMIT 200');
    $stmt->execute([$affiliateId]);
    $links = $stmt->fetchAll();
} catch (Throwable $e) { /* pre-v57 database */ }

$campaigns = [];
try {
    $stmt = $pdo->prepare("SELECT id, name, status FROM affiliate_campaigns WHERE status='active' ORDER BY name");
    $stmt->execute();
    $campaigns = $stmt->fetchAll();
} catch (Throwable $e) { /* pre-v57 database */ }
?>
<div class="app-page">
  <div class="app-page-head">
    <div>
      <h1><?= ui_e(is_rtl() ? 'روابط الإحالة' : 'Referral links') ?></h1>
      <p class="muted"><?= ui_e(is_rtl() ? 'أنشئ روابط مخصّصة لصفحات أو حملات محدّدة، وتابع أداء كل رابط.' : 'Create dedicated links for specific pages or campaigns and track each one.') ?></p>
    </div>
  </div>

  <?php if ($error): ?><div class="form-alert error"><?= ui_e($error) ?></div><?php endif; ?>
  <?php if ($created): ?>
    <div class="form-alert success">
      <p><?= ui_e(is_rtl() ? 'تم إنشاء الرابط:' : 'Link created:') ?></p>
      <div class="aff-linkbox-row">
        <input type="text" readonly value="<?= ui_e($created) ?>" id="affNewLink">
        <button type="button" class="btn btn-gold btn-sm" data-copy-target="#affNewLink" data-copy-done="<?= ui_e(is_rtl() ? 'تم النسخ' : 'Copied') ?>"><?= ui_e(is_rtl() ? 'نسخ' : 'Copy') ?></button>
      </div>
    </div>
  <?php endif; ?>

  <?php if (!$affiliateCanEarn): ?>
    <div class="form-alert"><?= ui_e(is_rtl()
      ? 'لا يمكنك إنشاء روابط فعّالة قبل اعتماد الشروط. يمكنك الاطلاع على المواد التسويقية الآن.'
      : 'You cannot create active links before accepting the terms. You can review the marketing materials now.') ?></div>
  <?php else: ?>
  <section class="app-card">
    <div class="app-card-head"><h2><?= ui_e(is_rtl() ? 'إنشاء رابط جديد' : 'Create a new link') ?></h2></div>
    <form method="post" class="aff-form">
      <input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>">
      <input type="hidden" name="action" value="create">
      <div class="aff-grid">
        <label class="field"><span><?= ui_e(is_rtl() ? 'اسم الرابط (للتعريف)' : 'Link name (for your reference)') ?></span>
          <input type="text" name="label" maxlength="120" placeholder="<?= ui_e(is_rtl() ? 'مثال: حملة إنستغرام' : 'e.g. Instagram campaign') ?>"></label>
        <label class="field"><span><?= ui_e(is_rtl() ? 'الصفحة المقصودة' : 'Destination page') ?></span>
          <input type="text" name="destination" maxlength="255" value="/" placeholder="/pricing.php">
          <small><?= ui_e(is_rtl() ? 'مسار داخل الموقع فقط، يبدأ بـ /' : 'A path inside the site only, starting with /') ?></small></label>
        <label class="field"><span>utm_source</span><input type="text" name="utm_source" maxlength="150" placeholder="instagram"></label>
        <label class="field"><span>utm_medium</span><input type="text" name="utm_medium" maxlength="150" placeholder="social"></label>
        <label class="field"><span>utm_campaign</span><input type="text" name="utm_campaign" maxlength="150" placeholder="spring"></label>
        <label class="field"><span>utm_content</span><input type="text" name="utm_content" maxlength="150"></label>
      </div>
      <button class="btn btn-primary" type="submit"><?= ui_e(is_rtl() ? 'إنشاء الرابط' : 'Create link') ?></button>
    </form>
  </section>
  <?php endif; ?>

  <section class="app-card">
    <div class="app-card-head"><h2><?= ui_e(is_rtl() ? 'روابطك' : 'Your links') ?></h2></div>
    <?php if (!$links): ?>
      <?= ui_empty_state(is_rtl() ? 'لا توجد روابط' : 'No links yet', is_rtl() ? 'أنشئ أول رابط إحالة من النموذج أعلاه.' : 'Create your first referral link using the form above.', 'external') ?>
    <?php else: ?>
      <div class="table-wrap">
        <table class="admin-table">
          <thead><tr>
            <th><?= ui_e(is_rtl() ? 'الاسم' : 'Name') ?></th>
            <th><?= ui_e(is_rtl() ? 'الرابط' : 'Link') ?></th>
            <th><?= ui_e(is_rtl() ? 'النقرات' : 'Clicks') ?></th>
            <th><?= ui_e(is_rtl() ? 'الحالة' : 'Status') ?></th>
            <th></th>
          </tr></thead>
          <tbody>
            <?php foreach ($links as $link):
                $url = affiliate_build_link($affiliate, (string)$link['destination_path'], [
                    'utm_source' => (string)($link['utm_source'] ?? ''),
                    'utm_medium' => (string)($link['utm_medium'] ?? ''),
                    'utm_campaign' => (string)($link['utm_campaign'] ?? ''),
                    'utm_content' => (string)($link['utm_content'] ?? ''),
                ]);
                // A link row can carry its own short code; the builder above uses the
                // affiliate code, which is what attribution actually reads.
            ?>
            <tr>
              <td><?= ui_e((string)($link['label'] ?: '—')) ?></td>
              <td>
                <div class="aff-link-cell">
                  <input type="text" readonly value="<?= ui_e($url) ?>" id="lnk<?= (int)$link['id'] ?>">
                  <button type="button" class="btn btn-outline btn-xs" data-copy-target="#lnk<?= (int)$link['id'] ?>" data-copy-done="<?= ui_e(is_rtl() ? 'تم' : 'Done') ?>"><?= ui_e(is_rtl() ? 'نسخ' : 'Copy') ?></button>
                </div>
                <small class="muted"><?= ui_e((string)$link['destination_path']) ?></small>
              </td>
              <td><?= number_format((int)$link['click_count']) ?></td>
              <td><span class="status-pill <?= (int)$link['is_active'] === 1 ? 'status-confirmed' : 'status-cancelled' ?>"><?= ui_e((int)$link['is_active'] === 1 ? (is_rtl() ? 'فعّال' : 'Active') : (is_rtl() ? 'موقوف' : 'Paused')) ?></span></td>
              <td>
                <form method="post" class="inline-form">
                  <input type="hidden" name="csrf_token" value="<?= ui_e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="link_id" value="<?= (int)$link['id'] ?>">
                  <button class="btn btn-outline btn-xs" type="submit"><?= ui_e((int)$link['is_active'] === 1 ? (is_rtl() ? 'إيقاف' : 'Pause') : (is_rtl() ? 'تفعيل' : 'Activate')) ?></button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>
<?php require_once __DIR__ . '/../includes/affiliate_footer.php'; ?>
