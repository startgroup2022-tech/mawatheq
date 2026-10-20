<?php
/**
 * رحلة العميل لحجز استشارة — الطبقة المشتركة بين الموقع وتطبيق الجوال.
 *
 * The customer picks a legal specialty, then an issue type, then a lawyer. This file
 * is the one place that turns the internal legal classification into that journey, so
 * the website and the mobile app cannot present it differently.
 *
 * THE CONFIDENTIALITY RULE THIS FILE EXISTS TO ENFORCE
 *
 *   "Najiz" and the classification's technical vocabulary are an INTERNAL reference.
 *   Nothing in this file that reaches a customer may contain the word Najiz, the
 *   name of the classification, or taxonomy jargon such as "lawsuit type" or
 *   "subcategory". A customer sees a legal specialty and a plain description of
 *   their problem.
 *
 *   The rule is enforced in code, not by convention: the customer-facing renderers
 *   pass every string they emit through legal_journey_customer_text(), which throws
 *   in development and scrubs in production if a forbidden term is present. That
 *   means a future edit that slips the internal name into a card fails loudly rather
 *   than quietly leaking it. The internal names remain available to the lawyer and
 *   admin views, which are internal by design.
 */

require_once __DIR__ . '/najiz_search_lib.php';

/**
 * Terms that must never appear in customer-facing output.
 *
 * Matched case-insensitively as whole substrings. Kept short and specific so it does
 * not fire on legitimate Arabic legal prose (the word "تصنيف" alone is not forbidden;
 * "تصنيف ناجز" and "نوع الدعوى" are).
 */
function legal_journey_forbidden_terms(): array {
    return [
        'ناجز', 'najiz',
        'نوع الدعوى', 'أنواع الدعاوى',
        'التصنيف الرئيسي', 'التصنيف الفرعي', 'تصنيف ناجز',
        'lawsuit type', 'subcategory', 'main category',
    ];
}

/**
 * Guard one customer-facing string.
 *
 * @param string $text  the string about to be shown to a customer
 * @param bool   $strict when true (development/tests) a violation throws; when false
 *                       (production) it is scrubbed and logged, because a leaked
 *                       internal word must never take the customer's page down.
 */
function legal_journey_customer_text(string $text, bool $strict = false): string {
    $lower = mb_strtolower($text, 'UTF-8');
    foreach (legal_journey_forbidden_terms() as $term) {
        if (mb_strpos($lower, mb_strtolower($term, 'UTF-8')) !== false) {
            if ($strict) {
                throw new RuntimeException('customer-facing text contains the internal term "' . $term . '"');
            }
            error_log('legal_journey: scrubbed internal term "' . $term . '" from customer output');
            $text = str_ireplace($term, '', $text);
        }
    }
    return trim($text);
}

/** True when a string is safe to show a customer. Used by the test suite. */
function legal_journey_text_is_customer_safe(string $text): bool {
    $lower = mb_strtolower($text, 'UTF-8');
    foreach (legal_journey_forbidden_terms() as $term) {
        if (mb_strpos($lower, mb_strtolower($term, 'UTF-8')) !== false) return false;
    }
    return true;
}

/**
 * Whether the journey tables are present.
 *
 * A database that never received upgrade_v63.sql must not break the consultation
 * pages: the journey is additive and the pre-existing booking flow keeps working.
 * Every public function here is therefore safe to call against an unmigrated
 * database and returns an empty/neutral result rather than throwing.
 */
function legal_journey_ready(PDO $pdo): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $pdo->query('SELECT 1 FROM najiz_categories LIMIT 1');
        $pdo->query('SELECT 1 FROM lawyer_specialties LIMIT 1');
        $ready = true;
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

/**
 * The legal specialties a customer can choose, as cards.
 *
 * Read from the real classification categories — nothing is invented. Every active
 * specialty is offered, because hiding a practice area would make the directory look
 * incomplete; a specialty that no approved lawyer currently provides is shown with a
 * zero count and, when chosen, leads to a clear alternative rather than an empty
 * screen. Staffed specialties sort first so the common path is the shortest.
 *
 * Each row carries: id, name, description, icon_key, lawyer_count.
 *
 * `description` and `icon_key` come from the customer-facing columns added by
 * upgrade_v63.sql, falling back to a neutral default when they are NULL, so the card
 * always renders something sensible.
 *
 * @param string $q when non-empty, filters by the customer's typed text (Arabic-folded)
 */
