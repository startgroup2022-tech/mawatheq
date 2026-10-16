-- ============================================================
-- Mawatheq Platform — Payment gateway abstraction + SADAD
-- Upgrade v45
-- ------------------------------------------------------------
-- SAFETY: additive only. No DROP, no destructive ALTER, no data rewrite. Every statement is
-- idempotent so the file can be re-run. Historical financial rows are never touched: existing
-- invoices/payments keep their stored currency and amount exactly as they are.
--
-- What it does:
--   1) Extends payment_transactions with the columns the unified gateway layer needs
--      (transaction id, currency, status, environment, response fields, timestamps, metadata).
--   2) Adds tenant_gateway_settings for per-tenant gateway credentials (SADAD is per-tenant).
--   3) Seeds SADAD defaults in site_settings so the admin console has a starting point.
-- ============================================================

SET NAMES utf8mb4;

-- 1) payment_transactions: additive columns ---------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_transactions' AND COLUMN_NAME='gateway_transaction_id');
SET @s := IF(@c=0, 'ALTER TABLE payment_transactions ADD COLUMN gateway_transaction_id VARCHAR(120) DEFAULT NULL AFTER gateway_order_ref', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_transactions' AND COLUMN_NAME='currency');
SET @s := IF(@c=0, 'ALTER TABLE payment_transactions ADD COLUMN currency VARCHAR(3) DEFAULT NULL AFTER amount', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_transactions' AND COLUMN_NAME='status');
SET @s := IF(@c=0, 'ALTER TABLE payment_transactions ADD COLUMN status VARCHAR(20) DEFAULT NULL AFTER currency', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_transactions' AND COLUMN_NAME='environment');
SET @s := IF(@c=0, 'ALTER TABLE payment_transactions ADD COLUMN environment VARCHAR(20) DEFAULT NULL AFTER status', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_transactions' AND COLUMN_NAME='response_code');
SET @s := IF(@c=0, 'ALTER TABLE payment_transactions ADD COLUMN response_code VARCHAR(40) DEFAULT NULL AFTER environment', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_transactions' AND COLUMN_NAME='response_message');
SET @s := IF(@c=0, 'ALTER TABLE payment_transactions ADD COLUMN response_message VARCHAR(255) DEFAULT NULL AFTER response_code', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_transactions' AND COLUMN_NAME='paid_at');
SET @s := IF(@c=0, 'ALTER TABLE payment_transactions ADD COLUMN paid_at DATETIME DEFAULT NULL AFTER response_message', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_transactions' AND COLUMN_NAME='callback_received_at');
SET @s := IF(@c=0, 'ALTER TABLE payment_transactions ADD COLUMN callback_received_at DATETIME DEFAULT NULL AFTER paid_at', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_transactions' AND COLUMN_NAME='verified_at');
SET @s := IF(@c=0, 'ALTER TABLE payment_transactions ADD COLUMN verified_at DATETIME DEFAULT NULL AFTER callback_received_at', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_transactions' AND COLUMN_NAME='metadata');
SET @s := IF(@c=0, 'ALTER TABLE payment_transactions ADD COLUMN metadata TEXT DEFAULT NULL AFTER verified_at', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_transactions' AND INDEX_NAME='idx_payment_tx_gateway');
SET @s := IF(@c=0, 'ALTER TABLE payment_transactions ADD INDEX idx_payment_tx_gateway (gateway, environment, status)', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 1b) Extend the payment-method enum to recognise SADAD (non-destructive enum extension) ------
SET @c := (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoice_payments' AND COLUMN_NAME='method');
SET @s := IF(@c IS NOT NULL AND LOCATE("'sadad'", @c)=0, "ALTER TABLE invoice_payments MODIFY COLUMN method ENUM('manual','cash','bank_transfer','card','ngenius','sadad','other') NOT NULL DEFAULT 'manual'", 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 2) Per-tenant gateway credentials -----------------------------------------
CREATE TABLE IF NOT EXISTS tenant_gateway_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_type ENUM('firm','lawyer','platform') NOT NULL,
    tenant_id INT UNSIGNED NOT NULL,
    gateway VARCHAR(30) NOT NULL,
    settings_json TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_tenant_gateway (tenant_type, tenant_id, gateway)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2b) Webhook/callback event log (replay protection + audit) -----------------
CREATE TABLE IF NOT EXISTS gateway_webhook_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    gateway VARCHAR(30) NOT NULL,
    event_id VARCHAR(120) DEFAULT NULL,
    event_type VARCHAR(60) DEFAULT NULL,
    reference VARCHAR(120) DEFAULT NULL,
    payment_id INT UNSIGNED DEFAULT NULL,
    processing_status ENUM('received','processed','ignored','duplicate','rejected') NOT NULL DEFAULT 'received',
    message VARCHAR(255) DEFAULT NULL,
    event_hash CHAR(64) DEFAULT NULL,
    raw_payload TEXT DEFAULT NULL,
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME DEFAULT NULL,
    UNIQUE KEY uniq_gateway_event_hash (gateway, event_hash),
    INDEX idx_gateway_events_ref (gateway, reference, received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3) SADAD defaults (blank endpoints/credentials; operator fills them in the console) ----
INSERT INTO site_settings (setting_key, setting_value) VALUES
('sadad_enabled', '0'),
('sadad_environment', 'sandbox'),
('sadad_currency', 'SAR')
ON DUPLICATE KEY UPDATE setting_value = setting_value;

-- ------------------------------------------------------------
-- ROLLBACK (manual, if ever needed — drops ONLY the additive columns/table):
--   ALTER TABLE payment_transactions DROP COLUMN gateway_transaction_id, DROP COLUMN currency,
--     DROP COLUMN status, DROP COLUMN environment, DROP COLUMN response_code,
--     DROP COLUMN response_message, DROP COLUMN paid_at, DROP COLUMN callback_received_at,
--     DROP COLUMN verified_at, DROP COLUMN metadata, DROP INDEX idx_payment_tx_gateway;
--   DROP TABLE tenant_gateway_settings;
--   DELETE FROM site_settings WHERE setting_key IN ('sadad_enabled','sadad_environment','sadad_currency');
-- ------------------------------------------------------------
