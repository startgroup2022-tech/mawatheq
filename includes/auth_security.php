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
