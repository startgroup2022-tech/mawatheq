<?php
/**
 * Execution 59 — lawyer sign-in reason codes, and the Jitsi call room.
 *
 * Two defects are pinned here, both over real HTTP against the running app:
 *
 * 1. A lawyer whose password is correct but whose account is not approved was told
 *    "session expired". The endpoint answered the same generic 401 for a wrong
 *    password and for every account state, so the app could not tell them apart.
 *    It now returns stable machine codes, and the test asserts each one — the code,
 *    not the prose, is the contract the app maps.
 *
 * 2. Jitsi was reachable but the app had no room to join. `start` and `token` now
 *    return a `meeting` block, and the two participants of one call must receive the
 *    *same* room with the correct moderator flag, while a non-participant must
 *    receive nothing at all.
 *
 * Requires the app running on 127.0.0.1:8080. Run: php tests/exec59_lawyer_login_calls_probe.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/jitsi.php';

$base = 'http://127.0.0.1:8080';
$failures = 0;
$checks = 0;
function check(string $label, bool $ok): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) { $failures++; echo "  FAIL: $label\n"; } else { echo "  ok:   $label\n"; }
}

/** POST JSON, optionally bearer-authenticated. Returns [code, decoded, raw]. */
function postJson(string $path, array $body, ?string $token = null): array {
    global $base;
    $headers = ['Content-Type: application/json'];
    if ($token !== null) $headers[] = "Authorization: Bearer $token";
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 20,
    ]);
    $raw = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode($raw, true), $raw];
}

function getJson(string $path, ?string $token = null): array {
    global $base;
    $headers = [];
    if ($token !== null) $headers[] = "Authorization: Bearer $token";
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 20,
    ]);
    $raw = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode($raw, true), $raw];
}

/** Decode a JWS payload segment without verifying it (verification is asserted separately). */
function jwtPayload(string $jwt): array {
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return [];
    $b64 = strtr($parts[1], '-_', '+/');
    $b64 .= str_repeat('=', (4 - strlen($b64) % 4) % 4);
    return json_decode((string)base64_decode($b64), true) ?: [];
}

$suffix = bin2hex(random_bytes(4));
$pass = 'Exec59-Test-Pass-2026';
$created = ['lawyers' => [], 'users' => [], 'consultations' => [], 'site_settings' => []];

