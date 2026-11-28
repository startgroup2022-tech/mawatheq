<?php
/**
 * كتالوج مزايا الباقات وحدودها — المصدر الواحد لكل ما تعد به باقة.
 *
 * Before this file the platform had two different ideas of what a plan promises:
 * `plans` carried `max_staff`, `storage_mb` and six `feature_*` flags, while the
 * marketing copy lived as free text in `plan_features`. Nothing read the columns,
 * so an operator could set "5 lawyers, 25 GB, chatbot on" in the database and every
 * surface would still describe the package by its two AI counters alone — and the
 * server never enforced any of it.
 *
 * The rule this file exists to hold: **a limit or feature name appears here exactly
 * once.** The public price table, the client's usage page, the admin form and the
 * server-side gate all read from this catalogue, so the site cannot advertise a
 * benefit the code does not apply, and cannot enforce one the customer was never
 * shown.
 *
 * Two conventions that match the rest of the platform:
 *
 *  - **NULL means unlimited**, in the column and here. `plan_limit_value()` returns
 *    null for "no limit" and the gates treat null as "allow".
 *  - **No plan at all means no limit.** Legacy accounts predate the plan tables;
 *    a NULL is not a refusal. `plan_usage()` therefore reports `enforced => false`,
 *    never `remaining => 0`.
 */

/** Every numeric limit a plan can carry, with how it is measured. */
function plan_limit_catalog(): array {
    return [
        'max_staff' => [
            'label_ar' => 'عدد أعضاء الفريق',
            'label_en' => 'Team members',
            'unit_ar' => 'عضو',
            'unit_en' => 'members',
            'enforced' => true,
            'count' => 'staff',
        ],
        'max_clients' => [
            'label_ar' => 'عدد العملاء',
            'label_en' => 'Clients',
            'unit_ar' => 'عميل',
            'unit_en' => 'clients',
            'enforced' => true,
            'count' => 'clients',
        ],
        'max_active_cases' => [
            'label_ar' => 'القضايا النشطة',
            'label_en' => 'Active cases',
            'unit_ar' => 'قضية',
            'unit_en' => 'cases',
            'enforced' => true,
            'count' => 'active_cases',
        ],
        'storage_mb' => [
            'label_ar' => 'مساحة التخزين',
            'label_en' => 'Storage',
            'unit_ar' => 'ميجابايت',
            'unit_en' => 'MB',
            'enforced' => true,
            'count' => 'storage_mb',
            // Shown in GB above ~1 GB; the stored unit stays MB so the column keeps its precision.
            'display_divisor' => 1024,
            'display_unit_ar' => 'جيجابايت',
            'display_unit_en' => 'GB',
        ],
        'max_templates' => [
            'label_ar' => 'عدد القوالب',
            'label_en' => 'Templates',
            'unit_ar' => 'قالب',
            'unit_en' => 'templates',
            'enforced' => true,
            'count' => 'templates',
        ],
        'ai_queries_limit' => [
            'label_ar' => 'استفسارات المساعد الذكي شهرياً',
            'label_en' => 'AI assistant queries per month',
            'unit_ar' => 'استفسار/شهر',
            'unit_en' => 'queries/month',
            'enforced' => true,
            'count' => 'ai_events',
        ],
        'document_analysis_limit' => [
            'label_ar' => 'تحليل المستندات شهرياً',
            'label_en' => 'Document analyses per month',
            'unit_ar' => 'تحليل/شهر',
            'unit_en' => 'analyses/month',
            'enforced' => true,
            'count' => 'documents',
        ],
        'consultations_included' => [
            'label_ar' => 'الاستشارات المضمّنة',
            'label_en' => 'Consultations included',
            'unit_ar' => 'استشارة/شهر',
            'unit_en' => 'consultations/month',
            'enforced' => false, // informational: consultations are booked, not consumed from a pool
            'count' => null,
        ],
    ];
}

