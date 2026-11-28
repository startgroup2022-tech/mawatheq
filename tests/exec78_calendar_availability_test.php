<?php
/**
 * Execution 78 — the published-hours calendar returns real slots instead of a 500.
 *
 * This began as a real defect found while proving the booking journey: `includes/availability.php`
 * reads CONSULTATION_DEFAULT_MINUTES on line 27 but never required `consultation_pricing.php`,
 * which declares it. The bug hid behind the empty case -- a lawyer with no published hours
 * returned `[]` from the `if (empty($windows)) return [];` short-circuit *above* line 27, so
 * the endpoint answered a cheerful `{"ok":true,"slots":[]}`. The moment a lawyer published
 * hours, every calendar request reached the undefined constant and died with a fatal 500:
 *
 *   {"ok":false,"error":"حدث خطأ في الخادم. يرجى المحاولة لاحقاً."}
 *
 * on both `/api/v1/available-slots.php` and the legacy `/api/available_slots.php`. That is the
 * "no times available" the customer would meet at the last step of a booking.
 *
 * The suite seeds one day of published hours, then asserts over real HTTP that the slots are
 * returned, that a taken interval disappears (including the 30 minutes a 60-minute booking
 * swallows), and that a cancelled booking gives the time back. Everything it creates is
 * removed in `finally`.
 */

require_once __DIR__ . '/../config/config.php';

$checks = 0;
$failures = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $checks, $failures;
    $checks++;
    if ($ok) { echo "  ok:   $label\n"; return; }
    $failures++;
    echo "  FAIL: $label" . ($detail !== '' ? " — $detail" : '') . "\n";
}

function fetch_json(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
    $raw = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode($raw, true), $raw];
}

const V1 = 'http://127.0.0.1:8080/api/v1/available-slots.php';
const LEGACY = 'http://127.0.0.1:8080/api/available_slots.php';

$lawyerId = 0;
$topic = 'exec78 calendar probe';
$seededDow = null;