function legal_journey_specialties(PDO $pdo, string $q = ''): array {
    if (!legal_journey_ready($pdo)) return [];

    $sql = "SELECT c.id, c.name_ar, c.name_en, c.customer_desc_ar, c.customer_icon_key,
                   (SELECT COUNT(DISTINCT ls.lawyer_id)
                      FROM lawyer_specialties ls
                      JOIN lawyers l ON l.id = ls.lawyer_id
                     WHERE ls.category_id = c.id AND l.status = 'approved') AS lawyer_count
              FROM najiz_categories c
             WHERE c.is_active = 1
             ORDER BY lawyer_count DESC, c.sort_order, c.id";
    $rows = $pdo->query($sql)->fetchAll();

    if ($q !== '') {
        $needle = najiz_normalize_ar($q);
        $rows = array_values(array_filter($rows, function ($r) use ($needle) {
            return mb_strpos(najiz_normalize_ar($r['name_ar']), $needle) !== false
                || mb_strpos(najiz_normalize_ar((string)$r['customer_desc_ar']), $needle) !== false;
        }));
    }

    $out = [];
    foreach ($rows as $r) {
        $desc = $r['customer_desc_ar'] !== null && $r['customer_desc_ar'] !== ''
            ? $r['customer_desc_ar']
            : legal_journey_default_description((string)$r['name_ar']);
        $out[] = [
            'id'            => (int)$r['id'],
            'name'          => legal_journey_customer_text((string)$r['name_ar']),
            'description'   => legal_journey_customer_text($desc),
            'icon_key'      => $r['customer_icon_key'] ?: 'general',
            'lawyer_count'  => (int)$r['lawyer_count'],
        ];
    }
    return $out;
}

/** A neutral description for a category that has no customer wording yet. */
function legal_journey_default_description(string $name): string {
    return is_rtl()
        ? 'اختر هذا التخصص لعرض المحامين المتاحين في ' . $name . '.'
        : 'Choose this specialty to see available lawyers in ' . $name . '.';
}

/**
 * The issue types a customer can choose within a specialty, in plain language.
 *
 * These are the real lawsuit types under the specialty's subcategories. The customer
 * sees the type's own name and definition — real legal descriptions, not taxonomy —
 * and never sees which internal level it came from. Duplicate names (the source lists
 * some types twice) are collapsed to one customer choice, because offering the same
 * sentence twice would confuse rather than inform.
 *
 * @param string $q when non-empty, filters by the customer's typed text
 */
function legal_journey_issue_types(PDO $pdo, int $categoryId, string $q = ''): array {
    if (!legal_journey_ready($pdo) || $categoryId <= 0) return [];

    $stmt = $pdo->prepare(
        "SELECT t.id, t.name_ar, t.definition, t.definition_missing, s.name_ar AS group_name
           FROM najiz_case_types t
           JOIN najiz_subcategories s ON s.id = t.subcategory_id
          WHERE s.category_id = ? AND t.is_active = 1 AND s.is_active = 1
          ORDER BY s.sort_order, s.id, t.sort_order, t.id"
    );
    $stmt->execute([$categoryId]);
    $rows = $stmt->fetchAll();

    if ($q !== '') {
        $needle = najiz_normalize_ar($q);
        $rows = array_values(array_filter($rows, function ($r) use ($needle) {
            return mb_strpos(najiz_normalize_ar($r['name_ar']), $needle) !== false
                || mb_strpos(najiz_normalize_ar((string)$r['definition']), $needle) !== false
                || mb_strpos(najiz_normalize_ar($r['group_name']), $needle) !== false;
        }));
    }

    // Collapse a type whose name repeats within the same specialty: the customer is
    // choosing "what is the problem", and two identical labels are not two problems.
    $seen = [];
    $out = [];
    foreach ($rows as $r) {
        $key = najiz_normalize_ar($r['name_ar']);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $definition = (!$r['definition_missing'] && $r['definition'] !== null && $r['definition'] !== '')
            ? $r['definition']
            : null;
        $out[] = [
            'id'         => (int)$r['id'],
            'name'       => legal_journey_customer_text((string)$r['name_ar']),
            'definition' => $definition !== null ? legal_journey_customer_text($definition) : null,
            'group'      => legal_journey_customer_text((string)$r['group_name']),
        ];
    }
    return $out;
}