/** Every boolean capability a plan can switch on. */
function plan_feature_catalog(): array {
    return [
        'feature_calendar' => ['label_ar' => 'التقويم والجلسات', 'label_en' => 'Calendar & hearings'],
        'feature_finance' => ['label_ar' => 'المالية والفواتير', 'label_en' => 'Finance & invoices'],
        'feature_reports' => ['label_ar' => 'التقارير والتحليلات', 'label_en' => 'Reports & analytics'],
        'feature_chatbot' => ['label_ar' => 'روبوت المحادثة للعملاء', 'label_en' => 'Client chatbot'],
        'feature_branding' => ['label_ar' => 'الهوية البصرية المخصّصة', 'label_en' => 'Custom branding'],
        'feature_whatsapp' => ['label_ar' => 'تكامل واتساب', 'label_en' => 'WhatsApp integration'],
    ];
}

/**
 * Read one plan's row, cached per request.
 * Returns null when the id does not resolve — callers must treat that as "no plan".
 */
function plan_by_id(PDO $pdo, int $planId): ?array {
    static $cache = [];
    if ($planId <= 0) return null;
    if (array_key_exists($planId, $cache)) return $cache[$planId];
    try {
        $stmt = $pdo->prepare('SELECT * FROM plans WHERE id=? LIMIT 1');
        $stmt->execute([$planId]);
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        error_log('plan_by_id failed: ' . $e->getMessage());
        $row = false;
    }
    return $cache[$planId] = ($row ?: null);
}

/**
 * The plan that governs a client account. Null means "no plan" = no limits.
 *
 * A subscription row overrides `users.plan_id` while it is active and unexpired, so an
 * upgrade takes effect on the subscription's terms rather than needing the user row
 * rewritten. When the subscription lapses the account falls back to `users.plan_id`
 * instead of silently keeping the higher tier's limits.
 */
function plan_for_client(PDO $pdo, int $userId): ?array {
    static $cache = [];
    if ($userId <= 0) return null;
    if (array_key_exists($userId, $cache)) return $cache[$userId];

    $planId = 0;
    try {
        $stmt = $pdo->prepare(
            "SELECT plan_id FROM subscriptions
             WHERE user_id=? AND status='active' AND (ends_at IS NULL OR ends_at >= CURDATE())
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$userId]);
        $planId = (int)$stmt->fetchColumn();

        if ($planId <= 0) {
            $stmt = $pdo->prepare('SELECT plan_id FROM users WHERE id=?');
            $stmt->execute([$userId]);
            $planId = (int)$stmt->fetchColumn();
        }
    } catch (PDOException $e) {
        error_log('plan_for_client failed: ' . $e->getMessage());
    }
    return $cache[$userId] = plan_by_id($pdo, $planId);
}

/**
 * The plan that governs a lawyer's firm. A firm owner's plan is the firm's plan; a
 * solo lawyer falls back to their own `lawyers.plan_id` if the column exists.
 */
function plan_for_firm(PDO $pdo, int $firmId, int $lawyerId = 0): ?array {
    if ($firmId > 0) {
        try {
            $stmt = $pdo->prepare('SELECT plan_id FROM law_firms WHERE id=? LIMIT 1');
            $stmt->execute([$firmId]);
            $planId = (int)$stmt->fetchColumn();
            if ($planId > 0) return plan_by_id($pdo, $planId);
        } catch (PDOException $e) {
            error_log('plan_for_firm failed: ' . $e->getMessage());
        }
    }
    if ($lawyerId > 0) {
        try {
            $stmt = $pdo->prepare('SELECT plan_id FROM lawyers WHERE id=? LIMIT 1');
            $stmt->execute([$lawyerId]);
            return plan_by_id($pdo, (int)$stmt->fetchColumn());
        } catch (PDOException $e) {
            error_log('plan_for_firm (lawyer) failed: ' . $e->getMessage());
        }
    }
    return null;
}

/** A limit value, normalised: null = unlimited, otherwise a non-negative int. */
function plan_limit_value(?array $plan, string $key): ?int {
    if ($plan === null || !array_key_exists($key, $plan)) return null;
    if ($plan[$key] === null || $plan[$key] === '') return null;
    return max(0, (int)$plan[$key]);
}

