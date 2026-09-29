<?php
/** Fetch client-safe billable time details for the supplied invoice IDs. */
function invoice_time_items(PDO $pdo, array $invoiceIds): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $invoiceIds), static fn($id) => $id > 0)));
    $result = [];
    foreach (array_chunk($ids, 500) as $chunk) {
        $marks = implode(',', array_fill(0, count($chunk), '?'));
        $stmt = $pdo->prepare("SELECT invoice_id,work_date,minutes,hourly_rate FROM case_time_entries WHERE billable=1 AND invoice_id IN ($marks) ORDER BY invoice_id,work_date,id");
        $stmt->execute($chunk);
        foreach ($stmt->fetchAll() as $row) {
            $row['line_amount'] = round(((int)$row['minutes'] / 60) * (float)$row['hourly_rate'], 3);
            $result[(int)$row['invoice_id']][] = $row;
        }
    }
    return $result;
}
