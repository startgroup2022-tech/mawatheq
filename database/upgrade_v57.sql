-- ============================================================================
-- Execution 31 — Affiliate, Referral & Partner Marketing System
-- upgrade_v57.sql
-- ============================================================================
--
-- Additive only. Every statement is either CREATE TABLE IF NOT EXISTS, a guarded
-- ALTER, or a guarded INSERT that runs only when the row is absent. Running this
-- file twice is a no-op; running it against a database that already has some of
-- these tables completes the rest without touching what is there.
--
-- WHY THERE IS NO POSITIONAL `AFTER` CLAUSE ANYWHERE IN THIS FILE
--   upgrade_v56.sql (Execution 30) shipped with `ADD COLUMN ... AFTER actor_name`,
--   and production's audit_logs had no actor_name column. MySQL aborts the file at
--   the first error, so every statement below that one silently never ran and the
--   migration looked applied while the fault it existed to fix survived. Positional
--   placement is cosmetic; a missing neighbour turns it into a hard stop. Columns
--   are appended instead.
--
-- COMPLIANCE POSTURE — read before changing any seeded value
--   The platform serves legal services. Sharing a lawyer's professional fee with a
--   third party who referred the client is regulated in Bahrain, and this migration
--   does NOT enable it. Every service is seeded with an eligibility mode that
--   either permits commission on non-regulated platform revenue only, or records
--   the service as attribution/analytics-only, or is disabled pending review.
--   `commission_permitted` on a service whose revenue is a lawyer's fee is set to 0
--   and must stay 0 until qualified Bahraini counsel and the compliance
--   administrator approve it in writing. The application layer enforces this as
--   well (includes/affiliate_service.php) — the seed is the first line, not the only one.
--
-- Money representation
--   Every monetary column is DECIMAL(12,3) like the rest of the platform (see
--   invoices.amount). The affiliate library converts to integer minor units
--   internally and never uses floating point for ledger arithmetic.
-- ============================================================================


-- ---------------------------------------------------------------------------
-- 1. Extend the admin role enum with the four affiliate governance roles
-- ---------------------------------------------------------------------------
-- The platform's RBAC is a role enum on `admins` plus a per-route allow-list in
-- includes/admin_rbac.php. Adding roles to the enum is additive; existing rows keep
-- their value. MySQL will not reorder an enum, so the new members are appended.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='admins' AND COLUMN_NAME='role'
             AND COLUMN_TYPE NOT LIKE '%affiliate_manager%');
SET @s := IF(@c=1,
  "ALTER TABLE admins MODIFY COLUMN role ENUM('super_admin','editor','support','affiliate_manager','finance_manager','compliance_reviewer') NOT NULL DEFAULT 'editor'",
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;


-- ---------------------------------------------------------------------------
-- 2. Extend api_tokens to carry an affiliate token
-- ---------------------------------------------------------------------------
-- An affiliate is not a `users` row and not a `lawyers` row, so the existing
-- user_type enum cannot express one. Appending 'affiliate' keeps the token table
-- single-source: one place issues, hashes, revokes and expires tokens.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='api_tokens' AND COLUMN_NAME='user_type'
             AND COLUMN_TYPE NOT LIKE '%affiliate%');
