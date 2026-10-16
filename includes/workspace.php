<?php
/**
 * Workspace service model (Phase 4 dashboard UX).
 *
 * One place that answers "which services does this panel expose, and what is the
 * real access state of each one?" — consumed by both dashboards so the two panels
 * cannot drift apart, and so a service is described once instead of being repeated
 * in every page that lists it.
 *
 * AI services are NOT redefined here: `ws_ai_service_cards()` reads the catalog in
 * includes/ai_services.php and the live usage from `ai_plan_usage()`, which is the
 * same source the server-side guard uses. A card's state is therefore a faithful
 * preview of what the endpoint will do — never an independent claim.
 *
 * Nothing here decides authorization. Every state is derived from a query result or
 * from the existing gate helpers; the enforcing code stays in the endpoints.
 */

require_once __DIR__ . '/ai_services.php';

if (!function_exists('ws_state_meta')) {
    /**
     * Presentation metadata for an access state. Keys are the only states the UI
     * understands, so a typo cannot silently render a service as available.
     *
     * @return array{label:string,tone:string,icon:string}
     */
    function ws_state_meta(string $state): array {
        $rtl = is_rtl();
        $map = [
            'available' => ['label' => $rtl ? 'متاحة' : 'Available', 'tone' => 'success', 'icon' => 'check-circle'],
            'unlimited' => ['label' => $rtl ? 'غير محدود' : 'Unlimited', 'tone' => 'success', 'icon' => 'check-circle'],
            'low' => ['label' => $rtl ? 'قاربت الحد' : 'Near limit', 'tone' => 'warning', 'icon' => 'alert'],
            'limit_reached' => ['label' => $rtl ? 'وصلت إلى الحد' : 'Limit reached', 'tone' => 'danger', 'icon' => 'alert'],
            'locked' => ['label' => $rtl ? 'غير متاحة في باقتك' : 'Not in your plan', 'tone' => 'muted', 'icon' => 'lock'],
            'firm_only' => ['label' => $rtl ? 'لأعضاء المكتب' : 'Firm members', 'tone' => 'muted', 'icon' => 'lock'],
        ];
        return $map[$state] ?? $map['available'];
    }
}

if (!function_exists('ws_meter_state')) {
    /**
     * Derive the state of a metered service from its live usage. Thresholds match
     * the meter fill colours in dashboard.css (80% warns, 100% blocks).
     *
     * @param int|null $used  null when the gate is unlimited
     * @param int|null $limit null means unlimited
     */
    function ws_meter_state(?int $used, ?int $limit): string {
        if ($limit === null || $limit <= 0) return 'unlimited';
        if ($used === null) return 'available';
        if ($used >= $limit) return 'limit_reached';
        if ($used >= (int)ceil($limit * 0.8)) return 'low';
        return 'available';
    }
}

if (!function_exists('ws_ai_service_cards')) {
    /**
     * Every AI service from the shared catalog, with its real access state and live
     * usage. `action_href` never points at a page the plan forbids: a blocked
     * service routes to the plan/contact surface instead of a dead end.
     *
     * @return array<int,array<string,mixed>>
     */
    function ws_ai_service_cards(PDO $pdo, int $userId): array {
        $rtl = is_rtl();
        $usage = ai_plan_usage($pdo, $userId);
        $cards = [];

        foreach (ai_service_catalog() as $key => $svc) {
            $gate = ai_service_gate($key);
            $used = null; $limit = null; $percent = null;

            if ($gate !== null) {
                $g = $usage[$gate];
                $used = (int)$g['used'];
                $limit = $g['limit'] === null ? null : (int)$g['limit'];
                $percent = ai_usage_percent($g);
                $state = ws_meter_state($used, $limit);
            } else {
                $state = 'available';
            }

            if ($state === 'limit_reached') {
                $actionLabel = $rtl ? 'تواصل لترقية الباقة' : 'Contact to upgrade';
                $actionHref = '/contact.php?topic=plan';
            } elseif ($state === 'locked') {
                $actionLabel = $rtl ? 'اطلب تفعيل الباقة' : 'Request a plan';
                $actionHref = '/contact.php?topic=plan';
            } else {
                $actionLabel = $rtl ? 'ابدأ الآن' : 'Start now';
                $actionHref = $svc['href'];
            }

            $cards[] = [
                'key' => $key,
                'icon' => $svc['icon'],
                'title' => $rtl ? $svc['label_ar'] : $svc['label_en'],
                'desc' => $rtl ? $svc['desc_ar'] : $svc['desc_en'],
                'href' => $svc['href'],
                'state' => $state,
                'used' => $used,
                'limit' => $limit,
                'percent' => $percent,
                'action_label' => $actionLabel,
                'action_href' => $actionHref,
            ];
        }
        return $cards;
    }
}

