-- ============================================================
-- Mawatheq Platform — Phase 3 (Finance + AI + Integrations)
-- Upgrade v43
-- ------------------------------------------------------------
-- SAFETY: this migration is additive only. It never drops a table or
-- column, never rewrites existing rows destructively, and every ALTER
-- is guarded by an information_schema check so it can be re-run safely.
-- Existing invoices/payments columns are preserved; new columns carry
-- DEFAULT NULL or a safe default so pre-v43 code keeps working.
-- ============================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- 1) INVOICE LIFECYCLE
--    Existing columns are kept: amount, description, status, is_draft,
--    due_date, paid_at, payment_method, gateway, gateway_order_ref.
--    New columns add the standard legal-billing lifecycle without
--    changing the semantics of any existing column.
-- ------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='invoice_number');
SET @s := IF(@c=0, 'ALTER TABLE invoices ADD COLUMN invoice_number VARCHAR(40) DEFAULT NULL AFTER id, ADD UNIQUE KEY uniq_invoices_number (invoice_number)', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='currency');
SET @s := IF(@c=0, 'ALTER TABLE invoices ADD COLUMN currency VARCHAR(3) NOT NULL DEFAULT ''BHD'' AFTER amount', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='subtotal');
SET @s := IF(@c=0, 'ALTER TABLE invoices ADD COLUMN subtotal DECIMAL(12,3) NOT NULL DEFAULT 0 AFTER currency', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='discount_total');
SET @s := IF(@c=0, 'ALTER TABLE invoices ADD COLUMN discount_total DECIMAL(12,3) NOT NULL DEFAULT 0 AFTER subtotal', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='tax_total');
SET @s := IF(@c=0, 'ALTER TABLE invoices ADD COLUMN tax_total DECIMAL(12,3) NOT NULL DEFAULT 0 AFTER discount_total', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='amount_paid');
SET @s := IF(@c=0, 'ALTER TABLE invoices ADD COLUMN amount_paid DECIMAL(12,3) NOT NULL DEFAULT 0 AFTER tax_total', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='issue_date');
SET @s := IF(@c=0, 'ALTER TABLE invoices ADD COLUMN issue_date DATE DEFAULT NULL AFTER due_date', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='notes');
SET @s := IF(@c=0, 'ALTER TABLE invoices ADD COLUMN notes TEXT DEFAULT NULL AFTER issue_date', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='terms');
SET @s := IF(@c=0, 'ALTER TABLE invoices ADD COLUMN terms TEXT DEFAULT NULL AFTER notes', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='created_by_lawyer_id');
SET @s := IF(@c=0, 'ALTER TABLE invoices ADD COLUMN created_by_lawyer_id INT UNSIGNED DEFAULT NULL AFTER lawyer_id', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Extend the status enum with the lifecycle states while keeping every
-- existing value ('unpaid','paid','overdue','cancelled') intact, so the
-- current UI and reports keep working without modification.
SET @s := (SELECT IF(LOCATE('partially_paid', COLUMN_TYPE)=0,
    'ALTER TABLE invoices MODIFY COLUMN status ENUM(''unpaid'',''partially_paid'',''paid'',''overdue'',''cancelled'') NOT NULL DEFAULT ''unpaid''',
    'SELECT 1')
    FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='status');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND INDEX_NAME='idx_invoices_status_due');
SET @s := IF(@c=0, 'ALTER TABLE invoices ADD INDEX idx_invoices_status_due (status,due_date)', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ------------------------------------------------------------
-- 2) INVOICE ITEMS — quantity/unit price/discount/tax line items.
--    Invoice `amount` stays the authoritative grand total so every
--    existing query, report and the payment gateway keep working.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS invoice_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT UNSIGNED NOT NULL,
    firm_id INT UNSIGNED DEFAULT NULL,
    description VARCHAR(255) NOT NULL,
    quantity DECIMAL(12,3) NOT NULL DEFAULT 1,
    unit_price DECIMAL(12,3) NOT NULL DEFAULT 0,
    discount_amount DECIMAL(12,3) NOT NULL DEFAULT 0,
    tax_rate DECIMAL(6,3) NOT NULL DEFAULT 0,
    line_total DECIMAL(12,3) NOT NULL DEFAULT 0,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_invoice_items_invoice (invoice_id,sort_order),
    INDEX idx_invoice_items_firm (firm_id),
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    CONSTRAINT fk_invoice_items_firm FOREIGN KEY (firm_id) REFERENCES law_firms(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 3) INVOICE PAYMENTS — real payment records (manual + gateway),
--    separate from payment_transactions which stores raw gateway events.
--    There is NO inventory anywhere in this schema.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS invoice_payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT UNSIGNED NOT NULL,
    firm_id INT UNSIGNED DEFAULT NULL,
    lawyer_id INT UNSIGNED DEFAULT NULL,
    amount DECIMAL(12,3) NOT NULL,
    currency VARCHAR(3) NOT NULL DEFAULT 'BHD',
    method ENUM('manual','cash','bank_transfer','card','ngenius','other') NOT NULL DEFAULT 'manual',
    status ENUM('pending','completed','failed','refunded') NOT NULL DEFAULT 'completed',
    reference VARCHAR(120) DEFAULT NULL,
    idempotency_key VARCHAR(80) DEFAULT NULL,
    notes VARCHAR(500) DEFAULT NULL,
    paid_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    recorded_by_type ENUM('lawyer','admin','client','system') NOT NULL DEFAULT 'system',
    recorded_by_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_invoice_payments_idem (idempotency_key),
    INDEX idx_invoice_payments_invoice (invoice_id,paid_at),
    INDEX idx_invoice_payments_firm (firm_id,paid_at),
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    CONSTRAINT fk_invoice_payments_firm FOREIGN KEY (firm_id) REFERENCES law_firms(id) ON DELETE SET NULL,
    CONSTRAINT fk_invoice_payments_lawyer FOREIGN KEY (lawyer_id) REFERENCES lawyers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tag the existing raw gateway event log with the tenant + a dedupe hash.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_transactions' AND COLUMN_NAME='firm_id');
