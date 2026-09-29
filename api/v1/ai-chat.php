<?php
/**
 * POST /api/v1/ai-chat.php
 * body: { "message": "...", "conversation_id": 5 }  — conversation_id اختياري، يُنشأ تلقائياً إن غاب
 * response: { ok, conversation_id, reply, reference, reference_article_id }
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/legal_ai.php';
require_once __DIR__ . '/../../includes/plan_limits.php';

$auth = api_authenticate($pdo);
if ($auth['type'] !== 'client') api_error('only clients can use the AI assistant', 403);

$in = api_input();
$message = trim($in['message'] ?? '');
if (!$message) api_error('message is required');

$conversationId = (int)($in['conversation_id'] ?? 0);
if ($conversationId) {
    $check = $pdo->prepare('SELECT id FROM ai_conversations WHERE id=? AND user_id=?');
    $check->execute([$conversationId, $auth['id']]);
    if (!$check->fetch()) api_error('conversation not found', 404);
} else {
    $pdo->prepare('INSERT INTO ai_conversations (user_id) VALUES (?)')->execute([$auth['id']]);
    $conversationId = (int)$pdo->lastInsertId();
}

$pdo->prepare('INSERT INTO ai_messages (conversation_id, sender, message) VALUES (?,?,?)')->execute([$conversationId, 'user', $message]);

$quota = check_ai_query_allowed($pdo, $auth['id']);
if (!$quota['allowed']) {
    $pdo->prepare('INSERT INTO ai_messages (conversation_id, sender, message) VALUES (?,?,?)')->execute([$conversationId, 'assistant', $quota['message']]);
    api_json(['ok' => true, 'conversation_id' => $conversationId, 'reply' => $quota['message'], 'reference' => null, 'reference_article_id' => null, 'sources'=>[], 'limit_reached' => true]);
}

$settings = ai_load_settings($pdo);
$isArabic = true; // تطبيق الجوال يرسل نص العميل، لغة الرد الافتراضية عربي ما لم يُحدَّد غير ذلك لاحقاً
$answer = generate_grounded_legal_answer($message, $isArabic, $settings, $pdo, (int)$auth['id'], 'client');
$reply = $answer['reply']; $reference = $answer['reference']; $referenceArticleId = $answer['reference_article_id'];

$sourceJson = json_encode($answer['sources'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$pdo->prepare('INSERT INTO ai_messages (conversation_id, sender, message, reference_law, reference_article_id, source_citations_json) VALUES (?,?,?,?,?,?)')
    ->execute([$conversationId, 'assistant', $reply, $reference, $referenceArticleId, $sourceJson]);

api_json(['ok' => true, 'conversation_id' => $conversationId, 'reply' => $reply, 'reference' => $reference, 'reference_article_id' => $referenceArticleId, 'sources'=>$answer['sources'] ?? []]);
