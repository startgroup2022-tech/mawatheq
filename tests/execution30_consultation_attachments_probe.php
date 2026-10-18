<?php
/**
 * Execution 30 — consultation attachments, live end-to-end probe.
 *
 * Covers every scenario the brief lists, against the real application over HTTP on
 * 127.0.0.1:8080 and the real private storage directory:
 *
 *   1. a consultation with no attachments (the regression case: booking must still work)
 *   2. a consultation with multiple attachments
 *   3. every allowed file type (TXT, DOC, DOCX, PDF, MP3, WAV, M4A, OGG, MP4, MOV, WEBM)
 *   4. an oversized file, per category
 *   5. an unsupported extension
 *   6. a file whose MIME type disagrees with its extension
 *   7. an unauthorized download (anonymous, and a different client)
 *   8. a cross-tenant download attempt (a lawyer in another firm)
 *   9. a deleted attachment, and a row whose file is missing
 *  10. mobile API compatibility (Bearer token: list, upload, download)
 *  11. interrupted / partial uploads, and orphan cleanup
 *  12. the existing consultation booking flow, unchanged
 *
 * Requires the app running on 127.0.0.1:8080 (see tests/setup_test_env.sh).
 * Run: php tests/execution30_consultation_attachments_probe.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/tenant.php';
require_once __DIR__ . '/../includes/consultation_attachments.php';

$base = 'http://127.0.0.1:8080';
$failures = 0;
$checks = 0;
function check(string $label, bool $ok): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) { $failures++; echo "  FAIL: $label\n"; } else { echo "  ok:   $label\n"; }
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
    if (!empty($opts['jar'])) { $cfg[CURLOPT_COOKIEJAR] = $opts['jar']; $cfg[CURLOPT_COOKIEFILE] = $opts['jar']; }
    if (!empty($opts['token'])) { $cfg[CURLOPT_HTTPHEADER] = ['Authorization: Bearer ' . $opts['token']]; }
    if (array_key_exists('post', $opts)) { $cfg[CURLOPT_POSTFIELDS] = $opts['post']; }
    if (array_key_exists('body', $opts)) { $cfg[CURLOPT_POSTFIELDS] = $opts['body']; }
    curl_setopt_array($ch, $cfg);
    $raw = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [$code, substr($raw, $headerSize), substr($raw, 0, $headerSize)];
}

function csrf_from(string $html): string {
    return preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m) ? $m[1] : '';
}

$suffix = bin2hex(random_bytes(4));
$created = ['users' => [], 'lawyers' => [], 'law_firms' => [], 'law_firm_members' => [],
            'consultations' => [], 'api_tokens' => [], 'attachments' => [], 'admins' => []];
$tmpFiles = [];

/** Write a real file to a temp path so CURLFile can post it. */
function tmpUpload(string $name, string $contents): string {
    global $tmpFiles;
    $path = tempnam(sys_get_temp_dir(), 'e30') . '-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
    file_put_contents($path, $contents);
    $tmpFiles[] = $path;
    return $path;
}

/* ------------------------------------------------------------------
 * Minimal but REAL files of each allowed type. The validation reads the actual bytes,
 * so a placeholder that only carries the right extension would be rejected — which is
 * exactly the behaviour under test. These carry the correct container signature.
 * ------------------------------------------------------------------ */
function sampleBytes(string $ext, int $padBytes = 0): string {
    $pad = $padBytes > 0 ? str_repeat('A', $padBytes) : '';
    switch ($ext) {
        case 'txt':
            return "تقرير القضية\nهذا نص تجريبي.\n" . $pad;
        case 'pdf':
            return "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n" . $pad;
        case 'doc':
            // OLE2 compound document header.
            return "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\x00", 512) . $pad;
        case 'docx':
            // A real (minimal) ZIP carrying the OOXML marker.
            $path = tempnam(sys_get_temp_dir(), 'e30zip');
            $zip = new ZipArchive();
            $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types/>');
            $zip->addFromString('word/document.xml', '<w:document/>');
            $zip->close();
            $bytes = (string)file_get_contents($path);
            unlink($path);
            return $bytes . $pad;
        case 'mp3':
            // A real ID3v2 header followed by an MPEG-1 Layer III frame, which is what makes
            // finfo report audio/mpeg rather than text/plain.
            return "ID3\x03\x00\x00\x00\x00\x00\x21" . str_repeat("\x00", 33)
                 . "\xFF\xFB\x90\x00" . str_repeat("\x00", 400) . $pad;
        case 'wav':
            return 'RIFF' . pack('V', 36) . 'WAVEfmt ' . str_repeat("\x00", 100) . $pad;
        case 'm4a':
            return "\x00\x00\x00\x20" . 'ftypM4A ' . str_repeat("\x00", 100) . $pad;
        case 'ogg':
            // An Ogg page carrying a Vorbis identification header.
            return "OggS\x00\x02" . str_repeat("\x00", 20) . "\x01\x1E" . "\x01vorbis"
                 . str_repeat("\x00", 60) . "OggS\x00\x00" . str_repeat("\x00", 20) . "\x01\x00" . str_repeat("\x00", 60) . $pad;
        case 'mp4':
            return "\x00\x00\x00\x20" . 'ftypisom' . str_repeat("\x00", 100) . $pad;
        case 'mov':
            return "\x00\x00\x00\x20" . 'ftypqt  ' . str_repeat("\x00", 100) . $pad;
        case 'webm':
            // EBML header with a Matroska DocType, as a real WebM file begins.
            return "\x1A\x45\xDF\xA3\x9F\x42\x86\x81\x01\x42\xF7\x81\x01\x42\xF2\x81\x04\x42\xF3\x81\x08\x42\x82\x88matroska\x42\x87\x81\x02\x42\x85\x81\x02"
                 . str_repeat("\x00", 200) . $pad;
    }
    return $pad;
}

