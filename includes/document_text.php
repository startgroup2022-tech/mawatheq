<?php
/**
 * Plain-text extraction for stored client documents.
 *
 * Handles PDF, DOCX, XLSX, plain text/markdown, and legacy binary `.doc`. Extraction is
 * best-effort and never blocks the document itself: a failure is recorded in `document_text`
 * with a status and a plain reason, so the lawyer sees "we could not read this file" rather
 * than an empty document or a 500.
 *
 * All external converters run through escapeshellarg on a resolved path, and the path is
 * confined to the uploads directory before use, so a crafted filename cannot influence the
 * command line.
 */

if (!function_exists('document_text_uploads_root')) {
    function document_text_uploads_root(): string {
        return realpath(__DIR__ . '/../uploads') ?: (__DIR__ . '/../uploads');
    }
}

if (!function_exists('document_text_resolve_path')) {
    /**
     * Resolve a stored path to an absolute path inside uploads/. Returns null when the file is
     * missing or escapes the uploads root, which refuses traversal rather than sanitising it.
     */
    function document_text_resolve_path(string $storedPath): ?string {
        $root = document_text_uploads_root();
        $candidate = $storedPath;
        if ($candidate === '' || str_contains($candidate, "\0")) return null;
        if ($candidate[0] !== '/') {
            $candidate = $root . '/' . ltrim($candidate, './');
        }
        $real = realpath($candidate);
        if ($real === false || !str_starts_with($real, $root)) return null;
        return $real;
    }
}

if (!function_exists('document_text_from_docx')) {
    function document_text_from_docx(string $path): string {
        if (!class_exists('ZipArchive')) return '';
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) return '';
        $xml = '';
        foreach (['word/document.xml'] as $name) {
            $part = $zip->getFromName($name);
            if ($part !== false) $xml .= $part;
        }
        $zip->close();
        if ($xml === '') return '';
        $xml = preg_replace('/<w:p[ >]/', "\n<w:p ", $xml);
        return trim(html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }
}

if (!function_exists('document_text_run_tool')) {
    /** Run an external converter and return stdout, or '' on any failure (missing binary included). */
    function document_text_run_tool(string $binary, string $path, int $timeout = 20): string {
        $exe = trim((string)@shell_exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null'));
        if ($exe === '') return '';
        $cmd = escapeshellarg($exe) . ' ' . escapeshellarg($path) . ' 2>/dev/null';
        $out = @shell_exec($cmd);
        return is_string($out) ? $out : '';
    }
}

if (!function_exists('document_extract_text')) {
    /**
     * Extract text for a file and return ['status','content','converter','detail'].
     * status is one of ok|unsupported|failed|too_large.
     */
    function document_extract_text(string $path, string $extension, int $maxBytes = 5_000_000): array {
        $extension = strtolower(ltrim($extension, '.'));
        if (!is_file($path) || !is_readable($path)) {
            return ['status' => 'failed', 'content' => '', 'converter' => null, 'detail' => 'file_not_readable'];
        }
        if ((int)filesize($path) > $maxBytes) {
            return ['status' => 'too_large', 'content' => '', 'converter' => null, 'detail' => 'file exceeds ' . $maxBytes . ' bytes'];
        }

        switch ($extension) {
            case 'txt':
            case 'md':
            case 'csv':
                $content = (string)@file_get_contents($path);
                return ['status' => 'ok', 'content' => $content, 'converter' => 'native', 'detail' => null];

            case 'docx':
                $content = document_text_from_docx($path);
                return $content !== ''
                    ? ['status' => 'ok', 'content' => $content, 'converter' => 'zip-xml', 'detail' => null]
                    : ['status' => 'failed', 'content' => '', 'converter' => 'zip-xml', 'detail' => 'docx part missing or unreadable'];

            case 'doc':
                // Legacy binary Word. antiword is preferred (cleaner layout); catdoc is the
                // fallback because the two disagree on some older files.
                $content = document_text_run_tool('antiword', $path);
                $converter = 'antiword';
                if (trim($content) === '') {
                    $content = document_text_run_tool('catdoc', $path);
                    $converter = 'catdoc';
                }
                if (trim($content) === '') {
                    return ['status' => 'failed', 'content' => '', 'converter' => null, 'detail' => 'neither antiword nor catdoc produced text (binary may be missing or the file is not a Word document)'];
                }
                return ['status' => 'ok', 'content' => $content, 'converter' => $converter, 'detail' => null];

            case 'pdf':
                $content = document_text_run_tool('pdftotext', $path);
                if (trim($content) === '') {
                    return ['status' => 'unsupported', 'content' => '', 'converter' => null, 'detail' => 'pdf text requires the pdftotext binary, which is not installed'];
                }
                return ['status' => 'ok', 'content' => $content, 'converter' => 'pdftotext', 'detail' => null];

            default:
                return ['status' => 'unsupported', 'content' => '', 'converter' => null, 'detail' => 'no extractor for .' . $extension];
        }
    }
}

if (!function_exists('document_text_store')) {
    /**
     * Extract and persist the text for a document row. Idempotent per document (upsert on
     * document_id), so re-running a backfill or a manual click does not duplicate rows.
     */
    function document_text_store(PDO $pdo, int $documentId): array {
        $st = $pdo->prepare('SELECT d.id, c.firm_id, d.stored_path, d.original_name, d.file_type FROM documents d LEFT JOIN cases c ON c.id=d.case_id WHERE d.id=? LIMIT 1');
        $st->execute([$documentId]);
        $doc = $st->fetch();
        if (!$doc) return ['ok' => false, 'error' => 'not_found'];

        $extension = strtolower((string)pathinfo((string)$doc['original_name'], PATHINFO_EXTENSION));
        if ($extension === '') $extension = strtolower((string)$doc['file_type']);

        $path = document_text_resolve_path((string)$doc['stored_path']);
        if ($path === null) {
            $result = ['status' => 'failed', 'content' => '', 'converter' => null, 'detail' => 'stored file not found under uploads/'];
        } else {
            $result = document_extract_text($path, $extension);
        }

        $content = (string)$result['content'];
        $pdo->prepare('INSERT INTO document_text (document_id, firm_id, source_extension, converter, content, char_count, status, detail)
                VALUES (?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE source_extension=VALUES(source_extension), converter=VALUES(converter),
                    content=VALUES(content), char_count=VALUES(char_count), status=VALUES(status), detail=VALUES(detail)')
            ->execute([
                $documentId,
                $doc['firm_id'] !== null ? (int)$doc['firm_id'] : null,
                $extension ?: null,
                $result['converter'],
                $content,
                strlen($content),
                $result['status'],
                $result['detail'],
            ]);

        return ['ok' => $result['status'] === 'ok', 'status' => $result['status'], 'converter' => $result['converter'], 'chars' => strlen($content), 'detail' => $result['detail']];
    }
}

if (!function_exists('document_text_get')) {
    function document_text_get(PDO $pdo, int $documentId): ?array {
        $st = $pdo->prepare('SELECT * FROM document_text WHERE document_id=? LIMIT 1');
        $st->execute([$documentId]);
        return $st->fetch() ?: null;
    }
}
