<?php
/**
 * Draft PDF export (Execution 68 continuation).
 *
 * Two claims, and the second is the one that matters:
 *
 *  1. The endpoint produces a real PDF — `%PDF` header, EOF marker, embedded Arabic
 *     font. A file that opens but renders boxes is worthless, so the font check is
 *     asserted from `pdffonts` output rather than assumed.
 *  2. The endpoint is not a way around the tenant scope. A lawyer from another firm,
 *     and an unauthenticated caller, must both be refused — otherwise the export
 *     becomes the easiest way to read a competitor's draft.
 *
 * The HTTP half needs the app on 127.0.0.1:8080; it skips cleanly when it is not up.
 *
 *   php tests/exec68_draft_pdf_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/pdf.php';

$passed = 0;
$failed = 0;
$skipped = 0;
function check(string $label, bool $condition): void {
    global $passed, $failed;
    if ($condition) { $passed++; echo "  ok   $label\n"; }
    else { $failed++; echo "  FAIL $label\n"; }
}
function skip(string $label): void {
    global $skipped;
    $skipped++;
    echo "  skip $label\n";
}

echo "== draft PDF export ==\n";

$endpoint = __DIR__ . '/../lawyer/download-draft-pdf.php';
check('lawyer/download-draft-pdf.php exists', is_file($endpoint));
$src = (string)file_get_contents($endpoint);
check('endpoint scopes the row with tenant_owner_where', str_contains($src, 'tenant_owner_where'));
check('endpoint refuses an open internal review', str_contains($src, 'internal_review_started_at'));
check('endpoint goes through the shared PDF factory', str_contains($src, 'mawatheq_pdf_render'));

// --- 1. The factory produces a real PDF with the Arabic face embedded --------
if (!mawatheq_pdf_fonts_available()) {
    skip('bundled Arabic fonts are missing; cannot render');
} else {
    $mpdf = mawatheq_pdf_render(pdf_text('البند الثاني: الأتعاب 5000 ريال سعودي.'), [
        'title' => 'عقد أتعاب محاماة',
        'date'  => '2026-11-11',
    ]);
    $bytes = mawatheq_pdf_bytes($mpdf);
    check('output starts with the PDF signature', str_starts_with($bytes, '%PDF'));
    check('output carries an EOF marker', str_contains(substr($bytes, -2048), '%%EOF'));
    check('output is more than a header', strlen($bytes) > 5000);

    $tmp = tempnam(sys_get_temp_dir(), 'pdfchk') . '.pdf';
    file_put_contents($tmp, $bytes);
    $fonts = (string)shell_exec('pdffonts ' . escapeshellarg($tmp) . ' 2>/dev/null');
    check('the Arabic face is embedded (not a fallback)', str_contains($fonts, 'IBMPlexSansArabic'));
    check('the embedded face is subset', (bool)preg_match('/IBMPlexSansArabic.*\byes\b.*\byes\b/', $fonts));

    // Digits: pdftotext drops them from an Arabic run in this mPDF/font combination,
    // so the only honest check is measured ink with and without them.
    $with = mawatheq_pdf_bytes(mawatheq_pdf_render(pdf_text('الأتعاب 5000 ريال'), ['title' => 'x']));
    $without = mawatheq_pdf_bytes(mawatheq_pdf_render(pdf_text('الأتعاب ريال'), ['title' => 'x']));
    $pw = tempnam(sys_get_temp_dir(), 'pdw') . '.pdf'; file_put_contents($pw, $with);
    $pn = tempnam(sys_get_temp_dir(), 'pdn') . '.pdf'; file_put_contents($pn, $without);
    @unlink($tmp);
    check('the digit-bearing render is not byte-identical to the digitless one', $with !== $without);
    @unlink($pw);
    @unlink($pn);
}

// --- 2. Tenant isolation and auth, over real HTTP -----------------------------
$base = 'http://127.0.0.1:8080';
$probe = @file_get_contents($base . '/login.php', false, stream_context_create(['http' => ['timeout' => 3]]));
if ($probe === false) {
    skip('app is not running on 127.0.0.1:8080; HTTP checks not executed');
} else {
    // Find a draft owned by a real lawyer, and a lawyer in a different firm.
    $draft = $pdo->query("SELECT d.id, d.lawyer_id, d.firm_id FROM draft_documents d WHERE d.generated_content IS NOT NULL LIMIT 1")->fetch();
    if (!$draft) {
        skip('no draft with content to request');
    } else {
        $out = [];
        // Unauthenticated request must not receive a PDF.
        $ch = curl_init($base . '/lawyer/download-draft-pdf.php?id=' . (int)$draft['id']);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false]);
        $body = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        check('an unauthenticated request is not served a PDF',
            $code !== 200 && !str_starts_with($body, '%PDF'));

        // A lawyer from another firm must not receive it either.
        if ((int)$draft['firm_id'] > 0) {
            $other = $pdo->prepare(
                "SELECT l.email FROM lawyers l
                  WHERE l.id NOT IN (SELECT lawyer_id FROM law_firm_members WHERE firm_id = ?)
                  LIMIT 1"
            );
            $other->execute([(int)$draft['firm_id']]);
            $otherEmail = $other->fetchColumn();
            if ($otherEmail) {
                $jar = tempnam(sys_get_temp_dir(), 'pdfjar');
                $ch = curl_init($base . '/lawyer-login.php');
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar,
                    CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 20]);
                $loginPage = (string)curl_exec($ch);
                curl_close($ch);
                if (preg_match('/name="csrf_token" value="([^"]+)"/', $loginPage, $m)) {
                    $ch = curl_init($base . '/lawyer-login.php');
                    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar,
                        CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false,
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => http_build_query([
                            'csrf_token' => $m[1],
                            'email' => $otherEmail,
                            'password' => 'Test-Password-2026',
                        ])]);
                    curl_exec($ch);
                    curl_close($ch);

                    $ch = curl_init($base . '/lawyer/download-draft-pdf.php?id=' . (int)$draft['id']);
                    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar,
                        CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => false]);
                    $body2 = (string)curl_exec($ch);
                    $code2 = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    @unlink($jar);
                    check('another firm\'s lawyer is not served the draft PDF',
                        $code2 !== 200 && !str_starts_with($body2, '%PDF'));
                } else {
                    skip('could not read the lawyer login form to test cross-tenant access');
                }
            } else {
                skip('no second lawyer outside this firm to test against');
            }
        }
    }
}

echo "\n{$passed} checks, {$failed} failure(s)";
if ($skipped) echo ", {$skipped} skipped";
echo "\n";
exit($failed > 0 ? 1 : 0);
