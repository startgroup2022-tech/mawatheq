<?php
/**
 * ============================================================
 * مواثيق — تكامل Jitsi Meet للمكالمات الصوتية والمرئية
 * MAWATHEQ — Jitsi Meet integration (Execution 59)
 *
 * WHY AN EXTERNAL HOSTED SERVICE
 *
 *   The owner's brief is explicit: no Jitsi server, no coturn and no other media
 *   server is installed on the MAWATHEQ cPanel host. So the media path is an
 *   externally hosted Jitsi deployment, and this module is the only place that
 *   decides which one, what room, and what the client is allowed to see.
 *
 * ACCESS CONTROL — THE HONEST ANSWER
 *
 *   Two hosted shapes exist and they are NOT equally private:
 *
 *   * `meet.jit.si` (the free public service) does not enforce participant
 *     authentication. Anyone who knows a room name can join that room, and the
 *     service now asks for a signed-in moderator before a *new* room can be
 *     started. A "secret-looking" room name is obscurity, not access control, so
 *     this module never presents it as private: `jitsi_access_control()`
 *     reports `room_name_only`, and the consultation screen says so.
 *
 *   * Jitsi as a Service (8x8 JaaS) signs a per-participant RS256 JWT whose
 *     `room` claim is checked by the bridge. That *is* enforceable participant
 *     authentication, and it is what `jitsi_access_control()` reports as
 *     `jwt_verified`. It needs an AppID, an API key id and a private key, all
 *     entered in Admin → Settings, so the owner can switch to it without a code
 *     change.
 *
 *   Nothing here claims a room is private unless the deployment can enforce it.
 * ============================================================
 */
require_once __DIR__ . '/secret_crypto.php';

/** Settings keys this module reads. One list so a writer and a reader cannot drift. */
function jitsi_setting_keys(): array {
    return [
        'jitsi_domain',
        'jitsi_app_id',
        'jitsi_api_key_id',
        'jitsi_private_key',
        'jitsi_moderator_email',
    ];
}

/**
 * Read the Jitsi configuration. The private key is decrypted on read and
 * re-encrypted in place if an older writer stored it as plaintext.
 */
function jitsi_settings(PDO $pdo): array {
    $keys = jitsi_setting_keys();
    $in = implode(',', array_fill(0, count($keys), '?'));
    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ($in)");
    $stmt->execute($keys);
    $settings = [];
    foreach ($stmt->fetchAll() as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }

    if (isset($settings['jitsi_private_key'])) {
        $stored = (string)$settings['jitsi_private_key'];
        $settings['jitsi_private_key'] = secret_decrypt_value($stored);
        if ($settings['jitsi_private_key'] !== '' && strpos($stored, 'enc:v1:') !== 0 && secret_encryption_key()) {
            secret_store_setting($pdo, 'jitsi_private_key', $settings['jitsi_private_key']);
        }
    }

    $settings['jitsi_domain'] = $settings['jitsi_domain'] ?? 'meet.jit.si';
    $settings['jitsi_app_id'] = $settings['jitsi_app_id'] ?? '';
    $settings['jitsi_api_key_id'] = $settings['jitsi_api_key_id'] ?? '';
    $settings['jitsi_private_key'] = $settings['jitsi_private_key'] ?? '';
    $settings['jitsi_moderator_email'] = $settings['jitsi_moderator_email'] ?? '';
    return $settings;
}

/** The Jitsi host the app should join, without a scheme. */
function jitsi_domain(PDO $pdo): string {
    $domain = trim(jitsi_settings($pdo)['jitsi_domain']);
    $domain = preg_replace('~^https?://~i', '', $domain) ?? $domain;
    return rtrim($domain, '/');
}

/** True when the host is the paid service that can verify participants by JWT. */
function jitsi_is_jaas(PDO $pdo): bool {
    return str_contains(strtolower(jitsi_domain($pdo)), '8x8.vc');
}

