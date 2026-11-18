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
 * @return array{ok:bool, error?:string}
 */
function booking_slot_is_bookable(PDO $pdo, int $lawyerId, string $scheduledAt, ?int $ignoreConsultationId = null): array {
    $ts = strtotime($scheduledAt);
    if ($ts === false) return ['ok' => false, 'error' => 'invalid_datetime'];
    if ($ts <= time()) return ['ok' => false, 'error' => 'in_past'];

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
            return ['ok' => false, 'error' => 'slot_taken'];
        }
    }

    // No hours published: still refuse a time already taken by an active booking for this
    // lawyer, so the free-form path cannot double-book either.
    $dup = $pdo->prepare("SELECT COUNT(*) FROM consultations
        WHERE lawyer_id = ? AND scheduled_at = ? AND status != 'cancelled'
          AND (? = 0 OR id <> ?)");
    $dup->execute([$lawyerId, $scheduledAt, (int)$ignoreConsultationId, (int)$ignoreConsultationId]);
    if ((int)$dup->fetchColumn() > 0) {
        return ['ok' => false, 'error' => 'slot_taken'];
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
 * @param callable $insert function(): int
 * @return array{ok:bool, id?:int, error?:string}
 */
function booking_create_locked(PDO $pdo, int $lawyerId, string $scheduledAt, callable $insert): array {
    $out = booking_with_slot_lock($pdo, $lawyerId, $scheduledAt, function () use ($pdo, $lawyerId, $scheduledAt, $insert) {
        $check = booking_slot_is_bookable($pdo, $lawyerId, $scheduledAt);
        if (!$check['ok']) return ['__error' => $check['error']];
        $id = (int)$insert();
        return ['__id' => $id];
    });

    if (!$out['ok']) {
        return ['ok' => false, 'error' => $out['error']];
    }
    $res = $out['result'];
    if (isset($res['__error'])) return ['ok' => false, 'error' => $res['__error']];
    return ['ok' => true, 'id' => (int)($res['__id'] ?? 0)];
}

/**
 * True when this lawyer has already confirmed a *different* consultation at this exact time.
 * Used by the lawyer/admin side before a reschedule is accepted.
 */
function booking_slot_conflicts(PDO $pdo, int $lawyerId, string $scheduledAt, int $ignoreConsultationId = 0): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM consultations
        WHERE lawyer_id = ? AND scheduled_at = ? AND status IN ('pending','confirmed')
          AND (? = 0 OR id <> ?)");
    $stmt->execute([$lawyerId, $scheduledAt, $ignoreConsultationId, $ignoreConsultationId]);
    return (int)$stmt->fetchColumn() > 0;
}
