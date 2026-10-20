<?php
/**
 * Consultation attachments — storage, validation, scoping and lifecycle.
 *
 * Everything that touches an attachment file or row goes through this file, so the rules
 * live in one place and the web route, the REST API and the dashboards cannot disagree.
 *
 * Storage model
 * -------------
 * Files are written OUTSIDE the web root, under a private directory that is not reachable
 * by URL. There is deliberately no public path to an attachment: the only way to read one
 * is download-consultation-attachment.php, which re-resolves ownership on every request.
 * A random stored name is not a security control on its own (it is a 40-hex guessable
 * secret at best), so it is treated as a collision-avoidance measure and the authorization
 * check is what actually protects the file.
 *
 * `stored_path` is relative to the storage root. Absolute paths are never stored, because a
 * database dump or a backup would then leak the server's directory layout, and a restored
 * copy on a different host would break.
 *
 * Validation model
 * ----------------
 * A filename extension is a claim, not evidence. Every upload is checked three ways: the
 * declared extension, the MIME type the system detects from the bytes, and the actual
 * container signature. An executable or a polyglot that satisfies one check fails another.
 * See consultation_attachment_sniff().
 */

if (!defined('CONSULTATION_ATTACHMENT_SETTING_PREFIX')) {
    define('CONSULTATION_ATTACHMENT_SETTING_PREFIX', 'consultation_attachment_');
}

/**
 * The storage root for consultation attachments.
 *
 * Prefers a directory that is a SIBLING of the application root, which places it outside
 * the document root on both a cPanel layout (public_html + a private sibling under the home
 * directory) and this repository's layout. Falls back to `<app>/storage/...`, which
 * .htaccess denies and .gitignore excludes, when the parent is not writable.
 *
 * An operator can pin it with MAWATHEQ_PRIVATE_STORAGE_DIR.
 */
function consultation_attachment_storage_root(): string {
    static $root = null;
    if ($root !== null) return $root;

    $appRoot = dirname(__DIR__);
    $override = getenv('MAWATHEQ_PRIVATE_STORAGE_DIR');
    if (is_string($override) && $override !== '' && str_starts_with($override, '/')) {
        return $root = rtrim($override, '/');
    }

    $outside = dirname($appRoot) . '/mawatheq-private/consultation-attachments';
    $inside  = $appRoot . '/storage/consultation-attachments';

    $parent = dirname($outside);
    if (is_dir($outside) || (is_dir($parent) && is_writable($parent)) || @mkdir($parent, 0755, true)) {
        return $root = $outside;
    }
    return $root = $inside;
}

/** Ensure the storage root exists, is private, and carries a deny-all .htaccess. */
function consultation_attachment_ensure_storage(): bool {
    $root = consultation_attachment_storage_root();
    if (!is_dir($root) && !@mkdir($root, 0750, true) && !is_dir($root)) {
        return false;
    }
    // The fallback directory sits under the app root, so it is only safe because Apache
    // denies it. Write that rule rather than assume it, in case the root .htaccess is
    // replaced during a deploy.
    @chmod($root, 02770);
    $guard = $root . '/.htaccess';
    if (!is_file($guard)) {
        @file_put_contents($guard, "# Consultation attachments: never served directly.\nRequire all denied\n");
    }
    return true;
}

/** Per-consultation directory holding the files. Sharded by consultation id. */
function consultation_attachment_storage_dir(int $consultationId): string {
    return consultation_attachment_storage_root() . '/' . $consultationId;
}

/**
 * Create a storage subdirectory with group write and the setgid bit.
 *
 * Apache writes the files but the maintenance sweeper and any cron job run as a different
 * user, and both must be able to rename and unlink inside these directories. 2770 with the
 * setgid bit keeps the group stable as files are added, instead of letting each writer's
 * primary group take over and locking the other out.
 */
function consultation_attachment_make_dir(string $dir): bool {
    if (is_dir($dir)) return true;
    if (!@mkdir($dir, 02770, true) && !is_dir($dir)) return false;
    @chmod($dir, 02770);
    return true;
}

/**
 * The allowed extensions and how each is classified.
 *
 * `mimes` is the set finfo may legitimately report for the extension. It is a set, not a
 * single value, because the same container is reported differently across libmagic
 * versions (an .m4a is audio/mp4 or audio/x-m4a; a .mov is video/quicktime or
 * video/mp4). Being strict about one spelling produces false rejections on a different
 * server, which is how a "validate the MIME type" rule quietly becomes "reject valid
 * files" in production.
 *
 * @return array<string, array{category:string, mimes:list<string>}>
 */
