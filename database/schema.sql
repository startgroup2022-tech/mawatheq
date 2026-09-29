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
    owner_lawyer_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_lawyer_id) REFERENCES lawyers(id) ON DELETE CASCADE,
    UNIQUE KEY uq_law_firm_owner (owner_lawyer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS law_firm_members (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    firm_id INT UNSIGNED NOT NULL,
    lawyer_id INT UNSIGNED NOT NULL,
    member_role ENUM('owner','partner','lawyer','staff') NOT NULL DEFAULT 'lawyer',
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
    original_name VARCHAR(255) NOT NULL,
    stored_path VARCHAR(255) NOT NULL,
    file_type VARCHAR(50) DEFAULT NULL,
    document_type ENUM('general','contract') NOT NULL DEFAULT 'general',
    status ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
    summary TEXT DEFAULT NULL,
    contract_analysis_json MEDIUMTEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
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
    consultation_type ENUM('text','voice','video') NOT NULL DEFAULT 'text',
    scheduled_at DATETIME NOT NULL,
    duration_minutes SMALLINT DEFAULT 30,
    topic VARCHAR(255) DEFAULT NULL,
    status ENUM('pending','confirmed','completed','cancelled') NOT NULL DEFAULT 'pending',
    price DECIMAL(12,3) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    room_code VARCHAR(64) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (lawyer_id) REFERENCES lawyers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- القضايا (إدارة القضايا للمحامين)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cases (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lawyer_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    case_type VARCHAR(100) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    win_probability TINYINT UNSIGNED DEFAULT NULL, -- 0-100
    status ENUM('open','in_review','closed_won','closed_lost','closed_settled') NOT NULL DEFAULT 'open',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (lawyer_id) REFERENCES lawyers(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
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
    note TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
    FOREIGN KEY (lawyer_id) REFERENCES lawyers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS case_evidence (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id INT UNSIGNED NOT NULL,
    added_by_lawyer_id INT UNSIGNED NOT NULL,
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
    INDEX idx_case_evidence_case (case_id, created_at)
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
    INDEX idx_case_time_invoice (invoice_id)
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
    actor_type ENUM('admin','lawyer','system') NOT NULL DEFAULT 'admin',
    actor_id INT UNSIGNED DEFAULT NULL,
    actor_name VARCHAR(150) DEFAULT NULL,
    action VARCHAR(255) NOT NULL,
    target_type VARCHAR(50) DEFAULT NULL,
    target_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS draft_documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lawyer_id INT UNSIGNED NOT NULL,
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
    FOREIGN KEY (lawyer_id) REFERENCES lawyers(id) ON DELETE CASCADE,
    FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE SET NULL
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
    FOREIGN KEY (lawyer_id) REFERENCES lawyers(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @c := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='case_time_entries' AND CONSTRAINT_NAME='fk_case_time_invoice');
SET @s := IF(@c=0,'ALTER TABLE case_time_entries ADD CONSTRAINT fk_case_time_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL','SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS payment_transactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT UNSIGNED DEFAULT NULL,
    gateway VARCHAR(30) NOT NULL DEFAULT 'ngenius',
    gateway_order_ref VARCHAR(100) DEFAULT NULL,
    event_type VARCHAR(50) DEFAULT NULL,
    amount DECIMAL(12,3) DEFAULT NULL,
    raw_payload TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
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
    CONSTRAINT fk_legal_calendar_lawyer FOREIGN KEY (lawyer_id) REFERENCES lawyers(id) ON DELETE CASCADE,
    CONSTRAINT fk_legal_calendar_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- بيانات أولية (Seed)
-- ============================================================

INSERT INTO site_settings (setting_key, setting_value) VALUES
('site_name_ar', 'مواثيق'),
('site_name_en', 'Mawatheq'),
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
('ngenius_webhook_secret', '');

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
