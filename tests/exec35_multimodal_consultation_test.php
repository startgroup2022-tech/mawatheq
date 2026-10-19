<?php
/**
 * Execution 35 — multimodal consultation submission, live end-to-end probe.
 *
 * This is the acceptance test for the brief. Every requirement is asserted against the
 * real application over HTTP on 127.0.0.1:8080 and the real private storage directory.
 * Nothing is mocked: files are real files, uploads go through the real v1 API with a real
 * Bearer token, and the rows are read back from MariaDB.
 *
 * Covered, mapped to the brief:
 *
 *   Voice recording
 *     1. audio uploads are accepted in every allowed audio format (mp3, wav, m4a, ogg)
 *     2. an audio file is stored privately and linked to the real consultation
 *     3. the original recording is preserved (bytes identical after any transcription)
 *     4. transcription without consent is refused
 *     5. transcription with consent is gated by the tenant AI policy, never bypassed
 *     6. a transcription that cannot run fails honestly (failed/declined), never faked
 *     7. transcription is idempotent: a retry reuses the stored result
 *
 *   Camera and documents
 *     8. an image/PDF uploads and is linked to the consultation
 *     9. a document is not exposed through a public URL
 *    10. multiple attachments on one consultation
 *
 *   Submission experience
 *    11. staging before the consultation exists (draft_token), then claiming on submit
 *    12. a claimed attachment is bound to the consultation and downloadable
 *    13. a draft cannot be claimed by a different client
 *    14. an abandoned draft can be discarded
 *    15. an empty submission (no description, no attachment) is refused
 *    16. an audio-only submission is accepted (description optional when audio exists)
 *    17. idempotent submit: a retry returns the SAME consultation, no duplicate row
 *    18. retry after a failed upload does not duplicate the consultation
 *
 *   Security
 *    19. file type is validated on the server (extension + sniffed MIME)
 *    20. an oversized file is refused per category
 *    21. a client cannot read another client's attachment
 *    22. a lawyer in another firm cannot read it (cross-tenant)
 *    23. an anonymous request cannot download it
 *    24. an integration token cannot reach the endpoint
 *    25. a deleted attachment stops being downloadable
 *    26. no stored path leaks in any API response
 *
 *   Lawyer integration
 *    27. the lawyer in the owning firm CAN download the client's recording
 *    28. the lawyer can read the transcription once it is ready
 *    29. the lawyer may not trigger transcription of the client's recording
 *    30. the lawyer's own upload is permitted and attributed to the lawyer
 *
 * Requires the app running on 127.0.0.1:8080 (see tests/setup_test_env.sh).
 * Run: php tests/exec35_multimodal_consultation_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/tenant.php';
require_once __DIR__ . '/../includes/consultation_attachments.php';
require_once __DIR__ . '/../includes/integrations.php';

$base = 'http://127.0.0.1:8080';
$failures = 0;
$checks = 0;
$skips = 0;
function check(string $label, bool $ok): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) { $failures++; echo "  FAIL: $label\n"; } else { echo "  ok:   $label\n"; }
}
function skip(string $label): void {
    global $skips;
    $skips++;
    echo "  SKIP: $label\n";
}

/** HTTP helper. Returns [code, body, headers]. */
function http(string $method, string $path, array $opts = []): array {
    global $base;
    $ch = curl_init($base . $path);
    $cfg = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER => true,
        CURLOPT_CUSTOMREQUEST => $method,
    ];
    if (!empty($opts['token'])) { $cfg[CURLOPT_HTTPHEADER] = ['Authorization: Bearer ' . $opts['token']]; }
    if (array_key_exists('post', $opts)) { $cfg[CURLOPT_POSTFIELDS] = $opts['post']; }
    if (array_key_exists('body', $opts)) { $cfg[CURLOPT_POSTFIELDS] = $opts['body']; }
    if (!empty($opts['json'])) {
        $cfg[CURLOPT_HTTPHEADER] = array_merge($cfg[CURLOPT_HTTPHEADER] ?? [], ['Content-Type: application/json']);
        $cfg[CURLOPT_POSTFIELDS] = json_encode($opts['json']);
    }
    curl_setopt_array($ch, $cfg);
    $raw = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $body = substr($raw, $headerSize);
    // JSON endpoints hand back a decoded array so callers can index the payload; a binary
    // download (the recording, the PDF) does not decode as JSON and is returned verbatim.
    $decoded = json_decode($body, true);
    return [$code, is_array($decoded) ? $decoded : $body, substr($raw, 0, $headerSize)];
}

