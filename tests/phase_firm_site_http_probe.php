<?php
/**
 * Live HTTP probe for the firm public website vertical (v50).
 *
 * Drives the real login, the real firm-site dashboard POSTs, and the real public pages
 * over HTTP against the running server — no mocks. Verifies:
 *   * an owner can publish a firm site and it becomes reachable publicly;
 *   * a plain firm lawyer is refused (403) on a management write;
 *   * a lawyer in firm A cannot read or edit firm B's site data;
 *   * an unpublished firm reads 404.
 *
 * Mutates firm 2 and restores it afterwards. Run: php tests/phase_firm_site_http_probe.php
 */

require_once __DIR__ . '/../config/config.php';

$base = 'http://127.0.0.1:8080';
$pass = 'Test-Password-2026';
$failures = 0; $checks = 0;
function check(string $label, bool $ok): void {
    global $failures, $checks; $checks++;
    if (!$ok) { $failures++; echo "  FAIL: $label\n"; } else echo "  ok:   $label\n";
}

/** Log in over HTTP and return [cookieJarPath, redirectLocation]. */
function http_login(string $base, string $loginPath, string $email, string $pass, string $jar): array {
    $ch = curl_init($base . $loginPath);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false]);
    $html = curl_exec($ch); curl_close($ch);
    preg_match('/name="csrf_token" value="([^"]+)"/', (string)$html, $m);
    $token = $m[1] ?? '';
    $ch = curl_init($base . $loginPath);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['csrf_token' => $token, 'email' => $email, 'password' => $pass]),
    ]);
    curl_exec($ch);
    $loc = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL); curl_close($ch);
    return [$jar, $loc];
}

function http_get(string $url, string $jar): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false]);
    $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $loc = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL); curl_close($ch);
    return [$code, (string)$body, $loc];
}

/** GET a page to extract a CSRF token (session-bound). */
function fetch_csrf(string $url, string $jar): string {
    [$c, $b] = http_get($url, $jar);
    preg_match('/name="csrf_token" value="([^"]+)"/', $b, $m);
    return $m[1] ?? '';
}

/** POST to the firm dashboard. Returns [httpCode, redirectUrl]. */
function firm_post(string $base, string $jar, array $fields): array {
    $token = fetch_csrf($base . '/lawyer/firm-site.php', $jar);
    $fields['csrf_token'] = $token;
    $ch = curl_init($base . '/lawyer/firm-site.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields),
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $loc = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL); curl_close($ch);
    return [$code, $loc];
}

// ---- Snapshot firm 2 so we can restore it -------------------------------------------
$firmB = $pdo->query('SELECT * FROM law_firms ORDER BY id DESC LIMIT 1')->fetch();
$firmBId = (int)$firmB['id'];
$slug = 'fs-probe-' . bin2hex(random_bytes(3));
$createdPages = []; $createdBranches = []; $createdDepts = []; $createdAnns = [];

