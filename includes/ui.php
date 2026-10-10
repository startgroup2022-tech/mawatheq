<?php
/**
 * Shared UI component helpers (Phase 4).
 *
 * Small, dependency-free rendering helpers so pages stop hand-rolling the same
 * markup (page headers, empty states, badges, pagination, table wrappers).
 * They only emit HTML — no query runs here, so no authorization decision is
 * ever taken by a view helper.
 */

if (!function_exists('ui_e')) {
    /** Escape for HTML text/attribute context. */
    function ui_e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('ui_icon')) {
    /** Inline SVG icon (stroke-based, inherits currentColor). */
    function ui_icon(string $name, string $class = 'i'): string {
        static $paths = [
            'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
            'briefcase' => '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
            'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
            'user' => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
            'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
            'message' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
            'file' => '<path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/>',
            'folder' => '<path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.5l-2-2H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2z"/>',
            'receipt' => '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8M12 17.5v-11"/>',
            'card' => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
            'paid' => '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 12h.01M18 12h.01"/>',
            'wallet' => '<path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/>',
            'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
            'spark' => '<path d="m12 3-1.9 5.8a2 2 0 0 1-1.3 1.3L3 12l5.8 1.9a2 2 0 0 1 1.3 1.3L12 21l1.9-5.8a2 2 0 0 1 1.3-1.3L21 12l-5.8-1.9a2 2 0 0 1-1.3-1.3Z"/>',
            'bot' => '<rect x="3" y="11" width="18" height="10" rx="2"/><circle cx="12" cy="5" r="2"/><path d="M12 7v4M8 16h.01M16 16h.01"/>',
            'book' => '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2zM22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>',
            'landmark' => '<path d="M3 22h18M6 18v-7M10 18v-7M14 18v-7M18 18v-7M12 2 3 7h18z"/>',
            'check' => '<path d="m9 11 3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
            'check-circle' => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/>',
            'bell' => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
            'clock' => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
            'chart' => '<path d="M3 3v18h18M18 17V9M13 17V5M8 17v-3"/>',
            'scale' => '<path d="m16 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"/><path d="m2 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"/><path d="M7 21h10M12 3v18M3 7h2c2 0 5-1 7-2 2 1 5 2 7 2h2"/>',
            'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
            'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
            'plus' => '<path d="M12 5v14M5 12h14"/>',
            'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
            'close' => '<path d="M18 6 6 18M6 6l12 12"/>',
            'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
            'external' => '<path d="M15 3h6v6M10 14 21 3M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
            'alert' => '<circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/>',
            'inbox' => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
            'edit' => '<path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
            'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>',
            'eye' => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
            'lock' => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        ];
        $body = $paths[$name] ?? $paths['dashboard'];
        return '<svg class="' . ui_e($class) . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $body . '</svg>';
    }
}

if (!function_exists('ui_breadcrumbs')) {
    /**
     * @param array<int,array{label:string,href?:?string}> $items
     */
    function ui_breadcrumbs(array $items): string {
        if (!$items) return '';
        $out = '<nav aria-label="' . (is_rtl() ? 'مسار التنقل' : 'Breadcrumb') . '"><ol class="breadcrumbs">';
        $last = count($items) - 1;
        foreach ($items as $i => $it) {
            $label = ui_e($it['label']);
            $out .= '<li>';
            if ($i === $last || empty($it['href'])) {
                $out .= '<span aria-current="page">' . $label . '</span>';
            } else {
                $out .= '<a href="' . ui_e($it['href']) . '">' . $label . '</a>'
                      . '<span class="sep" aria-hidden="true">/</span>';
            }
            $out .= '</li>';
        }
        return $out . '</ol></nav>';
    }
}

if (!function_exists('ui_page_head')) {
    /**
     * Page title + optional subtitle and actions.
     * @param array<int,string> $actions Pre-rendered action HTML.
     */
    function ui_page_head(string $title, string $subtitle = '', array $actions = [], string $tag = 'h2'): string {
        $tag = in_array($tag, ['h1', 'h2', 'h3'], true) ? $tag : 'h2';
        $html = '<div class="page-head"><div>';
        $html .= '<' . $tag . '>' . ui_e($title) . '</' . $tag . '>';
        if ($subtitle !== '') $html .= '<p class="page-sub">' . ui_e($subtitle) . '</p>';
        $html .= '</div>';
        if ($actions) $html .= '<div class="page-head-actions">' . implode('', $actions) . '</div>';
        return $html . '</div>';
    }
}

if (!function_exists('ui_empty_state')) {
    /**
     * @param array<int,string> $actions Pre-rendered action HTML.
     */
    function ui_empty_state(string $title, string $body = '', string $icon = 'inbox', array $actions = []): string {
        $html = '<div class="empty-state"><div class="empty-icon">' . ui_icon($icon) . '</div>';
        $html .= '<h3>' . ui_e($title) . '</h3>';
        if ($body !== '') $html .= '<p>' . ui_e($body) . '</p>';
        if ($actions) $html .= '<div class="empty-actions">' . implode('', $actions) . '</div>';
        return $html . '</div>';
    }
}