SET @s := IF(@c=1,
  "ALTER TABLE api_tokens MODIFY COLUMN user_type ENUM('client','lawyer','affiliate') NOT NULL",
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;


-- ---------------------------------------------------------------------------
-- 2b. Classify an invoice's revenue for the affiliate programme
-- ---------------------------------------------------------------------------
-- The payment hook has to decide what kind of revenue a payment is before it can
-- ask whether commission is lawful on it. There is no existing column that carries
-- that, and guessing from the invoice contents would be exactly the kind of
-- inference that gets a compliance gate wrong.
--
-- NULL — the default for every existing and new invoice — means "a lawyer's
-- professional fee", which is the regulated case and the safe assumption. The
-- application maps NULL to `legal_invoice_fee`, whose attribution mode is
-- `prohibited`. An administrator therefore has to say so explicitly, per invoice,
-- to have it treated as anything else, and the change is audited.
--
-- Appended, never positioned: a positional AFTER naming a column that might be
-- absent is what broke upgrade_v56.sql in production (see the note at the top).

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='affiliate_service_key');
SET @s := IF(@c=0,
  "ALTER TABLE invoices ADD COLUMN affiliate_service_key VARCHAR(60) DEFAULT NULL",
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND INDEX_NAME='idx_invoices_affiliate_service');
SET @s := IF(@c=0,
  "ALTER TABLE invoices ADD INDEX idx_invoices_affiliate_service (affiliate_service_key)",
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;


-- ---------------------------------------------------------------------------
-- 3. affiliates — the central identity both onboarding channels converge on
-- ---------------------------------------------------------------------------
-- One row per affiliate regardless of whether they arrived through the public form
-- or an admin invitation. `status` is the gate that decides whether they may
-- generate commission-bearing links at all: an applicant is 'pending' and cannot.

CREATE TABLE IF NOT EXISTS affiliates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(32) NOT NULL,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) DEFAULT NULL,
    full_name VARCHAR(180) NOT NULL,
    affiliate_type ENUM('individual','company','partner') NOT NULL DEFAULT 'individual',
    status ENUM('pending','under_review','approved','active','suspended','rejected','closed') NOT NULL DEFAULT 'pending',
    -- Compliance review is a separate axis from lifecycle: an affiliate can be
    -- active for payouts while a specific agreement version is awaiting review.
    compliance_status ENUM('not_reviewed','in_review','approved','rejected','needs_action') NOT NULL DEFAULT 'not_reviewed',
    company_name VARCHAR(190) DEFAULT NULL,
    commercial_registration VARCHAR(60) DEFAULT NULL,
    tax_number VARCHAR(60) DEFAULT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    country CHAR(2) NOT NULL DEFAULT 'BH',
    city VARCHAR(80) DEFAULT NULL,
    website VARCHAR(255) DEFAULT NULL,
    social_links VARCHAR(1000) DEFAULT NULL,
    marketing_channels VARCHAR(1000) DEFAULT NULL,
    promotion_methods VARCHAR(1000) DEFAULT NULL,
    payout_method ENUM('bank_transfer','cheque','none') NOT NULL DEFAULT 'none',
    payout_currency CHAR(3) NOT NULL DEFAULT 'BHD',
    email_verified_at DATETIME DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    approved_by INT UNSIGNED DEFAULT NULL,
    suspended_at DATETIME DEFAULT NULL,
    suspension_reason VARCHAR(500) DEFAULT NULL,
    rejection_reason VARCHAR(500) DEFAULT NULL,
    -- Set when the affiliate is itself a referred customer, so self-referral is
    -- decidable by identity rather than by heuristic alone.
    self_user_id INT UNSIGNED DEFAULT NULL,
    self_lawyer_id INT UNSIGNED DEFAULT NULL,
    parent_affiliate_id INT UNSIGNED DEFAULT NULL,
    commission_plan_id INT UNSIGNED DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    is_demo TINYINT(1) NOT NULL DEFAULT 0,
    last_login_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_affiliates_code (code),
    UNIQUE KEY uq_affiliates_email (email),
    INDEX idx_affiliates_status (status),
    INDEX idx_affiliates_type (affiliate_type),
    INDEX idx_affiliates_plan (commission_plan_id),
    INDEX idx_affiliates_demo (is_demo),
    CONSTRAINT fk_affiliates_parent FOREIGN KEY (parent_affiliate_id) REFERENCES affiliates(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- 4. affiliate_applications — the public onboarding channel
-- ---------------------------------------------------------------------------
-- Kept separate from `affiliates` so the full submitted payload and the review
-- trail survive even when the application is rejected and no affiliate row is
-- created. An approved application creates (or links) the affiliate.

CREATE TABLE IF NOT EXISTS affiliate_applications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    affiliate_id INT UNSIGNED DEFAULT NULL,
    reference CHAR(16) NOT NULL,
    full_name VARCHAR(180) NOT NULL,
    email VARCHAR(190) NOT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    country CHAR(2) NOT NULL DEFAULT 'BH',
    city VARCHAR(80) DEFAULT NULL,
    applicant_type ENUM('individual','company','partner') NOT NULL DEFAULT 'individual',
    company_name VARCHAR(190) DEFAULT NULL,
    commercial_registration VARCHAR(60) DEFAULT NULL,
    website VARCHAR(255) DEFAULT NULL,
    social_links VARCHAR(1000) DEFAULT NULL,
    marketing_channels VARCHAR(1000) DEFAULT NULL,
    promotion_methods VARCHAR(1000) DEFAULT NULL,
    audience_description TEXT DEFAULT NULL,
    expected_monthly_reach INT UNSIGNED DEFAULT NULL,
    payout_method ENUM('bank_transfer','cheque','none') NOT NULL DEFAULT 'none',
    payout_details VARCHAR(500) DEFAULT NULL,
    -- Explicit consent is a compliance requirement, not a formality: the terms
    -- version accepted is stored so a later dispute can be resolved against the
    -- exact text the applicant agreed to.
    accepted_terms TINYINT(1) NOT NULL DEFAULT 0,
    accepted_privacy TINYINT(1) NOT NULL DEFAULT 0,
    terms_version VARCHAR(20) DEFAULT NULL,
    privacy_version VARCHAR(20) DEFAULT NULL,
    accepted_ip VARCHAR(45) DEFAULT NULL,
    accepted_at DATETIME DEFAULT NULL,
    status ENUM('submitted','verification','compliance_review','approved','rejected','withdrawn') NOT NULL DEFAULT 'submitted',
    verification_notes VARCHAR(500) DEFAULT NULL,
    compliance_notes VARCHAR(500) DEFAULT NULL,
    reviewed_by INT UNSIGNED DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    rejection_reason VARCHAR(500) DEFAULT NULL,
    is_demo TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_affiliate_applications_reference (reference),
    INDEX idx_affiliate_applications_status (status),
    INDEX idx_affiliate_applications_email (email),
    INDEX idx_affiliate_applications_affiliate (affiliate_id),
    INDEX idx_affiliate_applications_demo (is_demo),
    CONSTRAINT fk_affiliate_applications_affiliate FOREIGN KEY (affiliate_id) REFERENCES affiliates(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- 5. affiliate_invitations — the private onboarding channel
-- ---------------------------------------------------------------------------
-- The token is stored as a SHA-256 hash, never in plaintext: a leaked database
-- dump must not hand an attacker a usable invitation. Single-use is enforced by
-- `accepted_at IS NULL` plus the unique token hash.

CREATE TABLE IF NOT EXISTS affiliate_invitations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token_hash CHAR(64) NOT NULL,
    email VARCHAR(190) DEFAULT NULL,
    full_name VARCHAR(180) DEFAULT NULL,
    invite_type ENUM('individual','company','partner') NOT NULL DEFAULT 'individual',
    invited_by INT UNSIGNED NOT NULL,
    commission_plan_id INT UNSIGNED DEFAULT NULL,
    -- An invitation may skip the public review queue, but never the compliance
    -- gate: preapproved only waives the *application* review, not the terms or
    -- service-eligibility review, both of which still run at activation.
    preapproved TINYINT(1) NOT NULL DEFAULT 0,
    affiliate_id INT UNSIGNED DEFAULT NULL,
    personal_message VARCHAR(1000) DEFAULT NULL,
    expires_at DATETIME NOT NULL,
    accepted_at DATETIME DEFAULT NULL,
    revoked_at DATETIME DEFAULT NULL,
    revoked_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_affiliate_invitations_token (token_hash),
    INDEX idx_affiliate_invitations_email (email),
    INDEX idx_affiliate_invitations_open (expires_at, accepted_at, revoked_at),
    CONSTRAINT fk_affiliate_invitations_affiliate FOREIGN KEY (affiliate_id) REFERENCES affiliates(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- 6. affiliate_agreements — versioned terms
-- ---------------------------------------------------------------------------
-- Terms change. An affiliate's commissions must be governed by the version they
-- actually accepted, so acceptance is a row here, not a boolean on the affiliate.

CREATE TABLE IF NOT EXISTS affiliate_agreements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    affiliate_id INT UNSIGNED NOT NULL,
    agreement_type ENUM('terms','privacy','commission_schedule','marketing_policy') NOT NULL DEFAULT 'terms',
    version VARCHAR(20) NOT NULL,
    document_hash CHAR(64) DEFAULT NULL,
    accepted_at DATETIME DEFAULT NULL,
    accepted_ip VARCHAR(45) DEFAULT NULL,
    accepted_user_agent VARCHAR(255) DEFAULT NULL,
    superseded_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_affiliate_agreements_affiliate (affiliate_id, agreement_type),
    CONSTRAINT fk_affiliate_agreements_affiliate FOREIGN KEY (affiliate_id) REFERENCES affiliates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- 7. affiliate_commission_plans — versioned compensation rules
-- ---------------------------------------------------------------------------
-- Versioned rather than edited in place: a plan change must not silently reprice
-- commissions already earned under the previous version. A new version is a new
-- row; `effective_to` closes the old one.

CREATE TABLE IF NOT EXISTS affiliate_commission_plans (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    slug VARCHAR(60) NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    plan_type ENUM('percentage','fixed','tiered','hybrid') NOT NULL DEFAULT 'percentage',
    description VARCHAR(500) DEFAULT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'BHD',
    -- The revenue base the percentage applies to. `eligible_net` is the only
    -- value that is safe by default: it strips tax, refunds and non-commissionable
    -- components before any percentage is taken.
    revenue_base ENUM('eligible_net','gross','subtotal') NOT NULL DEFAULT 'eligible_net',
    percentage DECIMAL(6,3) DEFAULT NULL,
    fixed_amount DECIMAL(12,3) DEFAULT NULL,
    recurring_months SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    first_payment_only TINYINT(1) NOT NULL DEFAULT 1,
    min_commission DECIMAL(12,3) DEFAULT NULL,
    max_commission DECIMAL(12,3) DEFAULT NULL,
    -- A hold period is the defence against paying commission on a payment that is
    -- later refunded or charged back.
    hold_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    min_conversion_amount DECIMAL(12,3) DEFAULT NULL,
    effective_from DATE NOT NULL,
    effective_to DATE DEFAULT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    -- Set when a plan is reserved for one partner's negotiated terms.
    partner_affiliate_id INT UNSIGNED DEFAULT NULL,
    exclusions VARCHAR(1000) DEFAULT NULL,
    tax_treatment ENUM('inclusive','exclusive','not_applicable') NOT NULL DEFAULT 'not_applicable',
    notes TEXT DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_affiliate_plan_slug_version (slug, version),
    INDEX idx_affiliate_plans_active (is_active, effective_from),
    INDEX idx_affiliate_plans_partner (partner_affiliate_id),
    CONSTRAINT fk_affiliate_plans_partner FOREIGN KEY (partner_affiliate_id) REFERENCES affiliates(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- 8. affiliate_commission_rules — per-service / per-tier detail
-- ---------------------------------------------------------------------------
-- One plan can treat services differently. A tiered plan stores its bands here.

CREATE TABLE IF NOT EXISTS affiliate_commission_rules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    plan_id INT UNSIGNED NOT NULL,
    service_key VARCHAR(60) DEFAULT NULL,
    rule_type ENUM('percentage','fixed','tier') NOT NULL DEFAULT 'percentage',
    tier_min DECIMAL(12,3) DEFAULT NULL,
    tier_max DECIMAL(12,3) DEFAULT NULL,
    tier_percentage DECIMAL(6,3) DEFAULT NULL,
    amount DECIMAL(12,3) DEFAULT NULL,
    max_per_conversion DECIMAL(12,3) DEFAULT NULL,
    sort_order SMALLINT DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_affiliate_rules_plan (plan_id),
    INDEX idx_affiliate_rules_service (service_key),
    CONSTRAINT fk_affiliate_rules_plan FOREIGN KEY (plan_id) REFERENCES affiliate_commission_plans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- 9. affiliate_service_catalog — which revenue may carry a commission
-- ---------------------------------------------------------------------------
-- This is the compliance gate expressed as data. `revenue_source` names the
-- platform table the eligible amount comes from, so eligibility is decided by
-- what the money actually is, not by what someone typed into a form.
--
-- revenue_source values:
--   'subscription'  — a plan subscription payment (platform revenue)
--   'consultation'  — a booked consultation fee
--   'invoice'       — a lawyer's invoice (PROFESSIONAL FEE — regulated)
--   'service'       — a platform administrative service fee
--
-- A row with commission_permitted=0 and attribution_mode='attribution_only' still
-- records the referral and its conversions; it simply cannot produce money.

CREATE TABLE IF NOT EXISTS affiliate_service_catalog (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    service_key VARCHAR(60) NOT NULL,
    label_ar VARCHAR(150) NOT NULL,
    label_en VARCHAR(150) NOT NULL,
    revenue_source ENUM('subscription','consultation','invoice','service') NOT NULL,
    -- How the platform's own fee for this service is located, when the revenue is
    -- not a whole invoice (e.g. a platform fee line inside an invoice).
    source_reference VARCHAR(60) DEFAULT NULL,
    attribution_mode ENUM('commission','attribution_only','analytics_only','disabled','prohibited') NOT NULL DEFAULT 'disabled',
    commission_permitted TINYINT(1) NOT NULL DEFAULT 0,
    -- True when the revenue is a regulated legal professional fee. Hard-blocks
    -- commission regardless of any other setting; the library refuses to override it.
    is_regulated_fee TINYINT(1) NOT NULL DEFAULT 0,
    requires_compliance_approval TINYINT(1) NOT NULL DEFAULT 1,
    compliance_approved TINYINT(1) NOT NULL DEFAULT 0,
    compliance_approved_by INT UNSIGNED DEFAULT NULL,
    compliance_approved_at DATETIME DEFAULT NULL,
    compliance_note VARCHAR(1000) DEFAULT NULL,
    max_commission_percent DECIMAL(6,3) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order SMALLINT DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_affiliate_service_key (service_key),
    INDEX idx_affiliate_service_mode (attribution_mode),
    INDEX idx_affiliate_service_source (revenue_source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- 10. affiliate_campaigns
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS affiliate_campaigns (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    slug VARCHAR(80) NOT NULL,
    description VARCHAR(1000) DEFAULT NULL,
    campaign_type ENUM('general','service','seasonal','partner') NOT NULL DEFAULT 'general',
    landing_path VARCHAR(255) NOT NULL DEFAULT '/',
    -- Optional campaign-level plan override. NULL means the affiliate's own plan applies.
    commission_plan_id INT UNSIGNED DEFAULT NULL,
    allowed_service_keys VARCHAR(1000) DEFAULT NULL,
    starts_at DATETIME DEFAULT NULL,
    ends_at DATETIME DEFAULT NULL,
    status ENUM('draft','pending_compliance','active','paused','ended','rejected') NOT NULL DEFAULT 'draft',
    compliance_approved TINYINT(1) NOT NULL DEFAULT 0,
    compliance_approved_by INT UNSIGNED DEFAULT NULL,
    compliance_approved_at DATETIME DEFAULT NULL,
    compliance_note VARCHAR(1000) DEFAULT NULL,
    max_affiliates INT UNSIGNED DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    is_demo TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_affiliate_campaigns_slug (slug),
    INDEX idx_affiliate_campaigns_status (status),
    CONSTRAINT fk_affiliate_campaigns_plan FOREIGN KEY (commission_plan_id) REFERENCES affiliate_commission_plans(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- 11. affiliate_links — the referral URLs an affiliate may hand out
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS affiliate_links (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    affiliate_id INT UNSIGNED NOT NULL,
    campaign_id INT UNSIGNED DEFAULT NULL,
    label VARCHAR(150) DEFAULT NULL,
    code VARCHAR(40) NOT NULL,
    destination_path VARCHAR(255) NOT NULL DEFAULT '/',
    utm_source VARCHAR(100) DEFAULT NULL,
    utm_medium VARCHAR(100) DEFAULT NULL,
    utm_campaign VARCHAR(150) DEFAULT NULL,
    utm_content VARCHAR(150) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    click_count INT UNSIGNED NOT NULL DEFAULT 0,
    unique_click_count INT UNSIGNED NOT NULL DEFAULT 0,
    conversion_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_affiliate_links_code (code),
    INDEX idx_affiliate_links_affiliate (affiliate_id),
    INDEX idx_affiliate_links_campaign (campaign_id),
    CONSTRAINT fk_affiliate_links_affiliate FOREIGN KEY (affiliate_id) REFERENCES affiliates(id) ON DELETE CASCADE,
    CONSTRAINT fk_affiliate_links_campaign FOREIGN KEY (campaign_id) REFERENCES affiliate_campaigns(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- 12. affiliate_clicks — server-side attribution record
-- ---------------------------------------------------------------------------
-- Written when a referral link is followed. The visitor id is a random opaque
-- token held in a first-party cookie; no fingerprinting, no device graph. It is
-- pseudonymous by construction and carries no personal data of its own.

CREATE TABLE IF NOT EXISTS affiliate_clicks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    affiliate_id INT UNSIGNED NOT NULL,
    link_id INT UNSIGNED DEFAULT NULL,
    campaign_id INT UNSIGNED DEFAULT NULL,
    visitor_token CHAR(32) NOT NULL,
    landing_path VARCHAR(255) DEFAULT NULL,
    referrer_host VARCHAR(190) DEFAULT NULL,
    utm_source VARCHAR(100) DEFAULT NULL,
    utm_medium VARCHAR(100) DEFAULT NULL,
    utm_campaign VARCHAR(150) DEFAULT NULL,
    utm_content VARCHAR(150) DEFAULT NULL,
    utm_term VARCHAR(150) DEFAULT NULL,
    ip_hash CHAR(64) DEFAULT NULL,
    user_agent_hash CHAR(64) DEFAULT NULL,
    -- Consent state at the moment of capture. When consent is absent the click is
    -- still recorded for aggregate traffic counting (no personal data), but the
    -- row is marked so no attribution is created from it.
    consent_state ENUM('granted','denied','unknown') NOT NULL DEFAULT 'unknown',
    is_bot TINYINT(1) NOT NULL DEFAULT 0,
    is_suspect TINYINT(1) NOT NULL DEFAULT 0,
    suspect_reason VARCHAR(255) DEFAULT NULL,
    is_demo TINYINT(1) NOT NULL DEFAULT 0,
    clicked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_affiliate_clicks_affiliate (affiliate_id, clicked_at),
    INDEX idx_affiliate_clicks_visitor (visitor_token),
    INDEX idx_affiliate_clicks_campaign (campaign_id, clicked_at),
    INDEX idx_affiliate_clicks_link (link_id),
    CONSTRAINT fk_affiliate_clicks_affiliate FOREIGN KEY (affiliate_id) REFERENCES affiliates(id) ON DELETE CASCADE,
    CONSTRAINT fk_affiliate_clicks_link FOREIGN KEY (link_id) REFERENCES affiliate_links(id) ON DELETE SET NULL,
    CONSTRAINT fk_affiliate_clicks_campaign FOREIGN KEY (campaign_id) REFERENCES affiliate_campaigns(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- 13. affiliate_attributions — the locked owner of a conversion
-- ---------------------------------------------------------------------------
-- The visitor_token is UNIQUE: one visitor, one attribution. That uniqueness is
-- what makes "first touch" enforceable and what stops a later request parameter
-- from reassigning the owner — the INSERT either wins or is ignored, and the
-- winner is never overwritten while the window is open.

CREATE TABLE IF NOT EXISTS affiliate_attributions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    visitor_token CHAR(32) NOT NULL,
    affiliate_id INT UNSIGNED NOT NULL,
    link_id INT UNSIGNED DEFAULT NULL,
    campaign_id INT UNSIGNED DEFAULT NULL,
    first_click_id BIGINT UNSIGNED DEFAULT NULL,
    last_click_id BIGINT UNSIGNED DEFAULT NULL,
    touch_count INT UNSIGNED NOT NULL DEFAULT 1,
    model ENUM('first_touch','last_touch') NOT NULL DEFAULT 'first_touch',
    first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    -- Set when the visitor registers or books. NULL until then.
    user_id INT UNSIGNED DEFAULT NULL,
    lawyer_id INT UNSIGNED DEFAULT NULL,
    converted_at DATETIME DEFAULT NULL,
    -- Set once the window closes or the owner is frozen by a conversion, so a
    -- later ?ref= cannot move it.
    locked_at DATETIME DEFAULT NULL,
    lock_reason VARCHAR(60) DEFAULT NULL,
    UNIQUE KEY uq_affiliate_attributions_visitor (visitor_token),
    INDEX idx_affiliate_attributions_affiliate (affiliate_id, first_seen_at),
    INDEX idx_affiliate_attributions_expiry (expires_at),
    INDEX idx_affiliate_attributions_user (user_id),
    CONSTRAINT fk_affiliate_attributions_affiliate FOREIGN KEY (affiliate_id) REFERENCES affiliates(id) ON DELETE CASCADE,
    CONSTRAINT fk_affiliate_attributions_campaign FOREIGN KEY (campaign_id) REFERENCES affiliate_campaigns(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- 14. affiliate_conversions — a referred customer's value event
-- ---------------------------------------------------------------------------
-- Created only from a verified payment (see includes/affiliate_payment.php, which
-- hooks finance_record_payment). Never from a browser event or an unverified
-- callback. `event_key` is UNIQUE and is the idempotency guard: the same payment
-- can be delivered many times and still produce exactly one conversion.
--
-- No client identity is stored here. `subject_ref` is a pseudonymous reference
-- (a hash of the internal id) so the affiliate dashboard can distinguish
-- conversions without ever learning who the client is.

CREATE TABLE IF NOT EXISTS affiliate_conversions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    affiliate_id INT UNSIGNED NOT NULL,
    attribution_id BIGINT UNSIGNED DEFAULT NULL,
    campaign_id INT UNSIGNED DEFAULT NULL,
    link_id INT UNSIGNED DEFAULT NULL,
    service_key VARCHAR(60) NOT NULL,
    -- Pseudonymous reference to the paying party. Never a name, email or id.
    subject_ref CHAR(32) NOT NULL,
    subject_type ENUM('client','lawyer','firm') NOT NULL DEFAULT 'client',
    event_type ENUM('registration','booking','payment','subscription') NOT NULL DEFAULT 'payment',
    -- Idempotency: '<source>:<id>' e.g. 'invoice_payment:4821'.
    event_key VARCHAR(80) NOT NULL,
    source_type VARCHAR(40) NOT NULL,
    source_id INT UNSIGNED DEFAULT NULL,
    invoice_id INT UNSIGNED DEFAULT NULL,
    payment_id INT UNSIGNED DEFAULT NULL,
    subscription_id INT UNSIGNED DEFAULT NULL,
    -- Money, in the currency of the underlying payment.
    currency CHAR(3) NOT NULL DEFAULT 'BHD',
    gross_amount DECIMAL(12,3) NOT NULL DEFAULT 0,
    tax_amount DECIMAL(12,3) NOT NULL DEFAULT 0,
    discount_amount DECIMAL(12,3) NOT NULL DEFAULT 0,
    refunded_amount DECIMAL(12,3) NOT NULL DEFAULT 0,
    -- The base the commission is computed from, after every exclusion.
    eligible_amount DECIMAL(12,3) NOT NULL DEFAULT 0,
    eligible_reason VARCHAR(255) DEFAULT NULL,
    is_eligible TINYINT(1) NOT NULL DEFAULT 0,
    ineligible_reason VARCHAR(255) DEFAULT NULL,
    status ENUM('tracked','qualified','pending_payment','paid_eligible','holding','approved','payable','paid_out','rejected','cancelled','refunded','reversed','disputed','suspended') NOT NULL DEFAULT 'tracked',
    converted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    qualified_at DATETIME DEFAULT NULL,
    is_demo TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_affiliate_conversions_event (event_key),
    INDEX idx_affiliate_conversions_affiliate (affiliate_id, converted_at),
    INDEX idx_affiliate_conversions_status (status),
    INDEX idx_affiliate_conversions_service (service_key),
    INDEX idx_affiliate_conversions_invoice (invoice_id),
    INDEX idx_affiliate_conversions_payment (payment_id),
    INDEX idx_affiliate_conversions_campaign (campaign_id),
    INDEX idx_affiliate_conversions_demo (is_demo),
    CONSTRAINT fk_affiliate_conversions_affiliate FOREIGN KEY (affiliate_id) REFERENCES affiliates(id) ON DELETE CASCADE,
    CONSTRAINT fk_affiliate_conversions_attribution FOREIGN KEY (attribution_id) REFERENCES affiliate_attributions(id) ON DELETE SET NULL,
    CONSTRAINT fk_affiliate_conversions_campaign FOREIGN KEY (campaign_id) REFERENCES affiliate_campaigns(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- 15. affiliate_commissions — one commission per conversion
-- ---------------------------------------------------------------------------
-- `conversion_id` is UNIQUE so a conversion can never produce two commissions,
-- which is the structural guarantee against double-crediting.

CREATE TABLE IF NOT EXISTS affiliate_commissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    affiliate_id INT UNSIGNED NOT NULL,
    conversion_id BIGINT UNSIGNED NOT NULL,
    plan_id INT UNSIGNED DEFAULT NULL,
    plan_version INT UNSIGNED DEFAULT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'BHD',
    base_amount DECIMAL(12,3) NOT NULL DEFAULT 0,
    commission_amount DECIMAL(12,3) NOT NULL DEFAULT 0,
    -- Amount already clawed back by refunds/reversals. Kept so the payable is
    -- always commission_amount - reversed_amount, never a mutated total.
    reversed_amount DECIMAL(12,3) NOT NULL DEFAULT 0,
    status ENUM('tracked','qualified','pending_payment','paid_eligible','holding','approved','payable','paid_out','rejected','cancelled','refunded','reversed','disputed','suspended') NOT NULL DEFAULT 'tracked',
    calculation_note VARCHAR(500) DEFAULT NULL,
    hold_until DATE DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    approved_by INT UNSIGNED DEFAULT NULL,
    payable_at DATETIME DEFAULT NULL,
    paid_out_at DATETIME DEFAULT NULL,
    payout_id INT UNSIGNED DEFAULT NULL,
    is_demo TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_affiliate_commissions_conversion (conversion_id),
    INDEX idx_affiliate_commissions_affiliate (affiliate_id, status),
    INDEX idx_affiliate_commissions_status (status),
    INDEX idx_affiliate_commissions_hold (hold_until),
    INDEX idx_affiliate_commissions_payout (payout_id),
    CONSTRAINT fk_affiliate_commissions_affiliate FOREIGN KEY (affiliate_id) REFERENCES affiliates(id) ON DELETE CASCADE,
    CONSTRAINT fk_affiliate_commissions_conversion FOREIGN KEY (conversion_id) REFERENCES affiliate_conversions(id) ON DELETE CASCADE,
    CONSTRAINT fk_affiliate_commissions_plan FOREIGN KEY (plan_id) REFERENCES affiliate_commission_plans(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- 16. affiliate_ledger_entries — immutable, balanced, append-only
-- ---------------------------------------------------------------------------
-- Every movement of commission value writes a row here. Nothing is ever updated
-- or deleted; a correction is a new opposing entry. `entry_group` ties the two
-- sides of a movement together so the ledger can be proven balanced by summing
-- per group. `direction` is a sign, so SUM(amount * sign) is the balance.
--
-- This is deliberately NOT a general ledger with a chart of accounts — the
-- platform has no accounting module (verified: no chart_of_accounts, no
-- journal_entries anywhere in the schema). This is a commission sub-ledger, and
-- includes/affiliate_ledger.php documents that limitation explicitly rather than
-- pretending entries were posted to an accounting system that does not exist.

CREATE TABLE IF NOT EXISTS affiliate_ledger_entries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    affiliate_id INT UNSIGNED NOT NULL,
    entry_group CHAR(32) NOT NULL,
    -- The account this leg affects. Commission sub-ledger accounts only.
    account ENUM('commission_earned','commission_payable','commission_paid','commission_reversed','payout_clearing','adjustment') NOT NULL,
    direction ENUM('debit','credit') NOT NULL,
    amount DECIMAL(12,3) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'BHD',
    commission_id BIGINT UNSIGNED DEFAULT NULL,
    conversion_id BIGINT UNSIGNED DEFAULT NULL,
    payout_id INT UNSIGNED DEFAULT NULL,
    description VARCHAR(500) DEFAULT NULL,
    actor_type ENUM('admin','affiliate','system') NOT NULL DEFAULT 'system',
    actor_id INT UNSIGNED DEFAULT NULL,
    -- Idempotency for a retried posting.
    idempotency_key VARCHAR(80) DEFAULT NULL,
    is_demo TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_affiliate_ledger_idem (idempotency_key),
    INDEX idx_affiliate_ledger_affiliate (affiliate_id, created_at),
    INDEX idx_affiliate_ledger_group (entry_group),
    INDEX idx_affiliate_ledger_account (account),
    INDEX idx_affiliate_ledger_commission (commission_id),
    CONSTRAINT fk_affiliate_ledger_affiliate FOREIGN KEY (affiliate_id) REFERENCES affiliates(id) ON DELETE CASCADE,
    CONSTRAINT fk_affiliate_ledger_commission FOREIGN KEY (commission_id) REFERENCES affiliate_commissions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- 17. affiliate_payout_requests
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS affiliate_payout_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    affiliate_id INT UNSIGNED NOT NULL,
    reference VARCHAR(40) NOT NULL,
    amount DECIMAL(12,3) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'BHD',
    status ENUM('requested','under_review','approved','processing','paid','rejected','failed','cancelled') NOT NULL DEFAULT 'requested',
    method ENUM('bank_transfer','cheque') NOT NULL DEFAULT 'bank_transfer',
    -- Encrypted at rest through secret_encrypt_value() when MAWATHEQ_ENCRYPTION_KEY
    -- is configured; masked in every interface.
    payout_details_enc TEXT DEFAULT NULL,
    payout_details_mask VARCHAR(120) DEFAULT NULL,
    affiliate_note VARCHAR(500) DEFAULT NULL,
    review_note VARCHAR(500) DEFAULT NULL,
    -- Dual approval: a payout at or above the configured threshold needs two
    -- distinct administrators, recorded separately.
    reviewed_by INT UNSIGNED DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    approved_by INT UNSIGNED DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    second_approved_by INT UNSIGNED DEFAULT NULL,
    second_approved_at DATETIME DEFAULT NULL,
    processed_by INT UNSIGNED DEFAULT NULL,
    processed_at DATETIME DEFAULT NULL,
    paid_by INT UNSIGNED DEFAULT NULL,
    paid_at DATETIME DEFAULT NULL,
    -- Manual bank payouts must carry evidence before the request may be closed.
    evidence_reference VARCHAR(120) DEFAULT NULL,
    evidence_note VARCHAR(500) DEFAULT NULL,
    failure_reason VARCHAR(500) DEFAULT NULL,
    is_demo TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_affiliate_payout_reference (reference),
    INDEX idx_affiliate_payouts_affiliate (affiliate_id, status),
    INDEX idx_affiliate_payouts_status (status),
    CONSTRAINT fk_affiliate_payouts_affiliate FOREIGN KEY (affiliate_id) REFERENCES affiliates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- 18. affiliate_payouts — the settled payment record
-- ---------------------------------------------------------------------------
-- Separated from the request so a request can be retried after a failed transfer
-- without losing the history of the attempt.

CREATE TABLE IF NOT EXISTS affiliate_payouts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payout_request_id INT UNSIGNED DEFAULT NULL,
    affiliate_id INT UNSIGNED NOT NULL,
    reference VARCHAR(40) NOT NULL,
    amount DECIMAL(12,3) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'BHD',
    method ENUM('bank_transfer','cheque') NOT NULL DEFAULT 'bank_transfer',
    status ENUM('processing','paid','failed','cancelled') NOT NULL DEFAULT 'processing',
    paid_at DATETIME DEFAULT NULL,
    paid_by INT UNSIGNED DEFAULT NULL,
    bank_reference VARCHAR(120) DEFAULT NULL,
    evidence_reference VARCHAR(120) DEFAULT NULL,
    note VARCHAR(500) DEFAULT NULL,
    is_demo TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_affiliate_payouts_reference (reference),
    INDEX idx_affiliate_payouts_record_affiliate (affiliate_id),
    CONSTRAINT fk_affiliate_payouts_record_affiliate FOREIGN KEY (affiliate_id) REFERENCES affiliates(id) ON DELETE CASCADE,
    CONSTRAINT fk_affiliate_payouts_request FOREIGN KEY (payout_request_id) REFERENCES affiliate_payout_requests(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- 19. affiliate_compliance_reviews
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS affiliate_compliance_reviews (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subject_type ENUM('affiliate','application','campaign','service','material','agreement','commission_model') NOT NULL,
    subject_id INT UNSIGNED NOT NULL,
    affiliate_id INT UNSIGNED DEFAULT NULL,
    review_type ENUM('onboarding','advertising','service_eligibility','commission_model','material','periodic') NOT NULL DEFAULT 'onboarding',
    status ENUM('pending','in_review','approved','rejected','needs_action') NOT NULL DEFAULT 'pending',
    risk_level ENUM('low','medium','high','prohibited') NOT NULL DEFAULT 'medium',
    findings TEXT DEFAULT NULL,
    -- The exact thing reviewed (e.g. an ad copy hash) so approval cannot be
    -- reused for different content.
    content_hash CHAR(64) DEFAULT NULL,
    required_disclosures VARCHAR(1000) DEFAULT NULL,
    conditions VARCHAR(1000) DEFAULT NULL,
    reviewer_id INT UNSIGNED DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    next_review_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_affiliate_compliance_subject (subject_type, subject_id),
    INDEX idx_affiliate_compliance_status (status),
    INDEX idx_affiliate_compliance_affiliate (affiliate_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- 20. affiliate_fraud_flags — signals, never verdicts
-- ---------------------------------------------------------------------------
-- A flag records an observation for a human to judge. Nothing in the application
-- suspends or bans an affiliate automatically on a flag alone; the check constraint
-- is a policy decision, documented in AGENTS.md and enforced in
-- includes/affiliate_fraud.php.

CREATE TABLE IF NOT EXISTS affiliate_fraud_flags (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    affiliate_id INT UNSIGNED DEFAULT NULL,
    conversion_id BIGINT UNSIGNED DEFAULT NULL,
    click_id BIGINT UNSIGNED DEFAULT NULL,
    payout_request_id INT UNSIGNED DEFAULT NULL,
    flag_type ENUM('self_referral','duplicate_account','repeated_payment','coupon_abuse','fake_conversion','param_manipulation','cookie_stuffing','automated_traffic','unauthorized_change','payout_account_change','conversion_spike','other') NOT NULL,
    severity ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    status ENUM('open','reviewing','cleared','confirmed','dismissed') NOT NULL DEFAULT 'open',
    signal_data VARCHAR(1000) DEFAULT NULL,
    description VARCHAR(500) DEFAULT NULL,
    reviewed_by INT UNSIGNED DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    resolution_note VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_affiliate_fraud_affiliate (affiliate_id, status),
    INDEX idx_affiliate_fraud_status (status, severity),
    INDEX idx_affiliate_fraud_conversion (conversion_id),
    CONSTRAINT fk_affiliate_fraud_affiliate FOREIGN KEY (affiliate_id) REFERENCES affiliates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- 21. affiliate_audit_logs — the affiliate module's own trail
-- ---------------------------------------------------------------------------
-- audit_logs is shared and keyed to an admin/lawyer/client actor. Affiliate events
-- need an affiliate actor and a before/after snapshot of a commission or status
-- change, so they get their own table rather than being forced into a shape that
-- cannot express them.

CREATE TABLE IF NOT EXISTS affiliate_audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_type ENUM('admin','affiliate','system') NOT NULL DEFAULT 'system',
    actor_id INT UNSIGNED DEFAULT NULL,
    actor_name VARCHAR(150) DEFAULT NULL,
    affiliate_id INT UNSIGNED DEFAULT NULL,
    action VARCHAR(100) NOT NULL,
    subject_type VARCHAR(40) DEFAULT NULL,
    subject_id INT UNSIGNED DEFAULT NULL,
    from_state VARCHAR(40) DEFAULT NULL,
    to_state VARCHAR(40) DEFAULT NULL,
    detail VARCHAR(1000) DEFAULT NULL,
    ip_hash CHAR(64) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_affiliate_audit_affiliate (affiliate_id, created_at),
    INDEX idx_affiliate_audit_subject (subject_type, subject_id),
    INDEX idx_affiliate_audit_action (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- 22. affiliate_notification_log — what was sent, so nothing is sent twice
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS affiliate_notification_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    affiliate_id INT UNSIGNED NOT NULL,
    event_key VARCHAR(80) NOT NULL,
    notification_type VARCHAR(60) NOT NULL,
    title VARCHAR(255) NOT NULL,
    body VARCHAR(1000) DEFAULT NULL,
    link VARCHAR(255) DEFAULT NULL,
    channel ENUM('in_app','email','sms') NOT NULL DEFAULT 'in_app',
    status ENUM('queued','sent','failed','skipped') NOT NULL DEFAULT 'sent',
    read_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_affiliate_notification_event (event_key, channel),
    INDEX idx_affiliate_notification_affiliate (affiliate_id, created_at),
    INDEX idx_affiliate_notification_unread (affiliate_id, read_at),
    CONSTRAINT fk_affiliate_notification_affiliate FOREIGN KEY (affiliate_id) REFERENCES affiliates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------------
-- 23. affiliate_daily_stats — pre-aggregated counters for the dashboards
-- ---------------------------------------------------------------------------
-- The dashboards would otherwise run several GROUP BY scans per page view over
-- tables that grow without bound (clicks especially). One row per affiliate per
-- day, maintained incrementally.

CREATE TABLE IF NOT EXISTS affiliate_daily_stats (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    affiliate_id INT UNSIGNED NOT NULL,
    stat_date DATE NOT NULL,
    clicks INT UNSIGNED NOT NULL DEFAULT 0,
    unique_visitors INT UNSIGNED NOT NULL DEFAULT 0,
    registrations INT UNSIGNED NOT NULL DEFAULT 0,
    conversions INT UNSIGNED NOT NULL DEFAULT 0,
    qualified_conversions INT UNSIGNED NOT NULL DEFAULT 0,
    paid_conversions INT UNSIGNED NOT NULL DEFAULT 0,
    eligible_revenue DECIMAL(14,3) NOT NULL DEFAULT 0,
    commission_earned DECIMAL(14,3) NOT NULL DEFAULT 0,
    commission_reversed DECIMAL(14,3) NOT NULL DEFAULT 0,
    currency CHAR(3) NOT NULL DEFAULT 'BHD',
    is_demo TINYINT(1) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_affiliate_daily_stats (affiliate_id, stat_date),
    INDEX idx_affiliate_daily_stats_date (stat_date),
    CONSTRAINT fk_affiliate_daily_stats_affiliate FOREIGN KEY (affiliate_id) REFERENCES affiliates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================================
-- Seed: the compliance gate. Every row starts closed.
-- ============================================================================
-- These inserts are the first line of defence and they are deliberately
-- conservative. Read the note at the top of this file before changing any value.
--
--   * A subscription is platform revenue the platform may lawfully share.
--     It still starts disabled, because "may lawfully share" is a decision for
--     compliance to record, not for a migration to assume.
--   * A lawyer's invoice is a regulated professional fee. commission_permitted
--     stays 0 and is_regulated_fee is 1. The library refuses commission on a
--     regulated fee even if the flag were flipped, so this cannot be enabled by
--     editing one row by accident.
--   * A consultation fee is likewise a professional fee and is treated the same.
--   * Services that only make sense as analytics are set to analytics_only so
--     they contribute to reports without ever producing money.

INSERT INTO affiliate_service_catalog (service_key, label_ar, label_en, revenue_source, attribution_mode, commission_permitted, is_regulated_fee, requires_compliance_approval, compliance_note, sort_order)
SELECT 'subscription_plan', 'اشتراك باقة', 'Plan subscription', 'subscription', 'disabled', 0, 0, 1, 'إيراد منصة. التعويض على التسويق مسموح به نظاماً، لكن التفعيل ينتظر موافقة مسؤول الالتزام.', 1
WHERE NOT EXISTS (SELECT 1 FROM affiliate_service_catalog WHERE service_key='subscription_plan');

INSERT INTO affiliate_service_catalog (service_key, label_ar, label_en, revenue_source, attribution_mode, commission_permitted, is_regulated_fee, requires_compliance_approval, compliance_note, sort_order)
SELECT 'consultation_fee', 'أتعاب استشارة', 'Consultation fee', 'consultation', 'attribution_only', 0, 1, 1, 'أتعاب مهنية منظّمة. ممنوع تقاسمها مع طرف ثالث دون موافقة قانونية مكتوبة.', 2
WHERE NOT EXISTS (SELECT 1 FROM affiliate_service_catalog WHERE service_key='consultation_fee');

INSERT INTO affiliate_service_catalog (service_key, label_ar, label_en, revenue_source, attribution_mode, commission_permitted, is_regulated_fee, requires_compliance_approval, compliance_note, sort_order)
SELECT 'legal_invoice_fee', 'أتعاب محامٍ (فاتورة)', 'Lawyer invoice fee', 'invoice', 'prohibited', 0, 1, 1, 'أتعاب مهنية منظّمة. ممنوعة منعاً باتاً لأي عمولة إحالة.', 3
WHERE NOT EXISTS (SELECT 1 FROM affiliate_service_catalog WHERE service_key='legal_invoice_fee');

INSERT INTO affiliate_service_catalog (service_key, label_ar, label_en, revenue_source, attribution_mode, commission_permitted, is_regulated_fee, requires_compliance_approval, compliance_note, sort_order)
SELECT 'platform_service_fee', 'رسوم خدمة منصة', 'Platform service fee', 'service', 'disabled', 0, 0, 1, 'رسوم إدارية غير منظّمة. تنتظر موافقة مسؤول الالتزام قبل التفعيل.', 4
WHERE NOT EXISTS (SELECT 1 FROM affiliate_service_catalog WHERE service_key='platform_service_fee');

INSERT INTO affiliate_service_catalog (service_key, label_ar, label_en, revenue_source, attribution_mode, commission_permitted, is_regulated_fee, requires_compliance_approval, compliance_note, sort_order)
SELECT 'ai_subscription', 'اشتراك خدمات الذكاء الاصطناعي', 'AI services subscription', 'subscription', 'analytics_only', 0, 0, 1, 'يُحتسب للتحليلات فقط في هذه المرحلة.', 5
WHERE NOT EXISTS (SELECT 1 FROM affiliate_service_catalog WHERE service_key='ai_subscription');


-- Default settings. Each is inserted only when absent, so an operator's value is
-- never overwritten by a re-run of this file.

INSERT INTO site_settings (setting_key, setting_value)
SELECT 'affiliate_program_enabled', '0'
WHERE NOT EXISTS (SELECT 1 FROM site_settings WHERE setting_key='affiliate_program_enabled');

INSERT INTO site_settings (setting_key, setting_value)
SELECT 'affiliate_attribution_days', '30'
WHERE NOT EXISTS (SELECT 1 FROM site_settings WHERE setting_key='affiliate_attribution_days');

INSERT INTO site_settings (setting_key, setting_value)
SELECT 'affiliate_attribution_model', 'first_touch'
WHERE NOT EXISTS (SELECT 1 FROM site_settings WHERE setting_key='affiliate_attribution_model');

INSERT INTO site_settings (setting_key, setting_value)
SELECT 'affiliate_cookie_days', '90'
WHERE NOT EXISTS (SELECT 1 FROM site_settings WHERE setting_key='affiliate_cookie_days');

INSERT INTO site_settings (setting_key, setting_value)
SELECT 'affiliate_require_consent', '1'
WHERE NOT EXISTS (SELECT 1 FROM site_settings WHERE setting_key='affiliate_require_consent');

INSERT INTO site_settings (setting_key, setting_value)
SELECT 'affiliate_min_payout', '50.000'
WHERE NOT EXISTS (SELECT 1 FROM site_settings WHERE setting_key='affiliate_min_payout');

INSERT INTO site_settings (setting_key, setting_value)
SELECT 'affiliate_payout_currency', 'BHD'
WHERE NOT EXISTS (SELECT 1 FROM site_settings WHERE setting_key='affiliate_payout_currency');

INSERT INTO site_settings (setting_key, setting_value)
SELECT 'affiliate_dual_approval_threshold', '500.000'
WHERE NOT EXISTS (SELECT 1 FROM site_settings WHERE setting_key='affiliate_dual_approval_threshold');

INSERT INTO site_settings (setting_key, setting_value)
SELECT 'affiliate_default_hold_days', '30'
WHERE NOT EXISTS (SELECT 1 FROM site_settings WHERE setting_key='affiliate_default_hold_days');

INSERT INTO site_settings (setting_key, setting_value)
SELECT 'affiliate_default_commission_percent', '10.000'
WHERE NOT EXISTS (SELECT 1 FROM site_settings WHERE setting_key='affiliate_default_commission_percent');

-- The default plan exists so a newly approved affiliate has something to be
-- attached to. It is a subscription-revenue plan; it can only ever pay on a
-- service whose compliance gate has opened.
INSERT INTO affiliate_commission_plans (name, slug, version, plan_type, description, currency, revenue_base, percentage, first_payment_only, hold_days, effective_from, is_default, is_active, tax_treatment, notes)
SELECT 'الخطة الافتراضية', 'default', 1, 'percentage', 'عمولة على صافي الإيراد المؤهل لخدمات المنصة فقط. لا تُطبَّق على الأتعاب المهنية المنظّمة.', 'BHD', 'eligible_net', 10.000, 1, 30, CURDATE(), 1, 1, 'not_applicable', 'الخطة الافتراضية للبرنامج. تخضع لموافقة الالتزام قبل أي صرف.'
WHERE NOT EXISTS (SELECT 1 FROM affiliate_commission_plans WHERE slug='default' AND version=1);


-- ---------------------------------------------------------------------------
-- 27. Guarded additive ALTERs
-- ---------------------------------------------------------------------------
-- This file is applied by hand on a live server and may have been run once before
-- being finalised, so the column and index below are added conditionally rather
-- than assumed present. Every statement appends rather than positioning: a
-- positional AFTER naming an absent column is what aborted upgrade_v56.sql in
-- production and silently skipped every statement below it.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='affiliate_notification_log' AND COLUMN_NAME='read_at');
SET @s := IF(@c=0, "ALTER TABLE affiliate_notification_log ADD COLUMN read_at DATETIME DEFAULT NULL", 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='affiliate_notification_log' AND INDEX_NAME='idx_affiliate_notification_unread');
SET @s := IF(@c=0, "ALTER TABLE affiliate_notification_log ADD INDEX idx_affiliate_notification_unread (affiliate_id, read_at)", 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;


-- ---------------------------------------------------------------------------
-- 28. Migration ledger
-- ---------------------------------------------------------------------------
-- Written last, so its presence is evidence the whole file ran rather than
-- evidence that it started. The repair tooling and the migration verifier read it.

CREATE TABLE IF NOT EXISTS affiliate_migration_ledger (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    migration_key VARCHAR(80) NOT NULL,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    note VARCHAR(255) DEFAULT NULL,
    UNIQUE KEY uq_affiliate_migration (migration_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO affiliate_migration_ledger (migration_key, note)
VALUES ('upgrade_v57_affiliate_program', 'affiliate, referral and partner marketing system')
ON DUPLICATE KEY UPDATE applied_at = applied_at;
