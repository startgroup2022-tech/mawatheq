<?php
/**
 * Renders the attachment list for one consultation.
 *
 * Shared by the client room, the lawyer room and the booking form, so the same row shows
 * the same actions everywhere and the permission logic is not re-implemented per page — and
 * so the booking form and the consultation pages cannot drift apart. The admin console
 * renders its own table instead: it shows cross-tenant metadata and storage statistics
 * rather than one consultation's file list, so it shares the library, not this markup.
 *
 * Expects, set by the caller:
 *   $pdo
 *   $actor          ['type' => client|lawyer|admin, 'id' => int]
 *   $consultation   row from `consultations`; NULL for the booking form, where the files
 *                   are staged against a draft token before the consultation exists
 *   $attachments    rows from consultation_attachment_list()  (optional; queried if absent)
 *   $draftToken     the session's draft token, required when $consultation is NULL
 *
 * The draft mode is the same markup with a different endpoint mode and no rows: an upload
 * there is staged and claimed by booking.php, not bound to a consultation id.
 */
require_once __DIR__ . '/consultation_attachments.php';

$isDraft = !isset($consultation) || !is_array($consultation);
$attachments = $attachments ?? ($isDraft ? [] : consultation_attachment_list($pdo, (int)$consultation['id']));
$canUpload = $isDraft ? true : consultation_attachment_can_upload($consultation, $actor);
$limits = consultation_attachment_limits($pdo);
$isAdmin = !$isDraft && $actor['type'] === 'admin';
$panelId = $isDraft ? 0 : (int)$consultation['id'];
$panelMode = $isDraft ? 'draft' : 'attach';

/** A short icon per category. Inline SVG so it inherits the theme colour. */
$iconFor = static function (string $category): string {
    $paths = [
        'document' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>',
        'audio' => '<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>',
        'video' => '<polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/>',
    ];
    $path = $paths[$category] ?? $paths['document'];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
};

