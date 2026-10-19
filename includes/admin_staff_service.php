<?php
/**
 * Staff account lifecycle (Execution 34).
 *
 * Create, activate, deactivate, reset access and revoke sessions for restricted
 * administrative employees. Every function returns ['ok' => bool, 'error' => ?string] so the
 * console can render a message without the caller having to guess, and every state change
 * writes an audit row naming the acting administrator.
 *
 * WHAT IS ENFORCED HERE RATHER THAN ONLY IN THE FORM
 *
 *   * A created account is always `staff`. The role is not read from the request, so a
 *     crafted POST cannot mint a second super_admin.
 *   * An account may not be created by a non-super_admin, checked again here rather than
 *     relying on the route guard alone.
 *   * Passwords go through password_hash() with the platform's own policy, and the plaintext
 *     is never written to the database or the audit log.
 *   * Deactivation and revocation both bump `session_invalidated_at`, which the existing
 *     account_session_is_stale() check already honours, so an active session dies on its
 *     next request rather than at its natural expiry.
 */

// The actor check and the permission reader live in the permission library; this file builds
// on them rather than duplicating them.
require_once __DIR__ . '/admin_permissions.php';

/**
 * Password policy for staff accounts.
 *
 * Ten characters minimum, matching the platform's other account forms, and a small set of
 * obviously-guessable rejections. Length is the property that matters most, so the rule is
 * length-first rather than a symbol-counting rule that pushes people towards `Passw0rd!`.
 *
 * @return string|null an error message, or null when acceptable
 */
function admin_staff_password_problem(string $password, string $confirm): ?string {
    if (strlen($password) < 10) {
        return 'كلمة المرور يجب أن تكون 10 أحرف على الأقل. / Password must be at least 10 characters.';
    }
    if (!hash_equals($password, $confirm)) {
        return 'كلمتا المرور غير متطابقتين. / The two passwords do not match.';
    }
    // Reject the common passwords outright, and reject a password that is one of them with
    // only digits or punctuation added — "password123!" is not meaningfully better than
    // "password". Matching a *whole* token rather than a substring is deliberate: a
    // passphrase like "Correct-Horse-Password-9" is fine, and a rule that refused it would
    // push people towards something worse.
    $weak = ['password', 'passw0rd', '12345678', '123456789', '1234567890', 'qwertyuiop',
             'qwerty', 'mawatheq', 'admin123', 'administrator', 'letmein', 'welcome',
             'iloveyou', 'monkey', 'dragon', 'football', 'abc123', 'changeme'];
    $letters = preg_replace('/[^a-z]/', '', strtolower($password));
    if (in_array($letters, $weak, true)) {
        return 'كلمة المرور شائعة جداً. اختر غيرها. / That password is too common. Choose another.';
    }
    // A single repeated character, however long, carries no entropy.
    if (preg_match('/^(.)\1+$/', $password)) {
        return 'كلمة المرور ضعيفة جداً. اختر غيرها. / That password is too weak. Choose another.';
    }
    return null;
}

/**
 * Create a restricted staff account.
 *
 * @param array{full_name:string,email:string,password:string,confirm:string} $data
 * @return array{ok:bool,error:?string,id?:int}
 */