if (!function_exists('ws_client_service_groups')) {
    /**
     * The client portal's services, grouped as the dashboard presents them. These are
     * the pages that exist and are reachable for a signed-in client; none of them
     * carries a plan gate today, so they report `available`.
     *
     * @return array<int,array{title:string,items:array<int,array<string,mixed>>}>
     */
    function ws_client_service_groups(): array {
        $rtl = is_rtl();
        return [
            [
                'title' => $rtl ? 'الخدمات القانونية' : 'Legal services',
                'items' => [
                    ['key' => 'consultations', 'icon' => 'calendar', 'title' => $rtl ? 'الاستشارة القانونية' : 'Legal consultation',
                     'desc' => $rtl ? 'احجز استشارة وتابع مواعيدك مع المحامين.' : 'Book a consultation and follow your appointments with lawyers.',
                     'href' => '/consultations.php', 'state' => 'available',
                     'action_label' => $rtl ? 'فتح الخدمة' : 'Open', 'action_href' => '/consultations.php'],
                    ['key' => 'my-cases', 'icon' => 'briefcase', 'title' => $rtl ? 'قضاياي' : 'My cases',
                     'desc' => $rtl ? 'تابع القضايا المرتبطة بحسابك.' : 'Track the cases linked to your account.',
                     'href' => '/my-cases.php', 'state' => 'available',
                     'action_label' => $rtl ? 'عرض القضايا' : 'View cases', 'action_href' => '/my-cases.php'],
                    ['key' => 'case-tasks', 'icon' => 'check', 'title' => $rtl ? 'مهامي' : 'My tasks',
                     'desc' => $rtl ? 'المهام التي يشاركها محاموك معك.' : 'Tasks your lawyers have shared with you.',
                     'href' => '/case-tasks.php', 'state' => 'available',
                     'action_label' => $rtl ? 'عرض المهام' : 'View tasks', 'action_href' => '/case-tasks.php'],
                    ['key' => 'documents', 'icon' => 'file', 'title' => $rtl ? 'مستنداتي' : 'My documents',
                     'desc' => $rtl ? 'الوصول إلى مستنداتك القانونية وتحليلاتها.' : 'Access your legal documents and their analyses.',
                     'href' => '/documents.php', 'state' => 'available',
                     'action_label' => $rtl ? 'عرض المستندات' : 'View documents', 'action_href' => '/documents.php'],
                    ['key' => 'draft-reviews', 'icon' => 'edit', 'title' => $rtl ? 'مراجعة المسودات' : 'Draft reviews',
                     'desc' => $rtl ? 'راجع المسودات المرسلة إليك واعتمدها.' : 'Review and approve drafts sent to you.',
                     'href' => '/draft-reviews.php', 'state' => 'available',
                     'action_label' => $rtl ? 'فتح المراجعة' : 'Open review', 'action_href' => '/draft-reviews.php'],
                ],
            ],
            [
                'title' => $rtl ? 'المالية' : 'Billing',
                'items' => [
                    ['key' => 'invoices', 'icon' => 'receipt', 'title' => $rtl ? 'فواتيري' : 'My invoices',
                     'desc' => $rtl ? 'اعرض فواتيرك وسجّل مدفوعاتك.' : 'View your invoices and settle payments.',
                     'href' => '/invoices.php', 'state' => 'available',
                     'action_label' => $rtl ? 'عرض الفواتير' : 'View invoices', 'action_href' => '/invoices.php'],
                ],
            ],
            [
                'title' => $rtl ? 'المعرفة' : 'Knowledge',
                'items' => [
                    ['key' => 'laws', 'icon' => 'book', 'title' => $rtl ? 'مكتبة الأنظمة' : 'Laws library',
                     'desc' => $rtl ? 'ابحث في الأنظمة والمواد القانونية.' : 'Search statutes and legal articles.',
                     'href' => '/laws.php', 'state' => 'available',
                     'action_label' => $rtl ? 'فتح المكتبة' : 'Open library', 'action_href' => '/laws.php'],
                ],
            ],
        ];
    }
}

