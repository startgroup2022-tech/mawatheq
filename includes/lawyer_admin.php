<?php
/**
 * التحقق من بيانات المحامي في لوحة التحكم — مصدر واحد لإنشاء الحساب وتعديله.
 *
 * The admin console creates and edits lawyers through the same fields, so the rules
 * live here rather than being restated in each handler. A value that passes here is
 * safe to store; a value that fails returns a specific, translated message so the
 * form can show the operator what to fix without echoing the input back as HTML.
 *
 * The rules, and why each exists:
 *
 *   full_name        required, <= 150 chars (the column width) — a long Arabic name
 *                    must be accepted, not truncated silently.
 *   email            valid, <= 150, and unique across BOTH `users` and `lawyers`,
 *                    because one address may hold only one account on the platform.
 *   password         required on create, 8–72 bytes (bcrypt's own ceiling), hashed
 *                    with PASSWORD_BCRYPT and never returned in any form.
 *   specialization   must be one of the platform's controlled list, not free text.
 *   country          fixed to Saudi Arabia (the platform onboards Saudi lawyers only);
 *                    any other posted code or jurisdiction name is ignored, never stored.
 *   city             required, and must belong to Saudi Arabia.
 *   phone            validated by country dialling pattern (includes/phone.php).
 *   years_experience normalised to a whole non-negative number ("20+" → 20).
 *   hourly_rate      non-negative, rounded to the currency's precision, or null.
 *
 * `$excludeId` lets the edit path ignore the row being edited when checking email
 * uniqueness — otherwise saving an unchanged email would collide with itself.
 */
require_once __DIR__ . '/phone.php';
require_once __DIR__ . '/specialization_field.php';
require_once __DIR__ . '/cities.php';
require_once __DIR__ . '/lawyer_experience.php';
require_once __DIR__ . '/currency.php';

/** Collect the lawyer fields from a POST, trimmed and typed. */
function lawyer_admin_collect(array $post): array {
    $str = function (string $key, string $default = '') use ($post): string {
        $v = $post[$key] ?? $default;
        return is_scalar($v) ? trim((string)$v) : $default;
    };
    return [
        'full_name'        => $str('full_name'),
        'email'            => strtolower($str('email')),
        'phone_dial'       => $str('phone_dial', '+966'),
        'phone_local'      => $str('phone_local'),
        'specialization'   => $str('specialization'),
        'specializations'  => is_array($post['specializations'] ?? null) ? $post['specializations'] : [],
        'years_experience' => $str('years_experience'),
        'hourly_rate'      => $str('hourly_rate'),
        'jurisdiction'     => $str('jurisdiction', 'السعودية'),
        'country_code'     => $str('country_code'),
        'city'             => $str('city'),
        'bio'              => $str('bio'),
        'status'           => $str('status', 'approved'),
    ];
}

/**
 * Validate a lawyer write. Returns ['ok'=>true, 'data'=>[...]] on success, or
 * ['ok'=>false, 'error'=>string, 'data'=>[...]] with the collected (unsanitised)
 * values so the caller can repopulate the form.
 *
 * @param bool $requirePassword true on create; false on edit (blank = keep current)
 */
function lawyer_admin_validate(PDO $pdo, array $post, ?int $excludeId = null, bool $requirePassword = true): array {
    $d = lawyer_admin_collect($post);

    if ($d['full_name'] === '') {
        return lawyer_admin_fail('يرجى إدخال الاسم الكامل.', $d);
    }
    if (mb_strlen($d['full_name']) > 150) {
        return lawyer_admin_fail('الاسم أطول من المسموح (150 حرفاً كحد أقصى).', $d);
    }
    if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL) || strlen($d['email']) > 150) {
        return lawyer_admin_fail('يرجى إدخال بريد إلكتروني صحيح.', $d);
    }

    // Password: required on create, optional on edit.
    $password = is_scalar($post['password'] ?? null) ? (string)$post['password'] : '';
    if ($requirePassword && $password === '') {
        return lawyer_admin_fail('يرجى إدخال كلمة مرور مؤقتة.', $d);
    }
    if ($password !== '') {
        if (strlen($password) < 8) {
            return lawyer_admin_fail('كلمة المرور يجب أن تكون 8 أحرف على الأقل.', $d);
        }
        if (strlen($password) > 72) {
            return lawyer_admin_fail('كلمة المرور يجب ألا تتجاوز 72 حرفاً.', $d);
        }
    }

    // A lawyer may hold more than one specialization. The multi-select is authoritative
    // when present; a caller that still posts the single field (an older form) is
    // accepted as a one-element list, so both shapes work.
    $specList = specialization_normalize_list($d['specializations']);
    if (!$specList && $d['specialization'] !== '' && in_array($d['specialization'], specialization_options(), true)) {
        $specList = [$d['specialization']];
    }
    if (!$specList) {
        return lawyer_admin_fail('يرجى اختيار تخصص قانوني واحد على الأقل من القائمة.', $d);
    }

    // The platform onboards Saudi lawyers only, so the country is fixed rather than
    // chosen. `lawyer_country_from_input()` resolves to Saudi Arabia regardless of the
    // posted code, so a crafted request cannot file a lawyer elsewhere.
    $country = lawyer_country_from_input($d['country_code'], $d['jurisdiction']);
    if ($d['city'] === '') {
        return lawyer_admin_fail('يرجى اختيار المدينة.', $d);
    }
    if (!city_is_valid($country['code'], $d['city'])) {
        return lawyer_admin_fail('المدينة المختارة لا تنتمي إلى الدولة المحددة.', $d);
    }

    $phone = validate_phone($d['phone_dial'], $d['phone_local']);
    if (!$phone['ok']) {
        return lawyer_admin_fail((string)$phone['error'], $d);
    }

    // Email uniqueness across both account tables.
    try {
        $sql = 'SELECT email FROM users WHERE email = ? UNION SELECT email FROM lawyers WHERE email = ?';
        $params = [$d['email'], $d['email']];
        if ($excludeId !== null) {
            $sql = 'SELECT email FROM users WHERE email = ? UNION SELECT email FROM lawyers WHERE email = ? AND id <> ?';
            $params[] = $excludeId;
        }
        $stmt = $pdo->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        if ($stmt->fetchColumn()) {
            return lawyer_admin_fail('هذا البريد الإلكتروني مسجّل مسبقاً لحساب آخر.', $d);
        }
    } catch (Throwable $e) {
        return lawyer_admin_fail('تعذّر التحقق من البريد الإلكتروني. حاول مرة أخرى.', $d);
    }

    $status = in_array($d['status'], ['pending', 'approved', 'suspended'], true) ? $d['status'] : 'approved';

    return [
        'ok' => true,
        'error' => null,
        'data' => [
            'full_name'        => $d['full_name'],
            'email'            => $d['email'],
            'phone'            => $phone['value'],
            'specialization'   => $specList[0],
            'specializations'  => specialization_list_to_stored($specList),
            'years_experience' => lawyer_experience_normalize($d['years_experience']),
            'hourly_rate'      => lawyer_price_normalize($d['hourly_rate']),
            'jurisdiction'     => $country['jurisdiction'],
            'country_code'     => $country['code'],
            'city'             => $d['city'],
            'bio'              => $d['bio'],
            'status'           => $status,
            'password'         => $password,
        ],
        'old' => $d,
    ];
}

/** Build a validation failure result, keeping the raw values for repopulation. */
function lawyer_admin_fail(string $message, array $collected): array {
    return ['ok' => false, 'error' => $message, 'data' => null, 'old' => $collected];
}
