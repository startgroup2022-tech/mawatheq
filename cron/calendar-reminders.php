<?php
// Run hourly from cPanel Cron Jobs: php /home/CPANEL_USER/public_html/cron/calendar-reminders.php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/notifications.php';

$events = $pdo->query("SELECT id,lawyer_id,title,DATE_FORMAT(event_at,'%Y-%m-%d %H:%i') AS event_at_display,event_type FROM legal_calendar_events
    WHERE status='upcoming' AND reminder_sent_at IS NULL
      AND event_at > NOW() AND event_at <= DATE_ADD(NOW(),INTERVAL 24 HOUR)
    ORDER BY event_at ASC LIMIT 500")->fetchAll();
$sent = 0;

foreach ($events as $event) {
    try {
        $pdo->beginTransaction();
        $claim = $pdo->prepare("UPDATE legal_calendar_events SET reminder_sent_at=NOW()
            WHERE id=? AND lawyer_id=? AND status='upcoming' AND reminder_sent_at IS NULL
              AND event_at > NOW() AND event_at <= DATE_ADD(NOW(),INTERVAL 24 HOUR)");
        $claim->execute([(int)$event['id'],(int)$event['lawyer_id']]);
        if ($claim->rowCount() !== 1) {
            $pdo->rollBack();
            continue;
        }
        $when = $event['event_at_display'];
        $title = 'تذكير بموعد قانوني خلال 24 ساعة';
        $message = $event['title'] . ' — ' . $when;
        notify($pdo,'lawyer',(int)$event['lawyer_id'],$title,$message,'/lawyer/calendar.php');
        $pdo->commit();
        $sent++;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Calendar reminder failed for event ' . (int)$event['id'] . ': ' . $e->getMessage());
    }
}

fwrite(STDOUT,'Calendar reminders created: ' . $sent . PHP_EOL);