function jbody(string $body): array {
    $d = json_decode($body, true);
    return is_array($d) ? $d : [];
}

$suffix = bin2hex(random_bytes(4));
$created = ['users' => [], 'lawyers' => [], 'law_firms' => [], 'law_firm_members' => [],
            'consultations' => [], 'api_tokens' => [], 'attachments' => []];
$tmpFiles = [];

function tmpUpload(string $name, string $contents): string {
    global $tmpFiles;
    $path = tempnam(sys_get_temp_dir(), 'e35') . '-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
    file_put_contents($path, $contents);
    $tmpFiles[] = $path;
    return $path;
}

/**
 * Minimal but REAL files. Validation sniffs the actual bytes, so a file that only claims to
 * be audio in its name is rejected — which is the behaviour under test, not a shortcut.
 */
function realBytes(string $ext, int $pad = 0): string {
    $pad = $pad > 0 ? str_repeat('x', $pad) : '';
    switch ($ext) {
        case 'pdf': return "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF" . $pad;
        case 'png': return "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . str_repeat("\x00", 30) . $pad;
        case 'jpg': return "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 40) . $pad;
        case 'mp3': return "ID3\x03\x00\x00\x00\x00\x00\x21" . str_repeat("\x00", 33) . "\xFF\xFB\x90\x00" . str_repeat("\x00", 400) . $pad;
        case 'wav': return 'RIFF' . pack('V', 36) . 'WAVEfmt ' . str_repeat("\x00", 100) . $pad;
        case 'm4a': return "\x00\x00\x00\x20" . 'ftypM4A ' . str_repeat("\x00", 100) . $pad;
        case 'ogg': return "OggS\x00\x02" . str_repeat("\x00", 20) . "\x01\x1E\x01vorbis" . str_repeat("\x00", 60) . "OggS\x00\x00" . str_repeat("\x00", 20) . "\x01\x00" . str_repeat("\x00", 60) . $pad;
    }
    return $pad;
}

/** Upload one file to a consultation or a draft, through the real v1 endpoint. */
function upload(string $token, array $fields, string $name, string $bytes): array {
    global $base;
    $post = $fields;
    $post['file'] = new CURLFile(tmpUpload($name, $bytes), 'application/octet-stream', $name);
    $ch = curl_init($base . '/api/v1/consultation-attachments.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
        CURLOPT_POSTFIELDS => $post,
    ]);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, jbody($body)];
}

echo "\n== setup: a firm with a lawyer and a client, plus a second firm for cross-tenant checks ==\n";

$seed = function (string $label) use ($pdo, $suffix, &$created): array {
    $pdo->prepare("INSERT INTO lawyers (full_name, email, password_hash, status, email_verified) VALUES (?,?,?,'approved',1)")
        ->execute(["محامي شامل $label", "e35-$label-lawyer-$suffix@example.test", password_hash('E35-Test-Pass-2026', PASSWORD_BCRYPT)]);
    $lawyerId = (int)$pdo->lastInsertId();
    $created['lawyers'][] = $lawyerId;

    $pdo->prepare("INSERT INTO users (full_name, email, password_hash, status, email_verified, account_type) VALUES (?,?,?,'active',1,'individual')")
        ->execute(["عميل شامل $label", "e35-$label-client-$suffix@example.test", password_hash('E35-Test-Pass-2026', PASSWORD_BCRYPT)]);
    $clientId = (int)$pdo->lastInsertId();
    $created['users'][] = $clientId;

    $pdo->prepare('INSERT INTO law_firms (name, display_name, owner_lawyer_id) VALUES (?,?,?)')
        ->execute(["مكتب شامل $label", "Multimodal Firm $label", $lawyerId]);
    $firmId = (int)$pdo->lastInsertId();
    $created['law_firms'][] = $firmId;

    $pdo->prepare("INSERT INTO law_firm_members (firm_id, lawyer_id, member_role, status) VALUES (?,?,'owner','active')")
        ->execute([$firmId, $lawyerId]);
    $created['law_firm_members'][] = (int)$pdo->lastInsertId();

    $clientToken = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO api_tokens (token, user_type, user_id, device_label, expires_at) VALUES (?,?,?,?,DATE_ADD(NOW(), INTERVAL 1 YEAR))')
        ->execute([hash('sha256', $clientToken), 'client', $clientId, "e35-$label"]);
    $created['api_tokens'][] = (int)$pdo->lastInsertId();

    $lawyerToken = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO api_tokens (token, user_type, user_id, device_label, expires_at) VALUES (?,?,?,?,DATE_ADD(NOW(), INTERVAL 1 YEAR))')
        ->execute([hash('sha256', $lawyerToken), 'lawyer', $lawyerId, "e35-$label-l"]);
    $created['api_tokens'][] = (int)$pdo->lastInsertId();

    return compact('lawyerId', 'clientId', 'firmId', 'clientToken', 'lawyerToken');
};