echo "\n== setup: two tenants, each with a lawyer, a client and a consultation ==\n";

$seed = function (string $label) use ($pdo, $suffix, &$created): array {
    $pdo->prepare("INSERT INTO lawyers (full_name, email, password_hash, status, email_verified) VALUES (?,?,?,'approved',1)")
        ->execute(["محامي مرفقات $label", "e30-$label-lawyer-$suffix@example.test", password_hash('E30-Test-Pass-2026', PASSWORD_BCRYPT)]);
    $lawyerId = (int)$pdo->lastInsertId();
    $created['lawyers'][] = $lawyerId;

    $pdo->prepare("INSERT INTO users (full_name, email, password_hash, status, email_verified, account_type) VALUES (?,?,?,'active',1,'individual')")
        ->execute(["عميل مرفقات $label", "e30-$label-client-$suffix@example.test", password_hash('E30-Test-Pass-2026', PASSWORD_BCRYPT)]);
    $clientId = (int)$pdo->lastInsertId();
    $created['users'][] = $clientId;

    $pdo->prepare('INSERT INTO law_firms (name, display_name, owner_lawyer_id) VALUES (?,?,?)')
        ->execute(["مكتب مرفقات $label", "Attach Firm $label", $lawyerId]);
    $firmId = (int)$pdo->lastInsertId();
    $created['law_firms'][] = $firmId;

    $pdo->prepare("INSERT INTO law_firm_members (firm_id, lawyer_id, member_role, status) VALUES (?,?,'owner','active')")
        ->execute([$firmId, $lawyerId]);
    $created['law_firm_members'][] = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO consultations (user_id, lawyer_id, firm_id, scheduled_at, topic, status) VALUES (?,?,?,DATE_ADD(NOW(), INTERVAL 1 DAY),?,'pending')")
        ->execute([$clientId, $lawyerId, $firmId, "استشارة مرفقات $label"]);
    $consultationId = (int)$pdo->lastInsertId();
    $created['consultations'][] = $consultationId;

    // A second consultation for the same client. The file-type matrix needs eleven uploads
    // but the per-consultation cap is ten, so the overflow goes here. Keeping the two apart
    // means the matrix tests acceptance and the cap test tests the cap, instead of one
    // masking the other.
    $pdo->prepare("INSERT INTO consultations (user_id, lawyer_id, firm_id, scheduled_at, topic, status) VALUES (?,?,?,DATE_ADD(NOW(), INTERVAL 2 DAY),?,'pending')")
        ->execute([$clientId, $lawyerId, $firmId, "استشارة مرفقات إضافية $label"]);
    $secondConsultationId = (int)$pdo->lastInsertId();
    $created['consultations'][] = $secondConsultationId;

    $clientToken = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO api_tokens (token, user_type, user_id, device_label, expires_at) VALUES (?,?,?,?,DATE_ADD(NOW(), INTERVAL 1 YEAR))')
        ->execute([hash('sha256', $clientToken), 'client', $clientId, "e30-$label"]);
    $created['api_tokens'][] = (int)$pdo->lastInsertId();

    $lawyerToken = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO api_tokens (token, user_type, user_id, device_label, expires_at) VALUES (?,?,?,?,DATE_ADD(NOW(), INTERVAL 1 YEAR))')
        ->execute([hash('sha256', $lawyerToken), 'lawyer', $lawyerId, "e30-$label-l"]);
    $created['api_tokens'][] = (int)$pdo->lastInsertId();

    return compact('lawyerId', 'clientId', 'firmId', 'consultationId', 'secondConsultationId', 'clientToken', 'lawyerToken');
};

$A = $seed('A');
$B = $seed('B');
check('tenant A seeded', $A['consultationId'] > 0);
check('tenant B seeded', $B['consultationId'] > 0);
check('tenants are distinct', $A['firmId'] !== $B['firmId']);

// Log in the two clients through the real form, keeping sessions in cookie jars.
$login = function (string $email, string $jar) use ($base): void {
    $ch = curl_init($base . '/login.php');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    $body = (string)curl_exec($ch);
    curl_close($ch);
    $token = csrf_from($body);
    $ch = curl_init($base . '/login.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['csrf_token' => $token, 'email' => $email, 'password' => 'E30-Test-Pass-2026'],
    ]);
    curl_exec($ch);
    curl_close($ch);
};

$jarA = tempnam(sys_get_temp_dir(), 'e30a');
$jarB = tempnam(sys_get_temp_dir(), 'e30b');
$login("e30-A-client-$suffix@example.test", $jarA);
$login("e30-B-client-$suffix@example.test", $jarB);

/** Session CSRF token for a logged-in client. */
$sessionCsrf = function (string $jar) use ($base): string {
    $ch = curl_init($base . '/booking.php?lawyer_id=1');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    $body = (string)curl_exec($ch);
    curl_close($ch);
    return csrf_from($body);
};

$csrfA = $sessionCsrf($jarA);
check('client A has a session CSRF token', $csrfA !== '');

