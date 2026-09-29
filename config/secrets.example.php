<?php
// Copy to config/secrets.php on the server and fill in private cPanel values.
// Keep secrets.php out of backups shared publicly and out of source control.
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_cpanel_database');
define('DB_USER', 'your_cpanel_database_user');
define('DB_PASS', 'replace-with-a-unique-strong-password');
define('SITE_URL', 'https://mawatheq.com');
define('MAIL_FROM_EMAIL', 'info@mawatheq.com');
// Generate a unique random key of at least 32 characters for first-admin setup.
define('ADMIN_SETUP_KEY', 'replace-with-a-random-secret-at-least-32-characters');
// Keep this stable: changing it makes encrypted provider/payment secrets unreadable.
define('MAWATHEQ_ENCRYPTION_KEY', 'replace-with-a-different-random-secret-at-least-32-characters');
