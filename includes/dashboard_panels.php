<?php
/**
 * Shared dashboard panels (Phase 4).
 *
 * The lawyer and client home screens both need the same small read models —
 * "who are my clients", "what is due", "which documents are waiting". They live
 * here rather than inline in each page so the two dashboards cannot drift apart,
 * and so every panel is tenant-scoped and plan-gated in exactly one place.
 *
 * Every function returns plain arrays ready for markup. Nothing here writes, and
 * nothing here invents a value: if a count is zero the caller decides whether to
 * hide the panel, and each row carries the real destination for its own entity.
 */

if (!function_exists('dash_panel_lawyer_clients')) {
    /**
     * Clients the lawyer (or their firm) actually has a relationship with, most
     * valuable first. "Value" is the real outstanding balance from finance.php,
     * not a score we made up.
     *
     * @return array<int,array{id:int,name:string,cases:int,open_cases:int,outstanding:float}>
     */
    function dash_panel_lawyer_clients(PDO $pdo, int $limit = 5): array
    {
        $caseScope = tenant_owner_where($pdo, 'c');
        $sql = "SELECT u.id, u.full_name AS name,
                       COUNT(DISTINCT c.id) AS cases,
                       COUNT(DISTINCT CASE WHEN c.status IN ('open','in_review') THEN c.id END) AS open_cases
                  FROM cases c
                  JOIN users u ON u.id = c.user_id
                 WHERE {$caseScope['sql']}
                 GROUP BY u.id, u.full_name
                 ORDER BY open_cases DESC, cases DESC, u.full_name ASC
                 LIMIT " . max(1, min(50, $limit));
        $stmt = $pdo->prepare($sql);
        $stmt->execute($caseScope['params']);
        $rows = $stmt->fetchAll() ?: [];

        if (!$rows) {
            return [];
        }

        // finance_client_balances already computes receivables per client for this
        // tenant scope; reuse it rather than duplicating the balance maths.
        $invScope = tenant_owner_where($pdo, 'i');
        $outstanding = [];
        foreach (finance_client_balances($pdo, $invScope, 500) as $bal) {
            $outstanding[(int)$bal['id']] = (float)$bal['outstanding'];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'          => (int)$r['id'],
                'name'        => (string)$r['name'],
                'cases'       => (int)$r['cases'],
                'open_cases'  => (int)$r['open_cases'],
                'outstanding' => (float)($outstanding[(int)$r['id']] ?? 0),
            ];
        }

        // Highest balance first; clients with no balance keep their work ordering.
        usort($out, static fn(array $a, array $b): int => $b['outstanding'] <=> $a['outstanding']);

        return $out;
    }
}

if (!function_exists('dash_panel_lawyer_tasks')) {
    /**
     * Open tasks across the lawyer's cases. `case_tasks` has no owner column, so the
     * scope comes from the case it hangs off — the same rule the case list uses.
     *
     * @return array<int,array{id:int,title:string,case_id:int,case_title:string,due_date:?string,overdue:bool}>
     */
    function dash_panel_lawyer_tasks(PDO $pdo, int $limit = 6): array
    {
        $caseScope = tenant_owner_where($pdo, 'c');
        $sql = "SELECT t.id, t.title, t.due_date, c.id AS case_id, c.title AS case_title
                  FROM case_tasks t
                  JOIN cases c ON c.id = t.case_id
                 WHERE {$caseScope['sql']} AND t.status = 'pending'
                 ORDER BY (t.due_date IS NULL), t.due_date ASC, t.id DESC
                 LIMIT " . max(1, min(50, $limit));
        $stmt = $pdo->prepare($sql);
        $stmt->execute($caseScope['params']);

        $today = date('Y-m-d');
        $out = [];
        foreach ($stmt->fetchAll() ?: [] as $r) {
            $due = $r['due_date'] !== null ? (string)$r['due_date'] : null;
            $out[] = [
                'id'         => (int)$r['id'],
                'title'      => (string)$r['title'],
                'case_id'    => (int)$r['case_id'],
                'case_title' => (string)$r['case_title'],
                'due_date'   => $due,
                'overdue'    => $due !== null && $due < $today,
            ];
        }
        return $out;
    }
}