/**
 * Approved lawyers who actually provide a specialty, with everything the customer
 * needs to compare them: fee, rating, experience and real availability.
 *
 * A lawyer qualifies only when they are `approved` AND have a lawyer_specialties row
 * for this category. No other lawyer is ever returned, which is what the brief means
 * by "do not show unrelated lawyers as matches".
 *
 * @return array<int,array<string,mixed>>
 */
function legal_journey_lawyers_for_specialty(PDO $pdo, int $categoryId, array $opts = []): array {
    if (!legal_journey_ready($pdo) || $categoryId <= 0) return [];

    $jurisdiction = trim((string)($opts['jurisdiction'] ?? ''));
    $maxFee       = isset($opts['max_fee']) && $opts['max_fee'] !== '' ? (float)$opts['max_fee'] : null;
    $q            = trim((string)($opts['q'] ?? ''));

    $sql = "SELECT l.id, l.full_name, l.specialization, l.bio, l.years_experience,
                   l.hourly_rate, l.rating, l.photo_path, l.jurisdiction
              FROM lawyers l
              JOIN lawyer_specialties ls ON ls.lawyer_id = l.id
             WHERE ls.category_id = ? AND l.status = 'approved'";
    $params = [$categoryId];

    if ($jurisdiction !== '') { $sql .= ' AND l.jurisdiction = ?'; $params[] = $jurisdiction; }
    if ($maxFee !== null)     { $sql .= ' AND (l.hourly_rate IS NULL OR l.hourly_rate <= ?)'; $params[] = $maxFee; }
    if ($q !== '')            { $sql .= ' AND l.full_name LIKE ?'; $params[] = '%' . $q . '%'; }

    $sql .= ' ORDER BY l.rating DESC, l.years_experience DESC, l.id';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $lawyers = $stmt->fetchAll();

    $today = date('Y-m-d');
    foreach ($lawyers as &$lw) {
        $lw['id'] = (int)$lw['id'];
        $lw['hourly_rate'] = $lw['hourly_rate'] !== null ? (float)$lw['hourly_rate'] : null;
        $lw['rating'] = $lw['rating'] !== null ? (float)$lw['rating'] : null;
        $lw['years_experience'] = (int)$lw['years_experience'];
        $lw['availability'] = legal_journey_availability_summary($pdo, $lw['id'], $today);
    }
    unset($lw);
    return $lawyers;
}

/**
 * A short, real availability summary for a lawyer: whether they have published
 * working hours at all, and the next few open slots across the coming days.
 *
 * Read from lawyer_availability and the same slot calculation the booking form uses,
 * so what the customer compares is exactly what they can book. No slot is invented.
 */
function legal_journey_availability_summary(PDO $pdo, int $lawyerId, ?string $fromDate = null, int $days = 7, int $maxSlots = 3): array {
    require_once __DIR__ . '/availability.php';
    $fromDate = $fromDate ?: date('Y-m-d');
    $hasHours = false;
    try {
        $c = $pdo->prepare('SELECT COUNT(*) FROM lawyer_availability WHERE lawyer_id = ?');
        $c->execute([$lawyerId]);
        $hasHours = (int)$c->fetchColumn() > 0;
    } catch (Throwable $e) {
        $hasHours = false;
    }

    $next = [];
    if ($hasHours) {
        for ($i = 0; $i < $days && count($next) < $maxSlots; $i++) {
            $date = date('Y-m-d', strtotime($fromDate . ' +' . $i . ' days'));
            foreach (get_available_slots($pdo, $lawyerId, $date) as $slot) {
                $next[] = ['date' => $date, 'time' => $slot];
                if (count($next) >= $maxSlots) break;
            }
        }
    }
    return [
        'has_published_hours' => $hasHours,
        'next_slots'          => $next,
        'slot_count'          => count($next),
    ];
}

