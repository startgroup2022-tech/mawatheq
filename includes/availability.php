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