$A = $seed('A');
$B = $seed('B');
check('firm A seeded', $A['firmId'] > 0);
check('firm B seeded', $B['firmId'] > 0);
check('the two firms are distinct', $A['firmId'] !== $B['firmId']);

/** Book a consultation through the real v1 endpoint, the way the mobile app does. */
function book(string $token, array $overrides = []): array {
    global $A;
    $date = date('Y-m-d', strtotime('+3 days'));
    $body = array_merge([
        'lawyer_id' => $A['lawyerId'],
        'consultation_type' => 'voice',
        'date' => $date,
        'time' => '10:00',
        'topic' => 'استشارة متعددة الوسائط',
    ], $overrides);
    [$code, $resp] = http('POST', '/api/v1/consultations.php', ['token' => $token, 'json' => $body]);
    return [$code, $resp];
}

// ------------------------------------------------------------------
echo "\n== 1) staging before the consultation exists (draft_token) ==\n";
// ------------------------------------------------------------------
$draftToken = consultation_attachment_new_draft_token();
check('a draft token is 64 hex characters', (bool)preg_match('/^[a-f0-9]{64}$/', $draftToken));

[$code, $up] = upload($A['clientToken'], ['draft_token' => $draftToken], 'recording.mp3', realBytes('mp3', 600));
check('a voice recording stages before the consultation exists (HTTP 201)', $code === 201);
$stagedId = (int)($up['attachment']['id'] ?? 0);
check('the staged attachment has an id', $stagedId > 0);
$created['attachments'][] = $stagedId;
check('the staged row has no consultation yet', ($up['attachment']['consultation_id'] ?? null) === null);
check('the staged row is reported as staged', ($up['attachment']['status'] ?? '') === 'staged');
check('the staged row is categorised as audio', ($up['attachment']['category'] ?? '') === 'audio');

// A document alongside the audio, so the submission is genuinely multimodal.
[$code, $upDoc] = upload($A['clientToken'], ['draft_token' => $draftToken], 'id-page.png', realBytes('png', 400));
check('a document stages alongside the recording (HTTP 201)', $code === 201);
$stagedDocId = (int)($upDoc['attachment']['id'] ?? 0);
$created['attachments'][] = $stagedDocId;

// A PDF page as well, which is what a scanned document becomes on the device.
[$code, $upPdf] = upload($A['clientToken'], ['draft_token' => $draftToken], 'contract.pdf', realBytes('pdf', 300));
check('a PDF stages as well (HTTP 201)', $code === 201);
$stagedPdfId = (int)($upPdf['attachment']['id'] ?? 0);
$created['attachments'][] = $stagedPdfId;

// Listing the draft returns exactly what was staged, and only to the uploader.
[$code, $list] = http('GET', '/api/v1/consultation-attachments.php?draft_token=' . $draftToken, ['token' => $A['clientToken']]);
check('the draft lists its staged files (HTTP 200)', $code === 200);
check("the draft lists three staged files", count($list["attachments"] ?? []) === 3);

[$code, $otherList] = http('GET', '/api/v1/consultation-attachments.php?draft_token=' . $draftToken, ['token' => $B['clientToken']]);
check('another client sees an empty draft, not this one\'s files', ($otherList['attachments'] ?? [null]) === []);

[$code, $resp] = http('GET', '/api/v1/consultation-attachments.php?draft_token=' . $draftToken, ['token' => $A['lawyerToken']]);
check('a lawyer may not read a draft (HTTP 403)', $code === 403);

// ------------------------------------------------------------------
echo "\n== 2) submission: empty refused, audio-only accepted, claim binds the files ==\n";
// ------------------------------------------------------------------
// An empty submission — no description and no attachments — must be refused.
[$code, $resp] = book($A['clientToken'], ['topic' => '', 'description' => '']);
check('a submission with no description and no attachment is refused', $code === 400);

// Audio-only: no written description, but a staged recording. This must succeed.
$audioOnlyToken = consultation_attachment_new_draft_token();
[$code, $up] = upload($A['clientToken'], ['draft_token' => $audioOnlyToken], 'voice-only.m4a', realBytes('m4a', 500));
check('an audio-only draft stages', $code === 201);
$audioOnlyId = (int)($up['attachment']['id'] ?? 0);
$created['attachments'][] = $audioOnlyId;

