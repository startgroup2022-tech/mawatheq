<?php
/**
 * رقابة روبوتات المحادثة ومكتبة النماذج.
 *
 * The operator's read of two firm-owned surfaces the console could not see at all:
 * every firm's public chat assistant and every firm's template library. Neither page
 * lets the operator author content *for* a firm — the bot's greeting and the firm's
 * templates belong to the firm. What the console owns is oversight: which bots are
 * published, which firms are near a plan ceiling, and the ability to switch a bot off
 * when it misbehaves.
 *
 * Disabling is the only write here, and it is deliberately one-directional in practice:
 * an operator can stop a bot, and the firm re-enables it from its own settings page once
 * it has fixed whatever was wrong. That keeps the audit trail honest about who published
 * what.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/admin_guard.php';
// adm() lives in admin_ui.php, which admin_header.php loads. This page uses adm() in the
// POST handler, above the header, so the module has to be present now.
require_once __DIR__ . '/includes/admin_ui.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/firm_chatbot.php';

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Invalid request');
    }
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'toggle_bot') {
        $botId = (int)($_POST['bot_id'] ?? 0);
        $enable = (int)($_POST['enable'] ?? 0) === 1;
        $stmt = $pdo->prepare('SELECT id, firm_id FROM firm_chatbots WHERE id = ?');
        $stmt->execute([$botId]);
        $row = $stmt->fetch();
        if (!$row) {
            $error = adm('الروبوت غير موجود.', 'Bot not found.');
        } else {
            $pdo->prepare('UPDATE firm_chatbots SET is_enabled = ? WHERE id = ?')->execute([$enable ? 1 : 0, $botId]);
            log_action($pdo, $enable ? 'admin_chatbot_enable' : 'admin_chatbot_disable', 'firm_chatbot', $botId);
            $success = $enable
                ? adm('تم تفعيل الروبوت.', 'Bot enabled.')
                : adm('تم إيقاف الروبوت. يمكن للمكتب إعادة تفعيله من إعداداته.', 'Bot disabled. The firm can re-enable it from its settings.');
        }
    }
}

// Every bot with its firm, plan and conversation count. LEFT JOINs so a bot whose firm
// was deleted still shows (and can be disabled) rather than vanishing from the console.
$bots = [];
try {
    $bots = $pdo->query(
        "SELECT b.*, f.name AS firm_name, f.public_slug, f.is_public, p.name_ar AS plan_name, p.feature_chatbot,
                (SELECT COUNT(*) FROM chatbot_conversations c WHERE c.bot_id = b.id) AS conversation_count,
                (SELECT COUNT(*) FROM chatbot_conversations c WHERE c.bot_id = b.id AND c.status = 'converted') AS converted_count
           FROM firm_chatbots b
           LEFT JOIN law_firms f ON f.id = b.firm_id
           LEFT JOIN plans p ON p.id = f.plan_id
          ORDER BY b.updated_at DESC, b.id DESC"
    )->fetchAll();
} catch (Throwable $e) {
    $error = adm('جداول الروبوت غير مهيأة. طبّق ترقية قاعدة البيانات upgrade_v81.sql.', 'The chatbot tables are not ready. Apply upgrade_v81.sql.');
}

// Template usage against each firm's ceiling, so an operator can see who is capped
// before the firm complains about a refused create.
$templates = [];
try {
    $templates = $pdo->query(
        "SELECT f.id AS firm_id, f.name AS firm_name, p.name_ar AS plan_name, p.max_templates,
                COUNT(t.id) AS template_count
           FROM law_firms f
           LEFT JOIN plans p ON p.id = f.plan_id
           LEFT JOIN document_templates t ON t.firm_id = f.id AND t.is_active = 1
          GROUP BY f.id, f.name, p.name_ar, p.max_templates
          HAVING template_count > 0 OR max_templates IS NOT NULL
          ORDER BY template_count DESC, f.id"
    )->fetchAll();
} catch (Throwable $e) {
    $templates = [];
}

require_once __DIR__ . '/includes/admin_header.php';
?>
<div class="page-head">
  <h1><?= adm('روبوتات المكاتب والنماذج', 'Firm chatbots and templates') ?></h1>
  <p class="page-sub"><?= adm(
      'رقابة على المساعد العام لكل مكتب وعلى استهلاك مكتبة النماذج. تعديل محتوى الروبوت أو القوالب يبقى للمكتب.',
      'Oversight of each firm public assistant and of template-library usage. Editing the bot or the templates stays with the firm.'
  ) ?></p>
</div>

<?php if ($success): ?><div class="adm-alert adm-alert-success"><?= ui_e($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="adm-alert adm-alert-error"><?= ui_e($error) ?></div><?php endif; ?>

<section class="card">
  <h2><?= adm('روبوتات المحادثة', 'Chat assistants') ?></h2>
  <?php if (!$bots): ?>
    <p style="color:var(--text-muted)"><?= adm('لا يوجد أي روبوت منشأ بعد.', 'No bots have been created yet.') ?></p>
  <?php else: ?>
    <div class="table-wrap table-cards">
      <table class="admin-table">
        <thead><tr>
          <th><?= adm('المكتب', 'Firm') ?></th>
          <th><?= adm('الباقة', 'Plan') ?></th>
          <th><?= adm('الحالة', 'State') ?></th>
          <th><?= adm('المحادثات', 'Conversations') ?></th>
          <th><?= adm('محوّلة', 'Converted') ?></th>
          <th><?= adm('آخر تحديث', 'Updated') ?></th>
          <th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($bots as $b): ?>
          <?php
            $enabled = (int)$b['is_enabled'] === 1;
            $planOk = (int)($b['feature_chatbot'] ?? 0) === 1;
            $siteOk = (int)($b['is_public'] ?? 0) === 1;
            $live = $enabled && $planOk && $siteOk;
          ?>
          <tr>
            <td data-label="<?= adm('المكتب', 'Firm') ?>"><?= ui_e((string)($b['firm_name'] ?? '—')) ?></td>
            <td data-label="<?= adm('الباقة', 'Plan') ?>"><?= ui_e((string)($b['plan_name'] ?? '—')) ?></td>
            <td data-label="<?= adm('الحالة', 'State') ?>">
              <?php if ($live): ?>
                <span class="status-badge status-active"><?= adm('منشور', 'Live') ?></span>
              <?php elseif (!$enabled): ?>
                <span class="status-badge status-closed"><?= adm('موقوف', 'Disabled') ?></span>
              <?php elseif (!$planOk): ?>
                <span class="status-badge status-pending"><?= adm('الباقة لا تشمله', 'Not in plan') ?></span>
              <?php else: ?>
                <span class="status-badge status-pending"><?= adm('الموقع غير منشور', 'Site not published') ?></span>
              <?php endif; ?>
            </td>
            <td data-label="<?= adm('المحادثات', 'Conversations') ?>"><?= (int)$b['conversation_count'] ?></td>
            <td data-label="<?= adm('محوّلة', 'Converted') ?>"><?= (int)$b['converted_count'] ?></td>
            <td data-label="<?= adm('آخر تحديث', 'Updated') ?>"><?= ui_e((string)$b['updated_at']) ?></td>
            <td>
              <form method="post" style="display:inline">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="action" value="toggle_bot">
                <input type="hidden" name="bot_id" value="<?= (int)$b['id'] ?>">
                <input type="hidden" name="enable" value="<?= $enabled ? '0' : '1' ?>">
                <button class="btn <?= $enabled ? 'btn-outline' : 'btn-primary' ?> btn-sm" type="submit">
                  <?= $enabled ? adm('إيقاف', 'Disable') : adm('تفعيل', 'Enable') ?>
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<section class="card">
  <h2><?= adm('استهلاك مكتبة النماذج', 'Template library usage') ?></h2>
  <?php if (!$templates): ?>
    <p style="color:var(--text-muted)"><?= adm('لا توجد بيانات.', 'No data.') ?></p>
  <?php else: ?>
    <div class="table-wrap table-cards">
      <table class="admin-table">
        <thead><tr>
          <th><?= adm('المكتب', 'Firm') ?></th>
          <th><?= adm('الباقة', 'Plan') ?></th>
          <th><?= adm('القوالب', 'Templates') ?></th>
          <th><?= adm('الحد', 'Ceiling') ?></th>
          <th><?= adm('الاستهلاك', 'Usage') ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($templates as $t): ?>
          <?php
            $used = (int)$t['template_count'];
            $cap = $t['max_templates'] === null ? null : (int)$t['max_templates'];
            $pct = ($cap !== null && $cap > 0) ? min(100, (int)round($used / $cap * 100)) : 0;
            $tone = $cap !== null && $used >= $cap ? 'status-pending' : 'status-active';
          ?>
          <tr>
            <td data-label="<?= adm('المكتب', 'Firm') ?>"><?= ui_e((string)$t['firm_name']) ?></td>
            <td data-label="<?= adm('الباقة', 'Plan') ?>"><?= ui_e((string)($t['plan_name'] ?? '—')) ?></td>
            <td data-label="<?= adm('القوالب', 'Templates') ?>"><?= $used ?></td>
            <td data-label="<?= adm('الحد', 'Ceiling') ?>"><?= $cap === null ? adm('غير محدود', 'Unlimited') : $cap ?></td>
            <td data-label="<?= adm('الاستهلاك', 'Usage') ?>">
              <?php if ($cap === null): ?>
                <span class="status-badge status-active"><?= adm('غير محدود', 'Unlimited') ?></span>
              <?php else: ?>
                <span class="status-badge <?= $tone ?>"><?= $pct ?>%</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
