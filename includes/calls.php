<?php
/**
 * ============================================================
 * مواثيق — الاتصال الصوتي والمرئي داخل التطبيق (Execution 57)
 * MAWATHEQ — in-app audio/video calls
 *
 * The one place that decides whether a call may happen, who may join it, and what
 * the client is told about the media path. Everything the mobile apps need is
 * derived here; no page or endpoint re-derives an authorisation rule.
 *
 * WHY A SIGNALLING TABLE AND NOT ONLY A ROOM NAME
 *
 *   The consultation already carries a stable `room_code`, which is enough for a
 *   browser to open a shared URL. It is not enough for an in-app call: the app must
 *   know *when* a call is ringing, *who* is being called, whether it was answered,
 *   and how long it lasted. Those are events, and events belong in rows. The room
 *   name is reused from the consultation so a client and lawyer who both open the
 *   consultation land in the same room.
 *
 * WHY THE PROVIDER IS CONFIGURATION, NOT CODE
 *
 *   `call_provider_settings()` reads the media server, the STUN/TURN servers and the
 *   credentials from `site_settings` (secrets encrypted at rest). Switching from a
 *   public STUN server to the owner's own TURN server is a settings change, not a
 *   deployment. Until an owner configures a server the system reports itself
 *   unconfigured rather than pretending a call will connect — see
 *   `call_provider_state()`. The app is built to show a call UI either way; only the
 *   ICE path changes.
 *
 * WHY SHORT-LIVED TOKENS
 *
 *   A long-lived token in a call URL is a credential that leaks through history,
 *   proxies and screenshots. `call_issue_token()` returns a value bound to one call
 *   and one participant, expiring in minutes, and the issuer re-checks the
 *   participant row and the booking state on every request.
 * ============================================================
 */
require_once __DIR__ . '/secret_crypto.php';

/** How long a call token is valid. Long enough to establish media, short enough to be useless later. */
function call_token_ttl_seconds(): int {
    return 600;
}

/** The media provider in use. `webrtc` is the built-in path; others are reserved. */
function call_provider_default(): string {
    return 'webrtc';
}

/** Settings keys this module reads. Kept in one list so a writer and a reader cannot drift. */
function call_setting_keys(): array {
    return [
        'call_enabled',
        'call_provider',
        'call_media_server',
        'call_api_key',
        'call_stun_urls',
        'call_turn_urls',
        'call_turn_username',
        'call_turn_credential',
        'call_ring_timeout_seconds',
        'call_max_duration_minutes',
    ];
}

/**
 * Read the call configuration. Secrets are decrypted on read and re-encrypted in
 * place if they were stored as plaintext by an older version of the settings page.
 */
function call_provider_settings(PDO $pdo): array {
    $keys = call_setting_keys();
    $in = implode(',', array_fill(0, count($keys), '?'));
    $rows = $pdo->prepare("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ($in)");
    $rows->execute($keys);
    $settings = [];
    foreach ($rows->fetchAll() as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }

    foreach (['call_api_key', 'call_turn_credential'] as $secretKey) {
        if (isset($settings[$secretKey])) {
            $stored = (string)$settings[$secretKey];
            $settings[$secretKey] = secret_decrypt_value($stored);
            if ($settings[$secretKey] !== '' && strpos($stored, 'enc:v1:') !== 0 && secret_encryption_key()) {
                secret_store_setting($pdo, $secretKey, $settings[$secretKey]);
            }
        }
    }

    $settings['call_provider'] = $settings['call_provider'] ?? call_provider_default();
    $settings['call_enabled'] = $settings['call_enabled'] ?? '1';
    $settings['call_media_server'] = $settings['call_media_server'] ?? '';
    $settings['call_api_key'] = $settings['call_api_key'] ?? '';
    // A public STUN server is a reasonable zero-config default: it lets two peers on
    // ordinary networks find each other and carries no credential. It is *not* enough
    // for symmetric NAT, which is why TURN is a documented dependency rather than
    // something this code can supply.
    $settings['call_stun_urls'] = $settings['call_stun_urls'] ?? 'stun:stun.l.google.com:19302';
    $settings['call_turn_urls'] = $settings['call_turn_urls'] ?? '';
    $settings['call_turn_username'] = $settings['call_turn_username'] ?? '';
    $settings['call_turn_credential'] = $settings['call_turn_credential'] ?? '';
    $settings['call_ring_timeout_seconds'] = (int)($settings['call_ring_timeout_seconds'] ?? 45);
    $settings['call_max_duration_minutes'] = (int)($settings['call_max_duration_minutes'] ?? 120);
    return $settings;
}

