<?php
/** Login throttling backed by MySQL so limits are shared across PHP workers. */
function auth_rate_limit_keys(string $accountType, string $email): array {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    return [
        hash('sha256', 'auth-ip|' . $accountType . '|' . $ip),
        hash('sha256', 'auth-account|' . $accountType . '|' . strtolower(trim($email))),
    ];
}

/** Rate-limit bucket for the caller's IP alone, for endpoints with no account identifier. */
function auth_rate_limit_ip_key(string $scope): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    return hash('sha256', $scope . '|ip|' . $ip);
}

function auth_rate_limit_remaining_key(PDO $pdo, string $key): int {
    $stmt = $pdo->prepare('SELECT MAX(TIMESTAMPDIFF(SECOND, NOW(), blocked_until)) FROM auth_rate_limits WHERE scope_key = ? AND blocked_until > NOW()');
    $stmt->execute([$key]);
    return max(0, (int)$stmt->fetchColumn());
}

/**
 * Record a failed attempt against one bucket, blocking after $maxAttempts.
 *
 * The threshold is a parameter because the buckets have different costs: a login
 * guess is cheap, while consuming a reset token runs bcrypt on every call, so that
 * endpoint needs a bound that stops CPU exhaustion without locking a user out of
 * their own reset link after a few typos.
 */
function auth_rate_limit_failure_key(PDO $pdo, string $key, int $maxAttempts = 5): void {
    $sql = "INSERT INTO auth_rate_limits (scope_key, attempts, window_started_at, blocked_until) VALUES (?,1,NOW(),NULL)
        ON DUPLICATE KEY UPDATE
        blocked_until = IF(window_started_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE) OR (blocked_until IS NOT NULL AND blocked_until <= NOW()), NULL, IF(attempts + 1 >= ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE), blocked_until)),
        attempts = IF(window_started_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE) OR (blocked_until IS NOT NULL AND blocked_until <= NOW()), 1, attempts + 1),
        window_started_at = IF(window_started_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE) OR (blocked_until IS NOT NULL AND blocked_until <= NOW()), NOW(), window_started_at)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$key, max(1, $maxAttempts)]);
}

function auth_rate_limit_clear_key(PDO $pdo, string $key): void {
    $pdo->prepare('DELETE FROM auth_rate_limits WHERE scope_key=?')->execute([$key]);
}

function auth_rate_limit_remaining(PDO $pdo, string $accountType, string $email): int {
    $keys = auth_rate_limit_keys($accountType, $email);
    $stmt = $pdo->prepare('SELECT MAX(TIMESTAMPDIFF(SECOND, NOW(), blocked_until)) FROM auth_rate_limits WHERE scope_key IN (?,?) AND blocked_until > NOW()');
    $stmt->execute($keys);
    return max(0, (int)$stmt->fetchColumn());
}

function auth_rate_limit_failure(PDO $pdo, string $accountType, string $email): void {
    foreach (auth_rate_limit_keys($accountType, $email) as $key) {
        auth_rate_limit_failure_key($pdo, $key);
    }
}

function auth_rate_limit_clear(PDO $pdo, string $accountType, string $email): void {
    $keys = auth_rate_limit_keys($accountType, $email);
    // Clear the account bucket after success but keep the IP bucket to prevent
    // successful logins from resetting limits for other accounts on that address.
    auth_rate_limit_clear_key($pdo, $keys[1]);
}

function create_email_verification(PDO $pdo, string $accountType, int $accountId, string $email, string $name): bool {
    require_once __DIR__ . '/mailer.php';
    $token = bin2hex(random_bytes(32));
    $table = $accountType === 'lawyer' ? 'lawyers' : 'users';
    $pdo->prepare("UPDATE {$table} SET email_verified=0, email_verify_token_hash=?, email_verify_expires=DATE_ADD(NOW(), INTERVAL 24 HOUR) WHERE id=?")
        ->execute([hash('sha256', $token), $accountId]);
    $url = rtrim(SITE_URL, '/') . '/verify-email.php?token=' . urlencode($token);
    $arabic = current_lang() === 'ar';
    $body = $arabic
        ? '<p>مرحباً ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '،</p><p>أكد بريدك الإلكتروني لتفعيل تسجيل الدخول إلى مواثيق. الرابط صالح لمدة 24 ساعة.</p>'
        : '<p>Hello ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p><p>Confirm your email address to activate your Mawatheq sign-in. This link is valid for 24 hours.</p>';
    $html = render_mail_template($arabic ? 'تأكيد البريد الإلكتروني' : 'Verify your email', $body, $arabic ? 'تأكيد البريد' : 'Verify email', $url);
    return send_mail($email, $arabic ? 'تأكيد البريد الإلكتروني - مواثيق' : 'Verify your Mawatheq email', $html);
}

