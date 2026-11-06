<?php
/**
 * ============================================================
 * /api/v1/lawyer-profile.php — ملف المحامي وإعداداته (Execution 57)
 * MAWATHEQ — the lawyer's professional profile and account settings
 *
 * GET  ?action=profile   → the profile, its specialties, availability, status
 * GET  ?action=settings  → notification preferences, privacy, sessions
 * GET  ?action=specialties → the specialty catalogue (for the picker)
 *
 * POST action=save-profile { full_name, phone?, specialization, bio?, ... }
 * POST action=save-specialties { category_ids[] }
 * POST action=save-availability { slots: [{day_of_week,start_time,end_time,slot_minutes}] }
 * POST action=save-settings { in_app?, email?, profile_visible? }
 * POST action=sign-out-all
 * POST action=change-password { current_password, new_password }
 *
 * ## What this endpoint deliberately does not do
 *
 * `status`, `email_verified` and the platform-visible fields are never writable from
 * here. A lawyer cannot approve themselves, and cannot un-verify an email that was
 * verified. Those are the admin console's decisions, and the columns are read-only on
 * this path by construction: the UPDATE names only the columns a lawyer owns.
 *
 * A specialty assignment is likewise a claim, not a grant — `lawyer_specialties` is
 * what the journey's matching reads, so a lawyer choosing a specialty they cannot
 * practise would route a real customer to them. The catalogue is read-only here.
 * ============================================================
 */
require_once __DIR__ . '/bootstrap.php';

$auth = api_authenticate($pdo);
if ($auth['type'] !== 'lawyer') api_error('this endpoint is for lawyers', 403);

$lawyerId = (int)$auth['id'];
$method = $_SERVER['REQUEST_METHOD'];
$in = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    $in = is_array($json) ? $json : $_POST;
}
$action = (string)($_GET['action'] ?? ($in['action'] ?? ''));