/** Whether a boolean capability is switched on. No plan = feature unavailable, not unlimited. */
function plan_feature_enabled(?array $plan, string $key): bool {
    if ($plan === null || !array_key_exists($key, $plan)) return false;
    return (int)$plan[$key] === 1;
}

/**
 * Measure current usage for a limit key.
 *
 * Every count is scoped by the caller's tenant: `$scope` carries firm_id/user_id, and
 * a query that cannot be scoped returns null so the gate fails open (a counting bug
 * must not lock a paying customer out of their own workspace).
 *
 * @param array{firm_id?:?int, user_id?:?int, lawyer_id?:?int} $scope
 */
function plan_count_usage(PDO $pdo, string $countKey, array $scope): ?int {
    $firmId = isset($scope['firm_id']) ? (int)$scope['firm_id'] : 0;
    $userId = isset($scope['user_id']) ? (int)$scope['user_id'] : 0;
    $lawyerId = isset($scope['lawyer_id']) ? (int)$scope['lawyer_id'] : 0;

    try {
        switch ($countKey) {
            case 'staff': {
                if ($firmId <= 0) return null;
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM law_firm_members WHERE firm_id=? AND status='active'");
                $stmt->execute([$firmId]);
                return (int)$stmt->fetchColumn();
            }
            case 'clients': {
                if ($firmId <= 0 && $lawyerId <= 0) return null;
                // The `clients` register is the source of truth. Its count is authoritative
                // even when it is zero: a firm that has registered no clients yet is at 0,
                // not "unknown", and must be allowed its first one. Falling through to the
                // case-derived count on a zero reading made the ceiling look already
                // reached, which refused a firm its first client.
                $stmt = $firmId > 0
                    ? $pdo->prepare("SELECT COUNT(*) FROM clients WHERE firm_id=? AND status='active'")
                    : $pdo->prepare('SELECT COUNT(DISTINCT user_id) FROM cases WHERE lawyer_id=? AND user_id IS NOT NULL');
                $stmt->execute([$firmId > 0 ? $firmId : $lawyerId]);
                return (int)$stmt->fetchColumn();
            }
            case 'active_cases': {
                if ($firmId <= 0 && $lawyerId <= 0) return null;
                $stmt = $firmId > 0
                    ? $pdo->prepare("SELECT COUNT(*) FROM cases WHERE firm_id=? AND status NOT IN ('closed','archived','منتهية','مغلقة')")
                    : $pdo->prepare("SELECT COUNT(*) FROM cases WHERE lawyer_id=? AND status NOT IN ('closed','archived','منتهية','مغلقة')");
                $stmt->execute([$firmId > 0 ? $firmId : $lawyerId]);
                return (int)$stmt->fetchColumn();
            }
            case 'storage_mb': {
                if ($firmId <= 0 && $userId <= 0) return null;
                $stmt = $firmId > 0
                    ? $pdo->prepare('SELECT COALESCE(SUM(file_size),0) FROM documents d LEFT JOIN cases c ON c.id=d.case_id WHERE c.firm_id=? OR d.user_id IN (SELECT user_id FROM cases WHERE firm_id=?)')
                    : $pdo->prepare('SELECT COALESCE(SUM(file_size),0) FROM documents WHERE user_id=?');
                $stmt->execute($firmId > 0 ? [$firmId, $firmId] : [$userId]);
                return (int)ceil(((int)$stmt->fetchColumn()) / 1048576);
            }
            case 'templates': {
                if ($firmId <= 0 && $lawyerId <= 0) return null;
                // Firm-owned templates plus the platform's shared ones count as one library
                // for display, but only the firm's own rows consume the quota.
                $stmt = $firmId > 0
                    ? $pdo->prepare('SELECT COUNT(*) FROM document_templates WHERE firm_id=? AND is_active=1')
                    : $pdo->prepare('SELECT COUNT(*) FROM document_templates WHERE created_by_lawyer_id=? AND firm_id IS NULL AND is_active=1');
                $stmt->execute([$firmId > 0 ? $firmId : $lawyerId]);
                return (int)$stmt->fetchColumn();
            }
            case 'documents': {
                if ($userId <= 0 && $firmId <= 0) return null;
                $stmt = $userId > 0
                    ? $pdo->prepare("SELECT COUNT(*) FROM documents WHERE user_id=? AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')")
                    : $pdo->prepare("SELECT COUNT(*) FROM documents d JOIN cases c ON c.id=d.case_id WHERE c.firm_id=? AND d.created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')");
                $stmt->execute([$userId > 0 ? $userId : $firmId]);
                return (int)$stmt->fetchColumn();
            }
            case 'ai_events': {
                if ($userId <= 0) return null;
                $stmt = $pdo->prepare(
                    "SELECT COUNT(*) FROM ai_messages m JOIN ai_conversations c ON c.id=m.conversation_id
                     WHERE c.user_id=? AND m.sender='user' AND m.created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
                );
                $stmt->execute([$userId]);
                return (int)$stmt->fetchColumn();
            }
        }
    } catch (PDOException $e) {
        error_log('plan_count_usage(' . $countKey . ') failed: ' . $e->getMessage());
    }
    return null;
}

