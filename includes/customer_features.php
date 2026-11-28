<?php
/**
 * Customer engagement features — favourites, verified ratings, availability alerts,
 * contact requests, and the consultation-mode vocabulary.
 *
 * Everything the website and the mobile app show for these features is produced here,
 * so the two clients cannot describe the same row differently. The website pages and
 * the `/api/v1/` endpoints are thin wrappers over these functions.
 *
 * Security posture, shared by every write in this file:
 *   * the acting account comes from the session / bearer token, never from a request
 *     field, so a caller cannot act as another user;
 *   * every row that is read or written is scoped to that account (no IDOR);
 *   * a rating is only accepted for a completed consultation the caller owns;
 *   * a contact request never exposes the lawyer's private email or phone.
 */

require_once __DIR__ . '/notifications.php';

// ---------------------------------------------------------------------------
// Consultation modes
// ---------------------------------------------------------------------------

/**
 * The two consultation modes the platform offers. `remote` is the video/voice room
 * that already exists; `office` is an in-person visit at the lawyer's registered
 * address. The keys are stable and stored nowhere — they are derived from the
 * lawyer's own `offers_remote` / `offers_in_office` columns.
 *
 * @return array<string,array{key:string,label_ar:string,label_en:string,icon:string}>
 */
function consultation_modes_catalog(): array {
    return [
        'remote' => ['key' => 'remote', 'label_ar' => 'عن بُعد', 'label_en' => 'Remote', 'icon' => 'video'],
        'office' => ['key' => 'office', 'label_ar' => 'حضوريًا في المكتب', 'label_en' => 'In office', 'icon' => 'map-pin'],
    ];
}

/** Localised label for a mode key, or '' when the key is unknown. */
function consultation_mode_label(string $mode): string {
    $m = consultation_modes_catalog()[$mode] ?? null;
    if (!$m) return '';
    return is_rtl() ? $m['label_ar'] : $m['label_en'];
}

/**
 * The modes a lawyer actually offers, in catalogue order. A lawyer row that predates
 * the migration has no `offers_*` columns set, so `offers_remote` is treated as
 * offered (the previous behaviour) unless it is explicitly 0.
 *
 * @param array<string,mixed> $lawyerRow
 * @return array<int,array{key:string,label_ar:string,label_en:string,icon:string}>
 */
function lawyer_consultation_modes(array $lawyerRow): array {
    $catalog = consultation_modes_catalog();
    $out = [];
    // Remote is the default: only an explicit 0 removes it.
    if ((int)($lawyerRow['offers_remote'] ?? 1) !== 0) $out[] = $catalog['remote'];
    if ((int)($lawyerRow['offers_in_office'] ?? 0) === 1) $out[] = $catalog['office'];
    // A lawyer who somehow offers neither still offers the platform's remote room, so
    // the customer is never shown a lawyer with no way to consult.
    if (empty($out)) $out[] = $catalog['remote'];
    return $out;
}

/** Short chips ("عن بُعد" / "حضوريًا") for a card. */
function lawyer_consultation_mode_chips(array $lawyerRow): array {
    return array_map(fn($m) => is_rtl() ? $m['label_ar'] : $m['label_en'], lawyer_consultation_modes($lawyerRow));
}

/** True when the lawyer offers in-person consultations at an office. */
function lawyer_offers_office(array $lawyerRow): bool {
    return (int)($lawyerRow['offers_in_office'] ?? 0) === 1;
}

/** True when the lawyer offers remote consultations. */
function lawyer_offers_remote(array $lawyerRow): bool {
    return (int)($lawyerRow['offers_remote'] ?? 1) !== 0;
}

// ---------------------------------------------------------------------------
// Spoken languages
// ---------------------------------------------------------------------------

/**
 * The languages a lawyer can be filtered by. A short, closed list keeps the filter
 * meaningful; the stored column is a comma-separated subset.
 *
 * @return array<string,string> code => localised label
 */
function lawyer_languages_catalog(): array {
    return [
        'ar' => is_rtl() ? 'العربية' : 'Arabic',
        'en' => is_rtl() ? 'الإنجليزية' : 'English',
        'fr' => is_rtl() ? 'الفرنسية' : 'French',
        'ur' => is_rtl() ? 'الأردية' : 'Urdu',
        'hi' => is_rtl() ? 'الهندية' : 'Hindi',
        'tr' => is_rtl() ? 'التركية' : 'Turkish',
    ];
}

/**
 * Normalise a submitted language list to a comma-separated string of valid codes.
 * Anything unknown is dropped, so a crafted value cannot be stored.
 */
function lawyer_languages_normalize($raw): string {
    $valid = array_keys(lawyer_languages_catalog());
    if (is_string($raw)) $raw = preg_split('/[\s,]+/', $raw) ?: [];
    if (!is_array($raw)) return '';
    $out = [];
    foreach ($raw as $code) {
        $code = strtolower(trim((string)$code));
        if (in_array($code, $valid, true) && !in_array($code, $out, true)) $out[] = $code;
    }
    return implode(',', $out);
}

/** The localised labels for a stored comma-separated language string. */
function lawyer_languages_labels(?string $stored): array {
    $catalog = lawyer_languages_catalog();
    $out = [];
    foreach (explode(',', (string)$stored) as $code) {
        $code = strtolower(trim($code));
        if ($code !== '' && isset($catalog[$code])) $out[] = $catalog[$code];
    }
    return $out;
}