$categoryLabel = static function (string $category): string {
    return match ($category) {
        'audio' => consultation_attachment_msg('صوت', 'Audio'),
        'video' => consultation_attachment_msg('فيديو', 'Video'),
        default => consultation_attachment_msg('مستند', 'Document'),
    };
};
?>
<section class="ca-panel<?= $isDraft ? ' ca-booking-wrap' : '' ?>" data-attachments data-consultation-id="<?= $panelId ?>">
  <header class="ca-panel-head">
    <h3>
      <?= consultation_attachment_msg('المرفقات والمستندات', 'Attachments and documents') ?>
      <span class="ca-count" data-attachment-count><?= count($attachments) ?></span>
    </h3>
    <?php if ($isDraft): ?>
      <span class="ca-quota"><?= consultation_attachment_msg('اختياري — يمكنك إرسال الطلب بدون مرفقات', 'Optional — you can submit without attachments') ?></span>
    <?php elseif (!$isAdmin): ?>
      <span class="ca-quota"><?= htmlspecialchars(sprintf(
          consultation_attachment_msg('الحد: %1$d ملفات · %2$d ميجابايت للمستند', 'Limit: %1$d files · %2$d MB per document'),
          $limits['max_files'], $limits['document_mb']
      )) ?></span>
    <?php endif; ?>
  </header>

  <ul class="ca-list" data-attachment-list>
    <?php foreach ($attachments as $a): ?>
      <?php
        $previewable = consultation_attachment_preview_mime($a['mime_type'] ?? null) !== null;
        $canDelete = consultation_attachment_can_delete($a, $consultation, $actor);
        $uploader = $a['uploaded_by_type'] === 'lawyer'
            ? consultation_attachment_msg('المحامي', 'Lawyer')
            : ($a['uploaded_by_type'] === 'admin'
                ? consultation_attachment_msg('الإدارة', 'Admin')
                : consultation_attachment_msg('العميل', 'Client'));
      ?>
      <li class="ca-item" data-attachment-id="<?= (int)$a['id'] ?>" data-mime="<?= htmlspecialchars((string)$a['mime_type']) ?>" data-name="<?= htmlspecialchars((string)$a['original_name']) ?>">
        <span class="ca-item-icon ca-cat-<?= htmlspecialchars((string)$a['file_category']) ?>"><?= $iconFor((string)$a['file_category']) ?></span>
        <span class="ca-item-body">
          <span class="ca-item-name" title="<?= htmlspecialchars((string)$a['original_name']) ?>"><?= htmlspecialchars((string)$a['original_name']) ?></span>
          <span class="ca-item-meta">
            <span class="ca-badge ca-badge-<?= htmlspecialchars((string)$a['file_category']) ?>"><?= htmlspecialchars($categoryLabel((string)$a['file_category'])) ?></span>
            <?= htmlspecialchars(consultation_attachment_format_size((int)$a['file_size'])) ?>
            · <?= htmlspecialchars(strtoupper((string)$a['file_type'])) ?>
            <?php if ($isAdmin): ?> · <?= htmlspecialchars($uploader) ?><?php endif; ?>
            <?php if ((int)$a['download_count'] > 0): ?> · <?= htmlspecialchars(sprintf(consultation_attachment_msg('تنزيلات: %d', 'Downloads: %d'), (int)$a['download_count'])) ?><?php endif; ?>
          </span>
        </span>
        <span class="ca-item-actions">
          <?php if ($previewable): ?>
            <button type="button" class="ca-btn" data-ca-preview="<?= (int)$a['id'] ?>" title="<?= consultation_attachment_msg('معاينة', 'Preview') ?>">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              <span class="ca-sr"><?= consultation_attachment_msg('معاينة', 'Preview') ?></span>
            </button>
          <?php endif; ?>
          <a class="ca-btn" href="/download-consultation-attachment.php?id=<?= (int)$a['id'] ?>" title="<?= consultation_attachment_msg('تنزيل', 'Download') ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            <span class="ca-sr"><?= consultation_attachment_msg('تنزيل', 'Download') ?></span>
          </a>
          <?php if ($canDelete): ?>
            <button type="button" class="ca-btn ca-btn-danger" data-ca-remove="<?= (int)$a['id'] ?>" title="<?= consultation_attachment_msg('حذف', 'Remove') ?>">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
              <span class="ca-sr"><?= consultation_attachment_msg('حذف', 'Remove') ?></span>
            </button>
          <?php endif; ?>
        </span>
      </li>
    <?php endforeach; ?>
    <li class="ca-empty" data-ca-empty <?= $attachments ? 'hidden' : '' ?>>
      <?= consultation_attachment_msg('لا توجد مرفقات بعد.', 'No attachments yet.') ?>
    </li>
  </ul>

  <?php if ($canUpload): ?>
    <div class="ca-uploader" data-ca-uploader data-endpoint="/api/consultation_attachments.php" data-mode="<?= $panelMode ?>" data-csrf="<?= htmlspecialchars(csrf_token()) ?>"
         data-limit-document="<?= (int)$limits['document_mb'] ?>" data-limit-audio="<?= (int)$limits['audio_mb'] ?>" data-limit-video="<?= (int)$limits['video_mb'] ?>">
      <label class="ca-drop" data-ca-drop tabindex="0" role="button"
             aria-label="<?= consultation_attachment_msg('اسحب الملفات هنا أو اضغط لاختيار الملفات', 'Drag files here or press to choose files') ?>">
        <input type="file" data-ca-input multiple hidden
               accept=".txt,.doc,.docx,.pdf,.mp3,.wav,.m4a,.ogg,.mp4,.mov,.webm">
        <span class="ca-drop-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
        </span>
        <span class="ca-drop-title"><?= consultation_attachment_msg('اسحب الملفات هنا أو اضغط لاختيار الملفات', 'Drag files here or press to choose files') ?></span>
        <span class="ca-drop-hint">
          <?= consultation_attachment_msg(
              'مستندات (TXT, DOC, DOCX, PDF) حتى ' . $limits['document_mb'] . ' ميجابايت · '
              . 'صوت (MP3, WAV, M4A, OGG) حتى ' . $limits['audio_mb'] . ' ميجابايت · '
              . 'فيديو (MP4, MOV, WEBM) حتى ' . $limits['video_mb'] . ' ميجابايت · '
              . 'بحد أقصى ' . $limits['max_files'] . ' ملفات',
              'Documents (TXT, DOC, DOCX, PDF) up to ' . $limits['document_mb'] . ' MB · '
              . 'Audio (MP3, WAV, M4A, OGG) up to ' . $limits['audio_mb'] . ' MB · '
              . 'Video (MP4, MOV, WEBM) up to ' . $limits['video_mb'] . ' MB · '
              . 'up to ' . $limits['max_files'] . ' files'
          ) ?>
        </span>
      </label>
      <div class="ca-errors" data-ca-errors role="alert" aria-live="polite"></div>
    </div>
  <?php elseif (!$isDraft && $actor['type'] !== 'admin'): ?>
    <p class="ca-closed">
      <?= consultation_attachment_msg(
          'لا يمكن إضافة مرفقات لأن هذه الاستشارة ' . ($consultation['status'] === 'completed' ? 'مكتملة' : 'ملغاة') . '.',
          'Attachments cannot be added because this consultation is ' . ($consultation['status'] === 'completed' ? 'completed' : 'cancelled') . '.'
      ) ?>
    </p>
  <?php endif; ?>

  <div class="ca-lightbox" data-ca-lightbox hidden>
    <div class="ca-lightbox-inner" role="dialog" aria-modal="true" aria-label="<?= consultation_attachment_msg('معاينة المرفق', 'Attachment preview') ?>">
      <button type="button" class="ca-lightbox-close" data-ca-close aria-label="<?= consultation_attachment_msg('إغلاق', 'Close') ?>">&times;</button>
      <div class="ca-lightbox-body" data-ca-lightbox-body></div>
      <div class="ca-lightbox-foot" data-ca-lightbox-foot></div>
    </div>
  </div>
</section>
