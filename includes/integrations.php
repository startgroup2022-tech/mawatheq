<?php
/**
 * External integration readiness (Phase 5).
 *
 * Two halves, both firm-scoped:
 *
 *   Inbound — scoped API tokens. A firm (or an operator acting for the whole platform)
 *   issues a token limited to a set of scopes. The REST API checks the scope before it
 *   reads or writes anything, so a partner integration can be granted the minimum it
 *   needs instead of a shared all-powerful secret.
 *
 *   Outbound — signed webhooks. A firm registers an endpoint; Mawatheq emits events and
 *   delivers them with an HMAC-SHA256 signature over `<timestamp>.<body>`. The receiver
 *   verifies the signature and the timestamp window, which is what stops a captured
 *   payload from being replayed later. Each delivery is recorded once per (endpoint,
 *   event), so a retry can never double-post the same event.
 *
 * Deliberately NOT built here: any fraud / suspicious-message analysis. This module only
 * hardens how Mawatheq talks to its own integrators.
 */

require_once __DIR__ . '/secret_crypto.php';
require_once __DIR__ . '/schema_guard.php';

const INTEGRATION_TOKEN_PREFIX = 'mwq_';
const INTEGRATION_SIGNATURE_TOLERANCE = 300; // seconds a receiver should accept either side of now

/* ------------------------------------------------------------------ scopes */

/**
 * All scopes a token may be granted. Every scope listed here is enforced on a real
 * endpoint; nothing is advertised before it is actually checked. Reads are separated
 * from writes so a reporting integration can be granted read-only access.
 */
function integration_scopes(): array {
    return [
        'cases:read'     => 'قراءة القضايا',
        'invoices:read'  => 'قراءة الفواتير',
        'documents:read' => 'قراءة المستندات',
        'calendar:read'  => 'قراءة التقويم',
    ];
}

/** Parse a stored scope string into a clean list; '*' means every scope. */
function integration_scopes_parse(?string $stored): array {
    $parts = array_values(array_filter(array_map('trim', explode(',', (string)$stored)), static fn($s) => $s !== ''));
    if (in_array('*', $parts, true)) return array_keys(integration_scopes());
    return array_values(array_intersect($parts, array_keys(integration_scopes())));
}

/** True when a token's stored scopes permit $needed. */
function integration_scope_allows(?string $storedScopes, string $needed): bool {
    $parts = array_values(array_filter(array_map('trim', explode(',', (string)$storedScopes)), static fn($s) => $s !== ''));
    if (in_array('*', $parts, true)) return true;
    return in_array($needed, $parts, true);
}

/* ------------------------------------------------------------- API tokens */

/**
 * Issue a new scoped API token for a firm (or platform-wide when $firmId is null).
 * The raw token is returned exactly once; only its SHA-256 hash is stored.
 * @return array{ok:bool, token:?string, id:?int, error:?string}
 */
function integration_token_issue(PDO $pdo, ?int $firmId, string $label, array $scopes): array {
    // Scopes are granted explicitly, never as a wildcard: a stored '*' would satisfy every
    // current and future scope via integration_scope_allows(). Refuse it loudly rather than
    // silently dropping it, so a caller never believes it granted more than it did.
    if (in_array('*', $scopes, true)) return ['ok' => false, 'token' => null, 'id' => null, 'error' => 'الصلاحية الشاملة غير مسموح بها؛ اختر صلاحيات محددة.'];
    $scopes = array_values(array_intersect($scopes, array_keys(integration_scopes())));
    if (!$scopes) return ['ok' => false, 'token' => null, 'id' => null, 'error' => 'اختر صلاحية واحدة على الأقل.'];
    $label = trim($label) !== '' ? mb_substr(trim($label), 0, 150, 'UTF-8') : 'تكامل';
    $token = INTEGRATION_TOKEN_PREFIX . bin2hex(random_bytes(24));
    try {
        $pdo->prepare('INSERT INTO integration_api_tokens (firm_id,label,token_hash,scopes) VALUES (?,?,?,?)')
            ->execute([$firmId, $label, hash('sha256', $token), implode(',', $scopes)]);
        return ['ok' => true, 'token' => $token, 'id' => (int)$pdo->lastInsertId(), 'error' => null];
    } catch (PDOException $e) {
        error_log('integration_token_issue failed: ' . $e->getMessage());
        return ['ok' => false, 'token' => null, 'id' => null, 'error' => 'تعذّر إنشاء الرمز.'];
    }
}

