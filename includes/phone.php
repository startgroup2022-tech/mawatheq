<?php
/**
 * حقل رقم الجوال: رمز الدولة + الرقم، مع تحقق من صحة الصيغة فقط
 * (لا يوجد تحقق عبر رسالة SMS / OTP بناءً على الطلب).
 */

function phone_countries(): array {
    return [
        ['dial' => '+966', 'flag' => '🇸🇦', 'name_ar' => 'السعودية',   'name_en' => 'Saudi Arabia',  'pattern' => '/^5[0-9]{8}$/',       'example' => '5xxxxxxxx'],
        ['dial' => '+973', 'flag' => '🇧🇭', 'name_ar' => 'البحرين',    'name_en' => 'Bahrain',       'pattern' => '/^3[0-9]{7}$/',       'example' => '3xxxxxxx'],
        ['dial' => '+971', 'flag' => '🇦🇪', 'name_ar' => 'الإمارات',   'name_en' => 'UAE',           'pattern' => '/^5[0-9]{8}$/',       'example' => '5xxxxxxxx'],
        ['dial' => '+965', 'flag' => '🇰🇼', 'name_ar' => 'الكويت',     'name_en' => 'Kuwait',        'pattern' => '/^[569][0-9]{7}$/',   'example' => '5xxxxxxx'],
        ['dial' => '+974', 'flag' => '🇶🇦', 'name_ar' => 'قطر',        'name_en' => 'Qatar',         'pattern' => '/^[3567][0-9]{7}$/',  'example' => '3xxxxxxx'],
        ['dial' => '+968', 'flag' => '🇴🇲', 'name_ar' => 'عُمان',      'name_en' => 'Oman',          'pattern' => '/^[79][0-9]{7}$/',    'example' => '9xxxxxxx'],
        ['dial' => '+20',  'flag' => '🇪🇬', 'name_ar' => 'مصر',        'name_en' => 'Egypt',         'pattern' => '/^1[0-9]{9}$/',       'example' => '1xxxxxxxxx'],
        ['dial' => '+962', 'flag' => '🇯🇴', 'name_ar' => 'الأردن',     'name_en' => 'Jordan',        'pattern' => '/^7[0-9]{8}$/',       'example' => '7xxxxxxxx'],
        ['dial' => '+964', 'flag' => '🇮🇶', 'name_ar' => 'العراق',     'name_en' => 'Iraq',          'pattern' => '/^7[0-9]{9}$/',       'example' => '7xxxxxxxxx'],
        ['dial' => '+961', 'flag' => '🇱🇧', 'name_ar' => 'لبنان',      'name_en' => 'Lebanon',       'pattern' => '/^[3789][0-9]{6,7}$/','example' => '7xxxxxxx'],
        ['dial' => 'other','flag' => '🌍', 'name_ar' => 'دولة أخرى',  'name_en' => 'Other country', 'pattern' => '/^[0-9]{6,14}$/',     'example' => '00xxxxxxxxx'],
    ];
}

/**
 * @return array{ok:bool, value:?string, error:?string}
 */
function validate_phone(string $dial, string $rawNumber): array {
    $digits = preg_replace('/[^0-9]/', '', $rawNumber ?? '');

    if ($digits === '') {
        return ['ok' => false, 'value' => null, 'error' => is_rtl() ? 'يرجى إدخال رقم الجوال.' : 'Please enter a phone number.'];
    }

    $match = null;
    foreach (phone_countries() as $c) {
        if ($c['dial'] === $dial) { $match = $c; break; }
    }
    if (!$match) {
        return ['ok' => false, 'value' => null, 'error' => is_rtl() ? 'يرجى اختيار رمز الدولة.' : 'Please choose a country code.'];
    }

    if ($match['dial'] === 'other') {
        if (strlen($digits) < 7 || strlen($digits) > 15) {
            return ['ok' => false, 'value' => null, 'error' => is_rtl() ? 'رقم الجوال غير صحيح.' : 'Invalid phone number.'];
        }
        return ['ok' => true, 'value' => '+' . $digits, 'error' => null];
    }

    $local = ltrim($digits, '0');
    if ($local === '') { $local = $digits; }

    if (!preg_match($match['pattern'], $local)) {
        $msg = is_rtl()
            ? 'رقم الجوال غير صحيح لدولة ' . $match['name_ar'] . ' (مثال: ' . $match['example'] . ').'
            : 'Invalid phone number for ' . $match['name_en'] . ' (example: ' . $match['example'] . ').';
        return ['ok' => false, 'value' => null, 'error' => $msg];
    }

    return ['ok' => true, 'value' => $match['dial'] . $local, 'error' => null];
}

/**
 * يحاول تفكيك رقم مخزّن مثل "+9665xxxxxxxx" إلى [dial, local] لعرضه في نموذج التعديل.
 */
function split_phone(?string $stored): array {
    $stored = trim((string)$stored);
    if ($stored === '') return ['dial' => '+966', 'local' => ''];
    foreach (phone_countries() as $c) {
        if ($c['dial'] !== 'other' && str_starts_with($stored, $c['dial'])) {
            return ['dial' => $c['dial'], 'local' => substr($stored, strlen($c['dial']))];
        }
    }
    if (str_starts_with($stored, '+')) {
        return ['dial' => 'other', 'local' => substr($stored, 1)];
    }
    return ['dial' => '+966', 'local' => $stored];
}

/** يطبع رقم جوال داخل عنصر معزول اتجاهياً (LTR) حتى لا ينقلب ترتيب الرمز والأرقام داخل صفحات RTL. */
function display_phone(?string $phone): string {
    $phone = trim((string)$phone);
    if ($phone === '') return '—';
    return '<span dir="ltr" style="unicode-bidi:isolate">' . htmlspecialchars($phone) . '</span>';
}

/**
 * يطبع حقل HTML لرمز الدولة + الرقم (select + input) داخل ‎.phone-group.
 */
function render_phone_field(string $name, string $dialValue = '+966', string $localValue = ''): void {
    $rtl = is_rtl();
    echo '<div class="phone-group">';
    echo '<select name="' . htmlspecialchars($name) . '_dial" aria-label="' . ($rtl ? 'رمز الدولة' : 'Country code') . '">';
    foreach (phone_countries() as $c) {
        $sel = $c['dial'] === $dialValue ? ' selected' : '';
        $label = $c['flag'] . ' ' . $c['dial'] . ' ' . ($rtl ? $c['name_ar'] : $c['name_en']);
        echo '<option value="' . htmlspecialchars($c['dial']) . '"' . $sel . '>' . htmlspecialchars($label) . '</option>';
    }
    echo '</select>';
    echo '<input type="tel" inputmode="numeric" name="' . htmlspecialchars($name) . '_local" value="' . htmlspecialchars($localValue) . '" placeholder="' . ($rtl ? '5xxxxxxxx' : '5xxxxxxxx') . '" required>';
    echo '</div>';
}
