<?php
/**
 * Execution 29 — file access-control probe.
 *
 * Verifies, over real HTTP, that private documents are reachable only by the right
 * principal, and that the storage directory is not itself served:
 *   - a client downloads only their own document
 *   - an anonymous request is refused
 *   - another client cannot download by guessing the id
 *   - an admin role that may not open the documents console cannot download either,
 *     even though the download route sits at the web root and never ran the console guard
 *   - the uploads directory does not serve files directly
 *   - a traversal-shaped stored_path cannot escape the upload directory
 *
 * Requires the app running on 127.0.0.1:8080 (see tests/setup_test_env.sh).
 * Run: php tests/execution29_file_security_probe.php
 */

require_once __DIR__ . '/../config/config.php';

$base = 'http://127.0.0.1:8080';
$failures = 0;
$checks = 0;
function check(string $label, bool $ok): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) { $failures++; echo "  FAIL: $label\n"; } else { echo "  ok:   $label\n"; }
}

/** HTTP request with an optional cookie jar; returns [code, body]. */
$http = function (string $path, array $opts = []) use ($base): array {
    $ch = curl_init($base . $path);
    $cfg = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false];
    if (!empty($opts['jar'])) { $cfg[CURLOPT_COOKIEJAR] = $opts['jar']; $cfg[CURLOPT_COOKIEFILE] = $opts['jar']; }
    if (!empty($opts['post'])) { $cfg[CURLOPT_POST] = true; $cfg[CURLOPT_POSTFIELDS] = $opts['post']; }
    curl_setopt_array($ch, $cfg);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
};

$suffix = bin2hex(random_bytes(4));
$created = ['users' => [], 'admins' => [], 'documents' => [], 'cases' => [], 'lawyers' => []];
$storedFiles = [];

/** Create a real upload file plus its documents row for a client. */
$makeDoc = function (int $userId) use ($pdo, $suffix, &$created, &$storedFiles): array {
    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
    $stored = bin2hex(random_bytes(20)) . '.pdf';
    file_put_contents(UPLOAD_DIR . $stored, "%PDF-1.4\n% e29 file security probe\n");
    $storedFiles[] = UPLOAD_DIR . $stored;
    $pdo->prepare('INSERT INTO documents (user_id, original_name, stored_path, file_type, status) VALUES (?,?,?,?,?)')
        ->execute([$userId, 'private-' . $suffix . '.pdf', 'uploads/documents/' . $stored, 'pdf', 'completed']);
    $id = (int)$pdo->lastInsertId();
    $created['documents'][] = $id;
    return ['id' => $id, 'stored' => $stored];
};

echo "\n== setup ==\n";
$pdo->prepare("INSERT INTO users (full_name, email, password_hash, status, email_verified, account_type) VALUES (?,?,?,'active',1,'individual')")
    ->execute(['مالك المستند', "e29-file-owner-$suffix@example.test", password_hash('E29-File-Pass-2026', PASSWORD_BCRYPT)]);
$ownerId = (int)$pdo->lastInsertId();
$created['users'][] = $ownerId;

$pdo->prepare("INSERT INTO users (full_name, email, password_hash, status, email_verified, account_type) VALUES (?,?,?,'active',1,'individual')")
    ->execute(['عميل آخر', "e29-file-other-$suffix@example.test", password_hash('E29-File-Pass-2026', PASSWORD_BCRYPT)]);
$otherId = (int)$pdo->lastInsertId();
$created['users'][] = $otherId;

$ownerDoc = $makeDoc($ownerId);
$otherDoc = $makeDoc($otherId);
check('two private documents exist', $ownerDoc['id'] > 0 && $otherDoc['id'] > 0);
check('the documents are distinct', $ownerDoc['id'] !== $otherDoc['id']);

// Two admin roles: one that may open the documents console, one that may not.
$pdo->prepare("INSERT INTO admins (full_name, email, password_hash, role) VALUES (?,?,?,'support')")
    ->execute(['دعم E29', "e29-file-support-$suffix@example.test", password_hash('E29-Admin-Pass-2026', PASSWORD_BCRYPT)]);
$created['admins'][] = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO admins (full_name, email, password_hash, role) VALUES (?,?,?,'editor')")
    ->execute(['محرر E29', "e29-file-editor-$suffix@example.test", password_hash('E29-Admin-Pass-2026', PASSWORD_BCRYPT)]);
$created['admins'][] = (int)$pdo->lastInsertId();

/** Log in through the real forms, keeping the session in a cookie jar. */
$clientLogin = function (string $email, string $jar) use ($http): void {
    [$code, $body] = $http('/login.php', ['jar' => $jar]);
    if (!preg_match('/name="csrf_token" value="([^"]+)"/', $body, $m)) { echo "  note: no csrf on login.php\n"; return; }
    $http('/login.php', ['jar' => $jar, 'post' => ['csrf_token' => $m[1], 'email' => $email, 'password' => 'E29-File-Pass-2026']]);
};
$adminLogin = function (string $email, string $jar) use ($http): void {
    [$code, $body] = $http('/admin/login.php', ['jar' => $jar]);
    if (!preg_match('/name="csrf_token" value="([^"]+)"/', $body, $m)) { echo "  note: no csrf on admin/login.php\n"; return; }
    $http('/admin/login.php', ['jar' => $jar, 'post' => ['csrf_token' => $m[1], 'email' => $email, 'password' => 'E29-Admin-Pass-2026']]);
};