if (!function_exists('ui_badge')) {
    /** Status pill whose modifier class is derived from the raw status value. */
    function ui_badge(string $status, ?string $label = null): string {
        $slug = strtolower(trim($status));
        $slug = preg_replace('/[^a-z0-9_]/', '_', $slug);
        $known = ['confirmed','active','approved','completed','paid','closed_won','success',
                  'pending','processing','unpaid','in_review','draft','partially_paid','overdue',
                  'cancelled','suspended','failed','closed_lost'];
        $cls = in_array($slug, $known, true) ? 'status-' . $slug : 'badge-neutral';
        $label = $label ?? $status;
        return '<span class="status-pill ' . ui_e($cls) . '">' . ui_e($label) . '</span>';
    }
}

if (!function_exists('ui_status_label')) {
    /** Arabic/English label for a raw status value. */
    function ui_status_label(string $status): string {
        $map = [
            'open' => ['مفتوحة', 'Open'], 'in_review' => ['قيد المراجعة', 'In review'],
            'closed_won' => ['مغلقة — كسب', 'Closed — won'], 'closed_lost' => ['مغلقة — خسارة', 'Closed — lost'],
            'closed_settled' => ['مغلقة — تسوية', 'Closed — settled'],
            'pending' => ['قيد الانتظار', 'Pending'], 'confirmed' => ['مؤكدة', 'Confirmed'],
            'completed' => ['مكتملة', 'Completed'], 'cancelled' => ['ملغاة', 'Cancelled'],
            'active' => ['نشط', 'Active'], 'approved' => ['معتمد', 'Approved'], 'suspended' => ['موقوف', 'Suspended'],
            'unpaid' => ['غير مدفوعة', 'Unpaid'], 'partially_paid' => ['مدفوعة جزئياً', 'Partially paid'],
            'paid' => ['مدفوعة', 'Paid'], 'overdue' => ['متأخرة', 'Overdue'], 'draft' => ['مسودة', 'Draft'],
            'failed' => ['فاشلة', 'Failed'], 'processing' => ['قيد المعالجة', 'Processing'],
        ];
        $key = strtolower(trim($status));
        if (!isset($map[$key])) return $status;
        return is_rtl() ? $map[$key][0] : $map[$key][1];
    }
}

if (!function_exists('ui_pagination')) {
    /**
     * Server-side pagination links. $baseUrl must already contain the query string
     * with a %d placeholder for the page number, e.g. "/lawyer/cases.php?page=%d".
     */
    function ui_pagination(int $page, int $perPage, int $total, string $baseUrl): string {
        $pages = (int)max(1, ceil($total / max(1, $perPage)));
        if ($pages < 2) return '';
        $page = max(1, min($page, $pages));
        $url = fn(int $p): string => sprintf($baseUrl, $p);
        $rtl = is_rtl();
        $out = '<nav class="pagination" aria-label="' . ($rtl ? 'ترقيم الصفحات' : 'Pagination') . '">';
        $out .= '<a class="' . ($page <= 1 ? 'is-disabled' : '') . '" href="' . ui_e($url(max(1, $page - 1))) . '"'
              . ($page <= 1 ? ' aria-disabled="true"' : '') . '>' . ($rtl ? 'السابق' : 'Previous') . '</a>';
        $from = max(1, $page - 2); $to = min($pages, $page + 2);
        if ($from > 1) $out .= '<a href="' . ui_e($url(1)) . '">1</a>' . ($from > 2 ? '<span>…</span>' : '');
        for ($p = $from; $p <= $to; $p++) {
            $out .= $p === $page
                ? '<span aria-current="page">' . $p . '</span>'
                : '<a href="' . ui_e($url($p)) . '">' . $p . '</a>';
        }
        if ($to < $pages) $out .= ($to < $pages - 1 ? '<span>…</span>' : '') . '<a href="' . ui_e($url($pages)) . '">' . $pages . '</a>';
        $out .= '<a class="' . ($page >= $pages ? 'is-disabled' : '') . '" href="' . ui_e($url(min($pages, $page + 1))) . '"'
              . ($page >= $pages ? ' aria-disabled="true"' : '') . '>' . ($rtl ? 'التالي' : 'Next') . '</a>';
        return $out . '</nav>';
    }
}

if (!function_exists('ui_stat')) {
    /** One KPI tile. $value must be a real, already-computed number. */
    function ui_stat(string $label, $value, string $variant = 'teal', string $icon = ''): string {
        $variant = in_array($variant, ['teal', 'navy', 'gold', 'success'], true) ? $variant : 'teal';
        $html = '<div class="stat-tile stat-tile-' . $variant . '">';
        if ($icon !== '') $html .= '<span class="stat-tile-icon" aria-hidden="true">' . ui_icon($icon) . '</span>';
        $html .= '<div><div class="num">' . ui_e($value) . '</div><div class="label">' . ui_e($label) . '</div></div></div>';
        return $html;
    }
}

if (!function_exists('ui_money')) {
    /**
     * Currency display. Kept as a thin wrapper over money() so older call sites
     * keep working while the currency and precision come from site settings.
     */
    function ui_money($amount, ?string $currency = null, ?int $decimals = null): string {
        return money($amount, $currency, $decimals);
    }
}

if (!function_exists('ui_date')) {
    /** Locale-aware date display (never changes stored values). */
    function ui_date(?string $value, bool $withTime = false): string {
        if (!$value) return '—';
        $ts = strtotime($value);
        if ($ts === false) return '—';
        $format = is_rtl() ? 'Y/m/d' : 'Y-m-d';
        if ($withTime) $format .= ' H:i';
        return '<span class="num-ltr">' . ui_e(date($format, $ts)) . '</span>';
    }
}
