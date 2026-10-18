-- ============================================================
-- Mawatheq upgrade v51 — Executive 08
--   conflict-of-interest engine, HR/attendance/leave, branch isolation,
--   custom domains, AI resilience + privacy governance.
-- Additive only, non-destructive, safe to re-run.
-- Compatible with v47–v50. No existing column is altered or dropped.
-- ============================================================
--
-- Design rules honoured here:
--   * Every new table is firm-scoped (`firm_id`). Branch-aware tables also carry a
--     nullable `branch_id`.
--   * `branch_id IS NULL` means "firm-wide / not yet assigned". Migration NEVER invents a
--     branch for an existing row — that would silently re-file a firm's records. The UI
--     surfaces unassigned rows and an owner must assign them explicitly.
--   * Conflict decisions are append-only in `conflict_audit_trail`; the application layer
--     never issues UPDATE/DELETE against it.
--   * Nothing here transmits data off-host; AI governance tables store policy, not content.

-- ============================================================
-- 1. CONFLICT-OF-INTEREST ENGINE
-- ============================================================

-- Parties a firm needs to screen: its clients, opposing parties, and related
-- individuals/companies. `name_norm` is the normalization key; `name_block` is a short
-- prefix used to keep fuzzy matching from scanning the whole table.
CREATE TABLE IF NOT EXISTS conflict_parties (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    firm_id INT UNSIGNED NOT NULL,
    party_type ENUM('client','opposing_party','related_person','related_company','witness','other') NOT NULL DEFAULT 'other',
    name_ar VARCHAR(255) DEFAULT NULL,
    name_en VARCHAR(255) DEFAULT NULL,
    name_norm VARCHAR(255) NOT NULL,
    name_block VARCHAR(8) NOT NULL DEFAULT '',
    id_number VARCHAR(80) DEFAULT NULL,
    commercial_register VARCHAR(80) DEFAULT NULL,
    country VARCHAR(100) DEFAULT NULL,
    notes VARCHAR(500) DEFAULT NULL,
    linked_client_user_id INT UNSIGNED DEFAULT NULL,
    linked_case_id INT UNSIGNED DEFAULT NULL,
    branch_id INT UNSIGNED DEFAULT NULL,
    status ENUM('active','archived') NOT NULL DEFAULT 'active',
    created_by_lawyer_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_cp_firm (firm_id),
    KEY idx_cp_norm (firm_id, name_norm),
    KEY idx_cp_block (firm_id, name_block),
    KEY idx_cp_type (firm_id, party_type),
    KEY idx_cp_case (linked_case_id),
    KEY idx_cp_branch (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One screening result. AI may populate `ai_*` (a suggestion only); only a human reviewer
-- populates the decision/override columns. `status='cleared_with_override'` is unreachable
-- without `override_approved_by_lawyer_id` + `override_reason`.
CREATE TABLE IF NOT EXISTS conflict_reviews (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    firm_id INT UNSIGNED NOT NULL,
    case_id INT UNSIGNED DEFAULT NULL,
    client_user_id INT UNSIGNED DEFAULT NULL,
    subject_party_id INT UNSIGNED DEFAULT NULL,
    matched_party_id INT UNSIGNED DEFAULT NULL,
    matched_case_id INT UNSIGNED DEFAULT NULL,
    match_type ENUM('exact','normalized','fuzzy','id_number','commercial_register','related') NOT NULL DEFAULT 'normalized',
    match_score DECIMAL(5,2) NOT NULL DEFAULT 0,
    severity ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    status ENUM('open','under_review','approved','rejected','cleared_with_override','closed') NOT NULL DEFAULT 'open',
    ai_assisted TINYINT(1) NOT NULL DEFAULT 0,
    ai_confidence DECIMAL(5,2) DEFAULT NULL,
    ai_rationale VARCHAR(1000) DEFAULT NULL,
    assigned_reviewer_lawyer_id INT UNSIGNED DEFAULT NULL,
    decision_by_lawyer_id INT UNSIGNED DEFAULT NULL,
    decision_at DATETIME DEFAULT NULL,
    decision_note VARCHAR(1000) DEFAULT NULL,
    override_approved_by_lawyer_id INT UNSIGNED DEFAULT NULL,
    override_reason VARCHAR(1000) DEFAULT NULL,
    branch_id INT UNSIGNED DEFAULT NULL,
    created_by_lawyer_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_cr_firm_status (firm_id, status),
    KEY idx_cr_case (case_id),
    KEY idx_cr_reviewer (assigned_reviewer_lawyer_id),
    KEY idx_cr_severity (firm_id, severity),
    KEY idx_cr_branch (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Alert rows created when a new conflict is detected. Delivery also goes through the
-- existing notifications table; this table is the durable record of what was raised.
CREATE TABLE IF NOT EXISTS conflict_alerts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    firm_id INT UNSIGNED NOT NULL,
    review_id INT UNSIGNED NOT NULL,
    alert_type ENUM('new_conflict','severity_escalated','reminder','decision_recorded') NOT NULL DEFAULT 'new_conflict',
    severity ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    recipient_lawyer_id INT UNSIGNED DEFAULT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    detail VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ca_firm (firm_id, is_read),
    KEY idx_ca_review (review_id),
    KEY idx_ca_recipient (recipient_lawyer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only decision trail. The application never updates or deletes a row here.
CREATE TABLE IF NOT EXISTS conflict_audit_trail (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    firm_id INT UNSIGNED NOT NULL,
    review_id INT UNSIGNED DEFAULT NULL,
    actor_lawyer_id INT UNSIGNED DEFAULT NULL,
    actor_role VARCHAR(40) DEFAULT NULL,
    action VARCHAR(60) NOT NULL,
    from_status VARCHAR(30) DEFAULT NULL,
    to_status VARCHAR(30) DEFAULT NULL,
    detail VARCHAR(1000) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_cat_firm (firm_id),
    KEY idx_cat_review (review_id),
    KEY idx_cat_actor (actor_lawyer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 2. HR, ATTENDANCE AND LEAVE
-- ============================================================

CREATE TABLE IF NOT EXISTS hr_employees (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    firm_id INT UNSIGNED NOT NULL,
    lawyer_id INT UNSIGNED DEFAULT NULL,
    user_id INT UNSIGNED DEFAULT NULL,
    employee_no VARCHAR(40) DEFAULT NULL,
    full_name VARCHAR(180) NOT NULL,
    job_title VARCHAR(150) DEFAULT NULL,
    department_id INT UNSIGNED DEFAULT NULL,
    branch_id INT UNSIGNED DEFAULT NULL,
    employment_type ENUM('full_time','part_time','contract','intern') NOT NULL DEFAULT 'full_time',
    employment_status ENUM('active','on_leave','terminated') NOT NULL DEFAULT 'active',
    hire_date DATE DEFAULT NULL,
    termination_date DATE DEFAULT NULL,
    work_email VARCHAR(150) DEFAULT NULL,
    work_phone VARCHAR(30) DEFAULT NULL,
    manager_lawyer_id INT UNSIGNED DEFAULT NULL,
    notes VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_he_firm (firm_id),
    KEY idx_he_lawyer (lawyer_id),
    KEY idx_he_dept (department_id),
    KEY idx_he_branch (branch_id),
    KEY idx_he_status (firm_id, employment_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_attendance (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    firm_id INT UNSIGNED NOT NULL,
    employee_id INT UNSIGNED NOT NULL,
    work_date DATE NOT NULL,
    check_in DATETIME DEFAULT NULL,
    check_out DATETIME DEFAULT NULL,
    status ENUM('present','late','absent','leave','holiday','remote') NOT NULL DEFAULT 'present',
    minutes_late INT UNSIGNED NOT NULL DEFAULT 0,
    worked_minutes INT UNSIGNED DEFAULT NULL,
    source ENUM('manual','self','system') NOT NULL DEFAULT 'manual',
    note VARCHAR(500) DEFAULT NULL,
    recorded_by_lawyer_id INT UNSIGNED DEFAULT NULL,
    branch_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_hr_att_emp_day (employee_id, work_date),
    KEY idx_ha_firm_date (firm_id, work_date),
    KEY idx_ha_employee (employee_id),
    KEY idx_ha_status (firm_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_leave_balances (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    firm_id INT UNSIGNED NOT NULL,
    employee_id INT UNSIGNED NOT NULL,
    leave_type ENUM('annual','sick','unpaid','emergency','maternity','paternity','other') NOT NULL DEFAULT 'annual',
    balance_year SMALLINT NOT NULL,
    entitled DECIMAL(6,1) NOT NULL DEFAULT 0,
    used DECIMAL(6,1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_hr_lb (employee_id, leave_type, balance_year),
    KEY idx_hlb_firm (firm_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_leave_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    firm_id INT UNSIGNED NOT NULL,
    employee_id INT UNSIGNED NOT NULL,
    leave_type ENUM('annual','sick','unpaid','emergency','maternity','paternity','other') NOT NULL DEFAULT 'annual',
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    days DECIMAL(6,1) NOT NULL DEFAULT 0,
    reason VARCHAR(500) DEFAULT NULL,
    status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    reviewed_by_lawyer_id INT UNSIGNED DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    review_note VARCHAR(500) DEFAULT NULL,
    branch_id INT UNSIGNED DEFAULT NULL,
    created_by_lawyer_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_hlr_firm_status (firm_id, status),
    KEY idx_hlr_employee (employee_id),
    KEY idx_hlr_dates (firm_id, start_date, end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_schedules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    firm_id INT UNSIGNED NOT NULL,
    employee_id INT UNSIGNED NOT NULL,
    branch_id INT UNSIGNED DEFAULT NULL,
    schedule_type ENUM('shift','court','duty','remote','training','other') NOT NULL DEFAULT 'shift',
    title VARCHAR(255) NOT NULL,
    start_at DATETIME NOT NULL,
    end_at DATETIME DEFAULT NULL,
    location VARCHAR(255) DEFAULT NULL,
    status ENUM('planned','completed','cancelled') NOT NULL DEFAULT 'planned',
    created_by_lawyer_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_hs_firm (firm_id, start_at),
    KEY idx_hs_employee (employee_id),
    KEY idx_hs_branch (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 3. BRANCH-LEVEL ACCESS ISOLATION
-- ============================================================

-- A member's home branch (nullable = unrestricted within the firm).
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='law_firm_members' AND COLUMN_NAME='branch_id');
SET @s := IF(@c=0,'ALTER TABLE law_firm_members ADD COLUMN branch_id INT UNSIGNED DEFAULT NULL','SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @i := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='law_firm_members' AND INDEX_NAME='idx_lfm_branch');
SET @s := IF(@i=0,'CREATE INDEX idx_lfm_branch ON law_firm_members (branch_id)','SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Explicit multi-branch grants. A row means "this lawyer may work in this branch";
-- access_level='manager' additionally satisfies branch-manager checks for it.
CREATE TABLE IF NOT EXISTS member_branch_access (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    firm_id INT UNSIGNED NOT NULL,
    lawyer_id INT UNSIGNED NOT NULL,
    branch_id INT UNSIGNED NOT NULL,
    access_level ENUM('member','manager') NOT NULL DEFAULT 'member',
    granted_by_lawyer_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mba (lawyer_id, branch_id),
    KEY idx_mba_firm (firm_id),
    KEY idx_mba_branch (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Explicit cross-branch sharing of one record (case/document/...) with another branch.
CREATE TABLE IF NOT EXISTS branch_record_shares (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    firm_id INT UNSIGNED NOT NULL,
    entity_type ENUM('case','document','evidence','consultation','invoice') NOT NULL,
    entity_id INT UNSIGNED NOT NULL,
    branch_id INT UNSIGNED NOT NULL,
    granted_by_lawyer_id INT UNSIGNED DEFAULT NULL,
    revoked_at DATETIME DEFAULT NULL,
    note VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_brs (entity_type, entity_id, branch_id),
    KEY idx_brs_firm (firm_id),
    KEY idx_brs_branch (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Branch column on the firm's branch-restricted working records. Nullable on purpose:
-- NULL = legacy/unassigned = visible firm-wide (see the design rules at the top).
-- Each ADD is guarded so re-running is a no-op.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cases' AND COLUMN_NAME='branch_id');
SET @s := IF(@c=0,'ALTER TABLE cases ADD COLUMN branch_id INT UNSIGNED DEFAULT NULL','SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='documents' AND COLUMN_NAME='branch_id');
SET @s := IF(@c=0,'ALTER TABLE documents ADD COLUMN branch_id INT UNSIGNED DEFAULT NULL','SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='case_evidence' AND COLUMN_NAME='branch_id');
SET @s := IF(@c=0,'ALTER TABLE case_evidence ADD COLUMN branch_id INT UNSIGNED DEFAULT NULL','SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='consultations' AND COLUMN_NAME='branch_id');
SET @s := IF(@c=0,'ALTER TABLE consultations ADD COLUMN branch_id INT UNSIGNED DEFAULT NULL','SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='branch_id');
SET @s := IF(@c=0,'ALTER TABLE invoices ADD COLUMN branch_id INT UNSIGNED DEFAULT NULL','SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='legal_calendar_events' AND COLUMN_NAME='branch_id');
SET @s := IF(@c=0,'ALTER TABLE legal_calendar_events ADD COLUMN branch_id INT UNSIGNED DEFAULT NULL','SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='case_time_entries' AND COLUMN_NAME='branch_id');
SET @s := IF(@c=0,'ALTER TABLE case_time_entries ADD COLUMN branch_id INT UNSIGNED DEFAULT NULL','SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='draft_documents' AND COLUMN_NAME='branch_id');
SET @s := IF(@c=0,'ALTER TABLE draft_documents ADD COLUMN branch_id INT UNSIGNED DEFAULT NULL','SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Supporting indexes for the (firm_id, branch_id) filter used by every scoped query.
SET @i := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cases' AND INDEX_NAME='idx_cases_branch');
SET @s := IF(@i=0,'CREATE INDEX idx_cases_branch ON cases (firm_id, branch_id)','SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @i := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='documents' AND INDEX_NAME='idx_documents_branch');
SET @s := IF(@i=0,'CREATE INDEX idx_documents_branch ON documents (branch_id)','SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @i := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='case_evidence' AND INDEX_NAME='idx_evidence_branch');
SET @s := IF(@i=0,'CREATE INDEX idx_evidence_branch ON case_evidence (branch_id)','SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @i := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='consultations' AND INDEX_NAME='idx_consultations_branch');
SET @s := IF(@i=0,'CREATE INDEX idx_consultations_branch ON consultations (branch_id)','SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @i := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND INDEX_NAME='idx_invoices_branch');
SET @s := IF(@i=0,'CREATE INDEX idx_invoices_branch ON invoices (branch_id)','SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @i := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='legal_calendar_events' AND INDEX_NAME='idx_lce_branch');
SET @s := IF(@i=0,'CREATE INDEX idx_lce_branch ON legal_calendar_events (branch_id)','SELECT 1'); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ============================================================
-- 4. CUSTOM DOMAINS
-- ============================================================

CREATE TABLE IF NOT EXISTS firm_domains (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    firm_id INT UNSIGNED NOT NULL,
    domain VARCHAR(255) NOT NULL,
    status ENUM('pending','verification_pending','verified','active','failed','disabled') NOT NULL DEFAULT 'pending',
    verification_token CHAR(48) NOT NULL,
    verification_method ENUM('dns_txt','dns_cname') NOT NULL DEFAULT 'dns_txt',
    verified_at DATETIME DEFAULT NULL,
    last_checked_at DATETIME DEFAULT NULL,
    last_check_result VARCHAR(500) DEFAULT NULL,
    ssl_status ENUM('unknown','manual_required','ready','failed') NOT NULL DEFAULT 'unknown',
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    created_by_lawyer_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_firm_domain (domain),
    KEY idx_fd_firm (firm_id),
    KEY idx_fd_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 5. AI RESILIENCE, RATE LIMITING AND PRIVACY GOVERNANCE
-- ============================================================

-- Provider/model health probe history (admin-only surface).
CREATE TABLE IF NOT EXISTS ai_provider_health (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(40) NOT NULL,
    model VARCHAR(150) DEFAULT NULL,
    endpoint VARCHAR(255) DEFAULT NULL,
    ok TINYINT(1) NOT NULL DEFAULT 0,
    latency_ms INT UNSIGNED DEFAULT NULL,
    error_message VARCHAR(500) DEFAULT NULL,
    checked_by_admin_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_aph_provider (provider, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Persisted copilot runs. `verified_json` holds facts taken from stored records;
-- `suggested_json` holds AI proposals. They are stored apart so the UI can never
-- present a suggestion as a verified fact.
CREATE TABLE IF NOT EXISTS ai_copilot_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    firm_id INT UNSIGNED DEFAULT NULL,
    case_id INT UNSIGNED DEFAULT NULL,
    lawyer_id INT UNSIGNED DEFAULT NULL,
    kind ENUM('chronology','summary','facts','missing_documents','hearing_prep','research','document_analysis','draft','risk_flags') NOT NULL,
    provider VARCHAR(40) DEFAULT NULL,
    model VARCHAR(150) DEFAULT NULL,
    verified_json LONGTEXT DEFAULT NULL,
    suggested_json LONGTEXT DEFAULT NULL,
    status ENUM('completed','pending_review','approved','rejected','failed') NOT NULL DEFAULT 'completed',
    requires_review TINYINT(1) NOT NULL DEFAULT 1,
    reviewed_by_lawyer_id INT UNSIGNED DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    error_message VARCHAR(500) DEFAULT NULL,
    branch_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_acr_firm (firm_id, created_at),
    KEY idx_acr_case (case_id),
    KEY idx_acr_status (firm_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Consent register (privacy notices / AI external-transmission consent).
CREATE TABLE IF NOT EXISTS privacy_consents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subject_type ENUM('client','lawyer','firm') NOT NULL,
    subject_id INT UNSIGNED NOT NULL,
    consent_type VARCHAR(60) NOT NULL,
    policy_version VARCHAR(40) NOT NULL DEFAULT 'v1',
    granted TINYINT(1) NOT NULL DEFAULT 0,
    granted_at DATETIME DEFAULT NULL,
    revoked_at DATETIME DEFAULT NULL,
    ip VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pc (subject_type, subject_id, consent_type, policy_version),
    KEY idx_pc_type (consent_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Retention policy per data class (declared policy; enforcement is operational).
CREATE TABLE IF NOT EXISTS data_retention_policies (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    data_class VARCHAR(60) NOT NULL,
    retention_days INT UNSIGNED NOT NULL DEFAULT 3650,
    legal_basis VARCHAR(255) DEFAULT NULL,
    notes VARCHAR(500) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_drp_class (data_class)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Runtime knobs for provider resilience and rate limits (key/value, same shape as
-- site_settings so the existing settings surface can read them).
INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES
    ('ai_max_retries', '2'),
    ('ai_timeout_seconds', '25'),
    ('ai_rate_limit_per_hour', '120'),
    ('ai_fallback_enabled', '1');

-- Declared retention defaults. Illustrative policy values, not legal advice.
INSERT IGNORE INTO data_retention_policies (data_class, retention_days, legal_basis, notes) VALUES
    ('case_file', 3650, 'عميل / تنفيذ عقد', 'ملفات القضايا المغلقة'),
    ('client_documents', 3650, 'عميل / تنفيذ عقد', 'مستندات العملاء'),
    ('ai_transmissions', 730, 'مصلحة مشروعة / أمن', 'سجلات إرسال خارجي'),
    ('audit_logs', 2555, 'التزام قانوني / أمن', 'سجل التدقيق');
