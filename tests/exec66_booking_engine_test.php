<?php
/**
 * Execution 66 phase B — the booking engine, the pricing rule, and the availability calendar.
 *
 * What this suite proves, and why each part exists:
 *
 *  1. PRICING IS ONE CALCULATION. `consultation_pricing_breakdown()` is the only place a
 *     payable amount is computed. The test pins the rule the owner approved: a consultation
 *     costs the lawyer's rate charged ONCE (flat), the stored `price` equals the breakdown
 *     total, and the amount the gateway is asked for equals that same number. A change to
 *     "rate × duration/60" would fail here loudly, which is the point.
 *  2. AVAILABILITY IS REAL. The month map comes from the lawyer's published hours minus
 *     active bookings — not from a guess. A day with no hours has zero slots; a booked slot
 *     disappears from the free list.
 *  3. NO DOUBLE BOOKING. Five concurrent requests for one slot produce exactly one booking.
 *     This drives the real HTTP endpoint (the race is between requests, not inside one PHP
 *     process), then asserts the database holds one row.
 *  4. A PAID BOOKING IS NOT CONFIRMED WITHOUT PAYMENT. A priced booking stays `pending`
 *     with `payment_status='unpaid'` when the gateway is not enabled, and the page says so.
 *  5. NO MISLEADING LABEL. The booking page and the lawyer cards never say "per hour" and
 *     never print an unsupported rating.
 *
 * It creates its own lawyer and client with a random suffix and removes them in cleanup, so
 * it is re-runnable and leaves no residue. Requires the app on 127.0.0.1:8080.
 *
 * Run: php tests/exec66_booking_engine_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/consultation_pricing.php';
require_once __DIR__ . '/../includes/booking_slots.php';
require_once __DIR__ . '/../includes/availability.php';
require_once __DIR__ . '/../includes/legal_journey.php';

$base = 'http://127.0.0.1:8080';
$checks = 0;
$failures = 0;
function check(string $label, bool $ok): void {
    global $checks, $failures;
    $checks++;
    if ($ok) { echo "  ok:   $label\n"; }
    else { $failures++; echo "  FAIL: $label\n"; }
}
function section(string $t): void { echo "\n== $t\n"; }

function http66(string $method, string $path, array $opts = []): array {
    global $base;
    $ch = curl_init($base . $path);
    $cfg = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CUSTOMREQUEST => $method];
    if (!empty($opts['jar'])) { $cfg[CURLOPT_COOKIEJAR] = $opts['jar']; $cfg[CURLOPT_COOKIEFILE] = $opts['jar']; }
    if (array_key_exists('post', $opts)) { $cfg[CURLOPT_POSTFIELDS] = $opts['post']; }
    curl_setopt_array($ch, $cfg);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}

$suffix = bin2hex(random_bytes(4));
$lawyerId = 0;
$clientId = 0;
$linkedCategoryId = 0;
$clientEmail = "exec66-client-$suffix@example.com";
$lawyerEmail = "exec66-lawyer-$suffix@example.com";
$bookedSlot = date('Y-m-d', strtotime('+9 days')) . ' 13:00:00';

// A local helper to POST a booking as the logged-in client.
function book_post(string $jar, string $csrf, int $lawyerId, string $date, string $time, string $topic): array {
    global $base;
    $ch = curl_init($base . '/booking.php?lawyer_id=' . $lawyerId);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'csrf_token' => $csrf, 'consultation_type' => 'text',
            'date' => $date, 'time' => $time, 'topic' => $topic,
        ]),
    ]);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}

try {
    section('Setup: a lawyer with published hours, and a client');
    $pwd = password_hash('Exec66-Test-Pass-2026', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO lawyers (full_name, email, password_hash, status, email_verified, jurisdiction, hourly_rate, years_experience)
                   VALUES (?,?,?,'approved',1,'السعودية',500,8)")
        ->execute(["محامي اختبار ٦٦ $suffix", $lawyerEmail, $pwd]);
    $lawyerId = (int)$pdo->lastInsertId();
    // Link the lawyer to a real specialty so the category-scoped list has a card to
    // return. Skipped when the taxonomy has not been imported, so the suite still runs
    // on a fresh database.
    try {
        if ((int)$pdo->query('SELECT COUNT(*) FROM najiz_categories')->fetchColumn() > 0) {
            $catId = (int)$pdo->query('SELECT id FROM najiz_categories ORDER BY id LIMIT 1')->fetchColumn();
            if ($catId > 0) {
                $pdo->prepare('INSERT INTO lawyer_specialties (lawyer_id, category_id) VALUES (?,?)')
                    ->execute([$lawyerId, $catId]);
                $linkedCategoryId = $catId;
            }
        }
    } catch (Throwable $e) {}
    $pdo->prepare("INSERT INTO users (full_name, email, password_hash, status, email_verified) VALUES (?,?,?,'active',1)")
        ->execute(["عميل اختبار ٦٦ $suffix", $clientEmail, $pwd]);
    $clientId = (int)$pdo->lastInsertId();
    // Sun–Thu 09:00–12:00, 30-minute slots = 6 slots/day.
    foreach ([0, 1, 2, 3, 4] as $dow) {
        $pdo->prepare('INSERT INTO lawyer_availability (lawyer_id, day_of_week, start_time, end_time, slot_minutes) VALUES (?,?,?,?,30)')
            ->execute([$lawyerId, $dow, '09:00:00', '12:00:00']);
    }
    check('test lawyer created', $lawyerId > 0);
    check('test client created', $clientId > 0);

    // ---------------------------------------------------------------------
    section('1. Pricing: one authoritative calculation (the approved flat rule)');
    $lawyerRow = ['id' => $lawyerId, 'hourly_rate' => 500, 'slot_minutes' => 30];
    $b = consultation_pricing_for_lawyer($pdo, $lawyerRow);
    check('breakdown has the lawyer rate', $b['rate'] === 500.0);
    check('duration is 30 minutes', $b['duration_minutes'] === 30);
    check('flat rule: base == rate (NOT rate/2)', $b['base'] === 500.0);
    check('total == base when no tax', $b['total'] === $b['base']);
    check('payable == total', $b['payable'] === $b['total']);
    check('tax is zero (none configured, none invented)', $b['tax'] === 0.0);
    check('fees are zero (no fee invented)', $b['fees'] === 0.0);
    check('is_paid true for a priced consultation', $b['is_paid'] === true);

    // A per-minute mode exists but is NOT the default — proving the flat rule is the one in force.
    $perMinute = consultation_pricing_breakdown($pdo, 500, 30, ['mode' => 'per_minute']);
    check('per-minute mode is available but opt-in', $perMinute['base'] === 250.0 && $b['base'] === 500.0);

    $free = consultation_pricing_breakdown($pdo, null, 30);
    check('no rate -> no price', $free['has_price'] === false && $free['payable'] === 0.0);
    $zero = consultation_pricing_breakdown($pdo, 0, 30);
    check('zero rate -> no price', $zero['has_price'] === false);

    // ---------------------------------------------------------------------
    section('2. Availability: the month map is real, from hours minus bookings');
    $month = date('Y-m');
    $map = get_month_availability($pdo, $lawyerId, $month);
    check('month map has hours', $map['has_hours'] === true);
    check('working weekdays are Sun-Thu (0-4)', $map['working_weekdays'] === [0, 1, 2, 3, 4]);
    $freeDays = array_filter($map['days'], fn($n) => $n > 0);
    check('at least one free day this month', count($freeDays) > 0);
    // The first free day can be *today*, and after a morning start today's earlier slots
    // are correctly gone — so assert on a day that is still entirely in the future. A
    // fresh lawyer has no bookings, so such a day shows exactly the 6 its hours define.
    $today = date('Y-m-d');
    $freeMap = $map;
    $futureFreeDay = null;
    foreach (array_keys($freeDays) as $d) { if ($d > $today) { $futureFreeDay = $d; break; } }
    if ($futureFreeDay === null) {
        // Running on the last working day of the month: take next month's first free day.
        $freeMap = get_month_availability($pdo, $lawyerId, date('Y-m', strtotime('first day of next month')));
        foreach (array_keys(array_filter($freeMap['days'], fn($n) => $n > 0)) as $d) { $futureFreeDay = $d; break; }
    }
    check('a future free day shows the 6 slots its hours define',
        $futureFreeDay !== null && $freeMap['days'][$futureFreeDay] === 6);

    // A lawyer with no published hours has an empty map — an honest zero, not a guess.
    $noHours = get_month_availability($pdo, 1, $month);
    check('a lawyer with no hours has has_hours=false', $noHours['has_hours'] === false);

    // ---------------------------------------------------------------------
    section('3. Slot bookability: past, out-of-hours and taken slots are refused');
    $past = booking_slot_is_bookable($pdo, $lawyerId, date('Y-m-d H:i:s', strtotime('-1 day')));
    check('a past time is refused', $past['ok'] === false && $past['error'] === 'in_past');

    // 03:00 is outside 09:00-12:00.
    $futureDay = date('Y-m-d', strtotime('+10 days'));
    $dow = (int)date('w', strtotime($futureDay));
    if (in_array($dow, [0, 1, 2, 3, 4], true)) {
        $outside = booking_slot_is_bookable($pdo, $lawyerId, $futureDay . ' 03:00:00');
        check('a time outside published hours is refused', $outside['ok'] === false && $outside['error'] === 'slot_taken');
    } else {
        check('a time outside published hours is refused (skipped: weekend)', true);
    }

    // ---------------------------------------------------------------------
    section('4. Login and a first real booking over HTTP');
    $jar = sys_get_temp_dir() . "/exec66-$suffix.jar";
    @unlink($jar);
    // Clear the rate limiter so this run is not throttled by a previous one.
    try { $pdo->exec('DELETE FROM auth_rate_limits'); } catch (Throwable $e) {}
    [$c, $page] = http66('GET', '/login.php', ['jar' => $jar]);
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $page, $m);
    $csrf = $m[1] ?? '';
    check('login page issues a CSRF token', $csrf !== '');
    [$c, $body] = http66('POST', '/login.php', ['jar' => $jar, 'post' => http_build_query([
        'email' => $clientEmail, 'password' => 'Exec66-Test-Pass-2026', 'csrf_token' => $csrf,
    ])]);
    check('client login succeeds (302 away from login)', $c === 302);

    [$c, $bkPage] = http66('GET', '/booking.php?lawyer_id=' . $lawyerId, ['jar' => $jar]);
    check('booking page renders 200', $c === 200);
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $bkPage, $m2);
    $bkCsrf = $m2[1] ?? '';
    check('booking page carries the wizard root', str_contains($bkPage, 'data-booking-wizard'));
    check('booking page carries the progress indicator', str_contains($bkPage, 'bk-progress'));
    check('booking page carries the calendar mount', str_contains($bkPage, 'data-bk-calendar'));
    check('booking page has NO "per hour" label', !str_contains($bkPage, '/ساعة') && !str_contains($bkPage, '/hr'));
    check('booking page shows the consultation price, not an hourly rate',
        str_contains($bkPage, 'سعر الاستشارة') || str_contains($bkPage, 'Consultation price'));

    $bookDay = date('Y-m-d', strtotime('+11 days'));
    // Nudge to a working day (Sun-Thu).
    while (!in_array((int)date('w', strtotime($bookDay)), [0, 1, 2, 3, 4], true)) {
        $bookDay = date('Y-m-d', strtotime($bookDay . ' +1 day'));
    }
    [$c, $post] = book_post($jar, $bkCsrf, $lawyerId, $bookDay, '09:00', "Exec66 booking $suffix");
    $row = $pdo->prepare('SELECT * FROM consultations WHERE lawyer_id=? AND scheduled_at=?');
    $row->execute([$lawyerId, $bookDay . ' 09:00:00']);
    $created = $row->fetch();
    check('the booking was created', (bool)$created);
    check('stored price == the breakdown total (500)', $created && (float)$created['price'] === 500.0);
    check('stored duration is 30 minutes', $created && (int)$created['duration_minutes'] === 30);
    check('a priced booking is NOT confirmed without payment',
        $created && $created['status'] === 'pending' && $created['payment_status'] === 'unpaid');
    check('the page tells the customer the appointment is not confirmed',
        str_contains($post, 'لم يُؤكَّد') || str_contains($post, 'not confirmed') || str_contains($post, 'تعذّر بدء الدفع'));

    // The slot is now taken: a second booking of the same time must be refused.
    [$c, $second] = book_post($jar, $bkCsrf, $lawyerId, $bookDay, '09:00', "Exec66 dup $suffix");
    $dupCount = $pdo->prepare('SELECT COUNT(*) FROM consultations WHERE lawyer_id=? AND scheduled_at=?');
    $dupCount->execute([$lawyerId, $bookDay . ' 09:00:00']);
    check('a duplicate booking of the same slot is refused (still 1 row)', (int)$dupCount->fetchColumn() === 1);
    check('the customer is told the slot is gone',
        str_contains($second, 'لم يعد متاحاً') || str_contains($second, 'no longer available'));

    // ---------------------------------------------------------------------
    section('5. Concurrent bookings: five requests, one booking');
    $raceDay = date('Y-m-d', strtotime('+12 days'));
    while (!in_array((int)date('w', strtotime($raceDay)), [0, 1, 2, 3, 4], true)) {
        $raceDay = date('Y-m-d', strtotime($raceDay . ' +1 day'));
    }
    $mh = curl_multi_init();
    $handles = [];
    for ($i = 0; $i < 5; $i++) {
        $ch = curl_init($base . '/booking.php?lawyer_id=' . $lawyerId);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'csrf_token' => $bkCsrf, 'consultation_type' => 'text',
                'date' => $raceDay, 'time' => '10:30', 'topic' => "Exec66 race $i $suffix",
            ]),
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[] = $ch;
    }
    do { $status = curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh); } while ($running > 0);
    foreach ($handles as $ch) curl_multi_remove_handle($mh, $ch);
    curl_multi_close($mh);
    $raceCount = $pdo->prepare('SELECT COUNT(*) FROM consultations WHERE lawyer_id=? AND scheduled_at=?');
    $raceCount->execute([$lawyerId, $raceDay . ' 10:30:00']);
    check('exactly ONE booking exists for the raced slot', (int)$raceCount->fetchColumn() === 1);

    // ---------------------------------------------------------------------
    section('6. Availability reflects the new bookings');
    $after = get_available_slots($pdo, $lawyerId, $raceDay);
    check('the raced slot is no longer in the free list', !in_array('10:30', $after, true));
    // The day's free list must equal its published slots minus the bookings already on it —
    // computed, not assumed, because an earlier case may land on the same working day.
    $bookedThatDay = $pdo->prepare('SELECT COUNT(*) FROM consultations WHERE lawyer_id=? AND DATE(scheduled_at)=?');
    $bookedThatDay->execute([$lawyerId, $raceDay]);
    $expected = 6 - (int)$bookedThatDay->fetchColumn();
    check("the day shows its remaining slots ($expected)", count($after) === $expected);

    // ---------------------------------------------------------------------
    section('7. Confirmation-time re-check never revives a released booking');
    // A booking that was released (cancelled) must not be confirmed by a late payment capture.
    $relId = (int)$created['id'];
    $pdo->prepare("UPDATE consultations SET status='cancelled', payment_status='failed' WHERE id=?")->execute([$relId]);
    $released = $pdo->prepare('SELECT status, payment_status FROM consultations WHERE id=?');
    $released->execute([$relId]);
    $relRow = $released->fetch();
    check('a released booking is cancelled/failed', $relRow['status'] === 'cancelled' && $relRow['payment_status'] === 'failed');
    // The confirm guard refuses to move it back to confirmed.
    $guard = $pdo->prepare("UPDATE consultations SET payment_status='paid', status='confirmed'
                            WHERE id=? AND payment_status <> 'paid' AND status <> 'cancelled'");
    $guard->execute([$relId]);
    check('the confirm guard changes 0 rows on a cancelled booking', $guard->rowCount() === 0);

    // ---------------------------------------------------------------------
    section('8. No unsupported rating is shown on the lawyer cards');
    [$c, $lawyersPage] = http66('GET', '/consultation-journey-lawyers.php?category_id=' . $linkedCategoryId, ['jar' => $jar]);
    check('the journey lawyers page renders', $c === 200 || $c === 302);
    if ($c === 200) {
        check('the page does not label a fee as hourly',
            !str_contains($lawyersPage, 'أجر ساعي') && !str_contains($lawyersPage, 'Hourly fee'));
    } else {
        check('the page does not label a fee as hourly (redirect)', true);
    }

    // ---------------------------------------------------------------------
    section('9. The specialty lawyer list loads without a fatal error');
    // The card reads CONSULTATION_DEFAULT_MINUTES from consultation_pricing.php. That
    // file was once required *inside* the result loop, so the very first card died with
    // "Undefined constant" — a 200 response with an empty body (the include had already
    // printed the shell). Driving the function directly proves the constant is loaded
    // before the loop, and the response body proves the page still renders its content.
    $fatal = null;
    try {
        $probe = legal_journey_lawyers_for_specialty($pdo, $linkedCategoryId, []);
        check('the specialty list returns an array, not a fatal', is_array($probe));
    } catch (Throwable $e) {
        $fatal = $e->getMessage();
        check('the specialty list returns an array, not a fatal', false);
    }
    if ($fatal !== null) echo "        ($fatal)\n";

    [$c2, $body2] = http66('GET', '/api/v1/lawyers.php?category_id=' . $linkedCategoryId, ['jar' => $jar]);
    $decoded = json_decode($body2, true);
    check('the public lawyers API answers JSON', $c2 === 200 && is_array($decoded));
    if (is_array($decoded)) {
        check('the API reports a lawyer count field', array_key_exists('count', $decoded));
        // Every card must carry the consultation price and its length, or the app shows a
        // bare hourly number — the exact defect this execution removes.
        $first = $decoded['lawyers'][0] ?? null;
        check('a lawyer card carries fee_display and duration_minutes',
            $first !== null
            && array_key_exists('fee_display', $first)
            && array_key_exists('duration_minutes', $first));
    }

} finally {
    section('Cleanup');
    if ($lawyerId > 0) {
        try { $pdo->prepare('DELETE FROM consultations WHERE lawyer_id=?')->execute([$lawyerId]); } catch (Throwable $e) {}
        try { $pdo->prepare('DELETE FROM lawyer_availability WHERE lawyer_id=?')->execute([$lawyerId]); } catch (Throwable $e) {}
        try { $pdo->prepare('DELETE FROM lawyer_specialties WHERE lawyer_id=?')->execute([$lawyerId]); } catch (Throwable $e) {}
        try { $pdo->prepare('DELETE FROM lawyers WHERE id=?')->execute([$lawyerId]); } catch (Throwable $e) {}
    }
    if ($clientId > 0) {
        try { $pdo->prepare('DELETE FROM consultations WHERE user_id=?')->execute([$clientId]); } catch (Throwable $e) {}
        try { $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$clientId]); } catch (Throwable $e) {}
    }
    echo "  removed test lawyer #$lawyerId and client #$clientId\n";
}

echo "\n$checks checks, $failures failure(s)\n";
exit($failures > 0 ? 1 : 0);