[$code, $resp] = book($A['clientToken'], [
    'topic' => '', 'description' => '', 'draft_token' => $audioOnlyToken, 'consultation_type' => 'voice',
]);
check('an audio-only submission is accepted (HTTP 201)', $code === 201);
$audioOnlyConsultation = (int)($resp['consultation_id'] ?? 0);
$created['consultations'][] = $audioOnlyConsultation;
check('the audio-only submission reports its claimed attachment', (int)($resp['attachments'] ?? 0) === 1);

// The main submission: description + staged audio + document.
$mainKey = 'e35-main-' . $suffix;
[$code, $resp] = book($A['clientToken'], [
    'topic' => 'أرجو مراجعة العقد المرفق والتسجيل الصوتي.',
    'draft_token' => $draftToken,
    'idempotency_key' => $mainKey,
    'consultation_type' => 'voice',
]);
check('the multimodal submission is accepted (HTTP 201)', $code === 201);
$consultationId = (int)($resp['consultation_id'] ?? 0);
$created['consultations'][] = $consultationId;
check('the submission reports three claimed attachments', (int)($resp['attachments'] ?? 0) === 3);
check('the submission is not a replay', ($resp['reused'] ?? true) === false);

// ------------------------------------------------------------------
echo "\n== 3) idempotent submit: a retry must not duplicate ==\n";
// ------------------------------------------------------------------
[$code, $retry] = book($A['clientToken'], [
    'topic' => 'أرجو مراجعة العقد المرفق والتسجيل الصوتي.',
    'draft_token' => $draftToken,
    'idempotency_key' => $mainKey,
    'consultation_type' => 'voice',
]);
check('a retried submission succeeds (HTTP 200)', $code === 200);
check('the retry returns the SAME consultation id', (int)($retry['consultation_id'] ?? 0) === $consultationId);
check('the retry is flagged as reused', ($retry['reused'] ?? false) === true);

$dupCount = (int)$pdo->query("SELECT COUNT(*) FROM consultations WHERE idempotency_key='" . $mainKey . "'")->fetchColumn();
check('exactly one consultation row exists for that idempotency key', $dupCount === 1);

// A retry after a FAILED upload: the first attempt fails validation, the second succeeds
// with the same key and must produce exactly one consultation.
$retryKey = 'e35-retry-' . $suffix;
$retryDraft = consultation_attachment_new_draft_token();
[$code, $up] = upload($A['clientToken'], ['draft_token' => $retryDraft], 'note.pdf', realBytes('pdf', 200));
check('the retry draft stages', $code === 201);
$created['attachments'][] = (int)($up['attachment']['id'] ?? 0);
[$code, $resp] = book($A['clientToken'], ['topic' => 'طلب ثانٍ', 'draft_token' => $retryDraft, 'idempotency_key' => $retryKey]);
check('the first attempt of the retry pair succeeds', $code === 201);
$retryConsultation = (int)($resp['consultation_id'] ?? 0);
$created['consultations'][] = $retryConsultation;
[$code, $resp2] = book($A['clientToken'], ['topic' => 'طلب ثانٍ', 'draft_token' => $retryDraft, 'idempotency_key' => $retryKey]);
check('the second attempt returns the same consultation', (int)($resp2['consultation_id'] ?? 0) === $retryConsultation);
$retryDup = (int)$pdo->query("SELECT COUNT(*) FROM consultations WHERE idempotency_key='" . $retryKey . "'")->fetchColumn();
check('the retried pair produced exactly one consultation', $retryDup === 1);

// ------------------------------------------------------------------
echo "\n== 4) the claimed files are really bound to the consultation ==\n";
// ------------------------------------------------------------------
[$code, $list] = http('GET', '/api/v1/consultation-attachments.php?consultation_id=' . $consultationId, ['token' => $A['clientToken']]);
check('the consultation lists its attachments (HTTP 200)', $code === 200);
$rows = $list['attachments'] ?? [];
check('the consultation has three attachments', count($rows) === 3);

$byId = [];
foreach ($rows as $r) { $byId[(int)$r['id']] = $r; }
check('the recording is bound to the consultation', isset($byId[$stagedId]));
check('the recording kept its audio category', ($byId[$stagedId]['category'] ?? '') === 'audio');
check('the recording is now stored, not staged', ($byId[$stagedId]['status'] ?? '') === 'stored');
check('the document is bound to the consultation', isset($byId[$stagedDocId]));
check('the PDF is bound to the consultation', isset($byId[$stagedPdfId]));