/** Snapshot a setting so the probe restores what it found. */
$settingBefore = [];
foreach (['call_enabled','call_provider','jitsi_domain','jitsi_app_id','jitsi_api_key_id'] as $k) {
    $row = $pdo->prepare('SELECT setting_value FROM site_settings WHERE setting_key = ?');
    $row->execute([$k]);
    $v = $row->fetchColumn();
    $settingBefore[$k] = ($v === false) ? null : $v;
    $pdo->prepare('INSERT INTO site_settings (setting_key, setting_value) VALUES (?,?)
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
        ->execute([$k, ['call_enabled'=>'1','call_provider'=>'jitsi','jitsi_domain'=>'meet.jit.si',
                        'jitsi_app_id'=>'','jitsi_api_key_id'=>''][$k]]);
}

// Repeated runs consume the sign-in rate-limit buckets; clear them so a second run
// in the same window measures the code and not the throttle.
$pdo->exec('DELETE FROM auth_rate_limits');

echo "== Part A: sign-in reason codes ==\n";

$mkLawyer = function (string $label, string $status, int $verified) use ($pdo, $suffix, $pass, &$created): int {
    $pdo->prepare('INSERT INTO lawyers (full_name, email, password_hash, status, email_verified) VALUES (?,?,?,?,?)')
        ->execute(["محامي $label", "e59-$label-$suffix@example.test", password_hash($pass, PASSWORD_BCRYPT), $status, $verified]);
    $id = (int)$pdo->lastInsertId();
    $created['lawyers'][] = $id;
    return $id;
};

$approved = $mkLawyer('approved', 'approved', 1);
$pending  = $mkLawyer('pending',  'pending',  1);
$unverif  = $mkLawyer('unverified','approved', 0);
$suspend  = $mkLawyer('suspended','suspended', 1);

// Wrong password -> invalid_credentials, 401.
[$c, $j] = postJson('/api/v1/auth/lawyer-login.php',
    ['email' => "e59-approved-$suffix@example.test", 'password' => 'definitely-wrong']);
check('wrong password returns 401', $c === 401);
check('wrong password code is invalid_credentials', ($j['error'] ?? '') === 'invalid_credentials');

// Unknown email must be indistinguishable from a wrong password.
[$c, $j] = postJson('/api/v1/auth/lawyer-login.php',
    ['email' => "nobody-$suffix@example.test", 'password' => 'whatever']);
check('unknown email returns the same 401', $c === 401);
check('unknown email returns the same code (no account enumeration)', ($j['error'] ?? '') === 'invalid_credentials');

// Correct password, unverified email.
[$c, $j] = postJson('/api/v1/auth/lawyer-login.php',
    ['email' => "e59-unverified-$suffix@example.test", 'password' => $pass]);
check('unverified account returns 403', $c === 403);
check('unverified account code is email_not_verified', ($j['error'] ?? '') === 'email_not_verified');

// Correct password, verified but not approved.
[$c, $j] = postJson('/api/v1/auth/lawyer-login.php',
    ['email' => "e59-pending-$suffix@example.test", 'password' => $pass]);
check('pending account returns 403', $c === 403);
check('pending account code is pending_approval', ($j['error'] ?? '') === 'pending_approval');

// Correct password, suspended.
[$c, $j] = postJson('/api/v1/auth/lawyer-login.php',
    ['email' => "e59-suspended-$suffix@example.test", 'password' => $pass]);
check('suspended account returns 403', $c === 403);
check('suspended account code is account_suspended', ($j['error'] ?? '') === 'account_suspended');

// Correct password, approved -> a real token.
[$c, $j] = postJson('/api/v1/auth/lawyer-login.php',
    ['email' => "e59-approved-$suffix@example.test", 'password' => $pass]);
check('approved account signs in', $c === 200 && !empty($j['token']));
$lawyerToken = (string)($j['token'] ?? '');

// The client endpoint must speak the same codes, since the app maps them the same way.
[$c, $j] = postJson('/api/v1/auth/login.php',
    ['email' => "nobody-$suffix@example.test", 'password' => 'x']);
check('client login uses the same invalid_credentials code', ($j['error'] ?? '') === 'invalid_credentials');

echo "\n== Part B: the Jitsi room ==\n";

// A client participant.
$pdo->prepare('INSERT INTO users (full_name, email, password_hash, status, email_verified, account_type) VALUES (?,?,?,?,?,?)')
    ->execute(['عميل ٥٩', "e59-client-$suffix@example.test", password_hash($pass, PASSWORD_BCRYPT), 'active', 1, 'individual']);
$clientId = (int)$pdo->lastInsertId();
$created['users'][] = $clientId;
[$c, $j] = postJson('/api/v1/auth/login.php', ['email' => "e59-client-$suffix@example.test", 'password' => $pass]);
$clientToken = (string)($j['token'] ?? '');
check('client participant signs in', $c === 200 && $clientToken !== '');

// A third party who is not on the consultation.
$pdo->prepare('INSERT INTO users (full_name, email, password_hash, status, email_verified, account_type) VALUES (?,?,?,?,?,?)')
    ->execute(['متطفل ٥٩', "e59-outsider-$suffix@example.test", password_hash($pass, PASSWORD_BCRYPT), 'active', 1, 'individual']);
$outsiderId = (int)$pdo->lastInsertId();
$created['users'][] = $outsiderId;
[$c, $j] = postJson('/api/v1/auth/login.php', ['email' => "e59-outsider-$suffix@example.test", 'password' => $pass]);
$outsiderToken = (string)($j['token'] ?? '');
check('outsider signs in', $c === 200 && $outsiderToken !== '');

$pdo->prepare('INSERT INTO consultations (user_id, lawyer_id, consultation_type, status, topic, scheduled_at) VALUES (?,?,?,?,?,NOW())')
    ->execute([$clientId, $approved, 'video', 'confirmed', "استشارة exec59 $suffix"]);
$consultationId = (int)$pdo->lastInsertId();
$created['consultations'][] = $consultationId;

// Calls must be reachable for the room to exist at all.
[$c, $st] = getJson("/api/v1/calls.php?action=state&consultation_id=$consultationId", $lawyerToken);
check('call state is available with Jitsi configured', ($st['available'] ?? false) === true);

// The lawyer starts the call.
[$c, $start] = postJson('/api/v1/calls.php',
    ['action' => 'start', 'consultation_id' => $consultationId, 'call_type' => 'video'], $lawyerToken);
check('lawyer can start the call', ($c === 200 || $c === 201) && !empty($start['call']['id']));
$callId = (int)($start['call']['id'] ?? 0);
$lawyerRoom = (string)($start['meeting']['room'] ?? '');
check('start returns a meeting room', $lawyerRoom !== '');
check('start room is scoped to the consultation', str_contains($lawyerRoom, (string)$consultationId));
check('the initiating lawyer is the moderator', ($start['meeting']['moderator'] ?? false) === true);
check('the meeting names the domain', ($start['meeting']['domain'] ?? '') === 'meet.jit.si');
check('the free host reports it cannot enforce identity', ($start['meeting']['access']['enforceable'] ?? true) === false);

// The app opens the meeting in a WebView through Jitsi's External API, so the
// payload must carry the page to load. A missing or malformed join_url is a blank
// call rather than an error, which is why it is asserted here.
$joinUrl = (string)($start['meeting']['join_url'] ?? '');
check('the meeting carries a join URL for the app', $joinUrl !== '');
check('the join URL is https', str_starts_with($joinUrl, 'https://'));
check('the join URL points at the meeting domain', str_contains($joinUrl, '//meet.jit.si/'));
check('the join URL carries the room', str_contains($joinUrl, $lawyerRoom));
check('a host without a signed token sends no jwt parameter', !str_contains($joinUrl, 'jwt='));

// The client joins: same room, not a moderator.
[$c, $tok] = getJson("/api/v1/calls.php?action=token&call_id=$callId", $clientToken);
check('client can fetch the call token', $c === 200);
check('the client joins the SAME room', ($tok['meeting']['room'] ?? '') === $lawyerRoom);
check('the client is not a moderator', ($tok['meeting']['moderator'] ?? true) === false);
check('the client sees their own display name', ($tok['meeting']['display_name'] ?? '') === 'عميل ٥٩');

// A third party must not be handed the room.
[$c, $bad, $raw] = getJson("/api/v1/calls.php?action=token&call_id=$callId", $outsiderToken);
check('an outsider is refused', $c === 404 || $c === 403);
check('an outsider is told nothing about the room', $lawyerRoom !== '' && !str_contains($raw, $lawyerRoom));

// Without a token at all.
[$c, , $raw] = getJson("/api/v1/calls.php?action=token&call_id=$callId");
check('an unauthenticated request is refused', $c === 401 || $c === 403);

echo "\n== Part C: JaaS signing, when a key is present ==\n";

$keyBefore = jitsi_settings($pdo)['jitsi_private_key'] ?? '';
$res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($res, $pem);
secret_store_setting($pdo, 'jitsi_private_key', $pem);
$pdo->prepare("INSERT INTO site_settings (setting_key,setting_value) VALUES ('jitsi_domain','8x8.vc')
               ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute();
$pdo->prepare("INSERT INTO site_settings (setting_key,setting_value) VALUES ('jitsi_app_id','vpaas-magic-cookie-e59')
               ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute();
$pdo->prepare("INSERT INTO site_settings (setting_key,setting_value) VALUES ('jitsi_api_key_id','vpaas-magic-cookie-e59/kid')
               ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute();

$ac = jitsi_access_control($pdo);
check('a configured key makes identity enforceable', ($ac['enforceable'] ?? false) === true);
check('access level reports jwt_verified', ($ac['level'] ?? '') === 'jwt_verified');

$jwt = jitsi_jwt($pdo, $lawyerRoom, ['type' => 'lawyer', 'id' => $approved, 'name' => 'م', 'email' => 'a@b.test'], true);
$parts = explode('.', (string)$jwt);
check('the token is a three-part JWS', count($parts) === 3);
$payload = jwtPayload((string)$jwt);
check('the token is scoped to the room', ($payload['room'] ?? '') === $lawyerRoom);
check('the token audience is the constant Jitsi expects', ($payload['aud'] ?? '') === 'jitsi');
check('the token subject is the JaaS app id', ($payload['sub'] ?? '') === 'vpaas-magic-cookie-e59');
check('the token is issued by the chat issuer', ($payload['iss'] ?? '') === 'chat');
// Jitsi's mod_token_affiliation.lua grants owner for moderator == true *or* "true".
check('the lawyer is marked a moderator in the token', ($payload['context']['user']['moderator'] ?? false) === 'true');
check('recording is refused by the token features', ($payload['context']['features']['recording'] ?? true) === false);

$pub = openssl_pkey_get_details($res)['key'];
$sig = base64_decode(strtr($parts[2], '-_', '+/'));
check('the signature verifies against the key', openssl_verify($parts[0].'.'.$parts[1], $sig, $pub, OPENSSL_ALGO_SHA256) === 1);
$tampered = $parts[0].'.'.rtrim(strtr(base64_encode('{"room":"other"}'), '+/', '-_'), '=').'.'.$parts[2];
check('a tampered payload is rejected', openssl_verify($tampered, $sig, $pub, OPENSSL_ALGO_SHA256) !== 1);

// With the key present, a real call now hands the participant a signed token.
// A second consultation is used because `call_start` correctly reuses the live call
// on the first one, and a reused call keeps its original type.
$pdo->prepare('INSERT INTO consultations (user_id, lawyer_id, consultation_type, status, topic, scheduled_at) VALUES (?,?,?,?,?,NOW())')
    ->execute([$clientId, $approved, 'voice', 'confirmed', "استشارة صوتية exec59 $suffix"]);
$audioConsultationId = (int)$pdo->lastInsertId();
$created['consultations'][] = $audioConsultationId;
[$c, $start2] = postJson('/api/v1/calls.php',
    ['action' => 'start', 'consultation_id' => $audioConsultationId, 'call_type' => 'voice'], $lawyerToken);
$signedRoom = (string)($start2['meeting']['room'] ?? '');
check('a JaaS deployment issues a signed join token', !empty($start2['meeting']['jwt']));
check('the issued token is scoped to the issued room', (jwtPayload((string)$start2['meeting']['jwt'])['room'] ?? '') === $signedRoom);
check('a voice call is recorded as a voice call', ($start2['meeting']['call_type'] ?? '') === 'voice');
check('a JaaS deployment reports identity is enforced', ($start2['meeting']['access']['enforceable'] ?? false) === true);

// JaaS serves every tenant under 8x8.vc/<appId>/<room>, and the app builds the
// External API path from these two values. Getting either wrong is a blank call.
check('a JaaS deployment reports its app id to the app', ($start2['meeting']['app_id'] ?? '') === 'vpaas-magic-cookie-e59');
$jaasUrl = (string)($start2['meeting']['join_url'] ?? '');
check('the JaaS join URL is under the app id path', str_contains($jaasUrl, '/vpaas-magic-cookie-e59/'));
check('the JaaS join URL carries the signed token', str_contains($jaasUrl, 'jwt='));
check('the JaaS join URL carries the room', str_contains($jaasUrl, $signedRoom));

echo "\n== cleanup ==\n";
foreach ($created['consultations'] as $cid) {
    $ids = $pdo->prepare('SELECT id FROM call_sessions WHERE consultation_id = ?');
    $ids->execute([$cid]);
    foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $sid) {
        $pdo->prepare('DELETE FROM call_events WHERE call_id = ?')->execute([$sid]);
        $pdo->prepare('DELETE FROM call_participants WHERE call_id = ?')->execute([$sid]);
    }
    $pdo->prepare('DELETE FROM call_sessions WHERE consultation_id = ?')->execute([$cid]);
    $pdo->prepare('DELETE FROM consultations WHERE id = ?')->execute([$cid]);
}
foreach ($created['users'] as $id) $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
foreach ($created['lawyers'] as $id) $pdo->prepare('DELETE FROM lawyers WHERE id = ?')->execute([$id]);
$pdo->prepare('DELETE FROM api_tokens WHERE user_id IN (?,?)')->execute([$clientId, $outsiderId]);
foreach ($settingBefore as $k => $v) {
    if ($v === null) $pdo->prepare('DELETE FROM site_settings WHERE setting_key = ?')->execute([$k]);
    else $pdo->prepare('UPDATE site_settings SET setting_value = ? WHERE setting_key = ?')->execute([$v, $k]);
}
secret_store_setting($pdo, 'jitsi_private_key', $keyBefore);
check('cleanup removed the probe consultation', (int)$pdo->query("SELECT COUNT(*) FROM consultations WHERE id = $consultationId")->fetchColumn() === 0);

echo "\n$checks checks, $failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
