<?php
/**
 * ============================================================
 * مواثيق — طبيب بوابة الدفع (CLI فقط، للقراءة فقط).
 * ============================================================
 * يجيب عن سؤال واحد: «لماذا فشل بدء الدفع؟» — على السيرفر مباشرةً، دون
 * الحاجة إلى فتح لوحة الإدارة.
 *
 *   php tools/gateway_doctor.php                فحص جاهزية البوابة (لا يُنشئ طلباً)
 *   php tools/gateway_doctor.php --live         فحص كامل (يُنشئ طلباً غير مدفوع)
 *   php tools/gateway_doctor.php B7A81366       ابحث عن الرقم المرجعي الذي رآه العميل
 *
 * لا يطبع أي مفتاح أو رمز أو سرّ. رمز الخروج 0 = البوابة جاهزة، 1 = يوجد ما يمنع الدفع.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found\n");
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/ngenius.php';
require_once __DIR__ . '/../includes/gateway_diagnostics.php';

$argvList = $argv ?? [];
$reference = '';
$live = false;
foreach (array_slice($argvList, 1) as $arg) {
    if ($arg === '--live') { $live = true; continue; }
    if (preg_match('/^[A-Fa-f0-9]{8}$/', $arg)) { $reference = strtoupper($arg); }
}

echo "مواثيق — طبيب بوابة الدفع / Mawatheq — gateway doctor\n";
echo str_repeat('=', 60) . "\n";

/* ---------------------------------------- a reference the customer saw ---- */
if ($reference !== '') {
    echo "الرقم المرجعي / Reference: $reference\n\n";
    if (!gateway_diag_schema_ready($pdo)) {
        echo "  [WARN] جدول التشخيص غير موجود — طبّق database/upgrade_v72.sql.\n";
        echo "         حتى ذلك الحين يُكتب كل فشل في سجلّ خطأ الخادم بصيغة:\n";
        echo "         gateway_diag[$reference] op=... stage=... code=...\n";
        echo "         ابحث في سجلّ Apache/PHP:  grep '$reference' /path/to/error_log\n";
        exit(1);
    }
    $rows = gateway_diag_find($pdo, $reference);
    if (!$rows) {
        echo "  [FAIL] لا يوجد أي سجلّ بهذا الرقم المرجعي في قاعدة البيانات.\n";
        echo "         تأكد من الرقم، أو ابحث في سجلّ خطأ الخادم عن:\n";
        echo "         gateway_diag[$reference]\n";
        exit(1);
    }
    foreach ($rows as $r) {
        $reason = gateway_diag_reason((string)($r['error_code'] ?? ''));
        printf("  العملية/operation : %s\n", (string)$r['operation']);
        printf("  المرحلة/stage     : %s\n", (string)$r['stage']);
        printf("  السبب/code        : %s\n", (string)($r['error_code'] ?? '—'));
        printf("  الشرح/why         : %s\n", $reason['ar']);
        printf("  Detail            : %s\n", (string)($r['detail'] ?? ''));
        printf("  البيئة/environment: %s\n", (string)($r['environment'] ?? '—'));
        printf("  الوقت/time        : %s\n", (string)$r['created_at']);
        echo "\n";
    }
    exit(0);
}

/* ------------------------------------------------ readiness (no charge) ---- */
$settings = ngenius_settings($pdo);
$enabled = ngenius_is_enabled($settings);
$env = (string)($settings['ngenius_environment'] ?? 'sandbox');
$outlet = (string)($settings['ngenius_outlet_ref'] ?? '');
$keySet = !empty($settings['ngenius_api_key']);
$outletCurrency = ngenius_currency($settings);
$siteCurrency = strtoupper(currency_code());

$bad = 0;
$say = function (bool $ok, string $label, string $detail) use (&$bad): void {
    if (!$ok) $bad++;
    printf("  [%s] %-30s %s\n", $ok ? 'OK  ' : 'FAIL', $label, $detail);
};

$say($enabled, 'gateway enabled', $enabled ? 'yes' : 'no — switch N-Genius on and enter the outlet ref + API key');
$say($outlet !== '', 'outlet reference set', $outlet !== '' ? 'yes' : 'no');
$say($keySet, 'API key set', $keySet ? 'yes' : 'no');
$say(true, 'environment', $env);
$say(true, 'outlet settlement currency', $outletCurrency);
$say(true, 'site currency', $siteCurrency);
$say(strtoupper($siteCurrency) === $outletCurrency, 'currency match',
    strtoupper($siteCurrency) === $outletCurrency
        ? 'site == outlet'
        : "MISMATCH — package and consultation payments will be refused (set the outlet currency to $siteCurrency, or move the site to $outletCurrency)");

if (!gateway_diag_schema_ready($pdo)) {
    echo "\n  [WARN] diagnostics table missing — apply database/upgrade_v72.sql so a failed\n";
    echo "         attempt is recorded and its reference is searchable.\n";
}

if ($bad > 0) {
    echo "\nالنتيجة: يوجد $bad ما يمنع بدء الدفع. أصلح البنود أعلاه ثم أعد الفحص.\n";
    echo "Result: $bad blocking problem(s) — fix the FAIL lines above and re-run.\n";
    exit(1);
}

/* ------------------------------------------------------- optional live probe ---- */
if (!$live) {
    echo "\nالنتيجة: الإعدادات سليمة. للتحقق الفعلي من الاتصال بالبوابة أعد التشغيل مع --live\n";
    echo "(يُنشئ طلباً غير مدفوع؛ لا يُخصم أي مبلغ).\n";
    echo "Result: configuration looks correct. Re-run with --live to test the real connection.\n";
    exit(0);
}

$probe = gateway_diag_probe($pdo, ['dry_run' => false]);
echo "\nفحص الاتصال الحقيقي / live probe: " . (($probe['ok'] ?? false) ? 'OK' : 'FAILED')
    . ' — verdict=' . (string)($probe['verdict'] ?? '?') . "\n";
foreach (($probe['stages'] ?? []) as $st) {
    printf("  [%s] %-12s %s\n", !empty($st['ok']) ? 'OK  ' : 'FAIL', (string)$st['stage'], (string)($st['detail'] ?? ''));
}
echo '  reference: ' . (string)($probe['correlation_id'] ?? '') . "\n";
exit(($probe['ok'] ?? false) ? 0 : 1);
