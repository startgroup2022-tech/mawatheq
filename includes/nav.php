<?php
/**
 * Role-aware navigation model (Phase 4).
 *
 * One definition per panel, grouped and icon-labelled, consumed by both the
 * sidebar and the mobile bottom bar.
 *
 * Every href here resolves to a page that exists and works; the client group for
 * AI is built from the services the current plan actually allows (see
 * includes/ai_services.php), so a locked service is still *shown* — greyed with a
 * lock and routed to the subscription page — instead of disappearing. That is a
 * presentation choice only: the server-side guard in each endpoint is what really
 * enforces the limit.
 */

if (!function_exists('ai_service_nav_items')) {
    /**
     * AI destinations for the client panel, in catalog order, marking the ones the
     * current plan does not allow. Locked entries keep their label (so the capability
     * stays discoverable) but carry `locked` and point at the subscription page.
     */
    function ai_service_nav_items(): array {
        require_once __DIR__ . '/ai_services.php';
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $items = [];
        foreach (ai_service_catalog() as $key => $svc) {
            $allowed = $userId > 0 ? ai_service_allowed($key, $userId) : true;
            $items[$key] = [
                'icon' => $svc['icon'],
                'label' => is_rtl() ? $svc['label_ar'] : $svc['label_en'],
                'href' => $allowed ? $svc['href'] : '/subscription.php',
                'locked' => !$allowed,
            ];
        }
        return $items;
    }
}

