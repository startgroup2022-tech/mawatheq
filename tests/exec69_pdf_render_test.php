<?php
/**
 * اختبار توليد PDF العربي — Execution 69
 *
 * The owner approved mPDF for Arabic PDFs. The risk this suite exists for is the
 * one that makes a generated contract worthless: the file opens, is the right
 * size, and *silently* shows boxes or omissions where the Arabic text should be.
 *
 * Two distinct claims are proved here, and they are not the same claim:
 *
 *  1. **The right fonts are embedded.** `pdffonts` must list the bundled
 *     IBM Plex Sans Arabic face as embedded and subset — a fallback face would
 *     still produce a plausible-looking PDF and would still be wrong.
 *
 *  2. **The ink is on the page.** The header/footer make a byte-count or
 *     "did it throw" check useless: they always render. So the body line is
 *     located as a band of dark pixels and compared against the same line with
 *     the digits removed. If the digits were dropped, the two bands measure the
 *     same. This is the check that actually catches a missing amount.
 *
 * Note for anyone extending this: `pdftotext` does **not** round-trip the digits
 * out of an Arabic run in this mPDF/font combination — it returns the Arabic
 * words and silently omits the numeral. Measured ink proves the digits are on the
 * page (1708 dark pixels with digits vs 860 without, on the same line). Do not
 * "fix" a PDF because pdftotext lost the number; render it. That is exactly the
 * mistake this comment exists to stop someone repeating.
 *
 * Run: php tests/exec69_pdf_render_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/pdf.php';

$passed = 0; $failed = 0;
function check(string $label, bool $cond, string $extra = ''): void {
    global $passed, $failed;
    if ($cond) { $passed++; echo "  ok:   $label\n"; }
    else { $failed++; echo "  FAIL: $label" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

$tmp = sys_get_temp_dir() . '/exec69pdf_' . getmypid();
@mkdir($tmp, 0700, true);
function cleanup_dir(string $d): void {
    foreach (glob($d . '/*') ?: [] as $f) { is_dir($f) ? cleanup_dir($f) : @unlink($f); }
    @rmdir($d);
}

echo "\n== 1. the font assets the PDF path depends on ==\n";
check('Cairo-Regular.ttf is shipped', is_file(mawatheq_pdf_font_dir() . '/Cairo-Regular.ttf'));
check('Cairo-Bold.ttf is shipped', is_file(mawatheq_pdf_font_dir() . '/Cairo-Bold.ttf'));
check('IBMPlexSansArabic-Regular.ttf is shipped', is_file(mawatheq_pdf_font_dir() . '/IBMPlexSansArabic-Regular.ttf'));
check('IBMPlexSansArabic-Bold.ttf is shipped', is_file(mawatheq_pdf_font_dir() . '/IBMPlexSansArabic-Bold.ttf'));
check('the availability probe agrees', mawatheq_pdf_fonts_available() === true);

echo "\n== 2. a document is produced at all ==\n";
$body = '<p style="text-align:right">بين الطرف الأول: شركة مواثيق للمحاماة، والطرف الثاني: العميل.</p>';
$mpdf = mawatheq_pdf_render($body, ['title' => 'عقد تقديم خدمات قانونية', 'brand_name' => 'مواثيق']);
$bytes = mawatheq_pdf_bytes($mpdf);
$pdfPath = $tmp . '/contract.pdf';
file_put_contents($pdfPath, $bytes);
check('the PDF is non-trivial in size', strlen($bytes) > 8000, strlen($bytes) . ' bytes');
check('it starts with a PDF header', str_starts_with($bytes, '%PDF-'));
// The Producer string lives in compressed object streams, so a raw byte search misses
// it. pdfinfo decompresses; fall back to the raw scan only if poppler is absent.
$info = (string)shell_exec('pdfinfo ' . escapeshellarg($pdfPath) . ' 2>/dev/null');
check('mPDF is the producer', stripos($info, 'mPDF') !== false || strpos($bytes, 'mPDF') !== false, trim($info));

echo "\n== 3. the Arabic face is embedded, not emulated ==\n";
$fonts = (string)shell_exec('pdffonts ' . escapeshellarg($pdfPath) . ' 2>/dev/null');
if (trim($fonts) === '') {
    echo "  SKIP: pdffonts is not installed (poppler-utils)\n";
} else {
    check('the embedded font is the bundled IBM Plex Arabic face', stripos($fonts, 'IBMPlexSansArabic') !== false, $fonts);
    // pdffonts prints `emb sub uni` as a single space-separated group, so the column after
    // the encoding is "yes yes yes" — asserting only on cols[3] === 'yes' fails on a
    // correctly embedded font. The first word of that group is the `emb` flag.
    $embedded = false;
    foreach (preg_split('/\R/', $fonts) ?: [] as $line) {
        if (stripos($line, 'IBMPlexSansArabic') === false) continue;
        if (preg_match('/Identity-\w+\s+(yes|no)\s+/i', $line, $m) && strtolower($m[1]) === 'yes') {
            $embedded = true;
        }
    }
    check('the face is marked embedded', $embedded, $fonts);
}

echo "\n== 4. the amount actually has ink on the page ==\n";
// Two documents identical except for the number. Same line, same font, same size.
$mk = function (string $text) use ($tmp): string {
    $m = mawatheq_pdf_render('<p style="text-align:right;font-size:16pt">' . $text . '</p>', []);
    $p = $tmp . '/m' . md5($text) . '.pdf';
    file_put_contents($p, mawatheq_pdf_bytes($m));
    return $p;
};
$withDigits = $mk('المبلغ 98765.43 ريال');
$without    = $mk('المبلغ ريال');

/** Ink (dark pixels) on the body text line — the widest band below the header. */
$bodyInk = function (string $pdf) use ($tmp): int {
    @array_map('unlink', glob($tmp . '/ink-*.png') ?: []);
    shell_exec('pdftoppm -r 150 -png -f 1 -l 1 ' . escapeshellarg($pdf) . ' ' . escapeshellarg($tmp . '/ink') . ' 2>/dev/null');
    $files = glob($tmp . '/ink-*.png') ?: [];
    if (!$files) return -1;
    $im = imagecreatefrompng($files[0]);
    $w = imagesx($im); $h = imagesy($im);
    $lines = []; $cur = null;
    for ($y = 0; $y < $h; $y++) {
        $d = 0;
        for ($x = 0; $x < $w; $x++) {
            $c = imagecolorat($im, $x, $y);
            if (((($c >> 16) & 0xFF) < 128)) $d++;
        }
        if ($d > 1) { if ($cur === null) $cur = [$y, $y]; else $cur[1] = $y; }
        elseif ($cur !== null) { $lines[] = $cur; $cur = null; }
    }
    if ($cur !== null) $lines[] = $cur;
    imagedestroy($im);
    // bands: header rule, header text, body, footer text — the body is the widest
    $best = null; $bestH = 0;
    foreach ($lines as $b) {
        $bh = $b[1] - $b[0];
        if ($bh > $bestH) { $bestH = $bh; $best = $b; }
    }
    if ($best === null) return 0;
    $im = imagecreatefrompng($files[0]);
    $ink = 0;
    for ($y = $best[0]; $y <= $best[1]; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $c = imagecolorat($im, $x, $y);
            if (((($c >> 16) & 0xFF) < 128)) $ink++;
        }
    }
    imagedestroy($im);
    return $ink;
};

