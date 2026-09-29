-- v40: Admin CRM module (Opportunities / Companies / Activities / Tasks & Follow-ups).
-- Leads = existing contact_leads, Contacts/Clients = existing users. Only the missing entities are added.
CREATE TABLE IF NOT EXISTS crm_companies (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    industry VARCHAR(120) DEFAULT NULL,
    website VARCHAR(190) DEFAULT NULL,
    phone VARCHAR(40) DEFAULT NULL,
    email VARCHAR(150) DEFAULT NULL,
    address VARCHAR(255) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_by_admin_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_crm_companies_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_opportunities (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(190) NOT NULL,
    stage ENUM('new','qualified','proposal','negotiation','won','lost') NOT NULL DEFAULT 'new',
    value DECIMAL(12,3) NOT NULL DEFAULT 0,
    probability TINYINT UNSIGNED NOT NULL DEFAULT 10,
    expected_close DATE DEFAULT NULL,
    user_id INT UNSIGNED DEFAULT NULL,
    company_id INT UNSIGNED DEFAULT NULL,
    lead_id INT UNSIGNED DEFAULT NULL,
    owner_admin_id INT UNSIGNED DEFAULT NULL,
    lost_reason VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_crm_opp_stage (stage),
    INDEX idx_crm_opp_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_activities (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type ENUM('lead','client','company','opportunity') NOT NULL,
    entity_id INT UNSIGNED NOT NULL,
    activity_type ENUM('call','email','meeting','whatsapp','note','stage_change') NOT NULL DEFAULT 'note',
    summary VARCHAR(500) NOT NULL,
    admin_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_crm_act_entity (entity_type, entity_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_tasks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    task_type ENUM('task','follow_up') NOT NULL DEFAULT 'task',
    due_at DATETIME DEFAULT NULL,
    status ENUM('open','done') NOT NULL DEFAULT 'open',
    entity_type ENUM('lead','client','company','opportunity') DEFAULT NULL,
    entity_id INT UNSIGNED DEFAULT NULL,
    assigned_admin_id INT UNSIGNED DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_crm_tasks_due (status, due_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
