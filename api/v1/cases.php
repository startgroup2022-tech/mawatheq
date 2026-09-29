<?php
/**
 * GET /api/v1/cases.php               → قائمة قضاياي
 * GET /api/v1/cases.php?id=5          → تفاصيل قضية واحدة (مع الأطراف/الادعاءات/المهام/الجدول الزمني)
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/tenant.php';
$auth = api_authenticate($pdo);

$caseId = (int)($_GET['id'] ?? 0);

if ($caseId) {
    if ($auth['type'] === 'lawyer') {
        $caseScope = tenant_owner_where_for_lawyer($pdo, $auth['id'], 'c');
        $stmt = $pdo->prepare('SELECT c.*, u.full_name AS client_name, u.email AS client_email FROM cases c JOIN users u ON u.id=c.user_id WHERE c.id=? AND ' . $caseScope['sql']);
        $stmt->execute(array_merge([$caseId], $caseScope['params']));
    } else {
        $stmt = $pdo->prepare('SELECT c.*, l.full_name AS lawyer_name FROM cases c JOIN lawyers l ON l.id=c.lawyer_id WHERE c.id=? AND c.user_id=?');
        $stmt->execute([$caseId, $auth['id']]);
    }
    $case = $stmt->fetch();
    if (!$case) api_error('case not found', 404);

    $timeline = $pdo->prepare('SELECT * FROM case_timeline WHERE case_id=? ORDER BY event_date DESC');
    $timeline->execute([$caseId]);

    $parties = $pdo->prepare('SELECT * FROM case_parties WHERE case_id=? ORDER BY id');
    $parties->execute([$caseId]);

    $claims = $pdo->prepare('SELECT * FROM case_claims WHERE case_id=? ORDER BY id');
    $claims->execute([$caseId]);

    $tasks = $auth['type'] === 'lawyer'
        ? $pdo->prepare('SELECT * FROM case_tasks WHERE case_id=? ORDER BY (due_date IS NULL), due_date, id')
        : $pdo->prepare('SELECT * FROM case_tasks WHERE case_id=? AND client_visible=1 ORDER BY (due_date IS NULL), due_date, id');
    $tasks->execute([$caseId]);

    $evidence = $auth['type'] === 'lawyer'
        ? $pdo->prepare('SELECT id,title,evidence_type,description,related_claim_id,ai_summary,ai_findings,ai_analyzed_at,created_at FROM case_evidence WHERE case_id=? ORDER BY created_at DESC')
        : $pdo->prepare('SELECT id,title,evidence_type,description,related_claim_id,ai_summary,ai_analyzed_at,created_at FROM case_evidence WHERE case_id=? AND 1=0');
    $evidence->execute([$caseId]);

    api_json([
        'ok' => true,
        'case' => $case,
        'timeline' => $timeline->fetchAll(),
        'parties' => $parties->fetchAll(),
        'claims' => $claims->fetchAll(),
        'tasks' => $tasks->fetchAll(),
        'evidence' => $evidence->fetchAll(),
    ]);
}

if ($auth['type'] === 'lawyer') {
    $listScope = tenant_owner_where_for_lawyer($pdo, $auth['id'], 'c');
    $stmt = $pdo->prepare('SELECT c.*, u.full_name AS client_name FROM cases c JOIN users u ON u.id=c.user_id WHERE ' . $listScope['sql'] . ' ORDER BY c.updated_at DESC');
    $stmt->execute($listScope['params']);
} else {
    $stmt = $pdo->prepare('SELECT c.*, l.full_name AS lawyer_name FROM cases c JOIN lawyers l ON l.id=c.lawyer_id WHERE c.user_id=? ORDER BY c.updated_at DESC');
    $stmt->execute([$auth['id']]);
}
api_json(['ok' => true, 'cases' => $stmt->fetchAll()]);
