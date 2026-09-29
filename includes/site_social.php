<?php
/**
 * روابط السوشيال ميديا الرسمية للشركة — تُعرض بالهيدر والفوتر.
 * الأحجام مضبوطة مباشرة على وسم svg (width/height) حتى تبقى صحيحة
 * حتى لو تأخر تحميل ملف CSS.
 */
function site_social_links(): array {
    return [
        ['name' => 'Instagram', 'url' => 'https://www.instagram.com/mawatheq.sa', 'icon' => 'instagram'],
        ['name' => 'TikTok',    'url' => 'https://www.tiktok.com/@mawatheq.sa',   'icon' => 'tiktok'],
        ['name' => 'X',         'url' => 'https://x.com/mawatheqsa',              'icon' => 'x'],
        ['name' => 'Facebook',  'url' => 'https://www.facebook.com/mawatheq.sa/', 'icon' => 'facebook'],
    ];
}

function social_icon_svg(string $icon, int $size = 16): string {
    $paths = [
        'instagram' => '<path d="M12 2c2.7 0 3.06.01 4.12.06 1.06.05 1.79.22 2.43.47.66.26 1.22.6 1.77 1.15.55.55.9 1.11 1.15 1.77.25.64.42 1.37.47 2.43.05 1.06.06 1.42.06 4.12s-.01 3.06-.06 4.12c-.05 1.06-.22 1.79-.47 2.43a4.9 4.9 0 0 1-1.15 1.77 4.9 4.9 0 0 1-1.77 1.15c-.64.25-1.37.42-2.43.47-1.06.05-1.42.06-4.12.06s-3.06-.01-4.12-.06c-1.06-.05-1.79-.22-2.43-.47a4.9 4.9 0 0 1-1.77-1.15 4.9 4.9 0 0 1-1.15-1.77c-.25-.64-.42-1.37-.47-2.43C2.01 15.06 2 14.7 2 12s.01-3.06.06-4.12c.05-1.06.22-1.79.47-2.43.26-.66.6-1.22 1.15-1.77a4.9 4.9 0 0 1 1.77-1.15c.64-.25 1.37-.42 2.43-.47C8.94 2.01 9.3 2 12 2zm0 3.6a6.4 6.4 0 1 0 0 12.8 6.4 6.4 0 0 0 0-12.8zm0 10.56a4.16 4.16 0 1 1 0-8.32 4.16 4.16 0 0 1 0 8.32zm6.66-10.82a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0z"/>',
        'tiktok'   => '<path d="M16.6 5.82c-1-.9-1.6-2.15-1.6-3.52h-3.1v13.9c0 1.5-1.22 2.7-2.72 2.7a2.72 2.72 0 1 1 0-5.44c.25 0 .49.03.72.09V10.4a5.9 5.9 0 0 0-.72-.05A5.85 5.85 0 0 0 3.3 16.2a5.85 5.85 0 0 0 5.85 5.85c3.23 0 5.85-2.62 5.85-5.85V9.02a8.4 8.4 0 0 0 4.9 1.57V7.5a5.1 5.1 0 0 1-3.3-1.68z"/>',
        'x'        => '<path d="M18.9 3H22l-7.4 8.5L23.3 21H16.8l-5-6.5L6 21H2.9l7.9-9L2 3h6.7l4.6 6 5.6-6zm-1.2 16h1.7L7.4 5H5.6l12.1 14z"/>',
        'facebook' => '<path d="M13.5 21v-7.6h2.6l.4-3h-3v-1.9c0-.87.24-1.46 1.5-1.46h1.6V4.3C15.9 4.2 15 4.1 13.9 4.1c-2.3 0-3.9 1.4-3.9 4v2.3H7.4v3h2.6V21h3.5z"/>',
    ];
    $path = $paths[$icon] ?? '';
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" style="width:' . $size . 'px;height:' . $size . 'px;display:block" fill="currentColor" aria-hidden="true">' . $path . '</svg>';
}

/**
 * يطبع صف أيقونات السوشيال ميديا كاملاً (روابط جاهزة).
 * $variant: 'dark' لخلفية داكنة (الفوتر) أو 'light' لخلفية فاتحة (الهيدر).
 */
function render_social_row(string $variant = 'dark', int $size = 16): void {
    $isDark = $variant === 'dark';
    $circleBg   = $isDark ? 'rgba(255,255,255,.08)' : '#F0F4F7';
    $circleFg   = $isDark ? '#ffffff' : '#1E3A5F';
    $hoverBg    = '#2BA8C4';
    $wrap       = $size + 20; // حجم الدائرة الكلي حول الأيقونة
    echo '<div class="social-row social-row-' . $variant . '">';
    foreach (site_social_links() as $s) {
        echo '<a href="' . htmlspecialchars($s['url']) . '" target="_blank" rel="noopener" aria-label="' . htmlspecialchars($s['name']) . '"'
           . ' style="width:' . $wrap . 'px;height:' . $wrap . 'px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;background:' . $circleBg . ';color:' . $circleFg . ';flex:none">'
           . social_icon_svg($s['icon'], $size)
           . '</a>';
    }
    echo '</div>';
}