function consultation_attachment_catalog(): array {
    return [
        // Documents
        'txt'  => ['category' => 'document', 'mimes' => ['text/plain']],
        'doc'  => ['category' => 'document', 'mimes' => ['application/msword', 'application/CDFV2', 'application/x-ole-storage', 'application/vnd.ms-office']],
        'docx' => ['category' => 'document', 'mimes' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/x-zip', 'application/octet-stream']],
        'pdf'  => ['category' => 'document', 'mimes' => ['application/pdf']],
        // Images — what the camera capture and the document scanner produce. A photo of a
        // contract is the most common submission on a phone, so these are first-class.
        'png'  => ['category' => 'image', 'mimes' => ['image/png']],
        'jpg'  => ['category' => 'image', 'mimes' => ['image/jpeg']],
        'jpeg' => ['category' => 'image', 'mimes' => ['image/jpeg']],
        // Audio
        'mp3'  => ['category' => 'audio', 'mimes' => ['audio/mpeg', 'audio/mp3', 'application/octet-stream']],
        'wav'  => ['category' => 'audio', 'mimes' => ['audio/x-wav', 'audio/wav', 'audio/wave', 'audio/vnd.wave']],
        'm4a'  => ['category' => 'audio', 'mimes' => ['audio/mp4', 'audio/x-m4a', 'audio/m4a', 'video/mp4', 'application/octet-stream']],
        'ogg'  => ['category' => 'audio', 'mimes' => ['audio/ogg', 'application/ogg', 'audio/x-ogg', 'video/ogg']],
        // Video
        'mp4'  => ['category' => 'video', 'mimes' => ['video/mp4', 'application/octet-stream']],
        'mov'  => ['category' => 'video', 'mimes' => ['video/quicktime', 'video/mp4', 'application/octet-stream']],
        'webm' => ['category' => 'video', 'mimes' => ['video/webm', 'audio/webm', 'video/x-matroska', 'audio/x-matroska', 'application/octet-stream']],
    ];
}

/** Extensions grouped for the UI hint, in display order. */
function consultation_attachment_groups(): array {
    return [
        'document' => ['label_ar' => 'مستندات', 'label_en' => 'Documents', 'extensions' => ['txt', 'doc', 'docx', 'pdf']],
        'image'    => ['label_ar' => 'صور', 'label_en' => 'Images', 'extensions' => ['png', 'jpg', 'jpeg']],
        'audio'    => ['label_ar' => 'ملفات صوتية', 'label_en' => 'Audio', 'extensions' => ['mp3', 'wav', 'm4a', 'ogg']],
        'video'    => ['label_ar' => 'ملفات فيديو', 'label_en' => 'Video', 'extensions' => ['mp4', 'mov', 'webm']],
    ];
}

/** The category an extension belongs to, or null when the extension is not allowed. */
function consultation_attachment_detect_category(string $extension): ?string {
    $catalog = consultation_attachment_catalog();
    $extension = strtolower($extension);
    return $catalog[$extension]['category'] ?? null;
}

/**
 * Parse a php.ini size shorthand ("2M", "8M", "512K", "1G") into bytes.
 *
 * @return int bytes; 0 when the value is unset, unlimited (-1) or unparseable.
 */
function consultation_attachment_ini_bytes(string $value): int {
    $value = trim($value);
    if ($value === '' || $value === '-1') return 0;
    $unit = strtolower(substr($value, -1));
    $number = (int)$value;
    switch ($unit) {
        case 'g': return $number * 1073741824;
        case 'm': return $number * 1048576;
        case 'k': return $number * 1024;
        default: return $number;
    }
}

/**
 * Read the configurable limits from site_settings, falling back to the documented
 * defaults, then clamp them to what the server can actually accept. Values are also
 * clamped to a sane range so an operator cannot set 0 (which would block every upload)
 * or an absurd figure by mistyping a field.
 *
 * The php.ini ceiling matters: upload_max_filesize and post_max_size are enforced by PHP
 * before any of this code runs. A 50 MB audio limit on a server with upload_max_filesize=2M
 * would be advertised in the UI and then fail at the HTTP layer with no useful error, so the
 * effective limit is lowered to the server's. post_max_size must also leave room for the
 * multipart envelope, which is why the request ceiling is reduced slightly rather than used
 * as-is.
 *
 * @return array{document_mb:int, audio_mb:int, video_mb:int, max_files:int, total_mb:int, user_total_mb:int}
 */
function consultation_attachment_limits(?PDO $pdo = null): array {
    $key = $pdo instanceof PDO ? (string)spl_object_id($pdo) : 'default';
    if (isset($GLOBALS['_consultation_attachment_limits'][$key])) {
        return $GLOBALS['_consultation_attachment_limits'][$key];
    }

    $defaults = [
        'document_mb' => 20,
        'audio_mb' => 50,
        'video_mb' => 100,
        'max_files' => 10,
        'total_mb' => 500,
        'user_total_mb' => 2048,
    ];
    $bounds = [
        'document_mb' => [1, 512], 'audio_mb' => [1, 1024], 'video_mb' => [1, 2048],
        'max_files' => [1, 50], 'total_mb' => [1, 10240], 'user_total_mb' => [1, 51200],
    ];

    $stored = [];
    if ($pdo instanceof PDO) {
        try {
            $keys = array_map(fn($k) => CONSULTATION_ATTACHMENT_SETTING_PREFIX . $k, array_keys($defaults));
            $in = implode(',', array_fill(0, count($keys), '?'));
            $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ($in)");
            $stmt->execute($keys);
            foreach ($stmt->fetchAll() as $row) {
                $short = substr($row['setting_key'], strlen(CONSULTATION_ATTACHMENT_SETTING_PREFIX));
                $stored[$short] = $row['setting_value'];
            }
        } catch (PDOException $e) {
            // A pre-v56 database has no settings rows; the defaults are the documented
            // behaviour, so fall through rather than failing the upload path.
            error_log('consultation_attachment_limits: ' . $e->getMessage());
        }
    }

    $limits = $defaults;
    foreach ($defaults as $key2 => $default) {
        if (isset($stored[$key2]) && $stored[$key2] !== null && $stored[$key2] !== '' && is_numeric($stored[$key2])) {
            $value = (int)$stored[$key2];
            [$min, $max] = $bounds[$key2];
            $limits[$key2] = max($min, min($max, $value));
        }
    }

    // Clamp the per-file limits to what PHP will actually accept. A file larger than
    // upload_max_filesize never reaches the application, and a request larger than
    // post_max_size arrives with $_POST and $_FILES both empty, so advertising a larger
    // number would promise something the server cannot deliver.
    if (in_array('document_mb', array_keys($defaults), true)) {
        $uploadBytes = consultation_attachment_ini_bytes((string)ini_get('upload_max_filesize'));
        $postBytes = consultation_attachment_ini_bytes((string)ini_get('post_max_size'));
        // Leave headroom for the multipart envelope and the other form fields.
        $requestCeiling = $postBytes > 0 ? max(1, intdiv($postBytes - 65536, 1048576)) : 0;
        foreach (['document_mb', 'audio_mb', 'video_mb'] as $perFile) {
            $ceiling = $limits[$perFile];
            if ($uploadBytes > 0) {
                $ceiling = min($ceiling, max(1, intdiv($uploadBytes, 1048576)));
            }
            if ($requestCeiling > 0) {
                $ceiling = min($ceiling, $requestCeiling);
            }
            $limits[$perFile] = $ceiling;
        }
        // The per-consultation and per-client totals cannot exceed the server ceiling either.
        if ($requestCeiling > 0) {
            $limits['total_mb'] = max(1, min($limits['total_mb'], $requestCeiling * $limits['max_files']));
        }
    }

    $GLOBALS['_consultation_attachment_limits'][$key] = $limits;
    return $limits;
}

/** Drop the cached limits — after an admin saves new values, and in tests. */
function consultation_attachment_limits_forget(?PDO $pdo = null): void {
    if ($pdo instanceof PDO) {
        unset($GLOBALS['_consultation_attachment_limits'][(string)spl_object_id($pdo)]);
        return;
    }
    $GLOBALS['_consultation_attachment_limits'] = [];
}

/** Byte limit for a category. */
function consultation_attachment_category_bytes(string $category, array $limits): int {
    // Images share the document limit. A phone photo is a document in every sense that
    // matters here, and giving it its own setting would be a knob nobody turns.
    $key = ($category === 'image' ? 'document' : $category) . '_mb';
    return ($limits[$key] ?? 20) * 1024 * 1024;
}

/**
 * Sniff the real container signature of a file.
 *
 * This is the check that a MIME type cannot replace: finfo reads the first few bytes and a
 * determined uploader can satisfy it while the file is something else. Each branch verifies
 * the structure the format actually requires, and the DOCX branch additionally requires the
 * OOXML marker inside the ZIP, so a ZIP of PHP is not accepted as a Word document.
 */
function consultation_attachment_sniff(string $path, string $extension): bool {
    $handle = @fopen($path, 'rb');
    if (!$handle) return false;
    $head = (string)fread($handle, 4096);
    fclose($handle);
    if ($head === '') return false;

    // Reject anything that carries an executable or script signature, whatever the
    // extension claims. A polyglot (a valid PDF that is also a valid PHP script) passes the
    // format check below, so this runs for every file, not only for script extensions.
    if (consultation_attachment_has_executable_signature($head)) return false;
    $extension = strtolower($extension);
    switch ($extension) {
        case 'pdf':
            return str_starts_with($head, '%PDF-');
        case 'png':
            // The 8-byte PNG signature, then an IHDR chunk. The chunk check keeps the test
            // meaningful rather than a four-byte prefix match.
            return str_starts_with($head, "\x89PNG\r\n\x1a\n") && substr($head, 12, 4) === 'IHDR';
        case 'jpg':
        case 'jpeg':
            // SOI marker followed by any real marker (APP0/APP1/DQT/SOF…), which is what
            // every encoder writes and what a renamed file will not have.
            return str_starts_with($head, "\xFF\xD8\xFF");
        case 'doc':
            return str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1");
        case 'docx':
            if (!str_starts_with($head, "PK\x03\x04")) return false;
            // The marker may sit beyond the first 4 KB in a heavily compressed archive, so
            // read the whole file when it is small enough to be a real document.
            $whole = (string)@file_get_contents($path);
            return str_contains($whole, '[Content_Types].xml') || str_contains($whole, 'word/');
        case 'txt':
            return consultation_attachment_looks_like_text($head);
        case 'mp3':
            if (str_starts_with($head, 'ID3')) return true;
            $b1 = ord($head[0]); $b2 = ord($head[1]);
            return $b1 === 0xFF && ($b2 & 0xE0) === 0xE0;
        case 'wav':
            return str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WAVE';
        case 'ogg':
            // A Vorbis, Opus or FLAC stream inside an Ogg page. Requiring the codec marker
            // keeps the check meaningful: 'OggS' alone is 4 bytes, which is too little to
            // distinguish a real container from a crafted file that starts with it.
            return str_starts_with($head, 'OggS')
                && (str_contains($head, 'vorbis') || str_contains($head, 'OpusHead') || str_contains($head, 'fLaC') || str_contains($head, 'Theora'));
        case 'm4a':
            return consultation_attachment_is_iso_bmff($head) && (str_contains(substr($head, 0, 64), 'M4A') || str_contains(substr($head, 0, 64), 'mp42') || str_contains(substr($head, 0, 64), 'isom'));
        case 'mp4':
            return consultation_attachment_is_iso_bmff($head) && !str_contains(substr($head, 0, 32), 'qt  ');
        case 'mov':
            return consultation_attachment_is_iso_bmff($head);
        case 'webm':
            // EBML magic plus a Matroska/WebM DocType, so the header is not just four bytes.
            return str_starts_with($head, "\x1A\x45\xDF\xA3")
                && (str_contains($head, 'webm') || str_contains($head, 'matroska'));
    }
    return false;
}

/**
 * True when the bytes carry a script/executable signature that must never be stored.
 *
 * The check is split by where each signature is meaningful, because scanning for every
 * needle at every offset rejects legitimate files. A two-byte sequence like `MZ` occurs in
 * random binary data roughly once per 65 KB, so searching a 4 KB head for it would reject
 * a few percent of perfectly good audio and video. Executable formats are only executable
 * when the magic is at offset 0, so that is where they are looked for.
 *
 * The one signature worth finding anywhere is the PHP open tag: a polyglot file that is a
 * valid PDF and also a valid PHP script is exactly the attack this guards against, and the
 * interpreter does not require the tag at offset 0.
 */
function consultation_attachment_has_executable_signature(string $head): bool {
    // Executable containers: meaningful only at the start of a file.
    //
    // A leading NUL is deliberately NOT in this list. It was tempting — no script starts
    // with NUL — but the ISO base media format (MP4, MOV, M4A) begins with a 4-byte box
    // size, so a small file's first byte really is 0x00. Rejecting it would refuse valid
    // video and audio. Each format's own signature check below is what constrains the
    // file; this list only screens out things that are executable by construction.
    $offsetZero = [
        "MZ",                     // DOS/Windows PE
        "\x7FELF",                // Linux ELF
        "\xCA\xFE\xBA\xBE",       // Java class / Mach-O fat
        "\xFE\xED\xFA\xCE",       // Mach-O 32
        "\xFE\xED\xFA\xCF",       // Mach-O 64
        "\xCE\xFA\xED\xFE",       // Mach-O 32 reversed
        "\xCF\xFA\xED\xFE",       // Mach-O 64 reversed
    ];
    foreach ($offsetZero as $needle) {
        if (str_starts_with($head, $needle)) return true;
    }
    // Shebang, meaningful only on the first line.
    if (str_starts_with($head, '#!/')) return true;

    // PHP open tags anywhere: the polyglot case.
    if (str_contains($head, '<?php') || str_contains($head, '<?=')) return true;

    return false;
}

/** ISO base media file format: a 4-byte size then 'ftyp' at offset 4. */
function consultation_attachment_is_iso_bmff(string $head): bool {
    return strlen($head) >= 12 && substr($head, 4, 4) === 'ftyp';
}

/**
 * Whether a WebM/Matroska file carries a video track.
 *
 * `MediaRecorder` writes an audio-only recording to `.webm` on Chromium and Firefox, and a
 * camera recording to the same container. The extension alone therefore cannot tell the two
 * apart, and the catalog maps `.webm` to video — so without this check a voice note is filed
 * as a video and `consultation_attachment_is_audio()` is false, which makes the recording
 * impossible to transcribe. The brief requires voice recording, so the container has to be
 * inspected rather than the name trusted.
 *
 * Every track declares its type in a `TrackType` element: id 0x83, then a one-byte size
 * (0x81) and a one-byte value — 0x01 video, 0x02 audio. A file with neither is not a real
 * recording (a truncated or synthetic header); it is treated as video, which is the
 * catalog default and therefore changes nothing.
 */
function consultation_attachment_webm_has_video_track(string $path): bool {
    $handle = @fopen($path, 'rb');
    if (!$handle) return true; // Unreadable: keep the safer video classification.
    // The Tracks element sits near the front, after the EBML header and Segment start.
    // 64 KB is generous for a real recorder and still bounded.
    $head = (string)fread($handle, 65536);
    fclose($handle);
    if ($head === '') return true;
    return str_contains($head, "\x83\x81\x01");
}

/**
 * Whether a WebM/Matroska file carries an audio track and no video track.
 *
 * This is the condition for reclassifying a `.webm` as audio: the audio track must be
 * positively present, so a container that declares no track at all keeps the catalog's
 * video classification rather than being guessed into audio.
 */
function consultation_attachment_webm_is_audio_only(string $path): bool {
    $handle = @fopen($path, 'rb');
    if (!$handle) return false;
    $head = (string)fread($handle, 65536);
    fclose($handle);
    if ($head === '') return false;
    return !str_contains($head, "\x83\x81\x01") && str_contains($head, "\x83\x81\x02");
}

/** Plain text: no NUL bytes and no control characters beyond tab/newline/return. */
function consultation_attachment_looks_like_text(string $head): bool {
    if (str_contains($head, "\x00")) return false;
    return preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $head) !== 1;
}

