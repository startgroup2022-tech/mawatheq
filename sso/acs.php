<?php
/**
 * SAML 2.0 Assertion Consumer Service.
 *
 * Receives the IdP's POST, hands the response to onelogin/php-saml for signature and
 * condition validation, then applies this platform's own admission rules before any session
 * is created. Every attempt — success or failure — is written to sso_login_attempts.
 *
 * No session is created unless a validated identity maps to an active member of the firm that
 * owns the configuration. A JIT-provisioned account is always created as role `lawyer`.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/sso.php';
require_once __DIR__ . '/../includes/audit_log.php';

$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) require_once $autoload;

$firmId = (int)($_REQUEST['firm'] ?? $_SESSION['sso_firm_id'] ?? 0);
$config = $firmId ? sso_load_config($pdo, $firmId) : null;
$ready = $config ? sso_config_ready($config) : ['ok' => false, 'reason' => 'no_config'];

if (empty($ready['ok'])) {
    sso_record_attempt($pdo, $firmId ?: null, null, 'error', (string)$ready['reason']);
    http_response_code(400);
    exit('SSO is not available for this workspace.');
}

$base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

try {
    $auth = new OneLogin\Saml2\Auth(sso_sp_settings($config, $base));
    $auth->processResponse();
} catch (Throwable $e) {
    sso_record_attempt($pdo, $firmId, null, 'error', 'saml_exception');
    // The message names the exact SAML check that failed (signature, audience, timing) and is
    // needed to diagnose an IdP misconfiguration; it never contains the assertion itself.
    error_log('SSO ACS exception: ' . $e->getMessage());
    http_response_code(400);
    exit('SSO response could not be processed.');
}

$errors = $auth->getErrors();
if (!empty($errors) || !$auth->isAuthenticated()) {
    $reason = $errors ? implode(',', array_slice($errors, 0, 3)) : 'not_authenticated';
    // Validation detail is logged for the same reason as above: it identifies the failing check.
    error_log('SSO ACS rejected: ' . $reason . ' | ' . (string)$auth->getLastErrorReason());
    sso_record_attempt($pdo, $firmId, null, 'failed', $reason);
    http_response_code(401);
    exit('SSO authentication failed.');
}

$identity = sso_identity_from_attributes($config, (array)$auth->getAttributes(), (string)$auth->getNameId());
$resolved = sso_resolve_member($pdo, $firmId, $config, (string)$identity['email']);

if (empty($resolved['ok'])) {
    sso_record_attempt($pdo, $firmId, (string)$identity['email'], 'denied', (string)($resolved['reason'] ?? 'denied'), (string)$identity['name_id']);
    http_response_code(403);
    exit('This account is not permitted to sign in to this workspace.');
}

$lawyerId = (int)$resolved['lawyer_id'];

if ($lawyerId === 0 && !empty($resolved['provision'])) {
    // JIT provisioning: lowest role only, never owner/partner, and the account starts approved
    // because the IdP already vouched for the address.
    $pdo->prepare('INSERT INTO lawyers (full_name, email, password_hash, status, email_verified, jurisdiction) VALUES (?,?,?,?,1,?)')
        ->execute([(string)$identity['name'], (string)$identity['email'], password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), 'approved', 'البحرين']);
    $lawyerId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO law_firm_members (firm_id, lawyer_id, member_role, status) VALUES (?,?, 'lawyer', 'active')")
        ->execute([$firmId, $lawyerId]);
}

$st = $pdo->prepare("SELECT id, full_name, email FROM lawyers WHERE id=? AND status='approved' LIMIT 1");
$st->execute([$lawyerId]);
$lawyer = $st->fetch();
if (!$lawyer) {
    sso_record_attempt($pdo, $firmId, (string)$identity['email'], 'denied', 'account_not_approved', (string)$identity['name_id']);
    http_response_code(403);
    exit('This account is not active.');
}

session_regenerate_id(true);
$_SESSION['lawyer_id'] = (int)$lawyer['id'];
$_SESSION['lawyer_name'] = (string)$lawyer['full_name'];
$_SESSION['sso_firm_id'] = $firmId;
$_SESSION['sso_login_at'] = time();

sso_record_attempt($pdo, $firmId, (string)$lawyer['email'], 'success', null, (string)$identity['name_id']);
log_action($pdo, 'sso_login', 'lawyer', (int)$lawyer['id']);

header('Location: /lawyer/dashboard.php');
exit;