/** Upload one file through the web endpoint (action=attach) and return [code, decoded]. */
$webAttach = function (string $jar, string $csrf, int $consultationId, string $filename, string $bytes) use ($base): array {
    $path = tmpUpload($filename, $bytes);
    $ch = curl_init($base . '/api/consultation_attachments.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'csrf_token' => $csrf, 'action' => 'attach',
            'consultation_id' => (string)$consultationId,
            'file' => new CURLFile($path, 'application/octet-stream', $filename),
        ],
    ]);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode($body, true) ?: [], $body];
};

/** Download an attachment as a client session. Returns [code, body, headers]. */
$download = function (string $jar, int $attachmentId) use ($base): array {
    $ch = curl_init($base . '/download-consultation-attachment.php?id=' . $attachmentId);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
    ]);
    $raw = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [$code, substr($raw, $hs), substr($raw, 0, $hs)];
};

$attachmentIds = [];

echo "\n== 1. every allowed file type is accepted ==\n";
$allTypes = ['txt' => 'document', 'doc' => 'document', 'docx' => 'document', 'pdf' => 'document',
             'mp3' => 'audio', 'wav' => 'audio', 'm4a' => 'audio', 'ogg' => 'audio',
             'mp4' => 'video', 'mov' => 'video', 'webm' => 'video'];
$attachmentIds = [];
$matrixIndex = 0;
foreach ($allTypes as $ext => $category) {
    // The first ten go to the primary consultation, the eleventh to the second, because the
    // per-consultation cap is ten. Both belong to the same client.
    $target = $matrixIndex < 10 ? $A['consultationId'] : $A['secondConsultationId'];
    $matrixIndex++;
    $name = "ملف-اختبار-$suffix.$ext";
    [$code, $data, $raw] = $webAttach($jarA, $csrfA, $target, $name, sampleBytes($ext));
    $ok = $code === 201 && !empty($data['ok']);
    check("$ext accepted as $category (HTTP $code)", $ok);
    if ($ok) {
        $attachmentIds[$ext] = (int)$data['file']['id'];
        check("$ext stored with the right category", ($data['file']['category'] ?? '') === $category);
        check("$ext kept its original name", ($data['file']['name'] ?? '') === $name);
    } else {
        echo "        -> " . substr((string)($data['error'] ?? $raw), 0, 160) . "\n";
    }
}
$created['attachments'] = array_values($attachmentIds);
check('all eleven allowed types stored', count($attachmentIds) === 11);

// The cap must hold on a full consultation, and must be enforced by the server rather than
// the browser. The primary consultation now holds exactly ten.
$maxFiles = consultation_attachment_limits($pdo)['max_files'];
$capStmt = $pdo->prepare('SELECT COUNT(*) FROM consultation_attachments WHERE consultation_id=? AND status=\'stored\'');
$capStmt->execute([$A['consultationId']]);
$storedCount = (int)$capStmt->fetchColumn();
check("the primary consultation is at the cap ($storedCount/$maxFiles)", $storedCount === $maxFiles);
[$code, $data] = $webAttach($jarA, $csrfA, $A['consultationId'], "over-cap-$suffix.pdf", sampleBytes('pdf'));
check("an eleventh file on the same consultation is refused (HTTP $code)", $code !== 201);
check('the cap refusal names the limit', str_contains((string)($data['error'] ?? ''), (string)$maxFiles));

echo "\n== 2. an unsupported extension is refused ==\n";
[$code, $data] = $webAttach($jarA, $csrfA, $A['consultationId'], 'script.php', "<?php echo 'x'; ?>");
check("a .php upload is refused (HTTP $code)", $code !== 201);
check('the refusal is explained', !empty($data['error']));

[$code, $data] = $webAttach($jarA, $csrfA, $A['consultationId'], 'archive.zip', "PK\x03\x04" . str_repeat("\x00", 200));
check("a .zip upload is refused (HTTP $code)", $code !== 201);

echo "\n== 3. a MIME/extension mismatch is refused ==\n";
// A PNG body with a .pdf extension: the extension is allowed, but the bytes and the
// detected type both disagree with it.
$png = "\x89PNG\r\n\x1A\n" . str_repeat("\x00", 100);
[$code, $data] = $webAttach($jarA, $csrfA, $A['consultationId'], 'notreally.pdf', $png);
check("a PNG body named .pdf is refused (HTTP $code)", $code !== 201);

// A PDF body named .txt: allowed extension, but the content is not text.
[$code, $data] = $webAttach($jarA, $csrfA, $A['consultationId'], 'notreally.txt', sampleBytes('pdf'));
check("a PDF body named .txt is refused (HTTP $code)", $code !== 201);

echo "\n== 4. a polyglot (a valid PDF that is also PHP) is refused ==\n";
$polyglot = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n<?php system(\$_GET['c']); ?>\n%%EOF\n";
[$code, $data] = $webAttach($jarA, $csrfA, $A['consultationId'], 'polyglot.pdf', $polyglot);
check("a PDF carrying a PHP tag is refused (HTTP $code)", $code !== 201);
check('the polyglot was not stored', $code !== 201 && empty($data['file']['id']));

