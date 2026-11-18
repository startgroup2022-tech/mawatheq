<?php
/**
 * تنزيل ملف من المكتبة القانونية (Execution 74).
 * Serves a hosted legal-library file.
 *
 * This route is the only door to a hosted file. It is public, because the catalogue is
 * public legal content, but it is defensive in the same way download-document.php is:
 *
 *   - only a row whose source redistribution is `hosted` has a `hosted_path` at all,
 *     so a link_only source can never be served from here;
 *   - the path is resolved with realpath() and must sit under LEGAL_LIBRARY_DIR, with a
 *     40-hex filename and a known extension, so a traversal-shaped stored path or a
 *     hand-placed file is refused;
 *   - the file must exist.
 *
 * Nothing is read from the request beyond the integer id.
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/legal_library.php';

$id = (int)($_GET['id'] ?? 0);
$row = legal_library_item($id);
if (!$row || ($row['redistribution'] ?? '') !== 'hosted' || empty($row['hosted_path'])) {
    http_response_code(404);
    exit('Not found');
}

$allowed = ['pdf' => 'application/pdf', 'txt' => 'text/plain; charset=utf-8'];
$ext = strtolower((string)($row['file_type'] ?? ''));
if (!isset($allowed[$ext])) {
    // Fall back to the stored path's own extension when file_type is unset.
    $ext = strtolower((string)pathinfo((string)$row['hosted_path'], PATHINFO_EXTENSION));
}
if (!isset($allowed[$ext])) { http_response_code(404); exit('Not found'); }

$base = realpath(LEGAL_LIBRARY_DIR);
$path = realpath(LEGAL_LIBRARY_DIR . basename((string)$row['hosted_path']));
if (!$base || !$path
    || strpos($path, $base . DIRECTORY_SEPARATOR) !== 0
    || !preg_match('/^[a-f0-9]{40}\.' . preg_quote($ext, '/') . '$/', basename($path))
    || !is_file($path)) {
    http_response_code(404);
    exit('Not found');
}

$name = preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', (string)$row['title']) . '.' . $ext;
header('Content-Type: ' . $allowed[$ext]);
header('Content-Length: ' . filesize($path));
header("Content-Disposition: attachment; filename=\"legal-library-{$id}.{$ext}\"; filename*=UTF-8''" . rawurlencode($name));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