/** The profile row, or null. */
$profileRow = function () use ($pdo, $lawyerId): ?array {
    $stmt = $pdo->prepare('SELECT id, full_name, email, phone, specialization, bio,
        years_experience, license_number, photo_path, qualifications, social_linkedin,
        social_twitter, social_website, jurisdiction, hourly_rate, rating, status,
        email_verified, created_at
        FROM lawyers WHERE id=?');
    $stmt->execute([$lawyerId]);
    $row = $stmt->fetch();
    return $row ?: null;
};

/** The lawyer's claimed specialties, as ids. */
$specialtyIds = function () use ($pdo, $lawyerId): array {
    try {
        $stmt = $pdo->prepare('SELECT category_id FROM lawyer_specialties WHERE lawyer_id=?');
        $stmt->execute([$lawyerId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        // The table arrives with upgrade_v63; on an older database the profile still
        // loads, just with no specialties.
        return [];
    }
};

/** Weekly availability. */
$availability = function () use ($pdo, $lawyerId): array {
    try {
        $stmt = $pdo->prepare('SELECT day_of_week, start_time, end_time, slot_minutes
            FROM lawyer_availability WHERE lawyer_id=? ORDER BY day_of_week, start_time');
        $stmt->execute([$lawyerId]);
        return array_map(static fn($r) => [
            'day_of_week' => (int)$r['day_of_week'],
            'start_time' => substr((string)$r['start_time'], 0, 5),
            'end_time' => substr((string)$r['end_time'], 0, 5),
            'slot_minutes' => (int)$r['slot_minutes'],
        ], $stmt->fetchAll());
    } catch (Throwable $e) {
        return [];
    }
};

if ($method === 'GET') {
    switch ($action) {
        case '':
        case 'profile':
            $row = $profileRow();
            if ($row === null) api_error('not found', 404);
            api_json([
                'ok' => true,
                'profile' => [
                    'id' => (int)$row['id'],
                    'full_name' => (string)$row['full_name'],
                    'email' => (string)$row['email'],
                    'phone' => (string)($row['phone'] ?? ''),
                    'specialization' => (string)($row['specialization'] ?? ''),
                    'bio' => (string)($row['bio'] ?? ''),
                    'years_experience' => (int)($row['years_experience'] ?? 0),
                    'license_number' => (string)($row['license_number'] ?? ''),
                    'photo_path' => (string)($row['photo_path'] ?? ''),
                    'qualifications' => (string)($row['qualifications'] ?? ''),
                    'social_linkedin' => (string)($row['social_linkedin'] ?? ''),
                    'social_twitter' => (string)($row['social_twitter'] ?? ''),
                    'social_website' => (string)($row['social_website'] ?? ''),
                    'jurisdiction' => (string)($row['jurisdiction'] ?? ''),
                    'hourly_rate' => (float)($row['hourly_rate'] ?? 0),
                    'rating' => (float)($row['rating'] ?? 0),
                    // Read-only: the admin console owns both of these.
                    'status' => (string)$row['status'],
                    'email_verified' => (int)$row['email_verified'] === 1,
                    'created_at' => (string)$row['created_at'],
                ],
                'specialty_ids' => $specialtyIds(),
                'availability' => $availability(),
            ]);
            break;

        case 'specialties':
            // The customer-facing catalogue, read-only. It is the same table the
            // journey matches on, so an id chosen here means the same thing there.
            try {
                $rows = $pdo->query('SELECT id, name_ar, name_en FROM najiz_categories
                    WHERE is_active = 1 ORDER BY name_ar')->fetchAll();
                api_json([
                    'ok' => true,
                    'specialties' => array_map(static fn($r) => [
                        'id' => (int)$r['id'],
                        'name_ar' => (string)$r['name_ar'],
                        'name_en' => (string)($r['name_en'] ?? ''),
                    ], $rows),
                ]);
            } catch (Throwable $e) {
                // The taxonomy arrives with upgrade_v59 + the importer. An unseeded
                // install answers with an empty list, not a 500 — same rule the public
                // journey endpoint follows.
                api_json(['ok' => true, 'specialties' => []]);
            }
            break;

        case 'settings':
            $prefs = ['in_app' => true, 'email' => true];
            try {
                $stmt = $pdo->prepare("SELECT channel, enabled FROM notification_preferences
                    WHERE subject_type='lawyer' AND subject_id=? AND event_key='all'");
                $stmt->execute([$lawyerId]);
                foreach ($stmt->fetchAll() as $p) {
                    $prefs[(string)$p['channel']] = ((int)$p['enabled'] === 1);
                }
            } catch (Throwable $e) {
                // Defaults stand.
            }
            $profileVisible = true;
            try {
                $stmt = $pdo->prepare("SELECT granted FROM privacy_consents
                    WHERE subject_type='lawyer' AND subject_id=? AND consent_type='profile_visible'
                    AND policy_version='v1'");
                $stmt->execute([$lawyerId]);
                $row = $stmt->fetch();
                if ($row !== false) $profileVisible = ((int)$row['granted'] === 1);
            } catch (Throwable $e) {
                // Default stands.
            }
            $sessions = 0;
            try {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM api_tokens
                    WHERE user_type=? AND user_id=? AND revoked_at IS NULL');
                $stmt->execute(['lawyer', $lawyerId]);
                $sessions = (int)$stmt->fetchColumn();
            } catch (Throwable $e) {
                // Best effort.
            }
            api_json([
                'ok' => true,
                'settings' => [
                    'notify_in_app' => $prefs['in_app'],
                    'notify_email' => $prefs['email'],
                    'profile_visible' => $profileVisible,
                    'active_sessions' => $sessions,
                ],
            ]);
            break;

        default:
            api_error('unknown action', 400);
    }
    exit;
}

if ($method !== 'POST') api_error('method not allowed', 405);

switch ($action) {
    case 'save-profile': {
        $name = trim((string)($in['full_name'] ?? ''));
        $specialization = trim((string)($in['specialization'] ?? ''));
        if ($name === '') api_error('full_name is required', 422);
        if ($specialization === '') api_error('specialization is required', 422);

        $phone = trim((string)($in['phone'] ?? ''));
        $bio = trim((string)($in['bio'] ?? ''));
        $years = max(0, min(70, (int)($in['years_experience'] ?? 0)));
        $license = trim((string)($in['license_number'] ?? ''));
        $qualifications = trim((string)($in['qualifications'] ?? ''));
        $linkedin = trim((string)($in['social_linkedin'] ?? ''));
        $twitter = trim((string)($in['social_twitter'] ?? ''));
        $website = trim((string)($in['social_website'] ?? ''));
        $jurisdiction = trim((string)($in['jurisdiction'] ?? ''));
        $rate = (float)($in['hourly_rate'] ?? 0);
        // `lawyers.jurisdiction` is NOT NULL with a default. An omitted field must keep
        // the stored value rather than write NULL, which the column rejects — the
        // difference between "the client did not send it" and "the client cleared it"
        // matters here.
        if ($jurisdiction === '') {
            $current = $pdo->prepare('SELECT jurisdiction FROM lawyers WHERE id=?');
            $current->execute([$lawyerId]);
            $jurisdiction = (string)($current->fetchColumn() ?: '');
            if ($jurisdiction === '') $jurisdiction = 'السعودية';
        }

        // Only the columns a lawyer owns. `status` and `email_verified` are absent by
        // construction, which is what makes them unwritable here.
        $pdo->prepare('UPDATE lawyers SET full_name=?, phone=?, specialization=?, bio=?,
            years_experience=?, license_number=?, qualifications=?, social_linkedin=?,
            social_twitter=?, social_website=?, jurisdiction=?, hourly_rate=?,
            updated_at=NOW() WHERE id=?')
            ->execute([
                $name,
                $phone !== '' ? $phone : null,
                $specialization,
                $bio !== '' ? $bio : null,
                $years,
                $license !== '' ? $license : null,
                $qualifications !== '' ? $qualifications : null,
                $linkedin !== '' ? $linkedin : null,
                $twitter !== '' ? $twitter : null,
                $website !== '' ? $website : null,
                $jurisdiction,
                $rate > 0 ? $rate : 0,
                $lawyerId,
            ]);
        api_json(['ok' => true]);
        break;
    }

    case 'save-specialties': {
        $ids = $in['category_ids'] ?? [];
        if (!is_array($ids)) api_error('category_ids must be an array', 422);
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($i) => $i > 0)));

        try {
            // Verify every id is a real, active category before storing any of them:
            // a stale or tampered id must not create a match the journey would then
            // act on.
            $valid = [];
            if ($ids) {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $pdo->prepare("SELECT id FROM najiz_categories WHERE is_active=1 AND id IN ($ph)");
                $stmt->execute($ids);
                $valid = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            }
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM lawyer_specialties WHERE lawyer_id=?')->execute([$lawyerId]);
            $insert = $pdo->prepare('INSERT INTO lawyer_specialties (lawyer_id, category_id) VALUES (?,?)');
            foreach ($valid as $cid) {
                $insert->execute([$lawyerId, $cid]);
            }
            $pdo->commit();
            api_json(['ok' => true, 'saved' => count($valid)]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            api_error('could not save specialties', 500);
        }
        break;
    }

    case 'save-availability': {
        $slots = $in['slots'] ?? [];
        if (!is_array($slots)) api_error('slots must be an array', 422);
        $clean = [];
        foreach ($slots as $slot) {
            if (!is_array($slot)) continue;
            $day = (int)($slot['day_of_week'] ?? -1);
            $start = (string)($slot['start_time'] ?? '');
            $end = (string)($slot['end_time'] ?? '');
            $minutes = (int)($slot['slot_minutes'] ?? 30);
            if ($day < 0 || $day > 6) continue;
            if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) continue;
            if ($start >= $end) continue;
            $clean[] = [$day, $start, $end, max(5, min(240, $minutes))];
        }
        try {
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM lawyer_availability WHERE lawyer_id=?')->execute([$lawyerId]);
            $insert = $pdo->prepare('INSERT INTO lawyer_availability
                (lawyer_id, day_of_week, start_time, end_time, slot_minutes) VALUES (?,?,?,?,?)');
            foreach ($clean as [$day, $start, $end, $minutes]) {
                $insert->execute([$lawyerId, $day, $start, $end, $minutes]);
            }
            $pdo->commit();
            api_json(['ok' => true, 'saved' => count($clean)]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            api_error('could not save availability', 500);
        }
        break;
    }

    case 'save-settings': {
        $inApp = !empty($in['in_app']);
        $email = !empty($in['email']);
        try {
            $stmt = $pdo->prepare("INSERT INTO notification_preferences
                (subject_type, subject_id, channel, event_key, enabled)
                VALUES ('lawyer', ?, ?, 'all', ?)
                ON DUPLICATE KEY UPDATE enabled=VALUES(enabled)");
            $stmt->execute([$lawyerId, 'in_app', $inApp ? 1 : 0]);
            $stmt->execute([$lawyerId, 'email', $email ? 1 : 0]);
        } catch (Throwable $e) {
            api_error('could not save notification preferences', 500);
        }
        if (array_key_exists('profile_visible', $in)) {
            $granted = !empty($in['profile_visible']);
            try {
                $pdo->prepare("INSERT INTO privacy_consents
                    (subject_type, subject_id, consent_type, policy_version, granted, granted_at, ip)
                    VALUES ('lawyer', ?, 'profile_visible', 'v1', ?, ?, ?)
                    ON DUPLICATE KEY UPDATE granted=VALUES(granted), granted_at=VALUES(granted_at)")
                    ->execute([
                        $lawyerId,
                        $granted ? 1 : 0,
                        $granted ? date('Y-m-d H:i:s') : null,
                        // The IP is stored on the consent row; `privacy_consents.ip` is
                        // wide enough for a literal, so the raw address is right here.
                        $_SERVER['REMOTE_ADDR'] ?? null,
                    ]);
            } catch (Throwable $e) {
                api_error('could not save privacy preference', 500);
            }
        }
        api_json(['ok' => true]);
        break;
    }

    case 'change-password': {
        $current = (string)($in['current_password'] ?? '');
        $new = (string)($in['new_password'] ?? '');
        if ($current === '') api_error('current_password is required', 422);
        if (strlen($new) < 8) api_error('new_password must be at least 8 characters', 422);

        $stmt = $pdo->prepare('SELECT password_hash FROM lawyers WHERE id=?');
        $stmt->execute([$lawyerId]);
        $hash = (string)$stmt->fetchColumn();
        if ($hash === '' || !password_verify($current, $hash)) {
            api_error('current password is incorrect', 403);
        }
        $pdo->prepare('UPDATE lawyers SET password_hash=?, updated_at=NOW() WHERE id=?')
            ->execute([password_hash($new, PASSWORD_BCRYPT), $lawyerId]);
        // Changing a password invalidates tokens issued before it, so a session that
        // was already stolen cannot outlive the change.
        $pdo->prepare('UPDATE api_tokens SET revoked_at=NOW() WHERE user_type=? AND user_id=? AND revoked_at IS NULL')
            ->execute(['lawyer', $lawyerId]);
        api_json(['ok' => true]);
        break;
    }

    case 'sign-out-all': {
        $pdo->prepare('UPDATE api_tokens SET revoked_at=NOW() WHERE user_type=? AND user_id=? AND revoked_at IS NULL')
            ->execute(['lawyer', $lawyerId]);
        $pdo->prepare('UPDATE lawyers SET session_invalidated_at=NOW() WHERE id=?')->execute([$lawyerId]);
        api_json(['ok' => true]);
        break;
    }

    default:
        api_error('unknown action', 400);
}
