import 'package:flutter/material.dart';

import '../../core/network/models.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_tokens.dart';

/// A compact card for one library item, shared by the search results, the hub's
/// "latest" strip and the saved-items list so every surface describes an item
/// identically: the type, the source, the real title, the opening of the text and
/// the official metadata. Nothing here invents a value — a field the source did not
/// publish is simply absent.
class LibraryItemCard extends StatelessWidget {
  const LibraryItemCard({
    super.key,
    required this.item,
    required this.rtl,
    required this.onTap,
    this.showSummary = true,
  });

  final LegalLibraryItem item;
  final bool rtl;
  final VoidCallback onTap;
  final bool showSummary;

  @override
  Widget build(BuildContext context) {
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
              Wrap(
                spacing: 6,
                runSpacing: 6,
                children: [
                  if (item.typeLabel.isNotEmpty) _Chip(label: item.typeLabel, tone: _ChipTone.type),
                  if (item.sourceName.isNotEmpty) _Chip(label: item.sourceName, tone: _ChipTone.source),
                  if (item.titleIsDerived)
                    _Chip(
                      label: rtl ? 'وصف من الحقول الرسمية' : 'Described from official fields',
                      tone: _ChipTone.derived,
                    ),
                ],
              ),
              const SizedBox(height: 8),
              Text(
                item.title,
                style: Theme.of(context)
                    .textTheme
                    .bodyMedium
                    ?.copyWith(fontWeight: FontWeight.w700, height: 1.6),
              ),
              if (showSummary && item.summary.isNotEmpty) ...[
                const SizedBox(height: 6),
                Text(
                  item.summary.length > 160 ? '${item.summary.substring(0, 160)}…' : item.summary,
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(height: 1.7),
                ),
              ],
              if (LibraryMetaChips.metaFor(item, rtl).isNotEmpty) ...[
                const SizedBox(height: 10),
                LibraryMetaChips(item: item, rtl: rtl),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

/// The shared metadata chip row for one item (court, region, number, date).
class LibraryMetaChips extends StatelessWidget {
  const LibraryMetaChips({super.key, required this.item, required this.rtl});

  final LegalLibraryItem item;
  final bool rtl;

  static List<String> metaFor(LegalLibraryItem it, bool rtl) {
    final out = <String>[];
    if (it.courtName.isNotEmpty) out.add(it.courtName);
    if (it.city.isNotEmpty) out.add(it.city);
    if (it.decisionNumber.isNotEmpty) out.add('${rtl ? "رقم" : "No."} ${it.decisionNumber}');
    if (it.decisionDate.isNotEmpty) {
      out.add(it.decisionDate);
    } else if (it.hijriYear != null) {
      out.add('${it.hijriYear} ${rtl ? "هـ" : "AH"}');
    }
    return out;
  }

  @override
  Widget build(BuildContext context) {
    final chips = metaFor(item, rtl);
    return Wrap(
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
    );
  }
}

enum _ChipTone { type, source, derived }

class _Chip extends StatelessWidget {
  const _Chip({required this.label, required this.tone});

  final String label;
  final _ChipTone tone;

  @override
  Widget build(BuildContext context) {
    final (bg, fg) = switch (tone) {
      _ChipTone.type => (context.tokens.infoBg, AppColors.teal),
      _ChipTone.source => (context.tokens.surface3, context.tokens.textMuted),
      _ChipTone.derived => (context.tokens.warningBg, context.tokens.warningFg),
    };
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(AppRadius.pill)),
      child: Text(
        label,
        style: TextStyle(fontSize: 11, fontWeight: FontWeight.w700, color: fg),
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

/// A small pill used for the browse rows on the hub (court / region / year).
class LibraryBrowseChip extends StatelessWidget {
  const LibraryBrowseChip({
    super.key,
    required this.label,
    required this.count,
    required this.onTap,
    this.icon,
  });

  final String label;
  final int count;
  final VoidCallback onTap;
  final IconData? icon;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: context.tokens.surface,
      clipBehavior: Clip.antiAlias,
      shape: RoundedRectangleBorder(
        side: BorderSide(color: context.tokens.border),
        borderRadius: BorderRadius.circular(AppRadius.pill),
      ),
      child: InkWell(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              if (icon != null) ...[
                Icon(icon, size: 15, color: context.tokens.textMuted),
                const SizedBox(width: 6),
              ],
              Text(label,
                  style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600)),
              const SizedBox(width: 6),
              Text('$count',
                  style: TextStyle(fontSize: 12, color: context.tokens.textMuted)),
            ],
          ),
        ),
      ),
    );
  }
}

/// A labelled section on the item page (الوقائع / الأسباب / منطوق الحكم).
class LibrarySectionBlock extends StatelessWidget {
  const LibrarySectionBlock({
    super.key,
    required this.title,
    required this.body,
    this.icon,
  });

  final String title;
  final String body;
  final IconData? icon;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.s5),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(width: 3, height: 18, color: AppColors.teal),
              const SizedBox(width: 8),
              if (icon != null) ...[
                Icon(icon, size: 16, color: context.tokens.textMuted),
                const SizedBox(width: 6),
              ],
              Expanded(
                child: Text(
                  title,
                  style: TextStyle(
                    fontSize: 15,
                    fontWeight: FontWeight.w800,
                    color: context.tokens.heading,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          Text(body, style: const TextStyle(height: 2.1, fontSize: 15)),
        ],
      ),
    );
  }
}
