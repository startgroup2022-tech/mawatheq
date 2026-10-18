<?php
/**
 * Execution 29 — live cross-tenant (IDOR/BOLA) probe.
 *
 * Seeds two independent firms, each with a lawyer and a client, plus cases, invoices,
 * consultations, documents and calendar events. It then authenticates as tenant A and
 * tries to read every one of tenant B's rows by id through the real /api/v1/ endpoints.
 *
 * The assertion is strict: a request for another tenant's row must never return 200, and
 * the response body must not contain that row's identifying data. A 404 and a 403 both
 * pass — either is a refusal; leaking the row is the failure.
 *
 * Requires the app running on 127.0.0.1:8080 (see tests/setup_test_env.sh).
 * Run: php tests/execution29_tenant_isolation_probe.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/tenant.php';

$base = 'http://127.0.0.1:8080';
$failures = 0;
$checks = 0;
function check(string $label, bool $ok): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) { $failures++; echo "  FAIL: $label\n"; } else { echo "  ok:   $label\n"; }
}

/** GET with a bearer token; returns [httpCode, body]. */
$get = function (string $token, string $path) use ($base): array {
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $token"],
        CURLOPT_TIMEOUT => 20,
    ]);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
};

$suffix = bin2hex(random_bytes(4));
$created = ['users' => [], 'lawyers' => [], 'law_firms' => [], 'cases' => [], 'invoices' => [],
            'consultations' => [], 'documents' => [], 'legal_calendar_events' => [], 'api_tokens' => [],
            'law_firm_members' => []];
$tokens = [];

/** Create a firm with an owner lawyer and a client, then one row of each entity type. */
$seedTenant = function (string $label) use ($pdo, $suffix, &$created, &$tokens): array {
    $emailL = "e29-{$label}-lawyer-{$suffix}@example.test";
    $emailC = "e29-{$label}-client-{$suffix}@example.test";

    $pdo->prepare("INSERT INTO lawyers (full_name, email, password_hash, status, email_verified) VALUES (?,?,?,'approved',1)")
        ->execute(["محامي $label", $emailL, password_hash('E29-Test-Pass-2026', PASSWORD_BCRYPT)]);
    $lawyerId = (int)$pdo->lastInsertId();
    $created['lawyers'][] = $lawyerId;

    $pdo->prepare("INSERT INTO users (full_name, email, password_hash, status, email_verified, account_type) VALUES (?,?,?,'active',1,'individual')")
        ->execute(["عميل $label", $emailC, password_hash('E29-Test-Pass-2026', PASSWORD_BCRYPT)]);
    $clientId = (int)$pdo->lastInsertId();
    $created['users'][] = $clientId;

    $pdo->prepare("INSERT INTO law_firms (name, display_name, owner_lawyer_id) VALUES (?,?,?)")
        ->execute(["مكتب $label", "Firm $label", $lawyerId]);
    $firmId = (int)$pdo->lastInsertId();
    $created['law_firms'][] = $firmId;

    $pdo->prepare("INSERT INTO law_firm_members (firm_id, lawyer_id, member_role, status) VALUES (?,?,'owner','active')")
        ->execute([$firmId, $lawyerId]);
    $created['law_firm_members'][] = (int)$pdo->lastInsertId();

    $pdo->prepare('INSERT INTO cases (user_id, lawyer_id, firm_id, title) VALUES (?,?,?,?)')
        ->execute([$clientId, $lawyerId, $firmId, "قضية $label"]);
    $caseId = (int)$pdo->lastInsertId();
    $created['cases'][] = $caseId;

    $pdo->prepare('INSERT INTO invoices (user_id, lawyer_id, firm_id, description, amount) VALUES (?,?,?,?,?)')
        ->execute([$clientId, $lawyerId, $firmId, "فاتورة $label", 1500.00]);
    $invoiceId = (int)$pdo->lastInsertId();
    $created['invoices'][] = $invoiceId;

    $pdo->prepare('INSERT INTO consultations (user_id, lawyer_id, firm_id, scheduled_at, topic) VALUES (?,?,?,DATE_ADD(NOW(), INTERVAL 1 DAY),?)')
        ->execute([$clientId, $lawyerId, $firmId, "استشارة $label"]);
    $consultId = (int)$pdo->lastInsertId();
    $created['consultations'][] = $consultId;

    $pdo->prepare('INSERT INTO documents (user_id, case_id, original_name, stored_path, file_type) VALUES (?,?,?,?,?)')
        ->execute([$clientId, $caseId, "doc-$label.pdf", "uploads/documents/e29-$label-$suffix.pdf", 'pdf']);
    $docId = (int)$pdo->lastInsertId();
    $created['documents'][] = $docId;

    $pdo->prepare('INSERT INTO legal_calendar_events (lawyer_id, firm_id, case_id, title, event_at, event_type) VALUES (?,?,?,?,DATE_ADD(NOW(), INTERVAL 2 DAY),?)')
        ->execute([$lawyerId, $firmId, $caseId, "جلسة $label", 'court_session']);
    $eventId = (int)$pdo->lastInsertId();
    $created['legal_calendar_events'][] = $eventId;

    // API tokens for both identities (stored hashed, as api_issue_token does).
    $lawyerToken = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO api_tokens (token, user_type, user_id, device_label, expires_at) VALUES (?,?,?,?,DATE_ADD(NOW(), INTERVAL 1 YEAR))')
        ->execute([hash('sha256', $lawyerToken), 'lawyer', $lawyerId, "e29-$label"]);
    $created['api_tokens'][] = (int)$pdo->lastInsertId();
    $clientToken = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO api_tokens (token, user_type, user_id, device_label, expires_at) VALUES (?,?,?,?,DATE_ADD(NOW(), INTERVAL 1 YEAR))')
        ->execute([hash('sha256', $clientToken), 'client', $clientId, "e29-$label"]);
    $created['api_tokens'][] = (int)$pdo->lastInsertId();

    $tokens[$label] = ['lawyer' => $lawyerToken, 'client' => $clientToken];

    return compact('lawyerId', 'clientId', 'firmId', 'caseId', 'invoiceId', 'consultId', 'docId', 'eventId', 'emailL', 'emailC');
};

