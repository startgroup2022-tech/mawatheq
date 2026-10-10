<?php
/**
 * Executive 09 — real SAML 2.0 SSO test.
 *
 * This is not a mock: it generates genuine SP and IdP key material, configures a firm, builds
 * a SIGNED SAML Response with the IdP's private key, POSTs it to the live ACS endpoint, and
 * checks that a session is created only for an identity that is allowed to enter that firm.
 *
 * It also asserts the refusals that matter: a tampered assertion (signature broken), an email
 * outside the firm's allowed domain, and an email that is not a member of the firm.
 *
 * Run: php tests/executive09_sso_test.php   (requires the dev server on 127.0.0.1:12000)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/sso.php';
require_once __DIR__ . '/../vendor/autoload.php';

$base = 'http://127.0.0.1:12000';
$acsBase = $base . '/sso/acs.php';
$spEntityId = $base . '/sso/metadata.php';
$failures = 0; $checks = 0;
function check(string $label, bool $ok): void {
    global $failures, $checks; $checks++;
    if (!$ok) { $failures++; echo "  FAIL: $label\n"; } else echo "  ok:   $label\n";
}

// ---- 1. key material -------------------------------------------------------
function gen_keypair(): array {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'mawatheq-test'], $key, ['digest_alg' => 'sha256']);
    $x509 = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);
    openssl_pkey_export($key, $privPem);
    openssl_x509_export($x509, $certPem);
    return [$privPem, $certPem];
}
[$idpKey, $idpCert] = gen_keypair();
check('generated IdP key material', str_contains($idpKey, 'PRIVATE KEY') && str_contains($idpCert, 'CERTIFICATE'));

// ---- 2. configure firm 17 --------------------------------------------------
$firmId = 0;
try {
    $st = $pdo->prepare("SELECT m.firm_id FROM law_firm_members m JOIN lawyers l ON l.id=m.lawyer_id WHERE l.email='owner-a@example.com' LIMIT 1");
    $st->execute(); $firmId = (int)($st->fetchColumn() ?: 0);
} catch (Throwable $e) {}
check('resolved the firm for owner A', $firmId > 0);
// The ACS URL must include ?firm=, because MessageValidator requires the assertion's Recipient
// and the response's Destination to match the URL the ACS actually receives (query included).
$acsUrl = $acsBase . '?firm=' . $firmId;

$idpEntityId = 'https://idp.test.local/metadata';
$save = sso_save_config($pdo, $firmId, [
    'enabled' => 1,
    'display_name' => 'Test IdP',
    'idp_entity_id' => $idpEntityId,
    'idp_sso_url' => 'https://idp.test.local/sso',
    'idp_x509_cert' => $idpCert,
    'allowed_domains' => 'example.com',
    'attribute_email' => 'email',
    'attribute_name' => 'displayName',
    'require_signed_response' => 1,
], 0);
check('SSO configuration saved', !empty($save['ok']));

$config = sso_load_config($pdo, $firmId);
check('SSO configuration is enabled and ready', $config && sso_config_ready($config)['ok']);

// ---- 3. build a signed SAML response --------------------------------------
function build_signed_response(string $idpEntityId, string $spEntityId, string $acsUrl, string $email, string $name, string $privPem, string $certPem, bool $tamper = false): string {
    $now = time();
    $id = '_' . bin2hex(random_bytes(12));
    $aId = '_' . bin2hex(random_bytes(12));
    $nb = gmdate('Y-m-d\TH:i:s\Z', $now - 60);
    $na = gmdate('Y-m-d\TH:i:s\Z', $now + 300);
    // php-saml 4.3's addSign() signs the ROOT element only, so the assertion is built and
    // signed standalone, then embedded in the response — the way a real IdP emits it.
    $assertion = <<<XML
<saml:Assertion xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="$aId" Version="2.0" IssueInstant="$nb">
  <saml:Issuer>$idpEntityId</saml:Issuer>
  <saml:Subject>
    <saml:NameID Format="urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress">$email</saml:NameID>
    <saml:SubjectConfirmation Method="urn:oasis:names:tc:SAML:2.0:cm:bearer">
      <saml:SubjectConfirmationData NotOnOrAfter="$na" Recipient="$acsUrl"/>
    </saml:SubjectConfirmation>
  </saml:Subject>
  <saml:Conditions NotBefore="$nb" NotOnOrAfter="$na">
    <saml:AudienceRestriction><saml:Audience>$spEntityId</saml:Audience></saml:AudienceRestriction>
  </saml:Conditions>
  <saml:AuthnStatement AuthnInstant="$nb" SessionIndex="$id">
    <saml:AuthnContext><saml:AuthnContextClassRef>urn:oasis:names:tc:SAML:2.0:ac:classes:PasswordProtectedTransport</saml:AuthnContextClassRef></saml:AuthnContext>
  </saml:AuthnStatement>
  <saml:AttributeStatement>
    <saml:Attribute Name="email"><saml:AttributeValue>$email</saml:AttributeValue></saml:Attribute>
    <saml:Attribute Name="displayName"><saml:AttributeValue>$name</saml:AttributeValue></saml:Attribute>
  </saml:AttributeStatement>
</saml:Assertion>
XML;
    $adoc = new DOMDocument();
    $adoc->loadXML($assertion);
    $signedAssertion = OneLogin\Saml2\Utils::addSign(
        $adoc, $privPem, $certPem,
        RobRichards\XMLSecLibs\XMLSecurityKey::RSA_SHA256,
        RobRichards\XMLSecLibs\XMLSecurityDSig::SHA256
    );
    // addSign() returns a full document, so drop its XML declaration before embedding: a second
    // declaration inside the response would make the XML ill-formed.
    $signedAssertion = preg_replace('/^<\?xml[^>]*\?>\s*/', '', (string)$signedAssertion);

    // xmlseclibs inserts the Signature as the first child, but the SAML assertion schema requires
    // Issuer to come first. Reorder so the document the library validates is schema-valid, as a
    // real IdP emits it.
    $sd = new DOMDocument();
    $sd->loadXML($signedAssertion);
    $xp = new DOMXPath($sd);
    $xp->registerNamespace('saml', 'urn:oasis:names:tc:SAML:2.0:assertion');
    $xp->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
    $sig = $xp->query('/saml:Assertion/ds:Signature')->item(0);
    $issuer = $xp->query('/saml:Assertion/saml:Issuer')->item(0);
    if ($sig && $issuer) {
        $issuer->parentNode->insertBefore($sig, $issuer->nextSibling);
    }
    $signedAssertion = $sd->saveXML($sd->documentElement);

    $response = '<samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" ID="' . $id . '" Version="2.0" IssueInstant="' . $nb . '" Destination="' . $acsUrl . '">'
        . '<saml:Issuer xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion">' . $idpEntityId . '</saml:Issuer>'
        . '<samlp:Status><samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/></samlp:Status>'
        . $signedAssertion
        . '</samlp:Response>';

    if ($tamper) {
        // Break the signature by changing the signed payload after signing.
        $response = str_replace($email, 'tampered-' . $email, $response);
    }
    return base64_encode($response);
}

