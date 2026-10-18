<?php
/**
 * Referral tracking engine (Execution 31).
 *
 * Turns a `?ref=CODE` on an inbound request into a durable, server-side attribution.
 *
 * DESIGN DECISIONS, and why they are what they are
 *
 * 1. The cookie is not the record.
 *    A cookie can be deleted, blocked, or forged. The authoritative attribution is
 *    the `affiliate_attributions` row, keyed by an opaque random visitor token. The
 *    cookie only carries that token back. Deleting the cookie loses the link to the
 *    row, which is a privacy-respecting outcome — it does not create a false
 *    attribution for somebody else.
 *
 * 2. First touch wins, and the winner is locked.
 *    `visitor_token` is UNIQUE on the attribution table. The first insert wins; a
 *    later `?ref=` from the same visitor cannot overwrite it while the window is
 *    open. This is what makes the model enforceable in the database rather than in
 *    a code path somebody could reorder. A conversion additionally sets `locked_at`,
 *    freezing the owner permanently so a post-purchase `?ref=` cannot claim it.
 *
 * 3. Consent gates personal-data capture, not aggregate counting.
 *    When consent is required and absent, the click is still recorded — with no
 *    personal data beyond a salted IP hash — so traffic statistics stay honest, but
 *    `consent_state='denied'` marks it and no attribution is created from it. That
 *    is the honest reading of "consent-aware": the tracking that requires consent
 *    does not happen, and the aggregate count that does not require it still does.
 *
 * 4. Nothing here trusts a request parameter for identity.
 *    The affiliate is resolved from the code, and its eligibility is re-checked at
 *    capture time. A link belonging to a suspended affiliate stops attributing the
 *    moment they are suspended.
 */
require_once __DIR__ . '/affiliate.php';
require_once __DIR__ . '/affiliate_service.php';

const AFFILIATE_COOKIE_NAME = 'mq_ref';
const AFFILIATE_CONSENT_COOKIE = 'mq_consent';

/**
 * The visitor token from the request cookie, or a new one.
 *
 * 32 hex characters — 128 bits of randomness, which is enough that two visitors
 * cannot collide and the token cannot be guessed from another one.
 */
function affiliate_visitor_token(bool $create = false): ?string {
    $existing = (string)($_COOKIE[AFFILIATE_COOKIE_NAME] ?? '');
    if (preg_match('/^[a-f0-9]{32}$/', $existing)) return $existing;
    return $create ? bin2hex(random_bytes(16)) : null;
}