echo "\n== 5. an oversized file is refused per category ==\n";
// The effective document limit is the lower of the configured value and the server's PHP
// ceiling, so it is read rather than assumed. The test file is one megabyte over it and
// carries valid PDF bytes, so the rejection is the size and not the format.
$limits = consultation_attachment_limits($pdo);
$bigPdf = sampleBytes('pdf', ($limits['document_mb'] + 1) * 1024 * 1024);
[$code, $data, $raw] = $webAttach($jarA, $csrfA, $A['consultationId'], 'huge.pdf', $bigPdf);
check("a PDF over {$limits['document_mb']} MB is refused (HTTP $code)", $code !== 201);
// The reason must be legible: either the application's own size message, or the
// request-too-large answer when PHP discarded the body before the script ran.
$reason = (string)($data['error'] ?? '');
$reasonCode = (string)($data['code'] ?? '');
check('the oversized upload explains itself', $reason !== '' || $reasonCode === 'request_too_large');
echo "        -> HTTP $code · " . ($reasonCode !== '' ? "[$reasonCode] " : '') . mb_substr($reason, 0, 120) . "\n";
check('the oversized upload was not stored', empty($data['file']['id']));
unset($bigPdf);

echo "\n== 6. the client downloads their own attachment ==\n";
$firstExt = 'pdf';
if (isset($attachmentIds[$firstExt])) {
    [$code, $body, $headers] = $download($jarA, $attachmentIds[$firstExt]);
    check("the owner receives the file (HTTP $code)", $code === 200);
    check('the bytes are the real file', str_starts_with($body, '%PDF-'));
    check('it is sent as an attachment, not inline', stripos($headers, 'Content-Disposition: attachment') !== false);
    check('nosniff is set', stripos($headers, 'X-Content-Type-Options: nosniff') !== false);
    check('no public URL is exposed in the body', !str_contains($body, $storageRoot = consultation_attachment_storage_root()));
}

echo "\n== 7. an unauthorized download is refused ==\n";
$anyId = (int)reset($attachmentIds);
[$code, $body] = $download(tempnam(sys_get_temp_dir(), 'e30anon'), $anyId);
check("an anonymous download is refused (HTTP $code)", $code !== 200);

[$code, $body] = $download($jarB, $anyId);
check("a different client is refused (HTTP $code)", $code !== 200);
check('no file bytes leaked to the other client', !str_starts_with($body, '%PDF'));

echo "\n== 8. a cross-tenant lawyer is refused ==\n";
// Lawyer B belongs to firm B; the attachment belongs to firm A's consultation.
[$code, $body] = http('GET', '/api/v1/consultation-attachments.php?id=' . $anyId . '&download=1', ['token' => $B['lawyerToken']]);
check("lawyer B cannot download tenant A's attachment (HTTP $code)", $code !== 200);
check('no file bytes leaked across tenants', !str_starts_with($body, '%PDF'));

[$code, $body] = http('GET', '/api/v1/consultation-attachments.php?consultation_id=' . $A['consultationId'], ['token' => $B['lawyerToken']]);
check("lawyer B cannot list tenant A's attachments (HTTP $code)", $code !== 200);

// The same-tenant lawyer must succeed, or the check above would pass trivially.
[$code, $body] = http('GET', '/api/v1/consultation-attachments.php?consultation_id=' . $A['consultationId'], ['token' => $A['lawyerToken']]);
check("lawyer A can list their own tenant's attachments (HTTP $code)", $code === 200);

echo "\n== 9. a deleted attachment is refused, and a missing file reports 410 ==\n";
if (isset($attachmentIds['txt'])) {
    $delId = $attachmentIds['txt'];
    [$code, $data] = http('POST', '/api/consultation_attachments.php', [
        'jar' => $jarA,
        'post' => ['csrf_token' => $csrfA, 'action' => 'remove', 'attachment_id' => (string)$delId],
    ]);
    $data = json_decode($data, true) ?: [];
    check("the owner can remove their own attachment (HTTP $code)", $code === 200 && !empty($data['ok']));
    [$code, $body] = $download($jarA, $delId);
    check("a deleted attachment is refused (HTTP $code)", $code !== 200);

    // The row survives as 'deleted' — the audit trail keeps the record — but no bytes are served.
    $row = consultation_attachment_find($pdo, $delId);
    check("the row is retained as 'deleted'", ($row['status'] ?? '') === 'deleted');
}

// A stored row whose file has been removed out of band must answer 410, not 200 or 500.
$ghostPath = consultation_attachment_storage_dir($A['consultationId']) . '/' . bin2hex(random_bytes(20)) . '.pdf';
@mkdir(dirname($ghostPath), 0750, true);
file_put_contents($ghostPath, "%PDF-1.4\n% to be deleted\n");
$pdo->prepare("INSERT INTO consultation_attachments (consultation_id, firm_id, uploaded_by_type, uploaded_by_id, original_name, stored_name, stored_path, file_category, file_type, mime_type, file_size, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,'stored')")
    ->execute([$A['consultationId'], $A['firmId'], 'client', $A['clientId'], 'ghost.pdf', basename($ghostPath),
               $A['consultationId'] . '/' . basename($ghostPath), 'document', 'pdf', 'application/pdf', 24]);
$ghostId = (int)$pdo->lastInsertId();
$created['attachments'][] = $ghostId;
unlink($ghostPath);
[$code, $body] = $download($jarA, $ghostId);
check("a row whose file is gone answers 410 (HTTP $code)", $code === 410);

