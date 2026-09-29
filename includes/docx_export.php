<?php
/**
 * مصدّر Word (.docx) بسيط بدون أي مكتبة خارجية (يعتمد فقط على امتداد ZipArchive القياسي في PHP،
 * متوفر افتراضياً على أغلب استضافات cPanel). يبني ملف .docx صالح لفتحه في Word/LibreOffice/Google Docs
 * من نص عادي، مع دعم الاتجاه من اليمين لليسار (RTL) للنصوص العربية.
 *
 * هذا ليس بديلاً كاملاً عن مكتبة توليد مستندات متقدمة (لا جداول، لا صور، لا تنسيق غني) — لكنه يغطي
 * فعلياً حاجة "Word Export احترافي" لمخرجات الصياغة القانونية: عنوان، فقرات، اتجاه صحيح، وترويسة/تذييل بسيطين.
 */

function docx_escape_xml(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/**
 * يحوّل نصاً عادياً (مفصولاً بأسطر) إلى فقرات XML لمستند Word، مع دعم RTL اختياري.
 */
function docx_paragraphs_xml(string $text, bool $rtl, bool $isHeading = false): string {
    $lines = preg_split('/\r\n|\r|\n/', $text);
    $bidi = $rtl ? '<w:bidi/>' : '';
    $rtlRun = $rtl ? '<w:rtl/>' : '';
    $jc = $rtl ? '<w:jc w:val="right"/>' : '';
    $xml = '';
    foreach ($lines as $line) {
        $line = trim($line);
        $sz = $isHeading ? '32' : '22'; // half-points: 16pt heading / 11pt body
        $bold = $isHeading ? '<w:b/>' : '';
        if ($line === '') {
            $xml .= '<w:p><w:pPr>' . $bidi . $jc . '</w:pPr></w:p>';
            continue;
        }
        $xml .= '<w:p><w:pPr>' . $bidi . $jc . '<w:rPr>' . $bold . $rtlRun . '<w:sz w:val="' . $sz . '"/></w:rPr></w:pPr>'
              . '<w:r><w:rPr>' . $bold . $rtlRun . '<w:sz w:val="' . $sz . '"/></w:rPr><w:t xml:space="preserve">' . docx_escape_xml($line) . '</w:t></w:r></w:p>';
    }
    return $xml;
}

/**
 * يبني محتوى ملف .docx كاملاً (ثنائي جاهز للحفظ/الإرسال) من عنوان ونص أساسي وتنبيه تذييل اختياري.
 */
function build_simple_docx(string $title, string $body, string $footerNote = '', bool $rtl = true): ?string {
    if (!class_exists('ZipArchive')) {
        return null; // الاستضافة لا تدعم ZipArchive — يجب تفعيل الامتداد من إعدادات PHP بلوحة cPanel
    }
    $tmpFile = tempnam(sys_get_temp_dir(), 'docx_');
    if ($tmpFile === false) return null;

    $zip = new ZipArchive();
    if ($zip->open($tmpFile, ZipArchive::OVERWRITE) !== true) { @unlink($tmpFile); return null; }

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        . '</Types>';

    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
        . '</Relationships>';

    $bodyXml = docx_paragraphs_xml($title, $rtl, true)
        . '<w:p/>'
        . docx_paragraphs_xml($body, $rtl, false);
    if ($footerNote !== '') {
        $bodyXml .= '<w:p/><w:p><w:pPr>' . ($rtl ? '<w:bidi/><w:jc w:val="right"/>' : '')
            . '<w:rPr><w:i/><w:sz w:val="18"/></w:rPr></w:pPr>'
            . '<w:r><w:rPr><w:i/>' . ($rtl ? '<w:rtl/>' : '') . '<w:sz w:val="18"/></w:rPr><w:t xml:space="preserve">' . docx_escape_xml($footerNote) . '</w:t></w:r></w:p>';
    }

    $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:body>' . $bodyXml
        . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1417" w:right="1417" w:bottom="1417" w:left="1417"/></w:sectPr>'
        . '</w:body></w:document>';

    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rootRels);
    $zip->addFromString('word/document.xml', $document);
    $zip->close();

    $binary = file_get_contents($tmpFile);
    @unlink($tmpFile);
    return $binary === false ? null : $binary;
}