/**
 * Full usage report for a scope, keyed by limit column.
 *
 * @return array<string, array{limit:?int, used:?int, remaining:?int, enforced:bool, exhausted:bool}>
 */
function plan_usage(PDO $pdo, ?array $plan, array $scope): array {
    $out = [];
    foreach (plan_limit_catalog() as $key => $meta) {
        $limit = plan_limit_value($plan, $key);
        // No plan, or an unlimited limit: nothing is measured and nothing is refused.
        $enforced = $plan !== null && $limit !== null && $meta['enforced'] && $meta['count'] !== null;
        $used = $enforced ? plan_count_usage($pdo, $meta['count'], $scope) : null;
        $out[$key] = [
            'limit' => $limit,
            'used' => $used,
            'remaining' => ($enforced && $used !== null) ? max(0, $limit - $used) : null,
            'enforced' => $enforced,
            'exhausted' => $enforced && $used !== null && $used >= $limit,
        ];
    }
    return $out;
}

/**
 * Decide one action against the plan.
 *
 * @param int $delta how many rows the action would add (1 for a normal create)
 * @return array{allowed:bool, limit:?int, used:?int, remaining:?int, reason:?string, message:?string}
 */
function plan_check(PDO $pdo, ?array $plan, array $scope, string $key, int $delta = 1): array {
    $catalog = plan_limit_catalog();
    $limit = plan_limit_value($plan, $key);
    $result = ['allowed' => true, 'limit' => $limit, 'used' => null, 'remaining' => null, 'reason' => null, 'message' => null];

    if (!isset($catalog[$key])) {
        $result['reason'] = 'unknown_limit';
        return $result;
    }
    // No plan, no limit, or a limit the schema marks informational: allow.
    if ($plan === null || $limit === null || !$catalog[$key]['enforced'] || $catalog[$key]['count'] === null) {
        return $result;
    }

    $used = plan_count_usage($pdo, $catalog[$key]['count'], $scope);
    if ($used === null) {
        // Unmeasurable: fail open and log. A counting bug must not block a paying customer.
        $result['reason'] = 'unmeasurable';
        return $result;
    }

    $result['used'] = $used;
    $result['remaining'] = max(0, $limit - $used);
    if ($used + max(1, $delta) > $limit) {
        $result['allowed'] = false;
        $result['reason'] = 'plan_limit_reached';
        $planName = is_rtl() ? (string)($plan['name_ar'] ?? '') : (string)($plan['name_en'] ?? '');
        $label = is_rtl() ? $catalog[$key]['label_ar'] : $catalog[$key]['label_en'];
        $result['message'] = is_rtl()
            ? "وصلت للحد الأقصى ({$limit}) في «{$label}» ضمن باقة {$planName}. يمكنك الترقية لباقة أعلى أو التواصل معنا."
            : "You've reached your plan ({$planName}) limit of {$limit} for \"{$label}\". Upgrade your plan or contact us.";
    }
    return $result;
}

