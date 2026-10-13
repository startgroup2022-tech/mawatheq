<?php
/**
 * Shared 404 body for the public firm site (v50). Callers set http_response_code(404)
 * before including this. Kept minimal and self-contained so it never depends on a firm row.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lang/strings.php';
$lang = current_lang();
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= is_rtl() ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= is_rtl() ? 'الصفحة غير موجودة' : 'Page not found' ?></title>
<meta name="robots" content="noindex">
<link rel="icon" href="/assets/img/logo.webp">
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="stylesheet" href="/assets/css/enterprise.css">
</head>
<body class="lang-<?= $lang ?>">
<main id="main-content" class="container" style="max-width:640px;margin:14vh auto;text-align:center">
  <h1 style="font-size:2.4rem;margin-bottom:.4rem">404</h1>
  <p style="color:var(--text-muted);margin-bottom:1.4rem"><?= is_rtl() ? 'هذا الموقع غير متاح أو لم يُنشر بعد.' : 'This site is unavailable or not published yet.' ?></p>
  <a class="btn btn-primary" href="/"><?= is_rtl() ? 'العودة للرئيسية' : 'Back to home' ?></a>
</main>
</body>
</html>
