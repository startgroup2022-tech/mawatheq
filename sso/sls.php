<?php
/**
 * SAML 2.0 single logout (SLS).
 *
 * Processes the IdP's LogoutRequest/LogoutResponse, destroys the platform session, and returns
 * the visitor to the lawyer login page. A failure here ends the local session anyway — leaving
 * someone signed in because the IdP was unreachable would be the unsafe direction.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/sso.php';

$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) require_once $autoload;

$firmId = (int)($_REQUEST['firm'] ?? $_SESSION['sso_firm_id'] ?? 0);
$config = $firmId ? sso_load_config($pdo, $firmId) : null;

$redirect = '/lawyer-login.php?logged_out=1';

if ($config && sso_config_ready($config)['ok']) {
    $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    try {
        $auth = new OneLogin\Saml2\Auth(sso_sp_settings($config, $base));
        $auth->processSLO(true, null, true, null, true);
        $errors = $auth->getErrors();
        if (empty($errors)) {
            $url = $auth->getSLOurl();
            if ($url !== '' && $url !== null) $redirect = $url;
        }
    } catch (Throwable $e) {
        error_log('SSO SLS exception: ' . $e->getMessage());
    }
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool)$p['secure'], (bool)$p['httponly']);
}
session_destroy();

header('Location: ' . $redirect);
exit;