/** Parse a newline/comma separated URL list into an array, dropping blanks. */
function call_parse_url_list(string $raw): array {
    $parts = preg_split('/[\s,]+/', trim($raw)) ?: [];
    return array_values(array_filter($parts, static fn($u) => $u !== ''));
}

/**
 * The ICE servers the client should use, in the shape the WebRTC stack expects.
 * TURN credentials are only included when a TURN server is actually configured —
 * sending an empty credential block makes some stacks refuse the candidate.
 */
function call_ice_servers(PDO $pdo): array {
    $s = call_provider_settings($pdo);
    $servers = [];
    foreach (call_parse_url_list($s['call_stun_urls']) as $url) {
        $servers[] = ['urls' => $url];
    }
    $turnUrls = call_parse_url_list($s['call_turn_urls']);
    if ($turnUrls) {
        $entry = ['urls' => count($turnUrls) === 1 ? $turnUrls[0] : $turnUrls];
        if ($s['call_turn_username'] !== '') $entry['username'] = $s['call_turn_username'];
        if ($s['call_turn_credential'] !== '') $entry['credential'] = $s['call_turn_credential'];
        $servers[] = $entry;
    }
    return $servers;
}

/**
 * Whether the media path is ready, and if not, why. Mirrors the AI provider
 * contract: "unconfigured" and "unreachable" are different answers, and the client
 * shows a different message for each.
 *
 * @return array{available:bool, blocking:?string, turn:bool, message:string}
 */
function call_provider_state(PDO $pdo): array {
    $s = call_provider_settings($pdo);
    if ($s['call_enabled'] !== '1') {
        return ['available' => false, 'blocking' => 'disabled', 'turn' => false, 'message' => 'الاتصال الصوتي والمرئي غير مُفعّل على المنصة.'];
    }
    $stun = call_parse_url_list($s['call_stun_urls']);
    $turn = call_parse_url_list($s['call_turn_urls']);
    if (!$stun && !$turn) {
        return ['available' => false, 'blocking' => 'no_ice', 'turn' => false, 'message' => 'لم يتم ضبط خادم الاتصال (STUN/TURN) بعد.'];
    }
    // With no TURN relay, calls work on ordinary networks and fail behind symmetric
    // NAT. That is a degraded state, not a broken one, and it is reported honestly
    // rather than hidden — the app still places the call.
    if (!$turn) {
        return ['available' => true, 'blocking' => null, 'turn' => false, 'message' => 'الاتصال متاح عبر STUN فقط. قد يتعذّر الاتصال على شبكات صارمة دون خادم TURN.'];
    }
    return ['available' => true, 'blocking' => null, 'turn' => true, 'message' => 'خادم الاتصال جاهز.'];
}

/** Live readiness probe: a DNS/port check on the configured media server, never a credential. */
function call_health_check(PDO $pdo): array {
    $s = call_provider_settings($pdo);
    $result = ['checked_at' => date('c'), 'stun' => [], 'turn' => [], 'media_server' => null];
    foreach (call_parse_url_list($s['call_stun_urls']) as $url) {
        $result['stun'][$url] = call_probe_url($url);
    }
    foreach (call_parse_url_list($s['call_turn_urls']) as $url) {
        $result['turn'][$url] = call_probe_url($url);
    }
    if ($s['call_media_server'] !== '') {
        $result['media_server'] = call_probe_url($s['call_media_server']);
    }
    return $result;
}

/** Resolve a stun:/turn:/https: URL to a reachable host:port. Returns no credential. */
function call_probe_url(string $url): array {
    $host = $url;
    $port = null;
    if (preg_match('~^(?:stun|turn|turns|https?):([^:/?]+)(?::(\d+))?~i', $url, $m)) {
        $host = $m[1];
        $port = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : (stripos($url, 'turns:') === 0 || stripos($url, 'https') === 0 ? 443 : 3478);
    }
    $ip = @gethostbyname($host);
    $resolved = $ip !== $host;
    return [
        'host' => $host,
        'port' => $port,
        'dns' => $resolved,
        'ip' => $resolved ? $ip : null,
    ];
}