/** Resolve a raw bearer token to its row, or null when unknown/revoked/inactive. */
function integration_token_find(PDO $pdo, string $rawToken): ?array {
    $rawToken = trim($rawToken);
    if ($rawToken === '' || strpos($rawToken, INTEGRATION_TOKEN_PREFIX) !== 0) return null;
    try {
        $stmt = $pdo->prepare('SELECT * FROM integration_api_tokens WHERE token_hash=? AND is_active=1 AND revoked_at IS NULL LIMIT 1');
        $stmt->execute([hash('sha256', $rawToken)]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (PDOException $e) { return null; }
}

/** Record that a token was used. Best-effort; never blocks the request. */
function integration_token_touch(PDO $pdo, int $tokenId): void {
    try { $pdo->prepare('UPDATE integration_api_tokens SET last_used_at=NOW() WHERE id=?')->execute([$tokenId]); }
    catch (PDOException $e) { /* non-fatal */ }
}

/** Revoke a token. Firm-scoped callers may only revoke their own firm's tokens. */
function integration_token_revoke(PDO $pdo, int $tokenId, ?int $firmId = null): bool {
    try {
        $sql = 'UPDATE integration_api_tokens SET is_active=0, revoked_at=NOW() WHERE id=? AND revoked_at IS NULL';
        $params = [$tokenId];
        if ($firmId !== null) { $sql .= ' AND firm_id=?'; $params[] = $firmId; }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) { return false; }
}

/** List tokens for a firm, or all tokens when $firmId is null (admin view). Never returns the hash. */
function integration_tokens_list(PDO $pdo, ?int $firmId = null): array {
    try {
        if ($firmId === null) {
            return $pdo->query('SELECT id,firm_id,label,scopes,is_active,last_used_at,created_at,revoked_at FROM integration_api_tokens ORDER BY id DESC')->fetchAll();
        }
        $stmt = $pdo->prepare('SELECT id,firm_id,label,scopes,is_active,last_used_at,created_at,revoked_at FROM integration_api_tokens WHERE firm_id=? ORDER BY id DESC');
        $stmt->execute([$firmId]);
        return $stmt->fetchAll();
    } catch (PDOException $e) { return []; }
}

/* ------------------------------------------------------------- webhook CRUD */

/** Validate an outbound webhook target: public http/https only, no embedded credentials. */
function integration_webhook_url_valid(string $url): bool {
    $parts = parse_url(trim($url));
    if (!is_array($parts) || empty($parts['host'])) return false;
    if (!in_array(strtolower($parts['scheme'] ?? ''), ['https', 'http'], true)) return false;
    if (isset($parts['user']) || isset($parts['pass'])) return false;
    return true;
}

/**
 * Register an outbound webhook. The signing secret is generated here and returned once
 * so the operator can paste it into the receiving system; it is stored encrypted.
 * @return array{ok:bool, id:?int, secret:?string, error:?string}
 */
function integration_webhook_create(PDO $pdo, ?int $firmId, string $label, string $targetUrl, array $eventTypes): array {
    $targetUrl = trim($targetUrl);
    if (!integration_webhook_url_valid($targetUrl)) {
        return ['ok' => false, 'id' => null, 'secret' => null, 'error' => 'عنوان الـ Webhook غير صالح؛ يجب أن يكون رابط http أو https عاماً.'];
    }
    $label = trim($label) !== '' ? mb_substr(trim($label), 0, 150, 'UTF-8') : 'تكامل خارجي';
    $types = integration_event_types_filter($eventTypes);
    $secret = bin2hex(random_bytes(32));
    try {
        $pdo->prepare('INSERT INTO integration_webhooks (firm_id,label,target_url,secret_enc,event_types) VALUES (?,?,?,?,?)')
            ->execute([$firmId, $label, $targetUrl, secret_encrypt_value($secret), implode(',', $types)]);
        return ['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'secret' => $secret, 'error' => null];
    } catch (PDOException $e) {
        error_log('integration_webhook_create failed: ' . $e->getMessage());
        return ['ok' => false, 'id' => null, 'secret' => null, 'error' => 'تعذّر حفظ الـ Webhook.'];
    }
}

/** Keep only recognised event types; empty selection means "all events". */
function integration_event_types_filter(array $types): array {
    $types = array_values(array_intersect($types, array_keys(integration_event_catalog())));
    return $types ?: ['*'];
}

/**
 * The events Mawatheq actually emits. Kept explicit so a typo cannot silently drop events,
 * and kept to the events wired into real code paths: a partner who subscribes to an event
 * that is never emitted would wait forever, so nothing is advertised before it fires.
 * Add an entry here only together with the call site that emits it.
 */
function integration_event_catalog(): array {
    return [
        'invoice.paid'          => 'سداد فاتورة',
        'case.created'          => 'فتح قضية',
        'case.status_changed'   => 'تغيّر حالة قضية',
        'consultation.booked'   => 'حجز استشارة',
    ];
}

/** List webhooks for a firm (or all when null). Secrets are never returned. */
function integration_webhooks_list(PDO $pdo, ?int $firmId = null): array {
    try {
        if ($firmId === null) {
            return $pdo->query('SELECT id,firm_id,label,target_url,event_types,is_active,created_at FROM integration_webhooks ORDER BY id DESC')->fetchAll();
        }
        $stmt = $pdo->prepare('SELECT id,firm_id,label,target_url,event_types,is_active,created_at FROM integration_webhooks WHERE firm_id=? ORDER BY id DESC');
        $stmt->execute([$firmId]);
        return $stmt->fetchAll();
    } catch (PDOException $e) { return []; }
}

/** Delete a webhook (and its deliveries, via ON DELETE CASCADE). Firm-scoped when given. */
function integration_webhook_delete(PDO $pdo, int $webhookId, ?int $firmId = null): bool {
    try {
        $sql = 'DELETE FROM integration_webhooks WHERE id=?';
        $params = [$webhookId];
        if ($firmId !== null) { $sql .= ' AND firm_id=?'; $params[] = $firmId; }
        $stmt = $pdo->prepare($sql); $stmt->execute($params);
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) { return false; }
}

/* ---------------------------------------------------------------- events */

/**
 * Record an event and queue it for every matching active endpoint. Returns the event id.
 * Delivery rows are created here (status pending) so nothing is lost if the process that
 * emitted the event cannot deliver synchronously.
 */
function integration_event_emit(PDO $pdo, ?int $firmId, string $eventType, array $payload): ?int {
    if (!isset(integration_event_catalog()[$eventType])) {
        error_log('integration_event_emit: unknown event type ' . $eventType);
        return null;
    }
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($json === false) $json = '{}';
    try {
        $pdo->prepare('INSERT INTO integration_events (firm_id,event_type,payload) VALUES (?,?,?)')
            ->execute([$firmId, $eventType, $json]);
        $eventId = (int)$pdo->lastInsertId();

        // Endpoints for this firm, plus platform-wide endpoints (firm_id IS NULL).
        if ($firmId === null) {
            $stmt = $pdo->query('SELECT id,event_types FROM integration_webhooks WHERE is_active=1 AND firm_id IS NULL');
        } else {
            $stmt = $pdo->prepare('SELECT id,event_types FROM integration_webhooks WHERE is_active=1 AND (firm_id = ? OR firm_id IS NULL)');
            $stmt->execute([$firmId]);
        }
        $insert = $pdo->prepare('INSERT IGNORE INTO integration_deliveries (webhook_id,event_id,event_type,payload) VALUES (?,?,?,?)');
        foreach ($stmt->fetchAll() as $hook) {
            $types = array_values(array_filter(array_map('trim', explode(',', (string)$hook['event_types'])), static fn($t) => $t !== ''));
            if (!in_array('*', $types, true) && !in_array($eventType, $types, true)) continue;
            $insert->execute([(int)$hook['id'], $eventId, $eventType, $json]);
        }
        return $eventId;
    } catch (PDOException $e) {
        error_log('integration_event_emit failed: ' . $e->getMessage());
        return null;
    }
}

/** Sign a webhook body. Format is `<timestamp>.<raw body>` with HMAC-SHA256. */
function integration_webhook_signature(string $secret, string $body, int $timestamp): string {
    return hash_hmac('sha256', $timestamp . '.' . $body, $secret);
}

/**
 * Verify an inbound signature against the tolerance window. Present so receivers have a
 * reference implementation and so the platform can be tested end to end.
 */
function integration_webhook_verify(string $secret, string $body, string $signature, int $timestamp, int $tolerance = INTEGRATION_SIGNATURE_TOLERANCE): bool {
    if (abs(time() - $timestamp) > $tolerance) return false;
    return hash_equals(integration_webhook_signature($secret, $body, $timestamp), $signature);
}

/**
 * Deliver one queued delivery. Idempotent per (webhook,event): a delivery already marked
 * delivered is skipped, so a retry can never double-post.
 * @return array{ok:bool, status:string, http_code:?int, error:?string}
 */
function integration_delivery_attempt(PDO $pdo, array $delivery, ?array $webhook = null): array {
    $deliveryId = (int)$delivery['id'];

    // Claim the delivery atomically. The update only lands when it has not already been
    // delivered, so a replay — a cron re-run, the admin drain, a stale array passed by a
    // caller — cannot post the same event twice. This is the authoritative check; the
    // caller's copy of the row is never trusted for the delivered/not-delivered decision.
    try {
        $claim = $pdo->prepare("UPDATE integration_deliveries SET status='delivering' WHERE id=? AND status<>'delivered'");
        $claim->execute([$deliveryId]);
        if ($claim->rowCount() === 0) {
            return ['ok' => true, 'status' => 'delivered', 'http_code' => null, 'error' => null];
        }
    } catch (PDOException $e) {
        error_log('integration_delivery_attempt claim failed: ' . $e->getMessage());
        return ['ok' => false, 'status' => 'failed', 'http_code' => null, 'error' => 'claim failed'];
    }

    if ($webhook === null) {
        $stmt = $pdo->prepare('SELECT * FROM integration_webhooks WHERE id=?');
        $stmt->execute([(int)$delivery['webhook_id']]);
        $webhook = $stmt->fetch();
    }
    if (!$webhook || empty($webhook['is_active'])) {
        integration_delivery_mark($pdo, $deliveryId, 'skipped', null, 'endpoint inactive or missing');
        return ['ok' => false, 'status' => 'skipped', 'http_code' => null, 'error' => 'endpoint inactive or missing'];
    }
    $url = (string)$webhook['target_url'];
    if (!integration_webhook_url_valid($url)) {
        integration_delivery_mark($pdo, $deliveryId, 'failed', null, 'invalid target url');
        return ['ok' => false, 'status' => 'failed', 'http_code' => null, 'error' => 'invalid target url'];
    }
    $secret = secret_decrypt_value((string)($webhook['secret_enc'] ?? ''));
    $body = (string)$delivery['payload'];
    $timestamp = time();
    $signature = $secret !== '' ? integration_webhook_signature($secret, $body, $timestamp) : '';
    if (!function_exists('curl_init')) {
        integration_delivery_mark($pdo, $deliveryId, 'failed', null, 'cURL unavailable');
        return ['ok' => false, 'status' => 'failed', 'http_code' => null, 'error' => 'cURL unavailable'];
    }
    $headers = ['Content-Type: application/json', 'X-Mawatheq-Event: ' . (string)$delivery['event_type'], 'X-Mawatheq-Timestamp: ' . $timestamp];
    if ($signature !== '') $headers[] = 'X-Mawatheq-Signature: sha256=' . $signature;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $ok = $code >= 200 && $code < 300;
    integration_delivery_mark($pdo, $deliveryId, $ok ? 'delivered' : 'failed', $code ?: null, $ok ? null : ($err !== '' ? $err : ('HTTP ' . $code)));
    return ['ok' => $ok, 'status' => $ok ? 'delivered' : 'failed', 'http_code' => $code ?: null, 'error' => $ok ? null : $err];
}

/** Persist the outcome of a delivery attempt and bump its attempt counter. */
function integration_delivery_mark(PDO $pdo, int $deliveryId, string $status, ?int $httpCode, ?string $error): void {
    try {
        $pdo->prepare('UPDATE integration_deliveries SET status=?, http_code=?, attempts=attempts+1, last_error=?, delivered_at=? WHERE id=?')
            ->execute([$status, $httpCode, $error === null ? null : mb_substr($error, 0, 500, 'UTF-8'), $status === 'delivered' ? date('Y-m-d H:i:s') : null, $deliveryId]);
    } catch (PDOException $e) { error_log('integration_delivery_mark failed: ' . $e->getMessage()); }
}

/**
 * Drain queued deliveries, optionally capped. Called by the cron drain endpoint so a slow
 * or dead endpoint cannot stall the request that emitted the event.
 *
 * Delivery is at-least-once: 'delivering' rows are included so a delivery interrupted by a
 * crash or timeout is retried rather than stranded. Receivers should treat the event id as
 * an idempotency key (a redelivery carries the same event id).
 * @return array{attempted:int, delivered:int, failed:int}
 */
function integration_deliver_pending(PDO $pdo, int $limit = 25): array {
    $attempted = 0; $delivered = 0; $failed = 0;
    try {
        $stmt = $pdo->prepare("SELECT * FROM integration_deliveries WHERE status IN ('pending','failed','delivering') ORDER BY id ASC LIMIT ?");
        $stmt->bindValue(1, max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) { return ['attempted' => 0, 'delivered' => 0, 'failed' => 0]; }

    $hooks = [];
    foreach ($rows as $delivery) {
        $attempted++;
        $wid = (int)$delivery['webhook_id'];
        if (!array_key_exists($wid, $hooks)) {
            $hs = $pdo->prepare('SELECT * FROM integration_webhooks WHERE id=?');
            $hs->execute([$wid]);
            $hooks[$wid] = $hs->fetch() ?: null;
        }
        $res = integration_delivery_attempt($pdo, $delivery, $hooks[$wid]);
        if ($res['ok']) $delivered++; else $failed++;
    }
    return ['attempted' => $attempted, 'delivered' => $delivered, 'failed' => $failed];
}

/** Recent delivery log for an operator view. */
function integration_deliveries_recent(PDO $pdo, int $limit = 50, ?int $firmId = null): array {
    try {
        $sql = 'SELECT d.*, w.label AS webhook_label, w.target_url FROM integration_deliveries d JOIN integration_webhooks w ON w.id=d.webhook_id';
        $params = [];
        if ($firmId !== null) { $sql .= ' WHERE w.firm_id=?'; $params[] = $firmId; }
        $sql .= ' ORDER BY d.id DESC LIMIT ?';
        $stmt = $pdo->prepare($sql);
        $i = 1;
        foreach ($params as $p) { $stmt->bindValue($i++, $p, PDO::PARAM_INT); }
        $stmt->bindValue($i, max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (PDOException $e) { return []; }
}

/* --------------------------------------------------- domain wiring helpers */

/** Resolve the firm a lawyer belongs to, or null for a solo lawyer / no membership. */
function integration_firm_for_lawyer(PDO $pdo, int $lawyerId): ?int {
    if ($lawyerId <= 0) return null;
    try {
        $stmt = $pdo->prepare("SELECT firm_id FROM law_firm_members WHERE lawyer_id=? AND status='active' LIMIT 1");
        $stmt->execute([$lawyerId]);
        $firm = $stmt->fetchColumn();
        return ($firm !== false && $firm !== null) ? (int)$firm : null;
    } catch (PDOException $e) { return null; }
}

/**
 * Emit a domain event without ever letting integration problems break the caller's
 * request. Called from the point where the business action already succeeded, so a
 * missing table or a bad payload is logged and swallowed rather than surfaced to a user.
 */
function integration_emit_quietly(PDO $pdo, ?int $firmId, string $eventType, array $payload): void {
    try {
        integration_event_emit($pdo, $firmId, $eventType, $payload);
    } catch (Throwable $e) {
        error_log('integration_emit_quietly(' . $eventType . '): ' . $e->getMessage());
    }
}

/** Convenience: emit an event for the firm a lawyer belongs to. */
function integration_emit_for_lawyer(PDO $pdo, int $lawyerId, string $eventType, array $payload): void {
    integration_emit_quietly($pdo, integration_firm_for_lawyer($pdo, $lawyerId), $eventType, $payload);
}
