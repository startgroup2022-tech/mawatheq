<?php
/**
 * Role-aware navigation model (Phase 4).
 *
 * One definition per panel, grouped and icon-labelled, consumed by both the
 * sidebar and the mobile bottom bar. Every href here already existed before
 * Phase 4 — this file only changes how the links are presented, so no page
 * becomes reachable (or unreachable) that was not already.
 */

if (!function_exists('nav_client')) {
    function nav_client(string $active): array {
        $rtl = is_rtl();
        $groups = [
            [
                'title' => $rtl ? 'المساعد القانوني' : 'Legal AI',
                'items' => [
                    'ai-assistant' => ['icon' => 'spark', 'label' => t('feat1_title'), 'href' => '/ai-assistant.php'],
                    'documents'    => ['icon' => 'file', 'label' => t('feat2_title'), 'href' => '/documents.php'],
                    'contract-comparison' => ['icon' => 'scale', 'label' => $rtl ? 'مقارنة العقود' : 'Contract comparison', 'href' => '/contract-comparison.php'],
                    'draft-reviews' => ['icon' => 'edit', 'label' => $rtl ? 'مراجعة المسودات' : 'Draft reviews', 'href' => '/draft-reviews.php'],
                ],
            ],
            [
                'title' => $rtl ? 'أعمالي' : 'My work',
                'items' => [
                    'my-cases'      => ['icon' => 'briefcase', 'label' => $rtl ? 'قضاياي' : 'My cases', 'href' => '/my-cases.php'],
                    'case-tasks'    => ['icon' => 'check', 'label' => $rtl ? 'مهامي' : 'My tasks', 'href' => '/case-tasks.php'],
                    'consultations' => ['icon' => 'calendar', 'label' => t('feat4_title'), 'href' => '/consultations.php'],
                ],
            ],
            [
                'title' => $rtl ? 'المالية والمعرفة' : 'Billing & knowledge',
                'items' => [
                    'invoices' => ['icon' => 'receipt', 'label' => $rtl ? 'الفواتير' : 'Invoices', 'href' => '/invoices.php'],
                    'laws'     => ['icon' => 'book', 'label' => $rtl ? 'مكتبة الأنظمة' : 'Laws library', 'href' => '/laws.php'],
                ],
            ],
            [
                'title' => $rtl ? 'الحساب' : 'Account',
                'items' => [
                    'profile' => ['icon' => 'user', 'label' => $rtl ? 'حسابي' : 'My account', 'href' => '/account.php'],
                ],
            ],
        ];
        return ['groups' => $groups, 'active' => $active];
    }
}

if (!function_exists('nav_lawyer')) {
    function nav_lawyer(string $active): array {
        $rtl = is_rtl();
        $isFirm = !empty($_SESSION['tenant_is_firm']);
        $groups = [
            [
                'title' => $rtl ? 'العمل' : 'Work',
                'items' => [
                    'dashboard' => ['icon' => 'dashboard', 'label' => $rtl ? 'نظرة عامة' : 'Overview', 'href' => '/lawyer/dashboard.php'],
                    'cases'     => ['icon' => 'briefcase', 'label' => $rtl ? 'القضايا' : 'Cases', 'href' => '/lawyer/cases.php'],
                    'consultations' => ['icon' => 'calendar', 'label' => $rtl ? 'الاستشارات' : 'Consultations', 'href' => '/lawyer/consultations.php'],
                    'calendar'  => ['icon' => 'clock', 'label' => $rtl ? 'التقويم القانوني' : 'Legal calendar', 'href' => '/lawyer/calendar.php'],
                    'availability' => ['icon' => 'clock', 'label' => $rtl ? 'أوقات الدوام' : 'Availability', 'href' => '/lawyer/availability.php'],
                ],
            ],
            [
                'title' => $rtl ? 'الصياغة والمراجعة' : 'Drafting & review',
                'items' => [
                    'drafting' => ['icon' => 'edit', 'label' => $rtl ? 'الصياغة الذكية' : 'AI drafting', 'href' => '/lawyer/drafting.php'],
                    'reviews'  => ['icon' => 'check-circle', 'label' => $rtl ? 'مراجعة المسودات' : 'Draft reviews', 'href' => '/lawyer/review-queue.php'],
                ],
            ],
            [
                'title' => $rtl ? 'المالية' : 'Billing',
                'items' => [
                    'invoices' => ['icon' => 'receipt', 'label' => $rtl ? 'الفواتير' : 'Invoices', 'href' => '/lawyer/invoices.php'],
                ],
            ],
        ];
        // Firm workspace is only shown when the lawyer actually has an active
        // firm membership, so the link is never a dead end.
        if ($isFirm) {
            $groups[] = [
                'title' => $rtl ? 'المكتب' : 'Firm',
                'items' => [
                    'firm' => ['icon' => 'landmark', 'label' => $rtl ? 'المكتب وقاعدة المعرفة' : 'Firm & knowledge base', 'href' => '/lawyer/firm.php'],
                ],
            ];
        }
        $groups[] = [
            'title' => $rtl ? 'الحساب' : 'Account',
            'items' => [
                'profile' => ['icon' => 'user', 'label' => $rtl ? 'الملف الشخصي' : 'Profile', 'href' => '/lawyer/profile.php'],
            ],
        ];
        return ['groups' => $groups, 'active' => $active];
    }
}

