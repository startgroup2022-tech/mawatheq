<?php
/**
 * حساب الأوقات المتاحة الفعلية لحجز موعد مع محامٍ في تاريخ معيّن:
 * أوقات دوامه المسجّلة لذلك اليوم من الأسبوع، مقسّمة لفترات (slots)،
 * مطروحاً منها الأوقات المحجوزة مسبقاً (غير الملغاة).
 */
function get_available_slots(PDO $pdo, int $lawyerId, string $date): array {
    $dayOfWeek = (int) date('w', strtotime($date)); // 0=الأحد
    $isToday = $date === date('Y-m-d');
    $nowTime = date('H:i:s');

    $stmt = $pdo->prepare('SELECT start_time, end_time, slot_minutes FROM lawyer_availability WHERE lawyer_id = ? AND day_of_week = ?');
    $stmt->execute([$lawyerId, $dayOfWeek]);
    $windows = $stmt->fetchAll();
    if (empty($windows)) return [];

    // الأوقات المحجوزة مسبقاً في هذا اليوم (باستثناء الملغاة)
    $bookedStmt = $pdo->prepare("
        SELECT TIME(scheduled_at) AS t FROM consultations
        WHERE lawyer_id = ? AND DATE(scheduled_at) = ? AND status != 'cancelled'
    ");
    $bookedStmt->execute([$lawyerId, $date]);
    $booked = array_map(fn($r) => substr($r['t'], 0, 5), $bookedStmt->fetchAll());

    $slots = [];
    foreach ($windows as $w) {
        $slotMinutes = max(15, (int)$w['slot_minutes']);
        $cursor = strtotime($date . ' ' . $w['start_time']);
        $end = strtotime($date . ' ' . $w['end_time']);
        while ($cursor < $end) {
            $timeLabel = date('H:i', $cursor);
            $isPast = $isToday && $timeLabel <= substr($nowTime, 0, 5);
            if (!in_array($timeLabel, $booked, true) && !$isPast) {
                $slots[] = $timeLabel;
            }
            $cursor += $slotMinutes * 60;
        }
    }
    sort($slots);
    return array_values(array_unique($slots));
}

/** أسماء أيام الأسبوع للعرض (0=الأحد وفق date('w')). */
function weekday_names(): array {
    return is_rtl()
        ? [0 => 'الأحد', 1 => 'الإثنين', 2 => 'الثلاثاء', 3 => 'الأربعاء', 4 => 'الخميس', 5 => 'الجمعة', 6 => 'السبت']
        : [0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'];
}

/** الأيام التي يعمل فيها المحامي فعلاً (0=الأحد) — من دوامه المنشور فقط. */
function lawyer_working_weekdays(PDO $pdo, int $lawyerId): array {
    $stmt = $pdo->prepare('SELECT DISTINCT day_of_week FROM lawyer_availability WHERE lawyer_id = ?');
    $stmt->execute([$lawyerId]);
    $days = array_map('intval', array_column($stmt->fetchAll(), 'day_of_week'));
    sort($days);
    return array_values(array_unique($days));
}

/**
 * خريطة توفر شهر كامل: لكل يوم في الشهر، هل للمحامي دوام فيه، وكم موعداً حرّاً.
 *
 * تُستخدم لرسم تقويم حقيقي في الموقع والتطبيق: اليوم «متاح» فقط إن كان للمحامي دوام فيه
 * وله موعد حر واحد على الأقل، لا إن كان مجرد يوم عمل. الشهر بلا حلقات استعلام متسرّعة:
 * نقرأ الدوام والمواعيد المحجوزة مرة واحدة لكل الشهر.
 *
 * @return array{month:string, days:array<string,int>, working_weekdays:array<int,int>, has_hours:bool}
 */
function get_month_availability(PDO $pdo, int $lawyerId, string $month): array {
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        return ['month' => $month, 'days' => [], 'working_weekdays' => [], 'has_hours' => false];
    }
    $first = $month . '-01';
    $start = new DateTime($first);
    $end = (clone $start)->modify('last day of this month');
    $today = date('Y-m-d');
    $nowTime = date('H:i:s');

    $winStmt = $pdo->prepare('SELECT day_of_week, start_time, end_time, slot_minutes FROM lawyer_availability WHERE lawyer_id = ?');
    $winStmt->execute([$lawyerId]);
    $windowsByDay = [];
    foreach ($winStmt->fetchAll() as $w) {
        $windowsByDay[(int)$w['day_of_week']][] = $w;
    }
    $hasHours = !empty($windowsByDay);

    // كل الحجوزات النشطة في هذا الشهر، مرة واحدة.
    $bookedStmt = $pdo->prepare("SELECT DATE(scheduled_at) AS d, TIME(scheduled_at) AS t FROM consultations
        WHERE lawyer_id = ? AND scheduled_at >= ? AND scheduled_at < ? AND status != 'cancelled'");
    $bookedStmt->execute([$lawyerId, $first . ' 00:00:00', $end->format('Y-m-d') . ' 23:59:59']);
    $bookedByDay = [];
    foreach ($bookedStmt->fetchAll() as $r) {
        $bookedByDay[$r['d']][] = substr((string)$r['t'], 0, 5);
    }

    $days = [];
    for ($d = clone $start; $d <= $end; $d->modify('+1 day')) {
        $date = $d->format('Y-m-d');
        if ($date < $today) { $days[$date] = 0; continue; }
        $dow = (int)$d->format('w');
        if (empty($windowsByDay[$dow])) { $days[$date] = 0; continue; }
        $count = 0;
        $isToday = $date === $today;
        foreach ($windowsByDay[$dow] as $w) {
            $slotMinutes = max(15, (int)$w['slot_minutes']);
            $cursor = strtotime($date . ' ' . $w['start_time']);
            $winEnd = strtotime($date . ' ' . $w['end_time']);
            while ($cursor < $winEnd) {
                $label = date('H:i', $cursor);
                $isPast = $isToday && $label <= substr($nowTime, 0, 5);
                if (!$isPast && !in_array($label, $bookedByDay[$date] ?? [], true)) $count++;
                $cursor += $slotMinutes * 60;
            }
        }
        $days[$date] = $count;
    }

    return [
        'month'            => $month,
        'days'             => $days,
        'working_weekdays' => lawyer_working_weekdays($pdo, $lawyerId),
        'has_hours'        => $hasHours,
    ];
}

/**
 * أقرب N موعد حر عبر الأيام القادمة (لا في يوم واحد) — لعرض «أقرب المواعيد» على البطاقة.
 *
 * @return array<int,array{date:string,time:string}>
 */
function get_next_available_slots(PDO $pdo, int $lawyerId, int $count = 3, int $maxDays = 14, ?string $fromDate = null): array {
    $from = $fromDate ?: date('Y-m-d');
    $out = [];
    for ($i = 0; $i < $maxDays && count($out) < $count; $i++) {
        $date = date('Y-m-d', strtotime($from . ' +' . $i . ' days'));
        foreach (get_available_slots($pdo, $lawyerId, $date) as $slot) {
            $out[] = ['date' => $date, 'time' => $slot];
            if (count($out) >= $count) break;
        }
    }
    return $out;
}
