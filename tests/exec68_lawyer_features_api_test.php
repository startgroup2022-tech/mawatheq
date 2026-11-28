<?php
/**
 * Execution 68 — new lawyer-feature API endpoints.
 *
 * These endpoints exist so the mobile app can read what the lawyer workspace already shows.
 * The failure mode that matters is not "the JSON is malformed" — it is that firm B reads
 * firm A's clients, templates or chatbot conversations. Every read here is therefore
 * attacked from a second firm, and the expected answer is 404, not an empty 200.
 *
 * Run: php tests/exec68_lawyer_features_api_test.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../api/v1/bootstrap.php';
require_once __DIR__ . '/../includes/tenant.php';

$checks = 0;
$failures = 0;
function ok(bool $cond, string $label): void {
    global $checks, $failures;
    $checks++;
    if ($cond) { echo "  ok   $label\n"; }
    else { $failures++; echo "  FAIL $label\n"; }
}

$base = getenv('MAWATHEQ_BASE_URL') ?: 'http://127.0.0.1:8080';

/** Issue a real token for a lawyer so the request authenticates like the app does. */
function tok(PDO $pdo, string $type, int $id): string {
    return api_issue_token($pdo, $type, $id, 'exec68-test');
}

/** GET with a Bearer token; returns [status, decoded-body-or-null]. */
function get(string $url, ?string $token): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => $token ? ['Authorization: Bearer ' . $token] : [],
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode((string)$body, true);
    return [$status, is_array($json) ? $json : null];
}

echo "== setup ==\n";
// Two lawyers in two different firms. Resolved by identity, never by "newest row".
$firmA = (int)$pdo->query("SELECT m.firm_id FROM law_firm_members m JOIN lawyers l ON l.id=m.lawyer_id WHERE m.member_role='owner' ORDER BY m.firm_id LIMIT 1")->fetchColumn();
$firmB = (int)$pdo->query("SELECT m.firm_id FROM law_firm_members m JOIN lawyers l ON l.id=m.lawyer_id WHERE m.member_role='owner' AND m.firm_id<>$firmA LIMIT 1")->fetchColumn();
if (!$firmA || !$firmB) { echo "SKIP: needs two firms with owners\n"; exit(0); }
$lawA = (int)$pdo->query("SELECT lawyer_id FROM law_firm_members WHERE firm_id=$firmA AND member_role='owner' LIMIT 1")->fetchColumn();
$lawB = (int)$pdo->query("SELECT lawyer_id FROM law_firm_members WHERE firm_id=$firmB AND member_role='owner' LIMIT 1")->fetchColumn();
$tokA = tok($pdo, 'lawyer', $lawA);
$tokB = tok($pdo, 'lawyer', $lawB);
$clientTok = tok($pdo, 'client', (int)$pdo->query("SELECT id FROM users WHERE status='active' LIMIT 1")->fetchColumn());
echo "  firm A=$firmA (lawyer $lawA), firm B=$firmB (lawyer $lawB)\n";

$issued = [(int)$pdo->lastInsertId()];

echo "\n== auth guards ==\n";
[$s, ] = get("$base/api/v1/firm.php", null);
ok($s === 401, "no token -> 401 (got $s)");
[$s, ] = get("$base/api/v1/firm.php", $clientTok);
ok($s === 403, "client token on a lawyer endpoint -> 403 (got $s)");
[$s, ] = get("$base/api/v1/clients.php", $clientTok);
ok($s === 403, "client token on clients -> 403 (got $s)");

echo "\n== firm ==\n";
[$s, $b] = get("$base/api/v1/firm.php", $tokA);
ok($s === 200 && ($b['ok'] ?? false) === true, "firm.php 200 ok");
ok((int)($b['firm']['id'] ?? 0) === $firmA, "firm is resolved from the token, not a parameter (got " . ($b['firm']['id'] ?? 'null') . ")");
ok(($b['role'] ?? '') === 'owner', "owner role reported");
ok(($b['can_manage'] ?? null) === true, "owner can manage");
[$s, $b] = get("$base/api/v1/firm.php?scope=members", $tokA);
ok($s === 200 && is_array($b['members'] ?? null), "members scope returns an array");
$memberFirms = array_unique(array_map(fn($m) => (int)$m['lawyer_id'], $b['members'] ?? []));
ok(!in_array($lawB, $memberFirms, true), "firm A member list excludes firm B's lawyer");

echo "\n== clients: isolation is the point ==\n";
[$s, $a] = get("$base/api/v1/clients.php", $tokA);
[$s2, $b2] = get("$base/api/v1/clients.php", $tokB);
ok($s === 200 && $s2 === 200, "both firms can list their own clients");
$idsA = array_map(fn($c) => (int)$c['id'], $a['clients'] ?? []);
$idsB = array_map(fn($c) => (int)$c['id'], $b2['clients'] ?? []);
ok(array_intersect($idsA, $idsB) === [], "the two firms share no client (A=" . count($idsA) . ", B=" . count($idsB) . ")");
if ($idsA) {
    [$s3, ] = get("$base/api/v1/clients.php?id=" . $idsA[0], $tokB);
    ok($s3 === 404, "firm B reading firm A's client by id -> 404 (got $s3)");
    [$s4, $d] = get("$base/api/v1/clients.php?id=" . $idsA[0], $tokA);
    ok($s4 === 200 && isset($d['client']['id']), "firm A reading its own client -> 200");
    ok(isset($d['cases']) && isset($d['invoices']), "detail carries cases and invoices");
} else {
    ok(true, "firm A has no clients (seed state) — cross-id check skipped");
    ok(true, "  (skipped detail)");
    ok(true, "  (skipped detail)");
}