/** True when an AppID, a key id and a usable private key are all present. */
function jitsi_jwt_configured(PDO $pdo): bool {
    $s = jitsi_settings($pdo);
    return $s['jitsi_app_id'] !== '' && $s['jitsi_api_key_id'] !== '' && $s['jitsi_private_key'] !== '';
}

/** Whether Jitsi can be used at all: a host is always set, so this mirrors `call_enabled`. */
function jitsi_configured(PDO $pdo): bool {
    return jitsi_domain($pdo) !== '';
}

/**
 * How strongly a room can be protected, so the UI never overstates privacy.
 *
 * @return array{level:string, enforceable:bool, message:string}
 */
function jitsi_access_control(PDO $pdo): array {
    if (jitsi_jwt_configured($pdo)) {
        return [
            'level' => 'jwt_verified',
            'enforceable' => true,
            'message' => 'الدخول محمي برمز موقّع لكل مشارك، ولا يمكن لأي طرف خارج الاستشارة الانضمام.',
        ];
    }
    return [
        'level' => 'room_name_only',
        'enforceable' => false,
        'message' => 'الغرفة محجوزة باسم عشوائي غير قابل للتخمين، لكن الخدمة المجانية لا تفرض '
            . 'التحقق من هوية المشارك. لتفعيل تحقق إلزامي، اربط حساب Jitsi as a Service (JaaS) '
            . 'من إعدادات الإدارة.',
    ];
}

/**
 * The room name for a consultation.
 *
 * Derived server-side from the consultation id and a deployment secret, so the two
 * parties land in the same room without either app inventing a name, and a client
 * cannot choose a room. This is NOT the access-control boundary — see
 * `jitsi_access_control()` — it only keeps rooms distinct and unguessable.
 *
 * The secret is the deployment encryption key. Changing it invalidates every room
 * name, which is harmless: a name is re-derived on every join.
 */
function jitsi_room_for_consultation(PDO $pdo, array $consultation): string {
    $id = (int)$consultation['id'];
    $secret = secret_encryption_key();
    if ($secret === null) {
        $secret = defined('MAWATHEQ_ENCRYPTION_KEY') ? MAWATHEQ_ENCRYPTION_KEY : (getenv('MAWATHEQ_ENCRYPTION_KEY') ?: 'mawatheq');
    }
    $digest = substr(hash_hmac('sha256', 'mawatheq-jitsi-room|' . $id, $secret), 0, 20);
    return 'MawatheqConsult' . $id . '-' . $digest;
}

/**
 * Encode a value as a base64url JWT segment.
 */
