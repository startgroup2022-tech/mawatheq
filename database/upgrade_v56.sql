-- Execution 30 — consultation attachments.
--
-- Adds the storage table for files a client (or the assigned lawyer) attaches to a
-- consultation, plus the site_settings rows that make the upload limits configurable
-- from the admin console.
--
-- Design notes, because several of these are load-bearing:
--
--  * Two-phase upload. The booking form collects files BEFORE the consultation row
--    exists, so a file is first written as `status='staged'` with `consultation_id` NULL
--    and a `draft_token`, then CLAIMED when the booking is submitted (the token is
--    matched and the row is bound to the new consultation). This is what makes a real
--    per-file progress bar possible without a separate throwaway table, and it is why
--    consultation_id is nullable. A staged row whose token is never claimed is an
--    abandoned draft: consultation_attachment_purge_drafts() removes it and its file.
--
--  * `stored_path` is a path RELATIVE to the private storage root, never a public URL.
--    The file lives outside the web root (see includes/consultation_attachments.php);
--    the only way to reach it is download-consultation-attachment.php, which re-checks
--    ownership on every request. There is deliberately no direct public URL to guess.
--
--  * `firm_id` mirrors the tenant of the consultation. It is denormalised on purpose so
--    the lawyer dashboard can scope attachments without joining consultations, and so a
--    firm's storage report counts its own files. It is written from the consultation row,
--    never from a request parameter.
--
--  * `original_name` keeps the name the uploader saw; `stored_name` is the random
--    server-side name on disk. They are separate columns because the display name is
--    attacker-controlled text and the on-disk name must never be derived from it.
--
--  * ON DELETE CASCADE on consultation_id: deleting a consultation removes its
--    attachment ROWS. The FILES are removed by includes/consultation_attachments.php
--    before the row is deleted (and by the orphan sweeper for rows removed by cascade),
--    because MySQL cannot unlink a file.
--
-- Idempotent and safe on a partially-migrated database: every statement is guarded by an
-- information_schema check, and no positional AFTER clause is used anywhere. MySQL stops a
-- file at the first error, so a positional AFTER naming an absent sibling column would
-- abandon the rest of this migration (see database/repair/01_repair_reset_columns.sql).
SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- 1) The attachments table.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS consultation_attachments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    consultation_id INT UNSIGNED DEFAULT NULL,
    draft_token CHAR(64) DEFAULT NULL,
    firm_id INT UNSIGNED DEFAULT NULL,
    uploaded_by_type ENUM('client','lawyer','admin') NOT NULL DEFAULT 'client',
    uploaded_by_id INT UNSIGNED NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(191) NOT NULL,
    stored_path VARCHAR(255) NOT NULL,
    file_category ENUM('document','audio','video') NOT NULL DEFAULT 'document',
    file_type VARCHAR(20) NOT NULL,
    mime_type VARCHAR(127) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    checksum CHAR(64) DEFAULT NULL,
    status ENUM('staged','stored','deleted') NOT NULL DEFAULT 'staged',
    download_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_downloaded_at DATETIME DEFAULT NULL,
    deleted_at DATETIME DEFAULT NULL,
    deleted_by_type VARCHAR(16) DEFAULT NULL,
    deleted_by_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_consultation_attachment_stored (stored_name),
    INDEX idx_consultation_attachments_consultation (consultation_id),
    INDEX idx_consultation_attachments_draft (draft_token),
    INDEX idx_consultation_attachments_firm (firm_id),
    INDEX idx_consultation_attachments_status (status),
    CONSTRAINT fk_consultation_attachments_consultation FOREIGN KEY (consultation_id)
        REFERENCES consultations(id) ON DELETE CASCADE,
    CONSTRAINT fk_consultation_attachments_firm FOREIGN KEY (firm_id)
        REFERENCES law_firms(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ------------------------------------------------------------
-- 2) Configurable limits, seeded only when absent so an operator's values survive a
--    re-run. The defaults match the specification: documents 20 MB, audio 50 MB,
--    video 100 MB, 10 files per consultation.
-- ------------------------------------------------------------
INSERT INTO site_settings (setting_key, setting_value)
SELECT 'consultation_attachment_document_mb', '20'
WHERE NOT EXISTS (SELECT 1 FROM site_settings WHERE setting_key='consultation_attachment_document_mb');

INSERT INTO site_settings (setting_key, setting_value)
SELECT 'consultation_attachment_audio_mb', '50'
WHERE NOT EXISTS (SELECT 1 FROM site_settings WHERE setting_key='consultation_attachment_audio_mb');

INSERT INTO site_settings (setting_key, setting_value)
SELECT 'consultation_attachment_video_mb', '100'
WHERE NOT EXISTS (SELECT 1 FROM site_settings WHERE setting_key='consultation_attachment_video_mb');

INSERT INTO site_settings (setting_key, setting_value)
SELECT 'consultation_attachment_max_files', '10'
WHERE NOT EXISTS (SELECT 1 FROM site_settings WHERE setting_key='consultation_attachment_max_files');

INSERT INTO site_settings (setting_key, setting_value)
SELECT 'consultation_attachment_total_mb', '500'
WHERE NOT EXISTS (SELECT 1 FROM site_settings WHERE setting_key='consultation_attachment_total_mb');