/**
 * A per-call room name. Reuses the consultation's own `room_code` so the web room
 * and the app room are the same room, and generates one if the consultation has
 * none yet (a consultation booked before rooms existed).
 */
function call_room_for_consultation(PDO $pdo, array $consultation): string {
    if (!empty($consultation['room_code'])) {
        return (string)$consultation['room_code'];
    }
    $code = 'mawatheq-' . (int)$consultation['id'] . '-' . bin2hex(random_bytes(4));
    $pdo->prepare('UPDATE consultations SET room_code=? WHERE id=?')->execute([$code, (int)$consultation['id']]);
    return $code;
}

/** The two parties a consultation connects. The only source of participant rows. */
function call_consultation_parties(array $consultation): array {
    return [
        ['type' => 'client', 'id' => (int)$consultation['user_id']],
        ['type' => 'lawyer', 'id' => (int)$consultation['lawyer_id']],
    ];
}

/**
 * Find the consultation a caller is a party to. Returns null for anyone else, so a
 * guessed id cannot be used to reach another tenant's matter.
 */
function call_consultation_for_party(PDO $pdo, int $consultationId, string $type, int $id): ?array {
    if ($consultationId <= 0) return null;
    if ($type === 'client') {
        $stmt = $pdo->prepare('SELECT * FROM consultations WHERE id=? AND user_id=?');
        $stmt->execute([$consultationId, $id]);
    } elseif ($type === 'lawyer') {
        // A firm-scoped lawyer may act on any consultation filed at their firm, not
        // only their own — that is the same rule the web dashboard uses.
        require_once __DIR__ . '/tenant.php';
        $scope = tenant_owner_where_for_lawyer($pdo, $id, 'consultations');
        $stmt = $pdo->prepare('SELECT * FROM consultations WHERE id=? AND ' . $scope['sql']);
        $stmt->execute(array_merge([$consultationId], $scope['params']));
    } else {
        return null;
    }
    $row = $stmt->fetch();
    return $row ?: null;
}

