<?php
/**
 * GET  /api/v1/ai-agents.php            → list the caller's AI agents + available tools
 * POST /api/v1/ai-agents.php            → create/update an agent { action, ... }
 * POST /api/v1/ai-agents.php?run=1      → run a tool through an agent (reads + writes)
 *
 * Write tools are refused unless the request carries an explicit confirmation for the
 * exact tool: { "confirm_tool": "<tool>", "confirm": true }. That check is enforced in
 * application code (includes/ai_tools.php), not by the model.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/tenant.php';
require_once __DIR__ . '/../../includes/ai_tools.php';
require_once __DIR__ . '/../../includes/ai_gateway.php';

$auth = api_authenticate($pdo);
if (!in_array($auth['type'], ['lawyer', 'client'], true)) api_error('unsupported account type', 403);

$ownerType = $auth['type'];
$ownerId = (int)$auth['id'];
$tenant = $ownerType === 'lawyer' ? ai_gateway_tenant_context($pdo, 'lawyer', $ownerId) : ['tenant_type' => 'platform', 'tenant_id' => 0, 'firm_id' => null];
$tenantType = $tenant['tenant_type'] === 'firm' ? 'firm' : ($ownerType === 'lawyer' ? 'solo' : 'solo');
$firmId = $tenant['firm_id'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $stmt = $pdo->prepare('SELECT id,name,description,allowed_tools,allowed_data_sources,status,created_at FROM ai_user_agents WHERE owner_type=? AND owner_id=? ORDER BY id DESC');
    $stmt->execute([$ownerType, $ownerId]);
    $agents = $stmt->fetchAll();
    $tools = [];
    foreach (ai_tool_registry() as $key => $meta) {
        $tools[] = ['tool' => $key, 'kind' => $meta['kind'], 'requires_confirmation' => $meta['requires_confirmation'],
            'label_ar' => $meta['label_ar'], 'label_en' => $meta['label_en'], 'data_category' => $meta['data_category']];
    }
    api_json(['ok' => true, 'agents' => $agents, 'tools' => $tools, 'privacy_mode' => ai_tenant_policy($pdo, $tenant['tenant_type'], (int)$tenant['tenant_id'])['privacy_mode']]);
}

$in = api_input();
$action = (string)($in['action'] ?? '');

if (isset($_GET['run']) || $action === 'run') {
    $agentId = (int)($in['agent_id'] ?? 0);
    $tool = trim((string)($in['tool'] ?? ''));
    $args = is_array($in['args'] ?? null) ? $in['args'] : [];
    $stmt = $pdo->prepare('SELECT * FROM ai_user_agents WHERE id=? AND owner_type=? AND owner_id=?');
    $stmt->execute([$agentId, $ownerType, $ownerId]);
    $agent = $stmt->fetch();
    if (!$agent) api_error('agent not found', 404);
    if ($agent['status'] !== 'active') api_error('agent is disabled', 409);
    if (!ai_tool_is_allowed((string)$agent['allowed_tools'], $tool)) {
        ai_agent_log_activity($pdo, $agentId, $ownerType, $ownerId, $tenantType, $firmId, $tool !== '' ? $tool : 'unknown', 'denied', 'not_required', 'tool not in the agent allow-list');
        api_error('this tool is not allowed for the agent', 403);
    }
    $registry = ai_tool_registry()[$tool];
    if ($registry['kind'] === 'read') {
        $result = ai_tool_read($pdo, $tool, $ownerType, $ownerId, $args);
        ai_agent_log_activity($pdo, $agentId, $ownerType, $ownerId, $tenantType, $firmId, $tool, $result['ok'] ? 'success' : 'failed', 'not_required', $result['error'] ?? null);
        if (!$result['ok']) api_error($result['error'] ?? 'tool failed', 400);
        api_json(['ok' => true, 'tool' => $tool, 'data' => $result['data'] ?? []]);
    }
    $confirmation = ['tool' => (string)($in['confirm_tool'] ?? ''), 'confirmed' => !empty($in['confirm']), 'actor_id' => $ownerId];
    $result = ai_tool_write($pdo, $tool, $ownerType, $ownerId, $args, $confirmation);
    $state = $confirmation['confirmed'] ? 'confirmed' : 'pending';
    ai_agent_log_activity($pdo, $agentId, $ownerType, $ownerId, $tenantType, $firmId, $tool, $result['ok'] ? 'success' : 'denied', $state, $result['error'] ?? null);
    if (!$result['ok']) api_error($result['error'] ?? 'tool failed', $result['error'] === 'confirmation_required' ? 409 : 400);
    api_json(['ok' => true, 'tool' => $tool, 'result' => $result['result'] ?? []]);
}

if ($action === 'save') {
    $id = (int)($in['agent_id'] ?? 0);
    $name = trim((string)($in['name'] ?? ''));
    $description = trim((string)($in['description'] ?? ''));
    $persona = trim((string)($in['persona_prompt'] ?? ''));
    $allowedTools = array_values(array_intersect(array_map('trim', (array)($in['allowed_tools'] ?? [])), array_keys(ai_tool_registry())));
    $dataSources = array_slice(array_values(array_unique(array_filter(array_map('trim', (array)($in['allowed_data_sources'] ?? []))))), 0, 20);
    $status = ($in['status'] ?? 'active') === 'disabled' ? 'disabled' : 'active';
    if ($name === '' || mb_strlen($name, 'UTF-8') > 150) api_error('name is required (max 150 chars)');
    if (mb_strlen($description, 'UTF-8') > 500 || mb_strlen($persona, 'UTF-8') > 4000) api_error('description or persona is too long');
    if (!$allowedTools) api_error('select at least one allowed tool');
    if ($id > 0) {
        $check = $pdo->prepare('SELECT id FROM ai_user_agents WHERE id=? AND owner_type=? AND owner_id=?');
        $check->execute([$id, $ownerType, $ownerId]);
        if (!$check->fetchColumn()) api_error('agent not found', 404);
        $pdo->prepare('UPDATE ai_user_agents SET name=?,description=?,persona_prompt=?,allowed_tools=?,allowed_data_sources=?,status=? WHERE id=? AND owner_type=? AND owner_id=?')
            ->execute([$name, $description ?: null, $persona ?: null, implode(',', $allowedTools), implode(',', $dataSources), $status, $id, $ownerType, $ownerId]);
        api_json(['ok' => true, 'agent_id' => $id, 'updated' => true]);
    }
    $pdo->prepare('INSERT INTO ai_user_agents (owner_type,owner_id,tenant_type,firm_id,name,description,persona_prompt,allowed_tools,allowed_data_sources,status) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$ownerType, $ownerId, $tenantType, $firmId, $name, $description ?: null, $persona ?: null, implode(',', $allowedTools), implode(',', $dataSources), $status]);
    api_json(['ok' => true, 'agent_id' => (int)$pdo->lastInsertId(), 'created' => true], 201);
}

api_error('unsupported action', 400);