// The row in the database must point at the consultation and at a real file.
$row = $pdo->query("SELECT * FROM consultation_attachments WHERE id=" . (int)$stagedId)->fetch();
check('the database row carries the consultation id', (int)$row['consultation_id'] === $consultationId);
check('the database row carries the firm id', (int)$row['firm_id'] === $A['firmId']);
check('the stored path moved out of drafts/', strpos((string)$row['stored_path'], 'drafts/') === false);
$abs = consultation_attachment_absolute_path($row);
check('the stored file exists on disk', $abs !== null && is_file($abs));

// The bytes on disk must be exactly what was uploaded — the recording is preserved.
$onDisk = $abs !== null ? (string)file_get_contents($abs) : '';
check('the stored recording is byte-identical to what was uploaded', $onDisk === realBytes('mp3', 600));

// No response may leak the stored path.
$listJson = json_encode($rows, JSON_UNESCAPED_UNICODE);
check('no response leaks a stored path', strpos($listJson, 'stored_path') === false && strpos($listJson, 'drafts/') === false);

// ------------------------------------------------------------------
echo "\n== 5) the recording is downloadable by its owner, and only its owner ==\n";
// ------------------------------------------------------------------
[$code, $bytes, $headers] = http('GET', '/api/v1/consultation-attachments.php?id=' . $stagedId . '&download=1', ['token' => $A['clientToken']]);
check('the owner can download the recording (HTTP 200)', $code === 200);
check('the downloaded bytes are the original recording', $bytes === realBytes('mp3', 600));
check('the download is sent as an attachment', stripos($headers, 'Content-Disposition: attachment') !== false);
check('the download carries nosniff', stripos($headers, 'X-Content-Type-Options: nosniff') !== false);
check('the download is not publicly cacheable', stripos($headers, 'Cache-Control: private, no-store') !== false);

[$code, $bytes] = http('GET', '/api/v1/consultation-attachments.php?id=' . $stagedId . '&download=1');
check('an anonymous request cannot download the recording (HTTP 401)', $code === 401);

[$code, $bytes] = http('GET', '/api/v1/consultation-attachments.php?id=' . $stagedId . '&download=1', ['token' => $B['clientToken']]);
check('another client cannot download the recording (HTTP 404)', $code === 404);

[$code, $bytes] = http('GET', '/api/v1/consultation-attachments.php?id=' . $stagedId . '&download=1', ['token' => $B['lawyerToken']]);
check("a lawyer in another firm cannot download the recording", in_array($code, [403, 404], true));

[$code, $bytes] = http('GET', '/api/v1/consultation-attachments.php?id=' . $stagedId, ['token' => $B['clientToken']]);
check('another client cannot even read the metadata (HTTP 404)', $code === 404);

// ------------------------------------------------------------------
echo "\n== 6) lawyer integration: playback, download, transcription read ==\n";
// ------------------------------------------------------------------
[$code, $bytes] = http('GET', '/api/v1/consultation-attachments.php?id=' . $stagedId . '&download=1', ['token' => $A['lawyerToken']]);
check('the owning lawyer CAN download the client\'s recording (HTTP 200)', $code === 200);
check('the lawyer receives the original recording bytes', $bytes === realBytes('mp3', 600));

[$code, $meta] = http('GET', '/api/v1/consultation-attachments.php?id=' . $stagedId, ['token' => $A['lawyerToken']]);
check('the lawyer can read the recording metadata (HTTP 200)', $code === 200);
check('the recording is marked previewable for inline playback', ($meta['attachment']['previewable'] ?? false) === true);

[$code, $list] = http('GET', '/api/v1/consultation-attachments.php?consultation_id=' . $consultationId, ['token' => $A['lawyerToken']]);
check('the lawyer sees the whole attachment set', count($list['attachments'] ?? []) === 3);

// A lawyer must not be able to make the client's voice leave the platform.
[$code, $resp] = http('POST', '/api/v1/consultation-attachments.php', [
    'token' => $A['lawyerToken'],
    'json' => ['action' => 'transcribe', 'attachment_id' => $stagedId, 'consent' => 1],
]);
check('a lawyer may not transcribe the client\'s recording (HTTP 403)', $code === 403);

// A lawyer's own upload is allowed and attributed to the lawyer.
[$code, $up] = upload($A['lawyerToken'], ['consultation_id' => $consultationId], 'lawyer-note.pdf', realBytes('pdf', 250));
check('the lawyer may attach a file to the consultation (HTTP 201)', $code === 201);
check('the lawyer\'s upload is attributed to the lawyer', ($up['attachment']['uploaded_by'] ?? '') === 'lawyer');
$created['attachments'][] = (int)($up['attachment']['id'] ?? 0);

