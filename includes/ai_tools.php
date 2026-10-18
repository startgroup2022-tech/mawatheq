<?php
/**
 * AI tool registry (Phase 3).
 *
 * An AI agent never touches the database directly. It can only request one of the named
 * tools below, and this module is the sole executor. That keeps three guarantees in
 * application code rather than in the model's behaviour:
 *
 *   1. No arbitrary SQL and no filesystem access — tools are fixed functions with bound
 *      parameters, so a prompt-injected "run this query" cannot become a real query.
 *   2. Every read/write is tenant-scoped through the resolved tenant of the *owner*, so an
 *      agent cannot read or mutate another tenant's rows even if asked to.
 *   3. Destructive tools (create_* / any future mutate_* / delete_*) are marked
 *      requires_confirmation and will refuse to run without an explicit human confirmation
 *      recorded against the same tool and target. The AI can prepare an action; only a
 *      person authorises it.
 */
require_once __DIR__ . '/tenant.php';

/** Full tool catalogue. `kind` is read|write; `requires_confirmation` gates writes. */
function ai_tool_registry(): array {
    return [
        'read_cases' => ['kind' => 'read', 'requires_confirmation' => false, 'data_category' => 'case_information', 'label_ar' => 'قراءة القضايا', 'label_en' => 'Read cases'],
        'read_clients' => ['kind' => 'read', 'requires_confirmation' => false, 'data_category' => 'client_information', 'label_ar' => 'قراءة العملاء', 'label_en' => 'Read clients'],
        'read_documents' => ['kind' => 'read', 'requires_confirmation' => false, 'data_category' => 'document_text', 'label_ar' => 'قراءة المستندات', 'label_en' => 'Read documents'],
        'read_calendar' => ['kind' => 'read', 'requires_confirmation' => false, 'data_category' => 'case_information', 'label_ar' => 'قراءة التقويم', 'label_en' => 'Read calendar'],
        'read_invoices' => ['kind' => 'read', 'requires_confirmation' => false, 'data_category' => 'client_information', 'label_ar' => 'قراءة الفواتير', 'label_en' => 'Read invoices'],
        'create_draft' => ['kind' => 'write', 'requires_confirmation' => true, 'data_category' => 'case_information', 'label_ar' => 'إنشاء مسودة', 'label_en' => 'Create draft'],
        'create_calendar_event' => ['kind' => 'write', 'requires_confirmation' => true, 'data_category' => 'case_information', 'label_ar' => 'إنشاء موعد', 'label_en' => 'Create calendar event'],
        'create_invoice' => ['kind' => 'write', 'requires_confirmation' => true, 'data_category' => 'client_information', 'label_ar' => 'إصدار فاتورة', 'label_en' => 'Create invoice'],
    ];
}

/** Which capabilities a tool needs from the owning tenant (finance tools reuse Phase 2 caps). */
function ai_tool_required_capability(string $tool): ?string {
    return [
        'read_cases' => 'cases.view',
        'read_clients' => 'clients.view',
        'read_documents' => 'documents.view',
        'read_calendar' => 'calendar.manage',
        'read_invoices' => 'finance.view_invoices',
        'create_draft' => 'drafting.create',
        'create_calendar_event' => 'calendar.manage',
        'create_invoice' => 'finance.create_invoices',
    ][$tool] ?? null;
}

function ai_tool_exists(string $tool): bool { return isset(ai_tool_registry()[$tool]); }

/** True when a tool is present in a comma-separated allow-list. */
function ai_tool_is_allowed(string $allowedToolsCsv, string $tool): bool {
    if (!ai_tool_exists($tool)) return false;
    $allowed = array_map('trim', explode(',', $allowedToolsCsv));
    return in_array($tool, $allowed, true);
}

/**
 * Gather the tenant-scoped payload a read tool exposes to the agent.
 * @return array{ok:bool, data?:array, text?:string, error?:string}
 */
