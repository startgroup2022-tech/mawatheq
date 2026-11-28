<?php
/**
 * Execution 69 — حجوزات: the calendar must not offer a time the lawyer cannot attend.
 *
 * The engine in includes/booking_slots.php compared *start times*. Two bookings clashed only
 * when they began at the same minute, so with a 30-minute grid and a 60-minute consultation:
 *
 *     book 10:00 for 60 minutes  →  the grid still offered 10:30  →  accepted  →  double-booked
 *
 * and a short slot could be dropped inside a long one. This suite proves the interval model
 * from both directions: the grid it offers, and the check that guards the INSERT. It also
 * pins the boundaries, because the cheap way to "fix" overlap is to use <=, which makes
 * back-to-back appointments impossible and is a different bug.
 *
 * Runs inside a transaction and rolls back, so it leaves no residue.
 *
 * Run: php tests/exec69_booking_conflict_test.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/booking_slots.php';

$failures = 0; $checks = 0;
function check(string $label, bool $ok, string $extra = ''): void {
    global $failures, $checks;
    $checks++;
    if ($ok) { echo "  ok:   $label\n"; }
    else { $failures++; echo "  FAIL: $label" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

$pdo->beginTransaction();

// A lawyer isolated to this test: a fixed weekday grid, no other rows.
$day = (int)date('w', strtotime('+7 days'));
$date = date('Y-m-d', strtotime('+7 days'));
$start = $date . ' 09:00:00';

$pdo->prepare("INSERT INTO lawyers (email, password_hash, full_name, status, email_verified) VALUES (?,?,?,'approved',1)")
    ->execute(['exec69conflict@example.test', password_hash('x', PASSWORD_DEFAULT), 'محامي التعارض']);
$lawyerId = (int)$pdo->lastInsertId();

// 09:00–13:00 in 30-minute slots: eight candidate starts.
$pdo->prepare('INSERT INTO lawyer_availability (lawyer_id, day_of_week, start_time, end_time, slot_minutes) VALUES (?,?,?,?,30)')
    ->execute([$lawyerId, $day, '09:00:00', '13:00:00']);

$clientId = (int)$pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
if ($clientId <= 0) {
    echo "  SKIP: no seeded client\n";
    $pdo->rollBack();
    echo "\n{$checks} checks, {$failures} failure(s)\n";
    exit($failures === 0 ? 0 : 1);
}

function make_booking(PDO $pdo, int $lawyerId, int $clientId, string $at, int $minutes): int {
    $pdo->prepare("INSERT INTO consultations (user_id, lawyer_id, scheduled_at, duration_minutes, topic, price, status)
                   VALUES (?,?,?,?,?,0,'confirmed')")
        ->execute([$clientId, $lawyerId, $at, $minutes, 'اختبار تعارض']);
    return (int)$pdo->lastInsertId();
}

echo "\n== 1. the grid offers every slot when the day is empty ==\n";
$slots = get_available_slots($pdo, $lawyerId, $date);
check('eight 30-minute slots are offered', count($slots) === 8, implode(',', $slots));
check('the first slot is 09:00', ($slots[0] ?? '') === '09:00', (string)($slots[0] ?? ''));
check('the last slot is 12:30', (end($slots) ?: '') === '12:30', (string)(end($slots) ?: ''));

echo "\n== 2. a 60-minute booking removes the slot it covers (the reported defect) ==\n";
$longId = make_booking($pdo, $lawyerId, $clientId, $start, 60);
$slots = get_available_slots($pdo, $lawyerId, $date);
check('09:00 is gone', !in_array('09:00', $slots, true), implode(',', $slots));
check('09:30 is gone because the 60-minute booking still occupies it', !in_array('09:30', $slots, true), implode(',', $slots));
check('10:00 remains free (the booking ended exactly then)', in_array('10:00', $slots, true), implode(',', $slots));
check('the grid shrank by exactly two slots', count($slots) === 6, (string)count($slots));

echo "\n== 3. the authoritative check refuses an overlapping interval ==\n";
$overlap = booking_slot_is_bookable($pdo, $lawyerId, $date . ' 09:30:00', null, 30);
check('booking inside a 60-minute appointment is refused', $overlap['ok'] === false, json_encode($overlap));
check('the refusal is reported as slot_taken', ($overlap['error'] ?? '') === 'slot_taken', (string)($overlap['error'] ?? ''));
check('the refusal names the clashing booking', (int)($overlap['conflict']['id'] ?? 0) === $longId, json_encode($overlap['conflict'] ?? null));

$backToBack = booking_slot_is_bookable($pdo, $lawyerId, $date . ' 10:00:00', null, 30);
check('a back-to-back booking at the exact end is allowed', $backToBack['ok'] === true, json_encode($backToBack));

$insideLong = make_booking($pdo, $lawyerId, $clientId, $date . ' 10:00:00', 90);
$shortInside = booking_slot_is_bookable($pdo, $lawyerId, $date . ' 10:30:00', null, 30);
check('a short slot cannot be dropped inside a longer booking', $shortInside['ok'] === false, json_encode($shortInside));

echo "\n== 4. half-open boundaries: no phantom conflict, no gap ==\n";
check('overlapping by one minute is refused',
    booking_slot_overlap($pdo, $lawyerId, $date . ' 10:29:00', 30) !== null);
check('starting exactly at the end is not a conflict',
    booking_slot_overlap($pdo, $lawyerId, $date . ' 11:30:00', 30) === null);

echo "\n== 5. the busy-interval helper agrees with the SQL check ==\n";
$busy = booking_busy_intervals($pdo, $lawyerId, $date);
check('both bookings are in the busy set', count($busy) === 2, (string)count($busy));
$s9 = strtotime($date . ' 09:00:00');
check('the helper sees 09:00–10:00 as busy', !booking_interval_is_free($busy, $s9 + 1800, $s9 + 3600));
check('the helper sees 12:00 as free', booking_interval_is_free($busy, strtotime($date . ' 12:00:00'), strtotime($date . ' 12:30:00')));

echo "\n== 6. a cancelled booking frees its time ==\n";
$pdo->prepare("UPDATE consultations SET status='cancelled' WHERE id=?")->execute([$longId]);
check('a cancelled interval no longer conflicts',
    booking_slot_overlap($pdo, $lawyerId, $date . ' 09:30:00', 30) === null);
$slots = get_available_slots($pdo, $lawyerId, $date);
check('the cancelled time returns to the grid', in_array('09:00', $slots, true), implode(',', $slots));

echo "\n== 7. a row with no recorded duration is treated as a real appointment ==\n";
// duration_minutes NULL/0 must not collapse to a zero-length interval, or it could be
// overlapped by anything.
$pdo->prepare('DELETE FROM consultations WHERE lawyer_id=?')->execute([$lawyerId]);
$pdo->prepare("INSERT INTO consultations (user_id, lawyer_id, scheduled_at, duration_minutes, topic, price, status)
               VALUES (?,?,?,NULL,?,0,'confirmed')")
    ->execute([$clientId, $lawyerId, $date . ' 09:00:00', 'بلا مدة']);
$nullDur = booking_slot_is_bookable($pdo, $lawyerId, $date . ' 09:15:00', null, 30);
check('a NULL duration still blocks its interval', $nullDur['ok'] === false, json_encode($nullDur));
check('the default duration was applied', (int)(booking_slot_overlap($pdo, $lawyerId, $date . ' 09:15:00', 30)['duration_minutes'] ?? 0) === CONSULTATION_DEFAULT_MINUTES);

echo "\n== 8. the locked create refuses before it writes ==\n";
$pdo->prepare('DELETE FROM consultations WHERE lawyer_id=?')->execute([$lawyerId]);
make_booking($pdo, $lawyerId, $clientId, $date . ' 11:00:00', 60);
$before = (int)$pdo->query('SELECT COUNT(*) FROM consultations WHERE lawyer_id=' . $lawyerId)->fetchColumn();
$result = booking_create_locked($pdo, $lawyerId, $date . ' 11:30:00', function () use ($pdo, $lawyerId, $clientId, $date) {
    return make_booking($pdo, $lawyerId, $clientId, $date . ' 11:30:00', 30);
}, 30);
$after = (int)$pdo->query('SELECT COUNT(*) FROM consultations WHERE lawyer_id=' . $lawyerId)->fetchColumn();
check('the locked create refuses the overlap', $result['ok'] === false, json_encode($result));
check('it reports slot_taken', ($result['error'] ?? '') === 'slot_taken', (string)($result['error'] ?? ''));
check('nothing was written', $after === $before, "$before -> $after");

$ok = booking_create_locked($pdo, $lawyerId, $date . ' 12:00:00', function () use ($pdo, $lawyerId, $clientId, $date) {
    return make_booking($pdo, $lawyerId, $clientId, $date . ' 12:00:00', 30);
}, 30);
check('the locked create accepts a free slot', $ok['ok'] === true, json_encode($ok));
check('it returns the new id', (int)($ok['id'] ?? 0) > 0);

echo "\n== 9. a booking that would run past the window is not offered ==\n";
$pdo->prepare('DELETE FROM consultations WHERE lawyer_id=?')->execute([$lawyerId]);
// Window ends 13:00; a 60-minute booking at 12:30 would run to 13:30.
$late = booking_slot_is_bookable($pdo, $lawyerId, $date . ' 12:30:00', null, 60);
check('a 60-minute booking past the closing time is refused', $late['ok'] === false, json_encode($late));
check('the reason is outside_hours', ($late['error'] ?? '') === 'outside_hours', (string)($late['error'] ?? ''));

$pdo->rollBack();
echo "\n{$checks} checks, {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
