<?php
/**
 * سجل تدقيق (Audit Log) للعمليات الحساسة بلوحة التحكم — من فعل ماذا ومتى.
 * لا يسجّل قيماً حساسة (مثل مفاتيح API) بنص صريح، فقط وصف الإجراء.
 */
function log_action(PDO $pdo, string $action, ?string $targetType = null, ?int $targetId = null): void {
    $actorType = !empty($_SESSION['admin_id']) ? 'admin' : (!empty($_SESSION['lawyer_id']) ? 'lawyer' : 'system');
    $actorId = $_SESSION['admin_id'] ?? $_SESSION['lawyer_id'] ?? null;
    $actorName = $_SESSION['admin_name'] ?? $_SESSION['lawyer_name'] ?? 'النظام';
    try {
        $pdo->prepare('INSERT INTO audit_logs (actor_type, actor_id, actor_name, action, target_type, target_id) VALUES (?,?,?,?,?,?)')
            ->execute([$actorType, $actorId, $actorName, $action, $targetType, $targetId]);
    } catch (PDOException $e) {
        // An unavailable audit table must not turn a successful admin action into an HTTP 500.
        error_log('Audit log write failed: ' . $e->getMessage());
    }
}