echo "\n== setup: two independent tenants ==\n";
$A = $seedTenant('A');
$B = $seedTenant('B');
check('tenant A seeded', $A['firmId'] > 0 && $A['lawyerId'] > 0 && $A['clientId'] > 0);
check('tenant B seeded', $B['firmId'] > 0 && $B['lawyerId'] > 0 && $B['clientId'] > 0);
check('the two tenants are distinct', $A['firmId'] !== $B['firmId'] && $A['lawyerId'] !== $B['lawyerId']);

/** Assert a cross-tenant read is refused and leaks nothing. */
$assertRefused = function (string $label, string $token, string $path, string $mustNotContain) use ($get): void {
    [$code, $body] = $get($token, $path);
    $refused = $code !== 200;
    check("$label refused (HTTP $code)", $refused);
    if ($mustNotContain !== '') {
        check("$label leaked no other-tenant data", !str_contains($body, $mustNotContain));
    }
};

/**
 * A list endpoint is expected to answer 200 with the caller's own rows — refusing the
 * whole request would be wrong. The isolation property there is that the other tenant's
 * rows are absent, so that is what is asserted.
 */
$assertListIsolated = function (string $label, string $token, string $path, string $ownMarker, string $otherMarker) use ($get): void {
    [$code, $body] = $get($token, $path);
    check("$label responds 200", $code === 200);
    check("$label excludes the other tenant's rows", !str_contains($body, $otherMarker));
    check("$label still returns the caller's own rows", $ownMarker === '' || str_contains($body, $ownMarker));
};

echo "\n== 1. lawyer A cannot read tenant B's rows by id ==\n";
$tA = $tokens['A']['lawyer'];
$assertRefused('cases.php?id=B.case', $tA, '/api/v1/cases.php?id=' . $B['caseId'], 'قضية B');
$assertRefused('documents.php?id=B.doc', $tA, '/api/v1/documents.php?id=' . $B['docId'], 'doc-B.pdf');
$assertListIsolated('invoices.php list', $tA, '/api/v1/invoices.php', 'فاتورة A', 'فاتورة B');
$assertListIsolated('consultations.php list', $tA, '/api/v1/consultations.php', 'استشارة A', 'استشارة B');
$assertListIsolated('calendar.php list', $tA, '/api/v1/calendar.php', 'جلسة A', 'جلسة B');

echo "\n== 2. lawyer B cannot read tenant A's rows by id ==\n";
$tB = $tokens['B']['lawyer'];
$assertRefused('cases.php?id=A.case', $tB, '/api/v1/cases.php?id=' . $A['caseId'], 'قضية A');
$assertRefused('documents.php?id=A.doc', $tB, '/api/v1/documents.php?id=' . $A['docId'], 'doc-A.pdf');
$assertListIsolated('invoices.php list', $tB, '/api/v1/invoices.php', 'فاتورة B', 'فاتورة A');
$assertListIsolated('consultations.php list', $tB, '/api/v1/consultations.php', 'استشارة B', 'استشارة A');
$assertListIsolated('calendar.php list', $tB, '/api/v1/calendar.php', 'جلسة B', 'جلسة A');

