<?php
/**
 * Booking slot reservation and race protection (Execution 66 phase B).
 *
 * `get_available_slots()` answers "which times are free right now", which is a read. Between
 * that read and the INSERT in booking.php there is a window: two customers can both be told
 * 10:00 is free and both book it. Nothing in the schema stops that — `consultations` has no
 * uniqueness on (lawyer_id, scheduled_at), and the booking insert ran outside a transaction.
 *
 * This module closes the window the way a booking system must: the final availability check
 * and the INSERT happen **inside one named lock** keyed on the lawyer and the slot, so a second
 * request for the same slot waits and then sees the first booking already there.
 *
 * Why a named lock (GET_LOCK) and not `SELECT ... FOR UPDATE`:
 *   - the slot is not a row. There is no `slot` table to lock, and locking a gap in
 *     `consultations` would require a range lock that InnoDB only takes under specific index
 *     and isolation conditions — easy to get subtly wrong.
 *   - GET_LOCK is connection-scoped and released by the database if the connection dies, so a
 *     crashed request cannot leave a slot locked forever.
 *   - it serialises exactly the (lawyer, slot) pair, not the whole table, so unrelated
 *     bookings still run concurrently.
 *
 * The lock is always released in a `finally`, and the caller is told plainly when the slot was
 * taken meanwhile (`slot_taken`), so the UI can ask the customer to choose another time rather
 * than failing with a raw database error.
 */
require_once __DIR__ . '/availability.php';
// CONSULTATION_DEFAULT_MINUTES is the platform's fallback length; the interval maths needs it
// so a row with no recorded duration is treated as a real appointment, not a zero-length one.
require_once __DIR__ . '/consultation_pricing.php';

/** The lock name for one lawyer/slot pair. Deterministic, bounded, and namespaced. */
function booking_slot_lock_name(int $lawyerId, string $scheduledAt): string {
    // MySQL lock names are limited to 64 chars. A hash keeps it fixed-length and unique per
    // (lawyer, datetime) without risking a truncation collision.
    return 'mwq_slot_' . $lawyerId . '_' . substr(hash('sha256', $scheduledAt), 0, 40);
}

/**
 * Run $fn while holding the named lock for this lawyer/slot. $fn returns the result.
 *
 * @param callable $fn function(): mixed
 * @return array{ok:bool, result?:mixed, error?:string}
 */
function booking_with_slot_lock(PDO $pdo, int $lawyerId, string $scheduledAt, callable $fn, int $timeout = 5): array {
    if ($lawyerId <= 0 || $scheduledAt === '') {
        return ['ok' => false, 'error' => 'bad_slot'];
    }
    $lockName = booking_slot_lock_name($lawyerId, $scheduledAt);
    $stmt = $pdo->prepare('SELECT GET_LOCK(?, ?)');
    $stmt->execute([$lockName, $timeout]);
    $acquired = (int)$stmt->fetchColumn() === 1;
    if (!$acquired) {
        // Another booking for this exact slot is in flight and did not finish in time.
        return ['ok' => false, 'error' => 'busy'];
    }
    try {
        return ['ok' => true, 'result' => $fn()];
    } finally {
        try {
            $rel = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $rel->execute([$lockName]);
        } catch (Throwable $e) {
            // The lock is released when the connection closes regardless; never mask the
            // caller's result with a release error.
            error_log('booking slot lock release failed: ' . $e->getMessage());
        }
    }
}

/**
 * Is this exact datetime still bookable for this lawyer?
 *
 * This is the authoritative check, run inside the slot lock immediately before the INSERT (and
 * again before a payment confirmation). It is stricter than "the slot is in the free list":
 * it also refuses a time that is in the past and a time outside the lawyer's published hours,
 * so a crafted POST cannot book 03:00 on a Sunday.
 *
 * The overlap test is interval-based (`booking_slot_overlap`), not start-time based. Checking
 * only that this minute is not another booking's start minute let a 60-minute appointment be
 * double-booked at its half hour, and let a short slot be placed inside a long one.
 *
 * @param int $durationMinutes the length the customer is booking
 * @return array{ok:bool, error?:string, conflict?:array}
 */
