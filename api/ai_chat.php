<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
require_once __DIR__ . '/../includes/legal_ai.php';
require_once __DIR__ . '/../includes/plan_limits.php';
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$conversationId = (int)($_POST['conversation_id'] ?? 0);
$message = trim($_POST['message'] ?? '');

if (!$conversationId || !$message) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_request']);
    exit;
}

// تأكيد ملكية المحادثة
$stmt = $pdo->prepare('SELECT id FROM ai_conversations WHERE id=? AND user_id=?');
$stmt->execute([$conversationId, $_SESSION['user_id']]);
if (!$stmt->fetch()) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

// تطبيق حد الباقة الفعلي على عدد استفسارات المساعد الذكي شهرياً (إن كانت الباقة تحدد ذلك)
$quota = check_ai_query_allowed($pdo, (int)$_SESSION['user_id']);
if (!$quota['allowed']) {
    $pdo->prepare('INSERT INTO ai_messages (conversation_id, sender, message) VALUES (?,?,?)')
        ->execute([$conversationId, 'assistant', $quota['message']]);
    echo json_encode(['reply' => $quota['message'], 'reference' => null, 'reference_article_id' => null, 'limit_reached' => true]);
    exit;
}

// حفظ رسالة المستخدم
$pdo->prepare('INSERT INTO ai_messages (conversation_id, sender, message) VALUES (?,?,?)')
    ->execute([$conversationId, 'user', $message]);

// جلب إعدادات مزوّد الذكاء الاصطناعي من قاعدة البيانات (لوحة التحكم → الإعدادات)
$settings = ai_load_settings($pdo);
// التكامل مفعّل فقط إن كان هناك مزوّد ومفتاح، وإن لم يكن الأدمن قد عطّله يدوياً
$aiEnabled = !empty($settings['ai_provider']) && !empty($settings['ai_api_key']) && ($settings['ai_enabled'] ?? '1') !== '0';

$answer = generate_grounded_legal_answer($message, current_lang() === 'ar', $settings, $pdo, (int)$_SESSION['user_id'], 'client');
$reply = $answer['reply'];
$reference = $answer['reference'];
$referenceArticleId = $answer['reference_article_id'];

$sourceJson = json_encode($answer['sources'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$pdo->prepare('INSERT INTO ai_messages (conversation_id, sender, message, reference_law, reference_article_id, source_citations_json) VALUES (?,?,?,?,?,?)')
    ->execute([$conversationId, 'assistant', $reply, $reference, $referenceArticleId, $sourceJson]);

echo json_encode(['reply' => $reply, 'reference' => $reference, 'reference_article_id' => $referenceArticleId, 'sources' => $answer['sources'] ?? []]);