/**
 * Account tables and login/forgot URLs for every self-service account type.
 * Shared by the web pages and the mobile API so the two can never drift into
 * using different tables, TTLs or token handling.
 *
 * @return array{table:string,login:string,forgot:string,label_ar:string,label_en:string}
 */
function password_reset_account_config(string $accountType): array {
    $map = [
        'client' => ['table' => 'users',   'login' => '/login.php',        'forgot' => '/forgot-password.php?as=client'],
        'lawyer' => ['table' => 'lawyers', 'login' => '/lawyer-login.php', 'forgot' => '/forgot-password.php?as=lawyer'],
        'admin'  => ['table' => 'admins',  'login' => '/admin/login.php',  'forgot' => '/forgot-password.php?as=admin'],
    ];
    return $map[$accountType] ?? $map['client'];
}

/**
 * Issues a reset token for an account and emails the reset link.
 *
 * Returns true only when the token was stored. Email delivery is reported
 * separately by send_mail(); callers that must not reveal whether an address
 * exists (the forgot-password page) ignore the boolean, while the API surfaces
 * the email outcome so the app never promises a message it did not send.
 * The reset token itself is never returned to a caller.
 */
function issue_password_reset(PDO $pdo, string $accountType, int $accountId, string $email, string $name): bool {
    require_once __DIR__ . '/mailer.php';
    $cfg = password_reset_account_config($accountType);
    $token = bin2hex(random_bytes(32));
    // Only the SHA-256 of the token is persisted, exactly as email verification already
    // does. A leaked database dump, a backup, or a SELECT in a support session therefore
    // yields no usable reset link: the plaintext exists only inside the email we send.
    // Lookups hash the presented token and match on that, so nothing needs the raw value.
    $pdo->prepare("UPDATE {$cfg['table']} SET reset_token=?, reset_expires=DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id=?")
        ->execute([hash('sha256', $token), $accountId]);

    $resetUrl = rtrim(SITE_URL, '/') . '/reset-password.php?as=' . $accountType . '&token=' . urlencode($token);
    $arabic = current_lang() === 'ar';
    $body = $arabic
        ? '<p>مرحباً ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '،</p><p>وصلنا طلب لإعادة تعيين كلمة مرور حسابك في منصة مواثيق. الرابط صالح لمدة ساعة واحدة فقط.</p><p>إن لم تطلب ذلك، تجاهل هذه الرسالة ولن يتغيّر شيء.</p>'
        : '<p>Hello ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p><p>We received a request to reset your Mawatheq account password. This link is valid for 1 hour only.</p><p>If you did not request this, you can safely ignore this email.</p>';
    $html = render_mail_template(
        $arabic ? 'إعادة تعيين كلمة المرور' : 'Reset your password',
        $body,
        $arabic ? 'إعادة تعيين كلمة المرور' : 'Reset password',
        $resetUrl
    );
    return send_mail($email, $arabic ? 'إعادة تعيين كلمة المرور - مواثيق' : 'Reset your Mawatheq password', $html);
}

/** SHA-256 of a reset token as stored; the raw token is never persisted. */
function password_reset_token_hash(string $token): string {
    return hash('sha256', $token);
}

/**
 * Revoke every live API token for an account after a password change.
 *
 * Also stamps the account's `session_invalidated_at`, which the route guards compare
 * against the session start time. Without that second step a password reset only
 * closed the API: an attacker holding a stolen *browser session cookie* kept full
 * access to the account until the cookie's 30-minute idle window elapsed, because the
 * PHP session was never re-checked against the credential change.
 */