SET @s := IF(@c=0, 'ALTER TABLE payment_transactions ADD COLUMN firm_id INT UNSIGNED DEFAULT NULL AFTER invoice_id, ADD INDEX idx_payment_tx_firm (firm_id)', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_transactions' AND COLUMN_NAME='event_hash');
SET @s := IF(@c=0, 'ALTER TABLE payment_transactions ADD COLUMN event_hash CHAR(64) DEFAULT NULL AFTER event_type, ADD UNIQUE KEY uniq_payment_tx_hash (event_hash)', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ------------------------------------------------------------
-- 4) AI TENANT PRIVACY CONTROL
--    One row per tenant (law firm or solo lawyer). Default is the most
--    restrictive mode: external transmission is denied until a human
--    explicitly allows it for the tenant.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ai_tenant_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_type ENUM('firm','lawyer','platform') NOT NULL,
    tenant_id INT UNSIGNED NOT NULL,
    privacy_mode ENUM('local_only','external_disabled','external_allowed') NOT NULL DEFAULT 'external_disabled',
    allowed_data_categories VARCHAR(500) NOT NULL DEFAULT 'user_prompt',
    external_features VARCHAR(500) NOT NULL DEFAULT '',
    consent_actor_type ENUM('lawyer','admin') DEFAULT NULL,
    consent_actor_id INT UNSIGNED DEFAULT NULL,
    consent_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_ai_tenant (tenant_type,tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 5) AI EXTERNAL TRANSMISSION AUDIT
--    One row every time tenant data leaves the platform for an external
--    AI provider. Records category + counts, never the content or keys.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ai_external_transmissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_type ENUM('firm','lawyer','platform') NOT NULL DEFAULT 'platform',
    tenant_id INT UNSIGNED DEFAULT NULL,
    actor_type ENUM('client','lawyer','admin','system') NOT NULL DEFAULT 'system',
    actor_id INT UNSIGNED DEFAULT NULL,
    firm_id INT UNSIGNED DEFAULT NULL,
    feature VARCHAR(60) NOT NULL,
    module_id VARCHAR(80) DEFAULT NULL,
    provider VARCHAR(40) DEFAULT NULL,
    model VARCHAR(150) DEFAULT NULL,
    data_category VARCHAR(60) NOT NULL DEFAULT 'user_prompt',
    payload_bytes INT UNSIGNED DEFAULT NULL,
    external_sent TINYINT(1) NOT NULL DEFAULT 0,
    consent_mode VARCHAR(30) DEFAULT NULL,
    outcome ENUM('allowed','denied','failed') NOT NULL DEFAULT 'allowed',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ai_tx_tenant (tenant_type,tenant_id,created_at),
    INDEX idx_ai_tx_feature (feature,created_at),
    INDEX idx_ai_tx_actor (actor_type,actor_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 6) AI USAGE TRACKING (tenant level)
--    Token/cost columns stay NULL when the provider does not report them
--    — nothing is ever fabricated.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ai_usage_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_type ENUM('firm','lawyer','platform','client') NOT NULL DEFAULT 'platform',
    tenant_id INT UNSIGNED DEFAULT NULL,
    firm_id INT UNSIGNED DEFAULT NULL,
    actor_type ENUM('client','lawyer','admin','system') NOT NULL DEFAULT 'system',
    actor_id INT UNSIGNED DEFAULT NULL,
    provider VARCHAR(40) DEFAULT NULL,
    model VARCHAR(150) DEFAULT NULL,
    feature VARCHAR(60) NOT NULL,
    module_id VARCHAR(80) DEFAULT NULL,
    success TINYINT(1) NOT NULL DEFAULT 1,
    input_tokens INT UNSIGNED DEFAULT NULL,
    output_tokens INT UNSIGNED DEFAULT NULL,
    estimated_cost DECIMAL(12,6) DEFAULT NULL,
    external_sent TINYINT(1) NOT NULL DEFAULT 0,
    error_message VARCHAR(500) DEFAULT NULL,
    duration_ms INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ai_usage_tenant (tenant_type,tenant_id,created_at),
    INDEX idx_ai_usage_feature (feature,created_at),
    INDEX idx_ai_usage_actor (actor_type,actor_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 7) USER/COMPANY AI AGENTS (controlled, permission-scoped)
--    Distinct from the platform admin `ai_agents`: these are owned by a
--    tenant/user and restricted to an explicit tool allow-list.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ai_user_agents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_type ENUM('client','lawyer') NOT NULL,
    owner_id INT UNSIGNED NOT NULL,
    tenant_type ENUM('firm','lawyer','solo') NOT NULL DEFAULT 'solo',
    firm_id INT UNSIGNED DEFAULT NULL,
    name VARCHAR(150) NOT NULL,
    description VARCHAR(500) DEFAULT NULL,
    persona_prompt TEXT DEFAULT NULL,
    allowed_tools VARCHAR(1000) NOT NULL DEFAULT '',
    allowed_data_sources VARCHAR(500) NOT NULL DEFAULT '',
    status ENUM('active','disabled') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ai_user_agents_owner (owner_type,owner_id),
    INDEX idx_ai_user_agents_firm (firm_id),
    CONSTRAINT fk_ai_user_agents_firm FOREIGN KEY (firm_id) REFERENCES law_firms(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 8) AI AGENT ACTIVITY LOG — every tool the agent attempts, and whether
--    the application authorized it. Destructive tools always require an
--    explicit human confirmation before they may execute.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ai_agent_activity_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    agent_id INT UNSIGNED DEFAULT NULL,
    owner_type ENUM('client','lawyer') DEFAULT NULL,
    owner_id INT UNSIGNED DEFAULT NULL,
    tenant_type ENUM('firm','lawyer','solo') DEFAULT NULL,
    firm_id INT UNSIGNED DEFAULT NULL,
    session_id VARCHAR(64) DEFAULT NULL,
    tool_name VARCHAR(80) NOT NULL,
    requires_confirmation TINYINT(1) NOT NULL DEFAULT 0,
    confirmation_state ENUM('not_required','pending','confirmed','rejected') NOT NULL DEFAULT 'not_required',
    outcome ENUM('success','denied','failed') NOT NULL DEFAULT 'success',
    detail VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ai_agent_activity_agent (agent_id,created_at),
    INDEX idx_ai_agent_activity_owner (owner_type,owner_id,created_at),
    INDEX idx_ai_agent_activity_firm (firm_id,created_at),
    FOREIGN KEY (agent_id) REFERENCES ai_user_agents(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 9) TENANT AI SETTINGS FOR EVERY PRE-EXISTING TENANT
--
--    These tenants already ran AI features before v43, so their behaviour must
--    be preserved: they are seeded as allowed, exactly as they operated before.
--    The restrictive default applies to tenants created *after* this migration,
--    which ai_tenant_policy() creates on first read as 'external_disabled'.
--    An admin or firm owner can tighten any tenant to local_only / disabled at
--    any time from the AI control surface.
-- ------------------------------------------------------------
INSERT IGNORE INTO ai_tenant_settings (tenant_type,tenant_id,privacy_mode,allowed_data_categories,external_features)
SELECT 'firm', f.id, 'external_allowed', 'user_prompt,legal_text,case_information,client_information,firm_knowledge,document_text', '' FROM law_firms f;

-- Solo practitioners (a lawyer with no active firm membership) are their own tenant.
INSERT IGNORE INTO ai_tenant_settings (tenant_type,tenant_id,privacy_mode,allowed_data_categories,external_features)
SELECT 'lawyer', l.id, 'external_allowed', 'user_prompt,legal_text,case_information,client_information,firm_knowledge,document_text', ''
FROM lawyers l
WHERE NOT EXISTS (SELECT 1 FROM law_firm_members m WHERE m.lawyer_id=l.id AND m.status='active');

-- ------------------------------------------------------------
-- 10) PLATFORM AI POLICY
--    The platform law library assistant and the client-facing document analysis
--    already run on the admin-configured provider; the platform policy therefore
--    reflects that existing platform-wide configuration. An admin can tighten or
--    disable it at any time from admin → AI control.
-- ------------------------------------------------------------
INSERT IGNORE INTO ai_tenant_settings (tenant_type,tenant_id,privacy_mode,allowed_data_categories,external_features)
VALUES ('platform', 0, 'external_allowed', 'user_prompt,legal_text,case_information,client_information,firm_knowledge,document_text', '');