function post_acs(string $acsUrl, string $saml, int $firmId): array {
    $ch = curl_init($acsUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['SAMLResponse' => $saml, 'RelayState' => '']),
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $loc = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    return [$code, $loc, (string)$body];
}

// ---- 4. a valid assertion for a real member signs in ----------------------
echo "\n== valid assertion for an existing member ==\n";
$saml = build_signed_response($idpEntityId, $spEntityId, $acsUrl, 'owner-a@example.com', 'Owner A', $idpKey, $idpCert);
[$code, $loc, $body] = post_acs($acsUrl, $saml, $firmId);
check('signed assertion is accepted (302 to dashboard)', $code === 302 && str_contains($loc, 'lawyer/dashboard'));
$st = $pdo->prepare("SELECT COUNT(*) FROM sso_login_attempts WHERE firm_id=? AND outcome='success'");
$st->execute([$firmId]);
check('success recorded in the SSO audit trail', (int)$st->fetchColumn() >= 1);

// ---- 5. a tampered assertion is refused ----------------------------------
echo "\n== tampered assertion ==\n";
$before = (int)$pdo->query("SELECT COUNT(*) FROM sso_login_attempts WHERE outcome='failed'")->fetchColumn();
$samlBad = build_signed_response($idpEntityId, $spEntityId, $acsUrl, 'owner-a@example.com', 'Owner A', $idpKey, $idpCert, true);
[$code2, , ] = post_acs($acsUrl, $samlBad, $firmId);
check('tampered assertion is refused (not 302)', $code2 !== 302);
$after = (int)$pdo->query("SELECT COUNT(*) FROM sso_login_attempts WHERE outcome='failed'")->fetchColumn();
check('refusal recorded in the SSO audit trail', $after > $before);

// ---- 6. an email outside the allowed domain is refused --------------------
echo "\n== disallowed domain ==\n";
$samlOut = build_signed_response($idpEntityId, $spEntityId, $acsUrl, 'someone@not-allowed.test', 'Someone', $idpKey, $idpCert);
[$code3, , $body3] = post_acs($acsUrl, $samlOut, $firmId);
check('an identity outside the allowed domain is refused', $code3 !== 302);
check('refusal is not a session', !str_contains($body3, 'dashboard'));

// ---- 7. a valid signature for a non-member is refused ---------------------
echo "\n== valid signature, not a member of this firm ==\n";
$samlNonMember = build_signed_response($idpEntityId, $spEntityId, $acsUrl, 'client-a@example.com', 'Client A', $idpKey, $idpCert);
[$code4, , ] = post_acs($acsUrl, $samlNonMember, $firmId);
check('a non-member of the firm is refused', $code4 !== 302);
$st = $pdo->prepare("SELECT COUNT(*) FROM sso_login_attempts WHERE firm_id=? AND outcome='denied'");
$st->execute([$firmId]);
check('denial recorded in the SSO audit trail', (int)$st->fetchColumn() >= 1);

// ---- 8. cleanup -----------------------------------------------------------
echo "\n== cleanup ==\n";
$pdo->prepare('DELETE FROM sso_configs WHERE firm_id=?')->execute([$firmId]);
$pdo->prepare('DELETE FROM sso_login_attempts WHERE firm_id=?')->execute([$firmId]);
check('test SSO configuration removed', (int)$pdo->query("SELECT COUNT(*) FROM sso_configs WHERE firm_id=$firmId")->fetchColumn() === 0);

echo "\n  Executive 09 SSO: $checks checks, $failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