/**
 * The "I am not sure what my problem is" option.
 *
 * Presented with every issue-type list and always selectable. Choosing it stores no
 * issue type, which is honest — the customer has not classified their problem — and
 * the journey continues to the lawyer list exactly as a specific choice would.
 */
function legal_journey_not_sure_option(): array {
    return [
        'id'   => 0,
        'name' => is_rtl() ? 'لست متأكدًا من نوع المشكلة' : 'I am not sure about the issue type',
    ];
}

/**
 * A clear alternative when no approved lawyer provides a specialty.
 *
 * Returns real, actionable options rather than an empty screen: the specialties that
 * DO have lawyers, so the customer can pick a nearby one, plus the general booking
 * route where they describe the problem in their own words. No unrelated lawyer is
 * ever offered as a match.
 */
function legal_journey_no_match_alternative(PDO $pdo, int $categoryId): array {
    $others = array_values(array_filter(
        legal_journey_specialties($pdo),
        fn($s) => (int)$s['id'] !== $categoryId
    ));
    return [
        'reason'  => is_rtl()
            ? 'لا يوجد حاليًا محامٍ متاح في هذا التخصص.'
            : 'No lawyer is currently available in this specialty.',
        'suggest' => is_rtl()
            ? 'يمكنك اختيار تخصص آخر أو إرسال طلبك وسنرشّح لك محاميًا مناسبًا.'
            : 'You can choose another specialty, or send your request and we will suggest a suitable lawyer.',
        'specialties' => $others,
    ];
}

/**
 * One lawyer's full comparison profile: what the customer needs to decide.
 *
 * Only an approved lawyer is returned. The profile carries the lawyer's own stated
 * specialization text (their own words, not a classification) and real availability.
 */
function legal_journey_lawyer_profile(PDO $pdo, int $lawyerId): ?array {
    try {
        $s = $pdo->prepare(
            "SELECT id, full_name, specialization, bio, years_experience, hourly_rate,
                    rating, photo_path, jurisdiction, qualifications
               FROM lawyers WHERE id = ? AND status = 'approved'"
        );
        $s->execute([$lawyerId]);
        $lw = $s->fetch();
    } catch (Throwable $e) {
        return null;
    }
    if (!$lw) return null;

    $lw['id'] = (int)$lw['id'];
    $lw['hourly_rate'] = $lw['hourly_rate'] !== null ? (float)$lw['hourly_rate'] : null;
    $lw['rating'] = $lw['rating'] !== null ? (float)$lw['rating'] : null;
    $lw['years_experience'] = (int)$lw['years_experience'];
    $lw['availability'] = legal_journey_availability_summary($pdo, $lw['id']);
    $lw['specialties'] = legal_journey_specialties_for_lawyer($pdo, $lw['id']);
    return $lw;
}

/** The customer-visible specialties a lawyer provides (names only, never internal ids). */
function legal_journey_specialties_for_lawyer(PDO $pdo, int $lawyerId): array {
    if (!legal_journey_ready($pdo)) return [];
    try {
        $s = $pdo->prepare(
            'SELECT c.id, c.name_ar FROM lawyer_specialties ls
               JOIN najiz_categories c ON c.id = ls.category_id
              WHERE ls.lawyer_id = ? AND c.is_active = 1
              ORDER BY c.sort_order, c.id'
        );
        $s->execute([$lawyerId]);
        return array_map(fn($r) => [
            'id'   => (int)$r['id'],
            'name' => legal_journey_customer_text((string)$r['name_ar']),
        ], $s->fetchAll());
    } catch (Throwable $e) {
        return [];
    }
}

