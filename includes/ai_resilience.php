<?php
/**
 * AI provider resilience, rate limiting and usage metering (Executive 08).
 *
 * The existing gateway (includes/ai_gateway.php) already enforces the privacy contract:
 * which tenant may send which data category to which provider. This file adds the
 * *operational* concerns around it, without changing that contract:
 *
 *   * retry with backoff on transient provider failures (never on a policy denial),
 *   * a request timeout read from settings rather than hard-coded,
 *   * a per-tenant hourly rate limit counted from ai_usage_events,
 *   * provider health probes recorded in ai_provider_health,
 *   * a safe fallback: when the provider is unavailable, the caller can fall back to the
 *     local law-library answer, which stays on-host.
 *
 * Retry and fallback never bypass ai_gateway_call — every attempt still goes through the
 * privacy gate, so a retry cannot smuggle a previously-denied payload out.
 */

require_once __DIR__ . '/ai_gateway.php';
require_once __DIR__ . '/ai_provider.php';
require_once __DIR__ . '/legal_ai.php';

if (!function_exists('ai_settings_int')) {
    /** Read an integer knob from settings, clamped, with a default. */
    function ai_settings_int(array $settings, string $key, int $default, int $min, int $max): int {
        $v = $settings[$key] ?? null;
        if (!is_numeric($v)) return $default;
        return max($min, min($max, (int)$v));
    }
}

if (!function_exists('ai_max_retries')) {
    /** Extra attempts after the first (0 = no retry). Bounded to keep a request finite. */
    function ai_max_retries(array $settings): int {
        return ai_settings_int($settings, 'ai_max_retries', 2, 0, 4);
    }
}

if (!function_exists('ai_request_timeout')) {
    function ai_request_timeout(array $settings): int {
        return ai_settings_int($settings, 'ai_timeout_seconds', 25, 5, 120);
    }
}

if (!function_exists('ai_rate_limit_per_hour')) {
    function ai_rate_limit_per_hour(array $settings): int {
        return ai_settings_int($settings, 'ai_rate_limit_per_hour', 120, 1, 100000);
    }
}

if (!function_exists('ai_rate_limit_remaining')) {
    /**
     * Count this tenant's AI calls in the rolling hour and report the remainder.
     * A count failure returns a permissive-but-finite value rather than blocking AI — the
     * privacy gate, not this counter, is the security boundary.
     */
    function ai_rate_limit_remaining(PDO $pdo, array $tenant, array $settings): array {
        $limit = ai_rate_limit_per_hour($settings);
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM ai_usage_events
                                   WHERE tenant_type=? AND tenant_id=? AND created_at >= (NOW() - INTERVAL 1 HOUR)");
            $stmt->execute([$tenant['tenant_type'], (int)$tenant['tenant_id']]);
            $used = (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log('AI rate limit count failed: ' . $e->getMessage());
            return ['limit' => $limit, 'used' => 0, 'remaining' => $limit, 'exceeded' => false];
        }
        return ['limit' => $limit, 'used' => $used, 'remaining' => max(0, $limit - $used), 'exceeded' => $used >= $limit];
    }
}