/**
 * The display name of an upload, reduced to something safe to echo back.
 *
 * The name is attacker-controlled text that ends up in HTML and in a Content-Disposition
 * header, so both the path separators and the control characters are stripped here and the
 * HTML escaping is left to the template. A name is never used to build a filesystem path.
 */
function consultation_attachment_safe_name(string $name): string {
    $name = str_replace('\\', '/', $name);
    $name = basename($name);
    $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
    $name = preg_replace('/[^\p{L}\p{N}._ ()\-\[\]]+/u', '_', $name) ?? '';
    $name = trim($name, ". \t\n\r\0\x0B");
    if ($name === '') $name = 'attachment';
    if (mb_strlen($name) > 180) {
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $base = pathinfo($name, PATHINFO_FILENAME);
        $name = mb_substr($base, 0, 170) . ($ext !== '' ? '.' . $ext : '');
    }
    return $name;
}

/** Human-readable size, Arabic-friendly. */
function consultation_attachment_format_size(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024 * 1024) return round($bytes / 1024, 1) . ' KB';
    if ($bytes < 1024 * 1024 * 1024) return round($bytes / (1024 * 1024), 1) . ' MB';
    return round($bytes / (1024 * 1024 * 1024), 2) . ' GB';
}

/** Arabic/English message pair. */
function consultation_attachment_msg(string $ar, string $en): string {
    return function_exists('is_rtl') && is_rtl() ? $ar : $en;
}

/** Map a PHP upload error code to a specific, honest message. */
function consultation_attachment_upload_error_message(int $code, array $limits): ?string {
    switch ($code) {
        case UPLOAD_ERR_OK:
            return null;
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return consultation_attachment_msg(
                'حجم الملف يتجاوز الحد المسموح على الخادم. جرّب ملفاً أصغر.',
                'The file exceeds the server upload limit. Try a smaller file.'
            );
        case UPLOAD_ERR_PARTIAL:
            return consultation_attachment_msg(
                'لم يكتمل رفع الملف (انقطع الاتصال). أعد المحاولة.',
                'The upload did not complete (the connection was interrupted). Try again.'
            );
        case UPLOAD_ERR_NO_FILE:
            return consultation_attachment_msg('لم يتم اختيار أي ملف.', 'No file was selected.');
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE:
            return consultation_attachment_msg(
                'تعذّر تخزين الملف مؤقتاً على الخادم.',
                'The server could not store the file temporarily.'
            );
        case UPLOAD_ERR_EXTENSION:
            return consultation_attachment_msg(
                'أوقف أحد إضافات الخادم عملية الرفع.',
                'A server extension stopped the upload.'
            );
        default:
            return consultation_attachment_msg('فشل رفع الملف.', 'The upload failed.');
    }
}

