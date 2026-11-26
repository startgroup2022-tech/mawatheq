<?php
/**
 * مصنع ملفات PDF العربية — mPDF configured for this platform.
 *
 * Every PDF the platform produces goes through here. Two things are easy to get
 * wrong and both produce a file that opens fine and is unreadable:
 *
 *  1. **The font must be registered before the document sets it.** mPDF picks its
 *     default font in the constructor, so an Arabic font supplied later still
 *     renders the first page in a Latin face. `configure` installs the family in
 *     `fontdata` and sets it as the default up front.
 *  2. **`autoLangToFont` alone is not enough for Arabic.** It maps the script to
 *     a font when one is configured; without `autoScriptToLang` the runs are not
 *     segmented first and short Arabic strings can fall through to the Latin face.
 *
 * The font files ship in assets/fonts/ (Cairo is the display face, IBM Plex Sans
 * Arabic the body face), so a deployment needs no font installation and cannot
 * silently fall back to a face that renders Arabic as boxes.
 */

/** Absolute path to the bundled fonts. */
function mawatheq_pdf_font_dir(): string {
    return dirname(__DIR__) . '/assets/fonts';
}

/** Whether the Arabic fonts are present; a missing font is a deployment fault, not a fallback. */
function mawatheq_pdf_fonts_available(): bool {
    return is_file(mawatheq_pdf_font_dir() . '/Cairo-Regular.ttf')
        && is_file(mawatheq_pdf_font_dir() . '/IBMPlexSansArabic-Regular.ttf');
}

/**
 * Build a configured mPDF instance.
 *
 * @param array $opts format, margins, footer_html, header_html, watermark
 */
function mawatheq_pdf_make(array $opts = []): \Mpdf\Mpdf {
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('mPDF is not installed: vendor/autoload.php is missing.');
    }
    require_once $autoload;
    if (!mawatheq_pdf_fonts_available()) {
        throw new RuntimeException('Arabic PDF fonts are missing from assets/fonts/.');
    }

    $fontDir = mawatheq_pdf_font_dir();
    $tempDir = sys_get_temp_dir() . '/mawatheq-mpdf';
    if (!is_dir($tempDir)) @mkdir($tempDir, 0700, true);

    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => $opts['format'] ?? 'A4',
        'margin_left' => $opts['margin_left'] ?? 18,
        'margin_right' => $opts['margin_right'] ?? 18,
        'margin_top' => $opts['margin_top'] ?? 24,
        'margin_bottom' => $opts['margin_bottom'] ?? 22,
        'margin_header' => 10,
        'margin_footer' => 10,
        'tempDir' => $tempDir,
        'fontDir' => [$fontDir],
        'fontdata' => [
            'mawatheqbody' => [
                'R' => 'IBMPlexSansArabic-Regular.ttf',
                'B' => 'IBMPlexSansArabic-Bold.ttf',
                'I' => 'IBMPlexSansArabic-Regular.ttf',
                'BI' => 'IBMPlexSansArabic-Bold.ttf',
                'useOTL' => 0xFF,
                'useKashida' => 75,
            ],
            'mawatheqdisplay' => [
                'R' => 'Cairo-Regular.ttf',
                'B' => 'Cairo-Bold.ttf',
                'useOTL' => 0xFF,
            ],
        ],
        'default_font' => 'mawatheqbody',
        'default_font_size' => 11,
        'directionality' => 'rtl',
    ]);

    $mpdf->autoScriptToLang = true;
    $mpdf->autoLangToFont = true;
    $mpdf->SetDirectionality('rtl');
    $mpdf->useSubstitutions = false; // keep every glyph in the bundled face
    $mpdf->SetAuthor((string)($opts['author'] ?? SITE_NAME_AR));
    $mpdf->SetCreator((string)($opts['creator'] ?? SITE_NAME_AR));
    $mpdf->SetTitle((string)($opts['title'] ?? ''));
    $mpdf->SetAutoPageBreak(true, 20);

    if (!empty($opts['show_watermark'])) {
        $mpdf->SetWatermarkText((string)($opts['show_watermark']), 0.06);
        $mpdf->showWatermarkText = true;
    }
    return $mpdf;
}

/**
 * The document wrapping: letterhead, title block, body, footer with page numbers.
 *
 * `$bodyHtml` is trusted HTML the caller built from escaped values — this helper
 * never interpolates user input itself, so it cannot become a template injection.
 */
function mawatheq_pdf_render(string $bodyHtml, array $opts = []): \Mpdf\Mpdf {
    $mpdf = mawatheq_pdf_make($opts);
    $brand = (string)($opts['brand_name'] ?? SITE_NAME_AR);
    $title = (string)($opts['title'] ?? '');
    $date  = (string)($opts['date'] ?? date('Y-m-d'));

    $header = '<table style="width:100%;border-bottom:1px solid #C9D4DE;font-size:9pt;color:#5C6B7A">'
        . '<tr><td style="text-align:right">' . htmlspecialchars($brand, ENT_QUOTES, 'UTF-8') . '</td>'
        . '<td style="text-align:left">' . htmlspecialchars($date, ENT_QUOTES, 'UTF-8') . '</td></tr></table>';
    $footer = '<table style="width:100%;border-top:1px solid #C9D4DE;font-size:8.5pt;color:#5C6B7A">'
        . '<tr><td style="text-align:right">' . htmlspecialchars($brand, ENT_QUOTES, 'UTF-8') . '</td>'
        . '<td style="text-align:left">{PAGENO} / {nbpg}</td></tr></table>';

    $mpdf->SetHTMLHeader($header);
    $mpdf->SetHTMLFooter($footer);

    $heading = $title !== ''
        ? '<h1 style="font-family:mawatheqdisplay;font-size:17pt;color:#0F1F33;text-align:center;margin:0 0 14pt">'
            . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>'
        : '';
    $mpdf->WriteHTML($heading . $bodyHtml);
    return $mpdf;
}

/** Stream a generated PDF to the browser as a download. */
function mawatheq_pdf_download(\Mpdf\Mpdf $mpdf, string $filename): void {
    $safe = preg_replace('/[^\p{Arabic}A-Za-z0-9._-]+/u', '-', $filename) ?: 'document.pdf';
    if (!preg_match('/\.pdf$/i', $safe)) $safe .= '.pdf';
    $mpdf->Output($safe, \Mpdf\Output\Destination::DOWNLOAD);
    exit;
}

/** Return the PDF as bytes (for storage or an API response). */
function mawatheq_pdf_bytes(\Mpdf\Mpdf $mpdf): string {
    return (string)$mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
}

/**
 * Escape a value for HTML body text.
 * PDF bodies are built by concatenation, so every interpolated value must pass here.
 */
function pdf_e(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** Multi-line text becomes paragraphs, never raw HTML. */
function pdf_text(?string $value): string {
    $v = trim((string)$value);
    if ($v === '') return '';
    return '<div style="white-space:pre-wrap;line-height:1.9">' . pdf_e($v) . '</div>';
}