if (!function_exists('dash_panel_lawyer_documents')) {
    /**
     * Documents belonging to the lawyer's clients, newest first. The client set is
     * derived from the scoped cases. `documents.case_id` (upgrade_v47) is surfaced
     * only when the linked case belongs to the same client, so a document can never
     * appear filed under another tenant's matter.
     *
     * @return array<int,array{id:int,name:string,status:string,type:string,case_title:?string,created_at:string}>
     */
    function dash_panel_lawyer_documents(PDO $pdo, int $limit = 5): array
    {
        $caseScope = tenant_owner_where($pdo, 'c');
        $sql = "SELECT d.id, d.original_name, d.status, d.document_type, d.created_at,
                       CASE WHEN lc.user_id = d.user_id THEN lc.title END AS case_title
                  FROM documents d
                  LEFT JOIN cases lc ON lc.id = d.case_id
                 WHERE d.user_id IN (SELECT c.user_id FROM cases c WHERE {$caseScope['sql']})
                 ORDER BY d.created_at DESC, d.id DESC
                 LIMIT " . max(1, min(50, $limit));
        $stmt = $pdo->prepare($sql);
        $stmt->execute($caseScope['params']);

        $out = [];
        foreach ($stmt->fetchAll() ?: [] as $r) {
            $out[] = [
                'id'         => (int)$r['id'],
                'name'       => (string)$r['original_name'],
                'status'     => (string)($r['status'] ?? ''),
                'type'       => (string)($r['document_type'] ?? ''),
                'case_title' => $r['case_title'] !== null ? (string)$r['case_title'] : null,
                'created_at' => (string)$r['created_at'],
            ];
        }
        return $out;
    }
}

if (!function_exists('dash_panel_lawyer_documents_count')) {
    /** Total documents across the lawyer's clients, for the panel header. */
    function dash_panel_lawyer_documents_count(PDO $pdo): int
    {
        $caseScope = tenant_owner_where($pdo, 'c');
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM documents d
              WHERE d.user_id IN (SELECT c.user_id FROM cases c WHERE {$caseScope['sql']})"
        );
        $stmt->execute($caseScope['params']);
        return (int)$stmt->fetchColumn();
    }
}

if (!function_exists('dash_panel_lawyer_drafts')) {
    /**
     * The lawyer's recent drafts and their approval state, so the dashboard can
     * answer "what is waiting on a decision" without opening the drafting page.
     * Scoped with the same rule the drafting page uses (`tenant_owner_where('d')`),
     * so a firm member sees the firm's drafts and a solo lawyer only their own.
     *
     * @return array<int,array{id:int,title:string,case_title:?string,approval_status:string,approval_stage:string,client_visible:bool,created_at:string}>
     */
    function dash_panel_lawyer_drafts(PDO $pdo, int $limit = 5): array
    {
        $scope = tenant_owner_where($pdo, 'd');
        $sql = "SELECT d.id, d.title, d.approval_status, d.approval_stage, d.client_visible, d.created_at,
                       c.title AS case_title
                  FROM draft_documents d
                  LEFT JOIN cases c ON c.id = d.case_id
                 WHERE {$scope['sql']}
                 ORDER BY d.created_at DESC, d.id DESC
                 LIMIT " . max(1, min(50, $limit));
        $stmt = $pdo->prepare($sql);
        $stmt->execute($scope['params']);

        $out = [];
        foreach ($stmt->fetchAll() ?: [] as $r) {
            $out[] = [
                'id'              => (int)$r['id'],
                'title'           => (string)$r['title'],
                'case_title'      => $r['case_title'] !== null ? (string)$r['case_title'] : null,
                'approval_status' => (string)($r['approval_status'] ?? 'draft'),
                'approval_stage'  => (string)($r['approval_stage'] ?? 'draft'),
                'client_visible'  => (bool)$r['client_visible'],
                'created_at'      => (string)$r['created_at'],
            ];
        }
        return $out;
    }
}

