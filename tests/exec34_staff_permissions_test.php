<?php
/**
 * Execution 34 — restricted administrative staff accounts.
 *
 * Covers the permission model at the level that matters: the decision function, the storage
 * layer, and the real HTTP surface. The last part is deliberate — a permission system that
 * only passes its own unit test while the console still serves a page is not a permission
 * system, so this drives real sessions through Apache and asserts both the allowed and the
 * refused response.
 *
 * Usage: php tests/exec34_staff_permissions_test.php
 *   Needs a running Apache on MAWATHEQ_BASE_URL (default http://localhost:8080).
 */
$base = getenv('MAWATHEQ_BASE_URL') ?: 'http://localhost:8080';
$root = dirname(__DIR__);
require_once $root . '/config/config.php';
require_once $root . '/includes/admin_permissions.php';
require_once $root . '/includes/admin_staff_service.php';
require_once $root . '/includes/auth_security.php';

$failures = 0;
$checks = 0;
function check(string $label, $got, $want = true): void {
    global $checks, $failures;
    $checks++;
    if ($got === $want) { echo "  ok:   $label\n"; return; }
    $failures++;
    $g = is_bool($got) ? var_export($got, true) : (is_array($got) ? json_encode($got) : (string)$got);
    $w = is_bool($want) ? var_export($want, true) : (string)$want;
    echo "  FAIL: $label — got [$g], want [$w]\n";
}
function section(string $t): void { echo "\n$t\n"; }

function http_login(string $base, string $email, string $pass, string $jar): array {
    $ch = curl_init($base . '/admin/login.php');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false]);
    $html = (string)curl_exec($ch); curl_close($ch);
    preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m);
    $token = $m[1] ?? '';
    $ch = curl_init($base . '/admin/login.php');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['csrf_token' => $token, 'email' => $email, 'password' => $pass])]);
    $body = (string)curl_exec($ch); $loc = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL); curl_close($ch);
    return [$token, $loc, $body];
}
function http_get(string $url, string $jar): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false]);
    $body = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, $body];
}
function http_post(string $url, string $jar, array $fields): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields), CURLOPT_FOLLOWLOCATION => false]);
    $body = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, $body];
}
function csrf_of(string $body): string { preg_match('/name="csrf_token" value="([^"]+)"/', $body, $m); return $m[1] ?? ''; }

$sfx = bin2hex(random_bytes(4));
$created = [];
$tmp = sys_get_temp_dir();

// A super_admin to act as, created directly because the console cannot mint one by design.
$superEmail = "exec34-super-$sfx@example.test";
$superPass = 'Exec34-Super-Password-2026';
$pdo->prepare("INSERT INTO admins (full_name,email,password_hash,role,is_active) VALUES (?,?,?,'super_admin',1)")
    ->execute(['Exec34 Super', $superEmail, password_hash($superPass, PASSWORD_DEFAULT)]);
$superId = (int)$pdo->lastInsertId();
$created[] = $superId;

$staffEmail = "exec34-staff-$sfx@example.test";
$staffPass = 'Exec34-Staff-Password-2026';
$staffId = 0;

// ===========================================================================
section('1. The permission decision function');
// ===========================================================================
check('an unknown action is refused', admin_staff_can($pdo, 1, 'blog', 'teleport'), false);
check('an unknown module is refused', admin_staff_can($pdo, 1, 'not-a-module', 'view'), false);
check('a non-positive account id is refused', admin_staff_can($pdo, 0, 'blog', 'view'), false);
check('a module that does not exist cannot be granted', admin_staff_can($pdo, $superId, 'nope', 'view'), false);

// ===========================================================================
section('2. Create a staff account');
// ===========================================================================
$res = admin_staff_create($pdo, [
    'full_name' => 'Exec34 Employee', 'email' => $staffEmail,
    'password' => $staffPass, 'confirm' => $staffPass,
], $superId);
check('the account is created', $res['ok'], true);
$staffId = (int)($res['id'] ?? 0);
if ($staffId) $created[] = $staffId;

