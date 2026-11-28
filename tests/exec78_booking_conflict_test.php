<?php
/**
 * Execution 78 — double-booking prevention, proven on real rows.
 *
 * booking_slot_overlap()/booking_slot_is_bookable() already implemented an interval test, but
 * nothing asserted it: exec66_booking_engine_test.php does not mention overlap at all. A
 * booking engine that silently accepts a 60-minute appointment on top of an existing one is
 * the defect the owner asked to close ("منع التعارض"), so it is checked here against the
 * database, not against a reimplementation of the predicate.
 *
 * The cases that matter, and why each is a real trap:
 *   - same start, same lawyer            -> must be refused
 *   - a start inside a longer booking    -> refused (a start-time-only test allows this)
 *   - a shorter booking swallowing a start -> refused (the reverse direction)
 *   - back-to-back (starts exactly at end) -> ALLOWED; refusing it would block a full day
 *   - cancelled appointment              -> its time is free again
 *   - another lawyer, same time          -> allowed; the lawyer is who is busy
 *   - the booking's own id when re-checking -> must not conflict with itself
 *
 * Runs in a transaction and rolls back, so it leaves no residue.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/booking_slots.php';

$checks = 0;
$failures = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $checks, $failures;
    $checks++;
    if ($ok) { echo "  ok:   $label\n"; return; }
    $failures++;
    echo "  FAIL: $label" . ($detail !== '' ? " — $detail" : '') . "\n";
}

if (!defined('CONSULTATION_DEFAULT_MINUTES')) define('CONSULTATION_DEFAULT_MINUTES', 30);

$pdo->beginTransaction();
$suffix = bin2hex(random_bytes(4));
$lawyerA = $lawyerB = $client = 0;

/** A weekday datetime well in the future, so 'in_past' never masks the overlap verdict. */
function future_slot(int $offsetDays, string $time): string {
    $d = new DateTime('+30 days', new DateTimeZone('Asia/Riyadh'));
    while ((int)$d->format('w') === 5) { $d->modify('+1 day'); } // avoid Friday
    $d->modify('+' . max(0, $offsetDays) . ' days');
    return $d->format('Y-m-d') . ' ' . $time . ':00';
}

