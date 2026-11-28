<?php
/**
 * GET  /api/v1/templates.php                  → قائمة القوالب المتاحة للمحامي
 * GET  /api/v1/templates.php?id=12            → قالب واحد مع حقوله
 * GET  /api/v1/templates.php?scope=categories → تصنيفات القوالب
 *
 * All reads go through `includes/template_library.php` rather than querying
 * `document_templates` here. The library owns the tier gate (`template_can_use()` against the
 * firm's plan tier) and the field parser, so the app cannot offer a template the web page
 * would refuse, and a `paid` template on a `free` firm is reported as locked rather than
 * simply missing — the same distinction `lawyer/templates.php` makes.
 *
 * Rendering (Word/PDF export) stays on the web: it writes a file and streams it, which is not
 * a JSON operation. The app opens the web page for export.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/tenant.php';
require_once __DIR__ . '/../../includes/template_library.php';

$auth = api_authenticate($pdo);
if (($auth['type'] ?? '') !== 'lawyer') {
    api_error('هذا العنصر متاح للمحامين فقط.', 403);
}
$lawyerId = (int)$auth['id'];
$ctx = tenant_context_for_lawyer($pdo, $lawyerId);
$firmId = !empty($ctx['firm_id']) ? (int)$ctx['firm_id'] : null;

if (!template_library_ready($pdo)) {
    // An install that has not run the template migration answers with an empty catalogue
    // instead of a 500, matching how the web page degrades.
    api_json(['ok' => true, 'ready' => false, 'templates' => [], 'categories' => []]);
}

$scope = (string)($_GET['scope'] ?? '');
if ($scope === 'categories') {
    api_json(['ok' => true, 'ready' => true, 'categories' => template_categories_all($pdo)]);
}

$templateId = (int)($_GET['id'] ?? 0);
if ($templateId > 0) {
    $template = template_get($pdo, $templateId, $firmId);
    if (!$template) api_error('القالب غير موجود.', 404);
    $firmTier = template_tier_for_firm($pdo, $firmId);
    $template['can_use'] = template_can_use($template, $firmTier);
    $template['fields'] = template_fields($template);
    api_json(['ok' => true, 'ready' => true, 'template' => $template]);
}

$firmTier = template_tier_for_firm($pdo, $firmId);
$templates = template_list($pdo, $firmId, [
    'category' => (string)($_GET['category'] ?? ''),
    'q' => trim((string)($_GET['q'] ?? '')),
]);
foreach ($templates as &$t) {
    $t['can_use'] = template_can_use($t, $firmTier);
}
unset($t);

api_json([
    'ok' => true,
    'ready' => true,
    'tier' => $firmTier,
    'categories' => template_categories_all($pdo),
    'templates' => $templates,
]);
