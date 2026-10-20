-- ============================================================================
-- Mawatheq Platform — customer home banners (Execution 38)
-- upgrade_v64.sql
-- ============================================================================
--
-- WHAT THIS ADDS
--
--   1) home_banners — the images the customer app's home slider shows.
--
--      The mobile home screen must open with a real, admin-managed banner slider,
--      and until now there was nowhere to store a banner: `articles` is the knowledge
--      centre, `testimonials` is customer quotes, and neither is a promotional image
--      with a destination link. This table is that store.
--
--      It is deliberately EMPTY on creation. The brief forbids fabricating promotions,
--      so the app must not invent a slide: an empty table means the slider renders its
--      empty state and the home screen simply moves on to the categories underneath.
--      Populating it is an operator action in /admin/banners.php.
--
--   2) The link_type/link_target pair is the banner's destination. It is split into a
--      type plus a target rather than a single free-text URL because the customer app
--      must not follow an arbitrary address an operator typed: a 'url' banner is opened
--      by the operating system's browser, and 'category' / 'specialty' banners resolve
--      to a numeric id the app navigates to internally. A banner with no destination
--      (link_type='none') is a legitimate, non-clickable image.
--
-- WHAT THIS DELIBERATELY DOES NOT DO
--
--   * No data is rewritten, dropped or re-typed. Every statement is additive.
--   * No banner is inserted. The table ships empty, like lawyer_specialties in v63.
--   * No positional AFTER clause names a column. A positional AFTER naming a column
--     that does not exist aborts the statement, and MySQL stops at the first error —
--     everything below silently never runs while the migration still looks applied.
--     (Execution 28 failure mode.) Column order is cosmetic.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS, so a re-run is a no-op. This matters because
-- a migration that derives from data may legitimately be run twice (see AGENTS.md).
-- ============================================================================

CREATE TABLE IF NOT EXISTS home_banners (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- The banner's own words. Both languages, like every other customer-facing table
    -- here, so an operator is not forced to leave one blank.
    title_ar VARCHAR(255) NOT NULL,
    title_en VARCHAR(255) NOT NULL DEFAULT '',
    subtitle_ar VARCHAR(500) DEFAULT NULL,
    subtitle_en VARCHAR(500) DEFAULT NULL,
    -- Path under the site root, e.g. uploads/banners/<random>.jpg. The app builds an
    -- absolute URL from the configured API origin; nothing absolute is stored.
    image_path VARCHAR(500) NOT NULL,
    -- Alt text is separate from the title: a banner whose title is short still needs a
    -- description for a screen reader, and reusing the title is not always accurate.
    alt_ar VARCHAR(255) DEFAULT NULL,
    alt_en VARCHAR(255) DEFAULT NULL,
    -- Where tapping the banner goes. 'none' means it is not tappable.
    link_type ENUM('none','category','specialty','url') NOT NULL DEFAULT 'none',
    -- For 'category'/'specialty' this is the numeric id as text; for 'url' an https
    -- address. VARCHAR rather than INT because the three cases share one column and a
    -- URL would not fit an INT. The API validates the target against the type before
    -- emitting it, so the app never receives a target it cannot resolve.
    link_target VARCHAR(500) DEFAULT NULL,
    status ENUM('active','hidden') NOT NULL DEFAULT 'active',
    sort_order SMALLINT NOT NULL DEFAULT 0,
    -- Optional scheduling window. NULL means "always". A banner whose window has passed
    -- stops being returned, which is how a time-limited campaign ends without an
    -- operator having to remember to hide it.
    starts_at DATETIME DEFAULT NULL,
    ends_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- The public endpoint filters on status and orders by sort_order, so the index
    -- matches the query rather than the primary key.
    KEY idx_home_banners_active (status, sort_order),
    KEY idx_home_banners_window (starts_at, ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