/** Convenience: a capability gate for pages that must be closed by plan. */
function plan_feature_allows(?array $plan, string $key, int $userId = 0): bool {
    // An explicit opt-in elsewhere (an admin override) is out of scope here; a plan with
    // no row means the feature is simply not part of what this account bought.
    return plan_feature_enabled($plan, $key);
}

/**
 * Human-readable value for a limit: "غير محدود" for null, otherwise "N unit",
 * collapsing storage to GB. Used by every surface so they cannot disagree.
 */
function plan_limit_label(?int $value, string $key, bool $rtl): string {
    $catalog = plan_limit_catalog();
    $meta = $catalog[$key] ?? ['label_ar' => $key, 'label_en' => $key, 'unit_ar' => '', 'unit_en' => ''];
    if ($value === null) {
        return $rtl ? 'غير محدود' : 'Unlimited';
    }
    if (!empty($meta['display_divisor']) && $value >= $meta['display_divisor']) {
        $div = (int)$meta['display_divisor'];
        $n = rtrim(rtrim(number_format($value / $div, 1, '.', ''), '0'), '.');
        $unit = $rtl ? ($meta['display_unit_ar'] ?? '') : ($meta['display_unit_en'] ?? '');
        return $n . ' ' . $unit;
    }
    $unit = $rtl ? ($meta['unit_ar'] ?? '') : ($meta['unit_en'] ?? '');
    return $value . ($unit !== '' ? ' ' . $unit : '');
}

/**
 * The complete marketing description of a plan: its typed limits and its capabilities.
 *
 * This is what the public price table lists instead of only the two AI counters, so a
 * package's real contents are visible exactly as the server enforces them.
 *
 * @return array<int, array{key:string, kind:string, label:string, value:string, enabled:bool}>
 */
function plan_benefit_lines(?array $plan, bool $rtl): array {
    $lines = [];
    if ($plan === null) return $lines;

    foreach (plan_limit_catalog() as $key => $meta) {
        $value = plan_limit_value($plan, $key);
        // Skip a limit the operator left unset for this plan rather than printing "unlimited"
        // for something that was never offered.
        if ($value === null && $key !== 'ai_queries_limit' && $key !== 'document_analysis_limit') {
            if (!array_key_exists($key, $plan) || $plan[$key] === null) continue;
        }
        $lines[] = [
            'key' => $key,
            'kind' => 'limit',
            'label' => $rtl ? $meta['label_ar'] : $meta['label_en'],
            'value' => plan_limit_label($value, $key, $rtl),
            'enabled' => $value !== null,
        ];
    }
    foreach (plan_feature_catalog() as $key => $meta) {
        if (!plan_feature_enabled($plan, $key)) continue;
        $lines[] = [
            'key' => $key,
            'kind' => 'feature',
            'label' => $rtl ? $meta['label_ar'] : $meta['label_en'],
            'value' => '',
            'enabled' => true,
        ];
    }
    return $lines;
}

/**
 * Normalise the plan columns this catalogue owns out of a posted form.
 * Kept beside the catalogue so a new limit cannot be added without a matching
 * parser — the admin form and the validator read the same list.
 *
 * @return array<string, mixed>
 */
function plan_normalize_catalog_input(array $post): array {
    $out = [];
    foreach (plan_limit_catalog() as $key => $meta) {
        if (!array_key_exists($key, $post)) continue;
        $raw = trim((string)$post[$key]);
        $out[$key] = $raw === '' ? null : max(0, (int)$raw);
    }
    foreach (plan_feature_catalog() as $key => $meta) {
        // A checkbox that is absent means "off", so presence is the signal.
        $out[$key] = isset($post[$key]) ? 1 : 0;
    }
    return $out;
}