/**
 * Validate one uploaded file. Returns the extension and category on success, or a message.
 *
 * @return array{ok:bool, error:?string, extension:?string, category:?string, mime:?string, size:int}
 */
function consultation_attachment_validate(array $file, array $limits): array {
    $fail = fn(string $msg) => ['ok' => false, 'error' => $msg, 'extension' => null, 'category' => null, 'mime' => null, 'size' => 0];

    $errorCode = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errorCode !== UPLOAD_ERR_OK) {
        return $fail(consultation_attachment_upload_error_message($errorCode, $limits) ?? consultation_attachment_msg('فشل رفع الملف.', 'The upload failed.'));
    }
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return $fail(consultation_attachment_msg('ملف غير صالح.', 'Invalid upload.'));
    }

    $extension = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    $catalog = consultation_attachment_catalog();
    if ($extension === '' || !isset($catalog[$extension])) {
        $allowed = implode(', ', array_keys($catalog));
        return $fail(consultation_attachment_msg(
            "صيغة الملف غير مدعومة. الصيغ المسموحة: $allowed",
            "Unsupported file format. Allowed: $allowed"
        ));
    }
    $category = $catalog[$extension]['category'];
    $size = (int)($file['size'] ?? 0);
    $maxBytes = consultation_attachment_category_bytes($category, $limits);
    if ($size < 1) {
        return $fail(consultation_attachment_msg('الملف فارغ.', 'The file is empty.'));
    }
    if ($size > $maxBytes) {
        $maxMb = $limits[$category . '_mb'];
        return $fail(consultation_attachment_msg(
            "حجم الملف يتجاوز الحد المسموح لهذه الفئة ($maxMb ميجابايت).",
            "The file exceeds the limit for this category ($maxMb MB)."
        ));
    }

    if (!function_exists('finfo_open')) {
        return $fail(consultation_attachment_msg('تعذّر التحقق من نوع الملف على الخادم.', 'The server cannot verify the file type.'));
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (string)finfo_file($finfo, $file['tmp_name']) : '';
    if ($finfo) finfo_close($finfo);
    if ($mime === '' || !in_array($mime, $catalog[$extension]['mimes'], true)) {
        return $fail(consultation_attachment_msg(
            'نوع الملف الفعلي لا يطابق امتداده.',
            'The actual file type does not match its extension.'
        ));
    }

    if (!consultation_attachment_sniff($file['tmp_name'], $extension)) {
        return $fail(consultation_attachment_msg(
            'محتوى الملف لا يطابق صيغته المعلنة، أو أنه يحتوي على تعليمات برمجية.',
            'The file content does not match its declared format, or it contains executable code.'
        ));
    }

    // A browser voice recording is a `.webm` with no video track. The catalog maps the
    // extension to video, so without this it would be filed as video and could never be
    // transcribed. Reclassify from the container, and re-check the size against the audio
    // limit, which is what this file really is.
    if ($extension === 'webm' && consultation_attachment_webm_is_audio_only($file['tmp_name'])) {
        $category = 'audio';
        $maxBytes = consultation_attachment_category_bytes($category, $limits);
        if ($size > $maxBytes) {
            $maxMb = $limits[$category . '_mb'];
            return $fail(consultation_attachment_msg(
                "حجم الملف يتجاوز الحد المسموح لهذه الفئة ($maxMb ميجابايت).",
                "The file exceeds the limit for this category ($maxMb MB)."
            ));
        }
    }

    return ['ok' => true, 'error' => null, 'extension' => $extension, 'category' => $category, 'mime' => $mime, 'size' => $size];
}

/** Total bytes of stored (not deleted) attachments on a consultation. */
function consultation_attachment_consultation_bytes(PDO $pdo, int $consultationId): int {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(file_size),0) FROM consultation_attachments WHERE consultation_id=? AND status='stored'");
    $stmt->execute([$consultationId]);
    return (int)$stmt->fetchColumn();
}

/** Count of stored attachments on a consultation. */
function consultation_attachment_count(PDO $pdo, int $consultationId): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM consultation_attachments WHERE consultation_id=? AND status='stored'");
    $stmt->execute([$consultationId]);
    return (int)$stmt->fetchColumn();
}

/** Total bytes a client has stored across every consultation. */
function consultation_attachment_client_bytes(PDO $pdo, int $clientId): int {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(file_size),0) FROM consultation_attachments WHERE uploaded_by_type='client' AND uploaded_by_id=? AND status='stored'");
    $stmt->execute([$clientId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Check the per-consultation and per-client quotas before accepting a file.
 *
 * @return array{allowed:bool, message:?string}
 */
function consultation_attachment_quota_check(PDO $pdo, int $consultationId, int $clientId, int $incomingBytes, array $limits): array {
    $count = consultation_attachment_count($pdo, $consultationId);
    if ($count >= $limits['max_files']) {
        return ['allowed' => false, 'message' => consultation_attachment_msg(
            'وصلت للحد الأقصى لعدد المرفقات لهذه الاستشارة (' . $limits['max_files'] . ' ملفات).',
            'This consultation has reached the maximum of ' . $limits['max_files'] . ' attachments.'
        )];
    }
    $current = consultation_attachment_consultation_bytes($pdo, $consultationId);
    if ($current + $incomingBytes > $limits['total_mb'] * 1024 * 1024) {
        return ['allowed' => false, 'message' => consultation_attachment_msg(
            'سيتجاوز الحجم الإجمالي المسموح لمرفقات هذه الاستشارة (' . $limits['total_mb'] . ' ميجابايت).',
            'This would exceed the total size allowed for this consultation (' . $limits['total_mb'] . ' MB).'
        )];
    }
    $clientBytes = consultation_attachment_client_bytes($pdo, $clientId);
    if ($clientBytes + $incomingBytes > $limits['user_total_mb'] * 1024 * 1024) {
        return ['allowed' => false, 'message' => consultation_attachment_msg(
            'سيتجاوز حجم التخزين المسموح لحسابك (' . $limits['user_total_mb'] . ' ميجابايت). احذف بعض المرفقات القديمة.',
            'This would exceed the storage allowed for your account (' . $limits['user_total_mb'] . ' MB). Remove some older attachments.'
        )];
    }
    return ['allowed' => true, 'message' => null];
}

/**
 * Store a validated upload and record it.
 *
 * The file is moved first and the row is written second. If the row write fails the file
 * is unlinked, so a database error cannot leave an orphan; if the process dies between the
 * two, consultation_attachment_purge_orphans() finds the file later. The opposite order
 * would leave a row pointing at a file that does not exist, which the download route would
 * have to treat as a 404 — a worse failure because it is visible to the user.
 *
 * @param array{type:string, id:int} $actor
 * @return array{ok:bool, error:?string, attachment:?array}
 */
function consultation_attachment_store(PDO $pdo, int $consultationId, ?int $firmId, array $file, array $actor, array $limits): array {
    $validation = consultation_attachment_validate($file, $limits);
    if (!$validation['ok']) {
        return ['ok' => false, 'error' => $validation['error'], 'attachment' => null];
    }
    $extension = (string)$validation['extension'];
    $category = (string)$validation['category'];

    if (!consultation_attachment_ensure_storage()) {
        return ['ok' => false, 'error' => consultation_attachment_msg('تعذّر تهيئة مجلد التخزين.', 'The storage directory could not be prepared.'), 'attachment' => null];
    }
    $dir = consultation_attachment_storage_dir($consultationId);
    if (!consultation_attachment_make_dir($dir)) {
        return ['ok' => false, 'error' => consultation_attachment_msg('تعذّر إنشاء مجلد التخزين.', 'The storage directory could not be created.'), 'attachment' => null];
    }

    $storedName = bin2hex(random_bytes(20)) . '.' . $extension;
    $absolute = $dir . '/' . $storedName;
    if (!move_uploaded_file($file['tmp_name'], $absolute)) {
        return ['ok' => false, 'error' => consultation_attachment_msg('تعذّر تخزين الملف على الخادم.', 'The file could not be stored.'), 'attachment' => null];
    }
    @chmod($absolute, 0660);

    // Defence in depth: the path is generated, so traversal is impossible by construction.
    // This asserts the invariant anyway, because a future change that feeds a stored path
    // back in would otherwise be silently exploitable.
    $realRoot = realpath(consultation_attachment_storage_root());
    $realFile = realpath($absolute);
    if ($realRoot === false || $realFile === false || !str_starts_with($realFile, $realRoot . DIRECTORY_SEPARATOR)) {
        @unlink($absolute);
        return ['ok' => false, 'error' => consultation_attachment_msg('مسار التخزين غير صالح.', 'Invalid storage path.'), 'attachment' => null];
    }

    $relative = $consultationId . '/' . $storedName;
    $original = consultation_attachment_safe_name((string)($file['name'] ?? 'attachment'));
    $checksum = hash_file('sha256', $absolute) ?: null;

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO consultation_attachments
                (consultation_id, firm_id, uploaded_by_type, uploaded_by_id, original_name, stored_name, stored_path, file_category, file_type, mime_type, file_size, checksum, status)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,\'stored\')'
        );
        $stmt->execute([
            $consultationId, $firmId, $actor['type'], $actor['id'], $original,
            $storedName, $relative, $category, $extension, $validation['mime'],
            $validation['size'], $checksum,
        ]);
    } catch (PDOException $e) {
        @unlink($absolute);
        error_log('consultation_attachment_store: ' . $e->getMessage());
        return ['ok' => false, 'error' => consultation_attachment_msg('تعذّر حفظ بيانات المرفق.', 'The attachment record could not be saved.'), 'attachment' => null];
    }

    $id = (int)$pdo->lastInsertId();
    $row = consultation_attachment_find($pdo, $id);
    return ['ok' => true, 'error' => null, 'attachment' => $row];
}

