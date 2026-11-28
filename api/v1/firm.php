<?php
/**
 * GET  /api/v1/firm.php            → مكتب المحاماة الذي ينتمي إليه المحامي (أو null)
 * GET  /api/v1/firm.php?scope=members → أعضاء المكتب
 *
 * The lawyer workspace has had a firm page on the web since v50; the mobile app had no way
 * to read it at all. This endpoint is read-only on purpose: creating a firm, inviting a
 * member and changing a role are owner/partner actions with audit consequences, and the web
 * page already implements every guard. A mobile write path would need to duplicate those
 * guards, so the app links to the web page for changes and reads here.
 *
 * Firm membership is resolved from the session's lawyer id — never from a request parameter.
 * A lawyer who belongs to no firm gets `firm: null` and the app renders the "no firm" state
 * rather than an error, which matches `lawyer/firm.php` (a solo lawyer uses it to create one).
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/tenant.php';

$auth = api_authenticate($pdo);
if (($auth['type'] ?? '') !== 'lawyer') {
    api_error('هذا العنصر متاح للمحامين فقط.', 403);
}
$lawyerId = (int)$auth['id'];

$stmt = $pdo->prepare(
    'SELECT f.id, f.name, f.legal_name, f.display_name, f.email, f.phone, f.city, f.address,
            f.logo_path, f.about_ar, f.about_en, f.website_url, f.public_slug, f.is_public,
            m.member_role, m.joined_at, m.branch_id
       FROM law_firm_members m
       JOIN law_firms f ON f.id = m.firm_id
      WHERE m.lawyer_id = ? AND m.status = \'active\'
      LIMIT 1'
);
$stmt->execute([$lawyerId]);
$firm = $stmt->fetch();

if (!$firm) {
    api_json(['ok' => true, 'firm' => null, 'members' => [], 'can_manage' => false]);
}

$firmId = (int)$firm['id'];
$role = (string)$firm['member_role'];

// The same capability helper the web page uses, so the app cannot offer an action the
// server would refuse. `firm_site_can_manage()` is owner/partner only.
require_once __DIR__ . '/../../includes/firm_site.php';
$canManage = function_exists('firm_site_can_manage') && firm_site_can_manage($pdo, $firmId, $lawyerId);

$firm['id'] = $firmId;
$firm['is_public'] = (bool)$firm['is_public'];

$members = [];
if (($_GET['scope'] ?? '') === 'members') {
    // Contact details of colleagues are appropriate inside the firm; the public team page is
    // the surface that must not expose them, and that is a different query.
    $m = $pdo->prepare(
        'SELECT m.id, m.member_role, m.joined_at, l.id AS lawyer_id, l.full_name,
                l.specialization, l.email
           FROM law_firm_members m
           JOIN lawyers l ON l.id = m.lawyer_id
          WHERE m.firm_id = ? AND m.status = \'active\'
          ORDER BY FIELD(m.member_role, \'owner\', \'partner\', \'lawyer\', \'staff\'), l.full_name'
    );
    $m->execute([$firmId]);
    $members = $m->fetchAll();
}

api_json([
    'ok' => true,
    'firm' => $firm,
    'role' => $role,
    'can_manage' => $canManage,
    'members' => $members,
]);