echo "\n== 10. mobile API compatibility ==\n";
// List
[$code, $body] = http('GET', '/api/v1/consultation-attachments.php?consultation_id=' . $A['consultationId'], ['token' => $A['clientToken']]);
$data = json_decode($body, true) ?: [];
check("the client token lists attachments (HTTP $code)", $code === 200 && !empty($data['ok']));
check('the payload carries metadata', !empty($data['attachments'][0]['name']) && isset($data['attachments'][0]['size']));
check('the payload exposes no stored path', !str_contains($body, 'stored_path') && !str_contains($body, consultation_attachment_storage_root()));
check('the payload advertises the limits', isset($data['limits']['max_files']) && isset($data['limits']['allowed_extensions']));

// Upload through the API
$apiFile = tmpUpload('api-upload.pdf', sampleBytes('pdf'));
$ch = curl_init($base . '/api/v1/consultation-attachments.php');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $A['clientToken']],
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => [
        'consultation_id' => (string)$A['consultationId'],
        'file' => new CURLFile($apiFile, 'application/pdf', 'api-upload.pdf'),
    ],
]);
$body = (string)curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$data = json_decode($body, true) ?: [];
// The max-files limit is already reached by the web uploads above, so a 422 here is the
// correct answer and proves the quota is shared between the web and API paths. Either
// outcome is only acceptable if it is explained.
$apiUploadOk = $code === 201 && !empty($data['ok']);
$apiUploadQuota = $code === 422 && !empty($data['error']);
check("the API upload answers consistently (HTTP $code)", $apiUploadOk || $apiUploadQuota);
if ($apiUploadOk) $created['attachments'][] = (int)$data['attachment']['id'];

// Download through the API. Use the id returned by the API upload when it succeeded,
// otherwise the PDF uploaded over the web path — both belong to this consultation.
$dlTarget = !empty($data['attachment']['id']) ? (int)$data['attachment']['id'] : (isset($attachmentIds['pdf']) ? $attachmentIds['pdf'] : $anyId);
[$code, $body, $headers] = http('GET', '/api/v1/consultation-attachments.php?id=' . $dlTarget . '&download=1', ['token' => $A['clientToken']]);
check("the API serves the file to its owner (HTTP $code)", $code === 200);
check('the API download is an octet-stream attachment', stripos($headers, 'Content-Disposition: attachment') !== false);
check('the API download sets nosniff', stripos($headers, 'X-Content-Type-Options: nosniff') !== false);

// An integration token must not reach consultation attachments at all.
$intToken = bin2hex(random_bytes(32));
try {
    $pdo->prepare('INSERT INTO integration_api_tokens (firm_id, token_hash, scopes, label, is_active) VALUES (?,?,?,?,1)')
        ->execute([$A['firmId'], hash('sha256', $intToken), 'documents:read', 'e30 probe']);
    $created['api_tokens'][] = (int)$pdo->lastInsertId();
    [$code] = http('GET', '/api/v1/consultation-attachments.php?consultation_id=' . $A['consultationId'], ['token' => $intToken]);
    check("an integration token is refused (HTTP $code)", $code !== 200);
} catch (Throwable $e) {
    echo "  note: integration_api_tokens unavailable, skipped (" . $e->getMessage() . ")\n";
}

// Backward compatibility: the pre-existing consultations endpoint still works unchanged.
[$code, $body] = http('GET', '/api/v1/consultations.php', ['token' => $A['clientToken']]);
check("the existing consultations API still answers (HTTP $code)", $code === 200);
check('the existing consultations payload is unchanged in shape', str_contains($body, '"consultations"'));

echo "\n== 11. staged drafts, claim on booking, and orphan cleanup ==\n";
// Stage a file the way the booking form does.
$draftToken = consultation_attachment_new_draft_token();
$stagedPath = tmpUpload('staged.pdf', sampleBytes('pdf'));
$ch = curl_init($base . '/api/consultation_attachments.php');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jarA, CURLOPT_COOKIEFILE => $jarA,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => [
        'csrf_token' => $csrfA, 'action' => 'stage', 'draft_token' => $draftToken,
        'file' => new CURLFile($stagedPath, 'application/pdf', 'staged.pdf'),
    ],
]);
$body = (string)curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$data = json_decode($body, true) ?: [];
check("a draft file is staged (HTTP $code)", $code === 201 && !empty($data['ok']));
$stagedId = (int)($data['file']['id'] ?? 0);

if ($stagedId) {
    $row = consultation_attachment_find($pdo, $stagedId);
    check("the staged row has no consultation yet", $row['consultation_id'] === null);
    check("the staged row is marked 'staged'", ($row['status'] ?? '') === 'staged');
    check('the staged file lives under drafts/', str_starts_with((string)$row['stored_path'], 'drafts/'));

    // A staged attachment must not be downloadable: it has no consultation to authorize against.
    [$code, $body] = $download($jarA, $stagedId);
    check("a staged attachment is not downloadable (HTTP $code)", $code !== 200);

    // Claim it into a real consultation, the way booking.php does.
    $claimed = consultation_attachment_claim($pdo, $draftToken, $A['consultationId'], $A['firmId'], $A['clientId']);
    check('the staged file is claimed by the consultation', $claimed === 1);
    $row = consultation_attachment_find($pdo, $stagedId);
    check("the claimed row is bound to the consultation", (int)$row['consultation_id'] === $A['consultationId']);
    check("the claimed row is marked 'stored'", ($row['status'] ?? '') === 'stored');
    check('the claimed file moved out of drafts/', !str_starts_with((string)$row['stored_path'], 'drafts/'));
    [$code, $body] = $download($jarA, $stagedId);
    check("the claimed file is now downloadable (HTTP $code)", $code === 200);

    // The draft token is single-use: claiming it again must not re-claim anything.
    $again = consultation_attachment_claim($pdo, $draftToken, $A['consultationId'], $A['firmId'], $A['clientId']);
    check('the draft token cannot be claimed twice', $again === 0);
}

