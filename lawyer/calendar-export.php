<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/includes/lawyer_guard.php';

$lawyerId = (int)$_SESSION['lawyer_id'];
$sql = "SELECT event_at,event_title,case_title,event_type,event_id FROM (
    SELECT CONCAT(ct.event_date,' 00:00:00') AS event_at,ct.event AS event_title,c.title AS case_title,'timeline' AS event_type,CONCAT('timeline-',ct.id) AS event_id
    FROM case_timeline ct JOIN cases c ON c.id=ct.case_id WHERE c.lawyer_id=?
    UNION ALL
    SELECT CONCAT(t.due_date,' 00:00:00') AS event_at,t.title AS event_title,c.title AS case_title,'task' AS event_type,CONCAT('task-',t.id) AS event_id
    FROM case_tasks t JOIN cases c ON c.id=t.case_id WHERE c.lawyer_id=? AND t.due_date IS NOT NULL AND t.status='pending'
    UNION ALL
    SELECT co.scheduled_at AS event_at,COALESCE(co.topic,'استشارة') AS event_title,u.full_name AS case_title,'consultation' AS event_type,CONCAT('consultation-',co.id) AS event_id
    FROM consultations co JOIN users u ON u.id=co.user_id WHERE co.lawyer_id=? AND co.status IN ('pending','confirmed')
    UNION ALL
    SELECT e.event_at AS event_at,e.title AS event_title,COALESCE(c.title,'') AS case_title,e.event_type AS event_type,CONCAT('event-',e.id) AS event_id
    FROM legal_calendar_events e LEFT JOIN cases c ON c.id=e.case_id WHERE e.lawyer_id=? AND e.status='upcoming'
) calendar_items WHERE event_at >= DATE_SUB(NOW(),INTERVAL 30 DAY) ORDER BY event_at ASC LIMIT 1000";
$stmt = $pdo->prepare($sql);
$stmt->execute([$lawyerId,$lawyerId,$lawyerId,$lawyerId]);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

$escapeIcs = static function (string $value): string {
    $value = str_replace(["\\", ";", ",", "\r\n", "\r", "\n"], ["\\\\", "\\;", "\\,", "\\n", "\\n", "\\n"], $value);
    return $value;
};
$lines = ['BEGIN:VCALENDAR','VERSION:2.0','PRODID:-//Mawatheq//Legal Calendar//AR','CALSCALE:GREGORIAN','METHOD:PUBLISH','X-WR-CALNAME:مواثيق - التقويم القانوني'];
foreach ($events as $event) {
    $timestamp = strtotime((string)$event['event_at']);
    if (!$timestamp) continue;
    $uid = hash('sha256', $lawyerId . ':' . $event['event_id']) . '@mawatheq.com';
    $summary = trim((string)$event['event_title']);
    $description = trim((string)$event['case_title']);
    if ($description !== '') $description = 'القضية/العميل: ' . $description;
    $lines[] = 'BEGIN:VEVENT';
    $lines[] = 'UID:' . $uid;
    $lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
    if (in_array($event['event_type'], ['timeline','task'], true)) {
        $date = date('Ymd', $timestamp);
        $lines[] = 'DTSTART;VALUE=DATE:' . $date;
        $lines[] = 'DTEND;VALUE=DATE:' . date('Ymd', strtotime('+1 day', $timestamp));
    } else {
        // Keep the entered local time without a server-timezone shift.
        $lines[] = 'DTSTART:' . date('Ymd\THis', $timestamp);
        $lines[] = 'DTEND:' . date('Ymd\THis', $timestamp + 1800);
    }
    $lines[] = 'SUMMARY:' . $escapeIcs($summary);
    if ($description !== '') $lines[] = 'DESCRIPTION:' . $escapeIcs($description);
    $lines[] = 'END:VEVENT';
}
$lines[] = 'END:VCALENDAR';
$ics = implode("\r\n", $lines) . "\r\n";
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="mawatheq-legal-calendar.ics"');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
echo $ics;