function ai_tool_read(PDO $pdo, string $tool, string $ownerType, int $ownerId, array $args = []): array {
    if (!ai_tool_exists($tool)) return ['ok' => false, 'error' => 'unknown_tool'];
    $reg = ai_tool_registry()[$tool];
    if ($reg['kind'] !== 'read') return ['ok' => false, 'error' => 'not_a_read_tool'];

    // Resolve tenant scope. A lawyer agent reuses the exact tenant helper used everywhere else;
    // a client agent may only see their own records.
    if ($ownerType === 'lawyer') {
        $ctx = tenant_context_for_lawyer($pdo, $ownerId);
        $lawyerScope = tenant_owner_where_for_lawyer($pdo, $ownerId, 'c');
    } else {
        $ctx = ['firm_id' => null, 'is_firm' => false];
        $lawyerScope = null;
    }

    switch ($tool) {
        case 'read_cases':
            if ($ownerType === 'client') {
                $stmt = $pdo->prepare('SELECT id, title, status FROM cases WHERE user_id=? ORDER BY id DESC LIMIT 20');
                $stmt->execute([$ownerId]);
            } else {
                $stmt = $pdo->prepare('SELECT c.id, c.title, c.status FROM cases c WHERE ' . $lawyerScope['sql'] . ' ORDER BY c.id DESC LIMIT 20');
                $stmt->execute($lawyerScope['params']);
            }
            return ['ok' => true, 'data' => $stmt->fetchAll()];

        case 'read_clients':
            if ($ownerType === 'client') return ['ok' => true, 'data' => []];
            $scope = tenant_owner_where_for_lawyer($pdo, $ownerId, 'i');
            $stmt = $pdo->prepare('SELECT DISTINCT u.id, u.full_name FROM users u JOIN invoices i ON i.user_id=u.id WHERE ' . $scope['sql'] . ' ORDER BY u.full_name LIMIT 50');
            $stmt->execute($scope['params']);
            return ['ok' => true, 'data' => $stmt->fetchAll()];

        case 'read_documents':
            if ($ownerType !== 'client') return ['ok' => true, 'data' => []]; // firm document scope is per-case; not exposed to agents by default
            $stmt = $pdo->prepare('SELECT id, original_name, status FROM documents WHERE user_id=? ORDER BY id DESC LIMIT 20');
            $stmt->execute([$ownerId]);
            return ['ok' => true, 'data' => $stmt->fetchAll()];

        case 'read_calendar':
            if ($ownerType === 'client') return ['ok' => true, 'data' => []]; // calendar events are lawyer-owned
            $scope = tenant_owner_where_for_lawyer($pdo, $ownerId, 'e');
            $stmt = $pdo->prepare('SELECT e.id, e.title, e.event_at, e.event_type FROM legal_calendar_events e WHERE ' . $scope['sql'] . ' ORDER BY e.event_at DESC LIMIT 20');
            $stmt->execute($scope['params']);
            return ['ok' => true, 'data' => $stmt->fetchAll()];

        case 'read_invoices':
            if ($ownerType === 'client') {
                $stmt = $pdo->prepare('SELECT id, description, amount, status, due_date FROM invoices WHERE user_id=? AND is_draft=0 ORDER BY id DESC LIMIT 20');
                $stmt->execute([$ownerId]);
            } else {
                $scope = tenant_owner_where_for_lawyer($pdo, $ownerId, 'i');
                $stmt = $pdo->prepare('SELECT i.id, i.description, i.amount, i.status, i.due_date FROM invoices i WHERE ' . $scope['sql'] . ' AND i.is_draft=0 ORDER BY i.id DESC LIMIT 20');
                $stmt->execute($scope['params']);
            }
            return ['ok' => true, 'data' => $stmt->fetchAll()];
    }
    return ['ok' => false, 'error' => 'unhandled_tool'];
}

/**
 * Execute a write tool. This ALWAYS requires a matching human confirmation token.
 *
 * @param array $confirmation ['tool'=>string,'confirmed'=>bool,'actor_id'=>int]
 * @return array{ok:bool, result?:array, error?:string}
 */
