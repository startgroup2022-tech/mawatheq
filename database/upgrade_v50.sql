-- ============================================================
-- Mawatheq upgrade v50 — law firm public website + office structure
-- Non-destructive, additive only. Safe to re-run.
-- ============================================================
--
-- A law firm on Mawatheq needs two things it could not express before:
--   * a public-facing identity (homepage copy, practice areas, contact, working hours,
--     SEO metadata, published pages) that the firm itself controls from its dashboard, and
--   * an internal office structure (branches, departments, announcements) so an office
--     manager can organise the staff the firm already has in law_firm_members.
--
-- Every table below is firm-scoped (firm_id). Public reads must filter on both
-- is_public/status AND firm_id; the firm dashboard writes must resolve firm_id from the
-- session (includes/tenant.php), never from a request parameter — that is what keeps
-- one firm's public site from exposing another firm's rows.

-- ------------------------------------------------------------
-- Firm website identity (columns on the existing tenant row)
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

-- ------------------------------------------------------------
-- Firm-controlled pages (about / services / practice areas / privacy / custom)
-- ------------------------------------------------------------
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

-- ------------------------------------------------------------
-- Branches / offices
-- ------------------------------------------------------------
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

-- ------------------------------------------------------------
-- Departments
-- ------------------------------------------------------------
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

-- ------------------------------------------------------------
-- Internal announcements (never public)
-- ------------------------------------------------------------
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

-- ------------------------------------------------------------
-- Firm-owned publications: scope articles to a firm when they belong to one.
-- Platform-wide editorial articles keep firm_id NULL.
-- ------------------------------------------------------------
ALTER TABLE articles ADD COLUMN IF NOT EXISTS firm_id INT UNSIGNED DEFAULT NULL;
ALTER TABLE articles ADD COLUMN IF NOT EXISTS author_lawyer_id INT UNSIGNED DEFAULT NULL;
ALTER TABLE articles ADD INDEX IF NOT EXISTS idx_articles_firm (firm_id, status);
