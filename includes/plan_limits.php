<?php
/**
 * ربط الباقات (plans) فعلياً بحدود استخدام العميل: عدد استشارات الذكاء
 * الاصطناعي وعدد تحليلات المستندات شهرياً. القيم NULL تعني "غير محدود".
 * إن لم يكن للمستخدم باقة (plan_id فارغ)، لا يُطبَّق أي حد (سلوك متوافق
 * مع الحسابات القديمة قبل تفعيل نظام الباقات).
 */

function get_user_plan(PDO $pdo, int $userId): ?array {
    $stmt = $pdo->prepare('
        SELECT p.* FROM users u JOIN plans p ON p.id = u.plan_id
        WHERE u.id = ?
    ');
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}

function count_ai_queries_this_month(PDO $pdo, int $userId): int {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) c FROM ai_messages m
        JOIN ai_conversations c ON c.id = m.conversation_id
        WHERE c.user_id = ? AND m.sender = 'user' AND m.created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
    ");
    $stmt->execute([$userId]);
    return (int)$stmt->fetch()['c'];
}

function count_documents_this_month(PDO $pdo, int $userId): int {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) c FROM documents
        WHERE user_id = ? AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
    ");
    $stmt->execute([$userId]);
    return (int)$stmt->fetch()['c'];
}

/** @return array{allowed:bool, used:int, limit:?int, message:?string} */
function check_ai_query_allowed(PDO $pdo, int $userId): array {
    $plan = get_user_plan($pdo, $userId);
    if (!$plan || $plan['ai_queries_limit'] === null) {
        return ['allowed' => true, 'used' => 0, 'limit' => null, 'message' => null];
    }
    $used = count_ai_queries_this_month($pdo, $userId);
    $limit = (int)$plan['ai_queries_limit'];
    if ($used >= $limit) {
        $msg = is_rtl()
            ? "وصلت للحد الأقصى ({$limit}) لاستفسارات المساعد الذكي ضمن باقتك (" . $plan['name_ar'] . ') لهذا الشهر. يمكنك الترقية لباقة أعلى من صفحة الباقات.'
            : "You've reached your plan's ({$plan['name_en']}) limit of {$limit} AI queries this month. You can upgrade your plan from the Pricing page.";
        return ['allowed' => false, 'used' => $used, 'limit' => $limit, 'message' => $msg];
    }
    return ['allowed' => true, 'used' => $used, 'limit' => $limit, 'message' => null];
}

/** @return array{allowed:bool, used:int, limit:?int, message:?string} */
function check_document_upload_allowed(PDO $pdo, int $userId): array {
    $plan = get_user_plan($pdo, $userId);
    if (!$plan || $plan['document_analysis_limit'] === null) {
        return ['allowed' => true, 'used' => 0, 'limit' => null, 'message' => null];
    }
    $used = count_documents_this_month($pdo, $userId);
    $limit = (int)$plan['document_analysis_limit'];
    if ($used >= $limit) {
        $msg = is_rtl()
            ? "وصلت للحد الأقصى ({$limit}) لتحليل المستندات ضمن باقتك (" . $plan['name_ar'] . ') لهذا الشهر. يمكنك الترقية لباقة أعلى من صفحة الباقات.'
            : "You've reached your plan's ({$plan['name_en']}) limit of {$limit} document analyses this month. You can upgrade your plan from the Pricing page.";
        return ['allowed' => false, 'used' => $used, 'limit' => $limit, 'message' => $msg];
    }
    return ['allowed' => true, 'used' => $used, 'limit' => $limit, 'message' => null];
}

/** الباقة الافتراضية للحسابات الجديدة: أرخص باقة نشطة (تُعامَل كالباقة المجانية)، أو null إن لم توجد أي باقة بعد. */
function default_plan_id(PDO $pdo): ?int {
    $stmt = $pdo->query("SELECT id FROM plans WHERE status='active' ORDER BY price_monthly ASC, sort_order ASC LIMIT 1");
    $row = $stmt->fetch();
    return $row ? (int)$row['id'] : null;
}