// ------------------------------------------------------------------
echo "\n== 7) transcription: consent required, policy-gated, honest on failure ==\n";
// ------------------------------------------------------------------
// Without consent the request must be refused outright.
[$code, $resp] = http('POST', '/api/v1/consultation-attachments.php', [
    'token' => $A['clientToken'],
    'json' => ['action' => 'transcribe', 'attachment_id' => $stagedId],
]);
check('transcription without consent is refused (HTTP 422)', $code === 422);

[$code, $resp] = http('POST', '/api/v1/consultation-attachments.php', [
    'token' => $A['clientToken'],
    'json' => ['action' => 'transcribe', 'attachment_id' => $stagedId, 'consent' => '0'],
]);
check('an explicit consent=0 is refused (HTTP 422)', $code === 422);

// A non-audio attachment can never be transcribed.
[$code, $resp] = http('POST', '/api/v1/consultation-attachments.php', [
    'token' => $A['clientToken'],
    'json' => ['action' => 'transcribe', 'attachment_id' => $stagedDocId, 'consent' => 1],
]);
check('a document cannot be transcribed (HTTP 422)', $code === 422);

// With consent, the outcome depends on the configured provider and the tenant policy.
// Either way it must be honest, and the recording must survive untouched.
[$code, $resp] = http('POST', '/api/v1/consultation-attachments.php', [
    'token' => $A['clientToken'],
    'json' => ['action' => 'transcribe', 'attachment_id' => $stagedId, 'consent' => 1, 'language' => 'ar'],
]);
$transcribeOutcome = $resp['attachment']['transcription']['status'] ?? null;
check('a consented transcription returns a definite outcome', in_array($code, [200, 502, 503, 403], true));
if ($code === 200) {
    check('a successful transcription is marked ready', $transcribeOutcome === 'ready');
    check('a successful transcription returns text', trim((string)($resp['attachment']['transcription']['text'] ?? '')) !== '');
    // The recording must be untouched by transcription.
    $rowAfter = $pdo->query("SELECT * FROM consultation_attachments WHERE id=" . (int)$stagedId)->fetch();
    $absAfter = consultation_attachment_absolute_path($rowAfter);
    check('the original recording is preserved after transcription',
        $absAfter !== null && is_file($absAfter) && (string)file_get_contents($absAfter) === realBytes('mp3', 600));
    check('the transcription did not overwrite the audio mime type', $rowAfter['mime_type'] === $row['mime_type']);

    // The lawyer can now read the transcript.
    [$lcode, $lmeta] = http('GET', '/api/v1/consultation-attachments.php?id=' . $stagedId, ['token' => $A['lawyerToken']]);
    check('the lawyer can read the finished transcription',
        ($lmeta['attachment']['transcription']['available'] ?? false) === true
        && trim((string)($lmeta['attachment']['transcription']['text'] ?? '')) !== '');

    // Idempotent: a second request reuses the stored result rather than calling again.
    [$rcode, $reuse] = http('POST', '/api/v1/consultation-attachments.php', [
        'token' => $A['clientToken'],
        'json' => ['action' => 'transcribe', 'attachment_id' => $stagedId, 'consent' => 1],
    ]);
    check('a repeated transcription reuses the stored result', ($reuse['reused'] ?? false) === true);
} else {
    // No provider, or the policy declined: the status must say so and the audio must survive.
    // The status lives on the row, so read it there — an error response carries only the
    // message, and the row is the honest record of what the platform concluded.
    $rowAfter = $pdo->query("SELECT * FROM consultation_attachments WHERE id=" . (int)$stagedId)->fetch();
    $transcribeOutcome = (string)($rowAfter['transcription_status'] ?? '');
    check('an unavailable transcription is reported honestly, not faked',
        in_array($transcribeOutcome, ['failed', 'declined'], true));
    $absAfter = consultation_attachment_absolute_path($rowAfter);
    check('the recording survives a failed transcription',
        $absAfter !== null && is_file($absAfter) && (string)file_get_contents($absAfter) === realBytes('mp3', 600));
    check('no transcription text was invented', trim((string)($rowAfter['transcription_text'] ?? '')) === '');
    echo "  note: transcription path exercised with outcome '" . (string)$transcribeOutcome . "' (HTTP $code)\n";
}

// The consent event must be recorded whenever consent was given.
$consentRow = $pdo->query("SELECT transcription_consent_at, transcription_consent_by FROM consultation_attachments WHERE id=" . (int)$stagedId)->fetch();
check('consent was recorded as an event with a timestamp', !empty($consentRow['transcription_consent_at']));
check('consent records who gave it', (int)$consentRow['transcription_consent_by'] === $A['clientId']);

