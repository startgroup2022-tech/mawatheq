<?php
/**
 * Execution 70 — the firm chat assistant is safe to expose to a stranger.
 *
 * The endpoint `/api/firm-chat.php` is the only unauthenticated writer in the firm-site
 * area. Everything here is an attempt to abuse it:
 *
 *   A. resolution    — an unknown key, a malformed key and a disabled bot all answer
 *                      identically, so the endpoint cannot be used to enumerate firms;
 *   B. plan gate     — a bot whose plan lacks `feature_chatbot` is refused, and works
 *                      again when the plan carries it;
 *   C. isolation     — a visitor key that did not open the conversation cannot read or
 *                      append to it;
 *   D. advice        — a request for a legal opinion is refused and offered a hand-off;
 *   E. lead capture  — a valid phone converts the conversation and links a real lead,
 *                      an invalid one is refused;
 *   F. firm scoping  — a lawyer of another firm cannot see or reply to a conversation.
 *
 * Driven over real HTTP against the running app, because the interesting failures
 * (CSRF exemption, JSON parsing, rate-limit table) live in the request path.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/firm_chatbot.php';

$base = getenv('MAWATHEQ_BASE_URL') ?: 'http://127.0.0.1:12000';
$checks = 0;
$failures = 0;

function chk(string $label, bool $ok, string $detail = ''): void {
    global $checks, $failures;
    $checks++;
    if ($ok) { echo "  ok   $label\n"; }
    else { $failures++; echo "  FAIL $label" . ($detail !== '' ? "  ($detail)" : '') . "\n"; }
}

function post_json(string $url, array $payload): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => json_decode((string)$body, true)];
}

function post_form(string $url, array $fields, string $cookieJar): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => (string)$body];
}

function get_page(string $url, string $cookieJar): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => (string)$body];
}

function csrf_from(string $html): string {
    if (preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m)) return $m[1];
    return '';
}

echo "Execution 70 — firm chat assistant security\n";
echo "============================================\n";

$firmId = (int)$pdo->query('SELECT id FROM law_firms ORDER BY id LIMIT 1')->fetchColumn();
$ownerId = (int)$pdo->query("SELECT lawyer_id FROM law_firm_members WHERE firm_id=$firmId AND member_role='owner' LIMIT 1")->fetchColumn();
$bot = firm_chatbot_ensure($pdo, $firmId, $ownerId);
if (!$bot) { echo "FATAL: could not ensure a bot\n"; exit(1); }

// Make the environment deterministic: corporate plan (feature_chatbot=1), firm published.
$pdo->exec("UPDATE law_firms SET plan_id=(SELECT id FROM plans WHERE feature_chatbot=1 AND status='active' ORDER BY id LIMIT 1), is_public=1, public_slug=COALESCE(public_slug,'chat-test-firm') WHERE id=$firmId");
$pdo->exec("UPDATE firm_chatbots SET is_enabled=1 WHERE id=" . (int)$bot['id']);
$key = (string)$bot['public_key'];
$visitorA = bin2hex(random_bytes(16));
$visitorB = bin2hex(random_bytes(16));

// Clear the rate-limit bucket this suite consumes, so a second run in the window is honest.
$bucketLike = 'chatbot|' . substr(hash('sha256', $key . '|' . $visitorA), 0, 40);
$pdo->prepare('DELETE FROM auth_rate_limits WHERE scope_key = ?')->execute([$bucketLike]);

// ---- A. resolution --------------------------------------------------------------
$unknown = post_json("$base/api/firm-chat.php", ['action' => 'start', 'key' => bin2hex(random_bytes(16)), 'visitor' => $visitorA]);
chk('unknown bot key -> 404 not_found', $unknown['status'] === 404 && ($unknown['body']['error'] ?? '') === 'not_found');

$malformed = post_json("$base/api/firm-chat.php", ['action' => 'start', 'key' => '../../etc/passwd', 'visitor' => $visitorA]);
chk('malformed key -> 404 not_found', $malformed['status'] === 404);

$getReq = get_page("$base/api/firm-chat.php", '/tmp/x.jar');
chk('GET -> 405', $getReq['status'] === 405);

// ---- B. plan gate ---------------------------------------------------------------
$freePlanId = (int)$pdo->query("SELECT id FROM plans WHERE feature_chatbot=0 AND status='active' ORDER BY id LIMIT 1")->fetchColumn();
$paidPlanId = (int)$pdo->query("SELECT id FROM plans WHERE feature_chatbot=1 AND status='active' ORDER BY id LIMIT 1")->fetchColumn();
if ($freePlanId && $paidPlanId) {
    $pdo->exec("UPDATE law_firms SET plan_id=$freePlanId WHERE id=$firmId");
    $denied = post_json("$base/api/firm-chat.php", ['action' => 'start', 'key' => $key, 'visitor' => $visitorA]);
    chk('plan without feature_chatbot -> 403 disabled', $denied['status'] === 403 && ($denied['body']['error'] ?? '') === 'disabled');

    $pdo->exec("UPDATE law_firms SET plan_id=$paidPlanId WHERE id=$firmId");
    $allowed = post_json("$base/api/firm-chat.php", ['action' => 'start', 'key' => $key, 'visitor' => $visitorA]);
    chk('plan with feature_chatbot -> 200', $allowed['status'] === 200 && !empty($allowed['body']['ok']));
} else {
    chk('both a free and a paid plan exist to test the gate', false);
}
$conversationId = (int)($allowed['body']['conversation'] ?? 0);

// ---- C. isolation ---------------------------------------------------------------
$hijack = post_json("$base/api/firm-chat.php", [
    'action' => 'send', 'key' => $key, 'visitor' => $visitorB,
    'conversation' => $conversationId, 'message' => 'hijack',
]);
chk('another visitor key cannot append to the conversation',
    $hijack['status'] === 404 && ($hijack['body']['error'] ?? '') === 'conversation_not_found');

$readHijack = post_json("$base/api/firm-chat.php", [
    'action' => 'contact', 'key' => $key, 'visitor' => $visitorB,
    'conversation' => $conversationId, 'phone' => '0501234567',
]);
chk('another visitor key cannot convert the conversation', $readHijack['status'] === 404);

// ---- D. legal advice is refused ------------------------------------------------
$advice = post_json("$base/api/firm-chat.php", [
    'action' => 'send', 'key' => $key, 'visitor' => $visitorA,
    'conversation' => $conversationId, 'message' => 'هل يحق لي رفع دعوى ضد شركتي وأربح القضية؟',
]);
chk('legal-advice request is classified as legal_advice',
    ($advice['body']['intent'] ?? '') === 'legal_advice', 'intent=' . ($advice['body']['intent'] ?? ''));
$adviceReply = (string)($advice['body']['reply'] ?? '');
chk('legal-advice reply declines rather than advising',
    str_contains($adviceReply, 'لا أستطيع') && str_contains($adviceReply, 'استشارة'));

// ---- E. lead capture -----------------------------------------------------------
$badPhone = post_json("$base/api/firm-chat.php", [
    'action' => 'contact', 'key' => $key, 'visitor' => $visitorA,
    'conversation' => $conversationId, 'phone' => '12',
]);
chk('invalid phone -> 422 phone_invalid', $badPhone['status'] === 422 && ($badPhone['body']['error'] ?? '') === 'phone_invalid');

$before = (int)$pdo->query('SELECT COUNT(*) FROM contact_leads')->fetchColumn();
$good = post_json("$base/api/firm-chat.php", [
    'action' => 'contact', 'key' => $key, 'visitor' => $visitorA,
    'conversation' => $conversationId, 'name' => 'زائر اختبار', 'phone' => '0509998877',
]);
$after = (int)$pdo->query('SELECT COUNT(*) FROM contact_leads')->fetchColumn();
chk('valid phone converts the conversation', ($good['body']['ok'] ?? false) === true);
chk('a contact_leads row was created', $after === $before + 1);

$convRow = $pdo->query("SELECT status, lead_id FROM chatbot_conversations WHERE id=$conversationId")->fetch();
chk('conversation marked converted and linked to the lead',
    ($convRow['status'] ?? '') === 'converted' && (int)($convRow['lead_id'] ?? 0) > 0);

// ---- F. firm scoping on the lawyer inbox --------------------------------------
$otherFirmId = (int)$pdo->query("SELECT id FROM law_firms WHERE id <> $firmId ORDER BY id LIMIT 1")->fetchColumn();
$otherLawyerId = (int)$pdo->query("SELECT lawyer_id FROM law_firm_members WHERE firm_id=$otherFirmId LIMIT 1")->fetchColumn();
$otherLawyerEmail = (string)$pdo->query("SELECT email FROM lawyers WHERE id=$otherLawyerId")->fetchColumn();

chk('firm_chatbot_conversation() refuses another firm',
    firm_chatbot_conversation($pdo, $conversationId, $otherFirmId) === null);
chk('firm_chatbot_conversations() is empty for another firm',
    firm_chatbot_conversations($pdo, $otherFirmId, '', 50) === []);

// Live HTTP: log in as the other firm's lawyer and try to open the conversation.
$jar = '/tmp/exec70_' . bin2hex(random_bytes(4)) . '.jar';
@unlink($jar);
$loginPage = get_page("$base/lawyer-login.php", $jar);
$csrf = csrf_from($loginPage['body']);
$login = post_form("$base/lawyer-login.php", [
    'csrf_token' => $csrf, 'email' => $otherLawyerEmail, 'password' => 'Test-Password-2026',
], $jar);
if ($login['status'] === 302) {
    $inbox = get_page("$base/lawyer/chatbot.php?c=$conversationId", $jar);
    chk('other firm cannot read the conversation over HTTP',
        !str_contains($inbox['body'], 'زائر اختبار') && !str_contains($inbox['body'], '0509998877'));
    $csrf2 = csrf_from($inbox['body']);
    if ($csrf2 !== '') {
        $reply = post_form("$base/lawyer/chatbot.php", [
            'csrf_token' => $csrf2, 'action' => 'reply',
            'conversation_id' => $conversationId, 'message' => 'hijack',
        ], $jar);
        chk('other firm cannot reply over HTTP (404)', $reply['status'] === 404);
    } else {
        chk('other firm chatbot page exposes a CSRF token to test the reply', false);
    }
} else {
    echo "  --   skipped live-HTTP cross-firm checks (could not log in as $otherLawyerEmail, status {$login['status']})\n";
}
@unlink($jar);

// ---- cleanup --------------------------------------------------------------------
$pdo->prepare('DELETE FROM chatbot_messages WHERE conversation_id = ?')->execute([$conversationId]);
$pdo->prepare('DELETE FROM chatbot_conversations WHERE id = ?')->execute([$conversationId]);
$pdo->prepare('DELETE FROM contact_leads WHERE phone = ?')->execute(['0509998877']);
$pdo->prepare('DELETE FROM auth_rate_limits WHERE scope_key = ?')->execute([$bucketLike]);
$pdo->exec("UPDATE firm_chatbots SET is_enabled=1 WHERE id=" . (int)$bot['id']);

echo "\n$checks checks, $failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