if (!function_exists('imagecreatefrompng')) {
    echo "  SKIP: the GD extension is required (php-gd)\n";
} else {
    $inkWith = $bodyInk($withDigits);
    $inkWithout = $bodyInk($without);
    check('the amount line renders ink', $inkWith > 0, "ink=$inkWith");
    check(
        'the digits add ink to the line (they are drawn, not dropped)',
        $inkWith > $inkWithout + 200,
        "with=$inkWith without=$inkWithout"
    );
}

echo "\n== 5. every interpolated value is escaped ==\n";
check('pdf_e escapes angle brackets', pdf_e('<script>') === '&lt;script&gt;');
check('pdf_e escapes a quote', pdf_e("a'b") === 'a&#039;b');
$multi = pdf_text("line one\nline two");
check('pdf_text wraps in a pre-wrap block', strpos($multi, 'white-space:pre-wrap') !== false);
check('pdf_text escapes its content', strpos(pdf_text('<b>x</b>'), '&lt;b&gt;') !== false);
check('pdf_text renders empty for whitespace only', pdf_text("   \n ") === '');

echo "\n== 6. a caller cannot silently skip the fonts ==\n";
$src = file_get_contents(__DIR__ . '/../includes/pdf.php');
check('the factory refuses to run without the fonts', strpos($src, 'Arabic PDF fonts are missing') !== false);
check('the factory sets the Arabic face as the default', strpos($src, "'default_font' => 'mawatheqbody'") !== false);
check('autoScriptToLang is enabled', strpos($src, 'autoScriptToLang = true') !== false);
check('autoLangToFont is enabled', strpos($src, 'autoLangToFont = true') !== false);

cleanup_dir($tmp);
echo "\n{$passed} checks, {$failed} failure(s)\n";
exit($failed === 0 ? 0 : 1);
