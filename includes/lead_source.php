<?php
/**
 * تتبع مصدر العميل/المحامي: من أي حملة (utm_campaign)، من أي موقع/قناة (utm_source)،
 * ومن أي صفحة دخل أول مرة (landing page). يُخزَّن "أول لمسة" فقط في الجلسة
 * (لا يُستبدل في الزيارات التالية) ثم يُحفظ مع تسجيل العميل/المحامي/الطلب.
 */

function capture_lead_source(): void {
    if (!empty($_SESSION['lead_source_captured'])) {
        return; // أول لمسة فقط
    }

    $source = trim($_GET['utm_source'] ?? '');
    $medium = trim($_GET['utm_medium'] ?? '');
    $campaign = trim($_GET['utm_campaign'] ?? '');

    // إن لم تتوفر معاملات UTM، استنتج المصدر من الـ Referrer عند توفره
    if ($source === '' && !empty($_SERVER['HTTP_REFERER'])) {
        $host = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_HOST);
        if ($host && stripos($host, $_SERVER['HTTP_HOST'] ?? '') === false) {
            $source = $host;
            if ($medium === '') $medium = 'referral';
        }
    }
    if ($source === '') $source = 'direct';

    $page = $_SERVER['REQUEST_URI'] ?? '/';

    $_SESSION['lead_source'] = [
        'utm_source'   => mb_substr($source, 0, 100),
        'utm_medium'   => mb_substr($medium, 0, 100),
        'utm_campaign' => mb_substr($campaign, 0, 150),
        'source_page'  => mb_substr($page, 0, 255),
    ];
    $_SESSION['lead_source_captured'] = true;
}

/**
 * @return array{utm_source:string, utm_medium:string, utm_campaign:string, source_page:string}
 */
function get_lead_source(): array {
    return $_SESSION['lead_source'] ?? [
        'utm_source' => 'direct', 'utm_medium' => '', 'utm_campaign' => '', 'source_page' => '/',
    ];
}

/**
 * تسمية عرض مختصرة لمصدر السجل، تُستخدم في لوحة التحكم.
 */
function format_lead_source(?string $source, ?string $medium, ?string $campaign): string {
    $parts = [];
    if ($source) $parts[] = $source;
    if ($medium) $parts[] = $medium;
    $label = $parts ? implode(' / ', $parts) : (is_rtl() ? 'مباشر' : 'Direct');
    if ($campaign) $label .= ' — ' . $campaign;
    return $label;
}