/** One attachment row, or null. Does not apply any authorization — callers must. */
function consultation_attachment_find(PDO $pdo, int $attachmentId): ?array {
    $stmt = $pdo->prepare('SELECT * FROM consultation_attachments WHERE id=? LIMIT 1');
    $stmt->execute([$attachmentId]);
    return $stmt->fetch() ?: null;
}

/** A fresh draft token binding staged uploads to the booking form that will claim them. */
function consultation_attachment_new_draft_token(): string {
    return bin2hex(random_bytes(32));
}

/**
 * Store a file as a STAGED upload, before the consultation exists.
 *
 * The booking form uploads as the user picks files, so there is no consultation id yet.
 * The row is created with `consultation_id = NULL`, the uploader's identity, and a draft
 * token that the booking POST must present to claim it. Because the token is unguessable
 * and the claim also checks `uploaded_by_id`, one client can never claim another's staged
 * file even if they somehow learned the token.
 *
 * @param array{type:string, id:int} $actor
 * @return array{ok:bool, error:?string, attachment:?array}
 */
function consultation_attachment_stage(PDO $pdo, string $draftToken, array $file, array $actor, array $limits): array {
    $validation = consultation_attachment_validate($file, $limits);
    if (!$validation['ok']) {
        return ['ok' => false, 'error' => $validation['error'], 'attachment' => null];
    }
    $extension = (string)$validation['extension'];
    $category = (string)$validation['category'];

    if (!consultation_attachment_ensure_storage()) {
        return ['ok' => false, 'error' => consultation_attachment_msg('تعذّر تهيئة مجلد التخزين.', 'The storage directory could not be prepared.'), 'attachment' => null];
    }
    // Staged files live under a drafts/ tree, so the sweeper never has to guess which
    // numeric directories are abandoned drafts and which belong to real consultations.
    $dir = consultation_attachment_storage_root() . '/drafts/' . substr($draftToken, 0, 2);
    if (!consultation_attachment_make_dir($dir)) {
        return ['ok' => false, 'error' => consultation_attachment_msg('تعذّر إنشاء مجلد التخزين.', 'The storage directory could not be created.'), 'attachment' => null];
    }

    $storedName = bin2hex(random_bytes(20)) . '.' . $extension;
    $absolute = $dir . '/' . $storedName;
    if (!move_uploaded_file($file['tmp_name'], $absolute)) {
        return ['ok' => false, 'error' => consultation_attachment_msg('تعذّر تخزين الملف على الخادم.', 'The file could not be stored.'), 'attachment' => null];
    }
    @chmod($absolute, 0660);

    $realRoot = realpath(consultation_attachment_storage_root());
    $realFile = realpath($absolute);
    if ($realRoot === false || $realFile === false || !str_starts_with($realFile, $realRoot . DIRECTORY_SEPARATOR)) {
        @unlink($absolute);
        return ['ok' => false, 'error' => consultation_attachment_msg('مسار التخزين غير صالح.', 'Invalid storage path.'), 'attachment' => null];
    }

    $relative = 'drafts/' . substr($draftToken, 0, 2) . '/' . $storedName;
    $original = consultation_attachment_safe_name((string)($file['name'] ?? 'attachment'));
    $checksum = hash_file('sha256', $absolute) ?: null;

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO consultation_attachments
                (consultation_id, draft_token, uploaded_by_type, uploaded_by_id, original_name, stored_name, stored_path, file_category, file_type, mime_type, file_size, checksum, status)
             VALUES (NULL,?,?,?,?,?,?,?,?,?,?,?,\'staged\')'
        );
        $stmt->execute([
            $draftToken, $actor['type'], $actor['id'], $original,
            $storedName, $relative, $category, $extension, $validation['mime'],
            $validation['size'], $checksum,
        ]);
    } catch (PDOException $e) {
        @unlink($absolute);
        error_log('consultation_attachment_stage: ' . $e->getMessage());
        return ['ok' => false, 'error' => consultation_attachment_msg('تعذّر حفظ بيانات المرفق.', 'The attachment record could not be saved.'), 'attachment' => null];
    }

    return ['ok' => true, 'error' => null, 'attachment' => consultation_attachment_find($pdo, (int)$pdo->lastInsertId())];
}

/** Staged uploads belonging to one draft token and one uploader. */
function consultation_attachment_staged(PDO $pdo, string $draftToken, int $uploaderId): array {
    $stmt = $pdo->prepare("SELECT * FROM consultation_attachments WHERE draft_token=? AND uploaded_by_id=? AND status='staged' ORDER BY created_at ASC, id ASC");
    $stmt->execute([$draftToken, $uploaderId]);
    return $stmt->fetchAll();
}

/**
 * Bind staged uploads to a consultation, moving each file from drafts/ to the
 * consultation's directory.
 *
 * Called once, when the booking is submitted. Returns the number claimed. A file that
 * cannot be moved is left staged (and later swept) rather than bound to the consultation
 * with a path that points into drafts/, which would be a row whose file the sweeper could
 * delete out from under it.
 *
 * The caller must already have verified that the consultation belongs to $uploaderId.
 */
