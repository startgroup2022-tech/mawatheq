<?php
/**
 * ============================================================
 * بانرات الصفحة الرئيسية — منطق مشترك بين الواجهة البرمجية ولوحة التحكم.
 * ============================================================
 *
 * The customer app's home slider is fed by `home_banners` (upgrade_v64.sql). The
 * admin console writes that table and `api/v1/banners.php` reads it, so the rules
 * that decide what a valid banner is — and what a customer is allowed to be sent
 * to — live here once instead of drifting between the two.
 */

/**
 * هل رابط الوجهة صالح لنوعه؟
 *
 * @return array{0:bool,1:string} [صالح؟, رسالة الخطأ]
 */
function banner_destination_validate(PDO $pdo, string $linkType, string $target): array {
    if (!in_array($linkType, ['none', 'category', 'specialty', 'url'], true)) {
        return [false, 'نوع الرابط غير معروف.'];
    }

    if ($linkType === 'none') {
        // An image with no destination is a legitimate banner, not an error.
        return [true, ''];
    }

    $target = trim($target);
    if ($target === '') {
        return [false, 'اختر وجهة للبانر أو اجعله غير قابل للنقر.'];
    }

    if ($linkType === 'url') {
        // Only https, and only an absolute address. A bare "mawatheq.com" or a
        // "javascript:" payload would otherwise be handed to the phone's browser
        // launcher as if it were a real link.
        $parts = parse_url($target);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return [false, 'الرابط يجب أن يبدأ بـ https:// ويكون عنواناً كاملاً.'];
        }
        return [true, ''];
    }

    // 'category' and 'specialty' must point at a row that actually exists. Storing a
    // dangling id would give the customer a banner that navigates to an empty screen.
    $id = (int)$target;
    if ($id <= 0) {
        return [false, 'معرّف الوجهة غير صالح.'];
    }

    if ($linkType === 'category') {
        require_once __DIR__ . '/legal_journey.php';
        $stmt = $pdo->prepare('SELECT COUNT(*) c FROM najiz_categories WHERE id = ?');
        $stmt->execute([$id]);
        if ((int)$stmt->fetch()['c'] === 0) {
            return [false, 'التخصص المحدد غير موجود.'];
        }
        return [true, ''];
    }

    // specialty == najiz_categories as well: the customer-facing "specialty" cards and
    // the journey's categories are the same taxonomy. Kept as a distinct link_type so
    // an operator can express "open the category screen" versus "start the journey at
    // this specialty", which the app routes differently.
    $stmt = $pdo->prepare('SELECT COUNT(*) c FROM najiz_categories WHERE id = ?');
    $stmt->execute([$id]);
    if ((int)$stmt->fetch()['c'] === 0) {
        return [false, 'التخصص المحدد غير موجود.'];
    }
    return [true, ''];
}

/**
 * يحوّل صف البانر إلى الشكل الذي يستهلكه التطبيق.
 *
 * `image_path` stays relative (e.g. `uploads/banners/ab12.jpg`). The app resolves it
 * against the origin it was built with (`MAWATHEQ_SITE_BASE`), so a deployment behind
 * a proxy or on a different host does not get a URL pointing at the wrong domain.
 *
 * A destination that no longer resolves (a specialty deleted after the banner was
 * created) degrades to `none`: the banner still renders, it simply stops being
 * tappable, instead of opening a dead screen.
 */
function banner_public_row(PDO $pdo, array $row): array {
    $linkType   = (string)$row['link_type'];
    $linkTarget = (string)($row['link_target'] ?? '');

    if ($linkType !== 'none') {
        [$ok] = banner_destination_validate($pdo, $linkType, $linkTarget);
        if (!$ok) {
            $linkType = 'none';
            $linkTarget = '';
        }
    }

    return [
        'id'          => (int)$row['id'],
        'title_ar'    => (string)$row['title_ar'],
        'title_en'    => (string)$row['title_en'],
        'subtitle_ar' => $row['subtitle_ar'],
        'subtitle_en' => $row['subtitle_en'],
        'image_path'  => (string)$row['image_path'],
        'alt_ar'      => $row['alt_ar'],
        'alt_en'      => $row['alt_en'],
        'link_type'   => $linkType,
        'link_target' => $linkType === 'none' ? null : $linkTarget,
    ];
}

/**
 * البانرات الظاهرة الآن، مرتّبة.
 *
 * A banner outside its scheduling window is not returned. A NULL bound means "no
 * bound", so the comparisons treat NULL as always-open — a plain `starts_at <= NOW()`
 * would hide every banner that never set a start date.
 */
function banner_active_rows(PDO $pdo): array {
    return $pdo->query(
        "SELECT id, title_ar, title_en, subtitle_ar, subtitle_en, image_path,
                alt_ar, alt_en, link_type, link_target, sort_order
         FROM home_banners
         WHERE status = 'active'
           AND (starts_at IS NULL OR starts_at <= NOW())
           AND (ends_at   IS NULL OR ends_at   >= NOW())
         ORDER BY sort_order, id"
    )->fetchAll();
}

/**
 * يتحقق من أن الملف صورة حقيقية بالامتداد المعلن.
 *
 * The extension alone is never trusted: finfo reads the leading bytes and getimagesize
 * confirms the image decodes. A `.jpg` that is really a PHP script is refused, which
 * matters because these files are served by the web server.
 *
 * @return array{0:bool,1:string} [صالح؟, البصمة الفعلية أو رسالة الخطأ]
 */
function banner_image_validate(string $path, string $extension): array {
    if (!function_exists('finfo_open')) {
        return [false, 'fileinfo غير متاح على هذا الخادم.'];
    }
    $allowed = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'webp' => ['image/webp'],
    ];
    if (!isset($allowed[$extension])) {
        return [false, 'الصيغ المدعومة: JPG أو PNG أو WEBP.'];
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = $finfo ? finfo_file($finfo, $path) : '';
    if ($finfo) finfo_close($finfo);
    if (!in_array($mime, $allowed[$extension], true)) {
        return [false, 'نوع الملف الفعلي لا يطابق امتداده.'];
    }
    if (!@getimagesize($path)) {
        return [false, 'الملف ليس صورة صالحة.'];
    }
    return [true, (string)$mime];
}

/**
 * مجلد تخزين صور البانرات، يُنشأ عند الحاجة.
 *
 * Mirrors consultation_attachment_ensure_storage(): the directory is made
 * group-writable (02770) so both the web server user and a deploy/CLI user can write
 * and remove files. Without it, images written by Apache cannot be deleted by the
 * console, and vice versa. Unlike attachments these files are served publicly, so no
 * deny guard is written.
 *
 * @return string|null المسار، أو null إذا تعذّر تهيئة التخزين.
 */
function banner_storage_dir(): ?string {
    $dir = dirname(__DIR__) . '/uploads/banners';
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        return null;
    }
    @chmod($dir, 02770);
    return is_writable($dir) ? $dir : null;
}