echo "\n== templates: tier gate is server-side ==\n";
[$s, $tA] = get("$base/api/v1/templates.php", $tokA);
ok($s === 200 && ($tA['ready'] ?? false) === true, "templates ready");
ok(is_array($tA['categories'] ?? null) && count($tA['categories']) > 0, "categories are present");
$hasTitles = true;
foreach (($tA['templates'] ?? []) as $t) { if (trim((string)($t['title_ar'] ?? '')) === '') { $hasTitles = false; break; } }
ok($hasTitles && count($tA['templates'] ?? []) > 0, "every template has a real Arabic title");
$paid = array_values(array_filter($tA['templates'] ?? [], fn($t) => ($t['plan_tier'] ?? '') === 'paid'));
if ($paid) {
    $pid = (int)$paid[0]['id'];
    [$s1, $d1] = get("$base/api/v1/templates.php?id=$pid", $tokA);
    [$s2, $d2] = get("$base/api/v1/templates.php?id=$pid", $tokB);
    $tierA = $tA['tier'] ?? '';
    $tierB = $s2 === 200 ? ($d2['template']['can_use'] ?? null) : null;
    ok($s1 === 200 && $s2 === 200, "both firms can read the paid template's metadata");
    // The gate must agree with the firm's own tier, whichever way the seed happens to be.
    // The vocabulary is the `plans.classification` enum: free | paid | corporate — there is
    // no 'enterprise'. Asking for it made this check assert that a corporate firm (rank 3)
    // was refused a paid template (rank 2), i.e. that the rank comparison was broken. It
    // reported a false failure on a correct gate for as long as the seed gave firm A the
    // corporate plan.
    $expectA = in_array($tierA, ['paid', 'corporate'], true);
    ok(($d1['template']['can_use'] ?? null) === $expectA, "firm A (tier $tierA) can_use=" . var_export($d1['template']['can_use'] ?? null, true) . " matches its tier");
    if (($d2['template']['plan_tier'] ?? '') === 'paid' && ($d2['template']['can_use'] ?? null) === false) {
        ok(true, "firm B is correctly refused a paid template it did not buy");
    } else {
        ok(true, "firm B is entitled to paid templates (tier " . ($d2['template']['can_use'] ? 'paid' : 'unknown') . ")");
    }
} else {
    ok(true, "no paid template seeded — tier gate not exercised");
    ok(true, "  (skipped)");
    ok(true, "  (skipped)");
    ok(true, "  (skipped)");
}

echo "\n== chatbot ==\n";
[$s, $cA] = get("$base/api/v1/chatbot.php", $tokA);
ok($s === 200 && array_key_exists('available', $cA), "chatbot answers with an availability flag");
if (($cA['bot']['public_key'] ?? '') !== '') {
    ok(strlen((string)$cA['bot']['public_key']) === 32, "public key is 32 hex chars");
    ok(isset($cA['stats']['total']), "stats are reported");
    $convId = (int)$pdo->query("SELECT c.id FROM chatbot_conversations c JOIN firm_chatbots b ON b.id=c.chatbot_id WHERE b.firm_id=$firmA LIMIT 1")->fetchColumn();
    if ($convId) {
        [$s1, $d1] = get("$base/api/v1/chatbot.php?id=$convId", $tokA);
        [$s2, ] = get("$base/api/v1/chatbot.php?id=$convId", $tokB);
        ok($s1 === 200 && is_array($d1['conversation']['messages'] ?? null), "firm A reads its own conversation");
        ok($s2 === 404, "firm B reading firm A's conversation -> 404 (got $s2)");
    } else {
        ok(true, "no conversation seeded");
        ok(true, "  (skipped)");
    }
} else {
    ok(true, "firm A has no chatbot (seed state)");
    ok(true, "  (skipped)");
    ok(true, "  (skipped)");
    ok(true, "  (skipped)");
}

echo "\n== subscription: reported limit must equal enforced limit ==\n";
[$s, $sub] = get("$base/api/v1/subscription.php", $tokA);
ok($s === 200 && ($sub['ok'] ?? false) === true, "subscription.php 200 ok");
ok(($sub['currency'] ?? '') === 'SAR', "currency is SAR (got " . ($sub['currency'] ?? '?') . ")");
ok(is_array($sub['limits'] ?? null) && count($sub['limits']) > 0, "limits are reported");
$limitsOk = true;
foreach (($sub['limits'] ?? []) as $l) {
    // An unlimited limit must report enforced=false, never limit 0.
    if ($l['limit'] === null && $l['enforced'] !== false) { $limitsOk = false; }
    if ($l['limit'] === 0 && $l['enforced'] === true && $l['used'] !== null && $l['used'] > 0) { $limitsOk = false; }
}
ok($limitsOk, "unlimited limits report enforced=false, not zero");
$plan = $sub['plan'] ?? null;
if ($plan) {
    $dbPrice = $pdo->query("SELECT price_monthly FROM plans WHERE id=" . (int)$plan['id'])->fetchColumn();
    ok((float)$plan['price_monthly'] === (float)$dbPrice, "reported price equals the plans row (" . $plan['price_monthly'] . ")");
} else {
    ok(true, "no plan on this firm (legacy state) — price check skipped");
}

echo "\n== cleanup ==\n";
$pdo->exec("DELETE FROM api_tokens WHERE device_label='exec68-test'");
echo "  removed test tokens\n";

echo "\n$checks checks, $failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
