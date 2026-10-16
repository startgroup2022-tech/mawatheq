<?php
/**
 * AI service catalog and plan gating (single source of truth).
 *
 * The plan limits that actually exist in the database are exactly two per client
 * account: `plans.ai_queries_limit` (assistant queries per month) and
 * `plans.document_analysis_limit` (document analyses per month). NULL means
 * unlimited. This file maps each real AI destination onto the gate that governs
 * it, so navigation, the dashboard, the subscription page and the endpoints all
 * agree on one answer.
 *
 * Enforcement is server-side: `ai_service_require()` is what a page or endpoint
 * calls before doing work. Hiding or greying a link is presentation only and is
 * never treated as the control.
 */

require_once __DIR__ . '/plan_limits.php';

if (!function_exists('ai_service_catalog')) {
    /**
     * Every AI destination that exists in the product, in display order.
     * `gate` names the plan limit that governs the service, or null when the
     * service consumes no metered AI quota.
     *
     * @return array<string,array{label_ar:string,label_en:string,href:string,icon:string,gate:?string,desc_ar:string,desc_en:string}>
     */
    function ai_service_catalog(): array {
        return [
            'ai-assistant' => [
                'label_ar' => 'المساعد القانوني', 'label_en' => 'Legal assistant',
                'href' => '/ai-assistant.php', 'icon' => 'spark', 'gate' => 'ai_queries',
                'desc_ar' => 'اطرح أسئلة قانونية واحصل على إجابات مسنودة بالأنظمة والمواد.',
                'desc_en' => 'Ask legal questions and get answers grounded in statutes and articles.',
            ],
            'document-analysis' => [
                'label_ar' => 'تحليل المستندات', 'label_en' => 'Document analysis',
                'href' => '/documents.php', 'icon' => 'file', 'gate' => 'document_analysis',
                'desc_ar' => 'ارفع عقوداً ومستندات لاستخراج الملخصات والمخاطر والتوصيات.',
                'desc_en' => 'Upload contracts and documents to extract summaries, risks and recommendations.',
            ],
            'contract-comparison' => [
                'label_ar' => 'مقارنة العقود', 'label_en' => 'Contract comparison',
                'href' => '/contract-comparison.php', 'icon' => 'scale', 'gate' => null,
                'desc_ar' => 'قارن تحليل عقدين لاكتشاف الفروق والالتزامات ونقاط المراجعة.',
                'desc_en' => 'Compare two analyzed contracts to surface differences, obligations and review points.',
            ],
        ];
    }
}

if (!function_exists('ai_service_gate')) {
    /** The plan limit governing a service, or null when it is not metered. */
    function ai_service_gate(string $key): ?string {
        $catalog = ai_service_catalog();
        return $catalog[$key]['gate'] ?? null;
    }
}

if (!function_exists('ai_plan_usage')) {
    /**
     * The signed-in client's plan with live usage for both metered gates.
     * Cached per request so navigation and page bodies cost one query between them.
     *
     * @return array{plan:?array,has_plan:bool,ai_queries:array{document_analysis:array}
     */
    function ai_plan_usage(PDO $pdo, int $userId): array {
        static $cache = [];
        if (isset($cache[$userId])) return $cache[$userId];

        $plan = get_user_plan($pdo, $userId);

        $aiLimit = ($plan && $plan['ai_queries_limit'] !== null) ? (int)$plan['ai_queries_limit'] : null;
        $docLimit = ($plan && $plan['document_analysis_limit'] !== null) ? (int)$plan['document_analysis_limit'] : null;

        $aiUsed = $aiLimit === null ? 0 : count_ai_queries_this_month($pdo, $userId);
        $docUsed = $docLimit === null ? 0 : count_documents_this_month($pdo, $userId);

        return $cache[$userId] = [
            'plan' => $plan,
            'has_plan' => $plan !== null,
            'ai_queries' => [
                'used' => $aiUsed, 'limit' => $aiLimit,
                'remaining' => $aiLimit === null ? null : max(0, $aiLimit - $aiUsed),
            ],
            'document_analysis' => [
                'used' => $docUsed, 'limit' => $docLimit,
                'remaining' => $docLimit === null ? null : max(0, $docLimit - $docUsed),
            ],
        ];
    }
}

if (!function_exists('ai_service_allowed')) {
    /** Whether the plan currently permits a service (presentation + guard share this). */
    function ai_service_allowed(string $key, int $userId, ?PDO $pdo = null): bool {
        $gate = ai_service_gate($key);
        if ($gate === null) return true;
        if (!$pdo instanceof PDO) {
            // Nav rendering has no connection in scope; reuse the shared one.
            $pdo = $GLOBALS['pdo'] ?? null;
        }
        if (!$pdo instanceof PDO) return true;
        $usage = ai_plan_usage($pdo, $userId)[$gate];
        return $usage['limit'] === null || $usage['used'] < $usage['limit'];
    }
}

if (!function_exists('ai_service_require')) {
    /**
     * Server-side guard. Call this before performing metered AI work; it is the
     * control that actually enforces the plan, independent of what the UI showed.
     *
     * @return array{allowed:bool,used:int,limit:?int,message:?string,plan_name:?string}
     */
    function ai_service_require(PDO $pdo, string $key, int $userId): array {
        $gate = ai_service_gate($key);
        $plan = get_user_plan($pdo, $userId);
        $planName = $plan ? (is_rtl() ? $plan['name_ar'] : $plan['name_en']) : null;

        if ($gate === null) {
            return ['allowed' => true, 'used' => 0, 'limit' => null, 'message' => null, 'plan_name' => $planName];
        }

        $usage = ai_plan_usage($pdo, $userId)[$gate];
        $allowed = $usage['limit'] === null || $usage['used'] < $usage['limit'];

        $message = null;
        if (!$allowed) {
            $label = $gate === 'ai_queries'
                ? (is_rtl() ? 'استفسارات المساعد' : 'assistant queries')
                : (is_rtl() ? 'تحليلات المستندات' : 'document analyses');
            $message = is_rtl()
                ? "وصلت للحد الأقصى ({$usage['limit']}) لـ{$label} ضمن باقتك" . ($planName ? " ({$planName})" : '') . ' لهذا الشهر. يمكنك الترقية لباقة أعلى من صفحة الباقة والاستخدام.'
                : "You have reached this month's limit of {$usage['limit']} {$label}" . ($planName ? " on the {$planName} plan" : '') . '. You can upgrade from the Plan & usage page.';
        }

        return [
            'allowed' => $allowed, 'used' => $usage['used'], 'limit' => $usage['limit'],
            'message' => $message, 'plan_name' => $planName,
        ];
    }
}

if (!function_exists('ai_usage_percent')) {
    /** Used percentage for a meter, or null when the gate is unlimited. */
    function ai_usage_percent(array $gate): ?int {
        if ($gate['limit'] === null || (int)$gate['limit'] <= 0) return null;
        return (int)min(100, round($gate['used'] / max(1, (int)$gate['limit']) * 100));
    }
}