if (!function_exists('dash_panel_lawyer_drafts_pending_count')) {
    /** Drafts awaiting an internal or client decision, for the dashboard header. */
    function dash_panel_lawyer_drafts_pending_count(PDO $pdo): int
    {
        $scope = tenant_owner_where($pdo, 'd');
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM draft_documents d
              WHERE {$scope['sql']}
                AND (d.approval_status IN ('submitted','changes_requested')
                     OR (d.client_visible = 1 AND d.approval_status = 'approved'))"
        );
        $stmt->execute($scope['params']);
        return (int)$stmt->fetchColumn();
    }
}

if (!function_exists('dash_panel_draft_state')) {
    /**
     * Map a draft's approval_status to a severity + bilingual label, shared by the
     * lawyer panel and any other surface that lists drafts.
     *
     * @return array{sev:string,ar:string,en:string}
     */
    function dash_panel_draft_state(string $status): array
    {
        return match ($status) {
            'approved'          => ['sev' => 'success', 'ar' => 'معتمدة',           'en' => 'Approved'],
            'submitted'         => ['sev' => 'warning', 'ar' => 'بانتظار المراجعة', 'en' => 'Awaiting review'],
            'changes_requested' => ['sev' => 'danger',  'ar' => 'تعديلات مطلوبة',   'en' => 'Changes requested'],
            default             => ['sev' => 'info',    'ar' => 'مسودة',            'en' => 'Draft'],
        };
    }
}

if (!function_exists('dash_panel_client_lawyers')) {
    /**
     * The lawyers this client actually deals with, taken from their own cases and
     * consultations. Contact details are shown only when the lawyer published them.
     *
     * @return array<int,array{id:int,name:string,specialization:string,photo:?string,active_cases:int,upcoming:int}>
     */
    function dash_panel_client_lawyers(PDO $pdo, int $userId, int $limit = 4): array
    {
        $sql = "SELECT l.id, l.full_name AS name, l.specialization, l.photo_path,
                       (SELECT COUNT(*) FROM cases c2
                         WHERE c2.lawyer_id = l.id AND c2.user_id = ? AND c2.status IN ('open','in_review')) AS active_cases,
                       (SELECT COUNT(*) FROM consultations co2
                         WHERE co2.lawyer_id = l.id AND co2.user_id = ?
                           AND co2.status IN ('pending','confirmed') AND co2.scheduled_at >= NOW()) AS upcoming
                  FROM lawyers l
                 WHERE l.id IN (
                       SELECT c.lawyer_id FROM cases c WHERE c.user_id = ?
                       UNION
                       SELECT co.lawyer_id FROM consultations co WHERE co.user_id = ?
                 )
                 ORDER BY active_cases DESC, upcoming DESC, l.full_name ASC
                 LIMIT " . max(1, min(50, $limit));
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId, $userId, $userId, $userId]);

        $out = [];
        foreach ($stmt->fetchAll() ?: [] as $r) {
            $out[] = [
                'id'             => (int)$r['id'],
                'name'           => (string)$r['name'],
                'specialization' => (string)($r['specialization'] ?? ''),
                'photo'          => !empty($r['photo_path']) ? (string)$r['photo_path'] : null,
                'active_cases'   => (int)$r['active_cases'],
                'upcoming'       => (int)$r['upcoming'],
            ];
        }
        return $out;
    }
}

