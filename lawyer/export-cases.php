<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/includes/lawyer_guard.php';
$lawyerId = (int)$_SESSION['lawyer_id'];
$stmt = $pdo->prepare('SELECT c.id,c.title,c.case_type,c.status,c.win_probability,c.description,c.created_at,u.full_name client_name FROM cases c JOIN users u ON u.id=c.user_id WHERE c.lawyer_id=? ORDER BY c.created_at DESC');
$stmt->execute([$lawyerId]);
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="mawatheq-cases-' . date('Y-m-d') . '.csv"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
$out = fopen('php://output','w');
fwrite($out,"\xEF\xBB\xBF");
fputcsv($out,['Case ID','Title','Case type','Client','Status','Win probability','Description','Created at'],escape:'');
$safe = static function ($value): string {
    $value = (string)$value;
    return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
};
while ($row = $stmt->fetch()) {
    fputcsv($out,[$row['id'],$safe($row['title']),$safe($row['case_type']),$safe($row['client_name']),$row['status'],$row['win_probability'],$safe($row['description']),$row['created_at']],escape:'');
}
fclose($out);