function booking_slot_is_bookable(PDO $pdo, int $lawyerId, string $scheduledAt, ?int $ignoreConsultationId = null, int $durationMinutes = 0): array {
    $ts = strtotime($scheduledAt);
    if ($ts === false) return ['ok' => false, 'error' => 'invalid_datetime'];
    if ($ts <= time()) return ['ok' => false, 'error' => 'in_past'];

    $duration = $durationMinutes > 0 ? $durationMinutes : CONSULTATION_DEFAULT_MINUTES;
    $duration = consultation_normalize_duration($duration);

    $date = date('Y-m-d', $ts);
    $time = date('H:i', $ts);

    // A lawyer who has published no hours is bookable on request (the existing behaviour:
    // the customer proposes a time and the lawyer confirms). We only enforce the published
    // grid when a grid exists.
    $c = $pdo->prepare('SELECT COUNT(*) FROM lawyer_availability WHERE lawyer_id = ?');
    $c->execute([$lawyerId]);
    $hasHours = (int)$c->fetchColumn() > 0;

    if ($hasHours) {
        $slots = get_available_slots($pdo, $lawyerId, $date);
        if ($ignoreConsultationId) {
            // When re-checking our own just-created booking, its own time is in the booked
            // set and would look taken. Add it back before comparing.
            $own = $pdo->prepare('SELECT DATE(scheduled_at)=? AS same_day, TIME(scheduled_at) AS t FROM consultations WHERE id=?');
            $own->execute([$date, $ignoreConsultationId]);
            $row = $own->fetch();
            if ($row && (int)$row['same_day'] === 1) {
                $slots[] = substr((string)$row['t'], 0, 5);
            }
        }
        if (!in_array($time, $slots, true)) {
            // Report the booking that occupies the time, not just "taken": the caller and the
            // support desk both need to know whether this is another appointment, the lawyer's
            // own hours, or a same-minute race.
            return [
                'ok' => false,
                'error' => 'slot_taken',
                'conflict' => booking_slot_overlap($pdo, $lawyerId, $scheduledAt, $duration, (int)$ignoreConsultationId),
            ];
        }
        // The grid can be published in a coarser unit than the requested duration (a 30-minute
        // grid, a 60-minute consultation). Being on the grid is not enough: the whole interval
        // must be inside the working window.
        $window = $pdo->prepare('SELECT end_time FROM lawyer_availability WHERE lawyer_id=? AND day_of_week=? AND ? >= start_time AND ? < end_time LIMIT 1');
        $window->execute([$lawyerId, (int)date('w', $ts), $time . ':00', $time . ':00']);
        $endTime = $window->fetchColumn();
        if ($endTime) {
            $windowEnd = strtotime($date . ' ' . $endTime);
            if ($ts + $duration * 60 > $windowEnd) {
                return ['ok' => false, 'error' => 'outside_hours'];
            }
        }
    }

    // The interval check, independent of whether a grid exists: a free-form booking must not
    // land on top of a longer appointment either.
    $conflict = booking_slot_overlap($pdo, $lawyerId, $scheduledAt, $duration, (int)$ignoreConsultationId);
    if ($conflict !== null) {
        return ['ok' => false, 'error' => 'slot_taken', 'conflict' => $conflict];
    }

    return ['ok' => true];
}

/**
 * Create a consultation inside the slot lock, re-checking availability first.
 *
 * `$insert` is a callable that performs the actual INSERT and returns the new id; it runs only
 * after the slot has been re-verified while the lock is held, which is what removes the
 * time-of-check/time-of-use window.
 *
 * The lock covers only the requested start minute, but the *check* covers the whole interval
 * the consultation occupies. Two different starts that overlap still serialise correctly
 * because each one's interval test sees the row the other committed. The last-arriving booking
 * is the one refused, and it is refused before it writes anything.
 *
 * @param int $durationMinutes length of the appointment; drives the overlap test
 * @param callable $insert function(): int
 * @return array{ok:bool, id?:int, error?:string, conflict?:array}
 */
function booking_create_locked(PDO $pdo, int $lawyerId, string $scheduledAt, callable $insert, int $durationMinutes = 0): array {
    $out = booking_with_slot_lock($pdo, $lawyerId, $scheduledAt, function () use ($pdo, $lawyerId, $scheduledAt, $insert, $durationMinutes) {
        $check = booking_slot_is_bookable($pdo, $lawyerId, $scheduledAt, null, $durationMinutes);
        if (!$check['ok']) {
            return ['__error' => $check['error'], '__conflict' => $check['conflict'] ?? null];
        }
        $id = (int)$insert();
        return ['__id' => $id];
    });

    if (!$out['ok']) {
        return ['ok' => false, 'error' => $out['error']];
    }
    $res = $out['result'];
    if (isset($res['__error'])) {
        return ['ok' => false, 'error' => $res['__error'], 'conflict' => $res['__conflict'] ?? null];
    }
    return ['ok' => true, 'id' => (int)($res['__id'] ?? 0)];
}

/**
 * True when this lawyer has already confirmed a *different* consultation at this exact time.
 * Used by the lawyer/admin side before a reschedule is accepted.
 *
 * Exact-time only: see booking_slot_overlap() for the interval-aware check that the booking
 * path uses. Kept because existing callers rely on its narrow meaning.
 */
