-- ============================================================
-- Mawatheq upgrade v52 — Executive 09
--   Enterprise SSO (SAML), automatic SSL provisioning for verified custom domains,
--   AI court-order extraction with mandatory human verification, legacy `.doc`
--   conversion, and legacy AI chat-history migration.
-- Additive only, non-destructive, safe to re-run.
-- Compatible with v51 and earlier. No existing column is altered or dropped.
-- ============================================================
--
-- Design rules honoured here:
--   * Every new table is firm-scoped (`firm_id`).
--   * No table stores a raw secret: IdP certificates are public material, but any
--     credential is encrypted at the application layer (see includes/secret_crypto.php)
--     before it is written. This schema only reserves the column.
--   * AI output is never authoritative: `court_order_extractions` keeps the machine
--     reading and the lawyer decision in separate columns, and nothing is exposed to a
--     client until `status='approved'`.
--   * The legacy chat archive keeps the ORIGINAL owner (`user_id`) so a migration cannot
--     widen who can read an old conversation.
--   * SSL provisioning records intent and outcome only. It never claims a certificate was
--     issued when the infrastructure did not issue one (`status='manual_required'`).

-- ============================================================
-- 1. ENTERPRISE SSO (SAML 2.0)
-- ============================================================

-- One IdP configuration per firm. `sp_*` columns are the Service Provider identity this
-- platform presents to the IdP; `idp_x509_cert` is the IdP signing certificate (public).
CREATE TABLE IF NOT EXISTS sso_configs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    firm_id INT UNSIGNED NOT NULL,
    provider_type ENUM('saml') NOT NULL DEFAULT 'saml',
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    display_name VARCHAR(190) DEFAULT NULL,
    idp_entity_id VARCHAR(500) DEFAULT NULL,
    idp_sso_url VARCHAR(500) DEFAULT NULL,
    idp_slo_url VARCHAR(500) DEFAULT NULL,
    idp_x509_cert MEDIUMTEXT DEFAULT NULL,
    sp_entity_id VARCHAR(500) DEFAULT NULL,
    name_id_format VARCHAR(190) DEFAULT NULL,
    attribute_email VARCHAR(120) NOT NULL DEFAULT 'email',
    attribute_name VARCHAR(120) NOT NULL DEFAULT 'displayName',
    attribute_role VARCHAR(120) DEFAULT NULL,
    jit_provisioning TINYINT(1) NOT NULL DEFAULT 0,
    allowed_domains VARCHAR(500) DEFAULT NULL,
    require_signed_response TINYINT(1) NOT NULL DEFAULT 1,
    created_by_lawyer_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sso_firm (firm_id),
    INDEX idx_sso_enabled (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only authentication trail. `ip_hash` is a salted hash, never the raw address, and
-- no assertion body or session token is ever written here.
CREATE TABLE IF NOT EXISTS sso_login_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    firm_id INT UNSIGNED DEFAULT NULL,
    email VARCHAR(190) DEFAULT NULL,
    outcome ENUM('success','failed','denied','error') NOT NULL,
    reason VARCHAR(190) DEFAULT NULL,
    name_id_hash CHAR(64) DEFAULT NULL,
    ip_hash CHAR(64) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sso_attempt_firm (firm_id, created_at),
    INDEX idx_sso_attempt_outcome (outcome, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 2. AUTOMATIC SSL PROVISIONING (for VERIFIED custom domains only)
-- ============================================================

-- One provisioning record per domain attempt. `provider='acme'` is used only when an ACME
-- account is configured for the deployment; otherwise the job is recorded as
-- `manual_required` and the UI states plainly that HTTPS is an operator step.
CREATE TABLE IF NOT EXISTS domain_ssl_jobs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    firm_id INT UNSIGNED NOT NULL,
    domain_id INT UNSIGNED NOT NULL,
    provider ENUM('acme','manual') NOT NULL DEFAULT 'manual',
    status ENUM('queued','pending_dns','issued','failed','manual_required') NOT NULL DEFAULT 'queued',
    detail VARCHAR(500) DEFAULT NULL,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_attempt_at DATETIME DEFAULT NULL,
    issued_at DATETIME DEFAULT NULL,
    expires_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ssl_domain (domain_id),
    INDEX idx_ssl_firm_status (firm_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 3. AI COURT-ORDER EXTRACTION (mandatory human verification)
-- ============================================================

-- `verified_json` holds fields read deterministically from the document metadata/structure.
-- `suggested_json` holds the model reading and is ALWAYS labelled as a suggestion. A row is
-- only visible to the case's client after a lawyer sets status='approved'.
CREATE TABLE IF NOT EXISTS court_order_extractions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    firm_id INT UNSIGNED NOT NULL,
    case_id INT UNSIGNED NOT NULL,
    document_id INT UNSIGNED DEFAULT NULL,
    status ENUM('pending','processing','extracted','failed','approved','rejected') NOT NULL DEFAULT 'pending',
    verified_json MEDIUMTEXT DEFAULT NULL,
    suggested_json MEDIUMTEXT DEFAULT NULL,
    citations_json MEDIUMTEXT DEFAULT NULL,
    unsupported_json MEDIUMTEXT DEFAULT NULL,
    confidence DECIMAL(4,3) DEFAULT NULL,
    requires_review TINYINT(1) NOT NULL DEFAULT 1,
    reviewed_by_lawyer_id INT UNSIGNED DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    review_note VARCHAR(500) DEFAULT NULL,
    model VARCHAR(120) DEFAULT NULL,
    error VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_co_case (case_id, created_at),
    INDEX idx_co_firm_status (firm_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 4. LEGACY `.doc` CONVERSION
-- ============================================================

-- Extracted plain text for a stored document. Kept separate from `documents` so a failed or
-- unavailable conversion never blocks the document itself from being stored.
CREATE TABLE IF NOT EXISTS document_text (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    document_id INT UNSIGNED NOT NULL,
    firm_id INT UNSIGNED DEFAULT NULL,
    source_extension VARCHAR(16) DEFAULT NULL,
    converter VARCHAR(40) DEFAULT NULL,
    content MEDIUMTEXT DEFAULT NULL,
    char_count INT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('ok','unsupported','failed','too_large') NOT NULL DEFAULT 'ok',
    detail VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_document_text_doc (document_id),
    INDEX idx_document_text_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 5. LEGACY AI CHAT MIGRATION
-- ============================================================

-- One archive per migrated legacy conversation. `user_id` is carried over unchanged so the
-- archive keeps exactly the original owner; `firm_id` is derived from that user's firm at
-- migration time. A NULL firm_id means the owner has no firm and the archive stays
-- user-private.
CREATE TABLE IF NOT EXISTS ai_chat_archives (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    firm_id INT UNSIGNED DEFAULT NULL,
    user_id INT UNSIGNED NOT NULL,
    legacy_conversation_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) DEFAULT NULL,
    message_count INT UNSIGNED NOT NULL DEFAULT 0,
    migrated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_archive_legacy (legacy_conversation_id),
    INDEX idx_archive_user (user_id),
    INDEX idx_archive_firm (firm_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_chat_archive_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    archive_id INT UNSIGNED NOT NULL,
    sender ENUM('user','assistant') NOT NULL,
    message TEXT NOT NULL,
    reference_law VARCHAR(255) DEFAULT NULL,
    source_citations_json LONGTEXT DEFAULT NULL,
    original_created_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_archive_msg (archive_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migration ledger so a re-run is a no-op and every action is auditable.
CREATE TABLE IF NOT EXISTS legacy_chat_migration_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    run_by VARCHAR(60) NOT NULL DEFAULT 'cli',
    conversations_seen INT UNSIGNED NOT NULL DEFAULT 0,
    conversations_migrated INT UNSIGNED NOT NULL DEFAULT 0,
    messages_migrated INT UNSIGNED NOT NULL DEFAULT 0,
    conversations_skipped INT UNSIGNED NOT NULL DEFAULT 0,
    detail VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
