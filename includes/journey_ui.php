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

/**
 * A five-star rating as markup. `average` may be null (no ratings yet), in which case
 * the caller shows an honest "no ratings" label instead of an empty star row. The
 * accessible name states the number so a screen reader does not read five glyphs.
 */
function journey_rating_stars(?float $average, int $count = 0): string {
    if ($average === null || $count === 0) return '';
    $rounded = (int)round($average);
    $stars = str_repeat('★', $rounded) . str_repeat('☆', 5 - $rounded);
    $label = is_rtl()
        ? 'التقييم ' . number_format($average, 1) . ' من 5 (' . $count . ' تقييم)'
        : 'Rating ' . number_format($average, 1) . ' of 5 (' . $count . ' reviews)';
    return '<span class="jr-stars" role="img" aria-label="' . htmlspecialchars($label) . '">'
         . '<span aria-hidden="true">' . $stars . '</span>'
         . ' <small>' . number_format($average, 1) . ' (' . (int)$count . ')</small></span>';
}

/**
 * The favourite heart button for a lawyer card. A plain form POST to
 * `lawyer-profile.php` so it works without JavaScript; when the customer is on the
 * profile page the same control is a bigger button, but on a card this keeps the
 * directory uncluttered. `$isFavorite` paints the filled state.
 */
function journey_favorite_button(int $lawyerId, bool $isFavorite): string {
    $csrf = csrf_token();
    $label = $isFavorite
        ? (is_rtl() ? 'إزالة من المفضلة' : 'Remove from favourites')
        : (is_rtl() ? 'أضف للمفضلة' : 'Add to favourites');
    return '<form method="post" action="/lawyer-profile.php?id=' . (int)$lawyerId . '" class="jr-fav-form">'
         . '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrf) . '">'
         . '<input type="hidden" name="action" value="toggle_favorite">'
         . '<button type="submit" class="jr-fav-btn' . ($isFavorite ? ' on' : '') . '" aria-pressed="' . ($isFavorite ? 'true' : 'false') . '" title="' . htmlspecialchars($label) . '" aria-label="' . htmlspecialchars($label) . '">'
         . '<span aria-hidden="true">' . ($isFavorite ? '♥' : '♡') . '</span></button></form>';
}

/**
 * One lawyer comparison card, shared by the directory, the journey and the favourites
 * page so all three render the same facts (price, duration, modes, verified rating,
 * real availability). `$favoriteIds` is the set of ids the current client saved, so a
 * card can paint the heart without a query per lawyer.
 *
 * @param array<string,mixed> $lw a row from legal_journey_hydrate_lawyer()
 * @param array<int,bool>     $favoriteIds
 */
function journey_lawyer_card(array $lw, array $favoriteIds = []): string {
    // Defensive: the card renders a price and a CSRF token, so the two helpers it calls
    // must be available even when a page includes this file without the shells that
    // normally load them.
    require_once __DIR__ . '/currency.php';
    require_once __DIR__ . '/csrf.php';
    require_once __DIR__ . '/specialization_field.php';
    $av = $lw['availability'] ?? ['has_published_hours' => false, 'next_slots' => []];
    $isFav = !empty($favoriteIds[(int)$lw['id']]);
    ob_start(); ?>
        <div class="compare-card">
          <div class="compare-head">
            <img src="<?= journey_avatar($lw['photo_path']) ?>" alt="<?= htmlspecialchars($lw['full_name']) ?>" onerror="this.src='/assets/img/avatar-placeholder.svg'">
            <div class="who">
              <strong><?= htmlspecialchars($lw['full_name']) ?></strong>
              <div class="sub">
                <?php $lwSpec = lawyer_specialization_display($lw); if ($lwSpec !== ''): ?><?= htmlspecialchars($lwSpec) ?> · <?php endif; ?>
                <?= (int)$lw['years_experience'] ?> <?= is_rtl() ? 'سنة خبرة' : 'yrs experience' ?>
              </div>
              <?php if (!empty($lw['location'])): ?>
                <div class="sub loc"><span aria-hidden="true">📍</span> <?= htmlspecialchars($lw['location']) ?></div>
              <?php endif; ?>
              <?php $stars = journey_rating_stars($lw['rating_average'] ?? null, (int)($lw['rating_count'] ?? 0)); ?>
              <?php if ($stars !== ''): ?><div class="sub"><?= $stars ?></div><?php endif; ?>
            </div>
            <?= journey_favorite_button((int)$lw['id'], $isFav) ?>
          </div>

          <?php if (!empty($lw['mode_chips'])): ?>
            <div class="compare-modes">
              <?php foreach ($lw['mode_chips'] as $chip): ?><span class="jr-mode-chip"><?= htmlspecialchars($chip) ?></span><?php endforeach; ?>
              <?php if (!empty($lw['languages'])): ?><span class="jr-mode-chip subtle">🗣️ <?= htmlspecialchars(implode('، ', $lw['languages'])) ?></span><?php endif; ?>
            </div>
          <?php endif; ?>

          <div class="compare-facts">
            <div class="fact">
              <div class="k"><?= is_rtl() ? 'سعر الاستشارة' : 'Consultation price' ?></div>
              <div class="v ltr"><?php
                if (!empty($lw['pricing']['has_price'])) echo htmlspecialchars(money_text($lw['pricing']['total']));
                else echo is_rtl() ? 'حسب الاتفاق' : 'On request';
              ?></div>
            </div>
            <div class="fact">
              <div class="k"><?= is_rtl() ? 'المدة' : 'Duration' ?></div>
              <div class="v ltr"><?= (int)($lw['duration_minutes'] ?? 30) ?> <?= is_rtl() ? 'دقيقة' : 'min' ?></div>
            </div>
          </div>

          <div class="avail-line">
            <?php if (!$av['has_published_hours']): ?>
              <span class="avail-chip none"><?= is_rtl() ? 'لم يحدد أوقات دوام — سيؤكد الموعد' : 'No published hours — will confirm' ?></span>
            <?php elseif (empty($av['next_slots'])): ?>
              <span class="avail-chip none"><?= is_rtl() ? 'لا توجد أوقات متاحة قريبًا' : 'No slots open soon' ?></span>
            <?php else: ?>
              <span><?= is_rtl() ? 'أقرب الأوقات:' : 'Next available:' ?></span>
              <?php foreach ($av['next_slots'] as $slot): ?>
                <span class="avail-chip ltr"><?= htmlspecialchars($slot['date']) ?> <?= htmlspecialchars($slot['time']) ?></span>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>

          <div class="compare-actions">
            <a href="/lawyer-profile.php?id=<?= (int)$lw['id'] ?>" class="btn btn-outline" style="flex:1"><?= is_rtl() ? 'عرض الملف' : 'View profile' ?></a>
            <a href="/booking.php?lawyer_id=<?= (int)$lw['id'] ?>" class="btn btn-primary" style="flex:1"><?= is_rtl() ? 'حجز استشارة' : 'Book consultation' ?></a>
          </div>
        </div>
    <?php
    return (string)ob_get_clean();
}
