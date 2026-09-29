-- v41: AI Orchestration — multi-step agent workflows with human approval, cost tracking.
CREATE TABLE IF NOT EXISTS ai_workflows (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL UNIQUE,
    description VARCHAR(500) DEFAULT NULL,
    steps_json MEDIUMTEXT NOT NULL,
    requires_approval TINYINT(1) NOT NULL DEFAULT 1,
    status ENUM('active','disabled') NOT NULL DEFAULT 'active',
    created_by_admin_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ai_workflow_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workflow_id INT UNSIGNED NOT NULL,
    admin_id INT UNSIGNED DEFAULT NULL,
    task_input TEXT NOT NULL,
    status ENUM('running','completed','failed','pending_approval','approved','rejected') NOT NULL DEFAULT 'running',
    failed_step TINYINT UNSIGNED DEFAULT NULL,
    error_message VARCHAR(500) DEFAULT NULL,
    final_output MEDIUMTEXT DEFAULT NULL,
    total_input_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    total_output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    estimated_cost DECIMAL(12,6) DEFAULT NULL,
    reviewed_by_admin_id INT UNSIGNED DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    review_note VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME DEFAULT NULL,
    INDEX idx_wf_runs_status (status, created_at),
    INDEX idx_wf_runs_workflow (workflow_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ai_agent_executions' AND COLUMN_NAME='workflow_run_id');
SET @s := IF(@c=0, 'ALTER TABLE ai_agent_executions ADD COLUMN workflow_run_id BIGINT UNSIGNED DEFAULT NULL, ADD COLUMN step_no TINYINT UNSIGNED DEFAULT NULL, ADD INDEX idx_exec_run (workflow_run_id)', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

INSERT IGNORE INTO ai_workflows (name, description, steps_json, requires_approval) VALUES
('Research → Analysis → Red-team review',
 'Grounded legal research, then case analysis on the findings, then an adversarial review of unsupported claims. Requires human approval.',
 '[{"agent":"legal_research","module":"legal_research"},{"agent":"case_analysis","module":"case_analysis"},{"agent":"red_team","module":"case_analysis"}]', 1);