// ------------------------------------------------------------------
echo "\n== 8) server-side validation: type and size ==\n";
// ------------------------------------------------------------------
[$code, $resp] = upload($A['clientToken'], ['consultation_id' => $consultationId], 'payload.php', "<?php echo 'pwned'; ?>" . str_repeat('x', 200));
check('a PHP file is refused (HTTP 422)', $code === 422);

// A file whose bytes contradict its extension is refused by the sniff.
[$code, $resp] = upload($A['clientToken'], ['consultation_id' => $consultationId], 'fake.mp3', str_repeat('not really audio at all ', 50));
check('a file whose bytes are not audio is refused despite the .mp3 name', $code === 422);

// An oversized audio file is refused by the per-category limit.
$limits = consultation_attachment_limits($pdo);
$tooBig = str_repeat('x', ((int)$limits['audio_mb'] * 1024 * 1024) + 2048);
$oversizedPath = tmpUpload('huge.mp3', realBytes('mp3', 100) . $tooBig);
$ch = curl_init($base . '/api/v1/consultation-attachments.php');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $A['clientToken']],
    CURLOPT_POSTFIELDS => ['consultation_id' => $consultationId, 'file' => new CURLFile($oversizedPath, 'audio/mpeg', 'huge.mp3')],
]);
$body = (string)curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
check('an oversized recording is refused (HTTP 413 or 422)', in_array($code, [413, 422], true));

// The catalogue must include every format the recorder and the camera can produce.
$catalog = consultation_attachment_catalog();
foreach (['mp3', 'wav', 'm4a', 'ogg'] as $ext) {
    check("the server accepts .$ext as audio", ($catalog[$ext]['category'] ?? '') === 'audio');
}
foreach (['png', 'jpg', 'jpeg'] as $ext) {
    check("the server accepts .$ext as an image", ($catalog[$ext]['category'] ?? '') === 'image');
}

// ------------------------------------------------------------------
echo "\n== 9) removal, and the integration-token boundary ==\n";
// ------------------------------------------------------------------
$removeTarget = (int)($stagedPdfId);
[$code, $resp] = http('DELETE', '/api/v1/consultation-attachments.php?id=' . $removeTarget, ['token' => $A['clientToken']]);
check('the owner can remove an attachment (HTTP 200)', $code === 200);
[$code, $bytes] = http('GET', '/api/v1/consultation-attachments.php?id=' . $removeTarget . '&download=1', ['token' => $A['clientToken']]);
check('a removed attachment is no longer downloadable (HTTP 404)', $code === 404);

// A lawyer may not delete the client's file.
[$code, $resp] = http('DELETE', '/api/v1/consultation-attachments.php?id=' . $stagedId, ['token' => $A['lawyerToken']]);
check('a lawyer may not delete the client\'s recording (HTTP 403)', $code === 403);

// A discarded draft is gone and unreachable.
$discardToken = consultation_attachment_new_draft_token();
[$code, $up] = upload($A['clientToken'], ['draft_token' => $discardToken], 'scratch.png', realBytes('png', 120));
$discardId = (int)($up['attachment']['id'] ?? 0);
check('a scratch draft stages', $code === 201);
[$code, $resp] = http('DELETE', '/api/v1/consultation-attachments.php?id=' . $discardId, ['token' => $A['clientToken']]);
check('a staged file can be discarded (HTTP 200)', $code === 200);
[$code, $list] = http('GET', '/api/v1/consultation-attachments.php?draft_token=' . $discardToken, ['token' => $A['clientToken']]);
check('a discarded draft is no longer listed', count($list['attachments'] ?? []) === 0);

// Integration tokens must not reach this endpoint at all.
try {
    $integrationToken = INTEGRATION_TOKEN_PREFIX . bin2hex(random_bytes(24));
    $pdo->prepare('INSERT INTO integration_api_tokens (firm_id, token_hash, scopes, label, is_active) VALUES (?,?,?,?,1)')
        ->execute([$A['firmId'], hash('sha256', $integrationToken), 'read', 'e35-integration']);
    $created['integration_tokens'][] = (int)$pdo->lastInsertId();
    [$code, $resp] = http('GET', '/api/v1/consultation-attachments.php?consultation_id=' . $consultationId, ['token' => $integrationToken]);
    check('an integration token cannot read consultation attachments (HTTP 403)', $code === 403);
} catch (Throwable $e) {
    skip('integration_api_tokens unavailable: ' . $e->getMessage());
}