$row = admin_staff_account($pdo, $staffId);
check('the role is staff, not an administrator', (string)($row['role'] ?? ''), 'staff');
check('the account starts active', (int)($row['is_active'] ?? 0), 1);
check('the password is stored as a hash', (bool)preg_match('/^\$2y\$|\$argon2/', (string)$pdo->query("SELECT password_hash FROM admins WHERE id=$staffId")->fetchColumn()), true);
check('the stored hash is not the plaintext', (string)$pdo->query("SELECT password_hash FROM admins WHERE id=$staffId")->fetchColumn() === $staffPass, false);

// ===========================================================================
section('3. Default deny');
// ===========================================================================
check('a fresh account holds no grants', admin_staff_permissions($pdo, $staffId), []);
check('and therefore cannot view any module', admin_staff_can($pdo, $staffId, 'blog', 'view'), false);
check('nor the dashboard module data', admin_staff_can($pdo, $staffId, 'dashboard', 'view'), false);
check('admin_authorize_route denies an unmapped console page', admin_authorize_route($pdo, 'brand-new-page.php', 'GET'), false);

// ===========================================================================
section('4. Duplicate and weak credentials are refused');
// ===========================================================================
$dup = admin_staff_create($pdo, ['full_name' => 'Dup', 'email' => $staffEmail, 'password' => $staffPass, 'confirm' => $staffPass], $superId);
check('a duplicate email is refused', $dup['ok'], false);
$short = admin_staff_create($pdo, ['full_name' => 'Short', 'email' => "exec34-short-$sfx@example.test", 'password' => 'short', 'confirm' => 'short'], $superId);
check('a short password is refused', $short['ok'], false);
$mismatch = admin_staff_create($pdo, ['full_name' => 'Mis', 'email' => "exec34-mis-$sfx@example.test", 'password' => 'LongEnough-2026-x', 'confirm' => 'LongEnough-2026-y'], $superId);
check('a mismatched confirmation is refused', $mismatch['ok'], false);
$badEmail = admin_staff_create($pdo, ['full_name' => 'Bad', 'email' => 'not-an-email', 'password' => 'LongEnough-2026-x', 'confirm' => 'LongEnough-2026-x'], $superId);
check('an invalid email is refused', $badEmail['ok'], false);

// ===========================================================================
section('5. No privilege escalation');
// ===========================================================================
$actor = admin_staff_account($pdo, $staffId);
check('a staff account cannot create another account', admin_staff_create($pdo, ['full_name' => 'X', 'email' => "exec34-x-$sfx@example.test", 'password' => 'LongEnough-2026-x', 'confirm' => 'LongEnough-2026-x'], $staffId)['ok'], false);
check('a staff account cannot grant itself permissions', admin_staff_set_permissions($pdo, $staffId, ['blog' => ['view']], $staffId), 0);
check('a staff account cannot deactivate anyone', admin_staff_set_active($pdo, $staffId, false, $staffId)['ok'], false);
check('a staff account cannot reset another account', admin_staff_reset_access($pdo, $superId, 'LongEnough-2026-x', 'LongEnough-2026-x', $staffId)['ok'], false);
check('a staff account cannot revoke sessions', admin_staff_revoke_sessions($pdo, $superId, $staffId)['ok'], false);
// The role is never read from input, so even the create path cannot be talked into it.
check('creating through the service always yields the staff role', (string)admin_staff_account($pdo, $staffId)['role'], 'staff');

// ===========================================================================
section('6. Granting, and what a grant does not do');
// ===========================================================================
$stored = admin_staff_set_permissions($pdo, $staffId, ['blog' => ['view', 'edit']], $superId);
check('two grants are stored', $stored, 2);
check('view is allowed on the granted module', admin_staff_can($pdo, $staffId, 'blog', 'view'), true);
check('edit is allowed on the granted module', admin_staff_can($pdo, $staffId, 'blog', 'edit'), true);
check('create was not granted and is refused', admin_staff_can($pdo, $staffId, 'blog', 'create'), false);
check('delete was not granted and is refused', admin_staff_can($pdo, $staffId, 'blog', 'delete'), false);
check('export was not granted and is refused', admin_staff_can($pdo, $staffId, 'blog', 'export'), false);
check('another module is still refused', admin_staff_can($pdo, $staffId, 'leads', 'view'), false);

