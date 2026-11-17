<?php
/**
 * المكتبة القانونية والقضائية — مساعدات العرض (Execution 68).
 * Legal & judicial library — shared render helpers.
 *
 * The hub, search, item and files pages all describe an item the same way, so the
 * chip row, the card and the meta grid live here once. Every helper only emits
 * HTML: no query runs here, so a view helper never takes an authorization decision
 * (the redistribution rule is applied upstream in legal_library_public_item()).
 */

require_once __DIR__ . '/ui.php';

if (!function_exists('ll_chip')) {
    /** One pill. $variant ∈ '', type, src, court, derived. */
    function ll_chip(string $text, string $variant = '', string $icon = ''): string {
        $cls = 'll-chip' . ($variant !== '' ? ' ' . $variant : '');
        $ic = $icon !== '' ? ui_icon($icon, 'll-ico ll-ico-sm') : '';
        return '<span class="' . $cls . '">' . $ic . ui_e($text) . '</span>';
    }
}

if (!function_exists('ll_item_meta_chips')) {
    /** The shared chip row for one public item. */
    function ll_item_meta_chips(array $it, bool $withType = true): string {
        $rtl = is_rtl();
        $out = '';
        if ($withType && !empty($it['type_label'])) $out .= ll_chip((string)$it['type_label'], 'type');
        if (!empty($it['court_name'])) $out .= ll_chip((string)$it['court_name'], 'court', 'landmark');
        if (!empty($it['city'])) $out .= ll_chip((string)$it['city'], 'court', 'map');
        if (!empty($it['decision_number'])) $out .= ll_chip(($rtl ? 'رقم ' : 'No. ') . $it['decision_number'], 'court');
        if (!empty($it['decision_date'])) $out .= ll_chip((string)$it['decision_date'], 'court', 'calendar');
        elseif (!empty($it['hijri_year'])) $out .= ll_chip((int)$it['hijri_year'] . ($rtl ? ' هـ' : ' AH'), 'court', 'calendar');
        if (!empty($it['title_is_derived'])) $out .= ll_chip($rtl ? 'وصف من الحقول الرسمية' : 'Described from official fields', 'derived', 'info');
        return $out !== '' ? '<div class="ll-chips ll-meta">' . $out . '</div>' : '';
    }
}

if (!function_exists('ll_item_card')) {
    /** A full result card (search results, latest lists). */
    function ll_item_card(array $it): string {
        $rtl = is_rtl();
        $url = '/legal-library-item.php?id=' . (int)$it['id'];
        $summary = trim((string)($it['summary'] ?? ''));
        $h = '<article class="ll-item">';
        $h .= '<div class="ll-item-top">' . ll_chip((string)$it['type_label'], 'type');
        if (!empty($it['source_name'])) $h .= ll_chip((string)$it['source_name'], 'src', 'building');
        $h .= '</div>';
        $h .= '<h3><a href="' . ui_e($url) . '">' . ui_e((string)$it['title']) . '</a></h3>';
        if ($summary !== '') $h .= '<p class="ll-summary">' . ui_e(mb_substr($summary, 0, 260)) . (mb_strlen($summary) > 260 ? '…' : '') . '</p>';
        $h .= ll_item_meta_chips($it, false);
        $h .= '<div class="ll-actions" style="margin:12px 0 0">'
            . '<a class="btn btn-outline btn-sm" href="' . ui_e($url) . '">' . ui_icon('eye', 'll-ico ll-ico-sm') . ' ' . ($rtl ? 'عرض التفاصيل' : 'View details') . '</a>';
        if (!empty($it['download_url'])) {
            $ext = !empty($it['download_is_external']);
            $h .= '<a class="btn btn-outline btn-sm" href="' . ui_e((string)$it['download_url']) . '"' . ($ext ? ' target="_blank" rel="noopener noreferrer"' : '') . '>'
                . ui_icon($ext ? 'external' : 'download', 'll-ico ll-ico-sm') . ' '
                . ($ext ? ($rtl ? 'من المصدر' : 'From source') : ($rtl ? 'تنزيل' : 'Download')) . '</a>';
        }
        $h .= '</div>';
        $h .= '</article>';
        return $h;
    }
}

if (!function_exists('ll_mini_card')) {
    /** A compact card for the "latest" strip on the hub. */
    function ll_mini_card(array $row): string {
        $it = legal_library_public_item($row);
        $url = '/legal-library-item.php?id=' . (int)$it['id'];
        $h = '<a class="ll-mini" href="' . ui_e($url) . '">';
        $h .= '<div class="ll-chips" style="margin-bottom:8px">' . ll_chip((string)$it['type_label'], 'type');
        if (!empty($it['source_name'])) $h .= ll_chip((string)$it['source_name'], 'src');
        $h .= '</div>';
        $h .= '<div class="ll-mini-title">' . ui_e((string)$it['title']) . '</div>';
        $h .= ll_item_meta_chips($it, false);
        $h .= '</a>';
        return $h;
    }
}

if (!function_exists('ll_type_icon')) {
    /** A stable icon per content type. */
    function ll_type_icon(string $type): string {
        return match ($type) {
            'legislation' => 'scale',
            'book'        => 'book',
            'statistics'  => 'chart',
            'article'     => 'file',
            default       => 'gavel',
        };
    }
}

if (!function_exists('ll_pager')) {
    /** Shared pager. $urlFor(int $page): string. */
    function ll_pager(int $page, int $pages, callable $urlFor): string {
        if ($pages <= 1) return '';
        $rtl = is_rtl();
        $h = '<nav class="ll-pager" aria-label="' . ($rtl ? 'تصفّح الصفحات' : 'Pagination') . '">';
        if ($page > 1) $h .= '<a href="' . ui_e($urlFor($page - 1)) . '">' . ($rtl ? 'السابق' : 'Prev') . '</a>';
        $start = max(1, $page - 2);
        $end = min($pages, $start + 4);
        if ($start > 1) {
            $h .= '<a href="' . ui_e($urlFor(1)) . '">1</a>';
            if ($start > 2) $h .= '<span class="dots">…</span>';
        }
        for ($p = $start; $p <= $end; $p++) {
            $h .= $p === $page ? '<span class="current" aria-current="page">' . $p . '</span>'
                               : '<a href="' . ui_e($urlFor($p)) . '">' . $p . '</a>';
        }
        if ($end < $pages) {
            if ($end < $pages - 1) $h .= '<span class="dots">…</span>';
            $h .= '<a href="' . ui_e($urlFor($pages)) . '">' . $pages . '</a>';
        }
        if ($page < $pages) $h .= '<a href="' . ui_e($urlFor($page + 1)) . '">' . ($rtl ? 'التالي' : 'Next') . '</a>';
        $h .= '</nav>';
        return $h;
    }
}