/** The customer-visible name of one specialty, or null when it does not exist. */
function legal_journey_specialty_name(PDO $pdo, int $categoryId): ?string {
    if (!legal_journey_ready($pdo) || $categoryId <= 0) return null;
    try {
        $s = $pdo->prepare('SELECT name_ar FROM najiz_categories WHERE id = ? AND is_active = 1');
        $s->execute([$categoryId]);
        $name = $s->fetchColumn();
        return $name ? legal_journey_customer_text((string)$name) : null;
    } catch (Throwable $e) {
        return null;
    }
}

/** The customer-visible name of one issue type, or null when it does not exist. */
function legal_journey_issue_type_name(PDO $pdo, int $caseTypeId): ?string {
    if (!legal_journey_ready($pdo) || $caseTypeId <= 0) return null;
    try {
        $s = $pdo->prepare('SELECT name_ar FROM najiz_case_types WHERE id = ? AND is_active = 1');
        $s->execute([$caseTypeId]);
        $name = $s->fetchColumn();
        return $name ? legal_journey_customer_text((string)$name) : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Whether consultations.specialty_category_id exists (upgrade_v63.sql applied).
 *
 * The booking form writes the customer's chosen specialty into that column. A
 * database that has not run v63 must keep working, so the column is written only
 * when it is really there rather than assumed.
 */
function legal_journey_specialty_column_ready(PDO $pdo): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $s = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
                           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='consultations'
                             AND COLUMN_NAME='specialty_category_id'");
        $ready = (int)$s->fetchColumn() > 0;
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

/**
 * Resolve a customer's choice into the consultation fields, validating every id
 * against the real tables so a crafted request cannot store a relationship that
 * does not exist.
 *
 * Returns, or nulls, exactly what may be written to the consultation:
 *   specialty_category_id, najiz_case_type_id
 *
 * A type is accepted only when it truly belongs to the chosen specialty — the
 * customer cannot attach an issue type from a different specialty, and the journey's
 * "not sure" path legitimately leaves both NULL.
 */
function legal_journey_resolve_choice(PDO $pdo, $categoryId, $caseTypeId): array {
    $out = ['specialty_category_id' => null, 'najiz_case_type_id' => null];
    if (!legal_journey_ready($pdo)) return $out;

    $categoryId = (int)$categoryId;
    if ($categoryId > 0) {
        try {
            $s = $pdo->prepare('SELECT id FROM najiz_categories WHERE id = ? AND is_active = 1');
            $s->execute([$categoryId]);
            if ($s->fetchColumn()) $out['specialty_category_id'] = $categoryId;
        } catch (Throwable $e) { /* leave null */ }
    }

    $caseTypeId = (int)$caseTypeId;
    if ($caseTypeId > 0 && $out['specialty_category_id'] !== null) {
        try {
            $s = $pdo->prepare(
                'SELECT t.id FROM najiz_case_types t
                   JOIN najiz_subcategories s ON s.id = t.subcategory_id
                  WHERE t.id = ? AND t.is_active = 1 AND s.category_id = ?'
            );
            $s->execute([$caseTypeId, $out['specialty_category_id']]);
            if ($s->fetchColumn()) $out['najiz_case_type_id'] = $caseTypeId;
        } catch (Throwable $e) { /* leave null */ }
    }
    return $out;
}

/**
 * The internal classification of a consultation, for the lawyer and admin views only.
 *
 * This is the one function allowed to return the taxonomy vocabulary, because its
 * output is never sent to a customer. It returns NULL-safe fields so a consultation
 * booked before this feature (or via "not sure") shows an honest "not classified"
 * rather than a fabricated category.
 */
function legal_journey_internal_classification(PDO $pdo, int $caseTypeId): ?array {
    if ($caseTypeId <= 0) return null;
    try {
        $s = $pdo->prepare(
            'SELECT t.id, t.name_ar, t.definition,
                    s.id AS subcategory_id, s.name_ar AS subcategory_name,
                    c.id AS category_id, c.name_ar AS category_name
               FROM najiz_case_types t
               JOIN najiz_subcategories s ON s.id = t.subcategory_id
               JOIN najiz_categories c ON c.id = s.category_id
              WHERE t.id = ?'
        );
        $s->execute([$caseTypeId]);
        $row = $s->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}