if (!function_exists('dash_panel_client_documents')) {
    /**
     * The client's own documents, newest first, with the analysis state and the
     * optional case link (`documents.case_id`, upgrade_v47) so the panel can show
     * what is still processing and which matter a document is filed under.
     *
     * @return array<int,array{id:int,name:string,status:string,type:string,case_id:?int,case_title:?string,has_analysis:bool,created_at:string}>
     */
    function dash_panel_client_documents(PDO $pdo, int $userId, int $limit = 5): array
    {
        $sql = "SELECT d.id, d.original_name, d.status, d.document_type, d.contract_analysis_json,
                       d.created_at, d.case_id, c.title AS case_title
                  FROM documents d
                  LEFT JOIN cases c ON c.id = d.case_id AND c.user_id = d.user_id
                 WHERE d.user_id = ?
                 ORDER BY d.created_at DESC, d.id DESC
                 LIMIT " . max(1, min(50, $limit));
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);

        $out = [];
        foreach ($stmt->fetchAll() ?: [] as $r) {
            $out[] = [
                'id'           => (int)$r['id'],
                'name'         => (string)$r['original_name'],
                'status'       => (string)($r['status'] ?? ''),
                'type'         => (string)($r['document_type'] ?? ''),
                'case_id'      => $r['case_id'] !== null ? (int)$r['case_id'] : null,
                'case_title'   => $r['case_title'] !== null ? (string)$r['case_title'] : null,
                'has_analysis' => !empty($r['contract_analysis_json']),
                'created_at'   => (string)$r['created_at'],
            ];
        }
        return $out;
    }
}

if (!function_exists('dash_panel_client_alerts')) {
    /**
     * Recent notifications for the client — the same rows the bell shows, surfaced
     * on the page so follow-up does not depend on noticing the bell.
     *
     * @return array<int,array{id:int,title:string,message:?string,link:?string,is_read:bool,created_at:string}>
     */
    function dash_panel_client_alerts(PDO $pdo, int $userId, int $limit = 5): array
    {
        $sql = "SELECT id, title, message, link, is_read, created_at
                  FROM notifications
                 WHERE recipient_type = 'client' AND recipient_id = ?
                 ORDER BY created_at DESC, id DESC
                 LIMIT " . max(1, min(50, $limit));
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);

        $out = [];
        foreach ($stmt->fetchAll() ?: [] as $r) {
            $out[] = [
                'id'         => (int)$r['id'],
                'title'      => (string)$r['title'],
                'message'    => $r['message'] !== null ? (string)$r['message'] : null,
                'link'       => !empty($r['link']) ? (string)$r['link'] : null,
                'is_read'    => (bool)$r['is_read'],
                'created_at' => (string)$r['created_at'],
            ];
        }
        return $out;
    }
}

if (!function_exists('dash_panel_client_unread_count')) {
    /** Unread client notifications, used to decide whether the panel is worth showing. */
    function dash_panel_client_unread_count(PDO $pdo, int $userId): int
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM notifications
              WHERE recipient_type = 'client' AND recipient_id = ? AND is_read = 0"
        );
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }
}

if (!function_exists('dash_panel_document_state')) {
    /**
     * Map a documents.status value to a severity + bilingual label. Shared so the
     * lawyer and client panels describe the same row the same way.
     *
     * @return array{sev:string,ar:string,en:string}
     */
    function dash_panel_document_state(string $status): array
    {
        return match ($status) {
            'completed'  => ['sev' => 'success', 'ar' => 'تم التحليل',    'en' => 'Analysed'],
            'processing' => ['sev' => 'warning', 'ar' => 'قيد المعالجة',  'en' => 'Processing'],
            'failed'     => ['sev' => 'danger',  'ar' => 'تعذّر التحليل', 'en' => 'Failed'],
            'pending'    => ['sev' => 'info',    'ar' => 'بانتظار التحليل', 'en' => 'Pending'],
            default      => ['sev' => 'info',    'ar' => 'مرفوع',         'en' => 'Uploaded'],
        };
    }
}
