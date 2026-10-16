<?php
/**
 * Phase 6 business seed — creates identical-shaped data for Firm A and Firm B so that
 * cross-tenant access can be attempted with real ids. Idempotent per firm.
 */
require_once __DIR__ . '/../config/config.php';

function first_id(PDO $pdo, string $sql, array $params) {
    $s = $pdo->prepare($sql); $s->execute($params); $v = $s->fetchColumn(); return $v === false ? null : (int)$v;
}

$firms = $pdo->query("SELECT id, owner_lawyer_id, name FROM law_firms ORDER BY id")->fetchAll();
if (count($firms) < 2) { fwrite(STDERR, "seed_phase6.php must run first\n"); exit(1); }

$out = [];
foreach ($firms as $firm) {
    $fid = (int)$firm['id'];
    $owner = (int)$firm['owner_lawyer_id'];
    $tag = 'Firm ' . $fid;

    // One client per firm (a user may only belong to one firm's book for this test).
    $clientEmail = "client-firm" . $fid . "@example.com";
    $clientId = first_id($pdo, "SELECT id FROM users WHERE email=?", [$clientEmail]);
    if (!$clientId) {
        $pdo->prepare("INSERT INTO users (full_name,email,password_hash,status,email_verified) VALUES (?,?,?,'active',1)")
            ->execute([$tag . ' Client', $clientEmail, password_hash('Test-Password-2026', PASSWORD_DEFAULT)]);
        $clientId = (int)$pdo->lastInsertId();
    }

    $caseId = first_id($pdo, "SELECT id FROM cases WHERE firm_id=? AND title=?", [$fid, $tag . ' Case']);
    if (!$caseId) {
        $pdo->prepare('INSERT INTO cases (lawyer_id,firm_id,user_id,title,status) VALUES (?,?,?,?,?)')
            ->execute([$owner, $fid, $clientId, $tag . ' Case', 'open']);
        $caseId = (int)$pdo->lastInsertId();
    }

    $docId = first_id($pdo, "SELECT id FROM documents WHERE user_id=? AND original_name=?", [$clientId, $tag . '-doc.pdf']);
    if (!$docId) {
        // Match the production upload naming exactly: bin2hex(random_bytes(20)) = 40 hex chars.
        $stored = bin2hex(random_bytes(20)) . '.pdf';
        $dir = UPLOAD_DIR;
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        file_put_contents($dir . $stored, "%PDF-1.4 phase6 fixture\n");
        $pdo->prepare('INSERT INTO documents (user_id,original_name,stored_path,file_type,status) VALUES (?,?,?,?,?)')
            ->execute([$clientId, $tag . '-doc.pdf', 'uploads/documents/' . $stored, 'pdf', 'completed']);
        $docId = (int)$pdo->lastInsertId();
    }

    $invId = first_id($pdo, "SELECT id FROM invoices WHERE firm_id=? AND description=?", [$fid, $tag . ' Invoice']);
    if (!$invId) {
        $pdo->prepare('INSERT INTO invoices (lawyer_id,firm_id,user_id,case_id,description,amount,status) VALUES (?,?,?,?,?,?,?)')
            ->execute([$owner, $fid, $clientId, $caseId, $tag . ' Invoice', 1500.000, 'unpaid']);
        $invId = (int)$pdo->lastInsertId();
    }

    $consId = first_id($pdo, "SELECT id FROM consultations WHERE lawyer_id=? AND user_id=? AND topic=?", [$owner, $clientId, $tag . ' Consult']);
    if (!$consId) {
        $cols = $pdo->query("SHOW COLUMNS FROM consultations")->fetchAll(PDO::FETCH_COLUMN);
        $fields = ['lawyer_id' => $owner, 'user_id' => $clientId];
        if (in_array('firm_id', $cols, true)) $fields['firm_id'] = $fid;
        if (in_array('topic', $cols, true)) $fields['topic'] = $tag . ' Consult';
        if (in_array('status', $cols, true)) $fields['status'] = 'pending';
        if (in_array('scheduled_at', $cols, true)) $fields['scheduled_at'] = date('Y-m-d H:i:s', time() + 86400);
        if (in_array('consultation_type', $cols, true)) $fields['consultation_type'] = 'text';
        $keys = array_keys($fields);
        $pdo->prepare('INSERT INTO consultations (' . implode(',', $keys) . ') VALUES (' . implode(',', array_fill(0, count($keys), '?')) . ')')
            ->execute(array_values($fields));
        $consId = (int)$pdo->lastInsertId();
    }

    $out[$fid] = ['firm' => $fid, 'owner' => $owner, 'client' => $clientId, 'case' => $caseId, 'document' => $docId, 'invoice' => $invId, 'consultation' => $consId];
}

foreach ($out as $fid => $r) {
    echo "firm=$fid owner={$r['owner']} client={$r['client']} case={$r['case']} doc={$r['document']} invoice={$r['invoice']} consult={$r['consultation']}\n";
}
file_put_contents('/tmp/phase6_ids.json', json_encode($out, JSON_PRETTY_PRINT));
