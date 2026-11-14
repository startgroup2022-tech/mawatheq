import 'package:flutter/material.dart';

import '../../core/config/app_config.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_tokens.dart';
import '../../core/network/models.dart';
import 'brand_widgets.dart';

/// The one lawyer card the app renders everywhere a lawyer is listed: the public
/// directory, the journey result, the favorites list and the availability alerts.
///
/// It exists so a lawyer is described identically on every screen. The fields it shows
/// all come from the server (`mode_chips`, `languages`, `rating_average`, `fee_display`),
/// so the card cannot disagree with the website, which renders the same fields from the
/// same endpoints.
class LawyerCard extends StatelessWidget {
  const LawyerCard({
    super.key,
    required this.lawyer,
    required this.rtl,
    this.isFavorite = false,
    this.onToggleFavorite,
    this.onBook,
    this.onTap,
    this.trailing,
  });

  final LawyerProfile lawyer;
  final bool rtl;

  /// Whether the heart is filled. Null [onToggleFavorite] hides the heart entirely —
  /// a guest has no favorites, so the control is absent rather than shown inert.
  final bool isFavorite;
  final VoidCallback? onToggleFavorite;

  /// The primary action. When omitted, no book button is drawn.
  final VoidCallback? onBook;

  /// Whole-card tap, used where the card opens a detail view.
  final VoidCallback? onTap;

  /// Extra content below the action row (e.g. a cancel button on the alerts screen).
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    final content = Padding(
      padding: const EdgeInsets.all(14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              _Avatar(lawyer: lawyer),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Expanded(
                          child: Text(
                            lawyer.name,
                            style: TextStyle(
                              fontWeight: FontWeight.w700,
                              color: context.tokens.heading,
                              fontSize: 15,
                            ),
                          ),
                        ),
                        if (onToggleFavorite != null)
                          _HeartButton(
                            filled: isFavorite,
                            onPressed: onToggleFavorite!,
                          ),
                      ],
                    ),
                    if (lawyer.specializationLabel.isNotEmpty) ...[
                      const SizedBox(height: 3),
                      Text(
                        lawyer.specializationLabel,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(color: context.tokens.textMuted, fontSize: 13),
                      ),
                    ],
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          Wrap(
            spacing: 10,
            runSpacing: 6,
            children: [
              if (lawyer.hasVerifiedRating)
                _MetaChip(
                  icon: Icons.star_rounded,
                  text: '${lawyer.ratingAverage!.toStringAsFixed(1)} (${lawyer.ratingCount})',
                  color: AppColors.gold,
                ),
              if (lawyer.yearsExperience > 0)
                _MetaChip(
                  icon: Icons.workspace_premium_outlined,
                  text: rtl ? '${lawyer.yearsExperience} سنة خبرة' : '${lawyer.yearsExperience} yrs',
                ),
              if (lawyer.location.isNotEmpty)
                _MetaChip(icon: Icons.place_outlined, text: lawyer.location)
              else if (lawyer.jurisdiction.isNotEmpty)
                _MetaChip(icon: Icons.place_outlined, text: lawyer.jurisdiction),
            ],
          ),
          // Consultation modes and spoken languages, straight from the server labels.
          if (lawyer.modeChips.isNotEmpty || lawyer.languages.isNotEmpty) ...[
            const SizedBox(height: 8),
            Wrap(
              spacing: 6,
              runSpacing: 6,
              children: [
                for (final chip in lawyer.modeChips) _TagChip(label: chip, strong: true),
                for (final lang in lawyer.languages) _TagChip(label: lang),
              ],
            ),
          ],
          const SizedBox(height: 10),
          Row(
            children: [
              if (lawyer.feeDisplay.isNotEmpty) ...[
                Icon(Icons.payments_outlined, size: 15, color: AppColors.teal),
                const SizedBox(width: 4),
                Flexible(
                  child: Text(
                    rtl
                        ? '${lawyer.feeDisplay} / ${lawyer.durationMinutes} دقيقة'
                        : '${lawyer.feeDisplay} / ${lawyer.durationMinutes} min',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(
                      color: AppColors.teal,
                      fontSize: 12.5,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ),
              ] else
                Flexible(
                  child: Text(
                    rtl ? 'حسب الاتفاق' : 'On request',
                    style: TextStyle(color: context.tokens.textSubtle, fontSize: 12.5),
                  ),
                ),
              const Spacer(),
              Text(
                lawyer.hasAvailability
                    ? (rtl ? '${lawyer.nextSlotCount} موعد متاح' : '${lawyer.nextSlotCount} slots')
                    : (rtl ? 'لا مواعيد' : 'No slots'),
                style: TextStyle(
                  color: lawyer.hasAvailability ? AppColors.teal : context.tokens.textSubtle,
                  fontSize: 12,
                  fontWeight: FontWeight.w600,
                ),
              ),
            ],
          ),
          if (onBook != null) ...[
            const SizedBox(height: 12),
            SecondaryButton(
              label: rtl ? 'احجز استشارة' : 'Book a consultation',
              icon: Icons.event_available_outlined,
              expand: true,
              onPressed: onBook,
            ),
          ],
          if (trailing != null) ...[
            const SizedBox(height: 10),
            trailing!,
          ],
        ],
      ),
    );

    return Container(
      decoration: BoxDecoration(
        color: context.tokens.surface,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        border: Border.all(color: context.tokens.border),
        boxShadow: AppShadow.xs,
      ),
      clipBehavior: Clip.antiAlias,
      child: onTap == null
          ? content
          : InkWell(onTap: onTap, child: content),
    );
  }
}