try {
    $pdo->prepare("INSERT INTO lawyers (full_name,email,password_hash,status,email_verified,jurisdiction) VALUES (?,?,?,'approved',1,'السعودية')")
        ->execute(['Conflict A ' . $suffix, "conflict-a-$suffix@example.com", password_hash('x', PASSWORD_DEFAULT)]);
    $lawyerA = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO lawyers (full_name,email,password_hash,status,email_verified,jurisdiction) VALUES (?,?,?,'approved',1,'السعودية')")
        ->execute(['Conflict B ' . $suffix, "conflict-b-$suffix@example.com", password_hash('x', PASSWORD_DEFAULT)]);
    $lawyerB = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO users (full_name,email,password_hash,status,email_verified) VALUES (?,?,?,'active',1)")
        ->execute(['Conflict client ' . $suffix, "conflict-c-$suffix@example.com", password_hash('x', PASSWORD_DEFAULT)]);
    $client = (int)$pdo->lastInsertId();

    /** Insert an appointment directly, so the test controls the exact interval. */
    $insert = function (int $lawyer, string $at, int $minutes, string $status = 'confirmed') use ($pdo, $client): int {
        $pdo->prepare(
            "INSERT INTO consultations (user_id,lawyer_id,consultation_type,scheduled_at,duration_minutes,status,payment_status,topic,created_at)
             VALUES (?,?,'video',?,?,?,'paid','conflict fixture',NOW())"
        )->execute([$client, $lawyer, $at, $minutes, $status]);
        return (int)$pdo->lastInsertId();
    };

    echo "== 1. the same start for the same lawyer is refused ==\n";
    $base = future_slot(0, '10:00');
    $existing = $insert($lawyerA, $base, 60);
    $r = booking_slot_is_bookable($pdo, $lawyerA, $base, null, 60);
    check('a duplicate start is not bookable', $r['ok'] === false, json_encode($r, JSON_UNESCAPED_UNICODE));
    check('the error names the taken slot', ($r['error'] ?? '') === 'slot_taken', $r['error'] ?? 'none');
    check('the conflicting row is reported', (int)($r['conflict']['id'] ?? 0) === $existing);

    echo "\n== 2. a start INSIDE a longer booking is refused (not just an equal start) ==\n";
    // existing 10:00-11:00; try 10:30 for 30 -> overlaps 10:30-11:00
    $inside = future_slot(0, '10:30');
    $r = booking_slot_is_bookable($pdo, $lawyerA, $inside, null, 30);
    check('10:30 inside a 10:00-11:00 booking is refused', $r['ok'] === false, json_encode($r, JSON_UNESCAPED_UNICODE));
    check('it is reported as an overlap, not a free slot', ($r['error'] ?? '') === 'slot_taken');

    echo "\n== 3. a shorter booking that would swallow the existing start is refused ==\n";
    // existing 11:30 (30m). Try 11:00 for 60 -> 11:00-12:00 covers 11:30.
    $late = $insert($lawyerA, future_slot(0, '11:30'), 30);
    $r = booking_slot_is_bookable($pdo, $lawyerA, future_slot(0, '11:00'), null, 60);
    check('11:00+60 over an 11:30 booking is refused', $r['ok'] === false, json_encode($r, JSON_UNESCAPED_UNICODE));

    echo "\n== 4. back-to-back is ALLOWED (a full day must stay bookable) ==\n";
    // existing 10:00-11:00; 11:00 starts exactly at its end -> allowed
    $r = booking_slot_is_bookable($pdo, $lawyerA, future_slot(0, '11:00'), null, 30);
    // 11:00-11:30 touches 11:30's start but does not overlap it
    check('11:00 (start == previous end) is bookable', $r['ok'] === true, json_encode($r, JSON_UNESCAPED_UNICODE));

    echo "\n== 5. a cancelled appointment frees its time ==\n";
    $paid = future_slot(1, '14:00');
    $cancelled = $insert($lawyerA, $paid, 60, 'cancelled');
    $r = booking_slot_is_bookable($pdo, $lawyerA, $paid, null, 60);
    check('a cancelled booking does not block its slot', $r['ok'] === true, json_encode($r, JSON_UNESCAPED_UNICODE));

    echo "\n== 6. another lawyer at the same time is unaffected ==\n";
    $r = booking_slot_is_bookable($pdo, $lawyerB, $base, null, 60);
    check('lawyer B can take 10:00 while lawyer A is busy', $r['ok'] === true, json_encode($r, JSON_UNESCAPED_UNICODE));

    echo "\n== 7. re-checking a booking does not conflict with itself ==\n";
    $r = booking_slot_is_bookable($pdo, $lawyerA, $base, $existing, 60);
    check('the booking\'s own id is ignored', $r['ok'] === true, json_encode($r, JSON_UNESCAPED_UNICODE));

    echo "\n== 8. a time in the past is refused before any overlap check ==\n";
    $r = booking_slot_is_bookable($pdo, $lawyerA, date('Y-m-d H:i:s', time() - 3600), null, 30);
    check('a past time is refused', $r['ok'] === false);
    check('the reason is in_past, not slot_taken', ($r['error'] ?? '') === 'in_past', $r['error'] ?? 'none');

    echo "\n== 9. an overnight booking blocks the next morning ==\n";
    // 22:30 for 120m -> 22:30-00:30 next day. 00:15 belongs to the previous day's consult.
    $night = future_slot(2, '22:30');
    $insert($lawyerA, $night, 120);
    $nextMorning = (new DateTime($night))->modify('+1 day')->format('Y-m-d') . ' 00:15:00';
    $r = booking_slot_is_bookable($pdo, $lawyerA, $nextMorning, null, 30);
    check('00:15 the next day overlaps a 22:30 + 120m booking', $r['ok'] === false, json_encode($r, JSON_UNESCAPED_UNICODE));

    echo "\n== 10. the interval predicate itself agrees with the SQL ==\n";
    $o = booking_slot_overlap($pdo, $lawyerA, $base, 60);
    check('overlap returns the occupying row', ($o['id'] ?? 0) === $existing);
    $o = booking_slot_overlap($pdo, $lawyerA, future_slot(3, '09:00'), 60);
    check('overlap returns null for a genuinely free interval', $o === null);
} finally {
    $pdo->rollBack();
    // Nothing was committed; a rollback leaves the schema and the seed untouched.
}

echo "\n" . str_repeat('-', 60) . "\n";
echo "  Execution 78 booking conflict: $checks checks, $failures failure(s)\n";
exit($failures > 0 ? 1 : 0);