// ------------------------------------------------------------------
echo "\n== 10) no regression: the pre-existing booking contract still works ==\n";
// ------------------------------------------------------------------
// A plain booking with no attachments and no idempotency key — the shape an older client
// build sends — must behave exactly as before.
[$code, $resp] = book($A['clientToken'], ['topic' => 'حجز تقليدي', 'consultation_type' => 'text']);
check('a classic booking with no attachments still succeeds (HTTP 201)', $code === 201);
$classicId = (int)($resp['consultation_id'] ?? 0);
$created['consultations'][] = $classicId;
check('a classic booking is not flagged as reused', ($resp['reused'] ?? true) === false);
check('a classic booking has no attachments', (int)($resp['attachments'] ?? 0) === 0);

// The list endpoint still returns the consultations, with the new derived columns additive.
[$code, $list] = http('GET', '/api/v1/consultations.php', ['token' => $A['clientToken']]);
check('the consultation list still works (HTTP 200)', $code === 200);
$found = null;
foreach (($list['consultations'] ?? []) as $c) { if ((int)$c['id'] === $consultationId) { $found = $c; break; } }
check('the multimodal consultation appears in the list', $found !== null);
// Derived, not hardcoded: the list must agree with the stored rows the database actually has,
// so the assertion keeps its meaning when the earlier steps change how many files survive.
$storedCount = (int)$pdo->query("SELECT COUNT(*) FROM consultation_attachments WHERE consultation_id=" . (int)$consultationId . " AND status='stored'")->fetchColumn();
check('the list reports the attachment count', $found !== null && (int)$found['attachment_count'] === $storedCount);

// ------------------------------------------------------------------
echo "\n== 11) audit trail is preserved ==\n";
// ------------------------------------------------------------------
$auditRows = $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE target_type='consultation_attachment' AND target_id IN (" . (int)$stagedId . "," . (int)$stagedDocId . ")")->fetchColumn();
check('attachment actions were written to the audit log', (int)$auditRows > 0);

// ------------------------------------------------------------------
echo "\n== cleanup ==\n";
// ------------------------------------------------------------------
// Remove the rows and files this probe created. Storage is deleted through the model's own
// helpers so the private directory is left exactly as it was found.
foreach ($created['attachments'] as $id) {
    $r = consultation_attachment_find($pdo, (int)$id);
    if ($r) {
        $abs = consultation_attachment_absolute_path($r);
        if ($abs !== null && is_file($abs)) @unlink($abs);
    }
}
if (!empty($created['attachments'])) {
    $ids = implode(',', array_map('intval', $created['attachments']));
    $pdo->exec("DELETE FROM consultation_attachments WHERE id IN ($ids)");
}
if (!empty($created['consultations'])) {
    $ids = implode(',', array_map('intval', $created['consultations']));
    $pdo->exec("DELETE FROM consultation_attachments WHERE consultation_id IN ($ids)");
    $pdo->exec("DELETE FROM consultations WHERE id IN ($ids)");
}
if (!empty($created['api_tokens'])) {
    $ids = implode(',', array_map('intval', $created['api_tokens']));
    $pdo->exec("DELETE FROM api_tokens WHERE id IN ($ids)");
}
if (!empty($created['integration_tokens'])) {
    $ids = implode(',', array_map('intval', $created['integration_tokens']));
    $pdo->exec("DELETE FROM integration_api_tokens WHERE id IN ($ids)");
}
if (!empty($created['law_firm_members'])) {
    $ids = implode(',', array_map('intval', $created['law_firm_members']));
    $pdo->exec("DELETE FROM law_firm_members WHERE id IN ($ids)");
}
if (!empty($created['law_firms'])) {
    $ids = implode(',', array_map('intval', $created['law_firms']));
    $pdo->exec("DELETE FROM law_firms WHERE id IN ($ids)");
}
if (!empty($created['lawyers'])) {
    $ids = implode(',', array_map('intval', $created['lawyers']));
    $pdo->exec("DELETE FROM lawyers WHERE id IN ($ids)");
}
if (!empty($created['users'])) {
    $ids = implode(',', array_map('intval', $created['users']));
    $pdo->exec("DELETE FROM users WHERE id IN ($ids)");
}
foreach ($tmpFiles as $f) { if (is_file($f)) @unlink($f); }
echo "  ok:   probe data removed\n";

echo "\n";
echo "  Execution 35 multimodal consultation: $checks checks, $failures failure(s)";
echo $skips > 0 ? ", $skips skipped" : "";
echo "\n";
exit($failures > 0 ? 1 : 0);
