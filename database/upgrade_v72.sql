-- ============================================================================
-- مواثيق — تشخيص عمليات بوابة الدفع (Execution 67)
-- MAWATHEQ — payment gateway diagnostics
-- upgrade_v72.sql
-- ============================================================================
--
-- WHY
--
-- The customer message "could not start the online payment" is produced by one line
-- for half a dozen unrelated causes: the gateway is off, the API key is rejected, the
-- account points at the wrong environment, the token call returns a bare 400, or the
-- outlet rejects the order. None of them is visible from the UI, so the failure cannot
-- be diagnosed without reading server logs — which shared cPanel hosting often hides.
--
-- This table records each gateway attempt with a correlation id the customer can quote,
-- the stage that failed, the gateway's own (sanitized) reason and the HTTP status.
-- Credentials, tokens and card fields are redacted before insert (see
-- includes/gateway_diagnostics.php).
--
-- SAFE TO REPLAY: pure CREATE TABLE IF NOT EXISTS, no foreign keys, additive only.
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS gateway_diagnostics (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    correlation_id CHAR(8) NOT NULL,
    gateway VARCHAR(30) NOT NULL DEFAULT 'ngenius',
    environment VARCHAR(20) DEFAULT NULL,
    operation VARCHAR(40) DEFAULT NULL,
    stage VARCHAR(40) DEFAULT NULL,
    http_status SMALLINT DEFAULT NULL,
    ok TINYINT(1) DEFAULT NULL,
    error_code VARCHAR(60) DEFAULT NULL,
    detail VARCHAR(300) DEFAULT NULL,
    order_ref VARCHAR(100) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_gw_diag_corr (correlation_id),
    KEY idx_gw_diag_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
