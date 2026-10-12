<?php
/** Login throttling backed by MySQL so limits are shared across PHP workers. */
function auth_rate_limit_keys(string $accountType, string $email): array {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    return [
        hash('sha256', 'auth-ip|' . $accountType . '|' . $ip),
        hash('sha256', 'auth-account|' . $accountType . '|' . strtolower(trim($email))),
    ];
}

function auth_rate_limit_remaining(PDO $pdo, string $accountType, string $email): int {
    $keys = auth_rate_limit_keys($accountType, $email);
    $stmt = $pdo->prepare('SELECT MAX(TIMESTAMPDIFF(SECOND, NOW(), blocked_until)) FROM auth_rate_limits WHERE scope_key IN (?,?) AND blocked_until > NOW()');
    $stmt->execute($keys);
    return max(0, (int)$stmt->fetchColumn());
}

function auth_rate_limit_failure(PDO $pdo, string $accountType, string $email): void {
    $sql = "INSERT INTO auth_rate_limits (scope_key, attempts, window_started_at, blocked_until) VALUES (?,1,NOW(),NULL)
        ON DUPLICATE KEY UPDATE
        blocked_until = IF(window_started_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE) OR (blocked_until IS NOT NULL AND blocked_until <= NOW()), NULL, IF(attempts + 1 >= 5, DATE_ADD(NOW(), INTERVAL 15 MINUTE), blocked_until)),
        attempts = IF(window_started_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE) OR (blocked_until IS NOT NULL AND blocked_until <= NOW()), 1, attempts + 1),
        window_started_at = IF(window_started_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE) OR (blocked_until IS NOT NULL AND blocked_until <= NOW()), NOW(), window_started_at)";
    $stmt = $pdo->prepare($sql);
    foreach (auth_rate_limit_keys($accountType, $email) as $key) {
        $stmt->execute([$key]);
    }
}

function auth_rate_limit_clear(PDO $pdo, string $accountType, string $email): void {
    $keys = auth_rate_limit_keys($accountType, $email);
    // Clear the account bucket after success but keep the IP bucket to prevent
    // successful logins from resetting limits for other accounts on that address.
    $stmt = $pdo->prepare('DELETE FROM auth_rate_limits WHERE scope_key=?');
    $stmt->execute([$keys[1]]);
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
    // Stored as plaintext by design: reset-password.php matches on the raw
    // column. The token is single-use, 1-hour, high-entropy and never logged.
    $pdo->prepare("UPDATE {$cfg['table']} SET reset_token=?, reset_expires=DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id=?")
        ->execute([$token, $accountId]);

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
    $stmt = $pdo->prepare("SELECT id FROM {$cfg['table']} WHERE reset_token=? AND reset_expires>NOW()");
    $stmt->execute([$token]);
    $account = $stmt->fetch();
    if (!$account) return ['ok' => false, 'error' => 'invalid_token'];

    if (strlen($password) < 8) return ['ok' => false, 'error' => 'weak_password'];
    if (strlen($password) > 72) return ['ok' => false, 'error' => 'too_long'];
    if ($password !== $confirm) return ['ok' => false, 'error' => 'mismatch'];

    $pdo->prepare("UPDATE {$cfg['table']} SET password_hash=?, reset_token=NULL, reset_expires=NULL WHERE id=?")
        ->execute([password_hash($password, PASSWORD_BCRYPT), $account['id']]);

    // Setting a password is a security event: any token issued before the reset
    // must stop working, so a stolen session cannot outlive the password change.
    if ($accountType !== 'admin') {
        $pdo->prepare('UPDATE api_tokens SET revoked_at=NOW() WHERE user_type=? AND user_id=? AND revoked_at IS NULL')
            ->execute([$accountType, (int)$account['id']]);
    }
    return ['ok' => true, 'error' => null];
}