if (!function_exists('nav_client')) {
    function nav_client(string $active): array {
        $rtl = is_rtl();
        $groups = [
            [
                'title' => $rtl ? 'الرئيسية' : 'Home',
                'items' => [
                    'dashboard' => ['icon' => 'dashboard', 'label' => $rtl ? 'لوحة التحكم' : 'Dashboard', 'href' => '/dashboard.php'],
                ],
            ],
            [
                'title' => $rtl ? 'خدماتي' : 'My services',
                'items' => [
                    'consultations' => ['icon' => 'calendar', 'label' => $rtl ? 'استشاراتي' : 'My consultations', 'href' => '/consultations.php'],
                    'my-cases'      => ['icon' => 'briefcase', 'label' => $rtl ? 'قضاياي' : 'My cases', 'href' => '/my-cases.php'],
                    'case-tasks'    => ['icon' => 'check', 'label' => $rtl ? 'مهامي' : 'My tasks', 'href' => '/case-tasks.php'],
                    'documents'     => ['icon' => 'file', 'label' => $rtl ? 'مستنداتي' : 'My documents', 'href' => '/documents.php'],
                    'draft-reviews' => ['icon' => 'edit', 'label' => $rtl ? 'مراجعة المسودات' : 'Draft reviews', 'href' => '/draft-reviews.php'],
                ],
            ],
            [
                'title' => $rtl ? 'مركز الذكاء الاصطناعي' : 'AI command center',
                'items' => ai_service_nav_items(),
            ],
            [
                'title' => $rtl ? 'المالية' : 'Billing',
                'items' => [
                    'invoices' => ['icon' => 'receipt', 'label' => $rtl ? 'فواتيري' : 'My invoices', 'href' => '/invoices.php'],
                ],
            ],
            [
                'title' => $rtl ? 'المعرفة' : 'Knowledge',
                'items' => [
                    'laws' => ['icon' => 'book', 'label' => $rtl ? 'مكتبة الأنظمة' : 'Laws library', 'href' => '/laws.php'],
                ],
            ],
            [
                'title' => $rtl ? 'الاشتراك' : 'Subscription',
                'items' => [
                    'subscription' => ['icon' => 'card', 'label' => $rtl ? 'الباقة والاستخدام' : 'Plan & usage', 'href' => '/subscription.php'],
                ],
            ],
            [
                'title' => $rtl ? 'الحساب' : 'Account',
                'items' => [
                    'profile' => ['icon' => 'user', 'label' => $rtl ? 'الملف الشخصي' : 'Profile', 'href' => '/account.php'],
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
                'title' => $rtl ? 'الرئيسية' : 'Home',
                'items' => [
                    'dashboard' => ['icon' => 'dashboard', 'label' => $rtl ? 'لوحة التحكم' : 'Dashboard', 'href' => '/lawyer/dashboard.php'],
                ],
            ],
            [
                'title' => $rtl ? 'العمل القانوني' : 'Legal work',
                'items' => [
                    'cases'         => ['icon' => 'briefcase', 'label' => $rtl ? 'القضايا' : 'Cases', 'href' => '/lawyer/cases.php'],
                    'consultations' => ['icon' => 'calendar', 'label' => $rtl ? 'الاستشارات' : 'Consultations', 'href' => '/lawyer/consultations.php'],
                    'calendar'      => ['icon' => 'clock', 'label' => $rtl ? 'التقويم والجلسات' : 'Calendar & sessions', 'href' => '/lawyer/calendar.php'],
                ],
            ],
            [
                'title' => $rtl ? 'الصياغة والذكاء الاصطناعي' : 'Drafting & AI',
                'items' => [
                    'drafting'     => ['icon' => 'edit', 'label' => $rtl ? 'الصياغة الذكية' : 'AI drafting', 'href' => '/lawyer/drafting.php'],
                    'availability' => ['icon' => 'clock', 'label' => $rtl ? 'أوقات الدوام' : 'Availability', 'href' => '/lawyer/availability.php'],
                ],
            ],
            [
                'title' => $rtl ? 'المالية' : 'Billing',
                'items' => [
                    'invoices' => ['icon' => 'receipt', 'label' => $rtl ? 'الفواتير والمدفوعات' : 'Invoices & payments', 'href' => '/lawyer/invoices.php'],
                ],
            ],
        ];
        // firm.php is reachable by every lawyer — a solo lawyer uses it to create a
        // firm, a member to manage one — so it is never hidden. Only the review queue
        // is firm-member-only (review-queue.php returns 403 for a solo lawyer), and
        // that link is shown only when it actually leads somewhere.
        $firmItems = [
            'firm' => ['icon' => 'landmark', 'label' => $isFirm ? ($rtl ? 'المكتب وقاعدة المعرفة' : 'Firm & knowledge base') : ($rtl ? 'إنشاء مكتب قانوني' : 'Create a law firm'), 'href' => '/lawyer/firm.php'],
        ];
        if ($isFirm) {
            $firmItems['firm-site'] = ['icon' => 'external', 'label' => $rtl ? 'موقع المكتب الإلكتروني' : 'Firm website', 'href' => '/lawyer/firm-site.php'];
            $firmItems['reviews'] = ['icon' => 'check-circle', 'label' => $rtl ? 'مراجعة المسودات' : 'Draft reviews', 'href' => '/lawyer/review-queue.php'];
        }
        $groups[] = [
            'title' => $rtl ? 'المكتب القانوني' : 'Law firm',
            'items' => $firmItems,
        ];
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
        $primary = ['dashboard', 'my-cases', 'cases', 'consultations', 'invoices', 'ai-assistant', 'documents', 'profile'];
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
                $locked = !empty($item['locked']);
                $cls = trim(($isActive ? 'active ' : '') . ($locked ? 'is-locked' : ''));
                $html .= '<a href="' . ui_e($item['href']) . '" class="' . $cls . '"'
                       . ($isActive ? ' aria-current="page"' : '')
                       . ($locked ? ' title="' . ui_e(is_rtl() ? 'غير متاح في باقتك — اعرض الباقات' : 'Not in your plan — view plans') . '"' : '') . '>'
                       . '<span class="nav-ico">' . ui_icon($item['icon']) . '</span>'
                       . '<span>' . ui_e($item['label']) . '</span>'
                       . ($locked ? '<span class="nav-lock" aria-hidden="true">' . ui_icon('lock') . '</span>' : '')
                       . '</a>';
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