try {
    echo "== 0. the module declares the constant it uses ==\n";
    $src = (string)file_get_contents(__DIR__ . '/../includes/availability.php');
    check('availability.php requires consultation_pricing.php',
        (bool)preg_match("~require_once[^;]*consultation_pricing\\.php~", $src),
        'a missing require is exactly the defect this suite exists for');

    $lawyerId = (int)$pdo->query("SELECT id FROM lawyers WHERE status='approved' AND email_verified=1 ORDER BY id LIMIT 1")->fetchColumn();
    check('an approved lawyer exists', $lawyerId > 0);
    if ($lawyerId === 0) { throw new RuntimeException('no approved lawyer'); }
    $userId = (int)$pdo->query("SELECT id FROM users WHERE email='client-a@example.com'")->fetchColumn();

    // A fixed weekday well ahead of today, so "already past" filtering cannot hide a slot.
    $d = new DateTime('+21 days', new DateTimeZone('Asia/Riyadh'));
    while ((int)$d->format('w') === 5) { $d->modify('+1 day'); }   // skip Friday
    $date = $d->format('Y-m-d');
    $seededDow = (int)$d->format('w');

    echo "\n== 1. with no published hours the answer is an empty list, not an error ==\n";
    [$c0, $j0] = fetch_json(V1 . "?lawyer_id=$lawyerId&date=$date");
    check('the endpoint answers 200', $c0 === 200, "got $c0");
    check('it reports ok', ($j0['ok'] ?? false) === true);
    check('with no slots', is_array($j0['slots'] ?? null) && count($j0['slots']) === 0);

    echo "\n== 2. publishing hours makes the calendar return real slots ==\n";
    $pdo->prepare('INSERT INTO lawyer_availability (lawyer_id,day_of_week,start_time,end_time,slot_minutes) VALUES (?,?,?,?,?)')
        ->execute([$lawyerId, $seededDow, '09:00:00', '12:00:00', 30]);
    [$c1, $j1, $r1] = fetch_json(V1 . "?lawyer_id=$lawyerId&date=$date");
    check('the endpoint still answers 200 with hours published', $c1 === 200, "got $c1: " . substr($r1, 0, 120));
    check('it reports ok (this is what used to be a 500)', ($j1['ok'] ?? false) === true, substr($r1, 0, 120));
    $slots = $j1['slots'] ?? [];
    check('six 30-minute slots fit in 09:00-12:00', count($slots) === 6, count($slots) . ' slots');
    check('09:00 is offered', in_array('09:00', $slots, true));
    check('11:30 is offered', in_array('11:30', $slots, true));
    check('12:00 is not offered (it is the end of the window)', !in_array('12:00', $slots, true));

    echo "\n== 3. a 30-minute booking removes exactly its own slot ==\n";
    $ins = $pdo->prepare('INSERT INTO consultations (user_id,lawyer_id,consultation_type,consultation_mode,scheduled_at,duration_minutes,topic,status,price,payment_status,created_at)
                          VALUES (?,?,?,?,?,?,?,?,?,?,NOW())');
    $ins->execute([$userId, $lawyerId, 'video', 'remote', "$date 10:00:00", 30, $topic, 'pending', 0, 'unpaid']);
    $booked30 = (int)$pdo->lastInsertId();
    [, $j2] = fetch_json(V1 . "?lawyer_id=$lawyerId&date=$date");
    $s2 = $j2['slots'] ?? [];
    check('10:00 is gone', !in_array('10:00', $s2, true));
    check('10:30 is still free', in_array('10:30', $s2, true));
    check('five slots remain', count($s2) === 5, count($s2) . ' slots');

    echo "\n== 4. a 60-minute booking removes both half-hours it covers ==\n";
    $pdo->prepare('DELETE FROM consultations WHERE id=?')->execute([$booked30]);
    $ins->execute([$userId, $lawyerId, 'video', 'remote', "$date 10:00:00", 60, $topic, 'pending', 0, 'unpaid']);
    $booked60 = (int)$pdo->lastInsertId();
    [, $j3] = fetch_json(V1 . "?lawyer_id=$lawyerId&date=$date");
    $s3 = $j3['slots'] ?? [];
    check('10:00 is gone', !in_array('10:00', $s3, true));
    check('10:30 is gone too (it sits inside 10:00-11:00)', !in_array('10:30', $s3, true),
        'a start-only comparison leaves 10:30 in the list');
    check('11:00 is still free', in_array('11:00', $s3, true));

    echo "\n== 5. a cancelled booking gives the time back ==\n";
    $pdo->prepare("UPDATE consultations SET status='cancelled' WHERE id=?")->execute([$booked60]);
    [, $j4] = fetch_json(V1 . "?lawyer_id=$lawyerId&date=$date");
    $s4 = $j4['slots'] ?? [];
    check('10:00 is offered again', in_array('10:00', $s4, true));
    check('10:30 is offered again', in_array('10:30', $s4, true));
    check('all six slots are back', count($s4) === 6, count($s4) . ' slots');

    echo "\n== 6. the legacy endpoint was broken the same way and is fixed too ==\n";
    [$c5, $j5] = fetch_json(LEGACY . "?lawyer_id=$lawyerId&date=$date");
    check('the legacy endpoint answers 200', $c5 === 200, "got $c5");
    check('it returns the same slots', ($j5['slots'] ?? []) === $s4);
} catch (Throwable $e) {
    $checks++;
    $failures++;
    echo "  FAIL: unexpected error — " . $e->getMessage() . "\n";
} finally {
    $pdo->prepare('DELETE FROM consultations WHERE topic=?')->execute([$topic]);
    if ($lawyerId > 0 && $seededDow !== null) {
        $pdo->prepare('DELETE FROM lawyer_availability WHERE lawyer_id=? AND day_of_week=?')
            ->execute([$lawyerId, $seededDow]);
    }
}

echo "\n" . str_repeat('-', 60) . "\n";
echo "  Execution 78 calendar availability: $checks checks, $failures failure(s)\n";
exit($failures > 0 ? 1 : 0);