echo "\n== 3. a lawyer sees their own tenant's rows (the scope is not simply denying) ==\n";
[$code, $body] = $get($tA, '/api/v1/cases.php?id=' . $A['caseId']);
check('lawyer A can read their own case', $code === 200 && str_contains($body, 'قضية A'));
[$code, $body] = $get($tA, '/api/v1/cases.php');
check('lawyer A case list contains their own case', $code === 200 && str_contains($body, 'قضية A'));
check('lawyer A case list excludes tenant B', !str_contains($body, 'قضية B'));

echo "\n== 4. client A cannot read client B's rows ==\n";
$cA = $tokens['A']['client'];
$assertRefused('client A cases.php?id=B.case', $cA, '/api/v1/cases.php?id=' . $B['caseId'], 'قضية B');
$assertRefused('client A documents.php?id=B.doc', $cA, '/api/v1/documents.php?id=' . $B['docId'], 'doc-B.pdf');
$assertListIsolated('client A invoices.php list', $cA, '/api/v1/invoices.php', 'فاتورة A', 'فاتورة B');
$assertListIsolated('client A consultations.php list', $cA, '/api/v1/consultations.php', 'استشارة A', 'استشارة B');
[$code, $body] = $get($cA, '/api/v1/cases.php');
check('client A case list excludes tenant B', $code === 200 && !str_contains($body, 'قضية B'));

echo "\n== 5. the finance summary is tenant-scoped ==\n";
[$code, $body] = $get($tA, '/api/v1/finance.php');
check('lawyer A finance summary responds', $code === 200);
check('lawyer A finance summary excludes tenant B case titles', !str_contains($body, 'قضية B'));
[$code, $body] = $get($tB, '/api/v1/finance.php');
check('lawyer B finance summary excludes tenant A case titles', !str_contains($body, 'قضية A'));

echo "\n== 6. a client cannot reach lawyer-only surfaces ==\n";
foreach (['/api/v1/finance.php', '/api/v1/calendar.php'] as $path) {
    [$code] = $get($cA, $path);
    check("client refused on $path (HTTP $code)", $code === 403);
}

echo "\n== 7. cross-tenant writes are refused ==\n";
/** POST with a bearer token; returns [httpCode, body]. */
$post = function (string $token, string $path, array $fields) use ($base): array {
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $token"],
        CURLOPT_TIMEOUT => 20,
    ]);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
};
// A lawyer completing another firm's calendar event must fail.
[$code, $body] = $post($tA, '/api/v1/calendar.php', ['action' => 'complete', 'event_id' => $B['eventId']]);
check("lawyer A cannot complete tenant B's event (HTTP $code)", $code === 404 || $code === 403);
$status = (string)$pdo->query("SELECT status FROM legal_calendar_events WHERE id=" . $B['eventId'])->fetchColumn();
check("tenant B's event was not modified", $status !== 'completed');

// The same call on the lawyer's own event must succeed. Before the fix this route
// returned 500 for every caller, because the scope clause referenced an alias the
// UPDATE did not declare.
[$code, $body] = $post($tA, '/api/v1/calendar.php', ['action' => 'complete', 'event_id' => $A['eventId']]);
check("lawyer A can complete their own event (HTTP $code)", $code === 200);
$ownStatus = (string)$pdo->query("SELECT status FROM legal_calendar_events WHERE id=" . $A['eventId'])->fetchColumn();
check('tenant A event was completed', $ownStatus === 'completed');
[$code] = $post($tA, '/api/v1/calendar.php', ['action' => 'complete', 'event_id' => $A['eventId']]);
check('completing an already-completed event returns 404, not 500', $code === 404);

echo "\n== cleanup ==\n";
foreach (['legal_calendar_events', 'documents', 'consultations', 'invoices', 'cases', 'law_firm_members', 'law_firms', 'api_tokens', 'lawyers', 'users'] as $table) {
    if (empty($created[$table])) continue;
    $ids = implode(',', array_map('intval', $created[$table]));
    $pdo->exec("DELETE FROM `$table` WHERE id IN ($ids)");
}
$left = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE email LIKE 'e29-%-$suffix@example.test'")->fetchColumn();
check('all seeded accounts removed', $left === 0);

echo "\n  Execution 29 tenant isolation: $checks checks, $failures failure(s)\n";
exit($failures > 0 ? 1 : 0);