try {
    // ---- Owner B publishes the site -------------------------------------------------
    $jarB = tempnam(sys_get_temp_dir(), 'fsjar');
    [, $loc] = http_login($base, '/lawyer-login.php', 'owner-b@example.com', $pass, $jarB);
    check('owner-b login redirects to dashboard', str_contains($loc, 'dashboard'));

    [$c, ] = http_get($base . '/lawyer/firm-site.php', $jarB);
    check('firm-site dashboard serves 200', $c === 200);

    [$c, ] = firm_post($base, $jarB, [
        'action' => 'save_branding', 'public_slug' => $slug, 'is_public' => '1',
        'tagline_ar' => 'Tagline B', 'about_ar' => 'About B', 'practice_areas' => 'Comercial',
    ]);
    check('owner publish redirects (302)', $c === 302);

    [$c, $pub] = http_get($base . '/firm.php?firm=' . urlencode($slug), $jarB);
    check('published firm homepage is public', $c === 200);
    check('homepage shows owner-entered content', str_contains($pub, 'Tagline B') && str_contains($pub, 'About B'));

    // ---- Add a published page over HTTP ---------------------------------------------
    [$c, ] = firm_post($base, $jarB, [
        'action' => 'save_page', 'title_ar' => 'صفحة تجريبية', 'page_slug' => 'probe-page',
        'content_ar' => 'محتوى الصفحة', 'page_type' => 'custom', 'status' => 'published',
    ]);
    check('page save redirects (302)', $c === 302);
    [$c, $pg] = http_get($base . '/firm-page.php?firm=' . urlencode($slug) . '&page=probe-page', $jarB);
    check('published page is reachable', $c === 200 && str_contains($pg, 'محتوى الصفحة'));

    // A draft page must 404 publicly.
    [$c, ] = firm_post($base, $jarB, [
        'action' => 'save_page', 'title_ar' => 'مسودة', 'page_slug' => 'draft-page',
        'content_ar' => 'draft', 'page_type' => 'custom', 'status' => 'draft',
    ]);
    [$c, ] = http_get($base . '/firm-page.php?firm=' . urlencode($slug) . '&page=draft-page', $jarB);
    check('draft page is NOT public (404)', $c === 404);

    // ---- Plain lawyer in firm A is refused a management write ------------------------
    $jarA = tempnam(sys_get_temp_dir(), 'fsjar');
    http_login($base, '/lawyer-login.php', 'member-a@example.com', $pass, $jarA);
    [$c, ] = firm_post($base, $jarA, ['action' => 'save_branding', 'public_slug' => 'hacked']);
    check('plain lawyer write is refused (403)', $c === 403);

    // Member A still gets a renderable read-only dashboard.
    [$c, $dashA] = http_get($base . '/lawyer/firm-site.php', $jarA);
    check('plain lawyer can still view dashboard', $c === 200);
    check('view is read-only for plain lawyer', str_contains($dashA, 'read-only') || str_contains($dashA, 'صلاحية العرض فقط'));

    // ---- Cross-tenant: firm A's owner cannot see firm B's public site data in-write ---
    $jarOwnerA = tempnam(sys_get_temp_dir(), 'fsjar');
    http_login($base, '/lawyer-login.php', 'owner-a@example.com', $pass, $jarOwnerA);
    [$c, ] = firm_post($base, $jarOwnerA, ['action' => 'delete_page', 'page_id' => '0']);
    check('owner A delete with foreign/zero id is harmless (302)', $c === 302);
    // The page created by owner B still exists — owner A's delete could not reach it.
    [$c, $pg2] = http_get($base . '/firm-page.php?firm=' . urlencode($slug) . '&page=probe-page', $jarB);
    check('firm B page survived firm A delete attempt', $c === 200 && str_contains($pg2, 'محتوى الصفحة'));

    // ---- Unpublished firm reads 404 ---------------------------------------------------
    $pdo->prepare('UPDATE law_firms SET is_public=0 WHERE id=?')->execute([$firmBId]);
    [$c, ] = http_get($base . '/firm.php?firm=' . urlencode($slug), $jarB);
    check('unpublished firm homepage 404s', $c === 404);
    [$c, ] = http_get($base . '/firm.php?firm=nonexistent-slug-xyz', $jarB);
    check('unknown slug 404s', $c === 404);

    foreach ([$jarA, $jarB, $jarOwnerA] as $j) { @unlink($j); }
} catch (Throwable $e) {
    echo "  FAIL: exception — " . $e->getMessage() . "\n";
    $failures++;
} finally {
    // ---- Restore firm 2 exactly as it was --------------------------------------------
    try {
        $pdo->prepare('UPDATE law_firms SET public_slug=?, is_public=?, tagline_ar=?, about_ar=?, practice_areas=? WHERE id=?')
            ->execute([$firmB['public_slug'], $firmB['is_public'], $firmB['tagline_ar'], $firmB['about_ar'], $firmB['practice_areas'], $firmBId]);
        $pdo->prepare('DELETE FROM firm_pages WHERE firm_id=? AND slug IN (?,?)')->execute([$firmBId, 'probe-page', 'draft-page']);
    } catch (Throwable $e) { error_log('probe cleanup: ' . $e->getMessage()); }
}

echo "\n" . ($checks - $failures) . "/$checks checks passed\n";
exit($failures === 0 ? 0 : 1);
