-- Knowledge/data architecture for modular, permission-aware AI retrieval.
-- Source content is not marked ready until text extraction and chunk indexing succeed.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS knowledge_sources (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED DEFAULT NULL,
    workspace_id INT UNSIGNED DEFAULT NULL,
    module_id VARCHAR(60) NOT NULL DEFAULT '*',
    source_type ENUM('file','manual','website','external_api','database') NOT NULL,
    source_name VARCHAR(255) NOT NULL,
    source_url TEXT DEFAULT NULL,
    source_domain VARCHAR(255) DEFAULT NULL,
    storage_path VARCHAR(500) DEFAULT NULL,
    original_filename VARCHAR(255) DEFAULT NULL,
    mime_type VARCHAR(150) DEFAULT NULL,
    content MEDIUMTEXT DEFAULT NULL,
    language VARCHAR(10) DEFAULT NULL,
    jurisdiction VARCHAR(100) DEFAULT NULL,
    category VARCHAR(100) DEFAULT NULL,
    tags TEXT DEFAULT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    status ENUM('active','archived') NOT NULL DEFAULT 'active',
    verification_status ENUM('unverified','verified') NOT NULL DEFAULT 'unverified',
    scope_type ENUM('global','firm','case','private') NOT NULL DEFAULT 'global',
    scope_id BIGINT UNSIGNED DEFAULT NULL,
    processing_status ENUM('queued','processing','needs_extraction','completed','failed') NOT NULL DEFAULT 'queued',
    indexing_status ENUM('pending','indexed','failed') NOT NULL DEFAULT 'pending',
    embedding_status ENUM('not_configured','pending','indexed','failed') NOT NULL DEFAULT 'not_configured',
    content_hash CHAR(64) DEFAULT NULL,
    created_by_type ENUM('admin','lawyer','client','system') NOT NULL DEFAULT 'admin',
    created_by INT UNSIGNED DEFAULT NULL,
    updated_by INT UNSIGNED DEFAULT NULL,
    retrieved_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_knowledge_module (module_id,status,processing_status,indexing_status),
    INDEX idx_knowledge_scope (scope_type,scope_id),
    INDEX idx_knowledge_jurisdiction (jurisdiction),
    INDEX idx_knowledge_hash (content_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS knowledge_source_versions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_id BIGINT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL,
    source_name VARCHAR(255) NOT NULL,
    source_url TEXT DEFAULT NULL,
    content MEDIUMTEXT DEFAULT NULL,
    metadata_json LONGTEXT DEFAULT NULL,
    changed_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_knowledge_version (source_id,version),
    FOREIGN KEY (source_id) REFERENCES knowledge_sources(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS knowledge_chunks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_id BIGINT UNSIGNED NOT NULL,
    chunk_order INT UNSIGNED NOT NULL,
    chunk_text MEDIUMTEXT NOT NULL,
    text_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_knowledge_chunk_order (source_id,chunk_order),
    INDEX idx_knowledge_chunk_hash (text_hash),
    FOREIGN KEY (source_id) REFERENCES knowledge_sources(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS knowledge_source_permissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_id BIGINT UNSIGNED NOT NULL,
    subject_type ENUM('admin','lawyer','client','firm','agent') NOT NULL,
    subject_id BIGINT UNSIGNED DEFAULT NULL,
    permission ENUM('read','manage') NOT NULL DEFAULT 'read',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_knowledge_permission (source_id,subject_type,subject_id,permission),
    FOREIGN KEY (source_id) REFERENCES knowledge_sources(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS knowledge_processing_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_id BIGINT UNSIGNED NOT NULL,
    stage VARCHAR(60) NOT NULL,
    status ENUM('started','completed','failed','skipped') NOT NULL,
    message VARCHAR(1000) DEFAULT NULL,
    duration_ms INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_knowledge_process_source (source_id,created_at),
    FOREIGN KEY (source_id) REFERENCES knowledge_sources(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ai_module_configs (
    module_id VARCHAR(60) PRIMARY KEY,
    display_name VARCHAR(120) NOT NULL,
    provider VARCHAR(40) DEFAULT NULL,
    model VARCHAR(150) DEFAULT NULL,
    system_prompt TEXT DEFAULT NULL,
    temperature DECIMAL(3,2) NOT NULL DEFAULT 0.20,
    max_tokens INT UNSIGNED NOT NULL DEFAULT 1200,
    requests_per_hour INT UNSIGNED NOT NULL DEFAULT 60,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ai_agents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    agent_key VARCHAR(60) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    agent_type VARCHAR(60) NOT NULL,
    system_prompt MEDIUMTEXT DEFAULT NULL,
    allowed_modules TEXT DEFAULT NULL,
    allowed_tools TEXT DEFAULT NULL,
    provider VARCHAR(40) DEFAULT NULL,
    model VARCHAR(150) DEFAULT NULL,
    temperature DECIMAL(3,2) NOT NULL DEFAULT 0.20,
    token_limit INT UNSIGNED NOT NULL DEFAULT 1200,
    status ENUM('active','disabled') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ai_agent_executions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    agent_id INT UNSIGNED DEFAULT NULL,
    user_type ENUM('admin','lawyer','client','system') NOT NULL DEFAULT 'system',
    user_id INT UNSIGNED DEFAULT NULL,
    task_name VARCHAR(150) NOT NULL,
    input_summary TEXT DEFAULT NULL,
    source_ids_json TEXT DEFAULT NULL,
    output_text MEDIUMTEXT DEFAULT NULL,
    status ENUM('queued','running','completed','failed','rejected') NOT NULL DEFAULT 'queued',
    error_message TEXT DEFAULT NULL,
    duration_ms INT UNSIGNED DEFAULT NULL,
    input_tokens INT UNSIGNED DEFAULT NULL,
    output_tokens INT UNSIGNED DEFAULT NULL,
    estimated_cost DECIMAL(12,6) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME DEFAULT NULL,
    INDEX idx_agent_execution_status (status,created_at),
    FOREIGN KEY (agent_id) REFERENCES ai_agents(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO ai_module_configs (module_id,display_name) VALUES
('*','Universal'),('legal_core','AI Legal Core'),('legal_research','Legal Research'),
('case_intelligence','Case Intelligence'),('case_analysis','Case Analysis'),
('evidence_intelligence','Evidence Intelligence'),('document_ai','Document AI'),
('legal_drafting','Legal Drafting'),('contract_intelligence','Contract Intelligence'),
('firm_ai','Firm AI'),('compliance','Compliance'),('opponent_analysis','Opponent Analysis'),
('meeting_ai','Meeting AI'),('hearing_ai','Hearing AI'),('translation','Translation'),
('summarization','Summarization'),('red_team','Red Team'),('agent','AI Agents');

INSERT IGNORE INTO ai_agents (agent_key,name,agent_type,system_prompt,allowed_modules,allowed_tools)
VALUES
('legal_research','Legal Research Agent','research','Use only permitted, retrieved sources. Never invent legal authorities.','legal_research,legal_core','knowledge_search'),
('case_analysis','Case Analysis Agent','case_analysis','Use only data from the authorized case and permitted sources.','case_analysis,case_intelligence','knowledge_search'),
('document_review','Document Review Agent','document_review','Review only the document explicitly supplied for this task.','document_ai,contract_intelligence','knowledge_search'),
('red_team','Red-Team Reviewer','red_team','Challenge claims and identify unsupported conclusions using supplied sources only.','red_team,case_analysis','knowledge_search');
