<?php
/**
 * Law firm public website + office structure service layer (v50).
 *
 * Two audiences, one file:
 *   * PUBLIC read models (firm_site_public_firm / pages / team / articles / branches) —
 *     these are called by the public pages and MUST only ever return rows the firm has
 *     actually published. Every query filters on the firm_id resolved from the slug, so a
 *     slug can never widen into another firm's rows.
 *   * FIRM-SCOPED write helpers — called from the firm dashboard. The firm_id they write to
 *     is passed in by the caller from the session tenant context, never from a request
 *     parameter. The permission check (owner/partner only) lives in firm_site_can_manage()
 *     and is asserted by the dashboard before any write.
 *
 * Client, case and document data is never read here. A public page shows the firm's own
 * marketing content and its lawyers' professional profiles (name, specialisation, bio) —
 * never client identities, case records, or any lawyer's private contact details.
 */

if (!function_exists('firm_site_can_manage')) {
    /**
     * A lawyer may manage the firm website / office structure only when they are an active
     * owner or partner of that exact firm. A plain lawyer/staff member gets read-only access
     * to the office overview and no write rights anywhere.
     */
    function firm_site_can_manage(?PDO $pdo, int $firmId, int $lawyerId): bool {
        if ($firmId <= 0 || $lawyerId <= 0) return false;
        $stmt = $pdo->prepare("SELECT member_role FROM law_firm_members WHERE firm_id=? AND lawyer_id=? AND status='active' LIMIT 1");
        $stmt->execute([$firmId, $lawyerId]);
        $role = $stmt->fetchColumn();
        return in_array($role, ['owner', 'partner'], true);
    }
}

if (!function_exists('firm_site_slugify')) {
    /** URL-safe slug; Arabic is allowed as-is so a firm's slug can match its own brand. */
    function firm_site_slugify(string $text): string {
        $text = trim($text);
        $text = preg_replace('/[\s_]+/u', '-', $text);
        $text = preg_replace('/[^\p{L}\p{N}\-]+/u', '', $text);
        $text = trim((string)$text, '-');
        return mb_substr($text, 0, 120, 'UTF-8');
    }
}

if (!function_exists('firm_site_unique_slug')) {
    /**
     * A slug unique across all firms. Empty input falls back to "firm-<id>" so a firm can
     * always publish even if it never set a name-derived slug.
     */
    function firm_site_unique_slug(?PDO $pdo, int $firmId, string $desired): string {
        $base = firm_site_slugify($desired);
        if ($base === '') $base = 'firm-' . $firmId;
        $slug = $base; $n = 1;
        while (true) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM law_firms WHERE public_slug=? AND id<>?');
            $stmt->execute([$slug, $firmId]);
            if ((int)$stmt->fetchColumn() === 0) return $slug;
            $slug = $base . '-' . (++$n);
        }
    }
}

if (!function_exists('firm_site_public_firm')) {
    /**
     * Resolve a publicly visible firm by slug. Returns null unless the firm is explicitly
     * published (is_public=1) — a draft/disabled site must read as "not found", never as a
     * page that simply omits some blocks.
     */
    function firm_site_public_firm(?PDO $pdo, string $slug): ?array {
        if ($slug === '') return null;
        $stmt = $pdo->prepare('SELECT * FROM law_firms WHERE public_slug=? AND is_public=1 LIMIT 1');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}

if (!function_exists('firm_site_pages')) {
    /** @param bool $publishedOnly true for the public site, false for the firm dashboard list */
    function firm_site_pages(?PDO $pdo, int $firmId, bool $publishedOnly = true): array {
        $sql = 'SELECT * FROM firm_pages WHERE firm_id=?';
        $params = [$firmId];
        if ($publishedOnly) { $sql .= " AND status='published'"; }
        $sql .= ' ORDER BY sort_order ASC, id ASC';
        $stmt = $pdo->prepare($sql); $stmt->execute($params);
        return $stmt->fetchAll();
    }
}

if (!function_exists('firm_site_branches')) {
    function firm_site_branches(?PDO $pdo, int $firmId, bool $activeOnly = true): array {
        $sql = 'SELECT * FROM firm_branches WHERE firm_id=?';
        if ($activeOnly) { $sql .= " AND status='active'"; }
        $sql .= ' ORDER BY is_primary DESC, id ASC';
        $stmt = $pdo->prepare($sql); $stmt->execute([$firmId]);
        return $stmt->fetchAll();
    }
}

if (!function_exists('firm_site_departments')) {
    function firm_site_departments(?PDO $pdo, int $firmId, bool $activeOnly = true): array {
        $sql = 'SELECT * FROM firm_departments WHERE firm_id=?';
        if ($activeOnly) { $sql .= " AND status='active'"; }
        $sql .= ' ORDER BY id ASC';
        $stmt = $pdo->prepare($sql); $stmt->execute([$firmId]);
        return $stmt->fetchAll();
    }
}

if (!function_exists('firm_site_announcements')) {
    /** Internal only — audience is 'all' | 'lawyers' | 'staff'. Never exposed publicly. */
    function firm_site_announcements(?PDO $pdo, int $firmId, string $audience = 'all', bool $publishedOnly = true): array {
        $sql = 'SELECT * FROM firm_announcements WHERE firm_id=?';
        $params = [$firmId];
        if ($publishedOnly) { $sql .= " AND status='published'"; }
        if ($audience !== 'all') { $sql .= " AND (audience='all' OR audience=?)"; $params[] = $audience; }
        $sql .= ' ORDER BY COALESCE(published_at, created_at) DESC LIMIT 50';
        $stmt = $pdo->prepare($sql); $stmt->execute($params);
        return $stmt->fetchAll();
    }
}

if (!function_exists('firm_site_team')) {
    /**
     * The firm's lawyers for the public team page. Only professional profile fields —
     * never email, phone, or any client-related data.
     */
    function firm_site_team(?PDO $pdo, int $firmId): array {
        $stmt = $pdo->prepare(
            "SELECT l.id, l.full_name, l.specialization, l.bio, l.years_experience, l.photo_path,
                    l.qualifications, l.social_linkedin, l.social_twitter, l.social_website,
                    l.jurisdiction, m.member_role
             FROM law_firm_members m
             JOIN lawyers l ON l.id = m.lawyer_id
             WHERE m.firm_id=? AND m.status='active' AND l.status='approved'
             ORDER BY FIELD(m.member_role,'owner','partner','lawyer','staff'), l.full_name ASC"
        );
        $stmt->execute([$firmId]);
        return $stmt->fetchAll();
    }
}

if (!function_exists('firm_site_articles')) {
    /**
     * Firm-owned publications. Platform editorial articles (firm_id NULL) are intentionally
     * excluded here so a firm's site shows only content the firm itself published.
     */
    function firm_site_articles(?PDO $pdo, int $firmId, bool $publishedOnly = true): array {
        $sql = 'SELECT * FROM articles WHERE firm_id=?';
        if ($publishedOnly) { $sql .= " AND status='published'"; }
        $sql .= ' ORDER BY published_at DESC, id DESC LIMIT 100';
        $stmt = $pdo->prepare($sql); $stmt->execute([$firmId]);
        return $stmt->fetchAll();
    }
}