// ===========================================================================
section('7. Sensitive modules are refused however they are posted');
// ===========================================================================
$injected = admin_staff_set_permissions($pdo, $staffId, [
    'finance' => ['view', 'edit'],
    'staff' => ['view'],
    'ai' => ['view'],
    'clients' => ['view'],
    'cases' => ['view'],
    'audit' => ['view'],
    'security' => ['view'],
    'system-settings' => ['edit'],
    'payments' => ['view'],
    'affiliate-finance' => ['view'],
    'integrations' => ['view'],
    'backups' => ['view'],
    'consultations' => ['view'],
], $superId);
check('no non-grantable module was stored', $injected, 0);
foreach (['finance', 'staff', 'ai', 'clients', 'cases', 'audit', 'security', 'system-settings', 'payments', 'affiliate-finance', 'integrations', 'backups', 'consultations'] as $mod) {
    check("access to $mod stays refused", admin_staff_can($pdo, $staffId, $mod, 'view'), false);
}
// And a row inserted directly, bypassing the service, is still inert.
$pdo->prepare("INSERT INTO admin_staff_permissions (admin_id,module_key,action_key) VALUES (?,?,?)")->execute([$staffId, 'finance', 'view']);
check('a hand-inserted row for a blocked module is inert', admin_staff_can($pdo, $staffId, 'finance', 'view'), false);
$pdo->prepare("DELETE FROM admin_staff_permissions WHERE admin_id=? AND module_key='finance'")->execute([$staffId]);

// ===========================================================================
section('8. Unknown actions cannot be stored');
// ===========================================================================
$weird = admin_staff_set_permissions($pdo, $staffId, ['blog' => ['view', 'sudo', 'drop_table']], $superId);
check('only the valid action is stored', $weird, 1);

// ===========================================================================
section('9. Permission changes take effect immediately');
// ===========================================================================
admin_staff_set_permissions($pdo, $staffId, ['leads' => ['view']], $superId);
check('leads view is now allowed', admin_staff_can($pdo, $staffId, 'leads', 'view'), true);
check('blog view is now withdrawn', admin_staff_can($pdo, $staffId, 'blog', 'view'), false);
admin_staff_set_permissions($pdo, $staffId, [], $superId);
check('clearing all grants denies everything again', admin_staff_can($pdo, $staffId, 'leads', 'view'), false);

// ===========================================================================
section('10. Deactivation and session revocation');
// ===========================================================================
admin_staff_set_permissions($pdo, $staffId, ['blog' => ['view']], $superId);
check('the active staff account is recognized', admin_is_active_staff($pdo, $staffId), true);
$deact = admin_staff_set_active($pdo, $staffId, false, $superId);
check('deactivation succeeds', $deact['ok'], true);
check('the account is no longer active', admin_is_active_staff($pdo, $staffId), false);
check('authorization now fails even for a granted module', admin_authorize_route($pdo, 'articles.php', 'GET'), false);
check('a disabled account cannot be a super_admin target', admin_staff_set_active($pdo, $superId, false, $superId)['ok'], false);

