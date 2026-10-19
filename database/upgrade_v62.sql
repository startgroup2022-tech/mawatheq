-- ============================================================================
-- Mawatheq Platform — multimodal consultation submission (Execution 35)
-- upgrade_v62.sql
-- ============================================================================
--
-- WHAT THIS ADDS
--
--   1) Transcription metadata on consultation_attachments. A voice recording the
--      customer submitted may optionally be transcribed to Arabic text. The
--      transcription is stored ALONGSIDE the recording, never instead of it: the
--      audio row and its file are untouched, so the original is always preserved
--      and can always be replayed or re-transcribed.
--
--   2) An idempotency key on consultations. The mobile client retries a failed
--      submission; without a key the retry books a second consultation. With one,
--      the server recognises the retry and returns the consultation it already
--      created instead of creating another.
--
-- WHAT THIS DELIBERATELY DOES NOT DO
--
--   * No data is rewritten, dropped or re-typed. Every statement is additive.
--   * No positional AFTER clause names a column. A positional AFTER naming a
--     column that does not exist aborts the statement, and MySQL stops at the
--     first error — everything below silently never runs while the migration
--     still looks applied. (Execution 28 failure mode.) Column order is
--     cosmetic; it is not worth that risk.
--   * The transcription columns are NOT back-filled. An existing audio
--     attachment keeps transcription_status='none' and NULL text, which is the
--     truth: no transcription was ever requested for it.
--
-- SAFETY
--   Additive only, guarded by information_schema tests, so the file can be run
--   any number of times and is a no-op once applied. The ledger entry at the end
--   is written only when the conditions actually held.
-- ============================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- 1) consultation_attachments — transcription metadata.
--
--    transcription_status is the state machine the API drives:
--      none       no transcription requested (the default for every existing row)
--      pending    customer asked for it and consented; not started
--      processing a worker/provider call is in flight
--      ready      transcription_text holds the result
--      failed     the attempt failed; transcription_error says why
--      declined   the tenant's AI policy refused the external call
-- ---------------------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='consultation_attachments'
             AND COLUMN_NAME='transcription_status');
SET @s := IF(@c=0, "ALTER TABLE consultation_attachments
    ADD COLUMN transcription_status ENUM('none','pending','processing','ready','failed','declined')
        NOT NULL DEFAULT 'none'", 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='consultation_attachments'
             AND COLUMN_NAME='transcription_text');
SET @s := IF(@c=0, "ALTER TABLE consultation_attachments
    ADD COLUMN transcription_text MEDIUMTEXT DEFAULT NULL", 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='consultation_attachments'
             AND COLUMN_NAME='transcription_language');
SET @s := IF(@c=0, "ALTER TABLE consultation_attachments
    ADD COLUMN transcription_language VARCHAR(10) DEFAULT NULL", 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='consultation_attachments'
             AND COLUMN_NAME='transcription_engine');
SET @s := IF(@c=0, "ALTER TABLE consultation_attachments
    ADD COLUMN transcription_engine VARCHAR(64) DEFAULT NULL", 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='consultation_attachments'
             AND COLUMN_NAME='transcription_error');
SET @s := IF(@c=0, "ALTER TABLE consultation_attachments
    ADD COLUMN transcription_error VARCHAR(255) DEFAULT NULL", 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Consent is recorded as an event (who, when), not as a boolean. The brief
-- requires that a recording is never sent to an external transcription provider
-- without disclosure and consent; storing only "true" would lose the evidence of
-- when consent was given and by whom.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='consultation_attachments'
             AND COLUMN_NAME='transcription_consent_at');
SET @s := IF(@c=0, "ALTER TABLE consultation_attachments
    ADD COLUMN transcription_consent_at DATETIME DEFAULT NULL", 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='consultation_attachments'
             AND COLUMN_NAME='transcription_consent_by');
SET @s := IF(@c=0, "ALTER TABLE consultation_attachments
    ADD COLUMN transcription_consent_by INT UNSIGNED DEFAULT NULL", 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='consultation_attachments'
             AND COLUMN_NAME='transcription_updated_at');
