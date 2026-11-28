<?php
/**
 * GET /api/v1/chatbot.php                  → إعدادات روبوت المكتب + إحصاء المحادثات
 * GET /api/v1/chatbot.php?scope=conversations → محادثات الروبوت
 * GET /api/v1/chatbot.php?id=9             → محادثة واحدة مع رسائلها
 *
 * The lawyer side of the firm chatbot. The public widget talks to `api/firm-chat.php`,
 * which resolves the bot from its 32-hex public key and never trusts a firm id. This
 * endpoint is the opposite direction: it is authenticated, resolves the firm from the
 * session, and is the only place a conversation can be read back.
 *
 * A conversation is scoped to the firm twice — `firm_chatbot_conversation()` joins the bot
 * and filters on its firm id, and the id in the request is only ever used to look *within*
 * that firm. Passing another firm's conversation id returns null, not a row.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/tenant.php';
require_once __DIR__ . '/../../includes/firm_chatbot.php';

$auth = api_authenticate($pdo);
if (($auth['type'] ?? '') !== 'lawyer') {
    api_error('هذا العنصر متاح للمحامين فقط.', 403);
}
$lawyerId = (int)$auth['id'];
$ctx = tenant_context_for_lawyer($pdo, $lawyerId);
$firmId = !empty($ctx['firm_id']) ? (int)$ctx['firm_id'] : 0;

if ($firmId <= 0) {
    // A solo lawyer has no firm, and the chatbot is a firm feature. Reporting the reason is
    // more useful than an empty object that looks like a broken bot.
    api_json(['ok' => true, 'available' => false, 'reason' => 'no_firm', 'bot' => null, 'conversations' => []]);
}

if (!firm_chatbot_ready($pdo)) {
    api_json(['ok' => true, 'available' => false, 'reason' => 'migration_required', 'bot' => null, 'conversations' => []]);
}

$conversationId = (int)($_GET['id'] ?? 0);
if ($conversationId > 0) {
    $conversation = firm_chatbot_conversation($pdo, $conversationId, $firmId);
    if (!$conversation) api_error('المحادثة غير موجودة.', 404);
    $msg = $pdo->prepare('SELECT id, sender, message, citations_json, created_at FROM chatbot_messages WHERE conversation_id = ? ORDER BY id');
    $msg->execute([$conversationId]);
    $conversation['messages'] = $msg->fetchAll();
    api_json(['ok' => true, 'available' => true, 'conversation' => $conversation]);
}

$bot = firm_chatbot_for_firm($pdo, $firmId);
$planAllows = firm_chatbot_plan_allows($pdo, $firmId);

if (($_GET['scope'] ?? '') === 'conversations') {
    $status = (string)($_GET['status'] ?? '');
    api_json([
        'ok' => true,
        'available' => $bot !== null && $planAllows,
        'conversations' => firm_chatbot_conversations($pdo, $firmId, $status),
    ]);
}

$stats = ['total' => 0, 'open' => 0, 'converted' => 0];
if ($bot) {
    $s = $pdo->prepare(
        "SELECT COUNT(*) AS total,
                SUM(status = 'open') AS open_count,
                SUM(status = 'converted') AS converted_count
           FROM chatbot_conversations WHERE chatbot_id = ?"
    );
    $s->execute([(int)$bot['id']]);
    $row = $s->fetch() ?: [];
    $stats = [
        'total' => (int)($row['total'] ?? 0),
        'open' => (int)($row['open_count'] ?? 0),
        'converted' => (int)($row['converted_count'] ?? 0),
    ];
}

api_json([
    'ok' => true,
    'available' => $bot !== null && $planAllows,
    'plan_allows' => $planAllows,
    'bot' => $bot ? [
        'id' => (int)$bot['id'],
        'public_key' => $bot['public_key'],
        'display_name_ar' => $bot['display_name_ar'],
        'display_name_en' => $bot['display_name_en'],
        'greeting_ar' => $bot['greeting_ar'],
        'greeting_en' => $bot['greeting_en'],
        'is_enabled' => (bool)$bot['is_enabled'],
        'allow_booking' => (bool)$bot['allow_booking'],
        'allow_handoff' => (bool)$bot['allow_handoff'],
        'whatsapp_number' => $bot['whatsapp_number'],
    ] : null,
    'stats' => $stats,
]);