admin_staff_set_active($pdo, $staffId, true, $superId);
check('reactivation restores the account', admin_is_active_staff($pdo, $staffId), true);
// Clear the stamp first: an earlier deactivation already set it, and two revocations in the
// same second are indistinguishable.
$pdo->prepare('UPDATE admins SET session_invalidated_at = NULL WHERE id = ?')->execute([$staffId]);
$before = (string)$pdo->query("SELECT session_invalidated_at FROM admins WHERE id=$staffId")->fetchColumn();
check('the stamp starts clear', $before, '');
admin_staff_revoke_sessions($pdo, $staffId, $superId);
$after = (string)$pdo->query("SELECT session_invalidated_at FROM admins WHERE id=$staffId")->fetchColumn();
check('revocation sets session_invalidated_at', $after !== '', true);
// A session that begins after the stamp is accepted, and one that began before is refused.
$staleTs = strtotime($after) - 60;
$_SESSION['mawatheq_session_started_at'] = $staleTs;
check('a session older than the stamp is stale', account_session_is_stale($pdo, 'admin', $staffId), true);
$_SESSION['mawatheq_session_started_at'] = strtotime($after) + 1;
check('a session newer than the stamp is accepted', account_session_is_stale($pdo, 'admin', $staffId), false);
unset($_SESSION['mawatheq_session_started_at']);

// ===========================================================================
section('11. Route mapping and write-action inference');
// ===========================================================================
check('blog maps articles.php', admin_route_module('articles.php'), 'blog');
check('classification maps classification.php', admin_route_module('classification.php'), 'classification');
check('finance maps invoices.php', admin_route_module('invoices.php'), 'finance');
check('an unmapped page resolves to no module', admin_route_module('totally-unknown.php'), null);
check('action=delete needs delete', admin_write_action(['action' => 'delete']), 'delete');
check('action=approve needs approve', admin_write_action(['action' => 'approve']), 'approve');
check('action=export needs export', admin_write_action(['action' => 'export']), 'export');
check('action=create needs create', admin_write_action(['action' => 'create']), 'create');
check('a plain form save needs edit', admin_write_action([]), 'edit');
check('delete is not satisfied by an edit keyword', admin_write_action(['action' => 'edit-delete']), 'delete');

// ===========================================================================
section('12. The catalogue itself');
// ===========================================================================
$modules = admin_staff_module_catalogue($pdo);
check('the catalogue is not empty', count($modules) > 0, true);
$grantable = admin_staff_grantable_modules($pdo);
check('there are grantable modules', count($grantable) > 0, true);
$blocked = array_filter($modules, fn($m) => (int)$m['grantable'] === 0);
check('there are blocked modules', count($blocked) > 0, true);
foreach (['finance', 'clients', 'cases', 'staff', 'audit', 'ai', 'security', 'backups', 'consultations', 'system-settings'] as $must) {
    check("$must is marked non-grantable", in_array($must, $grantable, true), false);
}

// ===========================================================================
section('13. Real HTTP enforcement');
// ===========================================================================
// A permission model that passes its own unit tests while the console still serves the page
// is not enforcement. This drives real sessions through Apache.
$adminJar = "$tmp/exec34-admin-$sfx.jar";
$staffJar = "$tmp/exec34-staff-$sfx.jar";

[$tok, $loc, $body] = http_login($base, $superEmail, $superPass, $adminJar);
check('the super_admin can sign in', str_contains($loc, '/admin/index.php'), true);

[$tok2, $loc2, $body2] = http_login($base, $staffEmail, $staffPass, $staffJar);
check('the staff account can sign in', str_contains($loc2, '/admin/index.php'), true);

// Give the account exactly one module, then probe what the HTTP layer actually returns.
admin_staff_set_permissions($pdo, $staffId, ['blog' => ['view']], $superId);

[$code, $html] = http_get("$base/admin/articles.php", $staffJar);
check('a granted page returns 200', $code, 200);
[$code, $html] = http_get("$base/admin/leads.php", $staffJar);
check('an ungranted page returns 403', $code, 403);
[$code, $html] = http_get("$base/admin/staff.php", $staffJar);
check('the staff console returns 403 to staff', $code, 403);
[$code, $html] = http_get("$base/admin/staff-edit.php?id=$staffId", $staffJar);
check('the permission editor returns 403 to staff', $code, 403);
[$code, $html] = http_get("$base/admin/invoices.php", $staffJar);
check('a finance page returns 403 to staff', $code, 403);
[$code, $html] = http_get("$base/admin/users.php", $staffJar);
check('a client-records page returns 403 to staff', $code, 403);
[$code, $html] = http_get("$base/admin/settings.php", $staffJar);
check('a system-settings page returns 403 to staff', $code, 403);
[$code, $html] = http_get("$base/admin/audit-log.php", $staffJar);
check('the audit log returns 403 to staff', $code, 403);
[$code, $html] = http_get("$base/admin/index.php", $staffJar);
check('the dashboard is reachable', $code, 200);

