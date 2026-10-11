-- ============================================================
-- منصة مواثيق | Mawatheq Platform - Database Schema
-- MySQL 5.7+ / MariaDB 10.3+
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- المستخدمون (عملاء / أفراد / شركات)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    phone VARCHAR(30) DEFAULT NULL,
    password_hash VARCHAR(255) NOT NULL,
    account_type ENUM('individual','company') NOT NULL DEFAULT 'individual',
    company_name VARCHAR(150) DEFAULT NULL,
    plan_id INT UNSIGNED DEFAULT NULL,
    status ENUM('active','suspended','pending') NOT NULL DEFAULT 'active',
    preferred_lang ENUM('ar','en') NOT NULL DEFAULT 'ar',
    email_verified TINYINT(1) NOT NULL DEFAULT 1,
    email_verify_token_hash CHAR(64) DEFAULT NULL,
    email_verify_expires DATETIME DEFAULT NULL,
    reset_token VARCHAR(64) DEFAULT NULL,
    reset_expires DATETIME DEFAULT NULL,
    utm_source VARCHAR(100) DEFAULT NULL,
    utm_medium VARCHAR(100) DEFAULT NULL,
    utm_campaign VARCHAR(150) DEFAULT NULL,
    source_page VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- المحامون
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS lawyers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    phone VARCHAR(30) DEFAULT NULL,
    password_hash VARCHAR(255) NOT NULL,
    specialization VARCHAR(150) DEFAULT NULL,
    bio TEXT DEFAULT NULL,
    years_experience SMALLINT UNSIGNED DEFAULT 0,
    license_number VARCHAR(100) DEFAULT NULL,
    photo_path VARCHAR(255) DEFAULT NULL,
    qualifications TEXT DEFAULT NULL,
    social_linkedin VARCHAR(255) DEFAULT NULL,
    social_twitter VARCHAR(255) DEFAULT NULL,
    social_website VARCHAR(255) DEFAULT NULL,
    jurisdiction VARCHAR(100) NOT NULL DEFAULT 'السعودية',
    hourly_rate DECIMAL(12,3) DEFAULT NULL,
    rating DECIMAL(2,1) DEFAULT 5.0,
    status ENUM('pending','approved','suspended') NOT NULL DEFAULT 'pending',
    email_verified TINYINT(1) NOT NULL DEFAULT 1,
    email_verify_token_hash CHAR(64) DEFAULT NULL,
    email_verify_expires DATETIME DEFAULT NULL,
    reset_token VARCHAR(64) DEFAULT NULL,
    reset_expires DATETIME DEFAULT NULL,
    utm_source VARCHAR(100) DEFAULT NULL,
    utm_medium VARCHAR(100) DEFAULT NULL,
    utm_campaign VARCHAR(150) DEFAULT NULL,
    source_page VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS law_firms (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(180) NOT NULL,
    legal_name VARCHAR(200) DEFAULT NULL,
    display_name VARCHAR(180) DEFAULT NULL,
    email VARCHAR(150) DEFAULT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    address VARCHAR(255) DEFAULT NULL,
    city VARCHAR(100) DEFAULT NULL,
    country VARCHAR(100) DEFAULT NULL,
    commercial_register VARCHAR(80) DEFAULT NULL,
    license_number VARCHAR(80) DEFAULT NULL,
    logo_path VARCHAR(255) DEFAULT NULL,
    owner_lawyer_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_lawyer_id) REFERENCES lawyers(id) ON DELETE CASCADE,
    UNIQUE KEY uq_law_firm_owner (owner_lawyer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS law_firm_members (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    firm_id INT UNSIGNED NOT NULL,
    lawyer_id INT UNSIGNED NOT NULL,
    member_role ENUM('owner','partner','lawyer','staff') NOT NULL DEFAULT 'lawyer',
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    permissions_json TEXT DEFAULT NULL,
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_law_firm_member_lawyer (lawyer_id),
    INDEX idx_law_firm_members_firm (firm_id,member_role),
    FOREIGN KEY (firm_id) REFERENCES law_firms(id) ON DELETE CASCADE,
    FOREIGN KEY (lawyer_id) REFERENCES lawyers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS firm_knowledge (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    firm_id INT UNSIGNED NOT NULL,
    author_lawyer_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    category VARCHAR(100) DEFAULT NULL,
    content LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_firm_knowledge_firm (firm_id,updated_at),
    FOREIGN KEY (firm_id) REFERENCES law_firms(id) ON DELETE CASCADE,
    FOREIGN KEY (author_lawyer_id) REFERENCES lawyers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- فريق الإدارة (لوحة التحكم)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admins (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('super_admin','editor','support') NOT NULL DEFAULT 'editor',
    reset_token VARCHAR(64) DEFAULT NULL,
    reset_expires DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- الباقات المؤسسية / خطط الاشتراك
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS plans (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name_ar VARCHAR(100) NOT NULL,
    name_en VARCHAR(100) NOT NULL,
    description_ar TEXT,
    description_en TEXT,
    price_monthly DECIMAL(12,3) NOT NULL DEFAULT 0,
    price_yearly DECIMAL(12,3) DEFAULT NULL,
    ai_queries_limit INT DEFAULT NULL,       -- NULL = غير محدود
    document_analysis_limit INT DEFAULT NULL,
    consultations_included INT DEFAULT 0,
    is_corporate TINYINT(1) NOT NULL DEFAULT 0,
    is_featured TINYINT(1) NOT NULL DEFAULT 0,
    sort_order SMALLINT DEFAULT 0,
    status ENUM('active','hidden') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- ميزات كل باقة (لعرضها كقائمة نقاط)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS plan_features (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    plan_id INT UNSIGNED NOT NULL,
    feature_ar VARCHAR(255) NOT NULL,
    feature_en VARCHAR(255) NOT NULL,
    sort_order SMALLINT DEFAULT 0,
    FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- الاشتراكات النشطة
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS subscriptions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    plan_id INT UNSIGNED NOT NULL,
    billing_cycle ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly',
    starts_at DATE NOT NULL,
    ends_at DATE NOT NULL,
    status ENUM('active','expired','cancelled') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (plan_id) REFERENCES plans(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- محادثات المساعد القانوني الذكي
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ai_conversations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ai_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT UNSIGNED NOT NULL,
    sender ENUM('user','assistant') NOT NULL,
    message TEXT NOT NULL,
    reference_law VARCHAR(255) DEFAULT NULL,   -- المادة/النظام المستشهد به
    reference_article_id INT UNSIGNED DEFAULT NULL, -- ربط قابل للنقر بمادة نظامية محددة (law_articles.id)
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (conversation_id) REFERENCES ai_conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- المستندات المرفوعة وتحليلها
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    case_id INT UNSIGNED DEFAULT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_path VARCHAR(255) NOT NULL,
    file_type VARCHAR(50) DEFAULT NULL,
    document_type ENUM('general','contract') NOT NULL DEFAULT 'general',
    status ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
    summary TEXT DEFAULT NULL,
    contract_analysis_json MEDIUMTEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_documents_case (case_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS document_findings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    document_id INT UNSIGNED NOT NULL,
    type ENUM('risk','recommendation') NOT NULL,
    content TEXT NOT NULL,
    severity ENUM('low','medium','high') DEFAULT 'medium',
    sort_order SMALLINT DEFAULT 0,
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- مكتبة الأنظمة واللوائح (تصنيفات + مواد)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS law_categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name_ar VARCHAR(150) NOT NULL,
    name_en VARCHAR(150) NOT NULL,
    icon VARCHAR(50) DEFAULT NULL,
    jurisdiction VARCHAR(100) NOT NULL DEFAULT 'السعودية',
    sort_order SMALLINT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS law_articles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id INT UNSIGNED NOT NULL,
    law_name_ar VARCHAR(255) NOT NULL,
    law_name_en VARCHAR(255) NOT NULL,
    article_number VARCHAR(50) DEFAULT NULL,
    content_ar TEXT NOT NULL,
    content_en TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES law_categories(id) ON DELETE CASCADE,
    FULLTEXT INDEX ft_law_search (law_name_ar, content_ar, content_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- الاستشارات القانونية (حجوزات)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS consultations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    lawyer_id INT UNSIGNED NOT NULL,
    firm_id INT UNSIGNED DEFAULT NULL,
    consultation_type ENUM('text','voice','video') NOT NULL DEFAULT 'text',
    scheduled_at DATETIME NOT NULL,
    duration_minutes SMALLINT DEFAULT 30,
    topic VARCHAR(255) DEFAULT NULL,
    status ENUM('pending','confirmed','completed','cancelled') NOT NULL DEFAULT 'pending',
    price DECIMAL(12,3) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    room_code VARCHAR(64) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_consultations_firm (firm_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (lawyer_id) REFERENCES lawyers(id) ON DELETE CASCADE,
    CONSTRAINT fk_consultations_firm FOREIGN KEY (firm_id) REFERENCES law_firms(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- القضايا (إدارة القضايا للمحامين)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cases (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lawyer_id INT UNSIGNED NOT NULL,
    firm_id INT UNSIGNED DEFAULT NULL,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    case_type VARCHAR(100) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    win_probability TINYINT UNSIGNED DEFAULT NULL, -- 0-100
    status ENUM('open','in_review','closed_won','closed_lost','closed_settled') NOT NULL DEFAULT 'open',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cases_firm (firm_id),
    FOREIGN KEY (lawyer_id) REFERENCES lawyers(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_cases_firm FOREIGN KEY (firm_id) REFERENCES law_firms(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS case_timeline (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id INT UNSIGNED NOT NULL,
    event VARCHAR(255) NOT NULL,
    event_date DATE NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- رسائل الاستشارة النصية (chat) + غرفة المكالمة (صوت/فيديو عبر Jitsi)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS consultation_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    consultation_id INT UNSIGNED NOT NULL,
    sender_type ENUM('client','lawyer') NOT NULL,
    message TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (consultation_id) REFERENCES consultations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- ملاحظات الإدارة الداخلية على كل عميل (CRM)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS crm_notes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    admin_id INT UNSIGNED DEFAULT NULL,
    note TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- مركز المعرفة (مدونة / مقالات)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS articles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title_ar VARCHAR(255) NOT NULL,
    title_en VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    excerpt_ar TEXT,
    excerpt_en TEXT,
    content_ar LONGTEXT,
    content_en LONGTEXT,
    cover_image VARCHAR(255) DEFAULT NULL,
    status ENUM('draft','published') NOT NULL DEFAULT 'draft',
    published_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- آراء العملاء (Testimonials)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS testimonials (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name_ar VARCHAR(150) NOT NULL,
    name_en VARCHAR(150) NOT NULL,
    role_ar VARCHAR(150) DEFAULT NULL,
    role_en VARCHAR(150) DEFAULT NULL,
    quote_ar TEXT NOT NULL,
    quote_en TEXT NOT NULL,
    sort_order SMALLINT DEFAULT 0,
    status ENUM('active','hidden') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- طلبات "انضم كمحامٍ" و"تواصل معنا"
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS contact_leads (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lead_type ENUM('contact','join_lawyer','corporate') NOT NULL DEFAULT 'contact',
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(150) DEFAULT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    message TEXT DEFAULT NULL,
    status ENUM('new','contacted','closed') NOT NULL DEFAULT 'new',
    utm_source VARCHAR(100) DEFAULT NULL,
    utm_medium VARCHAR(100) DEFAULT NULL,
    utm_campaign VARCHAR(150) DEFAULT NULL,
    source_page VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- إعدادات الموقع العامة (تُدار من لوحة التحكم)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS document_entities (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    document_id INT UNSIGNED NOT NULL,
    entity_type ENUM('date','amount','party','obligation') NOT NULL,
    value VARCHAR(255) NOT NULL,
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS case_parties (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id INT UNSIGNED NOT NULL,
    party_type ENUM('plaintiff','defendant','witness','third_party','other') NOT NULL DEFAULT 'other',
    name VARCHAR(255) NOT NULL,
    role_description VARCHAR(255) DEFAULT NULL,
    contact_info VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS case_claims (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id INT UNSIGNED NOT NULL,
    claim_type ENUM('claim','defense') NOT NULL DEFAULT 'claim',
    description TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS case_facts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id INT UNSIGNED NOT NULL,
    description TEXT NOT NULL,
    occurred_at DATE DEFAULT NULL,
    source_note TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
    INDEX idx_case_facts_case_date (case_id, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS case_tasks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    due_date DATE DEFAULT NULL,
    status ENUM('pending','done') NOT NULL DEFAULT 'pending',
    client_visible TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS case_notes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id INT UNSIGNED NOT NULL,
    lawyer_id INT UNSIGNED NOT NULL,
    firm_id INT UNSIGNED DEFAULT NULL,
    note TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
    FOREIGN KEY (lawyer_id) REFERENCES lawyers(id) ON DELETE CASCADE,
    INDEX idx_case_notes_firm (firm_id),
    CONSTRAINT fk_case_notes_firm FOREIGN KEY (firm_id) REFERENCES law_firms(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS case_evidence (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id INT UNSIGNED NOT NULL,
    added_by_lawyer_id INT UNSIGNED NOT NULL,
    firm_id INT UNSIGNED DEFAULT NULL,
    related_claim_id INT UNSIGNED DEFAULT NULL,
    related_fact_id INT UNSIGNED DEFAULT NULL,
    related_law_article_id INT UNSIGNED DEFAULT NULL,
    title VARCHAR(255) NOT NULL,
    evidence_type ENUM('document','contract','correspondence','photo','other') NOT NULL DEFAULT 'document',
    description TEXT DEFAULT NULL,
    original_name VARCHAR(255) DEFAULT NULL,
    stored_name VARCHAR(100) DEFAULT NULL,
    ai_summary TEXT DEFAULT NULL,
    ai_findings TEXT DEFAULT NULL,
    ai_analyzed_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
    FOREIGN KEY (added_by_lawyer_id) REFERENCES lawyers(id) ON DELETE CASCADE,
    FOREIGN KEY (related_claim_id) REFERENCES case_claims(id) ON DELETE SET NULL,
    FOREIGN KEY (related_fact_id) REFERENCES case_facts(id) ON DELETE SET NULL,
    FOREIGN KEY (related_law_article_id) REFERENCES law_articles(id) ON DELETE SET NULL,
    INDEX idx_case_evidence_case (case_id, created_at),
    INDEX idx_case_evidence_firm (firm_id),
    CONSTRAINT fk_case_evidence_firm FOREIGN KEY (firm_id) REFERENCES law_firms(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS case_evidence_reports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id INT UNSIGNED NOT NULL,
    lawyer_id INT UNSIGNED NOT NULL,
    conflicts_json MEDIUMTEXT DEFAULT NULL,
    missing_evidence_json MEDIUMTEXT DEFAULT NULL,
    summary TEXT DEFAULT NULL,
    model VARCHAR(100) DEFAULT NULL,
    evidence_count_at_run INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
    FOREIGN KEY (lawyer_id) REFERENCES lawyers(id) ON DELETE CASCADE,
    INDEX idx_case_evidence_reports_case (case_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS case_analyses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id INT UNSIGNED NOT NULL,
    lawyer_id INT UNSIGNED NOT NULL,
    analysis LONGTEXT NOT NULL,
    structured_json MEDIUMTEXT DEFAULT NULL,
    model VARCHAR(100) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
    FOREIGN KEY (lawyer_id) REFERENCES lawyers(id) ON DELETE CASCADE,
    INDEX idx_case_analyses_case (case_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS case_risks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    likelihood TINYINT UNSIGNED NOT NULL,
    impact TINYINT UNSIGNED NOT NULL,
    mitigation TEXT DEFAULT NULL,
    status ENUM('open','mitigated') NOT NULL DEFAULT 'open',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
    INDEX idx_case_risks_case (case_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS case_time_entries (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id INT UNSIGNED NOT NULL,
    lawyer_id INT UNSIGNED NOT NULL,
    firm_id INT UNSIGNED DEFAULT NULL,
    work_date DATE NOT NULL,
    description VARCHAR(255) NOT NULL,
    minutes SMALLINT UNSIGNED NOT NULL,
    hourly_rate DECIMAL(12,3) NOT NULL DEFAULT 0,
    billable TINYINT(1) NOT NULL DEFAULT 1,
    invoice_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
    FOREIGN KEY (lawyer_id) REFERENCES lawyers(id) ON DELETE CASCADE,
    INDEX idx_case_time_case (case_id,work_date),
    INDEX idx_case_time_invoice (invoice_id),
    INDEX idx_case_time_entries_firm (firm_id),
    CONSTRAINT fk_case_time_entries_firm FOREIGN KEY (firm_id) REFERENCES law_firms(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lawyer_availability (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lawyer_id INT UNSIGNED NOT NULL,
    day_of_week TINYINT UNSIGNED NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    slot_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    FOREIGN KEY (lawyer_id) REFERENCES lawyers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recipient_type ENUM('client','lawyer','admin') NOT NULL,
    recipient_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    message VARCHAR(500) DEFAULT NULL,
    link VARCHAR(255) DEFAULT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_recipient (recipient_type, recipient_id, is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_type ENUM('admin','lawyer','client','system') NOT NULL DEFAULT 'admin',
    actor_id INT UNSIGNED DEFAULT NULL,
    actor_name VARCHAR(150) DEFAULT NULL,
    firm_id INT UNSIGNED DEFAULT NULL,
    action VARCHAR(255) NOT NULL,
    target_type VARCHAR(50) DEFAULT NULL,
    target_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_logs_firm (firm_id),
    CONSTRAINT fk_audit_logs_firm FOREIGN KEY (firm_id) REFERENCES law_firms(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS draft_documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lawyer_id INT UNSIGNED NOT NULL,
    firm_id INT UNSIGNED DEFAULT NULL,
    case_id INT UNSIGNED DEFAULT NULL,
    doc_type VARCHAR(100) NOT NULL,
    title VARCHAR(255) NOT NULL,
    party_a VARCHAR(255) DEFAULT NULL,
    party_b VARCHAR(255) DEFAULT NULL,
    details TEXT DEFAULT NULL,
    generated_content MEDIUMTEXT DEFAULT NULL,
    language ENUM('ar','en') NOT NULL DEFAULT 'ar',
    status ENUM('generated','failed') NOT NULL DEFAULT 'generated',
    approval_status ENUM('draft','submitted','approved','changes_requested') NOT NULL DEFAULT 'draft',
    approval_stage ENUM('draft','lawyer_review','partner_review','approved','rejected','changes_requested') NOT NULL DEFAULT 'draft',
    approval_firm_id INT UNSIGNED DEFAULT NULL,
    approval_version INT UNSIGNED NOT NULL DEFAULT 1,
    internal_review_started_at DATETIME DEFAULT NULL,
    client_visible TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_draft_approval_queue (approval_firm_id,approval_stage,status),
    INDEX idx_draft_documents_firm (firm_id),
    FOREIGN KEY (lawyer_id) REFERENCES lawyers(id) ON DELETE CASCADE,
    FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE SET NULL,
    CONSTRAINT fk_draft_documents_firm FOREIGN KEY (firm_id) REFERENCES law_firms(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS contract_comparisons (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    first_document_id INT UNSIGNED NOT NULL,
    second_document_id INT UNSIGNED NOT NULL,
    comparison_result MEDIUMTEXT DEFAULT NULL,
    comparison_status ENUM('processing','completed','failed') NOT NULL DEFAULT 'processing',
    model VARCHAR(100) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_contract_comparison_user (user_id,created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (first_document_id) REFERENCES documents(id) ON DELETE CASCADE,
    FOREIGN KEY (second_document_id) REFERENCES documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS draft_document_versions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    draft_document_id INT UNSIGNED NOT NULL,
    version_number INT UNSIGNED NOT NULL,
    content MEDIUMTEXT NOT NULL,
    saved_by_lawyer_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_draft_version (draft_document_id,version_number),
    FOREIGN KEY (draft_document_id) REFERENCES draft_documents(id) ON DELETE CASCADE,
    FOREIGN KEY (saved_by_lawyer_id) REFERENCES lawyers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS draft_reviews (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    draft_document_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    decision ENUM('approved','changes_requested') NOT NULL,
    notes TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (draft_document_id) REFERENCES draft_documents(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_draft_reviews_document (draft_document_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS api_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(64) NOT NULL UNIQUE,
    user_type ENUM('client','lawyer') NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    device_label VARCHAR(150) DEFAULT NULL,
    last_used_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME DEFAULT NULL,
    INDEX idx_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS auth_rate_limits (
    scope_key CHAR(64) NOT NULL PRIMARY KEY,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    window_started_at DATETIME NOT NULL,
    blocked_until DATETIME DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_auth_rate_blocked (blocked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS invoices (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lawyer_id INT UNSIGNED NOT NULL,
    firm_id INT UNSIGNED DEFAULT NULL,
    user_id INT UNSIGNED NOT NULL,
    case_id INT UNSIGNED DEFAULT NULL,
    description VARCHAR(255) NOT NULL,
    amount DECIMAL(12,3) NOT NULL,
    status ENUM('unpaid','paid','overdue','cancelled') NOT NULL DEFAULT 'unpaid',
    is_draft TINYINT(1) NOT NULL DEFAULT 0,
    due_date DATE DEFAULT NULL,
    paid_at DATETIME DEFAULT NULL,
    payment_method VARCHAR(50) DEFAULT NULL,
    payment_note VARCHAR(255) DEFAULT NULL,
    gateway VARCHAR(30) DEFAULT NULL,
    gateway_order_ref VARCHAR(100) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_invoices_firm (firm_id),
    FOREIGN KEY (lawyer_id) REFERENCES lawyers(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE SET NULL,
    CONSTRAINT fk_invoices_firm FOREIGN KEY (firm_id) REFERENCES law_firms(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @c := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='case_time_entries' AND CONSTRAINT_NAME='fk_case_time_invoice');
SET @s := IF(@c=0,'ALTER TABLE case_time_entries ADD CONSTRAINT fk_case_time_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL','SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS payment_transactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT UNSIGNED DEFAULT NULL,
    gateway VARCHAR(30) NOT NULL DEFAULT 'ngenius',
    gateway_order_ref VARCHAR(100) DEFAULT NULL,
    gateway_transaction_id VARCHAR(120) DEFAULT NULL,
    event_type VARCHAR(50) DEFAULT NULL,
    amount DECIMAL(12,3) DEFAULT NULL,
    currency VARCHAR(3) DEFAULT NULL,
    status VARCHAR(20) DEFAULT NULL,
    environment VARCHAR(20) DEFAULT NULL,
    response_code VARCHAR(40) DEFAULT NULL,
    response_message VARCHAR(255) DEFAULT NULL,
    paid_at DATETIME DEFAULT NULL,
    callback_received_at DATETIME DEFAULT NULL,
    verified_at DATETIME DEFAULT NULL,
    metadata TEXT DEFAULT NULL,
    raw_payload TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

CREATE TABLE IF NOT EXISTS site_settings (
    setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
    setting_value TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS draft_workflow_reviews (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    draft_document_id INT UNSIGNED NOT NULL,
    reviewer_lawyer_id INT UNSIGNED NOT NULL,
    stage ENUM('lawyer_review','partner_review') NOT NULL,
    decision ENUM('approved','changes_requested','rejected') NOT NULL,
    notes TEXT DEFAULT NULL,
    version_number INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_draft_workflow_history (draft_document_id,version_number,created_at),
    FOREIGN KEY (draft_document_id) REFERENCES draft_documents(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewer_lawyer_id) REFERENCES lawyers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Lawyer-managed court, appeal, contract, and regulatory calendar events (v31).
CREATE TABLE IF NOT EXISTS legal_calendar_events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lawyer_id INT UNSIGNED NOT NULL,
    firm_id INT UNSIGNED DEFAULT NULL,
    case_id INT UNSIGNED DEFAULT NULL,
    event_type ENUM('court_session','appeal_deadline','contract_expiry','contract_renewal','government_deadline','other') NOT NULL DEFAULT 'other',
    title VARCHAR(255) NOT NULL,
    description TEXT DEFAULT NULL,
    event_at DATETIME NOT NULL,
    status ENUM('upcoming','completed','cancelled') NOT NULL DEFAULT 'upcoming',
    reminder_sent_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_legal_calendar_lawyer_date (lawyer_id,event_at,status),
    INDEX idx_legal_calendar_case (case_id,event_at),
    INDEX idx_legal_calendar_firm (firm_id),
    CONSTRAINT fk_legal_calendar_lawyer FOREIGN KEY (lawyer_id) REFERENCES lawyers(id) ON DELETE CASCADE,
    CONSTRAINT fk_legal_calendar_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE SET NULL,
    CONSTRAINT fk_legal_calendar_firm FOREIGN KEY (firm_id) REFERENCES law_firms(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- بيانات أولية (Seed)
-- ============================================================

INSERT INTO site_settings (setting_key, setting_value) VALUES
('site_name_ar', 'مواثيق'),
('site_name_en', 'Mawatheq'),
('site_currency', 'SAR'),
('whatsapp_number', '966501453765'),
('contact_email', 'info@mawatheq.com'),
('default_lang', 'ar'),
('ai_provider', ''),
('ai_api_key', ''),
('ai_model', ''),
('ai_enabled', '1'),
('ngenius_enabled', '0'),
('ngenius_environment', 'sandbox'),
('ngenius_outlet_ref', ''),
('ngenius_api_key', ''),
('ngenius_realm_name', 'ni'),
('ngenius_currency', 'SAR'),
('ngenius_webhook_secret', ''),
('sadad_enabled', '0'),
('sadad_environment', 'sandbox'),
('sadad_currency', 'SAR');

INSERT INTO law_categories (name_ar, name_en, icon, sort_order) VALUES
('نظام العمل', 'Labor Law', 'work', 1),
('الأحوال الشخصية', 'Family Law', 'family', 2),
('النظام التجاري', 'Commercial Law', 'store', 3),
('النظام العقاري', 'Real Estate', 'home', 4),
('النظام الجنائي', 'Criminal Law', 'security', 5),
('الملكية الفكرية', 'IP Law', 'copyright', 6);

INSERT INTO plans (name_ar, name_en, description_ar, description_en, price_monthly, price_yearly, ai_queries_limit, document_analysis_limit, consultations_included, is_corporate, is_featured, sort_order) VALUES
('أساسية', 'Basic', 'للأفراد الذين يحتاجون إجابات قانونية سريعة', 'For individuals who need quick legal answers', 0, 0, 15, 2, 0, 0, 0, 1),
('احترافية', 'Professional', 'للأفراد والمهنيين المستقلين', 'For individuals and independent professionals', 149, 1490, NULL, 15, 1, 0, 1, 2),
('مؤسسية', 'Corporate', 'للشركات والمكاتب القانونية', 'For companies and law firms', 799, 7990, NULL, NULL, 5, 1, 0, 3);

INSERT INTO plan_features (plan_id, feature_ar, feature_en, sort_order) VALUES
(1, '15 سؤال شهرياً للمساعد الذكي', '15 AI assistant questions / month', 1),
(1, 'تحليل عقدين شهرياً', 'Analyze 2 contracts / month', 2),
(1, 'الوصول لمكتبة الأنظمة', 'Access to the laws library', 3),
(2, 'أسئلة غير محدودة للمساعد الذكي', 'Unlimited AI assistant questions', 1),
(2, 'تحليل حتى 15 مستند شهرياً', 'Analyze up to 15 documents / month', 2),
(2, 'استشارة واحدة مجانية شهرياً', '1 free consultation / month', 3),
(2, 'أولوية في الرد', 'Priority support', 4),
(3, 'كل مزايا الباقة الاحترافية', 'Everything in Professional', 1),
(3, 'مستخدمون متعددون ولوحة فريق', 'Multiple users & team dashboard', 2),
(3, 'بنك بنود وقوالب عقود مخصصة', 'Clause bank & custom contract templates', 3),
(3, 'مستشار قانوني مخصص', 'Dedicated legal advisor', 4),
(3, '5 استشارات مجانية شهرياً', '5 free consultations / month', 5);

INSERT INTO testimonials (name_ar, name_en, role_ar, role_en, quote_ar, quote_en, sort_order) VALUES
('محمد أ.', 'Mohammed A.', 'عميل', 'Client', 'ساعدني روبوت المحادثة على فهم حقوقي العمالية بشكل فوري.', 'The chatbot helped me understand my labor rights instantly.', 1),
('سارة ل.', 'Sarah L.', 'مديرة الموارد البشرية', 'HR Manager', 'أصبحت مراجعة عقود شركتنا أسرع بفضل الشرح الواضح لمخاطر كل بند.', 'Reviewing our company''s contracts became faster with clear explanation of each clause''s risks.', 2);

-- AI knowledge architecture (same objects as upgrade_v26.sql)
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


-- Security and citation persistence (same objects as upgrade_v27.sql)
-- Persist grounded-source citations, administrator TOTP 2FA, and token revocation state.
SET NAMES utf8mb4;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ai_messages' AND COLUMN_NAME='source_citations_json');
SET @s := IF(@c=0, 'ALTER TABLE ai_messages ADD COLUMN source_citations_json LONGTEXT DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='admins' AND COLUMN_NAME='totp_secret');
SET @s := IF(@c=0, 'ALTER TABLE admins ADD COLUMN totp_secret VARCHAR(64) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='admins' AND COLUMN_NAME='totp_enabled');
SET @s := IF(@c=0, 'ALTER TABLE admins ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='admins' AND COLUMN_NAME='totp_verified_at');
SET @s := IF(@c=0, 'ALTER TABLE admins ADD COLUMN totp_verified_at DATETIME DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='admins' AND COLUMN_NAME='totp_recovery_hashes');
SET @s := IF(@c=0, 'ALTER TABLE admins ADD COLUMN totp_recovery_hashes TEXT DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='api_tokens' AND COLUMN_NAME='revoked_at');
SET @s := IF(@c=0, 'ALTER TABLE api_tokens ADD COLUMN revoked_at DATETIME DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;



-- Security telemetry for grounded knowledge access.
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS knowledge_access_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_id BIGINT UNSIGNED NOT NULL,
    actor_type ENUM('client','lawyer') NOT NULL,
    actor_id INT UNSIGNED NOT NULL,
    module_id VARCHAR(80) NOT NULL,
    case_id INT UNSIGNED DEFAULT NULL,
    query_hash CHAR(64) NOT NULL,
    accessed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_knowledge_access_source (source_id,accessed_at),
    INDEX idx_knowledge_access_actor (actor_type,actor_id,accessed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



-- Semantic embedding index schema (upgrade_v29.sql)
-- v29: semantic-search embedding columns and embedding provider settings.
SET NAMES utf8mb4;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='knowledge_chunks' AND COLUMN_NAME='embedding');
SET @s := IF(@c=0,'ALTER TABLE knowledge_chunks ADD COLUMN embedding MEDIUMTEXT DEFAULT NULL AFTER chunk_text','SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='knowledge_chunks' AND COLUMN_NAME='embedding_model');
SET @s := IF(@c=0,'ALTER TABLE knowledge_chunks ADD COLUMN embedding_model VARCHAR(60) DEFAULT NULL AFTER embedding','SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='law_articles' AND COLUMN_NAME='embedding');
SET @s := IF(@c=0,'ALTER TABLE law_articles ADD COLUMN embedding MEDIUMTEXT DEFAULT NULL','SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='law_articles' AND COLUMN_NAME='embedding_model');
SET @s := IF(@c=0,'ALTER TABLE law_articles ADD COLUMN embedding_model VARCHAR(60) DEFAULT NULL','SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
INSERT IGNORE INTO site_settings(setting_key,setting_value) VALUES ('embedding_provider',''),('embedding_api_key',''),('embedding_model','text-embedding-3-small');
CREATE TABLE IF NOT EXISTS embedding_reindex_log (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, run_by INT UNSIGNED DEFAULT NULL,
 scope ENUM('knowledge','law_articles') NOT NULL, processed INT UNSIGNED NOT NULL DEFAULT 0,
 failed INT UNSIGNED NOT NULL DEFAULT 0, started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 finished_at DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- AI answer verification/review schema (upgrade_v30.sql)
-- v30: source verification and AI answer review audit trail.
SET NAMES utf8mb4;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='law_articles' AND COLUMN_NAME='verification_status');
SET @s := IF(@c=0,"ALTER TABLE law_articles ADD COLUMN verification_status ENUM('unverified','verified') NOT NULL DEFAULT 'verified' AFTER embedding_model",'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
CREATE TABLE IF NOT EXISTS ai_answer_reviews (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 actor_type ENUM('client','lawyer','admin','anonymous') NOT NULL DEFAULT 'anonymous',
 actor_id INT UNSIGNED DEFAULT NULL, module_id VARCHAR(60) NOT NULL DEFAULT 'legal_core',
 question_hash CHAR(64) NOT NULL, question_excerpt VARCHAR(500) DEFAULT NULL,
 retrieval_method ENUM('none','keyword','semantic','hybrid') NOT NULL DEFAULT 'none',
 sources_json TEXT DEFAULT NULL,
 review_status ENUM('reviewed','source_only','unverified','no_source') NOT NULL,
 hallucination_risk ENUM('low','medium','high','n_a') NOT NULL DEFAULT 'n_a',
 unsupported_claims TEXT DEFAULT NULL, citation_integrity_ok TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_answer_review_actor(actor_type,actor_id,created_at),
 INDEX idx_answer_review_status(review_status,hallucination_risk)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- v40 CRM
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

-- v41 AI workflows
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

-- ============================================================
-- Phase 3 (v43) — Finance + AI + Integrations
-- Fresh installs get the same objects that upgrade_v43.sql adds to an
-- existing database. See database/upgrade_v43.sql for full comments.
-- ============================================================

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='invoice_number');
SET @s := IF(@c=0, 'ALTER TABLE invoices ADD COLUMN invoice_number VARCHAR(40) DEFAULT NULL AFTER id, ADD UNIQUE KEY uniq_invoices_number (invoice_number)', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='currency');
SET @s := IF(@c=0, 'ALTER TABLE invoices ADD COLUMN currency VARCHAR(3) NOT NULL DEFAULT ''SAR'' AFTER amount', 'SELECT 1');
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
SET @s := (SELECT IF(LOCATE('partially_paid', COLUMN_TYPE)=0,
    'ALTER TABLE invoices MODIFY COLUMN status ENUM(''unpaid'',''partially_paid'',''paid'',''overdue'',''cancelled'') NOT NULL DEFAULT ''unpaid''',
    'SELECT 1')
    FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='status');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND INDEX_NAME='idx_invoices_status_due');
SET @s := IF(@c=0, 'ALTER TABLE invoices ADD INDEX idx_invoices_status_due (status,due_date)', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

CREATE TABLE IF NOT EXISTS invoice_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, invoice_id INT UNSIGNED NOT NULL, firm_id INT UNSIGNED DEFAULT NULL,
    description VARCHAR(255) NOT NULL, quantity DECIMAL(12,3) NOT NULL DEFAULT 1, unit_price DECIMAL(12,3) NOT NULL DEFAULT 0,
    discount_amount DECIMAL(12,3) NOT NULL DEFAULT 0, tax_rate DECIMAL(6,3) NOT NULL DEFAULT 0, line_total DECIMAL(12,3) NOT NULL DEFAULT 0,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_invoice_items_invoice (invoice_id,sort_order), INDEX idx_invoice_items_firm (firm_id),
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    CONSTRAINT fk_invoice_items_firm FOREIGN KEY (firm_id) REFERENCES law_firms(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS invoice_payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, invoice_id INT UNSIGNED NOT NULL, firm_id INT UNSIGNED DEFAULT NULL,
    lawyer_id INT UNSIGNED DEFAULT NULL, amount DECIMAL(12,3) NOT NULL, currency VARCHAR(3) NOT NULL DEFAULT 'SAR',
    method ENUM('manual','cash','bank_transfer','card','ngenius','sadad','other') NOT NULL DEFAULT 'manual',
    status ENUM('pending','completed','failed','refunded') NOT NULL DEFAULT 'completed', reference VARCHAR(120) DEFAULT NULL,
    idempotency_key VARCHAR(80) DEFAULT NULL, notes VARCHAR(500) DEFAULT NULL, paid_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    recorded_by_type ENUM('lawyer','admin','client','system') NOT NULL DEFAULT 'system', recorded_by_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uniq_invoice_payments_idem (idempotency_key),
    INDEX idx_invoice_payments_invoice (invoice_id,paid_at), INDEX idx_invoice_payments_firm (firm_id,paid_at),
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    CONSTRAINT fk_invoice_payments_firm FOREIGN KEY (firm_id) REFERENCES law_firms(id) ON DELETE SET NULL,
    CONSTRAINT fk_invoice_payments_lawyer FOREIGN KEY (lawyer_id) REFERENCES lawyers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_transactions' AND COLUMN_NAME='firm_id');
SET @s := IF(@c=0, 'ALTER TABLE payment_transactions ADD COLUMN firm_id INT UNSIGNED DEFAULT NULL AFTER invoice_id, ADD INDEX idx_payment_tx_firm (firm_id)', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_transactions' AND COLUMN_NAME='event_hash');
SET @s := IF(@c=0, 'ALTER TABLE payment_transactions ADD COLUMN event_hash CHAR(64) DEFAULT NULL AFTER event_type, ADD UNIQUE KEY uniq_payment_tx_hash (event_hash)', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

CREATE TABLE IF NOT EXISTS ai_tenant_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_type ENUM('firm','lawyer','platform') NOT NULL, tenant_id INT UNSIGNED NOT NULL,
    privacy_mode ENUM('local_only','external_disabled','external_allowed') NOT NULL DEFAULT 'external_disabled',
    allowed_data_categories VARCHAR(500) NOT NULL DEFAULT 'user_prompt', external_features VARCHAR(500) NOT NULL DEFAULT '',
    consent_actor_type ENUM('lawyer','admin') DEFAULT NULL, consent_actor_id INT UNSIGNED DEFAULT NULL, consent_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_ai_tenant (tenant_type,tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ai_external_transmissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_type ENUM('firm','lawyer','platform') NOT NULL DEFAULT 'platform',
    tenant_id INT UNSIGNED DEFAULT NULL, actor_type ENUM('client','lawyer','admin','system') NOT NULL DEFAULT 'system',
    actor_id INT UNSIGNED DEFAULT NULL, firm_id INT UNSIGNED DEFAULT NULL, feature VARCHAR(60) NOT NULL, module_id VARCHAR(80) DEFAULT NULL,
    provider VARCHAR(40) DEFAULT NULL, model VARCHAR(150) DEFAULT NULL, data_category VARCHAR(60) NOT NULL DEFAULT 'user_prompt',
    payload_bytes INT UNSIGNED DEFAULT NULL, external_sent TINYINT(1) NOT NULL DEFAULT 0, consent_mode VARCHAR(30) DEFAULT NULL,
    outcome ENUM('allowed','denied','failed') NOT NULL DEFAULT 'allowed', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ai_tx_tenant (tenant_type,tenant_id,created_at), INDEX idx_ai_tx_feature (feature,created_at), INDEX idx_ai_tx_actor (actor_type,actor_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ai_usage_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_type ENUM('firm','lawyer','platform','client') NOT NULL DEFAULT 'platform',
    tenant_id INT UNSIGNED DEFAULT NULL, firm_id INT UNSIGNED DEFAULT NULL, actor_type ENUM('client','lawyer','admin','system') NOT NULL DEFAULT 'system',
    actor_id INT UNSIGNED DEFAULT NULL, provider VARCHAR(40) DEFAULT NULL, model VARCHAR(150) DEFAULT NULL, feature VARCHAR(60) NOT NULL,
    module_id VARCHAR(80) DEFAULT NULL, success TINYINT(1) NOT NULL DEFAULT 1, input_tokens INT UNSIGNED DEFAULT NULL,
    output_tokens INT UNSIGNED DEFAULT NULL, estimated_cost DECIMAL(12,6) DEFAULT NULL, external_sent TINYINT(1) NOT NULL DEFAULT 0,
    error_message VARCHAR(500) DEFAULT NULL, duration_ms INT UNSIGNED DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ai_usage_tenant (tenant_type,tenant_id,created_at), INDEX idx_ai_usage_feature (feature,created_at), INDEX idx_ai_usage_actor (actor_type,actor_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ai_user_agents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, owner_type ENUM('client','lawyer') NOT NULL, owner_id INT UNSIGNED NOT NULL,
    tenant_type ENUM('firm','lawyer','solo') NOT NULL DEFAULT 'solo', firm_id INT UNSIGNED DEFAULT NULL, name VARCHAR(150) NOT NULL,
    description VARCHAR(500) DEFAULT NULL, persona_prompt TEXT DEFAULT NULL, allowed_tools VARCHAR(1000) NOT NULL DEFAULT '',
    allowed_data_sources VARCHAR(500) NOT NULL DEFAULT '', status ENUM('active','disabled') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ai_user_agents_owner (owner_type,owner_id), INDEX idx_ai_user_agents_firm (firm_id),
    CONSTRAINT fk_ai_user_agents_firm FOREIGN KEY (firm_id) REFERENCES law_firms(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ai_agent_activity_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, agent_id INT UNSIGNED DEFAULT NULL, owner_type ENUM('client','lawyer') DEFAULT NULL,
    owner_id INT UNSIGNED DEFAULT NULL, tenant_type ENUM('firm','lawyer','solo') DEFAULT NULL, firm_id INT UNSIGNED DEFAULT NULL,
    session_id VARCHAR(64) DEFAULT NULL, tool_name VARCHAR(80) NOT NULL, requires_confirmation TINYINT(1) NOT NULL DEFAULT 0,
    confirmation_state ENUM('not_required','pending','confirmed','rejected') NOT NULL DEFAULT 'not_required',
    outcome ENUM('success','denied','failed') NOT NULL DEFAULT 'success', detail VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ai_agent_activity_agent (agent_id,created_at), INDEX idx_ai_agent_activity_owner (owner_type,owner_id,created_at),
    INDEX idx_ai_agent_activity_firm (firm_id,created_at),
    FOREIGN KEY (agent_id) REFERENCES ai_user_agents(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Pre-existing tenants keep the AI behaviour they already had (seeded allowed);
-- tenants created after v43 default to restrictive (external_disabled) on first read.
INSERT IGNORE INTO ai_tenant_settings (tenant_type,tenant_id,privacy_mode,allowed_data_categories,external_features)
SELECT 'firm', f.id, 'external_allowed', 'user_prompt,legal_text,case_information,client_information,firm_knowledge,document_text', '' FROM law_firms f;

INSERT IGNORE INTO ai_tenant_settings (tenant_type,tenant_id,privacy_mode,allowed_data_categories,external_features)
SELECT 'lawyer', l.id, 'external_allowed', 'user_prompt,legal_text,case_information,client_information,firm_knowledge,document_text', ''
FROM lawyers l
WHERE NOT EXISTS (SELECT 1 FROM law_firm_members m WHERE m.lawyer_id=l.id AND m.status='active');

INSERT IGNORE INTO ai_tenant_settings (tenant_type,tenant_id,privacy_mode,allowed_data_categories,external_features)
VALUES ('platform', 0, 'external_allowed', 'user_prompt,legal_text,case_information,client_information,firm_knowledge,document_text', '');

-- ------------------------------------------------------------
-- Cross-module links declared after every referenced table exists.
-- `documents` is created before `cases`, so its case link is added here.
-- Guarded so re-running the schema on an existing database is harmless.
-- ------------------------------------------------------------
SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documents' AND CONSTRAINT_NAME = 'fk_documents_case');
SET @s := IF(@fk = 0, 'ALTER TABLE documents ADD CONSTRAINT fk_documents_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ------------------------------------------------------------
-- v50 — law firm public website + office structure
-- Mirrors database/upgrade_v50.sql so a fresh install is complete. Additive and
-- guarded (IF NOT EXISTS) so re-running the schema on an existing database is harmless.
-- ------------------------------------------------------------
ALTER TABLE law_firms ADD COLUMN IF NOT EXISTS public_slug VARCHAR(190) DEFAULT NULL;
ALTER TABLE law_firms ADD COLUMN IF NOT EXISTS is_public TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE law_firms ADD COLUMN IF NOT EXISTS tagline_ar VARCHAR(300) DEFAULT NULL;
ALTER TABLE law_firms ADD COLUMN IF NOT EXISTS tagline_en VARCHAR(300) DEFAULT NULL;
ALTER TABLE law_firms ADD COLUMN IF NOT EXISTS about_ar MEDIUMTEXT DEFAULT NULL;
ALTER TABLE law_firms ADD COLUMN IF NOT EXISTS about_en MEDIUMTEXT DEFAULT NULL;
ALTER TABLE law_firms ADD COLUMN IF NOT EXISTS practice_areas VARCHAR(1000) DEFAULT NULL;
ALTER TABLE law_firms ADD COLUMN IF NOT EXISTS working_hours VARCHAR(500) DEFAULT NULL;
ALTER TABLE law_firms ADD COLUMN IF NOT EXISTS website_url VARCHAR(255) DEFAULT NULL;
ALTER TABLE law_firms ADD COLUMN IF NOT EXISTS social_json VARCHAR(1000) DEFAULT NULL;
ALTER TABLE law_firms ADD COLUMN IF NOT EXISTS meta_title_ar VARCHAR(200) DEFAULT NULL;
ALTER TABLE law_firms ADD COLUMN IF NOT EXISTS meta_title_en VARCHAR(200) DEFAULT NULL;
ALTER TABLE law_firms ADD COLUMN IF NOT EXISTS meta_description_ar VARCHAR(400) DEFAULT NULL;
ALTER TABLE law_firms ADD COLUMN IF NOT EXISTS meta_description_en VARCHAR(400) DEFAULT NULL;
ALTER TABLE law_firms ADD UNIQUE KEY IF NOT EXISTS uq_firm_public_slug (public_slug);
ALTER TABLE articles ADD COLUMN IF NOT EXISTS firm_id INT UNSIGNED DEFAULT NULL;
ALTER TABLE articles ADD COLUMN IF NOT EXISTS author_lawyer_id INT UNSIGNED DEFAULT NULL;
ALTER TABLE articles ADD INDEX IF NOT EXISTS idx_articles_firm (firm_id, status);

CREATE TABLE IF NOT EXISTS firm_pages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    firm_id INT UNSIGNED NOT NULL,
    slug VARCHAR(190) NOT NULL,
    page_type ENUM('about','services','practice_areas','privacy','custom') NOT NULL DEFAULT 'custom',
    title_ar VARCHAR(255) NOT NULL,
    title_en VARCHAR(255) DEFAULT NULL,
    content_ar MEDIUMTEXT,
    content_en MEDIUMTEXT,
    status ENUM('draft','published') NOT NULL DEFAULT 'draft',
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_firm_page_slug (firm_id, slug),
    INDEX idx_firm_page_pub (firm_id, status),
    FOREIGN KEY (firm_id) REFERENCES law_firms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS firm_branches (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    firm_id INT UNSIGNED NOT NULL,
    name_ar VARCHAR(180) NOT NULL,
    name_en VARCHAR(180) DEFAULT NULL,
    city VARCHAR(100) DEFAULT NULL,
    address VARCHAR(255) DEFAULT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    working_hours VARCHAR(500) DEFAULT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_firm_branch_firm (firm_id, status),
    FOREIGN KEY (firm_id) REFERENCES law_firms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS firm_departments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    firm_id INT UNSIGNED NOT NULL,
    name_ar VARCHAR(180) NOT NULL,
    name_en VARCHAR(180) DEFAULT NULL,
    description VARCHAR(500) DEFAULT NULL,
    manager_lawyer_id INT UNSIGNED DEFAULT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_firm_dept_firm (firm_id, status),
    FOREIGN KEY (firm_id) REFERENCES law_firms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS firm_announcements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    firm_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    body MEDIUMTEXT,
    audience ENUM('all','lawyers','staff') NOT NULL DEFAULT 'all',
    status ENUM('draft','published') NOT NULL DEFAULT 'published',
    created_by_lawyer_id INT UNSIGNED DEFAULT NULL,
    published_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_firm_ann_firm (firm_id, status),
    FOREIGN KEY (firm_id) REFERENCES law_firms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================
-- Folded from upgrade_v51.sql (Executive 08) — additive, idempotent
-- ============================================================

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

-- ============================================================
-- Folded from upgrade_v47.sql — additive, idempotent
-- ============================================================

-- ============================================================
-- Mawatheq upgrade v47 — link client documents to a case
-- Non-destructive. Safe to re-run.
-- ============================================================

-- The client document library (`documents`) and the lawyer evidence register
-- (`case_evidence`) were disconnected: a document a client uploaded could not be
-- associated with the case it belongs to. `case_id` closes that gap so the same
-- document surfaces inside the case file. It is nullable — a general library
-- document that belongs to no particular case stays valid.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documents' AND COLUMN_NAME = 'case_id');
SET @s := IF(@c = 0, 'ALTER TABLE documents ADD COLUMN case_id INT UNSIGNED DEFAULT NULL AFTER user_id', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documents' AND INDEX_NAME = 'idx_documents_case');
SET @s := IF(@idx = 0, 'CREATE INDEX idx_documents_case ON documents (case_id)', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documents' AND CONSTRAINT_NAME = 'fk_documents_case');
SET @s := IF(@fk = 0, 'ALTER TABLE documents ADD CONSTRAINT fk_documents_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ============================================================
-- Folded from upgrade_v48.sql — additive, idempotent
-- ============================================================

-- ============================================================
-- Mawatheq upgrade v48 — legal-aware chunk provenance
-- Non-destructive. Safe to re-run.
-- ============================================================

-- Legal-aware chunking records where a chunk came from so an answer can cite
-- the article/clause and page, not just the document title. Every column is
-- nullable: when the source has no detectable structure the value stays NULL
-- rather than being guessed, so a citation never points at an invented article.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'knowledge_chunks' AND COLUMN_NAME = 'heading_path');
SET @s := IF(@c = 0, 'ALTER TABLE knowledge_chunks ADD COLUMN heading_path VARCHAR(500) DEFAULT NULL AFTER chunk_text', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'knowledge_chunks' AND COLUMN_NAME = 'section_label');
SET @s := IF(@c = 0, 'ALTER TABLE knowledge_chunks ADD COLUMN section_label VARCHAR(255) DEFAULT NULL AFTER heading_path', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'knowledge_chunks' AND COLUMN_NAME = 'article_label');
SET @s := IF(@c = 0, 'ALTER TABLE knowledge_chunks ADD COLUMN article_label VARCHAR(255) DEFAULT NULL AFTER section_label', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'knowledge_chunks' AND COLUMN_NAME = 'clause_label');
SET @s := IF(@c = 0, 'ALTER TABLE knowledge_chunks ADD COLUMN clause_label VARCHAR(255) DEFAULT NULL AFTER article_label', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'knowledge_chunks' AND COLUMN_NAME = 'page_no');
SET @s := IF(@c = 0, 'ALTER TABLE knowledge_chunks ADD COLUMN page_no INT UNSIGNED DEFAULT NULL AFTER clause_label', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'knowledge_chunks' AND COLUMN_NAME = 'chunk_kind');
SET @s := IF(@c = 0, 'ALTER TABLE knowledge_chunks ADD COLUMN chunk_kind VARCHAR(20) DEFAULT NULL AFTER page_no', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'knowledge_chunks' AND INDEX_NAME = 'idx_knowledge_chunk_article');
SET @s := IF(@idx = 0, 'CREATE INDEX idx_knowledge_chunk_article ON knowledge_chunks (article_label)', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ============================================================
-- Folded from upgrade_v49.sql — additive, idempotent
-- ============================================================

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


-- ============================================================
-- Folded from upgrade_v52.sql (Executive 09) — additive, idempotent
-- Mirrors database/upgrade_v52.sql so a fresh install is complete. Every
-- statement is CREATE TABLE IF NOT EXISTS, so re-running the schema against
-- an existing database is harmless.
-- ============================================================

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
