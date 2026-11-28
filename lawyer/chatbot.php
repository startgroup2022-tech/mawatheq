<?php
/**
 * روبوت محادثة المكتب — إعداد الروبوت وصندوق المحادثات.
 *
 * Two things live on this page because they are two halves of one feature: the bot the
 * firm publishes on its public site, and the conversations that bot produced. The firm
 * configures the bot (name, greeting, what it may draw on) and answers the visitors it
 * converted into leads.
 *
 * Access: `owner`/`partner` may configure the bot; any firm member may read and answer
 * conversations. A solo lawyer is their own firm, so they get both.
 *
 * Every write is scoped by the session's firm, never by a request parameter: a manager of
 * firm A cannot edit firm B's bot even with a guessed id.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/includes/lawyer_guard.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/tenant.php';
require_once __DIR__ . '/../includes/firm_chatbot.php';

$lawyerId = (int)$_SESSION['lawyer_id'];
$tenant = tenant_current($pdo);
$firmId = (int)($tenant['firm_id'] ?? 0);
$role = (string)($tenant['role'] ?? '');
$canManage = !$firmId || in_array($role, ['owner', 'partner'], true);
$isArabic = is_rtl();

$notice = null;
$error = null;

// ---------------------------------------------------------------------------------
// POST must be handled before the shell prints. `lawyer_header.php` emits the whole
// <head> and topbar, so a header('Location:') after it is silently dropped.
// ---------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Invalid request');
    }

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create_bot') {
        if (!$canManage) { http_response_code(403); exit('Forbidden'); }
        $bot = firm_chatbot_ensure($pdo, $firmId, $lawyerId);
        if ($bot) {
            log_action($pdo, 'firm_chatbot_create', 'firm_chatbot', (int)$bot['id']);
            header('Location: /lawyer/chatbot.php?created=1');
            exit;
        }
        $error = $isArabic ? 'تعذّر إنشاء الروبوت.' : 'Could not create the bot.';
    }

    if ($action === 'save_bot') {
        if (!$canManage) { http_response_code(403); exit('Forbidden'); }
        if (firm_chatbot_save($pdo, $firmId, $_POST)) {
            log_action($pdo, 'firm_chatbot_save', 'firm_chatbot', 0);
            header('Location: /lawyer/chatbot.php?saved=1');
            exit;
        }
        $error = $isArabic ? 'تعذّر حفظ الإعدادات.' : 'Could not save the settings.';
    }

    if ($action === 'rotate_key') {
        if (!$canManage) { http_response_code(403); exit('Forbidden'); }
        $newKey = firm_chatbot_rotate_key($pdo, $firmId);
        if ($newKey) {
            log_action($pdo, 'firm_chatbot_rotate_key', 'firm_chatbot', 0);
            header('Location: /lawyer/chatbot.php?rotated=1');
            exit;
        }
        $error = $isArabic ? 'تعذّر تدوير المفتاح.' : 'Could not rotate the key.';
    }

    if ($action === 'reply') {
        $convId = (int)($_POST['conversation_id'] ?? 0);
        $text = trim((string)($_POST['message'] ?? ''));
        // firm_chatbot_conversation() joins the bot and filters on firm_id, so a
        // conversation of another firm resolves to null and the reply is refused.
        $conv = firm_chatbot_conversation($pdo, $convId, $firmId);
        if (!$conv) {
            http_response_code(404);
            exit('Not found');
        }
        if ($text !== '') {
            firm_chatbot_add_message($pdo, $convId, 'lawyer', $text);
            $pdo->prepare("UPDATE chatbot_conversations SET status = 'open' WHERE id = ? AND status = 'handoff_requested'")
                ->execute([$convId]);
            log_action($pdo, 'firm_chatbot_reply', 'chatbot_conversation', $convId);
            header('Location: /lawyer/chatbot.php?c=' . $convId . '&replied=1');
            exit;
        }
        $error = $isArabic ? 'لا يمكن إرسال رسالة فارغة.' : 'An empty message cannot be sent.';
    }

    if ($action === 'close') {
        $convId = (int)($_POST['conversation_id'] ?? 0);
        $conv = firm_chatbot_conversation($pdo, $convId, $firmId);
        if (!$conv) { http_response_code(404); exit('Not found'); }
        $pdo->prepare("UPDATE chatbot_conversations SET status = 'closed' WHERE id = ? AND status <> 'converted'")
            ->execute([$convId]);
        header('Location: /lawyer/chatbot.php');
        exit;
    }
}

if (isset($_GET['created'])) $notice = $isArabic ? 'تم إنشاء الروبوت. فعّله أدناه ليظهر على موقع المكتب.' : 'Bot created. Enable it below to publish it on the firm site.';
if (isset($_GET['saved'])) $notice = $isArabic ? 'تم حفظ الإعدادات.' : 'Settings saved.';
if (isset($_GET['rotated'])) $notice = $isArabic ? 'تم تدوير المفتاح. أي ويدجت منشور بالمفتاح القديم سيتوقف عن العمل.' : 'Key rotated. Any widget using the old key stops working.';

$bot = $firmId ? firm_chatbot_for_firm($pdo, $firmId) : null;
$planAllows = $firmId ? firm_chatbot_plan_allows($pdo, $firmId) : false;
$conversations = $firmId ? firm_chatbot_conversations($pdo, $firmId, '', 50) : [];
$openConvId = (int)($_GET['c'] ?? 0);
$openConv = $openConvId && $firmId ? firm_chatbot_conversation($pdo, $openConvId, $firmId) : null;
$openMessages = $openConv ? firm_chatbot_messages($pdo, (int)$openConv['id'], 200) : [];
$firmRow = null;
if ($firmId) {
    $st = $pdo->prepare('SELECT public_slug, is_public FROM law_firms WHERE id = ?');
    $st->execute([$firmId]);
    $firmRow = $st->fetch() ?: null;
}

require_once __DIR__ . '/includes/lawyer_header.php';
?>
<div class="dash-head">
  <div>
    <h1><?= $isArabic ? 'روبوت محادثة المكتب' : 'Firm chat assistant' ?></h1>
    <p style="color:var(--text-muted)"><?= $isArabic
        ? 'مساعد يجيب زوار موقع المكتب عن التعريف بالمكتب ومجالاته وحجز المواعيد، ويحوّل من يترك رقمه إلى عميل محتمل.'
        : 'An assistant that answers your public site visitors about the firm, its practice areas and booking, and turns a visitor who leaves a number into a lead.' ?></p>
  </div>
</div>

<?php if ($notice): ?><div class="dash-card" style="border-color:#B4D9C4;color:#1F6B43;margin-bottom:14px"><?= htmlspecialchars($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="dash-card" style="border-color:#E5B4B4;color:#8A2B2B;margin-bottom:14px"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php if (!$firmId): ?>
  <div class="dash-card">
    <p><?= $isArabic
        ? 'روبوت المحادثة متاح للمكاتب. أنشئ مكتباً أولاً من صفحة المكتب.'
        : 'The chat assistant is for firms. Create your firm first.' ?></p>
    <a class="btn btn-primary" href="/lawyer/firm.php"><?= $isArabic ? 'صفحة المكتب' : 'Firm page' ?></a>
  </div>
<?php elseif (!$planAllows): ?>
  <div class="dash-card" style="border-color:#E8D5A8;color:#7A5409">
    <strong><?= $isArabic ? 'غير مشمول في باقتك الحالية' : 'Not included in your current plan' ?></strong>
    <p style="margin:8px 0 0"><?= $isArabic
        ? 'روبوت المحادثة متاح في الباقات التي تشمل هذه الميزة. راجع صفحة الباقات للترقية.'
        : 'The chat assistant is available on plans that include it. See the plans page to upgrade.' ?></p>
    <a class="btn btn-outline" href="/pricing.php" style="margin-top:10px"><?= $isArabic ? 'الباقات' : 'Plans' ?></a>
  </div>
<?php elseif (!$bot): ?>
  <div class="dash-card">
    <h3><?= $isArabic ? 'لم يتم إعداد الروبوت بعد' : 'The bot is not set up yet' ?></h3>
    <p style="color:var(--text-muted)"><?= $isArabic
        ? 'أنشئ الروبوت ثم فعّله ليظهر على موقع المكتب العام.'
        : 'Create the bot, then enable it to appear on the firm public site.' ?></p>
    <?php if ($canManage): ?>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="action" value="create_bot">
        <button class="btn btn-primary" type="submit"><?= $isArabic ? 'إنشاء الروبوت' : 'Create the bot' ?></button>
      </form>
    <?php else: ?>
      <p style="color:var(--text-muted)"><?= $isArabic ? 'يُنشئه مالك المكتب أو شريك.' : 'An owner or partner creates it.' ?></p>
    <?php endif; ?>
  </div>
<?php else: ?>

  <div class="dash-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;align-items:start">
    <div class="dash-card">
      <h3><?= $isArabic ? 'إعداد الروبوت' : 'Bot settings' ?></h3>
      <?php if ($canManage): ?>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_bot">
        <label style="display:block;margin-bottom:10px">
          <span style="display:block;font-size:.85rem;color:var(--text-muted);margin-bottom:4px"><?= $isArabic ? 'اسم الروبوت (عربي)' : 'Bot name (Arabic)' ?></span>
          <input class="input" name="display_name_ar" value="<?= htmlspecialchars((string)$bot['display_name_ar']) ?>" style="width:100%">
        </label>
        <label style="display:block;margin-bottom:10px">
          <span style="display:block;font-size:.85rem;color:var(--text-muted);margin-bottom:4px"><?= $isArabic ? 'رسالة الترحيب (عربي)' : 'Greeting (Arabic)' ?></span>
          <textarea class="input" name="greeting_ar" rows="3" style="width:100%"><?= htmlspecialchars((string)$bot['greeting_ar']) ?></textarea>
        </label>
        <label style="display:block;margin-bottom:10px">
          <span style="display:block;font-size:.85rem;color:var(--text-muted);margin-bottom:4px"><?= $isArabic ? 'نطاق المعرفة' : 'Knowledge scope' ?></span>
          <select class="input" name="knowledge_scope" style="width:100%">
            <option value="firm_only" <?= $bot['knowledge_scope'] === 'firm_only' ? 'selected' : '' ?>><?= $isArabic ? 'مكتبي فقط (لا معرفة عامة)' : 'Firm only (no public knowledge)' ?></option>
            <option value="firm_and_public" <?= $bot['knowledge_scope'] === 'firm_and_public' ? 'selected' : '' ?>><?= $isArabic ? 'المكتب والمعرفة العامة' : 'Firm and public knowledge' ?></option>
            <option value="public_only" <?= $bot['knowledge_scope'] === 'public_only' ? 'selected' : '' ?>><?= $isArabic ? 'المعرفة العامة فقط' : 'Public knowledge only' ?></option>
          </select>
        </label>
        <label style="display:block;margin-bottom:10px">
          <span style="display:block;font-size:.85rem;color:var(--text-muted);margin-bottom:4px"><?= $isArabic ? 'رقم واتساب (اختياري)' : 'WhatsApp number (optional)' ?></span>
          <input class="input" name="whatsapp_number" value="<?= htmlspecialchars((string)$bot['whatsapp_number']) ?>" style="width:100%">
        </label>
        <label style="display:flex;gap:8px;align-items:center;margin-bottom:8px">
          <input type="checkbox" name="allow_booking" value="1" <?= (int)$bot['allow_booking'] === 1 ? 'checked' : '' ?>>
          <span><?= $isArabic ? 'السماح بحجز المواعيد' : 'Allow booking' ?></span>
        </label>
        <label style="display:flex;gap:8px;align-items:center;margin-bottom:12px">
          <input type="checkbox" name="allow_handoff" value="1" <?= (int)$bot['allow_handoff'] === 1 ? 'checked' : '' ?>>
          <span><?= $isArabic ? 'السماح بالتحويل إلى محامٍ' : 'Allow hand-off to a lawyer' ?></span>
        </label>
        <label style="display:flex;gap:8px;align-items:center;margin-bottom:12px">
          <input type="checkbox" name="is_enabled" value="1" <?= (int)$bot['is_enabled'] === 1 ? 'checked' : '' ?>>
          <span><strong><?= $isArabic ? 'تفعيل الروبوت على الموقع' : 'Publish the bot on the site' ?></strong></span>
        </label>
        <button class="btn btn-primary" type="submit"><?= $isArabic ? 'حفظ' : 'Save' ?></button>
      </form>
      <?php else: ?>
        <p style="color:var(--text-muted)"><?= $isArabic ? 'يعدّله مالك المكتب أو شريك.' : 'An owner or partner edits it.' ?></p>
      <?php endif; ?>
    </div>

    <div class="dash-card">
      <h3><?= $isArabic ? 'الكود والتضمين' : 'Key and embed' ?></h3>
      <?php if (!$firmRow || !(int)$firmRow['is_public']): ?>
        <div class="dash-card" style="border-color:#E8D5A8;color:#7A5409;margin-bottom:10px">
          <?= $isArabic
              ? 'موقع المكتب غير منشور، لذا لا يظهر الروبوت للزوار. انشره من صفحة موقع المكتب.'
              : 'The firm site is not published, so the bot is not visible to visitors. Publish it from the firm site page.' ?>
        </div>
      <?php endif; ?>
      <p style="color:var(--text-muted);font-size:.9rem"><?= $isArabic
          ? 'ضع هذا الكود في أي صفحة على موقع المكتب. المفتاح عام ولا يمنح أي وصول إلى بيانات العملاء.'
          : 'Place this snippet on any page of the firm site. The key is public and grants no access to client data.' ?></p>
      <pre style="background:#0F1F33;color:#E6EDF5;padding:12px;border-radius:10px;overflow:auto;font-size:.78rem;direction:ltr;text-align:left">&lt;script src="/assets/js/firm-chat-widget.js"
  data-key="<?= htmlspecialchars((string)$bot['public_key']) ?>"
  data-name="<?= htmlspecialchars((string)$bot['display_name_ar']) ?>"&gt;&lt;/script&gt;</pre>
      <p style="font-size:.8rem;color:var(--text-muted);direction:ltr;text-align:left">public key: <code><?= htmlspecialchars((string)$bot['public_key']) ?></code></p>
      <?php if ($canManage): ?>
      <form method="post" onsubmit="return confirm('<?= $isArabic ? 'سيوقف المفتاح الجديد الويدجت المنشور بالمفتاح القديم. متابعة؟' : 'A new key stops the widget published with the old key. Continue?' ?>')">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="action" value="rotate_key">
        <button class="btn btn-outline" type="submit"><?= $isArabic ? 'تدوير المفتاح' : 'Rotate key' ?></button>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="dash-card" style="margin-top:16px">
    <h3><?= $isArabic ? 'المحادثات' : 'Conversations' ?></h3>
    <?php if (!$conversations): ?>
      <p style="color:var(--text-muted)"><?= $isArabic ? 'لا توجد محادثات بعد.' : 'No conversations yet.' ?></p>
    <?php else: ?>
      <div class="table-wrap table-cards">
        <table class="admin-table" style="width:100%">
          <thead><tr>
            <th><?= $isArabic ? 'الزائر' : 'Visitor' ?></th>
            <th><?= $isArabic ? 'الحالة' : 'Status' ?></th>
            <th><?= $isArabic ? 'آخر رسالة' : 'Last message' ?></th>
            <th><?= $isArabic ? 'الرسائل' : 'Messages' ?></th>
            <th><?= $isArabic ? 'آخر تحديث' : 'Updated' ?></th>
            <th></th>
          </tr></thead>
          <tbody>
          <?php foreach ($conversations as $c): ?>
            <tr>
              <td data-label="<?= $isArabic ? 'الزائر' : 'Visitor' ?>"><?= htmlspecialchars((string)($c['visitor_name'] ?: ($isArabic ? 'زائر' : 'Visitor'))) ?></td>
              <td data-label="<?= $isArabic ? 'الحالة' : 'Status' ?>">
                <?php $st = (string)$c['status']; ?>
                <span class="status-badge status-<?= $st === 'converted' ? 'success' : ($st === 'handoff_requested' ? 'pending' : ($st === 'closed' ? 'closed' : 'active')) ?>">
                  <?= htmlspecialchars(match ($st) {
                      'converted' => $isArabic ? 'محوّلة لعميل' : 'Converted',
                      'handoff_requested' => $isArabic ? 'طلب محامٍ' : 'Handoff',
                      'closed' => $isArabic ? 'مغلقة' : 'Closed',
                      default => $isArabic ? 'مفتوحة' : 'Open',
                  }) ?>
                </span>
              </td>
              <td data-label="<?= $isArabic ? 'آخر رسالة' : 'Last message' ?>"><?= htmlspecialchars(mb_substr((string)($c['last_message'] ?? ''), 0, 60, 'UTF-8')) ?></td>
              <td data-label="<?= $isArabic ? 'الرسائل' : 'Messages' ?>"><?= (int)$c['message_count'] ?></td>
              <td data-label="<?= $isArabic ? 'آخر تحديث' : 'Updated' ?>"><?= htmlspecialchars((string)$c['updated_at']) ?></td>
              <td><a class="btn btn-outline" href="/lawyer/chatbot.php?c=<?= (int)$c['id'] ?>"><?= $isArabic ? 'فتح' : 'Open' ?></a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($openConv): ?>
  <div class="dash-card" style="margin-top:16px">
    <h3><?= $isArabic ? 'محادثة #' : 'Conversation #' ?><?= (int)$openConv['id'] ?></h3>
    <div style="max-height:420px;overflow:auto;background:var(--surface);border-radius:10px;padding:12px;margin-bottom:12px">
      <?php foreach ($openMessages as $m): ?>
        <div style="margin-bottom:10px;padding:10px 12px;border-radius:10px;background:<?= $m['sender'] === 'visitor' ? '#fff' : ($m['sender'] === 'assistant' ? '#EEF5FF' : '#F4F8F4') ?>;border:1px solid var(--border)">
          <div style="font-size:.72rem;color:var(--text-muted);margin-bottom:4px">
            <?= htmlspecialchars(match ($m['sender']) {
                'visitor' => $isArabic ? 'الزائر' : 'Visitor',
                'assistant' => $isArabic ? 'الروبوت' : 'Bot',
                'lawyer' => $isArabic ? 'المحامي' : 'Lawyer',
                default => $isArabic ? 'النظام' : 'System',
            }) ?> · <?= htmlspecialchars((string)$m['created_at']) ?>
          </div>
          <div style="white-space:pre-wrap"><?= htmlspecialchars((string)$m['message']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
      <input type="hidden" name="action" value="reply">
      <input type="hidden" name="conversation_id" value="<?= (int)$openConv['id'] ?>">
      <textarea class="input" name="message" rows="3" placeholder="<?= $isArabic ? 'اكتب ردك للزائر…' : 'Write your reply…' ?>" style="width:100%;margin-bottom:8px"></textarea>
      <button class="btn btn-primary" type="submit"><?= $isArabic ? 'إرسال' : 'Send' ?></button>
    </form>
    <form method="post" style="margin-top:8px">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
      <input type="hidden" name="action" value="close">
      <input type="hidden" name="conversation_id" value="<?= (int)$openConv['id'] ?>">
      <button class="btn btn-outline" type="submit"><?= $isArabic ? 'إغلاق المحادثة' : 'Close conversation' ?></button>
    </form>
  </div>
  <?php endif; ?>

<?php endif; ?>
<?php require_once __DIR__ . '/includes/lawyer_footer.php'; ?>