if (!function_exists('ws_lawyer_service_groups')) {
    /**
     * The lawyer workspace's services. Firm-only destinations are reported as
     * `firm_only` for a solo lawyer and carry no link — the same rule the sidebar
     * follows, so the dashboard never offers a route that answers 403.
     *
     * @return array<int,array{title:string,items:array<int,array<string,mixed>>}>
     */
    function ws_lawyer_service_groups(bool $isFirm): array {
        $rtl = is_rtl();
        $groups = [
            [
                'title' => $rtl ? 'العمل القانوني' : 'Legal work',
                'items' => [
                    ['key' => 'cases', 'icon' => 'briefcase', 'title' => $rtl ? 'القضايا' : 'Cases',
                     'desc' => $rtl ? 'أنشئ القضايا وتابع ملفاتها وإجراءاتها.' : 'Open cases and follow their files and actions.',
                     'href' => '/lawyer/cases.php', 'state' => 'available',
                     'action_label' => $rtl ? 'إدارة القضايا' : 'Manage cases', 'action_href' => '/lawyer/cases.php'],
                    ['key' => 'consultations', 'icon' => 'calendar', 'title' => $rtl ? 'الاستشارات' : 'Consultations',
                     'desc' => $rtl ? 'أكّد الاستشارات وأدر مواعيد العملاء.' : 'Confirm consultations and manage client appointments.',
                     'href' => '/lawyer/consultations.php', 'state' => 'available',
                     'action_label' => $rtl ? 'إدارة الاستشارات' : 'Manage', 'action_href' => '/lawyer/consultations.php'],
                    ['key' => 'calendar', 'icon' => 'clock', 'title' => $rtl ? 'التقويم والجلسات' : 'Calendar & sessions',
                     'desc' => $rtl ? 'تابع الجلسات والمواعيد النهائية.' : 'Track court sessions and deadlines.',
                     'href' => '/lawyer/calendar.php', 'state' => 'available',
                     'action_label' => $rtl ? 'فتح التقويم' : 'Open calendar', 'action_href' => '/lawyer/calendar.php'],
                ],
            ],
            [
                'title' => $rtl ? 'الصياغة والمستندات' : 'Drafting & documents',
                'items' => [
                    ['key' => 'drafting', 'icon' => 'edit', 'title' => $rtl ? 'الصياغة الذكية' : 'AI drafting',
                     'desc' => $rtl ? 'ولّد مسودات العقود والمذكرات بالذكاء الاصطناعي.' : 'Generate contract and memo drafts with AI.',
                     'href' => '/lawyer/drafting.php', 'state' => 'available',
                     'action_label' => $rtl ? 'ابدأ الصياغة' : 'Start drafting', 'action_href' => '/lawyer/drafting.php'],
                    ['key' => 'availability', 'icon' => 'clock', 'title' => $rtl ? 'أوقات الدوام' : 'Availability',
                     'desc' => $rtl ? 'حدّد أوقاتك المتاحة للحجز.' : 'Set the hours clients can book.',
                     'href' => '/lawyer/availability.php', 'state' => 'available',
                     'action_label' => $rtl ? 'ضبط الأوقات' : 'Set hours', 'action_href' => '/lawyer/availability.php'],
                ],
            ],
            [
                'title' => $rtl ? 'المالية' : 'Billing',
                'items' => [
                    ['key' => 'invoices', 'icon' => 'receipt', 'title' => $rtl ? 'الفواتير والمدفوعات' : 'Invoices & payments',
                     'desc' => $rtl ? 'أنشئ الفواتير وتابع التحصيل.' : 'Create invoices and follow collections.',
                     'href' => '/lawyer/invoices.php', 'state' => 'available',
                     'action_label' => $rtl ? 'إدارة الفواتير' : 'Manage', 'action_href' => '/lawyer/invoices.php'],
                ],
            ],
        ];

        if ($isFirm) {
            $groups[] = [
                'title' => $rtl ? 'المكتب القانوني' : 'Law firm',
                'items' => [
                    ['key' => 'reviews', 'icon' => 'check-circle', 'title' => $rtl ? 'مراجعة المسودات' : 'Draft reviews',
                     'desc' => $rtl ? 'راجع مسودات أعضاء المكتب واعتمدها.' : 'Review and approve your team’s drafts.',
                     'href' => '/lawyer/review-queue.php', 'state' => 'available',
                     'action_label' => $rtl ? 'فتح المراجعة' : 'Open queue', 'action_href' => '/lawyer/review-queue.php'],
                    ['key' => 'firm', 'icon' => 'landmark', 'title' => $rtl ? 'المكتب وقاعدة المعرفة' : 'Firm & knowledge base',
                     'desc' => $rtl ? 'أدر بيانات المكتب وقوالبه ومصادره.' : 'Manage firm details, templates and sources.',
                     'href' => '/lawyer/firm.php', 'state' => 'available',
                     'action_label' => $rtl ? 'إدارة المكتب' : 'Manage firm', 'action_href' => '/lawyer/firm.php'],
                ],
            ];
        }

        return $groups;
    }
}

