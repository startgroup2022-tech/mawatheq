<?php
/**
 * SAML 2.0 Service Provider metadata for a firm.
 *
 * Public by design: it publishes the SP entity id, the ACS URL and the SP signing certificate
 * (public material) so a firm's IT team can register this platform with their IdP. It never
 * exposes any private key or IdP secret.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/sso.php';

$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) require_once $autoload;

$firmId = (int)($_GET['firm'] ?? 0);
$config = $firmId ? sso_load_config($pdo, $firmId) : null;

if (!$config || !sso_library_available()) {
    http_response_code(404);
    exit('No SSO metadata is available for this workspace.');
}

$base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

try {
    $settings = new OneLogin\Saml2\Settings(sso_sp_settings($config, $base), true);
    $metadata = $settings->getSPMetadata();
    $errors = $settings->validateMetadata($metadata);
    if (!empty($errors)) throw new RuntimeException('invalid_metadata');
} catch (Throwable $e) {
    error_log('SSO metadata exception: ' . $e->getMessage());
    http_response_code(500);
    exit('SSO metadata could not be generated.');
}

header('Content-Type: application/xml; charset=utf-8');
header('Content-Disposition: inline; filename="mawatheq-saml-metadata.xml"');
echo $metadata;
