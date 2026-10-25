<?php
/**
 * ============================================================
 * /api/v1/calls.php — إشارات المكالمات الصوتية والمرئية داخل التطبيق
 * MAWATHEQ — in-app call signalling (Execution 57)
 *
 * Every action is authorised against the call's participant list, not against the
 * consultation alone, so a party whose booking changed cannot keep a live token.
 *
 * GET  ?action=state&consultation_id=N   → is a call possible, and the ICE servers
 * GET  ?action=history                   → my call log (client or lawyer)
 * GET  ?action=incoming                  → a ringing call addressed to me, if any
 * GET  ?action=get&call_id=N             → one call I am a party to
 * GET  ?action=token&call_id=N           → a short-lived join token
 * POST action=start   { consultation_id, call_type: voice|video }
 * POST action=accept  { call_id }
 * POST action=reject  { call_id }
 * POST action=end     { call_id, reason? }
 * POST action=joined  { call_id, token }   → mark my participant row joined
 * POST action=signal  { call_id, token, payload } → relay SDP/ICE to the other party
 * GET  ?action=poll&call_id=N&token=...&after_id=N → drain the other party's signals
 *
 * SIGNALLING
 *
 *   SDP offers/answers and ICE candidates are relayed through `call_events`, which
 *   already exists and is participant-scoped. Each signal is one row; a client polls
 *   with the last id it saw. This keeps the media path peer-to-peer (no server-side
 *   media) while giving the two apps a way to find each other without a separate
 *   WebSocket service to deploy.
 * ============================================================
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/calls.php';
require_once __DIR__ . '/../../includes/notifications.php';

$auth = api_authenticate($pdo);
if ($auth['type'] === 'integration') api_error('not available to integration tokens', 403);

$type = $auth['type'];
$id = (int)$auth['id'];

function call_payload(): array {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    return is_array($json) ? $json : $_POST;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = (string)($_GET['action'] ?? ($method === 'POST' ? (call_payload()['action'] ?? '') : ''));

if ($method === 'GET') {
    switch ($action) {
        case 'state':
            $consultationId = (int)($_GET['consultation_id'] ?? 0);
            $consultation = call_consultation_for_party($pdo, $consultationId, $type, $id);
            if (!$consultation) api_error('consultation not found', 404);
            $state = call_provider_state($pdo);
            api_json([
                'ok' => true,
                'available' => $state['available'],
                'blocking' => $state['blocking'],
                'message' => $state['message'],
                'turn' => $state['turn'],
                'ice_servers' => call_ice_servers($pdo),
                'consultation' => [
                    'id' => (int)$consultation['id'],
                    'type' => $consultation['consultation_type'],
                    'status' => $consultation['status'],
                    'scheduled_at' => $consultation['scheduled_at'],
                ],
            ]);
            // no break — api_json exits

        case 'history':
            api_json(['ok' => true, 'calls' => call_history_for($pdo, $type, $id)]);
            // no break

        case 'incoming':
            $incoming = call_incoming_for($pdo, $type, $id);
            api_json(['ok' => true, 'call' => $incoming]);
            // no break

        case 'get':
            $callId = (int)($_GET['call_id'] ?? 0);
            $call = call_get_for_participant($pdo, $callId, $type, $id);
            if (!$call) api_error('call not found', 404);
            api_json(['ok' => true, 'call' => $call, 'participants' => call_participants_list($pdo, $callId)]);
            // no break

        case 'token':
            $callId = (int)($_GET['call_id'] ?? 0);
            $call = call_get_for_participant($pdo, $callId, $type, $id);
            if (!$call) api_error('call not found', 404);
            $issued = call_issue_token($pdo, $call, $type, $id);
            if (!$issued['ok']) api_error($issued['message'] ?? $issued['error'], 403);
            api_json([
                'ok' => true,
                'token' => $issued['token'],
                'expires_at' => $issued['expires_at'],
                'expires_in' => $issued['expires_in'],
                'room_code' => $call['room_code'],
                'call_type' => $call['call_type'],
                'ice_servers' => call_ice_servers($pdo),
                'call' => $call,
            ]);
            // no break

        case 'poll':
            $callId = (int)($_GET['call_id'] ?? 0);
            $token = (string)($_GET['token'] ?? '');
            $verified = call_verify_token($pdo, $token);
            if (!$verified || $verified['call_id'] !== $callId || $verified['type'] !== $type || $verified['id'] !== $id) {
                api_error('invalid or expired call token', 403);
            }
            $afterId = (int)($_GET['after_id'] ?? 0);
            api_json(['ok' => true, 'signals' => call_signals_drain($pdo, $callId, $afterId, $type, $id)]);
            // no break

        default:
            api_error('unknown action', 400);
    }
}

if ($method === 'POST') {
    $in = call_payload();
    switch ($action) {
        case 'start': {
            $consultationId = (int)($in['consultation_id'] ?? 0);
            $consultation = call_consultation_for_party($pdo, $consultationId, $type, $id);
            if (!$consultation) api_error('consultation not found', 404);
            $callType = (string)($in['call_type'] ?? 'video');
            $result = call_start($pdo, $consultation, $type, $id, $callType);
            if (!$result['ok']) api_error($result['message'] ?? $result['error'], 409);
            $issued = call_issue_token($pdo, $result['call'], $type, $id);
            api_json([
                'ok' => true,
                'call' => $result['call'],
                'reused' => $result['reused'] ?? false,
                'token' => $issued['token'] ?? null,
                'ice_servers' => call_ice_servers($pdo),
            ], $result['reused'] ?? false ? 200 : 201);
        }

        case 'accept': {
            $callId = (int)($in['call_id'] ?? 0);
            $r = call_accept($pdo, $callId, $type, $id);
            if (!$r['ok']) api_error($r['error'], $r['error'] === 'forbidden' ? 403 : 409);
            api_json(['ok' => true, 'call' => $r['call']]);
        }

        case 'reject': {
            $callId = (int)($in['call_id'] ?? 0);
            $r = call_reject($pdo, $callId, $type, $id);
            if (!$r['ok']) api_error($r['error'], $r['error'] === 'forbidden' ? 403 : 409);
            api_json(['ok' => true, 'call' => $r['call']]);
        }

        case 'end': {
            $callId = (int)($in['call_id'] ?? 0);
            $reason = (string)($in['reason'] ?? 'hangup');
            $r = call_end($pdo, $callId, $type, $id, $reason);
            if (!$r['ok']) api_error($r['error'], $r['error'] === 'forbidden' ? 403 : 409);
            api_json(['ok' => true, 'call' => $r['call']]);
        }

        case 'joined': {
            $callId = (int)($in['call_id'] ?? 0);
            $token = (string)($in['token'] ?? '');
            $verified = call_verify_token($pdo, $token);
            if (!$verified || $verified['call_id'] !== $callId || $verified['type'] !== $type || $verified['id'] !== $id) {
                api_error('invalid or expired call token', 403);
            }
            call_mark_joined($pdo, $callId, $type, $id);
            api_json(['ok' => true]);
        }

        case 'signal': {
            $callId = (int)($in['call_id'] ?? 0);
            $token = (string)($in['token'] ?? '');
            $payload = $in['payload'] ?? null;
            $verified = call_verify_token($pdo, $token);
            if (!$verified || $verified['call_id'] !== $callId || $verified['type'] !== $type || $verified['id'] !== $id) {
                api_error('invalid or expired call token', 403);
            }
            if (!is_array($payload) || !isset($payload['kind'])) api_error('payload.kind is required');
            if (!in_array($payload['kind'], ['offer', 'answer', 'ice', 'bye', 'renegotiate'], true)) {
                api_error('unsupported signal kind');
            }
            $stored = call_signal_push($pdo, $callId, $type, $id, $payload);
            api_json(['ok' => true, 'signal_id' => $stored], 201);
        }

        default:
            api_error('unknown action', 400);
    }
}

api_error('method not allowed', 405);
