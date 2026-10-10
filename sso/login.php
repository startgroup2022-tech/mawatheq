<?php
/**
 * SAML 2.0 login initiation (SP-initiated).
 *
 * Resolves which firm the visitor is signing in to — either from an explicit `firm` id or from
 * the domain of the email they typed — then redirects to that firm's IdP with a signed
 * AuthNRequest. If no firm has SSO enabled, the visitor is sent back to the password form.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/sso.php';

$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) require_once $autoload;

$firmId = (int)($_GET['firm'] ?? 0);
$email = trim((string)($_GET['email'] ?? ''));

// Resolve the firm by email domain when no explicit firm was given.
if (!$firmId && $email !== '' && str_contains($email, '@')) {
    $domain = sso_email_domain($email);
    if ($domain !== '') {
        try {
            $st = $pdo->prepare("SELECT firm_id FROM sso_configs WHERE enabled=1 AND (allowed_domains LIKE ? OR allowed_domains LIKE ? OR allowed_domains LIKE ?) LIMIT 1");
            $st->execute([$domain, '%,' . $domain . ',%', '%,' . $domain]);
            $firmId = (int)($st->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            $firmId = 0;
        }
    }
}

$config = $firmId ? sso_load_config($pdo, $firmId) : null;
$ready = $config ? sso_config_ready($config) : ['ok' => false, 'reason' => 'no_config'];

if (empty($ready['ok'])) {
    sso_record_attempt($pdo, $firmId ?: null, $email !== '' ? $email : null, 'error', (string)$ready['reason']);
    header('Location: /lawyer-login.php?error=sso_unavailable');
    exit;
}

$_SESSION['sso_firm_id'] = $firmId;

$base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

try {
    $auth = new OneLogin\Saml2\Auth(sso_sp_settings($config, $base));
    $url = $auth->login(rtrim($base, '/') . '/sso/acs.php?firm=' . $firmId, [], false, false, true);
} catch (Throwable $e) {
    sso_record_attempt($pdo, $firmId, $email !== '' ? $email : null, 'error', 'authn_request_failed');
    error_log('SSO login exception: ' . $e->getMessage());
    header('Location: /lawyer-login.php?error=sso_unavailable');
    exit;
}

header('Location: ' . $url);
exit;