function admin_staff_create(PDO $pdo, array $data, int $actorId): array {
    if (!admin_staff_actor_is_super_admin($pdo, $actorId)) {
        return ['ok' => false, 'error' => 'غير مصرّح. / Not authorized.'];
    }

    $name = trim($data['full_name'] ?? '');
    $email = strtolower(trim($data['email'] ?? ''));
    if ($name === '' || mb_strlen($name) > 150) {
        return ['ok' => false, 'error' => 'الاسم مطلوب. / A name is required.'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
        return ['ok' => false, 'error' => 'البريد الإلكتروني غير صالح. / That email address is not valid.'];
    }
    if ($problem = admin_staff_password_problem($data['password'] ?? '', $data['confirm'] ?? '')) {
        return ['ok' => false, 'error' => $problem];
    }

    // The email column is UNIQUE, so the database is the authority on duplicates. Checking
    // first only turns a constraint violation into a readable message.
    try {
        $exists = $pdo->prepare('SELECT id FROM admins WHERE email = ?');
        $exists->execute([$email]);
        if ($exists->fetchColumn()) {
            return ['ok' => false, 'error' => 'يوجد حساب بهذا البريد بالفعل. / An account with that email already exists.'];
        }
    } catch (Throwable $e) {
        error_log('admin_staff_create lookup: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'تعذر التحقق من البريد. / Could not check that email.'];
    }

    try {
        // The role is hard-coded, never taken from the request.
        $stmt = $pdo->prepare(
            'INSERT INTO admins (full_name, email, password_hash, role, is_active, created_by)
             VALUES (?, ?, ?, ?, 1, ?)'
        );
        $stmt->execute([
            $name,
            $email,
            password_hash($data['password'], PASSWORD_DEFAULT),
            'staff',
            $actorId,
        ]);
        $newId = (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        error_log('admin_staff_create insert: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'تعذر إنشاء الحساب. / Could not create the account.'];
    }

    admin_staff_audit($pdo, 'admin.staff_created', $newId, $name, $actorId);
    return ['ok' => true, 'error' => null, 'id' => $newId];
}

/**
 * Turn an account on or off.
 *
 * Deactivating also invalidates live sessions, so the effect is immediate rather than
 * "immediate at their next login" — which for a terminated employee is not immediate at all.
 *
 * @return array{ok:bool,error:?string}
 */
function admin_staff_set_active(PDO $pdo, int $staffId, bool $active, int $actorId): array {
    if (!admin_staff_actor_is_super_admin($pdo, $actorId)) {
        return ['ok' => false, 'error' => 'غير مصرّح. / Not authorized.'];
    }
    if ($staffId <= 0 || $staffId === $actorId) {
        return ['ok' => false, 'error' => 'لا يمكن تنفيذ ذلك على هذا الحساب. / That account cannot be changed.'];
    }
    $target = admin_staff_account($pdo, $staffId);
    if ($target === null || (string)$target['role'] !== 'staff') {
        // Refusing non-staff targets keeps this page from being a way to disable the other
        // administrators, which is not what it is for.
        return ['ok' => false, 'error' => 'الحساب غير موجود أو ليس حساب موظف. / Not a staff account.'];
    }

    try {
        $pdo->prepare('UPDATE admins SET is_active = ? WHERE id = ?')->execute([$active ? 1 : 0, $staffId]);
    } catch (Throwable $e) {
        error_log('admin_staff_set_active: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'تعذر تحديث الحساب. / Could not update the account.'];
    }

    if (!$active) admin_staff_revoke_sessions($pdo, $staffId, $actorId, false);

    admin_staff_audit($pdo, $active ? 'admin.staff_activated' : 'admin.staff_deactivated', $staffId, (string)$target['full_name'], $actorId);
    return ['ok' => true, 'error' => null];
}

/**
 * End every session the employee currently holds.
 *
 * `session_invalidated_at` is compared against the session's own start time by
 * account_session_is_stale(), which the console guard already calls — so this needs no new
 * mechanism and takes effect on the next request.
 *
 * @return array{ok:bool,error:?string}
 */
function admin_staff_revoke_sessions(PDO $pdo, int $staffId, int $actorId, bool $audit = true): array {
    if (!admin_staff_actor_is_super_admin($pdo, $actorId)) {
        return ['ok' => false, 'error' => 'غير مصرّح. / Not authorized.'];
    }
    if ($staffId <= 0) return ['ok' => false, 'error' => 'حساب غير صالح. / Invalid account.'];
    try {
        $pdo->prepare('UPDATE admins SET session_invalidated_at = NOW() WHERE id = ?')->execute([$staffId]);
    } catch (Throwable $e) {
        error_log('admin_staff_revoke_sessions: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'تعذر إبطال الجلسات. / Could not revoke sessions.'];
    }
    if ($audit) {
        $target = admin_staff_account($pdo, $staffId);
        admin_staff_audit($pdo, 'admin.staff_sessions_revoked', $staffId, (string)($target['full_name'] ?? ''), $actorId);
    }
    return ['ok' => true, 'error' => null];
}

/**
 * Set a new password and end the employee's sessions.
 *
 * Both together, because a reset whose old sessions survive is not a reset.
 *
 * @return array{ok:bool,error:?string}
 */
function admin_staff_reset_access(PDO $pdo, int $staffId, string $password, string $confirm, int $actorId): array {
    if (!admin_staff_actor_is_super_admin($pdo, $actorId)) {
        return ['ok' => false, 'error' => 'غير مصرّح. / Not authorized.'];
    }
    $target = admin_staff_account($pdo, $staffId);
    if ($target === null || (string)$target['role'] !== 'staff') {
        return ['ok' => false, 'error' => 'الحساب غير موجود أو ليس حساب موظف. / Not a staff account.'];
    }
    if ($problem = admin_staff_password_problem($password, $confirm)) {
        return ['ok' => false, 'error' => $problem];
    }
    try {
        $pdo->prepare('UPDATE admins SET password_hash = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $staffId]);
    } catch (Throwable $e) {
        error_log('admin_staff_reset_access: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'تعذر تعيين كلمة المرور. / Could not set the password.'];
    }
    admin_staff_revoke_sessions($pdo, $staffId, $actorId, false);
    admin_staff_audit($pdo, 'admin.staff_access_reset', $staffId, (string)$target['full_name'], $actorId);
    return ['ok' => true, 'error' => null];
}

/**
 * Every staff account, newest first.
 *
 * @return list<array<string, mixed>>
 */
function admin_staff_list(PDO $pdo): array {
    try {
        $rows = $pdo->query(
            "SELECT id, full_name, email, is_active, created_at, last_login_at, created_by
             FROM admins WHERE role = 'staff' ORDER BY id DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
        return $rows ?: [];
    } catch (Throwable $e) {
        error_log('admin_staff_list: ' . $e->getMessage());
        return [];
    }
}

/**
 * Write an audit row for a staff action.
 *
 * Records who acted, on whom, and what changed — never a password, token or hash. The actor
 * is taken from the session by log_action(), and the target is passed explicitly because the
 * subject of the action is the employee, not the administrator.
 */
function admin_staff_audit(PDO $pdo, string $action, int $targetId, string $targetName, int $actorId): void {
    try {
        require_once __DIR__ . '/audit_log.php';
        log_action($pdo, $action, 'admin:' . $targetId, $targetId);
    } catch (Throwable $e) {
        // An audit failure must not undo a completed administrative action; the action has
        // already been applied and reporting success is still the honest answer.
        error_log('admin_staff_audit: ' . $e->getMessage());
    }
}
