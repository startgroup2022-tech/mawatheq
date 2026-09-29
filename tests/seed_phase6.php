<?php
/**
 * Phase 6 local QA seed — creates two firms and role accounts for HTTP/tenant tests.
 * Safe to re-run (idempotent by email). Never run in production.
 */
require_once __DIR__ . '/../config/config.php';

$pwd = password_hash('Test-Password-2026', PASSWORD_DEFAULT);
$now = date('Y-m-d H:i:s');

function upsert_lawyer(PDO $pdo, string $name, string $email, string $pwd): int {
    $s = $pdo->prepare('SELECT id FROM lawyers WHERE email=?');
    $s->execute([$email]);
    $id = $s->fetchColumn();
    if ($id) {
        $pdo->prepare("UPDATE lawyers SET password_hash=?,status='approved',email_verified=1 WHERE id=?")->execute([$pwd, $id]);
        return (int)$id;
    }
    $pdo->prepare("INSERT INTO lawyers (full_name,email,password_hash,status,email_verified,jurisdiction) VALUES (?,?,?,'approved',1,'السعودية')")
        ->execute([$name, $email, $pwd]);
    return (int)$pdo->lastInsertId();
}
function upsert_user(PDO $pdo, string $name, string $email, string $pwd): int {
    $s = $pdo->prepare('SELECT id FROM users WHERE email=?');
    $s->execute([$email]);
    $id = $s->fetchColumn();
    if ($id) {
        $pdo->prepare("UPDATE users SET password_hash=?,status='active',email_verified=1 WHERE id=?")->execute([$pwd, $id]);
        return (int)$id;
    }
    $pdo->prepare("INSERT INTO users (full_name,email,password_hash,status,email_verified) VALUES (?,?,?,'active',1)")
        ->execute([$name, $email, $pwd]);
    return (int)$pdo->lastInsertId();
}
function upsert_firm(PDO $pdo, string $name, int $ownerId): int {
    $s = $pdo->prepare('SELECT id FROM law_firms WHERE owner_lawyer_id=?');
    $s->execute([$ownerId]);
    $id = $s->fetchColumn();
    if (!$id) {
        $pdo->prepare('INSERT INTO law_firms (name,owner_lawyer_id) VALUES (?,?)')->execute([$name, $ownerId]);
        $id = (int)$pdo->lastInsertId();
    }
    $m = $pdo->prepare('SELECT id FROM law_firm_members WHERE firm_id=? AND lawyer_id=?');
    $m->execute([$id, $ownerId]);
    if (!$m->fetchColumn()) {
        $pdo->prepare("INSERT INTO law_firm_members (firm_id,lawyer_id,member_role,status) VALUES (?,?,'owner','active')")->execute([$id, $ownerId]);
    }
    return (int)$id;
}

$adminEmail = 'admin@mawatheq.com';
$s = $pdo->prepare('SELECT id FROM admins WHERE email=?');
$s->execute([$adminEmail]);
$adminId = $s->fetchColumn();
if ($adminId) {
    $pdo->prepare("UPDATE admins SET password_hash=?,role='super_admin',totp_enabled=0,totp_secret=NULL WHERE id=?")->execute([$pwd, $adminId]);
} else {
    $pdo->prepare("INSERT INTO admins (full_name,email,password_hash,role,totp_enabled) VALUES ('QA Admin',?,?,'super_admin',0)")->execute([$adminEmail, $pwd]);
}

$aOwner = upsert_lawyer($pdo, 'Firm A Owner', 'owner-a@example.com', $pwd);
$aMember = upsert_lawyer($pdo, 'Firm A Member', 'member-a@example.com', $pwd);
$bOwner = upsert_lawyer($pdo, 'Firm B Owner', 'owner-b@example.com', $pwd);
$firmA = upsert_firm($pdo, 'Firm A Legal', $aOwner);
$firmB = upsert_firm($pdo, 'Firm B Legal', $bOwner);
$pdo->prepare("INSERT INTO law_firm_members (firm_id,lawyer_id,member_role,status) VALUES (?,?,'lawyer','active')")->execute([$firmA, $aMember]);

$clientA = upsert_user($pdo, 'Client A', 'client-a@example.com', $pwd);
$clientB = upsert_user($pdo, 'Client B', 'client-b@example.com', $pwd);

echo "admin=$adminEmail\n";
echo "lawyerA(owner)=owner-a@example.com firm=$firmA\n";
echo "lawyerA(member)=member-a@example.com\n";
echo "lawyerB(owner)=owner-b@example.com firm=$firmB\n";
echo "clientA=client-a@example.com id=$clientA\n";
echo "clientB=client-b@example.com id=$clientB\n";
echo "password=Test-Password-2026\n";