// A staged file that is never claimed is cleaned up by the sweeper after the grace period.
$abandonedToken = consultation_attachment_new_draft_token();
$abandonedPath = tmpUpload('abandoned.pdf', sampleBytes('pdf'));
$ch = curl_init($base . '/api/consultation_attachments.php');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jarA, CURLOPT_COOKIEFILE => $jarA,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => [
        'csrf_token' => $csrfA, 'action' => 'stage', 'draft_token' => $abandonedToken,
        'file' => new CURLFile($abandonedPath, 'application/pdf', 'abandoned.pdf'),
    ],
]);
$body = (string)curl_exec($ch);
curl_close($ch);
$abandoned = json_decode($body, true) ?: [];
$abandonedId = (int)($abandoned['file']['id'] ?? 0);

if ($abandonedId) {
    $row = consultation_attachment_find($pdo, $abandonedId);
    $abandonedFile = consultation_attachment_storage_root() . '/' . $row['stored_path'];
    check('the abandoned draft file exists on disk', is_file($abandonedFile));

    // Age it past the grace period, then sweep.
    $pdo->prepare('UPDATE consultation_attachments SET created_at = DATE_SUB(NOW(), INTERVAL 72 HOUR) WHERE id=?')->execute([$abandonedId]);
    $swept = consultation_attachment_purge_drafts($pdo, 48);
    check('the sweeper removed the abandoned draft', $swept['removed'] >= 1);
    check('the abandoned file is gone from disk', !is_file($abandonedFile));
    check('the abandoned row is gone', consultation_attachment_find($pdo, $abandonedId) === null);

    // A recent draft must survive the sweep, or a client who leaves the form open loses work.
    $recentToken = consultation_attachment_new_draft_token();
    $recentPath = tmpUpload('recent.pdf', sampleBytes('pdf'));
    $ch = curl_init($base . '/api/consultation_attachments.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jarA, CURLOPT_COOKIEFILE => $jarA,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'csrf_token' => $csrfA, 'action' => 'stage', 'draft_token' => $recentToken,
            'file' => new CURLFile($recentPath, 'application/pdf', 'recent.pdf'),
        ],
    ]);
    $body = (string)curl_exec($ch);
    curl_close($ch);
    $recent = json_decode($body, true) ?: [];
    $recentId = (int)($recent['file']['id'] ?? 0);
    if ($recentId) {
        consultation_attachment_purge_drafts($pdo, 48);
        check('a recent draft survives the sweep', consultation_attachment_find($pdo, $recentId) !== null);
    }
}

// An orphaned file (no row at all) is reclaimed.
$orphanDir = consultation_attachment_storage_dir($A['consultationId']);
@mkdir($orphanDir, 0750, true);
$orphanFile = $orphanDir . '/' . bin2hex(random_bytes(20)) . '.pdf';
file_put_contents($orphanFile, "%PDF-1.4\n% orphan\n");
$purged = consultation_attachment_purge_orphans($pdo);
check('the orphan sweeper removed the file', $purged['files_removed'] >= 1);
check('the orphaned file is gone', !is_file($orphanFile));

echo "\n== 12. partial / interrupted upload is handled ==\n";
// A request that declares a large body but sends nothing: PHP reports UPLOAD_ERR_NO_FILE
// with empty $_FILES, and the endpoint must answer with a clear error rather than a 500.
$ch = curl_init($base . '/api/consultation_attachments.php');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jarA, CURLOPT_COOKIEFILE => $jarA,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Length: 5000000', 'Content-Type: multipart/form-data; boundary=----e30'],
    CURLOPT_POSTFIELDS => "------e30\r\n",
    CURLOPT_TIMEOUT => 20,
]);
$body = (string)curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
check("a truncated multipart body does not 500 (HTTP $code)", $code !== 500);
$data = json_decode($body, true);
check('the truncated upload gets a parseable JSON answer', is_array($data) && array_key_exists('ok', $data));

// An upload with no file part at all.
[$code, $data] = http('POST', '/api/consultation_attachments.php', [
    'jar' => $jarA,
    'post' => ['csrf_token' => $csrfA, 'action' => 'stage', 'draft_token' => consultation_attachment_new_draft_token()],
]);
$data = json_decode($data, true) ?: [];
check("an upload with no file part is refused clearly (HTTP $code)", $code === 400 && !empty($data['error']));

// An empty file.
[$code, $data] = $webAttach($jarA, $csrfA, $A['consultationId'], 'empty.pdf', '');
check("an empty file is refused (HTTP $code)", $code !== 201);

echo "\n== 13. CSRF is enforced on the upload endpoint ==\n";
$noCsrfPath = tmpUpload('nocsrf.pdf', sampleBytes('pdf'));
$ch = curl_init($base . '/api/consultation_attachments.php');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jarA, CURLOPT_COOKIEFILE => $jarA,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => [
        'action' => 'attach', 'consultation_id' => (string)$A['consultationId'],
        'file' => new CURLFile($noCsrfPath, 'application/pdf', 'nocsrf.pdf'),
    ],
]);
$body = (string)curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
check("an upload without a CSRF token is refused (HTTP $code)", $code === 403);