SET @s := IF(@c=0, "ALTER TABLE consultation_attachments
    ADD COLUMN transcription_updated_at DATETIME DEFAULT NULL", 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 2) consultation_attachments.file_category — add the 'image' value.
--
--    The original ENUM had document/audio/video only, so a photo from the device
--    camera had no category to be stored as. 'image' is appended to the END of the
--    list: existing values keep their exact index, so every stored row keeps the
--    meaning it had. Re-ordering the list is what would silently reinterpret data.
-- ---------------------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='consultation_attachments'
             AND COLUMN_NAME='file_category'
             AND COLUMN_TYPE NOT LIKE '%image%');
SET @s := IF(@c=1, "ALTER TABLE consultation_attachments
    MODIFY COLUMN file_category ENUM('document','audio','video','image')
        NOT NULL DEFAULT 'document'", 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 3) consultations — idempotency key for the mobile submit/retry flow.
--
--    NULL for every existing row and for every booking made from the web form,
--    which does not send one. The unique index is on (user_id, idempotency_key):
--    a key is scoped to the customer who chose it, so one customer's key can
--    never collide with another's and two different customers retrying at the
--    same instant both succeed.
-- ---------------------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='consultations'
             AND COLUMN_NAME='idempotency_key');
SET @s := IF(@c=0, "ALTER TABLE consultations
    ADD COLUMN idempotency_key CHAR(64) DEFAULT NULL", 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='consultations'
             AND INDEX_NAME='uq_consultations_idempotency');
SET @s := IF(@c=0, "ALTER TABLE consultations
    ADD UNIQUE KEY uq_consultations_idempotency (user_id, idempotency_key)", 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 3) An index for the transcription queue: a worker looks for rows that are
--    pending or processing, and that scan should not be a full table scan.
-- ---------------------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='consultation_attachments'
             AND INDEX_NAME='idx_consultation_attachments_transcription');
SET @s := IF(@c=0, "ALTER TABLE consultation_attachments
    ADD INDEX idx_consultation_attachments_transcription (transcription_status)", 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- Ledger: written only when every condition actually held.
-- ---------------------------------------------------------------------------
SET @ok := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='consultation_attachments'
              AND COLUMN_NAME IN ('transcription_status','transcription_text',
                                  'transcription_language','transcription_engine',
                                  'transcription_error','transcription_consent_at',
                                  'transcription_consent_by','transcription_updated_at'))
         + (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='consultation_attachments'
              AND COLUMN_NAME='file_category' AND COLUMN_TYPE LIKE '%image%')
         + (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='consultations'
              AND COLUMN_NAME='idempotency_key')
         -- COUNT(DISTINCT INDEX_NAME), not COUNT(*): information_schema.STATISTICS has one
         -- row per index COLUMN, so the two-column unique key counts as 2 and the guard
         -- would never reach its total. Counting the index once keeps the arithmetic true
         -- no matter how many columns a future key spans.
         + (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='consultations'
              AND INDEX_NAME='uq_consultations_idempotency')
         + (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='consultation_attachments'
              AND INDEX_NAME='idx_consultation_attachments_transcription');

SET @s := IF(@ok = 12,
    "INSERT INTO schema_migrations (filename, checksum, state, requirements_total, requirements_present, statements_ok)
     VALUES ('upgrade_v62.sql', REPEAT('0',64), 'verified', 12, @ok, 12)
     ON DUPLICATE KEY UPDATE state='verified', requirements_total=12, requirements_present=@ok, statements_ok=12",
    'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- ROLLBACK (manual, if ever needed). All of it is additive, so dropping it loses
-- only the transcription results and the idempotency keys — never an attachment,
-- a file, or a consultation:
--   ALTER TABLE consultation_attachments DROP INDEX idx_consultation_attachments_transcription;
--   ALTER TABLE consultations DROP INDEX uq_consultations_idempotency;
--   ALTER TABLE consultations DROP COLUMN idempotency_key;
--   ALTER TABLE consultation_attachments
--     MODIFY COLUMN file_category ENUM('document','audio','video') NOT NULL DEFAULT 'document';
--   ALTER TABLE consultation_attachments
--     DROP COLUMN transcription_status, DROP COLUMN transcription_text,
--     DROP COLUMN transcription_language, DROP COLUMN transcription_engine,
--     DROP COLUMN transcription_error, DROP COLUMN transcription_consent_at,
--     DROP COLUMN transcription_consent_by, DROP COLUMN transcription_updated_at;
--   DELETE FROM schema_migrations WHERE filename='upgrade_v62.sql';
-- ------------------------------------------------------------
