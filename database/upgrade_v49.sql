-- ============================================================
-- Mawatheq upgrade v49 — external integration readiness
-- Non-destructive. Safe to re-run.
-- ============================================================
--
-- External systems (a firm's own dashboard, an accounting tool, a mobile app) need a
-- supported way in and out of Mawatheq that does not rely on scraping browser pages or
-- on an operator pasting a shared secret into a script:
--   * integration_api_tokens — scoped, revocable bearer tokens for the REST API, so a
--     partner can read exactly the resources it was granted and nothing else.
--   * integration_webhooks    — operator-defined outbound endpoints, each with its own
--     signing secret so the receiver can verify the payload really came from Mawatheq.
--   * integration_events / integration_deliveries — an append-only event log and a
--     per-endpoint delivery record. The unique (webhook_id,event_id) key makes delivery
--     idempotent: a retry can never double-post the same event.

CREATE TABLE IF NOT EXISTS integration_api_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    firm_id INT UNSIGNED DEFAULT NULL,
    label VARCHAR(150) NOT NULL DEFAULT '',
    token_hash CHAR(64) NOT NULL,
    scopes VARCHAR(500) NOT NULL DEFAULT '',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_used_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at DATETIME DEFAULT NULL,
    UNIQUE KEY uq_integration_token_hash (token_hash),
    INDEX idx_integration_token_firm (firm_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS integration_webhooks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    firm_id INT UNSIGNED DEFAULT NULL,
    label VARCHAR(150) NOT NULL DEFAULT '',
    target_url VARCHAR(500) NOT NULL,
    secret_enc TEXT DEFAULT NULL,
    event_types VARCHAR(500) NOT NULL DEFAULT '*',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_integration_webhook_firm (firm_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS integration_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    firm_id INT UNSIGNED DEFAULT NULL,
    event_type VARCHAR(100) NOT NULL,
    payload MEDIUMTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_integration_event_firm_type (firm_id, event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS integration_deliveries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    webhook_id BIGINT UNSIGNED NOT NULL,
    event_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(100) NOT NULL DEFAULT '',
    payload MEDIUMTEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    http_code INT DEFAULT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    last_error VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delivered_at DATETIME DEFAULT NULL,
    UNIQUE KEY uq_integration_delivery (webhook_id, event_id),
    INDEX idx_integration_delivery_status (status),
    FOREIGN KEY (webhook_id) REFERENCES integration_webhooks(id) ON DELETE CASCADE,
    FOREIGN KEY (event_id) REFERENCES integration_events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
