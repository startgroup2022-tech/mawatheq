<?php
/**
 * GET /api/v1/banners.php — بانرات الصفحة الرئيسية (السلايدر).
 *
 * Public (no token): the home slider is the first thing a visitor sees, before any
 * account exists. Nothing private is returned — only banners an operator marked
 * `active` whose scheduling window (if any) is currently open.
 *
 *   response: { ok, banners: [ { id, title_ar, title_en, subtitle_ar, subtitle_en,
 *                                image_path, alt_ar, alt_en,
 *                                link_type, link_target } ] }
 *
 * `image_path` is relative (`uploads/banners/….jpg`); the app resolves it against the
 * origin it was built with, so a deployment on another host is not sent the wrong
 * absolute URL.
 *
 * The endpoint carries no internal classification vocabulary: a `specialty` banner
 * holds the numeric id of a customer-facing specialty card, which the app resolves
 * through journey.php. See the confidentiality note in api/v1/journey.php.
 *
 * An empty list is a valid answer and the expected one on a fresh install: the brief
 * forbids fabricating promotions, so the client shows its empty state instead.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/banners.php';

try {
    $rows = banner_active_rows($pdo);
} catch (Throwable $e) {
    // The table may not exist yet on a database that has not received upgrade_v64.sql.
    // That is an empty slider, not a broken home screen: answer with an empty list so
    // the app degrades to its empty state instead of failing the whole screen.
    error_log('api/v1/banners: ' . $e->getMessage());
    api_json(['ok' => true, 'banners' => []]);
}

$banners = [];
foreach ($rows as $row) {
    $banners[] = banner_public_row($pdo, $row);
}

api_json(['ok' => true, 'banners' => $banners]);