// The menu must not advertise what the guard refuses.
[$code, $dash] = http_get("$base/admin/index.php", $staffJar);
check('the dashboard does not link to the staff console', str_contains($dash, '/admin/staff.php'), false);
check('the dashboard does not link to invoices', str_contains($dash, '/admin/invoices.php'), false);
check('the dashboard does not link to the audit log', str_contains($dash, '/admin/audit-log.php'), false);

// The super_admin is unaffected by all of this.
[$code, $html] = http_get("$base/admin/staff.php", $adminJar);
check('the super_admin can open the staff console', $code, 200);
[$code, $html] = http_get("$base/admin/index.php", $adminJar);
check('the super_admin still sees the full dashboard', $code, 200);
check('the super_admin dashboard links to the staff console', str_contains($html, '/admin/staff.php'), true);

// A POST needs the write action, not merely view.
[$code, $html] = http_get("$base/admin/articles.php", $staffJar);
$token = csrf_of($html);
[$code, $body] = http_post("$base/admin/articles.php", $staffJar, ['csrf_token' => $token, 'action' => 'delete', 'id' => '1']);
check('a delete POST is refused with view-only rights', $code, 403);
[$code, $body] = http_post("$base/admin/articles.php", $staffJar, ['csrf_token' => 'wrong', 'action' => 'edit', 'id' => '1']);
check('a POST with a bad CSRF token is refused', $code, 403);

// ===========================================================================
section('14. Deactivation is effective over HTTP');
// ===========================================================================
admin_staff_set_active($pdo, $staffId, false, $superId);
[$code, $html] = http_get("$base/admin/index.php", $staffJar);
check('a deactivated account is bounced from the console', $code, 302);
[$code2, $loc3, $b3] = http_login($base, $staffEmail, $staffPass, "$tmp/exec34-staff2-$sfx.jar");
check('a deactivated account cannot sign in again', str_contains($loc3, '/admin/index.php'), false);
admin_staff_set_active($pdo, $staffId, true, $superId);

// ===========================================================================
section('15. Audit trail');
// ===========================================================================
$audit = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'admin.staff_%'");
$audit->execute();
check('staff actions were audited', (int)$audit->fetchColumn() > 0, true);
$denied = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE action = 'admin.access_denied'");
$denied->execute();
check('refused access attempts were audited', (int)$denied->fetchColumn() > 0, true);
// The audit log must not contain credentials.
$leak = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE action LIKE ? OR action LIKE ?");
$leak->execute(['%' . $staffPass . '%', '%password_hash%']);
check('no password appears in the audit log', (int)$leak->fetchColumn(), 0);

// ===========================================================================
section('16. Cleanup');
// ===========================================================================
foreach ($created as $id) {
    try {
        $pdo->prepare('DELETE FROM admin_staff_permissions WHERE admin_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM admins WHERE id = ?')->execute([$id]);
    } catch (Throwable $e) { /* best effort */ }
}
foreach (glob("$tmp/exec34-*-$sfx.jar") as $jar) @unlink($jar);
$still = $pdo->prepare('SELECT COUNT(*) FROM admins WHERE email IN (?, ?)');
$still->execute([$superEmail, $staffEmail]);
check('the test accounts were removed', (int)$still->fetchColumn(), 0);

echo "\n----------------------------------------\n";
echo "checks passed: $checks   failed: $failures\n";
exit($failures === 0 ? 0 : 1);
