<?php
/**
 * Presentation helpers for the customer consultation journey.
 *
 * Kept apart from includes/legal_journey.php, which owns the data and the
 * confidentiality rule. This file only turns a specialty's icon key into markup.
 * The icons are decorative: every card is labelled by its heading, so a specialty
 * with no icon key renders a neutral mark rather than nothing.
 */

function journey_icon_svg(string $key): string {
    $paths = [
        'family'      => '<path d="M12 21s-7-4.35-7-10a4 4 0 0 1 7-2.65A4 4 0 0 1 19 11c0 5.65-7 10-7 10z"/>',
        'commercial'  => '<path d="M3 7h18M5 7v13h14V7M9 11v5M15 11v5"/>',
        'labor'       => '<path d="M4 8h16v11H4zM9 8V6a3 3 0 0 1 6 0v2"/>',
        'criminal'    => '<path d="M12 3v18M5 8h14M7 8l-3 6h6zM17 8l-3 6h6z"/>',
        'general'     => '<path d="M6 3h9l5 5v13H6zM15 3v5h5"/>',
        'enforcement' => '<path d="M5 12l4 4 10-10"/>',
        'endowments'  => '<path d="M4 20V9l8-5 8 5v11M9 20v-6h6v6"/>',
    ];
    $d = $paths[$key] ?? $paths['general'];
    return '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
        . ' stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . $d . '</svg>';
}

/** A lawyer's avatar URL, falling back to the placeholder. */
function journey_avatar(?string $photoPath): string {
    return !empty($photoPath)
        ? '/' . htmlspecialchars($photoPath)
        : '/assets/img/avatar-placeholder.svg';
}