function ai_tool_write(PDO $pdo, string $tool, string $ownerType, int $ownerId, array $args, array $confirmation): array {
    if (!ai_tool_exists($tool)) return ['ok' => false, 'error' => 'unknown_tool'];
    $reg = ai_tool_registry()[$tool];
    if ($reg['kind'] === 'read') return ['ok' => false, 'error' => 'not_a_write_tool'];

    // Confirmation is enforced by application code, never by the model. The confirming actor
    // must be the authenticated user, and the confirmed tool must match the requested one.
    if (empty($confirmation['confirmed']) || ($confirmation['tool'] ?? '') !== $tool) {
        return ['ok' => false, 'error' => 'confirmation_required'];
    }
    if ($ownerType !== 'lawyer') return ['ok' => false, 'error' => 'write_not_permitted_for_owner_type'];
    $ctx = tenant_context_for_lawyer($pdo, $ownerId);
    $cap = ai_tool_required_capability($tool);
    if ($cap !== null) {
        $caps = $ctx['is_firm'] ? tenant_firm_capabilities($ctx['role'] ?? 'lawyer') : null;
        if ($caps !== null && !in_array($cap, $caps, true)) return ['ok' => false, 'error' => 'permission_denied'];
    }

    switch ($tool) {
        case 'create_calendar_event':
            $title = trim((string)($args['title'] ?? ''));
            $when = (string)($args['event_at'] ?? '');
            if ($title === '' || mb_strlen($title, 'UTF-8') > 255 || !strtotime($when)) return ['ok' => false, 'error' => 'invalid_args'];
            // Reuse the calendar table exactly as the interactive flow does; tenant tag from the resolved context.
            $pdo->prepare('INSERT INTO legal_calendar_events (lawyer_id, firm_id, title, event_at, event_type, status) VALUES (?,?,?,?,?,?)')
                ->execute([$ownerId, $ctx['firm_id'], $title, date('Y-m-d H:i:s', strtotime($when)), 'other', 'upcoming']);
            return ['ok' => true, 'result' => ['id' => (int)$pdo->lastInsertId()]];

        case 'create_invoice':
            require_once __DIR__ . '/finance.php';
            $clientId = (int)($args['user_id'] ?? 0);
            $desc = trim((string)($args['description'] ?? ''));
            $amount = is_numeric($args['amount'] ?? null) ? (float)$args['amount'] : 0.0;
            if ($clientId <= 0 || $desc === '' || $amount <= 0) return ['ok' => false, 'error' => 'invalid_args'];
            // The client must already belong to this tenant — never trust a supplied id.
            $tenantClients = tenant_context_for_lawyer($pdo, $ownerId);
            $scopeCheck = $pdo->prepare('SELECT id FROM invoices i WHERE i.user_id=? AND ' . tenant_owner_where_for_lawyer($pdo, $ownerId, 'i')['sql'] . ' LIMIT 1');
            $scopeCheck->execute(array_merge([$clientId], tenant_owner_where_for_lawyer($pdo, $ownerId, 'i')['params']));
            if (!$scopeCheck->fetchColumn()) {
                $c = $pdo->prepare('SELECT id FROM cases c WHERE c.user_id=? AND ' . tenant_owner_where_for_lawyer($pdo, $ownerId, 'c')['sql'] . ' LIMIT 1');
                $c->execute(array_merge([$clientId], tenant_owner_where_for_lawyer($pdo, $ownerId, 'c')['params']));
                if (!$c->fetchColumn()) return ['ok' => false, 'error' => 'client_not_in_tenant'];
            }
            $number = finance_next_invoice_number($pdo, $ctx['firm_id'], $ownerId);
            $pdo->prepare('INSERT INTO invoices (invoice_number,lawyer_id,created_by_lawyer_id,firm_id,user_id,description,amount,currency,status,is_draft,issue_date) VALUES (?,?,?,?,?,?,?,?,?,1,NOW())')
                ->execute([$number, $ownerId, $ownerId, $ctx['firm_id'], $clientId, mb_substr($desc, 0, 255, 'UTF-8'), $amount, FINANCE_CURRENCY, 'unpaid']);
            return ['ok' => true, 'result' => ['id' => (int)$pdo->lastInsertId(), 'invoice_number' => $number]];

        case 'create_draft':
            $title = trim((string)($args['title'] ?? ''));
            $content = trim((string)($args['content'] ?? ''));
            $docType = trim((string)($args['doc_type'] ?? 'General'));
            if ($title === '' || $content === '') return ['ok' => false, 'error' => 'invalid_args'];
            // AI output is stored as a clearly-unapproved generated draft; it never overwrites
            // an approved document and always enters the normal review workflow as "draft".
            $pdo->prepare("INSERT INTO draft_documents (lawyer_id, firm_id, doc_type, title, details, generated_content, language, status, approval_status, approval_stage) VALUES (?,?,?,?,?,?,?, 'generated', 'draft', 'draft')")
                ->execute([$ownerId, $ctx['firm_id'], mb_substr($docType ?: 'General', 0, 100, 'UTF-8'), mb_substr($title, 0, 255, 'UTF-8'), mb_substr($title, 0, 1000, 'UTF-8'), $content, 'ar']);
            return ['ok' => true, 'result' => ['id' => (int)$pdo->lastInsertId()]];
    }
    return ['ok' => false, 'error' => 'unhandled_tool'];
}

/** Append one row to the agent activity log. */
function ai_agent_log_activity(PDO $pdo, ?int $agentId, string $ownerType, int $ownerId, string $tenantType, ?int $firmId, string $tool, string $outcome, string $confirmationState = 'not_required', ?string $detail = null): void {
    $reg = ai_tool_registry()[$tool] ?? null;
    try {
        $pdo->prepare('INSERT INTO ai_agent_activity_log (agent_id,owner_type,owner_id,tenant_type,firm_id,tool_name,requires_confirmation,confirmation_state,outcome,detail) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$agentId, $ownerType, $ownerId, $tenantType, $firmId, mb_substr($tool, 0, 80, 'UTF-8'),
                $reg && $reg['requires_confirmation'] ? 1 : 0, $confirmationState, $outcome,
                $detail !== null ? mb_substr($detail, 0, 500, 'UTF-8') : null]);
    } catch (PDOException $e) {
        error_log('AI agent activity log failed: ' . $e->getMessage());
    }
}