function invalidate_account_sessions(PDO $pdo, string $accountType, int $accountId): void {
    $cfg = password_reset_account_config($accountType);
    if ($accountType !== 'admin') {
        $pdo->prepare('UPDATE api_tokens SET revoked_at=NOW() WHERE user_type=? AND user_id=? AND revoked_at IS NULL')
            ->execute([$accountType, $accountId]);
    }
    try {
        $pdo->prepare("UPDATE {$cfg['table']} SET session_invalidated_at=NOW() WHERE id=?")
            ->execute([$accountId]);
    } catch (Throwable $e) {
        // Older schema without the column: the API-token revocation above still applied.
        error_log('session invalidation stamp failed for ' . $accountType . ':' . $accountId . ': ' . $e->getMessage());
    }
}

/**
 * True when the account's password changed after this session was established, meaning
 * the session predates the credential change and must be discarded.
 *
 * Fails open on a schema that predates `session_invalidated_at`, so a deployment that has
 * not yet run upgrade_v53 keeps working instead of logging every user out.
 */
function account_session_is_stale(PDO $pdo, string $accountType, int $accountId): bool {
    $cfg = password_reset_account_config($accountType);
    try {
        $stmt = $pdo->prepare("SELECT session_invalidated_at FROM {$cfg['table']} WHERE id=?");
        $stmt->execute([$accountId]);
        $stamp = $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
    if (empty($stamp)) return false;
    $started = (int)($_SESSION['mawatheq_session_started_at'] ?? 0);
    // No recorded start (a session created before this change shipped) counts as stale
    // once the account has a stamp: the session is older than the credential change.
    if ($started === 0) return true;
    // Strictly greater, not greater-or-equal. The stamp means "sessions that began before
    // this instant are invalid", so a session that begins in the same second is a new one
    // and must be allowed. With >=, revoking an account's sessions and signing straight back
    // in lands in the same second, the fresh session is judged stale, and the account loops
    // between the login page and the guard with no way out.
    return strtotime((string)$stamp) > $started;
}

/**
 * Consumes a reset token exactly once and sets the new password.
 *
 * @return array{ok:bool,error:?string} error is a stable machine code
 *   (invalid_token|weak_password|too_long|mismatch) that the caller localises.
 */
function consume_password_reset(PDO $pdo, string $accountType, string $token, string $password, string $confirm): array {
    $cfg = password_reset_account_config($accountType);
    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        return ['ok' => false, 'error' => 'invalid_token'];
    }

    // Validate the password before touching the token. Otherwise a mistyped confirmation
    // would burn the single-use token and force the user to request a new link.
    if (strlen($password) < 8) return ['ok' => false, 'error' => 'weak_password'];
    if (strlen($password) > 72) return ['ok' => false, 'error' => 'too_long'];
    if ($password !== $confirm) return ['ok' => false, 'error' => 'mismatch'];

    // One atomic UPDATE claims the token, sets the password and stamps the invalidation
    // time. Because it only matches while reset_token still holds this hash, two requests
    // arriving together cannot both succeed: the loser sees rowCount()===0 and is refused.
    // The previous SELECT-then-UPDATE let a replayed request through a second time.
    //
    // LAST_INSERT_ID(id) makes MySQL return the matched row's id on this connection, which
    // is how the account id is recovered without a second SELECT that could pick the wrong
    // row and revoke an unrelated account's tokens.
    $claim = $pdo->prepare("UPDATE {$cfg['table']}
        SET reset_token=NULL, reset_expires=NULL, password_hash=?, session_invalidated_at=NOW(), id=LAST_INSERT_ID(id)
        WHERE reset_token=? AND reset_expires>NOW()");
    $claim->execute([password_hash($password, PASSWORD_BCRYPT), password_reset_token_hash($token)]);
    if ($claim->rowCount() !== 1) {
        return ['ok' => false, 'error' => 'invalid_token'];
    }
    $accountId = (int)$pdo->lastInsertId();

    // Setting a password is a security event: every token and session issued before the
    // reset must stop working, so a stolen one cannot outlive the credential change.
    invalidate_account_sessions($pdo, $accountType, $accountId);
    return ['ok' => true, 'error' => null];
}
