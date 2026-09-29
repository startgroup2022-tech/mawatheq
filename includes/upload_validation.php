<?php
/** Validate uploads using the detected content type, not only the supplied extension. */
function validate_legal_upload(array $file): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'error' => 'invalid_upload', 'extension' => null];
    }
    $maxBytes = MAX_UPLOAD_SIZE_MB * 1024 * 1024;
    if ((int)($file['size'] ?? 0) < 1 || (int)$file['size'] > $maxBytes) {
        return ['ok' => false, 'error' => 'invalid_size', 'extension' => null];
    }
    $extension = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    $allowed = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg'];
    if (!isset($allowed[$extension])) return ['ok' => false, 'error' => 'invalid_type', 'extension' => null];

    if (!function_exists('finfo_open')) return ['ok' => false, 'error' => 'fileinfo_unavailable', 'extension' => null];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : '';
    if ($finfo) finfo_close($finfo);
    if ($mime !== $allowed[$extension]) return ['ok' => false, 'error' => 'mime_mismatch', 'extension' => null];
    if ($extension === 'pdf') {
        $handle = fopen($file['tmp_name'], 'rb');
        $header = $handle ? fread($handle, 5) : '';
        if ($handle) fclose($handle);
        if ($header !== '%PDF-') return ['ok' => false, 'error' => 'invalid_pdf', 'extension' => null];
    } elseif (!@getimagesize($file['tmp_name'])) {
        return ['ok' => false, 'error' => 'invalid_image', 'extension' => null];
    }
    return ['ok' => true, 'error' => null, 'extension' => $extension];
}
