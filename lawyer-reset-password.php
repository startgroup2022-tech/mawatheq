<?php
/**
 * Lawyer password-reset landing page.
 *
 * Exists as its own path so that a device with both the client app and the lawyer
 * app installed can route a reset link to the correct one: Android App Links and
 * iOS Universal Links match on the URL path, so two apps cannot both claim
 * `/reset-password.php`. A lawyer link therefore points here, and the lawyer app
 * declares this path in its manifest.
 *
 * The page itself is the shared implementation — there is exactly one place that
 * consumes a reset token, so the two paths cannot drift apart. This wrapper only
 * fixes the account type when the link omitted it, so the form is rendered for a
 * lawyer rather than defaulting to the customer variant.
 */
$_GET['as'] = 'lawyer';
$_POST['as'] = 'lawyer';

require __DIR__ . '/reset-password.php';