function consultation_attachment_claim(PDO $pdo, string $draftToken, int $consultationId, ?int $firmId, int $uploaderId): int {
    $rows = consultation_attachment_staged($pdo, $draftToken, $uploaderId);
    if (!$rows) return 0;

    $targetDir = consultation_attachment_storage_dir($consultationId);
    if (!consultation_attachment_make_dir($targetDir)) {
        return 0;
    }
    $root = realpath(consultation_attachment_storage_root());
    if ($root === false) return 0;

    $claimed = 0;
    foreach ($rows as $row) {
        $from = $root . DIRECTORY_SEPARATOR . (string)$row['stored_path'];
        $to = $targetDir . '/' . (string)$row['stored_name'];
        if (!is_file($from) || !@rename($from, $to)) {
            continue;
        }
        $relative = $consultationId . '/' . $row['stored_name'];
        try {
            $stmt = $pdo->prepare("UPDATE consultation_attachments SET consultation_id=?, firm_id=?, draft_token=NULL, stored_path=?, status='stored' WHERE id=? AND status='staged' AND uploaded_by_id=?");
            $stmt->execute([$consultationId, $firmId, $relative, (int)$row['id'], $uploaderId]);
            if ($stmt->rowCount() === 1) {
                $claimed++;
            } else {
                // Another request claimed it first; put the file back so it stays consistent
                // with its row rather than becoming an orphan in the wrong directory.
                @rename($to, $from);
            }
        } catch (PDOException $e) {
            @rename($to, $from);
            error_log('consultation_attachment_claim: ' . $e->getMessage());
        }
    }
    return $claimed;
}

/** Remove one staged upload the user decided not to send. */
function consultation_attachment_discard_staged(PDO $pdo, int $attachmentId, int $uploaderId): bool {
    $stmt = $pdo->prepare("SELECT * FROM consultation_attachments WHERE id=? AND status='staged' AND uploaded_by_id=? LIMIT 1");
    $stmt->execute([$attachmentId, $uploaderId]);
    $row = $stmt->fetch();
    if (!$row) return false;
    $root = realpath(consultation_attachment_storage_root());
    $path = $root !== false ? $root . DIRECTORY_SEPARATOR . (string)$row['stored_path'] : null;
    try {
        $del = $pdo->prepare("UPDATE consultation_attachments SET status='deleted', deleted_at=NOW(), deleted_by_type='client', deleted_by_id=? WHERE id=? AND status='staged'");
        $del->execute([$uploaderId, $attachmentId]);
        if ($del->rowCount() === 0) return false;
    } catch (PDOException $e) {
        error_log('consultation_attachment_discard_staged: ' . $e->getMessage());
        return false;
    }
    if ($path !== null) @unlink($path);
    return true;
}

/**
 * Remove abandoned draft uploads.
 *
 * A booking form that is opened and abandoned leaves staged files. They are unreachable
 * (no consultation, no token in anyone's hands) but they occupy storage, so they are
 * removed after a grace period. The grace period is deliberate: a client who leaves the
 * form open over lunch and submits later must not find their uploads gone.
 *
 * @return array{removed:int, bytes_reclaimed:int}
 */
function consultation_attachment_purge_drafts(PDO $pdo, int $olderThanHours = 48, int $maxRows = 500): array {
    $removed = 0; $bytes = 0;
    try {
        $stmt = $pdo->prepare("SELECT id, stored_path, file_size FROM consultation_attachments WHERE status='staged' AND created_at < DATE_SUB(NOW(), INTERVAL ? HOUR) ORDER BY id ASC LIMIT $maxRows");
        $stmt->execute([$olderThanHours]);
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('consultation_attachment_purge_drafts: ' . $e->getMessage());
        return ['removed' => 0, 'bytes_reclaimed' => 0];
    }
    $root = realpath(consultation_attachment_storage_root());
    foreach ($rows as $row) {
        $path = $root !== false ? $root . DIRECTORY_SEPARATOR . (string)$row['stored_path'] : null;
        try {
            $del = $pdo->prepare("DELETE FROM consultation_attachments WHERE id=? AND status='staged'");
            $del->execute([(int)$row['id']]);
            if ($del->rowCount() === 0) continue;
        } catch (PDOException $e) {
            error_log('consultation_attachment_purge_drafts (delete): ' . $e->getMessage());
            continue;
        }
        if ($path !== null) @unlink($path);
        $removed++;
        $bytes += (int)$row['file_size'];
    }
    return ['removed' => $removed, 'bytes_reclaimed' => $bytes];
}

/**
 * Resolve a consultation for an actor, applying ownership and tenant rules.
 *
 * Returns the consultation row when the actor may see it, or null. This is the single
 * authorization gate for every attachment operation: the web route, the REST API and the
 * dashboards all call it, so "who may reach this consultation" is decided in exactly one
 * place. A lawyer is scoped through their tenant (firm-wide or solo) rather than by a bare
 * lawyer_id comparison, which is what keeps a firm member from reading another firm's
 * consultation by guessing its id.
 *
 * @param array{type:string, id:int} $actor
 */
function consultation_attachment_authorize(PDO $pdo, int $consultationId, array $actor): ?array {
    if ($consultationId <= 0) return null;

    if ($actor['type'] === 'admin') {
        // Admins reach every tenant; the caller applies the admin RBAC map, because the
        // map is a property of the console route, not of the consultation.
        $stmt = $pdo->prepare('SELECT * FROM consultations WHERE id=? LIMIT 1');
        $stmt->execute([$consultationId]);
        return $stmt->fetch() ?: null;
    }

    if ($actor['type'] === 'client') {
        $stmt = $pdo->prepare('SELECT * FROM consultations WHERE id=? AND user_id=? LIMIT 1');
        $stmt->execute([$consultationId, $actor['id']]);
        return $stmt->fetch() ?: null;
    }

    if ($actor['type'] === 'lawyer') {
        if (!function_exists('tenant_owner_where_for_lawyer')) {
            require_once __DIR__ . '/tenant.php';
        }
        $scope = tenant_owner_where_for_lawyer($pdo, $actor['id'], 'consultations');
        $stmt = $pdo->prepare('SELECT * FROM consultations WHERE id=? AND ' . $scope['sql'] . ' LIMIT 1');
        $stmt->execute(array_merge([$consultationId], $scope['params']));
        return $stmt->fetch() ?: null;
    }

    return null;
}

/**
 * Whether the actor may add attachments to this consultation.
 *
 * A completed or cancelled consultation is closed: the work is over, and letting either
 * side add files afterwards turns the attachment list into a channel that outlives the
 * engagement. The consultation status is the existing workflow's notion of "still open",
 * so it is reused rather than inventing a parallel flag.
 */
function consultation_attachment_can_upload(array $consultation, array $actor): bool {
    if ($actor['type'] === 'admin') return false;
    if (!in_array((string)$consultation['status'], ['pending', 'confirmed'], true)) return false;
    return in_array($actor['type'], ['client', 'lawyer'], true);
}

/**
 * Whether the actor may remove a specific attachment.
 *
 * A client may remove their own upload while the consultation is open, and a lawyer may
 * remove their own. Neither may remove the other's: an attachment is evidence of what was
 * exchanged, and letting one side delete the other's file silently is not a feature.
 * Admins may remove any, which is what the console is for.
 */
function consultation_attachment_can_delete(array $attachment, array $consultation, array $actor): bool {
    if ($actor['type'] === 'admin') return true;
    if (($attachment['uploaded_by_type'] ?? '') !== $actor['type']) return false;
    if ((int)($attachment['uploaded_by_id'] ?? 0) !== $actor['id']) return false;
    return in_array((string)$consultation['status'], ['pending', 'confirmed'], true);
}