if (!function_exists('ai_record_provider_health')) {
    function ai_record_provider_health(PDO $pdo, string $provider, ?string $model, ?string $endpoint, bool $ok, ?int $latencyMs, ?string $error, ?int $adminId = null): void {
        try {
            $pdo->prepare('INSERT INTO ai_provider_health (provider, model, endpoint, ok, latency_ms, error_message, checked_by_admin_id)
                           VALUES (?,?,?,?,?,?,?)')
                ->execute([$provider, $model, $endpoint, $ok ? 1 : 0, $latencyMs,
                    $error !== null ? mb_substr($error, 0, 500, 'UTF-8') : null, $adminId]);
        } catch (PDOException $e) {
            error_log('AI health record failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('ai_provider_health_probe')) {
    /**
     * Probe a provider with a tiny, non-confidential request and record the result.
     * The probe prompt contains no case data — it is a "ping", not a query.
     * @return array{ok:bool,latency_ms:int,error?:string}
     */
    function ai_provider_health_probe(PDO $pdo, string $provider, string $apiKey, string $model, string $endpoint = '', ?int $adminId = null): array {
        if (!ai_provider_chat_capable($provider)) {
            ai_record_provider_health($pdo, $provider, $model, $endpoint, false, null, 'provider_unsupported', $adminId);
            return ['ok' => false, 'latency_ms' => 0, 'error' => 'provider_unsupported'];
        }
        $settings = ['ai_provider' => $provider, 'ai_api_key' => $apiKey, 'ai_model' => $model, 'ai_endpoint' => $endpoint];
        $t0 = microtime(true);
        $res = ai_provider_chat($settings, 'You are a health check. Reply with the single word: OK', 'ping', 8, 0.0, 15);
        $ms = (int)round((microtime(true) - $t0) * 1000);
        $ok = !empty($res['ok']);
        ai_record_provider_health($pdo, $provider, $model, $endpoint, $ok, $ms, $ok ? null : ($res['error'] ?? 'probe_failed'), $adminId);
        return ['ok' => $ok, 'latency_ms' => $ms, 'error' => $ok ? null : ($res['error'] ?? 'probe_failed')];
    }
}

if (!function_exists('ai_is_transient_error')) {
    /**
     * Whether a provider error is worth retrying. Timeouts, connection resets and 5xx/429
     * are transient; a 4xx auth/validation error is not — retrying it just wastes a call,
     * and retrying an auth failure can look like a brute-force attempt.
     */
    function ai_is_transient_error(?string $error): bool {
        if ($error === null || $error === '') return false;
        $e = strtolower($error);
        foreach (['timeout', 'timed out', 'curl error 7', 'curl error 28', 'curl error 35', 'curl error 52',
                  'curl error 56', 'connection reset', 'temporarily unavailable', 'http 429', 'http 500',
                  'http 502', 'http 503', 'http 504', 'rate limit'] as $needle) {
            if (str_contains($e, $needle)) return true;
        }
        return false;
    }
}

if (!function_exists('ai_resilient_gateway_call')) {
    /**
     * Call the AI gateway with retry/backoff and a rate-limit gate.
     *
     * Differences from calling ai_gateway_call() directly:
     *   - refuses up-front when the hourly limit is spent (before any provider call),
     *   - retries transient failures, re-running the full privacy gate each attempt,
     *   - on exhaustion, optionally returns the local on-host answer (local_fallback=true).
     *
     * @return array{ok:bool,text:?string,denied:bool,reason:?string,error:?string,local_fallback:bool,attempts:int,input_tokens:?int,output_tokens:?int}
     */
    function ai_resilient_gateway_call(
        ?PDO $pdo,
        array $settings,
        array $tenant,
        string $feature,
        string $dataCategory,
        int $actorId,
        string $actorType,
        string $systemPrompt,
        string $userMessage,
        int $maxTokens = 900,
        ?float $temperature = null,
        ?string $moduleId = null,
        bool $localFallback = true,
        bool $isArabic = true
    ): array {
        $base = ['ok' => false, 'text' => null, 'denied' => false, 'reason' => null, 'error' => null,
                 'local_fallback' => false, 'attempts' => 0, 'input_tokens' => null, 'output_tokens' => null];

        if (!$pdo instanceof PDO) {
            $base['error'] = 'no_pdo';
            return $base;
        }

        $rl = ai_rate_limit_remaining($pdo, $tenant, $settings);
        if ($rl['exceeded']) {
            $base['denied'] = true;
            $base['reason'] = 'rate_limited';
            $base['error'] = $isArabic
                ? 'تم بلوغ الحد الأقصى لاستخدام الذكاء الاصطناعي لهذه الساعة. حاول مرة أخرى لاحقاً.'
                : 'The hourly AI usage limit has been reached. Please try again later.';
            if ($localFallback) {
                $local = local_law_fallback($userMessage, $isArabic);
                $base['text'] = $local['text'] ?? ($local['answer'] ?? null);
                $base['local_fallback'] = true;
            }
            ai_gateway_log_usage($pdo, $tenant, $feature, $actorType, $actorId, $settings, false, null, null, null, false, 'rate_limited', null, $moduleId);
            return $base;
        }

        $maxAttempts = 1 + ai_max_retries($settings);
        $lastError = null;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $base['attempts'] = $attempt;
            $res = ai_gateway_call($pdo, $settings, $tenant, $feature, $dataCategory, $actorId, $actorType,
                                   $systemPrompt, $userMessage, $maxTokens, $temperature, $moduleId);

            if (!empty($res['ok']) && $res['text'] !== null) {
                $base['ok'] = true;
                $base['text'] = $res['text'];
                $base['input_tokens'] = $res['input_tokens'] ?? null;
                $base['output_tokens'] = $res['output_tokens'] ?? null;
                return $base;
            }
            // A policy denial is final — never retried, never fallen back through a provider.
            if (!empty($res['denied'])) {
                $base['denied'] = true;
                $base['reason'] = $res['reason'] ?? 'external_denied';
                $base['error'] = $res['error'] ?? null;
                return $base;
            }
            $lastError = $res['error'] ?? 'provider_failed';
            if ($attempt < $maxAttempts && ai_is_transient_error($lastError)) {
                // Exponential backoff, capped, so a struggling provider is not hammered.
                $sleep = min(4, 1 << ($attempt - 1));
                usleep($sleep * 400000);
                continue;
            }
            break;
        }

        $base['error'] = $lastError;
        if ($localFallback) {
            $local = local_law_fallback($userMessage, $isArabic);
            $text = $local['text'] ?? ($local['answer'] ?? null);
            if ($text !== null) {
                $base['text'] = $text;
                $base['local_fallback'] = true;
                $base['ok'] = false; // served, but not by the model — callers must label it
            }
        }
        return $base;
    }
}

if (!function_exists('ai_provider_status_snapshot')) {
    /** Latest recorded health per provider, for the admin surface. */
    function ai_provider_status_snapshot(PDO $pdo): array {
        try {
            $rows = $pdo->query('SELECT provider, model, ok, latency_ms, error_message, created_at
                                 FROM ai_provider_health h
                                 WHERE id = (SELECT MAX(id) FROM ai_provider_health h2 WHERE h2.provider=h.provider)
                                 ORDER BY provider')->fetchAll();
            return $rows;
        } catch (PDOException $e) {
            return [];
        }
    }
}
