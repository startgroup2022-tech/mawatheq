-- The pre-fix shape: firm_id positioned AFTER a column that may be absent, with the
-- reset-column repair below it. Reproduced here so the regression is pinned.
ALTER TABLE audit_logs ADD COLUMN firm_id INT UNSIGNED DEFAULT NULL AFTER actor_name;
ALTER TABLE users ADD COLUMN reset_token VARCHAR(64) DEFAULT NULL;
ALTER TABLE users ADD COLUMN reset_expires DATETIME DEFAULT NULL;