class _Avatar extends StatelessWidget {
  const _Avatar({required this.lawyer});
  final LawyerProfile lawyer;

  @override
  Widget build(BuildContext context) {
    final url = lawyer.photoUrl(AppConfig.siteBase);
    return CircleAvatar(
      radius: 24,
      backgroundColor: AppColors.navy,
      child: url != null
          ? ClipOval(
              child: Image.network(
                url,
                width: 48,
                height: 48,
                fit: BoxFit.cover,
                errorBuilder: (_, __, ___) =>
                    const Icon(Icons.person, color: Colors.white),
              ),
            )
          : const Icon(Icons.person, color: Colors.white),
    );
  }
}

class _HeartButton extends StatelessWidget {
  const _HeartButton({required this.filled, required this.onPressed});
  final bool filled;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return IconButton(
      onPressed: onPressed,
      tooltip: filled ? 'إزالة من المفضّلة' : 'إضافة إلى المفضّلة',
      iconSize: 22,
      constraints: const BoxConstraints(minWidth: 44, minHeight: 44),
      icon: Icon(
        filled ? Icons.favorite : Icons.favorite_border,
        color: filled ? AppColors.danger : context.tokens.textSubtle,
      ),
    );
  }
}

class _MetaChip extends StatelessWidget {
  const _MetaChip({required this.icon, required this.text, this.color});
  final IconData icon;
  final String text;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final c = color ?? context.tokens.textSubtle;
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(icon, size: 15, color: c),
        const SizedBox(width: 4),
        Text(text, style: TextStyle(color: c, fontSize: 12)),
      ],
    );
  }
}

class _TagChip extends StatelessWidget {
  const _TagChip({required this.label, this.strong = false});
  final String label;
  final bool strong;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 4),
      decoration: BoxDecoration(
        color: strong ? context.tokens.infoBg : context.tokens.surface2,
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: strong ? AppColors.teal : context.tokens.border),
      ),
      child: Text(
        label,
        style: TextStyle(
          fontSize: 11.5,
          fontWeight: strong ? FontWeight.w700 : FontWeight.w500,
          color: strong ? AppColors.teal : context.tokens.textMuted,
        ),
      ),
    );
  }
}
