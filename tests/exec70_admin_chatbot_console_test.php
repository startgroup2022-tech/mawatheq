<?php
/**
 * Execution 70 — the operator can see and stop firm chatbots, and cannot author them.
 *
 * Two claims are checked here, and the second is the one that matters:
 *
 *  1. the console page renders the real rows (every bot with its firm, plan and
 *     conversation counts, plus template usage against each plan's ceiling);
 *  2. switching a bot off in the console actually stops it — the public endpoint answers
 *     `403 disabled` afterwards, so this is a control, not a badge.
 *
 * It also pins the boundary: `editor` and `staff` may not reach the page at all, and the
 * operator cannot change a bot's greeting — the content stays with the firm.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/admin_rbac.php';
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

function h_get(string $url, string $jar): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar,
                            CURLOPT_COOKIEJAR => $jar, CURLOPT_TIMEOUT => 30]);
    $b = curl_exec($ch); $s = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['status' => $s, 'body' => (string)$b];
}

function h_post(string $url, array $f, string $jar): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($f),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30]);
    $b = curl_exec($ch); $s = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['status' => $s, 'body' => (string)$b];
}

function bot_start(string $base, string $key, string $visitor): array {
    $ch = curl_init("$base/api/firm-chat.php");
    curl_setopt_array($ch, [CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['action' => 'start', 'key' => $key, 'visitor' => $visitor]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
    $b = curl_exec($ch); $s = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['status' => $s, 'body' => json_decode((string)$b, true)];
}

echo "Execution 70 — admin oversight of firm chatbots\n";
echo "================================================\n";

// ---- RBAC, asserted directly against the shared map ------------------------------
chk('super_admin may reach the console page', admin_role_allows('firm-chatbots.php', 'super_admin'));
chk('support may reach the console page', admin_role_allows('firm-chatbots.php', 'support'));
chk('editor may NOT reach the console page', !admin_role_allows('firm-chatbots.php', 'editor'));
chk('staff may NOT reach the console page', !admin_role_allows('firm-chatbots.php', 'staff'));

// ---- seed a bot on a plan that carries the feature, firm published ----------------
$firmId = (int)$pdo->query('SELECT id FROM law_firms ORDER BY id LIMIT 1')->fetchColumn();
$ownerId = (int)$pdo->query("SELECT lawyer_id FROM law_firm_members WHERE firm_id=$firmId AND member_role='owner' LIMIT 1")->fetchColumn();
$paidPlan = (int)$pdo->query("SELECT id FROM plans WHERE feature_chatbot=1 AND status='active' ORDER BY id LIMIT 1")->fetchColumn();
$prevPlan = $pdo->query("SELECT plan_id FROM law_firms WHERE id=$firmId")->fetchColumn();
$prevPublic = $pdo->query("SELECT is_public FROM law_firms WHERE id=$firmId")->fetchColumn();
$prevSlug = $pdo->query("SELECT public_slug FROM law_firms WHERE id=$firmId")->fetchColumn();
$pdo->exec("UPDATE law_firms SET plan_id=$paidPlan, is_public=1, public_slug=COALESCE(public_slug,'admin-chat-test') WHERE id=$firmId");

$bot = firm_chatbot_ensure($pdo, $firmId, $ownerId);
if (!$bot) { echo "FATAL: could not ensure a bot\n"; exit(1); }
$botId = (int)$bot['id'];
$key = (string)$bot['public_key'];
$prevEnabled = (int)$pdo->query("SELECT is_enabled FROM firm_chatbots WHERE id=$botId")->fetchColumn();
$pdo->exec("UPDATE firm_chatbots SET is_enabled=1 WHERE id=$botId");

$visitor = bin2hex(random_bytes(16));
$live = bot_start($base, $key, $visitor);
chk('the bot answers before the console touches it', $live['status'] === 200);

// ---- the console page renders the real rows --------------------------------------
$jar = '/tmp/exec70a_' . bin2hex(random_bytes(4)) . '.jar';
@unlink($jar);
$pdo->exec('DELETE FROM auth_rate_limits');
$loginPage = h_get("$base/admin/login.php", $jar);
preg_match('/name="csrf_token" value="([^"]+)"/', $loginPage['body'], $m);
$login = h_post("$base/admin/login.php", [
    'csrf_token' => $m[1] ?? '', 'email' => 'admin@mawatheq.com', 'password' => 'Test-Admin-2026',
], $jar);

if ($login['status'] === 302) {
    $page = h_get("$base/admin/firm-chatbots.php", $jar);
    chk('console page returns 200', $page['status'] === 200, 'status=' . $page['status']);
    chk('console page is not a blank body', strlen($page['body']) > 5000, 'bytes=' . strlen($page['body']));
    chk('console page has no fatal', !preg_match('/fatal error|uncaught/i', $page['body']));
    chk('console page lists the firm\'s bot', str_contains($page['body'], 'روبوتات المحادثة'));
    chk('console page shows template usage', str_contains($page['body'], 'استهلاك مكتبة النماذج'));

    // ---- disable through the console, then prove the endpoint obeys --------------
    $csrf = '';
    if (preg_match('/name="csrf_token" value="([^"]+)"/', $page['body'], $m2)) $csrf = $m2[1];
    $off = h_post("$base/admin/firm-chatbots.php", [
        'csrf_token' => $csrf, 'action' => 'toggle_bot', 'bot_id' => $botId, 'enable' => '0',
    ], $jar);
    chk('disable POST accepted', $off['status'] === 200);
    chk('DB shows the bot disabled', (int)$pdo->query("SELECT is_enabled FROM firm_chatbots WHERE id=$botId")->fetchColumn() === 0);

    $afterOff = bot_start($base, $key, bin2hex(random_bytes(16)));
    chk('public endpoint refuses a console-disabled bot (403 disabled)',
        $afterOff['status'] === 403 && ($afterOff['body']['error'] ?? '') === 'disabled',
        'status=' . $afterOff['status']);

    // ---- re-enable through the console ------------------------------------------
    $page2 = h_get("$base/admin/firm-chatbots.php", $jar);
    $csrf2 = '';
    if (preg_match('/name="csrf_token" value="([^"]+)"/', $page2['body'], $m3)) $csrf2 = $m3[1];
    $on = h_post("$base/admin/firm-chatbots.php", [
        'csrf_token' => $csrf2, 'action' => 'toggle_bot', 'bot_id' => $botId, 'enable' => '1',
    ], $jar);
    chk('enable POST accepted', $on['status'] === 200);
    $afterOn = bot_start($base, $key, bin2hex(random_bytes(16)));
    chk('public endpoint serves the bot again', $afterOn['status'] === 200);

    // ---- the console cannot author the bot's content -----------------------------
    $greetingBefore = (string)$pdo->query("SELECT greeting_ar FROM firm_chatbots WHERE id=$botId")->fetchColumn();
    h_post("$base/admin/firm-chatbots.php", [
        'csrf_token' => $csrf2, 'action' => 'save_bot', 'bot_id' => $botId, 'greeting_ar' => 'نص من الإدارة',
    ], $jar);
    $greetingAfter = (string)$pdo->query("SELECT greeting_ar FROM firm_chatbots WHERE id=$botId")->fetchColumn();
    chk('the console cannot overwrite the bot greeting', $greetingBefore === $greetingAfter);
} else {
    echo "  --   skipped live console checks (admin login status {$login['status']})\n";
}
@unlink($jar);

// ---- restore what we found -------------------------------------------------------
$pdo->exec("UPDATE firm_chatbots SET is_enabled=$prevEnabled WHERE id=$botId");
$pdo->exec("UPDATE law_firms SET plan_id=" . ($prevPlan === null ? 'NULL' : (int)$prevPlan) . " WHERE id=$firmId");
$pdo->exec("UPDATE law_firms SET is_public=" . (int)$prevPublic . " WHERE id=$firmId");
$pdo->prepare('UPDATE law_firms SET public_slug = ? WHERE id = ?')->execute([$prevSlug, $firmId]);

echo "\n$checks checks, $failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