echo "\n== 14. the storage directory is not web-served ==\n";
$root = consultation_attachment_storage_root();
$appRoot = realpath(__DIR__ . '/..');
$outside = !str_starts_with(realpath($root) ?: $root, (string)$appRoot . DIRECTORY_SEPARATOR);
check('the storage root is outside the application directory', $outside);
check('the storage root exists', is_dir($root));
$guard = $root . '/.htaccess';
check('a deny-all .htaccess is present in the storage root', is_file($guard) && str_contains((string)file_get_contents($guard), 'Require all denied'));
// Even if it were inside the web root, the .htaccess deny rule must block it.
if (!$outside) {
    [$code] = http('GET', '/storage/consultation-attachments/');
    check("a direct request to the storage path is refused (HTTP $code)", $code !== 200);
}

echo "\n== 15. a traversal-shaped stored_path cannot escape ==\n";
$pdo->prepare("INSERT INTO consultation_attachments (consultation_id, firm_id, uploaded_by_type, uploaded_by_id, original_name, stored_name, stored_path, file_category, file_type, mime_type, file_size, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,'stored')")
    ->execute([$A['consultationId'], $A['firmId'], 'client', $A['clientId'], 'traversal.pdf', 'traversal.pdf',
               '../../../../etc/passwd', 'document', 'pdf', 'application/pdf', 100]);
$travId = (int)$pdo->lastInsertId();
$created['attachments'][] = $travId;
[$code, $body] = $download($jarA, $travId);
check("a traversal stored_path is refused (HTTP $code)", $code !== 200);
check('no system file leaked', !str_contains($body, 'root:'));

// A path that is absolute rather than relative to the storage root.
$pdo->prepare("INSERT INTO consultation_attachments (consultation_id, firm_id, uploaded_by_type, uploaded_by_id, original_name, stored_name, stored_path, file_category, file_type, mime_type, file_size, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,'stored')")
    ->execute([$A['consultationId'], $A['firmId'], 'client', $A['clientId'], 'absolute.pdf', 'absolute.pdf',
               '/etc/passwd', 'document', 'pdf', 'application/pdf', 100]);
$absId = (int)$pdo->lastInsertId();
$created['attachments'][] = $absId;
[$code, $body] = $download($jarA, $absId);
check("an absolute stored_path is refused (HTTP $code)", $code !== 200);

echo "\n== 16. the existing booking flow still works (regression) ==\n";
// Book a consultation with no attachments through the real form.
$bookingPage = http('GET', '/booking.php?lawyer_id=' . $A['lawyerId'], ['jar' => $jarA]);
$bookingCsrf = csrf_from($bookingPage[1]);
$bookingToken = preg_match('/name="attachment_draft_token" value="([^"]+)"/', $bookingPage[1], $m) ? $m[1] : '';
check('the booking form renders', $bookingPage[0] === 200);
check('the booking form carries a draft token', $bookingToken !== '');
check('the attachments section is present', str_contains($bookingPage[1], 'المرفقات والمستندات'));
check('the drop zone is present', str_contains($bookingPage[1], 'data-ca-drop'));
check('the attachments panel is inside the booking form', (function () use ($bookingPage) {
    $html = $bookingPage[1];
    $formPos = strpos($html, 'id="bookingForm"');
    $panelPos = strpos($html, 'data-ca-drop');
    $submitPos = strpos($html, 'type="submit"');
    return $formPos !== false && $panelPos !== false && $panelPos > $formPos;
})());

$ch = curl_init($base . '/booking.php?lawyer_id=' . $A['lawyerId']);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jarA, CURLOPT_COOKIEFILE => $jarA,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => [
        'csrf_token' => $bookingCsrf,
        'consultation_type' => 'text',
        'date' => date('Y-m-d', strtotime('+2 days')),
        'time' => '10:00',
        'topic' => "حجز بدون مرفقات $suffix",
        'attachment_draft_token' => $bookingToken,
    ],
]);
$body = (string)curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
check("booking without attachments succeeds (HTTP $code)", $code === 200);
check('the confirmation is shown', str_contains($body, 'تم إرسال طلب الحجز'));
$newBookingId = (int)$pdo->query("SELECT id FROM consultations WHERE topic='حجز بدون مرفقات $suffix' ORDER BY id DESC LIMIT 1")->fetchColumn();
check('the consultation row was created', $newBookingId > 0);
if ($newBookingId) $created['consultations'][] = $newBookingId;
check('it has no attachments', $newBookingId > 0 && consultation_attachment_count($pdo, $newBookingId) === 0);

echo "\n== 17. the admin console renders, and RBAC still gates the files ==\n";
// The console is a new admin surface, and nothing else in the suite loads it. It is checked
// over HTTP as a real admin session so a template error or a missing RBAC entry is caught
// here rather than in production. An `editor` is used as the negative case: the role is
// refused `consultation-attachments.php` in includes/admin_rbac.php, and
// download-consultation-attachment.php re-reads the same map, so the files stay closed to a
// role that the documents console already refuses.
$adminEmail = 'e30-admin-' . $suffix . '@example.test';
$pdo->prepare("INSERT INTO admins (email, password_hash, full_name, role, created_at) VALUES (?,?,?,'super_admin',NOW())")
    ->execute([$adminEmail, password_hash('E30-Test-Pass-2026', PASSWORD_BCRYPT), 'E30 Admin']);
$adminId = (int)$pdo->lastInsertId();
$created['admins'][] = $adminId;

