<?php
/**
 * Execution 72 — customer engagement features.
 *
 * Six surfaces that let a customer act on a lawyer rather than only read about one, and
 * the lawyer panel that answers them:
 *
 *   1.  Favorites            — save/unsave, per client, deduped, never cross-tenant.
 *   2.  Verified reviews     — only a completed, owned consultation can be rated, once.
 *   3.  Review moderation    — reporting flags a review; admin status transitions work.
 *   4.  Availability alerts  — subscribe/cancel deduped per pair; dispatch notifies once.
 *   5.  Contact requests     — the customer's message reaches the lawyer's inbox, unread.
 *   6.  Consultation modes   — remote/office storage, labels and the language catalogue.
 *   7.  Notification prefs   — the channel catalogue, per-user state, the in-app lock.
 *   8.  Device tokens        — register/unregister, deduped, counted per account.
 *
 * Everything it creates is deleted in cleanup, so it is re-runnable and leaves no residue.
 * Runs against the configured database (see tests/setup_test_env.sh); it does not need the
 * HTTP server because every assertion is a direct call to the shared service functions the
 * website and the app both use.
 *
 * Run: php tests/exec72_customer_features_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/customer_features.php';
require_once __DIR__ . '/../includes/notifications.php';

$checks = 0;
$failures = 0;
function check(string $label, bool $ok): void {
    global $checks, $failures;
    $checks++;
    if ($ok) { echo "  ok:   $label\n"; }
    else { $failures++; echo "  FAIL: $label\n"; }
}
function section(string $t): void { echo "\n$t\n"; }

$suffix = bin2hex(random_bytes(4));
$created = ['users' => [], 'lawyers' => [], 'consultations' => [], 'reviews' => [], 'requests' => []];

// ---------------------------------------------------------------------------
section('Setup — seed a client, a lawyer and their consultation');
// ---------------------------------------------------------------------------

$clientEmail = "exec72-client-$suffix@example.test";
$pdo->prepare("INSERT INTO users (full_name, email, password_hash, status, email_verified, created_at) VALUES (?,?,?, 'active', 1, NOW())")
    ->execute(['عميل اختبار', $clientEmail, password_hash('x', PASSWORD_DEFAULT)]);
$clientId = (int)$pdo->lastInsertId();
$created['users'][] = $clientId;

$otherEmail = "exec72-other-$suffix@example.test";
$pdo->prepare("INSERT INTO users (full_name, email, password_hash, status, email_verified, created_at) VALUES (?,?,?, 'active', 1, NOW())")
    ->execute(['عميل آخر', $otherEmail, password_hash('x', PASSWORD_DEFAULT)]);
$otherClientId = (int)$pdo->lastInsertId();
$created['users'][] = $otherClientId;

$lawyerEmail = "exec72-lawyer-$suffix@example.test";
$pdo->prepare("INSERT INTO lawyers (full_name, email, password_hash, status, email_verified, specialization, jurisdiction, country_code, city, years_experience, rating, created_at)
               VALUES (?,?,?, 'approved', 1, 'قانون تجاري', 'السعودية', 'SA', 'الرياض', 8, 0, NOW())")
    ->execute(['محامٍ اختبار', $lawyerEmail, password_hash('x', PASSWORD_DEFAULT)]);
$lawyerId = (int)$pdo->lastInsertId();
$created['lawyers'][] = $lawyerId;

// A completed consultation the client owns — the only thing a review may be built on.
$pdo->prepare("INSERT INTO consultations (user_id, lawyer_id, consultation_type, scheduled_at, duration_minutes, topic, status, created_at)
               VALUES (?,?, 'video', DATE_SUB(NOW(), INTERVAL 3 DAY), 30, 'مراجعة عقد', 'completed', NOW())")
    ->execute([$clientId, $lawyerId]);
$consultationId = (int)$pdo->lastInsertId();
$created['consultations'][] = $consultationId;

check('client seeded', $clientId > 0);
check('lawyer seeded and public', lawyer_is_public($pdo, $lawyerId));

// ---------------------------------------------------------------------------
section('1. Favorites — save, unsave, per-client, cross-tenant safe');
// ---------------------------------------------------------------------------

check('a fresh pair is not favorited', !favorite_exists($pdo, $clientId, $lawyerId));

$state = favorite_toggle($pdo, $clientId, $lawyerId);
check('toggle adds the favorite', $state === true && favorite_exists($pdo, $clientId, $lawyerId));

$state = favorite_toggle($pdo, $clientId, $lawyerId);
check('toggling again removes it', $state === false && !favorite_exists($pdo, $clientId, $lawyerId));

favorite_add($pdo, $clientId, $lawyerId);
// Adding twice must not create two rows (the UNIQUE key guards it).
favorite_add($pdo, $clientId, $lawyerId);
$dup = $pdo->prepare('SELECT COUNT(*) FROM lawyer_favorites WHERE client_id=? AND lawyer_id=?');
$dup->execute([$clientId, $lawyerId]);
check('adding twice keeps exactly one row', (int)$dup->fetchColumn() === 1);

$ids = favorite_ids_for_client($pdo, $clientId);
check('the id list contains the saved lawyer', in_array($lawyerId, $ids, true));

$list = favorites_for_client($pdo, $clientId);
check('the favorites list hydrates the lawyer', count($list) === 1 && (int)$list[0]['id'] === $lawyerId);
check('the hydrated card carries the Execution 72 fields', isset($list[0]['mode_chips'], $list[0]['languages']));

// A different client must not see this favorite.
check('another client sees no favorites', favorite_ids_for_client($pdo, $otherClientId) === []);

check('count reflects one saver', favorite_count_for_lawyer($pdo, $lawyerId) === 1);
favorite_remove($pdo, $clientId, $lawyerId);
check('remove clears it', !favorite_exists($pdo, $clientId, $lawyerId));

// ---------------------------------------------------------------------------
section('2. Verified reviews — only a completed owned consultation, once');
// ---------------------------------------------------------------------------

$notCompleted = review_eligibility($pdo, $clientId, $consultationId);
check('a completed consultation is reviewable', $notCompleted['ok'] === true);

$res = review_create($pdo, $clientId, $consultationId, 5, 'استشارة ممتازة');
check('the review is created', $res['ok'] === true && $res['id'] > 0);
if ($res['ok']) $created['reviews'][] = (int)$res['id'];

$again = review_create($pdo, $clientId, $consultationId, 4, 'محاولة ثانية');
check('a second review on the same consultation is refused', $again['ok'] === false && $again['error'] === 'already_reviewed');

// A client cannot review a consultation they do not own.
$foreign = review_create($pdo, $otherClientId, $consultationId, 5, 'لست صاحبها');
check('a non-owner cannot review the consultation', $foreign['ok'] === false && $foreign['error'] === 'not_found');

// A pending consultation cannot be reviewed.
$pdo->prepare("INSERT INTO consultations (user_id, lawyer_id, consultation_type, scheduled_at, duration_minutes, topic, status, created_at)
               VALUES (?,?, 'video', NOW(), 30, 'قيد الانتظار', 'pending', NOW())")
    ->execute([$clientId, $lawyerId]);
$pendingId = (int)$pdo->lastInsertId();
$created['consultations'][] = $pendingId;
$pending = review_create($pdo, $clientId, $pendingId, 5, 'قبل الإكمال');
check('a pending consultation cannot be reviewed', $pending['ok'] === false && $pending['error'] === 'not_completed');

$summary = review_summary_for_lawyer($pdo, $lawyerId);
check('the summary counts the review', $summary['count'] === 1);
check('the summary averages the rating', abs(((float)$summary['average']) - 5.0) < 0.01);
check('the distribution lands on 5 stars', (int)$summary['distribution'][5] === 1);

// The cached lawyers.rating is recomputed to match the reviews.
$cached = $pdo->prepare('SELECT rating FROM lawyers WHERE id=?');
$cached->execute([$lawyerId]);
check('the lawyer rating cache is recomputed', abs(((float)$cached->fetchColumn()) - 5.0) < 0.01);

$published = reviews_for_lawyer($pdo, $lawyerId);
check('the public list returns the published review', count($published) === 1);
check('only the first name is exposed', ($published[0]['display_name'] ?? '') === 'عميل');

check('reviewable list excludes the already-reviewed consultation',
    !in_array($consultationId, array_column(reviewable_consultations($pdo, $clientId), 'id'), true));

// ---------------------------------------------------------------------------
section('3. Review moderation — report, status transitions');
// ---------------------------------------------------------------------------

$reviewId = $created['reviews'][0];
check('report returns true', review_report($pdo, $reviewId, $otherClientId) === true);
$rc = $pdo->prepare('SELECT report_count FROM lawyer_reviews WHERE id=?');
$rc->execute([$reviewId]);
check('the report count increments', (int)$rc->fetchColumn() === 1);

check('admin can hide the review', review_set_status($pdo, $reviewId, 'hidden', 'مخالف'));
$st = $pdo->prepare('SELECT status FROM lawyer_reviews WHERE id=?');
$st->execute([$reviewId]);
check('the status is hidden', $st->fetchColumn() === 'hidden');
check('a hidden review leaves the public list', count(reviews_for_lawyer($pdo, $lawyerId)) === 0);

// Hiding it recomputes the cache back to zero.
$cached->execute([$lawyerId]);
check('hiding recomputes the rating cache', (float)$cached->fetchColumn() === 0.0);

check('admin can restore it to published', review_set_status($pdo, $reviewId, 'published'));
$st->execute([$reviewId]);
check('the status is published again', $st->fetchColumn() === 'published');

$admin = reviews_for_admin($pdo, 'published', 500);
check('the admin list contains the review', in_array($reviewId, array_column($admin, 'id'), true));

// ---------------------------------------------------------------------------
section('4. Availability alerts — subscribe, cancel, single dispatch');
// ---------------------------------------------------------------------------

$sub = availability_alert_subscribe($pdo, $clientId, $lawyerId);
check('subscribe returns active', ($sub['status'] ?? '') === 'active');
$sub2 = availability_alert_subscribe($pdo, $clientId, $lawyerId);
check('subscribing twice is idempotent', ($sub2['status'] ?? '') === 'active');

// Silence the email channel for this client so the dispatch does not attempt a real
// send during the test; the in-app bell is the channel under test.
notification_preference_set($pdo, 'client', $clientId, 'email', false);

$cnt = $pdo->prepare('SELECT COUNT(*) FROM lawyer_availability_alerts WHERE client_id=? AND lawyer_id=?');
$cnt->execute([$clientId, $lawyerId]);
check('exactly one alert row exists', (int)$cnt->fetchColumn() === 1);

// A lawyer with no published hours has no open slot.
check('a lawyer with no hours has no open slot', !lawyer_has_open_slot($pdo, $lawyerId));

// Give the lawyer published hours so a slot exists, then confirm the sweep finds it.
$pdo->prepare('INSERT INTO lawyer_availability (lawyer_id, day_of_week, start_time, end_time, slot_minutes) VALUES (?,?,?,?,30)')
    ->execute([$lawyerId, (int)date('w'), '09:00:00', '17:00:00']);
check('a lawyer with hours has an open slot', lawyer_has_open_slot($pdo, $lawyerId));

$notified = availability_alerts_dispatch($pdo, $lawyerId);
check('the dispatch notifies the subscriber', $notified === 1);
// A second dispatch must not notify again (the row is fulfilled).
$notified2 = availability_alerts_dispatch($pdo, $lawyerId);
check('a second dispatch does not re-notify', $notified2 === 0);

$alerts = availability_alerts_for_client($pdo, $clientId);
check('the client list shows the alert', count($alerts) === 1);

$cancel = availability_alert_cancel($pdo, $clientId, $lawyerId);
check('cancel returns cancelled', ($cancel['status'] ?? '') === 'cancelled');
$afterCancel = availability_alert_for($pdo, $clientId, $lawyerId);
check('a cancelled alert is no longer active', ($afterCancel['status'] ?? '') !== 'active');

$pdo->prepare('DELETE FROM lawyer_availability WHERE lawyer_id=?')->execute([$lawyerId]);

// ---------------------------------------------------------------------------
section('5. Contact requests — customer writes, lawyer inbox reads');
// ---------------------------------------------------------------------------

$cr = contact_request_create($pdo, $clientId, $lawyerId, 'استفسار', 'هل يمكنك مراجعة عقد الإيجار؟');
check('the contact request is created', ($cr['ok'] ?? false) === true && ($cr['id'] ?? 0) > 0);
$requestId = (int)($cr['id'] ?? 0);
if ($requestId > 0) $created['requests'][] = $requestId;

$empty = contact_request_create($pdo, $clientId, $lawyerId, '', '');
check('an empty message is refused', ($empty['ok'] ?? true) === false);

$inbox = contact_requests_for_lawyer($pdo, $lawyerId);
check('the lawyer inbox contains the request', count($inbox) === 1 && (int)$inbox[0]['id'] === $requestId);
check('the unread count is one', contact_requests_unread_count($pdo, $lawyerId) === 1);

check('marking read returns true', contact_request_mark_read($pdo, $lawyerId, $requestId) === true);
check('the unread count drops to zero', contact_requests_unread_count($pdo, $lawyerId) === 0);
check('another lawyer cannot mark it read', contact_request_mark_read($pdo, $otherClientId, $requestId) === false);

$sent = contact_requests_for_client($pdo, $clientId);
check('the client sees the request they sent', count($sent) === 1);

// ---------------------------------------------------------------------------
section('6. Consultation modes and spoken languages');
// ---------------------------------------------------------------------------

$pdo->prepare("UPDATE lawyers SET offers_remote=1, offers_in_office=1, office_address='الرياض - العليا', languages='ar,en' WHERE id=?")
    ->execute([$lawyerId]);
$lw = $pdo->prepare('SELECT * FROM lawyers WHERE id=?');
$lw->execute([$lawyerId]);
$lwRow = $lw->fetch(PDO::FETCH_ASSOC);

$modes = lawyer_consultation_modes($lwRow);
check('both modes are reported', count($modes) === 2);
$chips = lawyer_consultation_mode_chips($lwRow);
check('two mode chips are produced', count($chips) === 2);
check('the lawyer offers office visits', lawyer_offers_office($lwRow) === true);

$labels = lawyer_languages_labels($lwRow['languages']);
check('two language labels are produced', count($labels) === 2);
check('the catalogue normalises unknown codes away', lawyer_languages_normalize('ar,xx,en') === 'ar,en');
check('an empty language string normalises to empty', lawyer_languages_normalize('') === '');

// The language predicate builds a bound SQL fragment, never a raw code.
[$pred, $params] = lawyer_language_predicate('l', 'ar');
check('the language predicate binds the code', str_contains($pred, '?') && $params === ['%,ar,%']);

// ---------------------------------------------------------------------------
section('7. Notification preferences — catalogue, state, in-app lock');
// ---------------------------------------------------------------------------

check('the catalogue is in_app/email/push', notification_channels_catalog() === ['in_app', 'email', 'push']);
// Use the untouched second client: the first client's email channel was silenced in
// section 4 to keep the alert dispatch from attempting a real send.
$prefClient = $otherClientId;
check('in_app is on by default', notification_preference_enabled($pdo, 'client', $prefClient, 'in_app') === true);
check('email defaults to on', notification_preference_enabled($pdo, 'client', $prefClient, 'email') === true);

notification_preference_set($pdo, 'client', $prefClient, 'email', false);
check('email can be turned off', notification_preference_enabled($pdo, 'client', $prefClient, 'email') === false);
check('the channel gate refuses a disabled channel', notification_channel_allows($pdo, 'client', $prefClient, 'email') === false);

// in_app is locked on: setting it false must not take effect.
notification_preference_set($pdo, 'client', $prefClient, 'in_app', false);
check('the in-app channel cannot be turned off', notification_preference_enabled($pdo, 'client', $prefClient, 'in_app') === true);

$payload = notification_preferences_payload($pdo, 'client', $prefClient);
check('the payload lists three channels', count($payload) === 3);
$inAppRow = null;
foreach ($payload as $row) { if ($row['key'] === 'in_app') $inAppRow = $row; }
check('the in-app channel is marked locked', ($inAppRow['locked'] ?? false) === true);
check('every channel carries both labels', isset($payload[0]['label_ar'], $payload[0]['label_en']));

// ---------------------------------------------------------------------------
section('8. Device tokens — register, dedupe, unregister');
// ---------------------------------------------------------------------------

$tok = 'exec72-device-' . $suffix;
check('registering a token returns true', device_token_register($pdo, 'client', $clientId, $tok, 'android') === true);
device_token_register($pdo, 'client', $clientId, $tok, 'android');
check('registering the same token twice keeps one row', device_token_count($pdo, 'client', $clientId) === 1);
device_token_register($pdo, 'client', $clientId, $tok . '-b', 'android');
check('a second device is counted', device_token_count($pdo, 'client', $clientId) === 2);
device_token_unregister($pdo, 'client', $clientId, $tok);
check('unregistering removes one device', device_token_count($pdo, 'client', $clientId) === 1);

// A push enqueue is created only when the push channel allows it and a device exists.
notification_preference_set($pdo, 'client', $clientId, 'push', true);
push_delivery_enqueue($pdo, 'client', $clientId, 'عنوان', 'نص', null);
$queued = $pdo->prepare('SELECT COUNT(*) FROM push_deliveries WHERE subject_type=? AND subject_id=?');
$queued->execute(['client', $clientId]);
check('a push delivery is queued', (int)$queued->fetchColumn() >= 1);

// ---------------------------------------------------------------------------
section('9. Cleanup');
// ---------------------------------------------------------------------------

foreach ($created['requests'] as $rid) $pdo->prepare('DELETE FROM lawyer_contact_requests WHERE id=?')->execute([$rid]);
$pdo->prepare('DELETE FROM lawyer_contact_requests WHERE lawyer_id=?')->execute([$lawyerId]);
foreach ($created['reviews'] as $rid) $pdo->prepare('DELETE FROM lawyer_reviews WHERE id=?')->execute([$rid]);
$pdo->prepare('DELETE FROM lawyer_reviews WHERE lawyer_id=?')->execute([$lawyerId]);
$pdo->prepare('DELETE FROM lawyer_availability_alerts WHERE lawyer_id=? OR client_id IN (?,?)')->execute([$lawyerId, $clientId, $otherClientId]);
$pdo->prepare('DELETE FROM lawyer_favorites WHERE lawyer_id=? OR client_id IN (?,?)')->execute([$lawyerId, $clientId, $otherClientId]);
$pdo->prepare('DELETE FROM push_deliveries WHERE subject_type=? AND subject_id IN (?,?)')->execute(['client', $clientId, $otherClientId]);
$pdo->prepare('DELETE FROM device_tokens WHERE subject_id IN (?,?)')->execute([$clientId, $otherClientId]);
$pdo->prepare('DELETE FROM notification_preferences WHERE subject_id IN (?,?)')->execute([$clientId, $otherClientId]);
foreach ($created['consultations'] as $cid) $pdo->prepare('DELETE FROM consultations WHERE id=?')->execute([$cid]);
foreach ($created['lawyers'] as $lid) {
    $pdo->prepare('DELETE FROM lawyer_specialties WHERE lawyer_id=?')->execute([$lid]);
    $pdo->prepare('DELETE FROM lawyers WHERE id=?')->execute([$lid]);
}
foreach ($created['users'] as $uid) $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$uid]);

$left = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email LIKE ?');
$left->execute(["exec72-%$suffix%"]);
check('every seeded account was removed', (int)$left->fetchColumn() === 0);

echo "\n" . $checks . " checks, " . $failures . " failure(s)\n";
exit($failures > 0 ? 1 : 0);
