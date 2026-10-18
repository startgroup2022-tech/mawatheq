<?php
/**
 * سجل تدقيق (Audit Log) للعمليات الحساسة بلوحة التحكم — من فعل ماذا ومتى.
 * لا يسجّل قيماً حساسة (مثل مفاتيح API) بنص صريح، فقط وصف الإجراء.
 */
function log_action(PDO $pdo, string $action, ?string $targetType = null, ?int $targetId = null): void {
    $actorType = !empty($_SESSION['admin_id']) ? 'admin' : (!empty($_SESSION['lawyer_id']) ? 'lawyer' : 'system');
    $actorId = $_SESSION['admin_id'] ?? $_SESSION['lawyer_id'] ?? null;
    $actorName = $_SESSION['admin_name'] ?? $_SESSION['lawyer_name'] ?? 'النظام';
    $firmId = null;
    if ($actorType === 'lawyer' && function_exists('tenant_firm_id')) {
        $firmId = tenant_firm_id($pdo);
    }
    try {
        $pdo->prepare('INSERT INTO audit_logs (actor_type, actor_id, actor_name, firm_id, action, target_type, target_id) VALUES (?,?,?,?,?,?,?)')
            ->execute([$actorType, $actorId, $actorName, $firmId, $action, $targetType, $targetId]);
    } catch (PDOException $e) {
        // An unavailable audit table must not turn a successful admin action into an HTTP 500.
        error_log('Audit log write failed: ' . $e->getMessage());
    }
}