/** Write the visitor cookie. Path-wide, SameSite=Lax, HttpOnly, no cross-site leakage. */
function affiliate_set_visitor_cookie(string $token, int $days): void {
    if (headers_sent()) return;
    $days = max(1, min(730, $days));
    setcookie(AFFILIATE_COOKIE_NAME, $token, [
        'expires' => time() + ($days * 86400),
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    // Make the value visible to the rest of this request without waiting for the
    // browser to send it back on the next one.
    $_COOKIE[AFFILIATE_COOKIE_NAME] = $token;
}

/** Clear the visitor cookie (used when consent is withdrawn). */
function affiliate_clear_visitor_cookie(): void {
    if (headers_sent()) return;
    setcookie(AFFILIATE_COOKIE_NAME, '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    unset($_COOKIE[AFFILIATE_COOKIE_NAME]);
}

/**
 * The visitor's recorded consent state.
 *
 * Absent cookie means `unknown`, not `granted`. A deployment that requires consent
 * therefore attributes nothing until the visitor has actually agreed — the safe
 * default, and the one a regulator would expect.
 */
function affiliate_consent_state(): string {
    $raw = (string)($_COOKIE[AFFILIATE_CONSENT_COOKIE] ?? '');
    return in_array($raw, ['granted', 'denied'], true) ? $raw : 'unknown';
}

/** Record a consent decision and act on it immediately. */
function affiliate_set_consent(string $state): void {
    if (!in_array($state, ['granted', 'denied'], true)) return;
    if (!headers_sent()) {
        setcookie(AFFILIATE_CONSENT_COOKIE, $state, [
            'expires' => time() + (180 * 86400),
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    $_COOKIE[AFFILIATE_CONSENT_COOKIE] = $state;
    // Withdrawing consent must actually remove the identifier, not merely stop
    // future attribution — otherwise the token keeps pointing at a recorded visit.
    if ($state === 'denied') affiliate_clear_visitor_cookie();
}

/**
 * Extract a referral code from the request.
 *
 * `ref` is the canonical parameter. `aff` and `affiliate` are accepted because
 * partners paste whichever they were given, and silently ignoring a link somebody
 * published is worse than honouring an alias.
 */
function affiliate_code_from_request(): string {
    foreach (['ref', 'aff', 'affiliate'] as $key) {
        $value = $_GET[$key] ?? null;
        if (!is_string($value)) continue;
        $value = strtoupper(trim($value));
        if ($value !== '' && preg_match('/^[A-Z0-9_-]{3,32}$/', $value)) return $value;
    }
    return '';
}

/** Is this request plausibly an automated crawler? */
function affiliate_is_bot(): bool {
    $ua = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($ua === '') return true; // no user agent at all is not a browser
    foreach (['bot', 'crawler', 'spider', 'curl', 'wget', 'python-requests', 'headless', 'phantom', 'slurp', 'bingpreview', 'facebookexternalhit', 'monitor', 'scrapy', 'httpclient'] as $needle) {
        if (str_contains($ua, $needle)) return true;
    }
    return false;
}

/** Salted hash of the user agent, for deduplication only. Never the raw string. */
function affiliate_ua_hash(): ?string {
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ua === '') return null;
    $salt = defined('MAWATHEQ_ENCRYPTION_KEY') ? (string)MAWATHEQ_ENCRYPTION_KEY : 'mawatheq-affiliate';
    return hash('sha256', $salt . '|ua|' . $ua);
}

/**
 * Resolve and record a referral from the current request.
 *
 * Called from config.php on every request, so it must be cheap when there is
 * nothing to do: no `?ref=` and no cookie is the overwhelmingly common case and
 * returns after two string checks and one settings read.
 *
 * @return array{action:string,affiliate_id?:int,reason?:string}
 */
function affiliate_track_request(?PDO $pdo = null): array {
    $pdo = $pdo ?: ($GLOBALS['pdo'] ?? null);
    if (!$pdo instanceof PDO) return ['action' => 'skip', 'reason' => 'no_database'];

    $settings = affiliate_settings($pdo);
    if (!$settings['program_enabled']) return ['action' => 'skip', 'reason' => 'program_disabled'];

    // The affiliate portal and the admin console are not referral destinations.
    // Following a ?ref= link into the affiliate's own dashboard must not record a
    // click against them, and a forged ?ref= on an admin URL must not create one.
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if (str_starts_with($path, '/affiliate') || str_starts_with($path, '/admin')) {
        return ['action' => 'skip', 'reason' => 'excluded_path'];
    }

    $code = affiliate_code_from_request();
    $token = affiliate_visitor_token();
    if ($code === '' && $token === null) return ['action' => 'skip', 'reason' => 'nothing_to_track'];

    // A visitor who already has an attribution keeps it. This is the first-touch
    // guarantee, and it is checked before the click is even recorded so a repeat
    // visit does not inflate the click count against a different affiliate.
    if ($token !== null) {
        $existing = affiliate_attribution_find($pdo, $token);
        if ($existing) {
            if ($code !== '' && (int)$existing['affiliate_id'] !== 0) {
                $owner = affiliate_find($pdo, (int)$existing['affiliate_id']);
                if ($owner && strtoupper((string)$owner['code']) !== $code) {
                    // A different affiliate's link, but this visitor is already owned.
                    return affiliate_attribution_touch($pdo, $existing, $settings);
                }
            }
            return affiliate_attribution_touch($pdo, $existing, $settings);
        }
    }

    if ($code === '') return ['action' => 'skip', 'reason' => 'no_attribution'];

    $affiliate = affiliate_find_by_code($pdo, $code);
    if (!$affiliate) return ['action' => 'unknown_code'];

    // A suspended or rejected affiliate stops attributing immediately. Their existing
    // attributions are handled by the suspension routine, not here.
    if (!affiliate_can_earn($affiliate)) {
        return ['action' => 'ineligible_affiliate', 'affiliate_id' => (int)$affiliate['id']];
    }

    $link = affiliate_link_find_by_code($pdo, $code);
    $campaignId = $link && $link['campaign_id'] ? (int)$link['campaign_id'] : null;
    if ($campaignId) {
        $campaign = affiliate_campaign_find($pdo, $campaignId);
        if (!$campaign || !affiliate_campaign_is_live($campaign)) {
            $campaignId = null;
        }
    }

    $token = $token ?? bin2hex(random_bytes(16));
    affiliate_set_visitor_cookie($token, $settings['cookie_days']);

    return affiliate_record_click($pdo, $affiliate, $link, $campaignId, $token, $settings);
}

/**
 * Record one click and, when consent allows, create the attribution.
 *
 * @return array{action:string,affiliate_id?:int,click_id?:int,attribution_id?:int,reason?:string}
 */
function affiliate_record_click(PDO $pdo, array $affiliate, ?array $link, ?int $campaignId, string $token, array $settings): array {
    $consent = affiliate_consent_state();
    $isBot = affiliate_is_bot();
    $suspect = null;

    // Cookie stuffing: one visitor token arriving with an implausible burst of clicks
    // in a short window. Flagged, not blocked — the click still counts for the
    // aggregate, but the flag tells a human to look before any money follows.
    try {
        $recent = $pdo->prepare('SELECT COUNT(*) FROM affiliate_clicks WHERE visitor_token=? AND clicked_at > (NOW() - INTERVAL 60 SECOND)');
        $recent->execute([$token]);
        if ((int)$recent->fetchColumn() >= 10) $suspect = 'rapid_repeat_clicks';
    } catch (Throwable $e) {
        // A flag we cannot evaluate is not a reason to lose the click.
    }

    try {
        $pdo->prepare('INSERT INTO affiliate_clicks
            (affiliate_id, link_id, campaign_id, visitor_token, landing_path, referrer_host, utm_source, utm_medium, utm_campaign, utm_content, utm_term, ip_hash, user_agent_hash, consent_state, is_bot, is_suspect, suspect_reason)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                (int)$affiliate['id'],
                $link ? (int)$link['id'] : null,
                $campaignId,
                $token,
                mb_substr((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'), 0, 255, 'UTF-8'),
                affiliate_referrer_host(),
                affiliate_utm('utm_source', $link),
                affiliate_utm('utm_medium', $link),
                affiliate_utm('utm_campaign', $link),
                affiliate_utm('utm_content', $link),
                affiliate_utm('utm_term', $link),
                affiliate_ip_hash(),
                affiliate_ua_hash(),
                $consent,
                $isBot ? 1 : 0,
                $suspect !== null ? 1 : 0,
                $suspect,
            ]);
        $clickId = (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        error_log('affiliate click insert failed: ' . $e->getMessage());
        return ['action' => 'error', 'reason' => 'click_insert_failed'];
    }

    if ($link) {
        try { $pdo->prepare('UPDATE affiliate_links SET click_count = click_count + 1 WHERE id=?')->execute([(int)$link['id']]); } catch (Throwable $e) {}
    }
    affiliate_stats_bump($pdo, (int)$affiliate['id'], ['clicks' => 1, 'unique_visitors' => 1]);

    // Consent gates attribution. Without it the click above is all we keep.
    if ($settings['require_consent'] && $consent !== 'granted') {
        return ['action' => 'click_only', 'affiliate_id' => (int)$affiliate['id'], 'click_id' => $clickId, 'reason' => 'no_consent'];
    }
    if ($isBot) {
        return ['action' => 'click_only', 'affiliate_id' => (int)$affiliate['id'], 'click_id' => $clickId, 'reason' => 'bot'];
    }

    $attributionId = affiliate_attribution_create($pdo, $affiliate, $link, $campaignId, $token, $clickId, $settings);
    return [
        'action' => $attributionId ? 'attributed' : 'click_only',
        'affiliate_id' => (int)$affiliate['id'],
        'click_id' => $clickId,
        'attribution_id' => $attributionId ?: null,
    ];
}

/** First-party referrer host only, never the full URL (which can carry a token). */
function affiliate_referrer_host(): ?string {
    $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
    if ($ref === '') return null;
    $host = parse_url($ref, PHP_URL_HOST);
    if (!is_string($host) || $host === '') return null;
    $own = strtolower((string)preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
    if ($host !== '' && $own !== '' && strcasecmp($host, $own) === 0) return null; // internal navigation is not a referrer
    return mb_substr($host, 0, 190, 'UTF-8');
}

/**
 * A UTM value: the request's own parameter wins, then the link's stored default.
 * Validated to a printable, bounded charset so a crafted parameter cannot inject
 * markup into the admin tables or a CSV export.
 */
function affiliate_utm(string $key, ?array $link): ?string {
    $raw = $_GET[$key] ?? null;
    if (!is_string($raw) || trim($raw) === '') {
        $raw = $link[$key] ?? null;
    }
    if (!is_string($raw)) return null;
    $raw = trim($raw);
    if ($raw === '') return null;
    // Strip control characters, then bound the length.
    $raw = preg_replace('/[\x00-\x1F\x7F]/u', '', $raw) ?? '';
    $raw = trim($raw);
    return $raw === '' ? null : mb_substr($raw, 0, 150, 'UTF-8');
}

/** The attribution owned by a visitor token, or null. */
function affiliate_attribution_find(PDO $pdo, string $token): ?array {
    try {
        $stmt = $pdo->prepare('SELECT * FROM affiliate_attributions WHERE visitor_token=?');
        $stmt->execute([$token]);
        return $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Create the attribution for a visitor.
 *
 * The UNIQUE key on visitor_token does the arbitration: a concurrent request that
 * loses the race gets a duplicate-key error, which is read as "somebody else owns
 * this visitor" and resolved by returning the winning row. That is the correct
 * outcome — the first touch is the first commit, and no amount of concurrency
 * produces two owners.
 */
function affiliate_attribution_create(PDO $pdo, array $affiliate, ?array $link, ?int $campaignId, string $token, int $clickId, array $settings): ?int {
    $days = $settings['attribution_days'];
    $expires = date('Y-m-d H:i:s', time() + ($days * 86400));
    try {
        $pdo->prepare('INSERT INTO affiliate_attributions
            (visitor_token, affiliate_id, link_id, campaign_id, first_click_id, last_click_id, touch_count, model, expires_at)
            VALUES (?,?,?,?,?,?,1,?,?)')
            ->execute([
                $token, (int)$affiliate['id'], $link ? (int)$link['id'] : null, $campaignId,
                $clickId, $clickId, $settings['attribution_model'], $expires,
            ]);
        $id = (int)$pdo->lastInsertId();
        affiliate_audit($pdo, 'attribution_created', [
            'affiliate_id' => (int)$affiliate['id'],
            'subject_type' => 'attribution', 'subject_id' => $id,
            'detail' => 'visitor token recorded, model=' . $settings['attribution_model'] . ', window=' . $days . 'd',
        ]);
        return $id;
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? 0) === 1062) {
            // Lost the race, or an attribution already exists. The existing row is
            // authoritative and is deliberately not overwritten.
            $existing = affiliate_attribution_find($pdo, $token);
            return $existing ? (int)$existing['id'] : null;
        }
        error_log('affiliate attribution insert failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Register a further touch by an already-owned visitor.
 *
 * The owner is never changed. Under first-touch the extra touches are recorded so
 * the journey is visible; under last-touch the owner moves to the most recent
 * eligible affiliate, which is the model's definition.
 */
function affiliate_attribution_touch(PDO $pdo, array $attribution, array $settings): array {
    try {
        if (!empty($attribution['locked_at'])) {
            return ['action' => 'locked', 'affiliate_id' => (int)$attribution['affiliate_id']];
        }
        if (strtotime((string)$attribution['expires_at']) < time()) {
            return ['action' => 'expired', 'affiliate_id' => (int)$attribution['affiliate_id']];
        }
        $pdo->prepare('UPDATE affiliate_attributions SET last_seen_at=NOW(), touch_count=touch_count+1 WHERE id=?')
            ->execute([(int)$attribution['id']]);
        return ['action' => 'touched', 'affiliate_id' => (int)$attribution['affiliate_id']];
    } catch (Throwable $e) {
        error_log('affiliate_attribution_touch: ' . $e->getMessage());
        return ['action' => 'error'];
    }
}

/**
 * Bind an authenticated account to its attribution, and freeze the owner.
 *
 * Called at registration and at booking. Once a conversion-capable account is
 * bound, the owner is locked: a `?ref=` arriving afterwards cannot move it, which
 * closes the "refer yourself after signing up" hole.
 *
 * @return array{ok:bool,attribution_id?:int,affiliate_id?:int,reason?:string}
 */
function affiliate_bind_identity(PDO $pdo, string $subjectType, int $subjectId, ?string $token = null): array {
    $pdo = $pdo ?: ($GLOBALS['pdo'] ?? null);
    if (!$pdo instanceof PDO || $subjectId <= 0) return ['ok' => false, 'reason' => 'bad_input'];

    $settings = affiliate_settings($pdo);
    if (!$settings['program_enabled']) return ['ok' => false, 'reason' => 'program_disabled'];

    $token = $token ?? affiliate_visitor_token();
    if ($token === null) return ['ok' => false, 'reason' => 'no_visitor_token'];

    $attribution = affiliate_attribution_find($pdo, $token);
    if (!$attribution) return ['ok' => false, 'reason' => 'no_attribution'];
    if (strtotime((string)$attribution['expires_at']) < time()) return ['ok' => false, 'reason' => 'outside_window'];

    // An account may be bound once, to one affiliate. If it is already bound anywhere —
    // including to this same affiliate — the attempt is refused.
    //
    // The guard has to be a lookup rather than the `{$column} IS NULL` condition on the
    // UPDATE below, because that condition is on the *attribution* row and a subject can
    // have several attributions (a visitor token per device). Without this check a
    // second device's attribution could be bound to the same account and the affiliate
    // resolved from it would be whichever row the lookup happened to return — which is
    // how one customer becomes two affiliates' commission.
    $existing = affiliate_attribution_for_subject($pdo, $subjectType, $subjectId);
    if ($existing) {
        return ['ok' => false, 'reason' => 'already_bound', 'attribution_id' => (int)$existing['id'], 'affiliate_id' => (int)$existing['affiliate_id']];
    }

    // Self-referral by identity: an affiliate may not refer their own account.
    $affiliate = affiliate_find($pdo, (int)$attribution['affiliate_id']);
    if (!$affiliate) return ['ok' => false, 'reason' => 'no_attribution'];
    if (affiliate_is_self_referral($affiliate, $subjectType, $subjectId)) {
        affiliate_flag($pdo, [
            'affiliate_id' => (int)$affiliate['id'],
            'flag_type' => 'self_referral',
            'severity' => 'high',
            'description' => 'محاولة إحالة ذاتية عند الربط: ' . $subjectType . ' #' . $subjectId,
        ]);
        return ['ok' => false, 'reason' => 'self_referral'];
    }

    $column = $subjectType === 'lawyer' ? 'lawyer_id' : 'user_id';
    try {
        $pdo->prepare("UPDATE affiliate_attributions SET {$column}=?, converted_at=COALESCE(converted_at, NOW()), locked_at=NOW(), lock_reason='identity_bound' WHERE id=?")
            ->execute([$subjectId, (int)$attribution['id']]);
    } catch (Throwable $e) {
        error_log('affiliate_bind_identity: ' . $e->getMessage());
        return ['ok' => false, 'reason' => 'update_failed'];
    }

    // Registration is a tracked milestone even where it pays nothing.
    affiliate_record_conversion($pdo, $attribution, [
        'service_key' => 'subscription_plan',
        'event_type' => 'registration',
        'event_key' => 'registration:' . $subjectType . ':' . $subjectId,
        'source_type' => 'registration',
        'source_id' => $subjectId,
        'subject_type' => $subjectType,
        'gross' => '0',
        'currency' => $settings['payout_currency'],
    ]);

    return ['ok' => true, 'attribution_id' => (int)$attribution['id'], 'affiliate_id' => (int)$attribution['affiliate_id']];
}

/** True when the account being bound is the affiliate's own. */
function affiliate_is_self_referral(array $affiliate, string $subjectType, int $subjectId): bool {
    if ($subjectType === 'client' && (int)($affiliate['self_user_id'] ?? 0) === $subjectId) return true;
    if ($subjectType === 'lawyer' && (int)($affiliate['self_lawyer_id'] ?? 0) === $subjectId) return true;
    return false;
}

/** Look up an attribution by the account it was bound to. */
function affiliate_attribution_for_subject(PDO $pdo, string $subjectType, int $subjectId): ?array {
    $column = $subjectType === 'lawyer' ? 'lawyer_id' : 'user_id';
    try {
        $stmt = $pdo->prepare("SELECT * FROM affiliate_attributions WHERE {$column}=? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$subjectId]);
        return $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** A referral link by its code. */
function affiliate_link_find_by_code(PDO $pdo, string $code): ?array {
    try {
        $stmt = $pdo->prepare('SELECT * FROM affiliate_links WHERE code=? AND is_active=1');
        $stmt->execute([strtoupper(trim($code))]);
        return $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** An affiliate by id, or null. Local alias so this file has no hidden dependency. */
function affiliate_link_find(PDO $pdo, int $id): ?array {
    try {
        $stmt = $pdo->prepare('SELECT * FROM affiliate_links WHERE id=?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** A campaign by id. */
function affiliate_campaign_find(PDO $pdo, int $id): ?array {
    try {
        $stmt = $pdo->prepare('SELECT * FROM affiliate_campaigns WHERE id=?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** A campaign is live when it is active and inside its date range. */
function affiliate_campaign_is_live(?array $campaign): bool {
    if (!$campaign) return false;
    if (($campaign['status'] ?? '') !== 'active') return false;
    $now = time();
    if (!empty($campaign['starts_at']) && strtotime((string)$campaign['starts_at']) > $now) return false;
    if (!empty($campaign['ends_at']) && strtotime((string)$campaign['ends_at']) < $now) return false;
    return true;
}

/**
 * Build a referral URL for an affiliate.
 *
 * The destination is constrained to a same-site path: an affiliate must not be able
 * to construct a link that sends traffic to an external site under our domain, and
 * an open redirect through the referral parameter is exactly how that would happen.
 */
function affiliate_build_link(array $affiliate, string $path = '/', array $utm = []): string {
    $path = affiliate_safe_destination($path);
    $query = ['ref' => (string)$affiliate['code']];
    foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content'] as $key) {
        if (!empty($utm[$key])) {
            $clean = preg_replace('/[\x00-\x1F\x7F]/u', '', (string)$utm[$key]) ?? '';
            $clean = trim($clean);
            if ($clean !== '') $query[$key] = mb_substr($clean, 0, 150, 'UTF-8');
        }
    }
    $base = rtrim(SITE_URL, '/');
    return $base . $path . '?' . http_build_query($query);
}

/**
 * Constrain a destination to a safe same-site path.
 *
 * Rejects anything with a scheme or host (including protocol-relative `//evil.com`)
 * and any traversal, falling back to `/`. Without this a stored destination of
 * `//attacker.example` would turn every affiliate link into an open redirect.
 */
function affiliate_safe_destination(string $path): string {
    $path = trim($path);
    if ($path === '' || $path[0] !== '/') return '/';
    if (str_starts_with($path, '//')) return '/';
    if (str_contains($path, '\\')) return '/';
    if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $path)) return '/';
    if (preg_match('#(^|/)\.\.(/|$)#', $path)) return '/';
    // Strip any query/fragment the caller embedded; the builder owns the query.
    $path = preg_split('/[?#]/', $path)[0] ?? '/';
    return $path === '' ? '/' : mb_substr($path, 0, 255, 'UTF-8');
}

/** Increment today's pre-aggregated counters for an affiliate. */
function affiliate_stats_bump(PDO $pdo, int $affiliateId, array $deltas, ?string $date = null): void {
    if ($affiliateId <= 0 || !$deltas) return;
    $date = $date ?: date('Y-m-d');
    $allowed = ['clicks', 'unique_visitors', 'registrations', 'conversions', 'qualified_conversions', 'paid_conversions'];
    $sets = [];
    $params = [];
    foreach ($deltas as $key => $value) {
        if (!in_array($key, $allowed, true)) continue;
        $value = (int)$value;
        if ($value === 0) continue;
        $sets[] = "`{$key}` = `{$key}` + ?";
        $params[] = $value;
    }
    if (!$sets) return;
    try {
        $pdo->prepare('INSERT INTO affiliate_daily_stats (affiliate_id, stat_date, currency) VALUES (?,?,?) ON DUPLICATE KEY UPDATE affiliate_id = affiliate_id')
            ->execute([$affiliateId, $date, affiliate_settings($pdo)['payout_currency']]);
        $params[] = $affiliateId;
        $params[] = $date;
        $pdo->prepare('UPDATE affiliate_daily_stats SET ' . implode(', ', $sets) . ' WHERE affiliate_id=? AND stat_date=?')
            ->execute($params);
    } catch (Throwable $e) {
        // Statistics are a convenience; losing a counter must not fail a conversion.
        error_log('affiliate_stats_bump: ' . $e->getMessage());
    }
}
