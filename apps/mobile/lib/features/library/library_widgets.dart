import 'package:flutter/material.dart';

import '../../core/network/models.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_tokens.dart';

/// A compact card for one library item, shared by the search results and the
/// saved-items list so the two surfaces describe an item identically.
class LibraryItemCard extends StatelessWidget {
  const LibraryItemCard({
    super.key,
    required this.item,
    required this.rtl,
    required this.onTap,
  });

  final LegalLibraryItem item;
  final bool rtl;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final chips = <String>[
      if (item.typeLabel.isNotEmpty) item.typeLabel,
      if (item.sourceName.isNotEmpty) item.sourceName,
      if (item.courtName.isNotEmpty) '🏛 ${item.courtName}',
      if (item.city.isNotEmpty) '📍 ${item.city}',
      if (item.decisionNumber.isNotEmpty) '${rtl ? "رقم" : "No."} ${item.decisionNumber}',
      if (item.hijriYear != null) '${item.hijriYear} ${rtl ? "هـ" : "AH"}',
    ];

    return Material(
      color: context.tokens.surface,
      clipBehavior: Clip.antiAlias,
      shape: RoundedRectangleBorder(
        side: BorderSide(color: context.tokens.border),
        borderRadius: BorderRadius.circular(AppRadius.lg),
      ),
      child: InkWell(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(AppSpacing.s4),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                item.title,
                style: Theme.of(context)
                    .textTheme
                    .bodyMedium
                    ?.copyWith(fontWeight: FontWeight.w700, height: 1.6),
              ),
              if (item.summary.isNotEmpty) ...[
                const SizedBox(height: 6),
                Text(
                  item.summary.length > 160
                      ? '${item.summary.substring(0, 160)}…'
                      : item.summary,
                  style: Theme.of(context).textTheme.bodySmall,
                ),
              ],
              if (chips.isNotEmpty) ...[
                const SizedBox(height: 10),
                Wrap(
                  spacing: 6,
                  runSpacing: 6,
                  children: [
                    for (final c in chips)
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 3),
                        decoration: BoxDecoration(
                          color: context.tokens.surface3,
                          borderRadius: BorderRadius.circular(AppRadius.pill),
                        ),
                        child: Text(
                          c,
                          style: TextStyle(
                            fontSize: 11,
                            color: context.tokens.textMuted,
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                      ),
                  ],
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

/// A small labelled value used on the item detail page.
class LibraryMetaRow extends StatelessWidget {
  const LibraryMetaRow({super.key, required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 6),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 120,
            child: Text(
              label,
              style: TextStyle(
                color: context.tokens.textMuted,
                fontSize: 12,
                fontWeight: FontWeight.w700,
              ),
            ),
          ),
          Expanded(
            child: Text(value, style: const TextStyle(height: 1.6)),
          ),
        ],
      ),
    );
  }
}

/// The teal chip that marks a hosted source's readable text.
class LibraryReadableBadge extends StatelessWidget {
  const LibraryReadableBadge({super.key, required this.rtl});

  final bool rtl;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(
        color: context.tokens.infoBg,
        borderRadius: BorderRadius.circular(AppRadius.pill),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          const Icon(Icons.check_circle_outline, size: 14, color: AppColors.teal),
          const SizedBox(width: 6),
          Text(
            rtl ? 'النص الكامل متاح للقراءة هنا' : 'Full text readable here',
            style: const TextStyle(
              fontSize: 11,
              color: AppColors.teal,
              fontWeight: FontWeight.w700,
            ),
          ),
        ],
      ),
    );
  }
}