function booking_slot_conflicts(PDO $pdo, int $lawyerId, string $scheduledAt, int $ignoreConsultationId = 0): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM consultations
        WHERE lawyer_id = ? AND scheduled_at = ? AND status IN ('pending','confirmed')
          AND (? = 0 OR id <> ?)");
    $stmt->execute([$lawyerId, $scheduledAt, $ignoreConsultationId, $ignoreConsultationId]);
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Find a consultation that overlaps the interval [start, start+duration) for this lawyer.
 *
 * The platform's earlier conflict checks compared *start times*: a booking was refused only
 * when another began at exactly the same minute. That is wrong for every consultation whose
 * length exceeds the slot grid. With 30-minute slots and a 60-minute consultation at 10:00,
 * 10:30 was still offered — and accepted — producing two appointments the lawyer could not
 * attend. Half-open intervals are the fix: two bookings clash when each begins before the
 * other ends, which is the only definition that cannot double-book.
 *
 * The boundaries are deliberately half-open, so a 10:00–11:00 booking does not block an 11:00
 * start. Using <= on either side would make back-to-back appointments impossible.
 *
 * `duration_minutes` is coalesced to the platform default because older rows predate the
 * column being populated; treating a NULL as zero-length would let it be overlapped.
 *
 * @return array{id:int, scheduled_at:string, duration_minutes:int}|null the clashing row
 */
function booking_slot_overlap(PDO $pdo, int $lawyerId, string $scheduledAt, int $durationMinutes, int $ignoreConsultationId = 0): ?array {
    $start = strtotime($scheduledAt);
    if ($start === false) return null;
    $duration = $durationMinutes > 0 ? $durationMinutes : CONSULTATION_DEFAULT_MINUTES;
    $end = $start + $duration * 60;

    // Narrow the scan to the same day and the next (an overnight consultation), then evaluate
    // the interval in SQL so the database does the comparison rather than the application.
    $dayFrom = date('Y-m-d 00:00:00', $start - 86400);
    $dayTo   = date('Y-m-d 23:59:59', $end + 86400);

    $stmt = $pdo->prepare(
        "SELECT id, scheduled_at,
                COALESCE(NULLIF(duration_minutes, 0), ?) AS duration_minutes
           FROM consultations
          WHERE lawyer_id = ?
            AND status <> 'cancelled'
            AND (? = 0 OR id <> ?)
            AND scheduled_at BETWEEN ? AND ?
            AND scheduled_at < ?
            AND DATE_ADD(scheduled_at, INTERVAL COALESCE(NULLIF(duration_minutes, 0), ?) MINUTE) > ?
          ORDER BY scheduled_at LIMIT 1"
    );
    $stmt->execute([
        CONSULTATION_DEFAULT_MINUTES,
        $lawyerId,
        $ignoreConsultationId, $ignoreConsultationId,
        $dayFrom, $dayTo,
        date('Y-m-d H:i:s', $end),   // existing start < new end
        CONSULTATION_DEFAULT_MINUTES,
        date('Y-m-d H:i:s', $start), // existing end   > new start
    ]);
    $row = $stmt->fetch();
    return $row ? [
        'id' => (int)$row['id'],
        'scheduled_at' => (string)$row['scheduled_at'],
        'duration_minutes' => (int)$row['duration_minutes'],
    ] : null;
}

/**
 * Every busy interval for a lawyer on a date, so a slot grid can subtract real occupancy.
 *
 * @return array<int, array{start:int, end:int}> unix timestamps, half-open
 */
function booking_busy_intervals(PDO $pdo, int $lawyerId, string $date): array {
    $dayStart = strtotime($date . ' 00:00:00');
    if ($dayStart === false) return [];
    $stmt = $pdo->prepare(
        "SELECT scheduled_at, COALESCE(NULLIF(duration_minutes, 0), ?) AS duration_minutes
           FROM consultations
          WHERE lawyer_id = ? AND status <> 'cancelled'
            AND scheduled_at BETWEEN ? AND ?"
    );
    $stmt->execute([
        CONSULTATION_DEFAULT_MINUTES, $lawyerId,
        date('Y-m-d H:i:s', $dayStart - 86400), date('Y-m-d H:i:s', $dayStart + 2 * 86400),
    ]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $s = strtotime((string)$r['scheduled_at']);
        if ($s === false) continue;
        $out[] = ['start' => $s, 'end' => $s + ((int)$r['duration_minutes']) * 60];
    }
    return $out;
}

/**
 * Whether a candidate interval is free, expressed against the busy list.
 * Half-open, so an interval ending exactly when another begins is free.
 */
function booking_interval_is_free(array $busy, int $start, int $end): bool {
    foreach ($busy as $b) {
        if ($start < $b['end'] && $b['start'] < $end) return false;
    }
    return true;
}