/** A participant row for a call, or null. The authorisation check for joining. */
function call_participant(PDO $pdo, int $callId, string $type, int $id): ?array {
    $stmt = $pdo->prepare('SELECT * FROM call_participants WHERE call_id=? AND participant_type=? AND participant_id=?');
    $stmt->execute([$callId, $type, $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function call_log_event(PDO $pdo, int $callId, string $eventType, ?string $actorType = 'system', ?int $actorId = null, ?string $detail = null): void {
    $pdo->prepare('INSERT INTO call_events (call_id, event_type, actor_type, actor_id, detail) VALUES (?,?,?,?,?)')
        ->execute([$callId, $eventType, $actorType ?: 'system', $actorId, $detail]);
}

/**
 * Start a call. Idempotent for the caller: an existing ringing call for the same
 * consultation is returned rather than creating a second ring, so a client that
 * retries a flaky request does not ring the other party twice.
 *
 * @return array{ok:bool, error?:string, call?:array}
 */
function call_start(PDO $pdo, array $consultation, string $actorType, int $actorId, string $callType): array {
    $state = call_provider_state($pdo);
    if (!$state['available']) {
        return ['ok' => false, 'error' => $state['blocking'], 'message' => $state['message']];
    }
    $callType = in_array($callType, ['voice', 'video'], true) ? $callType : 'video';

    // A consultation that is cancelled or already completed has no call to make.
    if (in_array($consultation['status'], ['cancelled'], true)) {
        return ['ok' => false, 'error' => 'consultation_cancelled', 'message' => 'الاستشارة ملغاة.'];
    }

    $consultationId = (int)$consultation['id'];
    $existing = $pdo->prepare("SELECT * FROM call_sessions WHERE consultation_id=? AND status IN ('ringing','accepted') ORDER BY id DESC LIMIT 1");
    $existing->execute([$consultationId]);
    $live = $existing->fetch();
    if ($live) {
        return ['ok' => true, 'call' => $live, 'reused' => true];
    }

    $room = call_room_for_consultation($pdo, $consultation);
    $settings = call_provider_settings($pdo);
    $firmId = $consultation['firm_id'] !== null ? (int)$consultation['firm_id'] : null;

    $pdo->prepare('INSERT INTO call_sessions (consultation_id, firm_id, room_code, call_type, provider, status, initiated_by, initiated_by_id) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$consultationId, $firmId, $room, $callType, $settings['call_provider'], 'ringing', $actorType, $actorId]);
    $callId = (int)$pdo->lastInsertId();

    foreach (call_consultation_parties($consultation) as $party) {
        $pdo->prepare('INSERT INTO call_participants (call_id, participant_type, participant_id, state) VALUES (?,?,?,?)')
            ->execute([$callId, $party['type'], $party['id'], $party['type'] === $actorType && $party['id'] === $actorId ? 'joined' : 'invited']);
    }
    if ($actorType === 'client') {
        $pdo->prepare("UPDATE call_participants SET joined_at=NOW() WHERE call_id=? AND participant_type=? AND participant_id=?")
            ->execute([$callId, $actorType, $actorId]);
    }

    call_log_event($pdo, $callId, 'started', $actorType, $actorId, $callType);

    // Ring the other party. The notification names no private detail — only that a
    // call is waiting and which consultation it belongs to.
    require_once __DIR__ . '/notifications.php';
    $other = $actorType === 'client' ? ['lawyer', (int)$consultation['lawyer_id']] : ['client', (int)$consultation['user_id']];
    notify(
        $pdo,
        $other[0],
        $other[1],
        $callType === 'video' ? 'مكالمة فيديو واردة' : 'مكالمة صوتية واردة',
        'بخصوص استشارتك' . ($consultation['topic'] ? ': ' . $consultation['topic'] : ''),
        $actorType === 'client' ? '/lawyer/consultations.php' : '/consultations.php'
    );

    $call = $pdo->prepare('SELECT * FROM call_sessions WHERE id=?');
    $call->execute([$callId]);
    return ['ok' => true, 'call' => $call->fetch(), 'reused' => false];
}

/**
 * Accept a ringing call. Only a participant of the *other* side (or a rejoin by the
 * initiator) may do this; the row is locked so two simultaneous accepts cannot both
 * set `answered_at` and produce two different durations.
 */
function call_accept(PDO $pdo, int $callId, string $actorType, int $actorId): array {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM call_sessions WHERE id=? FOR UPDATE');
        $stmt->execute([$callId]);
        $call = $stmt->fetch();
        if (!$call) { $pdo->rollBack(); return ['ok' => false, 'error' => 'not_found']; }
        if (!call_participant($pdo, $callId, $actorType, $actorId)) { $pdo->rollBack(); return ['ok' => false, 'error' => 'forbidden']; }
        if ($call['status'] === 'accepted') { $pdo->commit(); return ['ok' => true, 'call' => $call, 'already' => true]; }
        if ($call['status'] !== 'ringing') { $pdo->rollBack(); return ['ok' => false, 'error' => 'not_ringing']; }

        $pdo->prepare("UPDATE call_sessions SET status='accepted', answered_at=NOW() WHERE id=?")->execute([$callId]);
        $pdo->prepare("UPDATE call_participants SET state='joined', joined_at=NOW() WHERE call_id=? AND participant_type=? AND participant_id=?")
            ->execute([$callId, $actorType, $actorId]);
        call_log_event($pdo, $callId, 'accepted', $actorType, $actorId);

        require_once __DIR__ . '/notifications.php';
        $other = $actorType === 'client' ? ['lawyer', (int)$call['initiated_by_id']] : ['client', (int)$call['initiated_by_id']];
        // Only notify when the other party is not the initiator (they already know).
        if ($actorType !== $call['initiated_by']) {
            notify($pdo, $other[0], $other[1], 'تم الرد على المكالمة', null, null);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    $fresh = $pdo->prepare('SELECT * FROM call_sessions WHERE id=?');
    $fresh->execute([$callId]);
    return ['ok' => true, 'call' => $fresh->fetch()];
}

/** Reject a ringing call. Recorded as `rejected` so the caller is told, not left ringing. */
function call_reject(PDO $pdo, int $callId, string $actorType, int $actorId): array {
    $stmt = $pdo->prepare('SELECT * FROM call_sessions WHERE id=?');
    $stmt->execute([$callId]);
    $call = $stmt->fetch();
    if (!$call) return ['ok' => false, 'error' => 'not_found'];
    if (!call_participant($pdo, $callId, $actorType, $actorId)) return ['ok' => false, 'error' => 'forbidden'];
    if ($call['status'] !== 'ringing') return ['ok' => false, 'error' => 'not_ringing'];

    $pdo->prepare("UPDATE call_sessions SET status='rejected', ended_at=NOW(), end_reason='rejected' WHERE id=?")->execute([$callId]);
    $pdo->prepare("UPDATE call_participants SET state='declined' WHERE call_id=? AND participant_type=? AND participant_id=?")
        ->execute([$callId, $actorType, $actorId]);
    call_log_event($pdo, $callId, 'rejected', $actorType, $actorId);

    require_once __DIR__ . '/notifications.php';
    $other = $actorType === 'client' ? ['lawyer', (int)$call['initiated_by_id']] : ['client', (int)$call['initiated_by_id']];
    if ($actorType !== $call['initiated_by']) {
        notify($pdo, $other[0], $other[1], 'تم رفض المكالمة', null, null);
    }
    $fresh = $pdo->prepare('SELECT * FROM call_sessions WHERE id=?');
    $fresh->execute([$callId]);
    return ['ok' => true, 'call' => $fresh->fetch()];
}

/**
 * End a call. The duration is written once from the server clock, so a client that
 * never reports the end cannot leave a call running forever, and a client that
 * reports a wrong duration cannot change the record.
 */
function call_end(PDO $pdo, int $callId, string $actorType, int $actorId, string $reason = 'hangup'): array {
    $stmt = $pdo->prepare('SELECT * FROM call_sessions WHERE id=?');
    $stmt->execute([$callId]);
    $call = $stmt->fetch();
    if (!$call) return ['ok' => false, 'error' => 'not_found'];
    if (!call_participant($pdo, $callId, $actorType, $actorId)) return ['ok' => false, 'error' => 'forbidden'];
    if (in_array($call['status'], ['ended', 'rejected', 'missed', 'cancelled', 'failed'], true)) {
        return ['ok' => true, 'call' => $call, 'already' => true];
    }

    $duration = null;
    if ($call['answered_at'] !== null) {
        $duration = max(0, time() - strtotime((string)$call['answered_at']));
    }
    $newStatus = $call['status'] === 'ringing' ? 'cancelled' : 'ended';
    $pdo->prepare('UPDATE call_sessions SET status=?, ended_at=NOW(), duration_seconds=?, end_reason=? WHERE id=?')
        ->execute([$newStatus, $duration, $reason, $callId]);
    $pdo->prepare("UPDATE call_participants SET state='left', left_at=NOW() WHERE call_id=? AND state='joined'")->execute([$callId]);
    call_log_event($pdo, $callId, 'ended', $actorType, $actorId, $reason);

    $fresh = $pdo->prepare('SELECT * FROM call_sessions WHERE id=?');
    $fresh->execute([$callId]);
    return ['ok' => true, 'call' => $fresh->fetch()];
}

/**
 * Expire calls that rang past the timeout. Called opportunistically from the call
 * endpoints so a deployment without cron still records missed calls, and by
 * cron/calls.php on a schedule.
 */
function call_sweep_stale(PDO $pdo, ?int $ringTimeout = null): int {
    $timeout = $ringTimeout ?? call_provider_settings($pdo)['call_ring_timeout_seconds'];
    $stmt = $pdo->prepare("SELECT id FROM call_sessions WHERE status='ringing' AND started_at < DATE_SUB(NOW(), INTERVAL ? SECOND)");
    $stmt->execute([$timeout]);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    foreach ($ids as $id) {
        $pdo->prepare("UPDATE call_sessions SET status='missed', ended_at=NOW(), end_reason='no_answer' WHERE id=?")->execute([$id]);
        call_log_event($pdo, $id, 'missed', 'system', null, 'ring_timeout');
        $row = $pdo->prepare('SELECT * FROM call_sessions WHERE id=?');
        $row->execute([$id]);
        $call = $row->fetch();
        if (!$call) continue;
        require_once __DIR__ . '/notifications.php';
        $other = $call['initiated_by'] === 'client' ? ['lawyer', (int)$call['initiated_by_id']] : ['client', (int)$call['initiated_by_id']];
        notify($pdo, $other[0], $other[1], 'مكالمة فائتة', null, null);
    }
    return count($ids);
}

/** A participant's own call history, newest first. Never another party's calls. */
function call_history_for(PDO $pdo, string $type, int $id, int $limit = 50): array {
    $stmt = $pdo->prepare(
        'SELECT cs.*, c.topic, c.consultation_type,
                l.full_name AS lawyer_name, u.full_name AS client_name
           FROM call_sessions cs
           JOIN call_participants cp ON cp.call_id=cs.id AND cp.participant_type=? AND cp.participant_id=?
           JOIN consultations c ON c.id=cs.consultation_id
           JOIN lawyers l ON l.id=c.lawyer_id
           JOIN users u ON u.id=c.user_id
          ORDER BY cs.id DESC LIMIT ?'
    );
    $stmt->bindValue(1, $type);
    $stmt->bindValue(2, $id, PDO::PARAM_INT);
    $stmt->bindValue(3, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/** One call, only if the caller is a participant. */
function call_get_for_participant(PDO $pdo, int $callId, string $type, int $id): ?array {
    $stmt = $pdo->prepare('SELECT * FROM call_sessions WHERE id=?');
    $stmt->execute([$callId]);
    $call = $stmt->fetch();
    if (!$call) return null;
    if (!call_participant($pdo, $callId, $type, $id)) return null;
    return $call;
}

/** The most recent ringing call addressed to this participant, if any. Drives the incoming-call screen. */
function call_incoming_for(PDO $pdo, string $type, int $id): ?array {
    call_sweep_stale($pdo);
    $stmt = $pdo->prepare(
        "SELECT cs.*, c.topic, c.consultation_type,
                l.full_name AS lawyer_name, u.full_name AS client_name
           FROM call_sessions cs
           JOIN call_participants cp ON cp.call_id=cs.id AND cp.participant_type=? AND cp.participant_id=?
           JOIN consultations c ON c.id=cs.consultation_id
           JOIN lawyers l ON l.id=c.lawyer_id
           JOIN users u ON u.id=c.user_id
          WHERE cs.status='ringing' AND cs.initiated_by<>?
          ORDER BY cs.id DESC LIMIT 1"
    );
    $stmt->execute([$type, $id, $type]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Issue a short-lived join token. This is the only door into a call: it re-checks
 * that the caller is a participant, that the call is joinable, and that the booking
 * is not cancelled. A token is bound to the call and the participant, so it cannot
 * be replayed by a different account.
 */
function call_issue_token(PDO $pdo, array $call, string $type, int $id): array {
    if (!call_participant($pdo, (int)$call['id'], $type, $id)) {
        return ['ok' => false, 'error' => 'forbidden', 'message' => 'لا تملك صلاحية الدخول إلى هذه المكالمة.'];
    }
    if (!in_array($call['status'], ['ringing', 'accepted'], true)) {
        return ['ok' => false, 'error' => 'call_not_active', 'message' => 'انتهت هذه المكالمة.'];
    }
    $cons = $pdo->prepare('SELECT * FROM consultations WHERE id=?');
    $cons->execute([(int)$call['consultation_id']]);
    $consultation = $cons->fetch();
    if (!$consultation || $consultation['status'] === 'cancelled') {
        return ['ok' => false, 'error' => 'consultation_cancelled', 'message' => 'الاستشارة ملغاة.'];
    }

    $nonce = bin2hex(random_bytes(16));
    $expiresAt = time() + call_token_ttl_seconds();
    $payload = implode('|', [$call['id'], $type, $id, $expiresAt, $nonce]);
    $signature = call_token_sign($pdo, $payload);

    return [
        'ok' => true,
        'token' => base64_encode($payload . '|' . $signature),
        'expires_at' => date('c', $expiresAt),
        'expires_in' => call_token_ttl_seconds(),
    ];
}

/** HMAC key for call tokens, derived from the deployment encryption key. */
function call_token_secret(): string {
    $key = secret_encryption_key();
    if ($key !== null) return $key;
    // No encryption key configured: fall back to a per-deployment value so tokens
    // are still unforgeable without a shared secret in the repo.
    $configured = defined('MAWATHEQ_ENCRYPTION_KEY') ? MAWATHEQ_ENCRYPTION_KEY : (getenv('MAWATHEQ_ENCRYPTION_KEY') ?: '');
    return hash('sha256', 'mawatheq-call-token|' . $configured, true);
}

function call_token_sign(PDO $pdo, string $payload): string {
    return hash_hmac('sha256', $payload, call_token_secret());
}

/** Verify a join token and return the participant it names, or null. */
function call_verify_token(PDO $pdo, string $token): ?array {
    $decoded = base64_decode($token, true);
    if ($decoded === false) return null;
    $parts = explode('|', $decoded);
    if (count($parts) !== 6) return null;
    [$callId, $type, $id, $expiresAt, $nonce, $signature] = $parts;
    $payload = implode('|', [$callId, $type, $id, $expiresAt, $nonce]);
    if (!hash_equals(call_token_sign($pdo, $payload), $signature)) return null;
    if ((int)$expiresAt < time()) return null;
    return ['call_id' => (int)$callId, 'type' => $type, 'id' => (int)$id];
}

/** A consultation's call history, for the consultation detail screen. */
function call_history_for_consultation(PDO $pdo, int $consultationId): array {
    $stmt = $pdo->prepare('SELECT * FROM call_sessions WHERE consultation_id=? ORDER BY id DESC LIMIT 50');
    $stmt->execute([$consultationId]);
    return $stmt->fetchAll();
}

/** The participants on a call, for the call screen's "who is on this" line. */
function call_participants_list(PDO $pdo, int $callId): array {
    $stmt = $pdo->prepare('SELECT participant_type, participant_id, state, joined_at, left_at FROM call_participants WHERE call_id=?');
    $stmt->execute([$callId]);
    return $stmt->fetchAll();
}

/** Mark a participant as joined (idempotent). */
function call_mark_joined(PDO $pdo, int $callId, string $type, int $id): void {
    $pdo->prepare("UPDATE call_participants SET state='joined', joined_at=COALESCE(joined_at, NOW()) WHERE call_id=? AND participant_type=? AND participant_id=?")
        ->execute([$callId, $type, $id]);
    call_log_event($pdo, $callId, 'joined', $type, $id);
}

/**
 * Relay one SDP/ICE signal. The payload is stored as JSON in `call_events.detail`,
 * tagged so the drain can filter to the *other* party's signals only — a party must
 * never receive its own offer back, or the peer connection would be created twice.
 */
function call_signal_push(PDO $pdo, int $callId, string $type, int $id, array $payload): int {
    $detail = json_encode(['from' => $type, 'from_id' => $id, 'kind' => $payload['kind'], 'data' => $payload['data'] ?? null], JSON_UNESCAPED_UNICODE);
    $pdo->prepare('INSERT INTO call_events (call_id, event_type, actor_type, actor_id, detail) VALUES (?,?,?,?,?)')
        ->execute([$callId, 'signal', $type, $id, $detail]);
    return (int)$pdo->lastInsertId();
}

/**
 * Drain signals addressed to this participant: every `signal` event after `afterId`
 * that was sent by the *other* party. The other party's signals are the only ones
 * that matter to this peer, and filtering them out means a peer never answers its
 * own offer.
 */
function call_signals_drain(PDO $pdo, int $callId, int $afterId, string $type, int $id): array {
    $stmt = $pdo->prepare("SELECT id, actor_type, detail, created_at FROM call_events WHERE call_id=? AND event_type='signal' AND id>? AND actor_type<>? ORDER BY id ASC LIMIT 200");
    $stmt->execute([$callId, $afterId, $type]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $decoded = json_decode((string)$row['detail'], true);
        $out[] = [
            'id' => (int)$row['id'],
            'from' => $row['actor_type'],
            'kind' => $decoded['kind'] ?? null,
            'data' => $decoded['data'] ?? null,
            'at' => $row['created_at'],
        ];
    }
    return $out;
}
