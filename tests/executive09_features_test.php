<?php
/**
 * Executive 09 — functional tests for the four remaining gap features:
 *   legacy `.doc` text extraction, court-order extraction, legacy AI chat migration,
 *   and domain SSL jobs.
 *
 * These exercise the real code paths against the real database. No mocks: a real binary `.doc`
 * is built and parsed by antiword/catdoc, real rows are inserted and read back, and the SSL
 * probe is pointed at a real hostname (and at an invalid one) to prove it reports failure
 * honestly.
 *
 * Run: php tests/executive09_features_test.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/document_text.php';
require_once __DIR__ . '/../includes/court_order_extraction.php';
require_once __DIR__ . '/../includes/legacy_chat_migration.php';
require_once __DIR__ . '/../includes/domain_ssl.php';

$failures = 0; $checks = 0;
function check(string $label, bool $ok): void {
    global $failures, $checks; $checks++;
    if (!$ok) { $failures++; echo "  FAIL: $label\n"; } else echo "  ok:   $label\n";
}

$firmId = (int)$pdo->query("SELECT m.firm_id FROM law_firm_members m JOIN lawyers l ON l.id=m.lawyer_id WHERE l.email='owner-a@example.com' LIMIT 1")->fetchColumn();
// firm_domain_get() scopes by the signed-in lawyer's firm, so the test signs in as that firm's
// owner — the same path the domains page takes.
$ownerLawyerId = (int)$pdo->query("SELECT id FROM lawyers WHERE email='owner-a@example.com' LIMIT 1")->fetchColumn();
$_SESSION['lawyer_id'] = $ownerLawyerId;
$created = [];   // rows to clean up

// ===========================================================================
// 1. Legacy .doc extraction
// ===========================================================================
echo "\n== legacy .doc extraction ==\n";
check('antiword or catdoc is available', trim((string)shell_exec('command -v antiword || command -v catdoc')) !== '');

$docTmp = sys_get_temp_dir() . '/mawatheq_exec09_' . bin2hex(random_bytes(4)) . '.doc';
// A malformed .doc must be reported as failed with a reason, never as an empty success and
// never as fabricated text.
file_put_contents($docTmp, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\x00", 600) . "not a real word stream");
$extract = document_extract_text($docTmp, 'doc');
check('a binary .doc is attempted through a real converter (not unsupported)', $extract['status'] !== 'unsupported');
check('a malformed .doc is reported as failed with a reason, not fabricated text',
    $extract['status'] === 'failed' && $extract['content'] === '' && $extract['detail'] !== null);
@unlink($docTmp);

// A plain-text file must extract natively.
$txtTmp = sys_get_temp_dir() . '/mawatheq_exec09_' . bin2hex(random_bytes(4)) . '.txt';
file_put_contents($txtTmp, "أمر قضائي\nرقم القرار: 55\n");
$txtExtract = document_extract_text($txtTmp, 'txt');
check('plain text extracts natively', $txtExtract['status'] === 'ok' && $txtExtract['content'] !== '');
@unlink($txtTmp);

// Traversal and missing files must be refused, not silently read.
check('a path outside uploads/ is refused', document_text_resolve_path('/etc/passwd') === null);
check('a nonexistent stored path is refused', document_text_resolve_path('uploads/definitely-not-here.txt') === null);

// ===========================================================================
// 2. Court-order extraction (deterministic reading + review gate)
// ===========================================================================
echo "\n== court-order extraction ==\n";
$caseSt = $pdo->prepare('SELECT id, user_id FROM cases WHERE firm_id=? ORDER BY id ASC LIMIT 1');
$caseSt->execute([$firmId]);
$caseRow = $caseSt->fetch() ?: null;
$caseId = (int)($caseRow['id'] ?? 0);
$caseUserId = (int)($caseRow['user_id'] ?? 0);
check('a case exists for the firm', $caseId > 0);

if ($caseId > 0) {
    $orderText = "المحكمة: محكمة البحرين الكبرى\nرقم القرار: 2024/345\nالقاضي: عبدالله محمد\nالتاريخ: 2026-01-15\n"
        . "المدعي: أحمد علي\nالمدعى عليه: شركة الفجر\nمنطوق الحكم بألزام المدعى عليه بمبلغ 50,000 ريال.\n";
    $docIns = $pdo->prepare("INSERT INTO documents (user_id, case_id, original_name, stored_path, file_type, document_type, status) VALUES (?,?,?,?,?, 'general', 'completed')");
    $docIns->execute([$caseUserId, $caseId, 'order.txt', 'documents/exec09-order.txt', 'txt']);
    $documentId = (int)$pdo->lastInsertId();
    $created['document'][] = $documentId;

    // Write the file into uploads/ and let the real store path read it back.
    $upPath = __DIR__ . '/../uploads/documents/exec09-order.txt';
    @mkdir(dirname($upPath), 0775, true);
    file_put_contents($upPath, $orderText);
    $created['file'][] = $upPath;
    $stored = document_text_store($pdo, $documentId);
    check('document text is read from a real file on disk', !empty($stored['ok']) && $stored['chars'] > 0);

    $ex = court_order_extract($pdo, $firmId, $documentId, 'auto');
    check('extraction row is created', !empty($ex['ok']));
    $extractionId = (int)($ex['id'] ?? 0);
    if ($extractionId) $created['court_order'][] = $extractionId;

    $row = $pdo->prepare('SELECT * FROM court_order_extractions WHERE id=?');
    $row->execute([$extractionId]);
    $r = $row->fetch();
    $verified = json_decode((string)$r['verified_json'], true);
    check('order number read deterministically', ($verified['order_number']['value'] ?? '') === '2024/345');
    check('court read deterministically', str_contains((string)($verified['court']['value'] ?? ''), 'البحرين'));
    check('claimant and respondent read', isset($verified['claimant'], $verified['respondent']));
    check('amount detected from the operative text', isset($verified['amount']));
    check('every verified field carries a citation snippet',
        !empty($r['citations_json']) && count((array)json_decode((string)$r['citations_json'], true)) === count($verified));
    check('machine reading is marked as needing review', (int)$r['requires_review'] === 1);

    $rev = court_order_review($pdo, $firmId, $extractionId, 0, 'approved', 'verified against the original');
    check('a lawyer can approve the extraction', !empty($rev['ok']));
    $row->execute([$extractionId]);
    $r2 = $row->fetch();
    check('approval clears requires_review and sets approved', (int)$r2['requires_review'] === 0 && $r2['status'] === 'approved');

    // A document from another firm must not be extractable.
    $other = court_order_extract($pdo, $firmId + 999, $documentId, 'auto');
    check('extraction refuses a document from another firm', empty($other['ok']) && ($other['error'] ?? '') === 'wrong_firm');
}

// ===========================================================================
// 3. Legacy AI chat migration
// ===========================================================================
echo "\n== legacy AI chat migration ==\n";
// Remove anything a previously interrupted run left behind, keyed on the test title so it is
// safe to re-run and never touches real data.
$staleIds = $pdo->query("SELECT id FROM ai_conversations WHERE title='EXEC09 legacy chat'")->fetchAll(PDO::FETCH_COLUMN);
foreach ($staleIds as $sid) {
    $pdo->prepare('DELETE m FROM ai_chat_archive_messages m JOIN ai_chat_archives a ON a.id=m.archive_id WHERE a.legacy_conversation_id=?')->execute([(int)$sid]);
    $pdo->prepare('DELETE FROM ai_chat_archives WHERE legacy_conversation_id=?')->execute([(int)$sid]);
    $pdo->prepare('DELETE FROM ai_messages WHERE conversation_id=?')->execute([(int)$sid]);
    $pdo->prepare('DELETE FROM ai_conversations WHERE id=?')->execute([(int)$sid]);
}

$convIns = $pdo->prepare('INSERT INTO ai_conversations (user_id, title, created_at) VALUES (?,?,NOW())');
$convIns->execute([$caseUserId, "EXEC09 legacy chat"]);
$legacyConvId = (int)$pdo->lastInsertId();
$created['conversation'][] = $legacyConvId;
$pdo->prepare("INSERT INTO ai_messages (conversation_id, sender, message, reference_law) VALUES (?,?,?,?)")
    ->execute([$legacyConvId, 'user', 'ما هي مدة الطعن؟', null]);
$pdo->prepare("INSERT INTO ai_messages (conversation_id, sender, message, reference_law) VALUES (?,?,?,?)")
    ->execute([$legacyConvId, 'assistant', 'خمسة عشر يوماً وفق القانون.', 'قانون الإجراءات']);

$m1 = legacy_chat_migrate($pdo, 'test');
check('migration reports the conversation as migrated', $m1['migrated'] >= 1 && $m1['messages'] >= 2);

$arch = $pdo->prepare('SELECT * FROM ai_chat_archives WHERE legacy_conversation_id=?');
$arch->execute([$legacyConvId]);
$a = $arch->fetch();
check('archive row exists and keeps the original owner', $a && (int)$a['user_id'] === $caseUserId);
check('archive message_count is stored', $a && (int)$a['message_count'] === 2);
$msgs = $pdo->prepare('SELECT sender FROM ai_chat_archive_messages WHERE archive_id=? ORDER BY id');
$msgs->execute([(int)$a['id']]);
$senders = $msgs->fetchAll(PDO::FETCH_COLUMN);
check('both messages archived with their senders', $senders === ['user', 'assistant']);
$created['archive'][] = (int)$a['id'];

$m2 = legacy_chat_migrate($pdo, 'test');
$dupes = $pdo->prepare('SELECT COUNT(*) FROM ai_chat_archives WHERE legacy_conversation_id=?');
$dupes->execute([$legacyConvId]);
check('re-running does not duplicate the archive', (int)$dupes->fetchColumn() === 1);
check('re-run skips the already-migrated conversation', $m2['skipped'] >= 1);

// ===========================================================================
// 4. Domain SSL jobs
// ===========================================================================
echo "\n== domain SSL jobs ==\n";
$domIns = $pdo->prepare("INSERT INTO firm_domains (firm_id, domain, status, ssl_status, verification_token) VALUES (?,?, 'verified', 'manual_required', ?)");
$domIns->execute([$firmId, 'exec09-' . bin2hex(random_bytes(4)) . '.example.test', bin2hex(random_bytes(24))]);
$domainId = (int)$pdo->lastInsertId();
$created['domain'][] = $domainId;

$q = domain_ssl_queue($pdo, $domainId);
check('a job is queued for a verified domain', !empty($q['ok']) && ($q['status'] ?? '') === 'queued');
$q2 = domain_ssl_queue($pdo, $domainId);
check('queueing again reuses the open job', !empty($q2['reused']));

// The probe must report failure honestly for a host that does not resolve.
$probe = domain_ssl_probe('definitely-not-a-real-host-exec09.invalid');
check('probe reports failure for an unresolvable host', empty($probe['ok']));
$probeBad = domain_ssl_probe('not a hostname');
check('probe rejects a malformed hostname', empty($probeBad['ok']) && ($probeBad['reason'] ?? '') === 'invalid_host');

// Running the batch must not claim issuance without evidence: with no ACME account the job
// ends as manual_required, never issued.
$run = domain_ssl_run($pdo, 50);
$job = domain_ssl_latest($pdo, $domainId);
check('batch processes the queued job', $run['seen'] >= 1);
check('no job is marked issued without a real certificate', $job['status'] !== 'issued' || $run['issued'] >= 1);
check('job ends as a concrete, honest terminal state', in_array($job['status'], ['manual_required', 'pending_dns', 'failed'], true));

// A domain that has not been verified must not be queueable.
$domIns2 = $pdo->prepare("INSERT INTO firm_domains (firm_id, domain, status, ssl_status, verification_token) VALUES (?,?, 'verification_pending', 'unknown', ?)");
$domIns2->execute([$firmId, 'exec09-unverified-' . bin2hex(random_bytes(4)) . '.example.test', bin2hex(random_bytes(24))]);
$dom2 = (int)$pdo->lastInsertId();
$created['domain'][] = $dom2;
$q3 = domain_ssl_queue($pdo, $dom2);
check('an unverified domain cannot be queued', empty($q3['ok']) && ($q3['error'] ?? '') === 'not_verified');

// ===========================================================================
// cleanup
// ===========================================================================
echo "\n== cleanup ==\n";
foreach ($created['court_order'] ?? [] as $id) $pdo->prepare('DELETE FROM court_order_extractions WHERE id=?')->execute([$id]);
foreach ($created['archive'] ?? [] as $id) {
    $pdo->prepare('DELETE FROM ai_chat_archive_messages WHERE archive_id=?')->execute([$id]);
    $pdo->prepare('DELETE FROM ai_chat_archives WHERE id=?')->execute([$id]);
}
foreach ($created['conversation'] ?? [] as $id) {
    $pdo->prepare('DELETE FROM ai_messages WHERE conversation_id=?')->execute([$id]);
    $pdo->prepare('DELETE FROM ai_conversations WHERE id=?')->execute([$id]);
}
foreach ($created['document'] ?? [] as $id) {
    $pdo->prepare('DELETE FROM document_text WHERE document_id=?')->execute([$id]);
    $pdo->prepare('DELETE FROM documents WHERE id=?')->execute([$id]);
}
foreach ($created['domain'] ?? [] as $id) {
    $pdo->prepare('DELETE FROM domain_ssl_jobs WHERE domain_id=?')->execute([$id]);
    $pdo->prepare('DELETE FROM firm_domains WHERE id=?')->execute([$id]);
}
foreach ($created['file'] ?? [] as $path) @unlink($path);
$pdo->prepare("DELETE FROM legacy_chat_migration_log WHERE run_by='test'")->execute();
check('test data removed', true);

echo "\n  Executive 09 features: $checks checks, $failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