if (!function_exists('nav_bottom_items')) {
    /**
     * Up to five destinations for the mobile bottom bar. The first entries of
     * each panel are the primary ones; the rest stay reachable through the
     * sidebar drawer, so nothing is lost.
     */
    function nav_bottom_items(array $nav): array {
        $flat = [];
        foreach ($nav['groups'] as $g) {
            foreach ($g['items'] as $key => $item) {
                $item['key'] = $key;
                $flat[] = $item;
            }
        }
        $primary = ['ai-assistant', 'dashboard', 'my-cases', 'cases', 'consultations', 'invoices', 'profile', 'documents'];
        $picked = [];
        foreach ($primary as $want) {
            foreach ($flat as $item) {
                if ($item['key'] === $want && !in_array($item, $picked, true)) { $picked[] = $item; break; }
            }
            if (count($picked) === 4) break;
        }
        foreach ($flat as $item) { if (count($picked) >= 4) break; if (!in_array($item, $picked, true)) $picked[] = $item; }
        // Final slot is always the account/profile entry so settings are one tap away.
        foreach ($flat as $item) {
            if ($item['key'] === 'profile') { $picked[] = $item; break; }
        }
        return array_slice($picked, 0, 5);
    }
}

if (!function_exists('nav_render_sidebar')) {
    function nav_render_sidebar(array $nav): string {
        $html = '<nav class="app-nav" aria-label="' . (is_rtl() ? 'القائمة الرئيسية' : 'Main navigation') . '">';
        foreach ($nav['groups'] as $group) {
            $html .= '<div class="app-nav-group"><div class="app-nav-title">' . ui_e($group['title']) . '</div>';
            foreach ($group['items'] as $key => $item) {
                $isActive = $nav['active'] === $key;
                $html .= '<a href="' . ui_e($item['href']) . '" class="' . ($isActive ? 'active' : '') . '"'
                       . ($isActive ? ' aria-current="page"' : '') . '>'
                       . '<span class="nav-ico">' . ui_icon($item['icon']) . '</span>'
                       . '<span>' . ui_e($item['label']) . '</span></a>';
            }
            $html .= '</div>';
        }
        return $html . '</nav>';
    }
}

if (!function_exists('nav_render_bottom')) {
    function nav_render_bottom(array $nav): string {
        $items = nav_bottom_items($nav);
        if (!$items) return '';
        $html = '<nav class="app-bottomnav" aria-label="' . (is_rtl() ? 'تنقل سريع' : 'Quick navigation') . '"><ul>';
        foreach ($items as $item) {
            $isActive = $nav['active'] === ($item['key'] ?? '');
            $html .= '<li><a href="' . ui_e($item['href']) . '" class="' . ($isActive ? 'active' : '') . '"'
                   . ($isActive ? ' aria-current="page"' : '') . '>'
                   . ui_icon($item['icon'])
                   . '<span>' . ui_e($item['label']) . '</span></a></li>';
        }
        return $html . '</ul></nav>';
    }
}