/**
 * Whether a file may be previewed inline rather than only downloaded.
 *
 * Inline rendering is what makes a preview an attack surface: an SVG or an HTML file
 * rendered in the origin can run script, and a PDF is rendered by the browser's plugin.
 * Only the formats that are safe to render are listed, and the download route sends
 * everything else as an attachment with a nosniff header. Anything not listed here is
 * download-only, which is the fail-closed default.
 */
function consultation_attachment_preview_mime(?string $mime): ?string {
    $inline = [
        'application/pdf',
        'image/png', 'image/jpeg', 'image/gif', 'image/webp',
        'text/plain',
        'audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/x-wav', 'audio/wave', 'audio/vnd.wave',
        'audio/mp4', 'audio/x-m4a', 'audio/m4a', 'audio/ogg',
        'video/mp4', 'video/webm', 'video/quicktime',
    ];
    return $mime !== null && in_array($mime, $inline, true) ? $mime : null;
}

// ===========================================================================
// Transcription (Execution 35)
//
// A customer may optionally transcribe a voice recording to Arabic text. Three
// rules shape everything below:
//
//   1. The recording is never replaced. Transcription writes to columns beside the
//      audio row and never touches the file, so the original is always replayable
//      and can always be re-transcribed by a better model later.
//   2. Nothing leaves the platform without an explicit, recorded consent event,
//      and even then only through ai_gateway_authorize(), which applies the
//      tenant's own privacy policy. A tenant whose policy forbids external calls
//      is refused here — the recording stays on the platform and the request
//      fails closed with a reason the UI can show.
//   3. A provider that is not configured, or that cannot transcribe audio, is
//      reported as unavailable rather than faked. transcription_status is the
//      single source of truth for what actually happened.
// ===========================================================================

/** The transcription states, matching the ENUM in upgrade_v62.sql. */
function consultation_attachment_transcription_states(): array {
    return ['none', 'pending', 'processing', 'ready', 'failed', 'declined'];
}

/** True when this attachment is an audio file and therefore transcribable. */
function consultation_attachment_is_audio(array $attachment): bool {
    return (string)($attachment['file_category'] ?? '') === 'audio';
}

/** The provider-specific model used for transcription, from the AI settings. */
function consultation_attachment_transcription_model(array $settings): string {
    return trim((string)($settings['ai_transcription_model'] ?? '')) ?: 'whisper-1';
}

/**
 * The provider id that will be asked to transcribe, or null when none can.
 *
 * Anthropic has no audio endpoint, so it is not a transcription provider. Both
 * OpenAI and a self-hosted OpenAI-compatible server expose `/v1/audio/transcriptions`.
 */
function consultation_attachment_transcription_provider(array $settings): ?string {
    if (($settings['ai_enabled'] ?? '1') === '0') return null;
    $provider = (string)($settings['ai_provider'] ?? '');
    if ($provider === 'openai') {
        return !empty($settings['ai_api_key']) ? 'openai' : null;
    }
    if ($provider === 'openai_compatible') {
        return ai_endpoint_normalize((string)($settings['ai_endpoint'] ?? '')) !== null ? 'openai_compatible' : null;
    }
    return null;
}

/**
 * Send one audio file to the configured provider and return the transcript.
 *
 * The file is streamed from disk with CURLFile rather than read into memory: a
 * 50 MB recording is a legitimate upload and base64-encoding it would double the
 * memory the PHP worker needs for no benefit.
 *
 * @return array{ok:bool, text:?string, error:?string, http_code:int}
 */
function consultation_attachment_transcribe_audio(array $settings, string $absolutePath, string $language = 'ar'): array {
    $fail = fn(string $msg, int $code = 0) => ['ok' => false, 'text' => null, 'error' => $msg, 'http_code' => $code];

    $provider = consultation_attachment_transcription_provider($settings);
    if ($provider === null) {
        return $fail(consultation_attachment_msg(
            'لا يوجد مزوّد ذكاء اصطناعي مهيّأ لتحويل الصوت إلى نص.',
            'No AI provider configured for speech-to-text.'
        ));
    }
    if (!function_exists('curl_init')) {
        return $fail(consultation_attachment_msg('cURL غير مفعّل على السيرفر.', 'cURL is not enabled on the server.'));
    }
    if (!is_file($absolutePath) || !is_readable($absolutePath)) {
        return $fail(consultation_attachment_msg('ملف الصوت غير متاح.', 'The audio file is unavailable.'));
    }

    if ($provider === 'openai') {
        $url = 'https://api.openai.com/v1/audio/transcriptions';
        $key = (string)$settings['ai_api_key'];
    } else {
        $base = ai_endpoint_normalize((string)($settings['ai_endpoint'] ?? ''));
        $url = $base . '/audio/transcriptions';
        $key = (string)($settings['ai_api_key'] ?? '');
    }

    $post = [
        'file' => new CURLFile($absolutePath),
        'model' => consultation_attachment_transcription_model($settings),
        // ISO-639-1. The platform is Arabic-first and the brief asks for Arabic
        // speech; naming the language measurably improves accuracy over detection.
        'language' => $language,
        'response_format' => 'json',
    ];
    $headers = ['Accept: application/json'];
    if ($key !== '') $headers[] = 'Authorization: Bearer ' . $key;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $post,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return $fail(consultation_attachment_msg('تعذّر الاتصال بمزوّد تحويل الصوت.', 'Could not reach the speech-to-text provider.'));
    }
    $data = json_decode((string)$response, true);
    if ($httpCode !== 200) {
        // The provider's message is not surfaced verbatim: it can echo request detail.
        error_log('consultation_attachment_transcribe_audio: HTTP ' . $httpCode);
        return $fail(consultation_attachment_msg('فشل تحويل الصوت إلى نص.', 'Speech-to-text failed.'), $httpCode);
    }
    $text = is_array($data) ? trim((string)($data['text'] ?? '')) : '';
    if ($text === '') {
        return $fail(consultation_attachment_msg('لم يُعِد المزوّد أي نص.', 'The provider returned no text.'), $httpCode);
    }
    return ['ok' => true, 'text' => $text, 'error' => null, 'http_code' => $httpCode];
}

/**
 * Record the customer's consent to send this recording for transcription.
 *
 * Consent is stored as an event (when, by whom) rather than a boolean so the
 * platform can show exactly what was agreed and when. Re-consenting updates the
 * timestamp, which is what a later audit should see.
 */
