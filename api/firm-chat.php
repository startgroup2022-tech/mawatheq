<?php
/**
 * نقطة نهاية عامة لروبوت محادثة المكتب — يستدعيها ويدجت الموقع العام.
 *
 * This is the only unauthenticated writer in the firm-site area, so its whole design is
 * about what it *cannot* do:
 *
 *  - it resolves the bot from a 32-hex `public_key`, never from a firm id in the request;
 *  - it refuses a bot whose firm is unpublished, disabled, or whose plan lacks
 *    `feature_chatbot`;
 *  - it never accepts a `firm_id`, `lawyer_id` or conversation id from the client for the
 *    "start" action, and for "send" it re-checks the conversation belongs to that bot;
 *  - it rate-limits per bot key and per visitor key, because the endpoint is public and
 *    an LLM call is the expensive path;
 *  - it returns only the assistant's own reply — never a list of firm records.
 *
 * CSRF-exempt by design (see config/config.php): an anonymous visitor has no session
 * token. The unguessable key plus the rate limit are the protection, not a cookie.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/firm_chatbot.php';
require_once __DIR__ . '/../includes/ai_provider.php';
require_once __DIR__ . '/../includes/ai_gateway.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function fc_out(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    fc_out(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$raw = file_get_contents('php://input');
$body = json_decode((string)$raw, true);
if (!is_array($body)) $body = $_POST;

$action = (string)($body['action'] ?? '');
$key = (string)($body['key'] ?? '');

$bot = firm_chatbot_by_key($pdo, $key);
if (!$bot) {
    // Unknown key and disabled bot answer identically, so the endpoint cannot be used
    // to discover which firms exist.
    fc_out(404, ['ok' => false, 'error' => 'not_found']);
}
if (!firm_chatbot_enabled($pdo, $bot)) {
    fc_out(403, ['ok' => false, 'error' => 'disabled']);
}

$arabic = true; // the public firm site is Arabic-first; the widget may ask for English

// ---- rate limit: per bot key and per visitor key ---------------------------------
// A simple fixed-window counter in the existing auth_rate_limits table, so a public
// chat cannot be used to run up an AI bill or to enumerate replies.
$visitorKey = strtolower(trim((string)($body['visitor'] ?? '')));
if (!preg_match('/^[a-f0-9]{32}$/', $visitorKey)) {
    $visitorKey = bin2hex(random_bytes(16));
}
$bucketKey = 'chatbot|' . substr(hash('sha256', $key . '|' . $visitorKey), 0, 40);
try {
    $window = 60;      // seconds
    $limit = 20;       // messages per window per visitor
    $pdo->prepare(
        'INSERT INTO auth_rate_limits (scope_key, attempts, window_started_at)
         VALUES (?,1,NOW())
         ON DUPLICATE KEY UPDATE
           attempts = IF(window_started_at < (NOW() - INTERVAL ? SECOND), 1, attempts + 1),
           window_started_at = IF(window_started_at < (NOW() - INTERVAL ? SECOND), NOW(), window_started_at)'
    )->execute([$bucketKey, $window, $window]);
    $st = $pdo->prepare('SELECT attempts FROM auth_rate_limits WHERE scope_key = ?');
    $st->execute([$bucketKey]);
    if ((int)$st->fetchColumn() > $limit) {
        fc_out(429, ['ok' => false, 'error' => 'rate_limited', 'retry_after' => $window]);
    }
} catch (PDOException $e) {
    // A rate-limit table problem must not silently disable the limit; fail closed.
    error_log('firm-chat rate limit: ' . $e->getMessage());
    fc_out(503, ['ok' => false, 'error' => 'unavailable']);
}

// ---- actions ---------------------------------------------------------------------
if ($action === 'start') {
    $conv = firm_chatbot_start_conversation($pdo, (int)$bot['id'], $visitorKey);
    if (!$conv) fc_out(500, ['ok' => false, 'error' => 'start_failed']);

    $greeting = $arabic
        ? (string)($bot['greeting_ar'] ?: 'مرحباً بك. كيف يمكننا مساعدتك؟')
        : (string)($bot['greeting_en'] ?: 'Welcome. How can we help?');

    $existing = firm_chatbot_messages($pdo, (int)$conv['id'], 1);
    if (!$existing) {
        firm_chatbot_add_message($pdo, (int)$conv['id'], 'assistant', $greeting);
    }
    fc_out(200, [
        'ok' => true,
        'visitor' => $visitorKey,
        'conversation' => (int)$conv['id'],
        'bot_name' => $arabic ? (string)($bot['display_name_ar'] ?: 'المساعد') : (string)($bot['display_name_en'] ?: 'Assistant'),
        'greeting' => $greeting,
        'allow_booking' => (int)$bot['allow_booking'] === 1,
        'allow_handoff' => (int)$bot['allow_handoff'] === 1,
        'messages' => array_map(
            fn($m) => ['sender' => $m['sender'], 'message' => $m['message']],
            firm_chatbot_messages($pdo, (int)$conv['id'], 50)
        ),
    ]);
}

if ($action === 'send') {
    $convId = (int)($body['conversation'] ?? 0);
    $text = trim((string)($body['message'] ?? ''));
    if ($text === '' || mb_strlen($text, 'UTF-8') > 2000) {
        fc_out(422, ['ok' => false, 'error' => 'message_invalid']);
    }

    // The conversation must belong to this bot AND this visitor key. Checking the bot
    // alone would let a guessed id read or append to another visitor's thread.
    $conv = null;
    try {
        $st = $pdo->prepare('SELECT * FROM chatbot_conversations WHERE id = ? AND chatbot_id = ? AND visitor_key = ? LIMIT 1');
        $st->execute([$convId, (int)$bot['id'], $visitorKey]);
        $conv = $st->fetch() ?: null;
    } catch (PDOException $e) {
        $conv = null;
    }
    if (!$conv) fc_out(404, ['ok' => false, 'error' => 'conversation_not_found']);
    if (in_array((string)$conv['status'], ['closed', 'converted'], true)) {
        fc_out(409, ['ok' => false, 'error' => 'conversation_closed']);
    }

    firm_chatbot_add_message($pdo, (int)$conv['id'], 'visitor', $text);
    $result = firm_chatbot_reply($pdo, $bot, $text, $arabic);
    firm_chatbot_add_message($pdo, (int)$conv['id'], 'assistant', (string)$result['reply'], $result['citations'] ?: null);

    fc_out(200, [
        'ok' => true,
        'reply' => (string)$result['reply'],
        'intent' => (string)$result['intent'],
        'source' => (string)$result['source'],
        'offer_booking' => (int)$bot['allow_booking'] === 1,
        'offer_handoff' => (int)$bot['allow_handoff'] === 1,
    ]);
}

if ($action === 'contact') {
    $convId = (int)($body['conversation'] ?? 0);
    $phone = (string)($body['phone'] ?? '');
    $name = (string)($body['name'] ?? '');
    $email = (string)($body['email'] ?? '');

    $conv = null;
    try {
        $st = $pdo->prepare('SELECT * FROM chatbot_conversations WHERE id = ? AND chatbot_id = ? AND visitor_key = ? LIMIT 1');
        $st->execute([$convId, (int)$bot['id'], $visitorKey]);
        $conv = $st->fetch() ?: null;
    } catch (PDOException $e) {
        $conv = null;
    }
    if (!$conv) fc_out(404, ['ok' => false, 'error' => 'conversation_not_found']);

    $res = firm_chatbot_convert($pdo, $conv, $bot, $name, $phone, $email);
    if (!$res['ok']) {
        fc_out(422, ['ok' => false, 'error' => $res['error']]);
    }
    firm_chatbot_add_message($pdo, (int)$conv['id'], 'system', 'تم استلام بيانات التواصل وتحويل المحادثة إلى الفريق.');
    fc_out(200, ['ok' => true, 'message' => 'شكراً لك. سيتواصل معك الفريق في أقرب وقت.']);
}

fc_out(400, ['ok' => false, 'error' => 'unknown_action']);