function jitsi_b64url(string $raw): string {
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

/**
 * Build a JaaS JWT for one participant.
 *
 * RS256, signed with the private key from settings. The `room` claim is what makes
 * this enforceable: the bridge refuses a token whose room does not match, so a
 * token issued for consultation 41 cannot be replayed into consultation 42.
 *
 * Returns null when JaaS is not configured — the caller then joins the free service
 * without a token rather than sending an unsigned one.
 */
function jitsi_jwt(PDO $pdo, string $room, array $user, bool $moderator): ?string {
    if (!jitsi_jwt_configured($pdo)) return null;
    $s = jitsi_settings($pdo);

    $privateKey = $s['jitsi_private_key'];
    $resource = @openssl_pkey_get_private($privateKey);
    if ($resource === false) {
        // A malformed key must fail loudly for the operator, not silently downgrade
        // to an unprotected room.
        error_log('MAWATHEQ jitsi: the configured JaaS private key could not be read');
        return null;
    }

    $now = time();
    $header = ['alg' => 'RS256', 'kid' => $s['jitsi_api_key_id'], 'typ' => 'JWT'];
    $payload = [
        'aud' => 'jitsi',
        'iss' => 'chat',
        'sub' => $s['jitsi_app_id'],
        'room' => $room,
        'nbf' => $now - 10,
        'exp' => $now + 3600,
        'context' => [
            'user' => [
                'id' => $user['type'] . '-' . (int)$user['id'],
                'name' => (string)$user['name'],
                'email' => (string)($user['email'] ?? ''),
                'moderator' => $moderator ? 'true' : 'false',
            ],
            'features' => [
                'livestreaming' => false,
                'recording' => false,
                'transcription' => false,
                'outbound-call' => false,
                'file-upload' => false,
            ],
            'room' => ['regex' => false],
        ],
    ];

    $signingInput = jitsi_b64url(json_encode($header, JSON_UNESCAPED_UNICODE))
        . '.' . jitsi_b64url(json_encode($payload, JSON_UNESCAPED_UNICODE));
    $signature = '';
    if (!openssl_sign($signingInput, $signature, $resource, OPENSSL_ALGO_SHA256)) {
        error_log('MAWATHEQ jitsi: signing the JaaS token failed');
        return null;
    }
    return $signingInput . '.' . jitsi_b64url($signature);
}

/**
 * The page the app opens to carry one participant's call.
 *
 * Jitsi runs in a WebView rather than a bundled native SDK: the native Jitsi SDK
 * pulls a second WebRTC build into the APK (`com.facebook.react:react-native-webrtc`)
 * that collides with the one `flutter_webrtc` already ships for the built-in
 * peer-to-peer screen — the same `org.webrtc` classes and the same
 * `libjingle_peerconnection_so.so` — and Gradle refuses to merge them. The hosted
 * web client needs no native dependency at all, so both call paths coexist in one
 * APK, and the access control is unchanged because the JWT is still issued and
 * signed here.
 *
 * JaaS serves every tenant under `8x8.vc/<appId>/<room>`, so the app id is part of
 * the path there; on a self-hosted or meet.jit.si deployment the room is the whole
 * path. The token rides as a query parameter, which is the shape the web client
 * reads it from.
 */
function jitsi_join_url(PDO $pdo, string $room, ?string $jwt): string {
    $s = jitsi_settings($pdo);
    $domain = jitsi_domain($pdo);
    if ($domain === '') return '';

    $appId = trim((string)($s['jitsi_app_id'] ?? ''));
    $isJaas = $appId !== '' && str_contains($domain, '8x8.vc');
    $path = $isJaas ? $appId . '/' . $room : $room;

    $url = 'https://' . $domain . '/' . $path;
    if ($jwt !== null && $jwt !== '') {
        $url .= '?jwt=' . rawurlencode($jwt);
    }
    return $url;
}

/**
 * Everything the mobile app needs to open a branded Jitsi call for one participant.
 *
 * The lawyer is the moderator: they host the consultation, and on a host that
 * enforces authentication that is what lets them admit or remove a participant.
 *
 * @return array{provider:string, domain:string, app_id:string, room:string, jwt:?string,
 *               join_url:string, subject:string, display_name:string, moderator:bool,
 *               language:string, access:array}
 */
function jitsi_join_payload(PDO $pdo, array $consultation, string $type, int $id, array $user): array {
    $room = jitsi_room_for_consultation($pdo, $consultation);
    $moderator = ($type === 'lawyer');

    $topic = trim((string)($consultation['topic'] ?? ''));
    $subject = 'مواثيق — استشارة قانونية';
    if ($topic !== '') {
        // The subject is shown to both parties, so it carries only the consultation's
        // own topic — never a name, an email or any other account detail.
        $subject .= ': ' . mb_substr($topic, 0, 60, 'UTF-8');
    }

    $jwt = jitsi_jwt($pdo, $room, $user + ['type' => $type, 'id' => $id], $moderator);
    $settings = jitsi_settings($pdo);

    return [
        'provider' => 'jitsi',
        'domain' => jitsi_domain($pdo),
        // Not a secret: the app id is already part of the public JaaS URL. The app
        // needs it to build the External API path for a JaaS tenant.
        'app_id' => trim((string)($settings['jitsi_app_id'] ?? '')),
        'room' => $room,
        'jwt' => $jwt,
        'join_url' => jitsi_join_url($pdo, $room, $jwt),
        'subject' => $subject,
        'display_name' => (string)($user['name'] ?? ''),
        'moderator' => $moderator,
        'language' => 'ar',
        'access' => jitsi_access_control($pdo),
    ];
}