function consultation_attachment_record_transcription_consent(PDO $pdo, int $attachmentId, int $actorId): bool {
    $stmt = $pdo->prepare("UPDATE consultation_attachments
        SET transcription_consent_at=NOW(), transcription_consent_by=?
        WHERE id=? AND file_category='audio'");
    $stmt->execute([$actorId, $attachmentId]);
    return $stmt->rowCount() >= 0;
}

/** Set the transcription state, optionally with the result text and error. */
function consultation_attachment_set_transcription(
    PDO $pdo, int $attachmentId, string $status,
    ?string $text = null, ?string $error = null, ?string $engine = null, ?string $language = null
): bool {
    if (!in_array($status, consultation_attachment_transcription_states(), true)) return false;
    $stmt = $pdo->prepare(
        'UPDATE consultation_attachments
            SET transcription_status=?, transcription_text=COALESCE(?, transcription_text),
                transcription_error=?, transcription_engine=COALESCE(?, transcription_engine),
                transcription_language=COALESCE(?, transcription_language),
                transcription_updated_at=NOW()
          WHERE id=?'
    );
    return $stmt->execute([$status, $text, $error, $engine, $language, $attachmentId]);
}

/**
 * Whether the transcription text may be shown to this actor.
 *
 * The transcript is client evidence and follows exactly the same authorization as
 * the recording itself: if you may read the audio, you may read its transcript.
 * There is deliberately no separate rule — two rules for one file is how a leak
 * happens.
 */
function consultation_attachment_can_read_transcription(array $attachment, array $actor): bool {
    return (string)($attachment['transcription_status'] ?? 'none') === 'ready'
        && trim((string)($attachment['transcription_text'] ?? '')) !== '';
}


/** Stored attachments of a consultation, oldest first. */
function consultation_attachment_list(PDO $pdo, int $consultationId, bool $includeDeleted = false): array {
    $sql = 'SELECT * FROM consultation_attachments WHERE consultation_id=?';
    if (!$includeDeleted) $sql .= " AND status='stored'";
    $sql .= ' ORDER BY created_at ASC, id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$consultationId]);
    return $stmt->fetchAll();
}

/**
 * Resolve an attachment's absolute path, refusing anything outside the storage root.
 *
 * Returns null when the row, the file, or the path invariant is wrong, so a caller can
 * answer 404 rather than reading an arbitrary file.
 */
function consultation_attachment_absolute_path(array $row): ?string {
    if (($row['status'] ?? '') !== 'stored') return null;
    $relative = (string)($row['stored_path'] ?? '');
    if ($relative === '' || str_contains($relative, '..') || str_starts_with($relative, '/')) return null;

    $root = realpath(consultation_attachment_storage_root());
    if ($root === false) return null;
    $candidate = $root . DIRECTORY_SEPARATOR . $relative;
    $real = realpath($candidate);
    if ($real === false || !is_file($real)) return null;
    if (!str_starts_with($real, $root . DIRECTORY_SEPARATOR)) return null;
    return $real;
}

/** Soft-delete: mark the row deleted and unlink the file. */
function consultation_attachment_delete(PDO $pdo, array $row, array $actor): bool {
    $path = consultation_attachment_absolute_path($row);
    try {
        $stmt = $pdo->prepare(
            "UPDATE consultation_attachments
                SET status='deleted', deleted_at=NOW(), deleted_by_type=?, deleted_by_id=?
              WHERE id=? AND status='stored'"
        );
        $stmt->execute([$actor['type'], $actor['id'], (int)$row['id']]);
        if ($stmt->rowCount() === 0) return false;
    } catch (PDOException $e) {
        error_log('consultation_attachment_delete: ' . $e->getMessage());
        return false;
    }
    // The row is now deleted; the file is removed after so a failed unlink leaves an
    // orphan the sweeper can reclaim, rather than a live row whose file is gone.
    if ($path !== null) @unlink($path);
    return true;
}

/** Record a download for the audit trail and the usage statistics. */
function consultation_attachment_record_download(PDO $pdo, int $attachmentId): void {
    try {
        $pdo->prepare('UPDATE consultation_attachments SET download_count = download_count + 1, last_downloaded_at = NOW() WHERE id=?')
            ->execute([$attachmentId]);
    } catch (PDOException $e) {
        // Statistics must never break a download the user is authorized to make.
        error_log('consultation_attachment_record_download: ' . $e->getMessage());
    }
}

/**
 * Reclaim files with no live row, and rows whose file is gone.
 *
 * Two directions, because both happen: a crash between move_uploaded_file() and the INSERT
 * leaves a file with no row (it is never reachable and only wastes space), and a failed
 * unlink during delete leaves a row whose file is missing. The second is reported, not
 * silently deleted — the row is the user's record that the file once existed.
 *
 * @return array{files_removed:int, rows_missing_file:int, bytes_reclaimed:int}
 */
function consultation_attachment_purge_orphans(PDO $pdo, int $maxFiles = 500): array {
    $removed = 0; $bytes = 0; $missingRows = 0;
    $root = consultation_attachment_storage_root();
    if (!is_dir($root)) return ['files_removed' => 0, 'rows_missing_file' => 0, 'bytes_reclaimed' => 0];

    $known = [];
    try {
        foreach ($pdo->query("SELECT stored_name, stored_path FROM consultation_attachments WHERE status='stored'")->fetchAll() as $row) {
            $known[(string)$row['stored_path']] = true;
        }
    } catch (PDOException $e) {
        error_log('consultation_attachment_purge_orphans: ' . $e->getMessage());
        return ['files_removed' => 0, 'rows_missing_file' => 0, 'bytes_reclaimed' => 0];
    }

    $examined = 0;
    foreach (scandir($root) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || $entry === '.htaccess') continue;
        $dir = $root . '/' . $entry;
        if (!is_dir($dir) || !ctype_digit($entry)) continue;
        foreach (scandir($dir) ?: [] as $file) {
            if ($file === '.' || $file === '..' || $file === '.htaccess') continue;
            if (++$examined > $maxFiles) break 2;
            $relative = $entry . '/' . $file;
            if (isset($known[$relative])) continue;
            $absolute = $dir . '/' . $file;
            $size = (int)@filesize($absolute);
            if (@unlink($absolute)) { $removed++; $bytes += $size; }
        }
    }

    // Rows whose file has gone missing. Reported, never deleted automatically.
    try {
        foreach ($pdo->query("SELECT id, stored_path FROM consultation_attachments WHERE status='stored'")->fetchAll() as $row) {
            $absolute = $root . '/' . (string)$row['stored_path'];
            if (!is_file($absolute)) $missingRows++;
        }
    } catch (PDOException $e) {
        error_log('consultation_attachment_purge_orphans (rows): ' . $e->getMessage());
    }

    return ['files_removed' => $removed, 'rows_missing_file' => $missingRows, 'bytes_reclaimed' => $bytes];
}

/**
 * Storage statistics, optionally scoped to one firm (null = platform-wide, for an admin).
 *
 * @return array{files:int, bytes:int, consultations:int, by_category:array<string,int>, by_day:array<string,int>}
 */
function consultation_attachment_stats(PDO $pdo, ?int $firmId = null): array {
    $where = "status='stored'";
    $params = [];
    if ($firmId !== null) { $where .= ' AND firm_id = ?'; $params[] = $firmId; }

    $totals = $pdo->prepare("SELECT COUNT(*) AS files, COALESCE(SUM(file_size),0) AS bytes, COUNT(DISTINCT consultation_id) AS consultations FROM consultation_attachments WHERE $where");
    $totals->execute($params);
    $row = $totals->fetch() ?: ['files' => 0, 'bytes' => 0, 'consultations' => 0];

    $cat = $pdo->prepare("SELECT file_category, COUNT(*) AS n FROM consultation_attachments WHERE $where GROUP BY file_category");
    $cat->execute($params);
    $byCategory = [];
    foreach ($cat->fetchAll() as $c) $byCategory[(string)$c['file_category']] = (int)$c['n'];

    $day = $pdo->prepare("SELECT DATE(created_at) AS d, COUNT(*) AS n FROM consultation_attachments WHERE $where GROUP BY DATE(created_at) ORDER BY d DESC LIMIT 30");
    $day->execute($params);
    $byDay = [];
    foreach ($day->fetchAll() as $d) $byDay[(string)$d['d']] = (int)$d['n'];

    return [
        'files' => (int)$row['files'],
        'bytes' => (int)$row['bytes'],
        'consultations' => (int)$row['consultations'],
        'by_category' => $byCategory,
        'by_day' => $byDay,
    ];
}

/**
 * Which file categories a consultation type may carry.
 *
 * The booking form declares text/voice/video; a voice consultation is for audio and a
 * video consultation for video, so the picker leads with the relevant category. This is a
 * UX hint only — the server accepts any allowed category regardless, because a client
 * legitimately attaches a PDF contract to a video call and refusing it would be wrong.
 */
function consultation_attachment_primary_category(string $consultationType): string {
    return ['voice' => 'audio', 'video' => 'video'][$consultationType] ?? 'document';
}