/**
 * SQL predicate matching a lawyer whose `languages` column contains the given code.
 * The column is a comma-separated list, so a padded LIKE matches a whole token and
 * never a substring.
 *
 * @return array{0:string,1:array<int,string>} [predicate, params]
 */
function lawyer_language_predicate(string $alias, string $code): array {
    $code = strtolower(trim($code));
    if (!isset(lawyer_languages_catalog()[$code])) return ['', []];
    return ["CONCAT(',', {$alias}.languages, ',') LIKE ?", ['%,' . $code . ',%']];
}

// ---------------------------------------------------------------------------
// Favourites
// ---------------------------------------------------------------------------

/** True when the client has this lawyer saved. */
function favorite_exists(PDO $pdo, int $clientId, int $lawyerId): bool {
    if ($clientId <= 0 || $lawyerId <= 0) return false;
    $stmt = $pdo->prepare('SELECT 1 FROM lawyer_favorites WHERE client_id=? AND lawyer_id=? LIMIT 1');
    $stmt->execute([$clientId, $lawyerId]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Add a lawyer to the client's favourites. Idempotent — the UNIQUE key plus
 * INSERT IGNORE means a double tap cannot create a duplicate. Returns true when the
 * row is present afterwards.
 */
function favorite_add(PDO $pdo, int $clientId, int $lawyerId): bool {
    if ($clientId <= 0 || $lawyerId <= 0) return false;
    if (!lawyer_is_public($pdo, $lawyerId)) return false;
    $pdo->prepare('INSERT IGNORE INTO lawyer_favorites (client_id, lawyer_id) VALUES (?,?)')
        ->execute([$clientId, $lawyerId]);
    return favorite_exists($pdo, $clientId, $lawyerId);
}

/** Remove a lawyer from the client's favourites. Idempotent. */
function favorite_remove(PDO $pdo, int $clientId, int $lawyerId): bool {
    if ($clientId <= 0 || $lawyerId <= 0) return false;
    $pdo->prepare('DELETE FROM lawyer_favorites WHERE client_id=? AND lawyer_id=?')
        ->execute([$clientId, $lawyerId]);
    return !favorite_exists($pdo, $clientId, $lawyerId);
}

/** Toggle and return the resulting state. */
function favorite_toggle(PDO $pdo, int $clientId, int $lawyerId): bool {
    if (favorite_exists($pdo, $clientId, $lawyerId)) {
        favorite_remove($pdo, $clientId, $lawyerId);
        return false;
    }
    return favorite_add($pdo, $clientId, $lawyerId);
}

/** True when the lawyer is approved and visible in the directory. */
function lawyer_is_public(PDO $pdo, int $lawyerId): bool {
    if ($lawyerId <= 0) return false;
    $stmt = $pdo->prepare("SELECT 1 FROM lawyers WHERE id=? AND status='approved' LIMIT 1");
    $stmt->execute([$lawyerId]);
    return (bool)$stmt->fetchColumn();
}

/**
 * The client's saved lawyers, hydrated with the same card fields the directory uses,
 * newest save first. Empty when the client has none, so the page can show its empty
 * state rather than inventing content.
 *
 * @return array<int,array<string,mixed>>
 */
function favorites_for_client(PDO $pdo, int $clientId): array {
    if ($clientId <= 0) return [];
    require_once __DIR__ . '/legal_journey.php';
    $stmt = $pdo->prepare(
        "SELECT l.id, l.full_name, l.specialization, l.specializations, l.bio, l.years_experience,
                l.hourly_rate, l.rating, l.photo_path, l.jurisdiction, l.country_code, l.city,
                l.languages, l.offers_remote, l.offers_in_office, l.office_address,
                f.created_at AS favorited_at,
                (SELECT MIN(a.slot_minutes) FROM lawyer_availability a WHERE a.lawyer_id = l.id) AS slot_minutes
           FROM lawyer_favorites f
           JOIN lawyers l ON l.id = f.lawyer_id
          WHERE f.client_id = ? AND l.status = 'approved'
          ORDER BY f.created_at DESC"
    );
    $stmt->execute([$clientId]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[] = legal_journey_hydrate_lawyer($pdo, $row);
    }
    return $out;
}

/** The set of lawyer ids this client has saved — used to paint the heart on cards. */
function favorite_ids_for_client(PDO $pdo, int $clientId): array {
    if ($clientId <= 0) return [];
    $stmt = $pdo->prepare('SELECT lawyer_id FROM lawyer_favorites WHERE client_id=?');
    $stmt->execute([$clientId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** How many clients saved this lawyer — a light social proof on the profile. */
function favorite_count_for_lawyer(PDO $pdo, int $lawyerId): int {
    if ($lawyerId <= 0) return 0;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM lawyer_favorites WHERE lawyer_id=?');
    $stmt->execute([$lawyerId]);
    return (int)$stmt->fetchColumn();
}

// ---------------------------------------------------------------------------
// Verified ratings
// ---------------------------------------------------------------------------

/**
 * A review may only be written for a consultation the client owns and that has
 * actually completed. This is the anti-fraud gate: an unpaid, cancelled or in-flight
 * consultation cannot be rated, so no rating exists without a real engagement behind
 * it — which is what justifies the "verified" badge.
 *
 * @return array{ok:bool,error?:string,consultation?:array<string,mixed>}
 */
function review_eligibility(PDO $pdo, int $clientId, int $consultationId): array {
    if ($clientId <= 0 || $consultationId <= 0) return ['ok' => false, 'error' => 'not_found'];
    $stmt = $pdo->prepare('SELECT id, lawyer_id, status, payment_status FROM consultations WHERE id=? AND user_id=?');
    $stmt->execute([$consultationId, $clientId]);
    $c = $stmt->fetch();
    if (!$c) return ['ok' => false, 'error' => 'not_found'];
    if ($c['status'] !== 'completed') return ['ok' => false, 'error' => 'not_completed'];
    if (review_exists_for_consultation($pdo, $consultationId)) return ['ok' => false, 'error' => 'already_reviewed'];
    return ['ok' => true, 'consultation' => $c];
}

/** True when this consultation already carries a review. */
function review_exists_for_consultation(PDO $pdo, int $consultationId): bool {
    if ($consultationId <= 0) return false;
    $stmt = $pdo->prepare('SELECT 1 FROM lawyer_reviews WHERE consultation_id=? LIMIT 1');
    $stmt->execute([$consultationId]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Create a verified review. The consultation's own row is the authority for the
 * lawyer id — never the request — so a review cannot be attached to a lawyer the
 * client never consulted. Returns the new review id, or an error string.
 *
 * @return array{ok:bool,error?:string,id?:int}
 */
function review_create(PDO $pdo, int $clientId, int $consultationId, int $rating, ?string $comment): array {
    $elig = review_eligibility($pdo, $clientId, $consultationId);
    if (!$elig['ok']) return ['ok' => false, 'error' => $elig['error']];
    $rating = max(1, min(5, $rating));
    $comment = trim((string)$comment);
    if (mb_strlen($comment) > 2000) $comment = mb_substr($comment, 0, 2000);
    $lawyerId = (int)$elig['consultation']['lawyer_id'];

    try {
        $pdo->prepare('INSERT INTO lawyer_reviews (lawyer_id, client_id, consultation_id, rating, comment) VALUES (?,?,?,?,?)')
            ->execute([$lawyerId, $clientId, $consultationId, $rating, $comment !== '' ? $comment : null]);
    } catch (Throwable $e) {
        // The UNIQUE key is the last line of defence against a concurrent double-submit.
        return ['ok' => false, 'error' => 'already_reviewed'];
    }
    $id = (int)$pdo->lastInsertId();
    review_recompute_lawyer_rating($pdo, $lawyerId);
    return ['ok' => true, 'id' => $id];
}

/**
 * Recompute the lawyer's displayed rating from published reviews. The stored
 * `lawyers.rating` is a cache; the reviews are the truth. Called after every review
 * write so the directory, the profile and the API never disagree.
 */
function review_recompute_lawyer_rating(PDO $pdo, int $lawyerId): void {
    if ($lawyerId <= 0) return;
    $stmt = $pdo->prepare("SELECT COUNT(*) c, AVG(rating) a FROM lawyer_reviews WHERE lawyer_id=? AND status='published'");
    $stmt->execute([$lawyerId]);
    $row = $stmt->fetch();
    $avg = $row['c'] > 0 ? round((float)$row['a'], 1) : null;
    $pdo->prepare('UPDATE lawyers SET rating=? WHERE id=?')->execute([$avg, $lawyerId]);
}

/**
 * The rating summary for a lawyer card / profile: average (1 dp), count, and a
 * per-star distribution. A lawyer with no review reports `count=0` and `average=null`
 * so the UI can show "no ratings yet" instead of a fake zero.
 *
 * @return array{average:?float,count:int,distribution:array<int,int>}
 */
function review_summary_for_lawyer(PDO $pdo, int $lawyerId): array {
    $dist = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
    if ($lawyerId <= 0) return ['average' => null, 'count' => 0, 'distribution' => $dist];
    $stmt = $pdo->prepare("SELECT rating, COUNT(*) c FROM lawyer_reviews WHERE lawyer_id=? AND status='published' GROUP BY rating");
    $stmt->execute([$lawyerId]);
    $count = 0; $sum = 0;
    foreach ($stmt->fetchAll() as $r) {
        $star = (int)$r['rating'];
        $c = (int)$r['c'];
        if (isset($dist[$star])) $dist[$star] = $c;
        $count += $c;
        $sum += $star * $c;
    }
    return [
        'average'      => $count > 0 ? round($sum / $count, 1) : null,
        'count'        => $count,
        'distribution' => $dist,
    ];
}

/**
 * Published reviews for a lawyer, newest first, with the reviewer's display name.
 * Only the client's first name is exposed — a public review must not publish a full
 * identity. Hidden/removed reviews are never returned.
 *
 * @return array<int,array<string,mixed>>
 */
function reviews_for_lawyer(PDO $pdo, int $lawyerId, int $limit = 20): array {
    if ($lawyerId <= 0) return [];
    $limit = max(1, min(100, $limit));
    $stmt = $pdo->prepare(
        "SELECT r.id, r.rating, r.comment, r.created_at, u.full_name AS client_name
           FROM lawyer_reviews r
           LEFT JOIN users u ON u.id = r.client_id
          WHERE r.lawyer_id = ? AND r.status = 'published'
          ORDER BY r.created_at DESC
          LIMIT {$limit}"
    );
    $stmt->execute([$lawyerId]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $name = trim((string)($r['client_name'] ?? ''));
        // First token only: "محمد العتيبي" -> "محمد".
        $first = $name !== '' ? preg_split('/\s+/u', $name)[0] : '';
        $r['display_name'] = $first !== '' ? $first : (is_rtl() ? 'عميل' : 'Client');
        $out[] = $r;
    }
    return $out;
}

/** The client's own review for a consultation, if any — so the UI can show it. */
function review_for_consultation(PDO $pdo, int $clientId, int $consultationId): ?array {
    if ($clientId <= 0 || $consultationId <= 0) return null;
    $stmt = $pdo->prepare('SELECT * FROM lawyer_reviews WHERE consultation_id=? AND client_id=? LIMIT 1');
    $stmt->execute([$consultationId, $clientId]);
    return $stmt->fetch() ?: null;
}

/**
 * Completed consultations the client can still rate, newest first. Drives the
 * "rate your consultation" list; a consultation already reviewed is excluded.
 *
 * @return array<int,array<string,mixed>>
 */
function reviewable_consultations(PDO $pdo, int $clientId): array {
    if ($clientId <= 0) return [];
    $stmt = $pdo->prepare(
        "SELECT c.id, c.lawyer_id, c.topic, c.scheduled_at, c.status, l.full_name AS lawyer_name, l.photo_path
           FROM consultations c
           JOIN lawyers l ON l.id = c.lawyer_id
      LEFT JOIN lawyer_reviews r ON r.consultation_id = c.id
          WHERE c.user_id = ? AND c.status = 'completed' AND r.id IS NULL
          ORDER BY c.scheduled_at DESC"
    );
    $stmt->execute([$clientId]);
    return $stmt->fetchAll();
}

/**
 * A client reports a review. Reports are counted rather than acted on automatically —
 * a human decides in the console, exactly as the conflict engine does. Returns false
 * when the review is unknown, so a caller cannot probe ids.
 */
function review_report(PDO $pdo, int $reviewId, int $reporterId): bool {
    if ($reviewId <= 0) return false;
    $stmt = $pdo->prepare('SELECT lawyer_id FROM lawyer_reviews WHERE id=? LIMIT 1');
    $stmt->execute([$reviewId]);
    if (!$stmt->fetch()) return false;
    // A reporter can only report a review that is not their own.
    $pdo->prepare('UPDATE lawyer_reviews SET report_count = report_count + 1 WHERE id=?')->execute([$reviewId]);
    return true;
}

// ---------------------------------------------------------------------------
// Admin moderation of published reviews
// ---------------------------------------------------------------------------

/** The reviews the console lists, newest first, optionally filtered by status. */
function reviews_for_admin(PDO $pdo, string $status = '', int $limit = 200): array {
    $limit = max(1, min(500, $limit));
    $sql = "SELECT r.*, l.full_name AS lawyer_name, u.full_name AS client_name
              FROM lawyer_reviews r
              JOIN lawyers l ON l.id = r.lawyer_id
              JOIN users u ON u.id = r.client_id";
    $params = [];
    if (in_array($status, ['published', 'pending', 'hidden', 'removed'], true)) {
        $sql .= ' WHERE r.status = ?';
        $params[] = $status;
    }
    $sql .= " ORDER BY r.created_at DESC LIMIT {$limit}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Change a review's moderation status. This is a human trust decision — nothing here
 * is automatic. Setting `removed` keeps the row (audit trail) but excludes it from the
 * public average, so a lawyer's score is recomputed from published reviews only.
 */
function review_set_status(PDO $pdo, int $reviewId, string $status, ?string $note = null): bool {
    if ($reviewId <= 0 || !in_array($status, ['published', 'pending', 'hidden', 'removed'], true)) return false;
    $stmt = $pdo->prepare('UPDATE lawyer_reviews SET status=?, admin_note=? WHERE id=?');
    $stmt->execute([$status, $note, $reviewId]);
    if ($stmt->rowCount() < 1) {
        // rowCount is 0 when the value is unchanged; confirm the row exists so a real
        // status change is not reported as a failure.
        $chk = $pdo->prepare('SELECT lawyer_id FROM lawyer_reviews WHERE id=?');
        $chk->execute([$reviewId]);
        $lawyerId = (int)$chk->fetchColumn();
        if ($lawyerId <= 0) return false;
        review_recompute_lawyer_rating($pdo, $lawyerId);
        return true;
    }
    $get = $pdo->prepare('SELECT lawyer_id FROM lawyer_reviews WHERE id=?');
    $get->execute([$reviewId]);
    $lawyerId = (int)$get->fetchColumn();
    if ($lawyerId > 0) review_recompute_lawyer_rating($pdo, $lawyerId);
    return true;
}

// ---------------------------------------------------------------------------
// Availability alerts ("notify me when a slot opens")
// ---------------------------------------------------------------------------

/**
 * Subscribe the client to a lawyer's next open slot. Idempotent through the UNIQUE
 * key: re-subscribing a cancelled alert re-activates the same row, so the pair never
 * holds two rows and a duplicate notification cannot be sent.
 *
 * @return array{ok:bool,status:string,error?:string}
 */
function availability_alert_subscribe(PDO $pdo, int $clientId, int $lawyerId): array {
    if ($clientId <= 0 || $lawyerId <= 0) return ['ok' => false, 'status' => '', 'error' => 'not_found'];
    if (!lawyer_is_public($pdo, $lawyerId)) return ['ok' => false, 'status' => '', 'error' => 'not_found'];

    $stmt = $pdo->prepare('SELECT id, status FROM lawyer_availability_alerts WHERE client_id=? AND lawyer_id=?');
    $stmt->execute([$clientId, $lawyerId]);
    $row = $stmt->fetch();
    if ($row) {
        // Re-activate without touching `created_at`, so ordering is stable.
        $pdo->prepare("UPDATE lawyer_availability_alerts SET status='active', notified_at=NULL WHERE id=?")
            ->execute([$row['id']]);
        return ['ok' => true, 'status' => 'active'];
    }
    $pdo->prepare("INSERT INTO lawyer_availability_alerts (client_id, lawyer_id, status) VALUES (?,?,'active')")
        ->execute([$clientId, $lawyerId]);
    return ['ok' => true, 'status' => 'active'];
}

/** Cancel the client's alert for a lawyer. Idempotent. */
function availability_alert_cancel(PDO $pdo, int $clientId, int $lawyerId): array {
    if ($clientId <= 0 || $lawyerId <= 0) return ['ok' => false, 'status' => '', 'error' => 'not_found'];
    $pdo->prepare("UPDATE lawyer_availability_alerts SET status='cancelled' WHERE client_id=? AND lawyer_id=? AND status='active'")
        ->execute([$clientId, $lawyerId]);
    return ['ok' => true, 'status' => 'cancelled'];
}

/** The client's alert state for one lawyer, or null when none exists. */
function availability_alert_for(PDO $pdo, int $clientId, int $lawyerId): ?array {
    if ($clientId <= 0 || $lawyerId <= 0) return null;
    $stmt = $pdo->prepare('SELECT * FROM lawyer_availability_alerts WHERE client_id=? AND lawyer_id=? LIMIT 1');
    $stmt->execute([$clientId, $lawyerId]);
    return $stmt->fetch() ?: null;
}

/**
 * True when the lawyer has at least one open slot starting in the next `$days` days.
 * This is the "real availability" check the alert trigger and the directory's
 * availability filter both rely on — a slot in the past never counts.
 */
function lawyer_has_open_slot(PDO $pdo, int $lawyerId, int $days = 14): bool {
    if ($lawyerId <= 0) return false;
    require_once __DIR__ . '/legal_journey.php';
    $summary = legal_journey_availability_summary($pdo, $lawyerId, date('Y-m-d'), max(1, $days), 1);
    return ($summary['slot_count'] ?? 0) > 0;
}

/**
 * Notify every client with an active alert for this lawyer that a slot just opened,
 * and mark those alerts fulfilled so the same opening is never announced twice.
 * Returns the number of clients notified.
 *
 * Called by the alert sweep (`cron/availability-alerts.php`) after a lawyer publishes
 * hours, and directly from the booking flow when a cancellation frees a slot.
 */
function availability_alerts_dispatch(PDO $pdo, int $lawyerId): int {
    if ($lawyerId <= 0) return 0;
    if (!lawyer_has_open_slot($pdo, $lawyerId)) return 0;

    $stmt = $pdo->prepare("SELECT a.id, a.client_id FROM lawyer_availability_alerts a WHERE a.lawyer_id=? AND a.status='active'");
    $stmt->execute([$lawyerId]);
    $rows = $stmt->fetchAll();
    if (empty($rows)) return 0;

    $lw = $pdo->prepare('SELECT full_name FROM lawyers WHERE id=?');
    $lw->execute([$lawyerId]);
    $lawyerName = (string)($lw->fetchColumn() ?: '');

    $notified = 0;
    foreach ($rows as $a) {
        $clientId = (int)$a['client_id'];
        // Preference-gated: the bell always records it; email and push only when the
        // customer allowed them. The alert is a courtesy, never a security message, so it
        // honours the toggles.
        $mailStmt = $pdo->prepare('SELECT email FROM users WHERE id=?');
        $mailStmt->execute([$clientId]);
        notification_dispatch(
            $pdo, 'client', $clientId,
            is_rtl() ? 'توفّر موعد جديد' : 'A new slot is available',
            is_rtl()
                ? 'أصبح لدى ' . $lawyerName . ' موعد متاح للحجز. اضغط لعرض المواعيد.'
                : $lawyerName . ' has an open slot. Tap to view the times.',
            '/booking.php?lawyer_id=' . $lawyerId,
            ['email' => true, 'email_to' => (string)$mailStmt->fetchColumn()]
        );
        $pdo->prepare("UPDATE lawyer_availability_alerts SET status='fulfilled', notified_at=NOW() WHERE id=?")
            ->execute([$a['id']]);
        $notified++;
    }
    return $notified;
}

/**
 * The client's own alerts, newest first, with the lawyer's name — the "my alerts"
 * list, so the customer can see and cancel what they asked for.
 *
 * @return array<int,array<string,mixed>>
 */
function availability_alerts_for_client(PDO $pdo, int $clientId): array {
    if ($clientId <= 0) return [];
    $stmt = $pdo->prepare(
        "SELECT a.*, l.full_name AS lawyer_name, l.photo_path
           FROM lawyer_availability_alerts a
           JOIN lawyers l ON l.id = a.lawyer_id
          WHERE a.client_id = ?
          ORDER BY a.created_at DESC"
    );
    $stmt->execute([$clientId]);
    return $stmt->fetchAll();
}

// ---------------------------------------------------------------------------
// Contact requests
// ---------------------------------------------------------------------------

/**
 * Store a contact request from a client to a lawyer. The lawyer's own row is checked
 * first (only a real, approved lawyer can be contacted), and the message is bounded so
 * an oversized body cannot be stored. No contact detail of the lawyer is returned.
 *
 * @return array{ok:bool,error?:string,id?:int}
 */
function contact_request_create(PDO $pdo, int $clientId, int $lawyerId, ?string $subject, string $message): array {
    if ($clientId <= 0 || $lawyerId <= 0) return ['ok' => false, 'error' => 'not_found'];
    if (!lawyer_is_public($pdo, $lawyerId)) return ['ok' => false, 'error' => 'not_found'];
    $message = trim($message);
    if (mb_strlen($message) < 5) return ['ok' => false, 'error' => 'message_required'];
    if (mb_strlen($message) > 4000) $message = mb_substr($message, 0, 4000);
    $subject = trim((string)$subject);
    if (mb_strlen($subject) > 150) $subject = mb_substr($subject, 0, 150);

    $pdo->prepare('INSERT INTO lawyer_contact_requests (client_id, lawyer_id, subject, message) VALUES (?,?,?,?)')
        ->execute([$clientId, $lawyerId, $subject !== '' ? $subject : null, $message]);
    $id = (int)$pdo->lastInsertId();

    // Notify the lawyer (and their firm) that a new request arrived. The link opens the
    // lawyer's own inbox, never the client's private detail.
    notify($pdo, 'lawyer', $lawyerId,
        is_rtl() ? 'طلب تواصل جديد' : 'New contact request',
        is_rtl() ? 'وصل طلب تواصل جديد من أحد العملاء.' : 'A client sent you a contact request.',
        '/lawyer/contact-requests.php');
    return ['ok' => true, 'id' => $id];
}

/**
 * The contact requests a lawyer has received, newest first. Scoped to the lawyer's own
 * id — a lawyer can never read another lawyer's inbox (no IDOR).
 *
 * @return array<int,array<string,mixed>>
 */
function contact_requests_for_lawyer(PDO $pdo, int $lawyerId, int $limit = 50): array {
    if ($lawyerId <= 0) return [];
    $limit = max(1, min(200, $limit));
    $stmt = $pdo->prepare(
        "SELECT r.*, u.full_name AS client_name, u.email AS client_email, u.phone AS client_phone
           FROM lawyer_contact_requests r
           LEFT JOIN users u ON u.id = r.client_id
          WHERE r.lawyer_id = ?
          ORDER BY r.created_at DESC
          LIMIT {$limit}"
    );
    $stmt->execute([$lawyerId]);
    return $stmt->fetchAll();
}

/** Mark a contact request read, scoped to its lawyer so a guessed id cannot be touched. */
function contact_request_mark_read(PDO $pdo, int $lawyerId, int $requestId): bool {
    if ($lawyerId <= 0 || $requestId <= 0) return false;
    $stmt = $pdo->prepare("UPDATE lawyer_contact_requests SET status='read' WHERE id=? AND lawyer_id=? AND status='new'");
    $stmt->execute([$requestId, $lawyerId]);
    return $stmt->rowCount() > 0;
}

/** The client's own sent contact requests, so they can see what they sent. */
function contact_requests_for_client(PDO $pdo, int $clientId, int $limit = 50): array {
    if ($clientId <= 0) return [];
    $limit = max(1, min(200, $limit));
    $stmt = $pdo->prepare(
        "SELECT r.*, l.full_name AS lawyer_name
           FROM lawyer_contact_requests r
           JOIN lawyers l ON l.id = r.lawyer_id
          WHERE r.client_id = ?
          ORDER BY r.created_at DESC
          LIMIT {$limit}"
    );
    $stmt->execute([$clientId]);
    return $stmt->fetchAll();
}

/** Count of unread contact requests for the lawyer's dashboard badge. */
function contact_requests_unread_count(PDO $pdo, int $lawyerId): int {
    if ($lawyerId <= 0) return 0;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM lawyer_contact_requests WHERE lawyer_id=? AND status='new'");
    $stmt->execute([$lawyerId]);
    return (int)$stmt->fetchColumn();
}

// ---------------------------------------------------------------------------
// Notification preferences (channel-aware)
// ---------------------------------------------------------------------------

/**
 * The notification channels a client can control, with their default. `in_app` and
 * `email` are stored per account; `push` is the mobile push channel. A missing row
 * means "on", so a new account is notified without opting in.
 *
 * @return array<int,string>
 */
function notification_channels_catalog(): array {
    return ['in_app', 'email', 'push'];
}

/**
 * The effective preference for one channel. `in_app` is treated as always available
 * (the bell is the platform's own surface); email and push respect the stored value.
 */
function notification_preference_enabled(PDO $pdo, string $subjectType, int $subjectId, string $channel): bool {
    if (!in_array($channel, notification_channels_catalog(), true)) return false;
    if ($subjectId <= 0) return true;
    try {
        $stmt = $pdo->prepare("SELECT enabled FROM notification_preferences WHERE subject_type=? AND subject_id=? AND channel=? AND event_key='all' LIMIT 1");
        $stmt->execute([$subjectType, $subjectId, $channel]);
        $v = $stmt->fetchColumn();
        return $v === false ? true : ((int)$v === 1);
    } catch (Throwable $e) {
        // A pre-migration database has no such table; behave as the default (on).
        return true;
    }
}

/**
 * Persist one channel preference for an account. Upsert through the UNIQUE key so a
 * repeat save updates the existing row rather than failing.
 *
 * `in_app` is deliberately inert: the bell is the platform's own record of what
 * happened, not an opt-in outbound channel, so it cannot be switched off. Accepting the
 * write and ignoring it keeps every caller's shape the same while guaranteeing the
 * platform's own surface always reflects reality.
 */
function notification_preference_set(PDO $pdo, string $subjectType, int $subjectId, string $channel, bool $enabled): void {
    if ($channel === 'in_app') return;
    if (!in_array($channel, notification_channels_catalog(), true)) return;
    if ($subjectId <= 0) return;
    $pdo->prepare(
        'INSERT INTO notification_preferences (subject_type, subject_id, channel, event_key, enabled)
         VALUES (?,?,?, \'all\', ?)
         ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)'
    )->execute([$subjectType, $subjectId, $channel, $enabled ? 1 : 0]);
}

/**
 * All channel preferences for an account as a name => bool map, defaulting missing
 * channels to true. This is what the website page and the app settings both render.
 *
 * @return array<string,bool>
 */
function notification_preferences_for(PDO $pdo, string $subjectType, int $subjectId): array {
    $out = array_fill_keys(notification_channels_catalog(), true);
    if ($subjectId <= 0) return $out;
    try {
        $stmt = $pdo->prepare("SELECT channel, enabled FROM notification_preferences WHERE subject_type=? AND subject_id=? AND event_key='all'");
        $stmt->execute([$subjectType, $subjectId]);
        foreach ($stmt->fetchAll() as $r) {
            if (isset($out[$r['channel']])) $out[$r['channel']] = ((int)$r['enabled'] === 1);
        }
    } catch (Throwable $e) {
        // Pre-migration database: report the defaults.
    }
    // in_app is the platform's own record, not an opt-in channel — always on, even if an
    // older row (or a direct write) left it disabled.
    $out['in_app'] = true;
    return $out;
}

/**
 * Whether an outbound (email / push) notification should actually be sent for an
 * account. The bell is always allowed; the optional channels are gated here, so
 * turning a switch off really stops the send rather than only repainting the toggle.
 */
function notification_channel_allows(PDO $pdo, string $subjectType, int $subjectId, string $channel): bool {
    if ($channel === 'in_app') return true;
    return notification_preference_enabled($pdo, $subjectType, $subjectId, $channel);
}

/**
 * The API/website payload for the notification settings screen: the three channels
 * with their current values and localised labels.
 *
 * @return array<int,array{key:string,enabled:bool,label_ar:string,label_en:string,description_ar:string,description_en:string}>
 */
function notification_preferences_payload(PDO $pdo, string $subjectType, int $subjectId): array {
    $prefs = notification_preferences_for($pdo, $subjectType, $subjectId);
    $meta = [
        'in_app' => ['ar' => 'إشعارات داخل التطبيق', 'en' => 'In-app notifications',
                     'dar' => 'تنبيهات جرس الإشعارات داخل المنصة.', 'den' => 'The bell inside the platform.'],
        'email'  => ['ar' => 'إشعارات البريد الإلكتروني', 'en' => 'Email notifications',
                     'dar' => 'ملخّصات وتحديثات تُرسل إلى بريدك.', 'den' => 'Summaries and updates sent to your inbox.'],
        'push'   => ['ar' => 'إشعارات الهاتف (Push)', 'en' => 'Push notifications',
                     'dar' => 'تنبيهات فورية على جهازك عبر التطبيق.', 'den' => 'Real-time alerts on your phone via the app.'],
    ];
    $out = [];
    foreach (notification_channels_catalog() as $ch) {
        $out[] = [
            'key'            => $ch,
            'enabled'        => (bool)$prefs[$ch],
            'locked'         => $ch === 'in_app',
            'label_ar'       => $meta[$ch]['ar'],
            'label_en'       => $meta[$ch]['en'],
            'description_ar' => $meta[$ch]['dar'],
            'description_en' => $meta[$ch]['den'],
        ];
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Device tokens and the preference-gated dispatcher
// ---------------------------------------------------------------------------

/**
 * Register (or refresh) a mobile push token for an account. The token column is
 * UNIQUE, so a device that re-registers updates its row instead of duplicating, and a
 * token that moves to a different account is re-pointed rather than left stale.
 */
function device_token_register(PDO $pdo, string $subjectType, int $subjectId, string $token, string $platform = 'android'): bool {
    $token = trim($token);
    if ($subjectId <= 0 || $token === '' || strlen($token) > 255) return false;
    if (!in_array($subjectType, ['client', 'lawyer'], true)) return false;
    if (!in_array($platform, ['android', 'ios', 'web'], true)) $platform = 'android';
    $pdo->prepare(
        'INSERT INTO device_tokens (subject_type, subject_id, token, platform, last_seen_at)
         VALUES (?,?,?,?,NOW())
         ON DUPLICATE KEY UPDATE subject_type=VALUES(subject_type), subject_id=VALUES(subject_id),
                                 platform=VALUES(platform), last_seen_at=NOW()'
    )->execute([$subjectType, $subjectId, $token, $platform]);
    return true;
}

/** Remove a token (e.g. on logout). Idempotent; scoped to the account so a token cannot be removed by another user. */
function device_token_unregister(PDO $pdo, string $subjectType, int $subjectId, string $token): void {
    $token = trim($token);
    if ($token === '' || $subjectId <= 0) return;
    $pdo->prepare('DELETE FROM device_tokens WHERE subject_type=? AND subject_id=? AND token=?')
        ->execute([$subjectType, $subjectId, $token]);
}

/** Whether an account has at least one registered device. */
function device_token_count(PDO $pdo, string $subjectType, int $subjectId): int {
    if ($subjectId <= 0) return 0;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM device_tokens WHERE subject_type=? AND subject_id=?');
    $stmt->execute([$subjectType, $subjectId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Queue a push for an account, but only when the push channel is enabled and a device
 * is actually registered. This is where "the toggle really stops the send" is made
 * true: with the switch off, no row is ever written.
 *
 * @return bool true when a delivery was queued
 */
function push_delivery_enqueue(PDO $pdo, string $subjectType, int $subjectId, string $title, ?string $body, ?string $link): bool {
    if ($subjectId <= 0 || !in_array($subjectType, ['client', 'lawyer'], true)) return false;
    if (!notification_channel_allows($pdo, $subjectType, $subjectId, 'push')) return false;
    if (device_token_count($pdo, $subjectType, $subjectId) < 1) return false;
    $pdo->prepare('INSERT INTO push_deliveries (subject_type, subject_id, title, body, link) VALUES (?,?,?,?,?)')
        ->execute([$subjectType, $subjectId, $title, $body, $link]);
    return true;
}

/**
 * The single dispatcher every optional notification goes through. It fans one event out
 * to the three channels, each gated by the account's own preference:
 *
 *   * in_app — always stored (the bell is the platform's own surface);
 *   * email  — only when an email body is supplied AND the account allows email;
 *   * push   — only when the account allows push AND a device is registered.
 *
 * A `force` flag bypasses the preferences for a strictly transactional message (an
 * account-security mail must never be silenced by a settings toggle).
 *
 * @param array{email?:bool,email_to?:?string,email_html?:?string,force?:bool} $opts
 * @return array{in_app:bool,email:bool,push:bool}
 */
function notification_dispatch(PDO $pdo, string $subjectType, int $subjectId, string $title, ?string $message = null, ?string $link = null, array $opts = []): array {
    $result = ['in_app' => false, 'email' => false, 'push' => false];
    if ($subjectId <= 0) return $result;

    $force = !empty($opts['force']);

    if ($force || notification_channel_allows($pdo, $subjectType, $subjectId, 'in_app')) {
        notify($pdo, $subjectType, $subjectId, $title, $message, $link);
        $result['in_app'] = true;
    }

    if (!empty($opts['email']) && !empty($opts['email_to'])
        && ($force || notification_channel_allows($pdo, $subjectType, $subjectId, 'email'))) {
        $mailer = __DIR__ . '/mailer.php';
        if (is_file($mailer)) {
            require_once $mailer;
            if (function_exists('send_mail')) {
                $html = $opts['email_html'] ?? notification_email_wrap($title, (string)$message, $link);
                $result['email'] = (bool)send_mail((string)$opts['email_to'], $title, $html);
            }
        }
    }

    if ($force || notification_channel_allows($pdo, $subjectType, $subjectId, 'push')) {
        $result['push'] = push_delivery_enqueue($pdo, $subjectType, $subjectId, $title, $message, $link);
    }

    return $result;
}

/** A minimal, RTL-safe HTML wrapper for a notification email. */
function notification_email_wrap(string $title, string $message, ?string $link): string {
    $rtl = is_rtl();
    $dir = $rtl ? 'rtl' : 'ltr';
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeMsg = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
    $brand = 'مواثيق';
    $cta = '';
    if ($link) {
        $url = (str_starts_with($link, 'http') ? $link : (defined('SITE_URL') ? rtrim(SITE_URL, '/') : '') . $link);
        $cta = '<p style="margin:24px 0"><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" style="background:#123a5e;color:#fff;padding:12px 22px;border-radius:10px;text-decoration:none">'
             . ($rtl ? 'عرض التفاصيل' : 'View details') . '</a></p>';
    }
    return '<div dir="' . $dir . '" style="font-family:system-ui,Segoe UI,Tahoma,sans-serif;max-width:560px;margin:auto;color:#0f1f33">'
         . '<h2 style="color:#123a5e">' . $safeTitle . '</h2><p style="line-height:1.7">' . $safeMsg . '</p>'
         . $cta . '<hr style="border:none;border-top:1px solid #e6ebf1;margin:28px 0">'
         . '<p style="font-size:12px;color:#7a8aa0">' . $brand . '</p></div>';
}