if (!function_exists('ws_render_service_card')) {
    /** One service card. Blocked services are rendered without a link. */
    function ws_render_service_card(array $card): string {
        $rtl = is_rtl();
        $state = $card['state'];
        $meta = ws_state_meta($state);
        $blocked = in_array($state, ['limit_reached', 'locked', 'firm_only'], true);

        $cls = 'service-card state-' . $state;
        $tag = $blocked ? 'div' : 'a';
        $attr = $blocked ? '' : ' href="' . ui_e($card['href']) . '"';

        $html = '<' . $tag . ' class="' . $cls . '"' . $attr . '>';
        $html .= '<span class="sc-top">'
               . '<span class="sc-ico">' . ui_icon($card['icon']) . '</span>'
               . '<span class="sc-title">' . ui_e($card['title']) . '</span>'
               . '<span class="sc-state tone-' . ui_e($meta['tone']) . '">' . ui_icon($meta['icon']) . ui_e($meta['label']) . '</span>'
               . '</span>';
        $html .= '<span class="sc-desc">' . ui_e($card['desc']) . '</span>';

        if ($state !== 'unlimited' && ($card['limit'] ?? null) !== null && ($card['used'] ?? null) !== null) {
            $pct = (int)($card['percent'] ?? 0);
            $fill = $pct >= 100 ? 'is-full' : ($pct >= 80 ? 'is-warn' : '');
            $html .= '<span class="sc-meter">'
                   . '<span class="meter-head"><span class="meter-label">' . ($rtl ? 'الاستخدام هذا الشهر' : 'This month’s usage') . '</span>'
                   . '<span class="meter-value"><span class="num-ltr">' . (int)$card['used'] . '</span> / <span class="num-ltr">' . (int)$card['limit'] . '</span></span></span>'
                   . '<span class="meter-track"><span class="meter-fill ' . $fill . '" style="width:' . $pct . '%"></span></span>'
                   . '</span>';
        } elseif ($state === 'unlimited') {
            $html .= '<span class="sc-meter"><span class="meter-note">' . ($rtl ? 'لا حد شهري على هذه الخدمة في باقتك.' : 'No monthly cap on this service in your plan.') . '</span></span>';
        }

        $html .= '<span class="sc-foot">';
        if ($blocked) {
            $html .= '<span class="service-lock-badge">' . ui_icon($meta['icon']) . ui_e($meta['label']) . '</span>';
            if (!empty($card['action_href'])) {
                $html .= '<a class="btn btn-outline btn-sm" href="' . ui_e($card['action_href']) . '">' . ui_e($card['action_label']) . '</a>';
            }
        } else {
            $html .= '<span class="btn btn-primary btn-sm">' . ui_e($card['action_label']) . '</span>';
        }
        $html .= '</span></' . $tag . '>';

        return $html;
    }
}

if (!function_exists('ws_render_service_groups')) {
    /** Render grouped service cards with a section heading per group. */
    function ws_render_service_groups(array $groups): string {
        $html = '';
        foreach ($groups as $group) {
            $html .= '<div class="ws-group">';
            $html .= '<h3 class="ws-group-title">' . ui_e($group['title']) . '</h3>';
            $html .= '<div class="service-grid">';
            foreach ($group['items'] as $card) {
                $html .= ws_render_service_card($card);
            }
            $html .= '</div></div>';
        }
        return $html;
    }
}

if (!function_exists('ws_render_feature_checklist')) {
    /**
     * Compact "what can I use" list: one row per service with a status chip. This is
     * the at-a-glance answer, so it stays a list rather than another grid of cards.
     *
     * @param array<int,array<string,mixed>> $cards
     */
    function ws_render_feature_checklist(array $cards): string {
        $html = '<ul class="feature-list">';
        foreach ($cards as $card) {
            $meta = ws_state_meta($card['state']);
            $blocked = in_array($card['state'], ['limit_reached', 'locked', 'firm_only'], true);
            $html .= '<li class="feature-row is-' . ui_e($meta['tone']) . ($blocked ? ' is-blocked' : '') . '">';
            $html .= '<span class="feature-ico">' . ui_icon($card['icon']) . '</span>';
            $html .= '<span class="feature-name">' . ui_e($card['title']) . '</span>';
            $html .= '<span class="feature-state tone-' . ui_e($meta['tone']) . '">' . ui_icon($meta['icon']) . ui_e($meta['label']) . '</span>';
            $html .= '</li>';
        }
        return $html . '</ul>';
    }
}

if (!function_exists('ws_flatten_cards')) {
    /** Flatten grouped service definitions into a single card list. */
    function ws_flatten_cards(array $groups): array {
        $out = [];
        foreach ($groups as $group) {
            foreach ($group['items'] as $card) { $out[] = $card; }
        }
        return $out;
    }
}
