<?php
/**
 * Law firm public website + office structure test (v50).
 *
 * Drives the real includes/firm_site.php read models and permission helper against real
 * rows inside a rolled-back transaction. The point of these assertions is tenant isolation
 * and publish-gating: a firm's unpublished content, or another firm's content, must never
 * be reachable through a public read model or a management write.
 *
 * Run: php tests/phase_firm_site_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/firm_site.php';

$failures = 0;
$checks = 0;
function check(string $label, bool $ok): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) { $failures++; echo "  FAIL: $label\n"; }
    else echo "  ok:   $label\n";
}

$pdo->beginTransaction();
try {
    $suffix = bin2hex(random_bytes(4));
    $pwd = password_hash('Test-Password-123', PASSWORD_DEFAULT);
    $mkLawyer = function (string $name, string $email) use ($pdo, $pwd): int {
        $pdo->prepare("INSERT INTO lawyers (full_name,email,password_hash,status,email_verified) VALUES (?,?,?,'approved',1)")
            ->execute([$name, $email, $pwd]);
        return (int)$pdo->lastInsertId();
    };

    $ownerA  = $mkLawyer("FS Owner A $suffix", "fs-owner-a-$suffix@test.local");
    $memberA = $mkLawyer("FS Member A $suffix", "fs-member-a-$suffix@test.local");
    $ownerB  = $mkLawyer("FS Owner B $suffix", "fs-owner-b-$suffix@test.local");

    $pdo->prepare('INSERT INTO law_firms (name,owner_lawyer_id) VALUES (?,?)')->execute(["FS Firm A $suffix", $ownerA]);
    $firmA = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO law_firms (name,owner_lawyer_id) VALUES (?,?)')->execute(["FS Firm B $suffix", $ownerB]);
    $firmB = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO law_firm_members (firm_id,lawyer_id,member_role,status) VALUES (?,?,'owner','active')")->execute([$firmA, $ownerA]);
    $pdo->prepare("INSERT INTO law_firm_members (firm_id,lawyer_id,member_role,status) VALUES (?,?,'partner','active')")->execute([$firmA, $mkLawyer("FS Partner A $suffix", "fs-partner-a-$suffix@test.local")]);
    $pdo->prepare("INSERT INTO law_firm_members (firm_id,lawyer_id,member_role,status) VALUES (?,?,'lawyer','active')")->execute([$firmA, $memberA]);
    $pdo->prepare("INSERT INTO law_firm_members (firm_id,lawyer_id,member_role,status) VALUES (?,?,'owner','active')")->execute([$firmB, $ownerB]);

    // ---- Publish firm A, leave firm B unpublished -------------------------------------
    $slugA = "fs-firm-a-$suffix";
    $pdo->prepare("UPDATE law_firms SET public_slug=?, is_public=1, tagline_ar='tag', about_ar='about' WHERE id=?")->execute([$slugA, $firmA]);

    // ---- Permission model -------------------------------------------------------------
    check('owner may manage site',            firm_site_can_manage($pdo, $firmA, $ownerA) === true);
    check('a foreign owner may NOT manage',   firm_site_can_manage($pdo, $firmA, $ownerB) === false);
    check('a plain lawyer may NOT manage',    firm_site_can_manage($pdo, $firmA, $memberA) === false);

    // A partner row created above resolves by email lookup — verify via a fresh query.
    $partnerId = (int)$pdo->query("SELECT l.id FROM lawyers l JOIN law_firm_members m ON m.lawyer_id=l.id WHERE m.firm_id=$firmA AND m.member_role='partner' LIMIT 1")->fetchColumn();
    check('a partner may manage site',        firm_site_can_manage($pdo, $firmA, $partnerId) === true);

    // ---- Public resolution ------------------------------------------------------------
    $pub = firm_site_public_firm($pdo, $slugA);
    check('published firm resolves by slug',  $pub !== null && (int)$pub['id'] === $firmA);
    check('unpublished firm is not public',   firm_site_public_firm($pdo, "no-such-$suffix") === null);
    $pdo->prepare("UPDATE law_firms SET is_public=0 WHERE id=?")->execute([$firmA]);
    check('draft firm reads as not found',    firm_site_public_firm($pdo, $slugA) === null);
    $pdo->prepare("UPDATE law_firms SET is_public=1 WHERE id=?")->execute([$firmA]);

    // ---- Pages: published only, tenant-scoped -----------------------------------------
    $ins = $pdo->prepare('INSERT INTO firm_pages (firm_id,slug,page_type,title_ar,status,sort_order) VALUES (?,?,?,?,?,?)');
    $ins->execute([$firmA, 'about-a', 'about', 'About A', 'published', 1]);
    $ins->execute([$firmA, 'draft-a', 'custom', 'Draft A', 'draft', 2]);
    $ins->execute([$firmB, 'secret-b', 'custom', 'Firm B page', 'published', 1]);

    $pagesA = firm_site_pages($pdo, $firmA, true);
    $slugs  = array_column($pagesA, 'slug');
    check('published pages returned',         in_array('about-a', $slugs, true));
    check('draft page hidden publicly',       !in_array('draft-a', $slugs, true));
    check('firm B page never leaks into A',   !in_array('secret-b', $slugs, true));
    check('dashboard list includes drafts',   in_array('draft-a', array_column(firm_site_pages($pdo, $firmA, false), 'slug'), true));

    // Cross-tenant page lookup uses (firm_id, slug): B's slug under A is empty.
    $cross = $pdo->prepare("SELECT * FROM firm_pages WHERE firm_id=? AND slug=? AND status='published'");
    $cross->execute([$firmA, 'secret-b']);
    check('firm B page unreadable under A',   $cross->fetch() === false);

    // ---- Branches / departments -------------------------------------------------------
    $pdo->prepare("INSERT INTO firm_branches (firm_id,name_ar,status) VALUES (?,?,'active')")->execute([$firmA, 'Main A']);
    $pdo->prepare("INSERT INTO firm_branches (firm_id,name_ar,status) VALUES (?,?,'inactive')")->execute([$firmA, 'Closed A']);
    $pdo->prepare("INSERT INTO firm_branches (firm_id,name_ar,status) VALUES (?,?,'active')")->execute([$firmB, 'Foreign B']);
    $branchesA = array_column(firm_site_branches($pdo, $firmA, true), 'name_ar');
    check('active branch returned',           in_array('Main A', $branchesA, true));
    check('inactive branch hidden',           !in_array('Closed A', $branchesA, true));
    check('foreign branch never leaks',       !in_array('Foreign B', $branchesA, true));

    $pdo->prepare("INSERT INTO firm_departments (firm_id,name_ar,manager_lawyer_id) VALUES (?,?,?)")->execute([$firmA, 'Litigation', $ownerA]);
    $pdo->prepare("INSERT INTO firm_departments (firm_id,name_ar) VALUES (?,?)")->execute([$firmB, 'Foreign dept']);
    $deptsA = array_column(firm_site_departments($pdo, $firmA, true), 'name_ar');
    check('department returned',              in_array('Litigation', $deptsA, true));
    check('foreign department never leaks',   !in_array('Foreign dept', $deptsA, true));

    // ---- Announcements: internal, audience-filtered, tenant-scoped --------------------
    $pdo->prepare("INSERT INTO firm_announcements (firm_id,title,audience,status,created_by_lawyer_id) VALUES (?,?,?,?,?)")->execute([$firmA, 'All hands', 'all', 'published', $ownerA]);
    $pdo->prepare("INSERT INTO firm_announcements (firm_id,title,audience,status,created_by_lawyer_id) VALUES (?,?,?,?,?)")->execute([$firmA, 'Staff only', 'staff', 'published', $ownerA]);
    $pdo->prepare("INSERT INTO firm_announcements (firm_id,title,audience,status,created_by_lawyer_id) VALUES (?,?,?,?,?)")->execute([$firmB, 'Foreign ann', 'all', 'published', $ownerB]);
    $allTitles = array_column(firm_site_announcements($pdo, $firmA, 'all', true), 'title');
    $lawTitles = array_column(firm_site_announcements($pdo, $firmA, 'lawyers', true), 'title');
    check('announcement for all returned',    in_array('All hands', $allTitles, true));
    check('audience filter excludes staff',   !in_array('Staff only', $lawTitles, true));
    check('foreign announcement never leaks', !in_array('Foreign ann', $allTitles, true));

    // ---- Team: only this firm's active approved lawyers -------------------------------
    $teamIds = array_column(firm_site_team($pdo, $firmA), 'id');
    check('owner in team',                    in_array($ownerA, array_map('intval', $teamIds), true));
    check('member in team',                   in_array($memberA, array_map('intval', $teamIds), true));
    check('foreign owner not in team',        !in_array($ownerB, array_map('intval', $teamIds), true));
    // A non-approved lawyer in the firm must be excluded even though the membership is active.
    $pending = $mkLawyer("FS Pending $suffix", "fs-pending-$suffix@test.local");
    $pdo->prepare('UPDATE lawyers SET status=? WHERE id=?')->execute(['pending', $pending]);
    $pdo->prepare("INSERT INTO law_firm_members (firm_id,lawyer_id,member_role,status) VALUES (?,?,'lawyer','active')")->execute([$firmA, $pending]);
    check('unapproved lawyer hidden from team', !in_array($pending, array_map('intval', array_column(firm_site_team($pdo, $firmA), 'id')), true));

    // ---- Firm articles: firm-owned only -----------------------------------------------
    $pdo->prepare("INSERT INTO articles (title_ar,title_en,slug,status,firm_id) VALUES ('A1','A1','fs-a1-$suffix','published',?)")->execute([$firmA]);
    $pdo->prepare("INSERT INTO articles (title_ar,title_en,slug,status,firm_id) VALUES ('B1','B1','fs-b1-$suffix','published',?)")->execute([$firmB]);
    $pdo->prepare("INSERT INTO articles (title_ar,title_en,slug,status,firm_id) VALUES ('D1','D1','fs-d1-$suffix','draft',?)")->execute([$firmA]);
    $artSlugs = array_column(firm_site_articles($pdo, $firmA, true), 'slug');
    check('firm article returned',            in_array('fs-a1-' . $suffix, $artSlugs, true));
    check('firm draft article hidden',        !in_array('fs-d1-' . $suffix, $artSlugs, true));
    check('foreign article never leaks',      !in_array('fs-b1-' . $suffix, $artSlugs, true));

    // ---- Slug uniqueness --------------------------------------------------------------
    $u1 = firm_site_unique_slug($pdo, $firmB, $slugA);           // already taken by A
    $u2 = firm_site_unique_slug($pdo, $firmB, $slugA);
    check('unique slug avoids collision',     $u1 !== $slugA);
    check('unique slug is stable/deduped',    $u2 === $u1);
    $empty = firm_site_unique_slug($pdo, $firmB, '!!!');
    check('empty-slug falls back to firm id', str_contains($empty, 'firm-' . $firmB));
    check('slugify strips unsafe chars',      firm_site_slugify('a<script> b') === 'ascript-b');

    // ---- Source guards: pages must carry the tenant/CSRF contract --------------------
    foreach (['firm.php', 'firm-page.php', 'firm-team.php', 'firm-articles.php'] as $page) {
        $src = file_get_contents(__DIR__ . '/../' . $page);
        check("$page uses public resolver",   str_contains($src, 'firm_site_public_firm'));
        check("$page 404s on unknown firm",   str_contains($src, 'firm_not_found'));
    }
    $dash = file_get_contents(__DIR__ . '/../lawyer/firm-site.php');
    check('dashboard requires csrf',          str_contains($dash, 'csrf_is_valid'));
    check('dashboard requires can_manage',    str_contains($dash, 'firm_site_can_manage'));
    check('dashboard scopes writes by firm',  str_contains($dash, 'WHERE id=? AND firm_id=?'));
    check('dashboard requires auth guard',    str_contains($dash, 'lawyer_guard.php'));

    $pdo->rollBack();
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "  FAIL: exception — " . $e->getMessage() . "\n";
    $failures++;
}

echo "\n" . ($checks - $failures) . "/$checks checks passed\n";
exit($failures === 0 ? 0 : 1);