$editorEmail = 'e30-editor-' . $suffix . '@example.test';
$pdo->prepare("INSERT INTO admins (email, password_hash, full_name, role, created_at) VALUES (?,?,?,'editor',NOW())")
    ->execute([$editorEmail, password_hash('E30-Test-Pass-2026', PASSWORD_BCRYPT), 'E30 Editor']);
$created['admins'][] = (int)$pdo->lastInsertId();

$adminLogin = function (string $email, string $jar) use ($base): void {
    $ch = curl_init($base . '/admin/login.php');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    $body = (string)curl_exec($ch);
    curl_close($ch);
    $ch = curl_init($base . '/admin/login.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['csrf_token' => csrf_from($body), 'email' => $email, 'password' => 'E30-Test-Pass-2026'],
    ]);
    curl_exec($ch);
    curl_close($ch);
};

$jarAdmin = tempnam(sys_get_temp_dir(), 'e30ad');
$jarEditor = tempnam(sys_get_temp_dir(), 'e30ed');
$adminLogin($adminEmail, $jarAdmin);
$adminLogin($editorEmail, $jarEditor);

[$code, $page] = http('GET', '/admin/consultation-attachments.php', ['jar' => $jarAdmin]);
check("the admin console renders for a super_admin (HTTP $code)", $code === 200);
check('the console shows the storage root', str_contains($page, 'consultation-attachments'));
check('the console exposes the limits form', str_contains($page, 'save_limits'));
check('the console exposes the cleanup form', str_contains($page, 'value="purge"'));
foreach (['SQLSTATE', 'Fatal error', 'Warning:', 'Notice:'] as $bad) {
    check("the console renders without a PHP $bad", stripos($page, $bad) === false);
}

// A live attachment, resolved from the database rather than reused from an earlier section:
// the id `reset($attachmentIds)` yields was soft-deleted in section 9, and a deleted row is
// refused with 404 before RBAC is even consulted — which would make the editor check pass for
// the wrong reason and the super_admin check fail.
$liveId = (int)$pdo->query("SELECT id FROM consultation_attachments WHERE consultation_id=" . (int)$A['consultationId'] . " AND status='stored' ORDER BY id ASC LIMIT 1")->fetchColumn();
check('a live attachment is available to probe the admin routes', $liveId > 0);

[$code, ] = http('GET', '/admin/consultation-attachments.php', ['jar' => $jarEditor]);
check("an editor is refused the console (HTTP $code)", $code === 403);

// The editor must not reach the file either, even with a valid id: the console route and the
// download route share one role map, which is the invariant that broke before Execution 29.
[$code, $body] = $download($jarEditor, $liveId);
check("an editor is refused the download route (HTTP $code)", $code !== 200);
check('no bytes leaked to the editor', !str_starts_with($body, '%PDF'));

// A super_admin may download, which is what makes the check above meaningful. The bytes are
// compared with the file on disk rather than a signature: the first live attachment is
// whichever type sorted first by id, so asserting "%PDF" would test the seed order, not access.
[$code, $body] = $download($jarAdmin, $liveId);
check("a super_admin can download (HTTP $code)", $code === 200);
$liveRow = consultation_attachment_find($pdo, $liveId);
$livePath = $liveRow ? consultation_attachment_absolute_path($liveRow) : null;
check('the super_admin receives the real bytes', $livePath !== null && $body === (string)file_get_contents($livePath));

echo "\n== cleanup ==\n";
if ($created['attachments']) {
    foreach ($created['attachments'] as $aid) {
        $row = consultation_attachment_find($pdo, $aid);
        if ($row) {
            $p = consultation_attachment_absolute_path($row);
            if ($p !== null) @unlink($p);
        }
    }
}
// Remove every row the probe created, plus any row belonging to the probe consultations.
if ($created['consultations']) {
    $ids = implode(',', array_map('intval', $created['consultations']));
    $pdo->exec("DELETE FROM consultation_attachments WHERE consultation_id IN ($ids)");
    $pdo->exec("DELETE FROM consultations WHERE id IN ($ids)");
}
$pdo->exec("DELETE FROM consultation_attachments WHERE original_name LIKE '%-$suffix.%'");
if ($created['api_tokens']) {
    $ids = implode(',', array_map('intval', $created['api_tokens']));
    $pdo->exec("DELETE FROM api_tokens WHERE id IN ($ids)");
}
if ($created['law_firm_members']) {
    $ids = implode(',', array_map('intval', $created['law_firm_members']));
    $pdo->exec("DELETE FROM law_firm_members WHERE id IN ($ids)");
}
foreach (['lawyers', 'law_firms', 'users'] as $table) {
    if ($created[$table]) {
        $ids = implode(',', array_map('intval', $created[$table]));
        $pdo->exec("DELETE FROM $table WHERE id IN ($ids)");
    }
}
if ($created['admins']) {
    $ids = implode(',', array_map('intval', $created['admins']));
    $pdo->exec("DELETE FROM admins WHERE id IN ($ids)");
}
foreach ($tmpFiles as $f) { if (is_file($f)) unlink($f); }
foreach ([$jarA, $jarB, $jarAdmin, $jarEditor] as $j) { if (is_file($j)) unlink($j); }

$left = (int)$pdo->query("SELECT COUNT(*) FROM consultations WHERE topic LIKE '%$suffix%'")->fetchColumn();
check('probe consultations removed', $left === 0);

echo "\n  Execution 30 consultation attachments: $checks checks, $failures failure(s)\n";
exit($failures > 0 ? 1 : 0);