$ownerJar = tempnam(sys_get_temp_dir(), 'e29owner');
$otherJar = tempnam(sys_get_temp_dir(), 'e29other');
$supportJar = tempnam(sys_get_temp_dir(), 'e29support');
$editorJar = tempnam(sys_get_temp_dir(), 'e29editor');
$clientLogin("e29-file-owner-$suffix@example.test", $ownerJar);
$clientLogin("e29-file-other-$suffix@example.test", $otherJar);
$adminLogin("e29-file-support-$suffix@example.test", $supportJar);
$adminLogin("e29-file-editor-$suffix@example.test", $editorJar);

echo "\n== 1. anonymous access is refused ==\n";
[$code] = $http('/download-document.php?id=' . $ownerDoc['id']);
check("anonymous download is refused (HTTP $code)", $code !== 200);

echo "\n== 2. the owner downloads their own document ==\n";
[$code, $body] = $http('/download-document.php?id=' . $ownerDoc['id'], ['jar' => $ownerJar]);
check("the owner receives their document (HTTP $code)", $code === 200 && str_starts_with($body, '%PDF'));

echo "\n== 3. another client cannot download by id ==\n";
[$code, $body] = $http('/download-document.php?id=' . $ownerDoc['id'], ['jar' => $otherJar]);
check("another client is refused (HTTP $code)", $code !== 200);
check('no document bytes leaked', !str_starts_with($body, '%PDF'));

echo "\n== 4. an admin whose role may read documents can download ==\n";
[$code] = $http('/download-document.php?id=' . $ownerDoc['id'], ['jar' => $supportJar]);
check("support can download a client document (HTTP $code)", $code === 200);

echo "\n== 5. an admin whose role may NOT read documents cannot ==\n";
// `editor` is refused the documents console by the RBAC map. This route is at the web
// root and never ran admin_guard.php, so before the fix the check was only "is an admin
// logged in" — an editor could read every client's documents by id.
[$code, $body] = $http('/admin/documents.php', ['jar' => $editorJar]);
check("editor is refused the documents console (HTTP $code)", $code === 403);
[$code, $body] = $http('/download-document.php?id=' . $ownerDoc['id'], ['jar' => $editorJar]);
check("editor is refused the document download (HTTP $code)", $code === 403);
check('no document bytes leaked to the editor', !str_starts_with($body, '%PDF'));

echo "\n== 6. the upload directory is not served directly ==\n";
[$code, $body] = $http('/uploads/documents/' . $ownerDoc['stored']);
check("a direct file request is refused (HTTP $code)", $code !== 200);
check('no document bytes served directly', !str_starts_with($body, '%PDF'));
[$code] = $http('/uploads/documents/');
check("directory listing is refused (HTTP $code)", $code !== 200);

echo "\n== 7. a traversal-shaped stored_path cannot escape the upload directory ==\n";
$pdo->prepare('INSERT INTO documents (user_id, original_name, stored_path, file_type, status) VALUES (?,?,?,?,?)')
    ->execute([$ownerId, 'traversal.pdf', 'uploads/documents/../../../../etc/passwd', 'pdf', 'completed']);
$travId = (int)$pdo->lastInsertId();
$created['documents'][] = $travId;
[$code, $body] = $http('/download-document.php?id=' . $travId, ['jar' => $ownerJar]);
check("a traversal path is refused (HTTP $code)", $code !== 200);
check('no system file leaked', !str_contains($body, 'root:'));
// A non-canonical stored name (not a 40-hex filename) must also be refused.
$pdo->prepare('INSERT INTO documents (user_id, original_name, stored_path, file_type, status) VALUES (?,?,?,?,?)')
    ->execute([$ownerId, 'evil.pdf', 'uploads/documents/evil.pdf', 'pdf', 'completed']);
$evilId = (int)$pdo->lastInsertId();
$created['documents'][] = $evilId;
file_put_contents(UPLOAD_DIR . 'evil.pdf', "%PDF-1.4\n% should never be served\n");
$storedFiles[] = UPLOAD_DIR . 'evil.pdf';
[$code, $body] = $http('/download-document.php?id=' . $evilId, ['jar' => $ownerJar]);
check("a non-canonical stored name is refused (HTTP $code)", $code !== 200);
check('the non-canonical file was not served', !str_starts_with($body, '%PDF'));

echo "\n== cleanup ==\n";
if ($created['documents']) {
    $ids = implode(',', array_map('intval', $created['documents']));
    $pdo->exec("DELETE FROM documents WHERE id IN ($ids)");
}
if ($created['admins']) {
    $ids = implode(',', array_map('intval', $created['admins']));
    $pdo->exec("DELETE FROM admins WHERE id IN ($ids)");
}
if ($created['users']) {
    $ids = implode(',', array_map('intval', $created['users']));
    $pdo->exec("DELETE FROM users WHERE id IN ($ids)");
}
foreach ($storedFiles as $f) { if (is_file($f)) unlink($f); }
foreach ([$ownerJar, $otherJar, $supportJar, $editorJar] as $j) { if (is_file($j)) unlink($j); }
$left = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE email LIKE 'e29-file-%'")->fetchColumn();
check('all seeded accounts removed', $left === 0);

echo "\n  Execution 29 file security: $checks checks, $failures failure(s)\n";
exit($failures > 0 ? 1 : 0);
