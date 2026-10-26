import 'package:flutter/material.dart';

import '../../core/network/models.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_tokens.dart';


/// The reusable component set named in MAWATHEQ_DESIGN_SYSTEM.md.
/// The website uses the same component names as CSS classes, so one can be
/// traced to the other.

// --------------------------------------------------------------------- logo

/// The original MAWATHEQ mark. The asset is the owner's own file
/// (`assets/images/logo.webp`, byte-identical to the website's) — it is never
/// redrawn, recoloured or replaced with a generic legal icon.
class BrandLogo extends StatelessWidget {
  const BrandLogo({super.key, this.size = 32});

  final double size;

  @override
  Widget build(BuildContext context) {
    return Image.asset(
      'assets/images/logo.webp',
      width: size,
      height: size,
      fit: BoxFit.contain,
      // If the asset is ever missing the app must still render a recognisable
      // mark rather than a broken-image box.
      errorBuilder: (context, error, stack) => Icon(
        Icons.gavel_outlined,
        size: size * 0.8,
        color: context.tokens.heading,
      ),
    );
  }
}

// ---------------------------------------------------------------- app bar

/// Brand app bar: original logo, wordmark, optional subtitle and actions.
class BrandAppBar extends StatelessWidget implements PreferredSizeWidget {
  const BrandAppBar({
    super.key,
    this.subtitle,
    this.actions = const [],
    this.showLogo = true,
    this.bottom,
    this.leading,
  });

  final String? subtitle;
  final List<Widget> actions;
  final bool showLogo;
  final PreferredSizeWidget? bottom;

  /// Optional leading widget. Left null, the AppBar keeps its default (an automatic
  /// back button when the route can pop), so existing screens are unaffected.
  final Widget? leading;

  @override
  Size get preferredSize => Size.fromHeight(kToolbarHeight + (bottom?.preferredSize.height ?? 0));

  @override
  Widget build(BuildContext context) {
    return AppBar(
      titleSpacing: AppSpacing.s4,
      leading: leading,
      title: Row(
        children: [
          if (showLogo) ...[
            const BrandLogo(size: 30),
            const SizedBox(width: AppSpacing.s2),
          ],
          // Flexible + ellipsis because the bar also carries action icons: with a
          // subtitle the title is the widest thing in the row, so it is the part
          // that must yield rather than overflow the bar.
          Flexible(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisAlignment: MainAxisAlignment.center,
              mainAxisSize: MainAxisSize.min,
              children: [
                const Text(
                  'مواثيق',
                  style: TextStyle(fontWeight: FontWeight.w800),
                  overflow: TextOverflow.ellipsis,
                ),
                if (subtitle != null)
                  Text(
                    subtitle!,
                    style: TextStyle(
                      fontSize: AppFontSize.xs,
                      color: context.tokens.textSubtle,
                      fontWeight: FontWeight.w400,
                    ),
                    overflow: TextOverflow.ellipsis,
                  ),
              ],
            ),
          ),
        ],
      ),
      actions: actions,
      bottom: bottom,
    );
  }
}

// ---------------------------------------------------------------- buttons

/// Primary action — navy fill, matching `.btn-primary` on the website.
class PrimaryButton extends StatelessWidget {
  const PrimaryButton({
    super.key,
    required this.label,
    this.onPressed,
    this.icon,
    this.expand = false,
    this.loading = false,
  });

  final String label;
  final VoidCallback? onPressed;
  final IconData? icon;
  final bool expand;
  final bool loading;

  @override
  Widget build(BuildContext context) {
    final child = loading
        ? const SizedBox(
            width: 18,
            height: 18,
            child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
          )
        : Row(
            mainAxisSize: MainAxisSize.min,
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              if (icon != null) ...[
                Icon(icon, size: 18),
                const SizedBox(width: AppSpacing.s2),
              ],
              if (expand)
                Flexible(
                  child: Text(label,
                      maxLines: 1, overflow: TextOverflow.ellipsis),
                )
              else
                Text(label),
            ],
          );

    final button = FilledButton(
      onPressed: loading ? null : onPressed,
      child: child,
    );
    return expand ? SizedBox(width: double.infinity, child: button) : button;
  }
}

/// Secondary action — outline style, matching `.btn-outline`.
class SecondaryButton extends StatelessWidget {
  const SecondaryButton({
    super.key,
    required this.label,
    this.onPressed,
    this.icon,
    this.expand = false,
  });

  final String label;
  final VoidCallback? onPressed;
  final IconData? icon;
  final bool expand;

  @override
  Widget build(BuildContext context) {
    final button = OutlinedButton(
      onPressed: onPressed,
      child: Row(
        mainAxisSize: MainAxisSize.min,
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          if (icon != null) ...[
            Icon(icon, size: 18),
            const SizedBox(width: AppSpacing.s2),
          ],
          if (expand)
            Flexible(
              child: Text(label,
                  maxLines: 1, overflow: TextOverflow.ellipsis),
            )
          else
            Text(label),
        ],
      ),
    );
    return expand ? SizedBox(width: double.infinity, child: button) : button;
  }
}

// ------------------------------------------------------------ hero card

/// The rounded hero container from the approved design: light-blue surface,
/// navy typography and a cyan gradient accent.
class GradientHeroCard extends StatelessWidget {
  const GradientHeroCard({
    super.key,
    required this.title,
    required this.body,
    this.ctaLabel,
    this.onCta,
    this.eyebrow,
  });

  final String title;
  final String body;
  final String? ctaLabel;
  final VoidCallback? onCta;
  final String? eyebrow;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(AppSpacing.s5),
      decoration: BoxDecoration(
        gradient: context.tokens.washGradient,
        borderRadius: BorderRadius.circular(AppRadius.xl),
        border: Border.all(color: context.tokens.washBorder),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (eyebrow != null) ...[
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
              decoration: BoxDecoration(
                gradient: AppColors.cyanAccent,
                borderRadius: BorderRadius.circular(AppRadius.pill),
              ),
              child: Text(
                eyebrow!,
                style: const TextStyle(
                  fontSize: AppFontSize.xs,
                  fontWeight: FontWeight.w700,
                  color: AppColors.navyDeep,
                ),
              ),
            ),
            const SizedBox(height: AppSpacing.s3),
          ],
          Text(title, style: theme.textTheme.titleLarge?.copyWith(color: context.tokens.heading)),
          const SizedBox(height: AppSpacing.s2),
          Text(body, style: theme.textTheme.bodySmall?.copyWith(color: context.tokens.textMuted)),
          if (ctaLabel != null) ...[
            const SizedBox(height: AppSpacing.s4),
            SizedBox(
              height: 42,
              child: FilledButton(
                onPressed: onCta,
                style: FilledButton.styleFrom(
                  backgroundColor: AppColors.teal,
                  padding: const EdgeInsets.symmetric(horizontal: AppSpacing.s5),
                ),
                child: Text(ctaLabel!),
              ),
            ),
          ],
        ],
      ),
    );
  }
}

// -------------------------------------------------------------- tiles

/// A capability tile in the home feature grid.
class FeatureTile extends StatelessWidget {
  const FeatureTile({
    super.key,
    required this.icon,
    required this.label,
    this.onTap,
    this.enabled = true,
  });

  final IconData icon;
  final String label;
  final VoidCallback? onTap;
  final bool enabled;

  @override
  Widget build(BuildContext context) {
    return Opacity(
      opacity: enabled ? 1 : 0.45,
      child: Material(
        color: context.tokens.surface,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        child: InkWell(
          onTap: enabled ? onTap : null,
          borderRadius: BorderRadius.circular(AppRadius.lg),
          child: Container(
            padding: const EdgeInsets.symmetric(
              horizontal: AppSpacing.s3,
              vertical: AppSpacing.s4,
            ),
            decoration: BoxDecoration(
              border: Border.all(color: context.tokens.border),
              borderRadius: BorderRadius.circular(AppRadius.lg),
            ),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Container(
                  width: 40,
                  height: 40,
                  decoration: BoxDecoration(
                    gradient: AppColors.cyanAccent,
                    borderRadius: BorderRadius.circular(AppRadius.sm),
                  ),
                  child: Icon(icon, size: 20, color: AppColors.navyDeep),
                ),
                const SizedBox(height: AppSpacing.s2),
                Text(
                  label,
                  textAlign: TextAlign.center,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    fontSize: AppFontSize.xs,
                    fontWeight: FontWeight.w600,
                    color: context.tokens.text,
                    height: 1.4,
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// A prominent primary action, sized for the home screen.
///
/// Two of these sit side by side directly under the banner slider, which is the
/// first thing the customer acts on. It is deliberately larger and higher
/// contrast than [FeatureTile]: the top of the home screen answers "what do I do
/// here", and the secondary services sit further down.
class ActionCard extends StatelessWidget {
  const ActionCard({
    super.key,
    required this.icon,
    required this.label,
    required this.caption,
    required this.onTap,
    this.primary = true,
  });

  final IconData icon;
  final String label;
  final String caption;
  final VoidCallback onTap;

  /// The filled brand treatment. Set false for the outline variant, which is used
  /// where a second action must not compete with the first.
  final bool primary;

  @override
  Widget build(BuildContext context) {
    final tokens = context.tokens;
    final fg = primary ? tokens.onBrand : tokens.heading;

    return Material(
      color: primary ? tokens.brandFill : tokens.surface,
      borderRadius: BorderRadius.circular(AppRadius.xl),
      child: InkWell(
        borderRadius: BorderRadius.circular(AppRadius.xl),
        onTap: onTap,
        child: Container(
          padding: const EdgeInsets.symmetric(
            horizontal: AppSpacing.s4,
            vertical: AppSpacing.s5,
          ),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(AppRadius.xl),
            border: Border.all(
              color: primary ? tokens.brandFill : tokens.border,
            ),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Icon(icon, size: 26, color: fg),
              const SizedBox(height: AppSpacing.s3),
              Text(
                label,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(
                  fontSize: AppFontSize.md,
                  fontWeight: FontWeight.w800,
                  color: fg,
                  height: 1.3,
                ),
              ),
              const SizedBox(height: AppSpacing.s1),
              Text(
                caption,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(
                  fontSize: AppFontSize.xs,
                  height: 1.45,
                  // On the filled card the caption is the same colour as the label
                  // rather than a muted tone: a muted navy on a dark fill is the
                  // exact pairing that reads as unreadable text in dark mode.
                  color: primary
                      ? fg.withValues(alpha: 0.88)
                      : tokens.textMuted,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// A compact stat card (label + value) used on dashboards.
class DashboardCard extends StatelessWidget {
  const DashboardCard({
    super.key,
    required this.value,
    required this.label,
    this.icon,
    this.accent,
    this.onTap,
  });

  final String value;
  final String label;
  final IconData? icon;
  final Color? accent;

  /// Optional destination. When supplied the card becomes a button and gains the
  /// 44px minimum hit area the accessibility audit requires; without it the card
  /// stays a plain figure, so an existing caller is unaffected.
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final card = Container(
      padding: const EdgeInsets.all(AppSpacing.s4),
      decoration: BoxDecoration(
        color: context.tokens.surface,
        border: Border.all(color: context.tokens.border),
        borderRadius: BorderRadius.circular(AppRadius.lg),
        boxShadow: AppShadow.xs,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (icon != null) ...[
            Icon(icon, size: 20, color: accent ?? AppColors.teal),
            const SizedBox(height: AppSpacing.s2),
          ],
          Text(
            value,
            style: Theme.of(context).textTheme.titleLarge?.copyWith(
                  // The fallback is the themed heading, not a fixed navy: the number
                  // sits on the card surface, and navy-on-dark is unreadable. A
                  // caller passing an accent (gold) still wins, because that is a
                  // deliberate highlight on a fixed brand colour.
                  color: accent ?? context.tokens.heading,
                ),
          ),
          const SizedBox(height: 2),
          Text(label, style: Theme.of(context).textTheme.bodySmall),
        ],
      ),
    );
    if (onTap == null) return card;
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(AppRadius.lg),
      child: ConstrainedBox(
        constraints: const BoxConstraints(minHeight: 44),
        child: card,
      ),
    );
  }
}

// ------------------------------------------------------------ status

/// A labelled status pill. Colour alone never carries the meaning — the label
/// always states the state, which also satisfies the accessibility rule.
class StatusBadge extends StatelessWidget {
  const StatusBadge({super.key, required this.status, this.label});

  final String status;
  final String? label;

  static const _labels = {
    'open': 'مفتوحة',
    'in_review': 'قيد المراجعة',
    'closed_won': 'مكسوبة',
    'closed_lost': 'خاسرة',
    'closed_settled': 'تسوية',
    'pending': 'قيد الانتظار',
    'processing': 'قيد المعالجة',
    'completed': 'مكتملة',
    'failed': 'فاشلة',
    'active': 'نشط',
    'confirmed': 'مؤكدة',
    'cancelled': 'ملغاة',
    'paid': 'مدفوعة',
    'unpaid': 'غير مدفوعة',
    'overdue': 'متأخرة',
    'draft': 'مسودة',
    'approved': 'معتمد',
    'suspended': 'موقوف',
  };

  @override
  Widget build(BuildContext context) {
    final text = label ?? _labels[status] ?? status;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(
        color: AppColors.backgroundForStatusIn(status, context.tokens),
        borderRadius: BorderRadius.circular(AppRadius.pill),
      ),
      child: Text(
        text,
        style: TextStyle(
          fontSize: AppFontSize.xs,
          fontWeight: FontWeight.w700,
          color: AppColors.statusFgFor(status, context.tokens),
        ),
      ),
    );
  }
}

// -------------------------------------------------------------- cards

/// A case row card.
class CaseCard extends StatelessWidget {
  const CaseCard({super.key, required this.item, this.onTap});

  final CaseSummary item;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    return _SurfaceCard(
      onTap: onTap,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  item.title.isEmpty ? 'قضية #${item.id}' : item.title,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: Theme.of(context).textTheme.titleMedium,
                ),
              ),
              const SizedBox(width: AppSpacing.s2),
              StatusBadge(status: item.status),
            ],
          ),
          if (item.clientName.isNotEmpty || item.caseNumber.isNotEmpty) ...[
            const SizedBox(height: AppSpacing.s2),
            Text(
              [item.caseNumber, item.clientName].where((s) => s.isNotEmpty).join(' · '),
              style: Theme.of(context).textTheme.bodySmall,
            ),
          ],
          if (item.nextHearing.isNotEmpty) ...[
            const SizedBox(height: AppSpacing.s2),
            Row(
              children: [
                const Icon(Icons.event_outlined, size: 15, color: AppColors.teal),
                const SizedBox(width: 6),
                Text('الجلسة القادمة: ${item.nextHearing}',
                    style: Theme.of(context).textTheme.bodySmall),
              ],
            ),
          ],
        ],
      ),
    );
  }
}

/// A document row card.
class DocumentCard extends StatelessWidget {
  const DocumentCard({super.key, required this.item, this.onTap});

  final DocumentSummary item;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    return _SurfaceCard(
      onTap: onTap,
      child: Row(
        children: [
          Container(
            width: 40,
            height: 40,
            decoration: BoxDecoration(
              color: context.tokens.infoBg,
              borderRadius: BorderRadius.circular(AppRadius.sm),
            ),
            child: const Icon(Icons.description_outlined, size: 20, color: AppColors.teal),
          ),
          const SizedBox(width: AppSpacing.s3),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  item.title.isEmpty ? 'مستند #${item.id}' : item.title,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: Theme.of(context).textTheme.titleMedium?.copyWith(
                        fontSize: AppFontSize.base,
                      ),
                ),
                const SizedBox(height: 2),
                Text(
                  item.documentType == 'contract' ? 'عقد' : 'مستند عام',
                  style: Theme.of(context).textTheme.bodySmall,
                ),
              ],
            ),
          ),
          StatusBadge(status: item.status),
        ],
      ),
    );
  }
}

/// A consultation row card.
class ConsultationCard extends StatelessWidget {
  const ConsultationCard({super.key, required this.item, this.onTap});

  final Consultation item;
  final VoidCallback? onTap;

  static const _types = {'text': 'نصية', 'voice': 'صوتية', 'video': 'مرئية'};

  @override
  Widget build(BuildContext context) {
    return _SurfaceCard(
      onTap: onTap,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  item.topic.isEmpty ? 'استشارة #${item.id}' : item.topic,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: Theme.of(context).textTheme.titleMedium?.copyWith(
                        fontSize: AppFontSize.base,
                      ),
                ),
              ),
              StatusBadge(status: item.status),
            ],
          ),
          const SizedBox(height: AppSpacing.s2),
          Text(
            [
              item.lawyerName.isNotEmpty ? item.lawyerName : item.clientName,
              _types[item.consultationType] ?? item.consultationType,
              item.schedule,
            ].where((s) => s.isNotEmpty).join(' · '),
            style: Theme.of(context).textTheme.bodySmall,
          ),
          if (item.attachmentCount > 0) ...[
            const SizedBox(height: AppSpacing.s2),
            Row(
              children: [
                const Icon(Icons.attach_file, size: 14),
                const SizedBox(width: 4),
                Text(
                  item.attachmentCount == 1
                      ? 'مرفق واحد'
                      : '${item.attachmentCount} مرفقات',
                  style: Theme.of(context).textTheme.bodySmall,
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}

// ------------------------------------------------------------ AI bubble

/// A chat bubble in the assistant screen. User bubbles align to the trailing
/// edge (which mirrors correctly under RTL).
class AIChatBubble extends StatelessWidget {
  const AIChatBubble({
    super.key,
    required this.message,
    required this.isUser,
    this.reference,
    this.isDemo = false,
  });

  final String message;
  final bool isUser;
  final String? reference;
  final bool isDemo;

  @override
  Widget build(BuildContext context) {
    return Align(
      alignment: isUser ? AlignmentDirectional.centerEnd : AlignmentDirectional.centerStart,
      child: Container(
        constraints: BoxConstraints(
          maxWidth: MediaQuery.of(context).size.width * 0.82,
        ),
        margin: const EdgeInsets.only(bottom: AppSpacing.s3),
        padding: const EdgeInsets.all(AppSpacing.s3),
        decoration: BoxDecoration(
          color: isUser ? context.tokens.infoBg : context.tokens.surface,
          border: Border.all(
            color: isUser ? context.tokens.washBorder : context.tokens.border,
          ),
          borderRadius: BorderRadius.circular(AppRadius.lg),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (isDemo) ...[
              Row(
                children: const [
                  Icon(Icons.info_outline, size: 13, color: AppColors.gold),
                  SizedBox(width: 5),
                  Text(
                    'مثال توضيحي',
                    style: TextStyle(
                      fontSize: 11,
                      fontWeight: FontWeight.w700,
                      color: AppColors.gold,
                    ),
                  ),
                ],
              ),
              const SizedBox(height: AppSpacing.s2),
            ],
            Text(
              message,
              style: Theme.of(context).textTheme.bodyMedium?.copyWith(height: 1.85),
            ),
            if (reference != null && reference!.isNotEmpty) ...[
              const SizedBox(height: AppSpacing.s2),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                decoration: BoxDecoration(
                  color: context.tokens.infoBg,
                  borderRadius: BorderRadius.circular(AppRadius.pill),
                  border: Border.all(color: context.tokens.washBorder),
                ),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    const Icon(Icons.menu_book_outlined, size: 13, color: AppColors.teal),
                    const SizedBox(width: 5),
                    Flexible(
                      child: Text(
                        reference!,
                        style: const TextStyle(
                          fontSize: 11,
                          color: AppColors.teal,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

// ------------------------------------------------------------ inputs

/// RTL-aware search field.
class SearchField extends StatelessWidget {
  const SearchField({
    super.key,
    required this.hint,
    this.onChanged,
    this.onSubmitted,
    this.controller,
  });

  final String hint;
  final ValueChanged<String>? onChanged;
  final ValueChanged<String>? onSubmitted;
  final TextEditingController? controller;

  @override
  Widget build(BuildContext context) {
    return TextField(
      controller: controller,
      onChanged: onChanged,
      onSubmitted: onSubmitted,
      textInputAction: TextInputAction.search,
      decoration: InputDecoration(
        hintText: hint,
        prefixIcon: Icon(Icons.search, size: 20, color: context.tokens.textSubtle),
        isDense: true,
      ),
    );
  }
}

/// A single-select chip row.
class FilterChips extends StatelessWidget {
  const FilterChips({
    super.key,
    required this.options,
    required this.selected,
    required this.onSelected,
  });

  final List<String> options;
  final String selected;
  final ValueChanged<String> onSelected;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 40,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        itemCount: options.length,
        separatorBuilder: (_, __) => const SizedBox(width: AppSpacing.s2),
        itemBuilder: (context, index) {
          final option = options[index];
          final isSelected = option == selected;
          return ChoiceChip(
            label: Text(option),
            selected: isSelected,
            onSelected: (_) => onSelected(option),
            showCheckmark: false,
          );
        },
      ),
    );
  }
}

// -------------------------------------------------------- states

/// Empty state with an icon, a message and an optional action.
class EmptyState extends StatelessWidget {
  const EmptyState({
    super.key,
    required this.message,
    this.icon = Icons.inbox_outlined,
    this.actionLabel,
    this.onAction,
    this.title,
  });

  final String message;
  final IconData icon;
  final String? actionLabel;
  final VoidCallback? onAction;
  final String? title;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.s8),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Container(
              width: 68,
              height: 68,
              decoration: BoxDecoration(
                color: context.tokens.infoBg,
                shape: BoxShape.circle,
              ),
              child: Icon(icon, size: 30, color: AppColors.teal),
            ),
            const SizedBox(height: AppSpacing.s4),
            if (title != null) ...[
              Text(title!, style: Theme.of(context).textTheme.titleMedium),
              const SizedBox(height: AppSpacing.s2),
            ],
            Text(
              message,
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.bodySmall,
            ),
            if (actionLabel != null) ...[
              const SizedBox(height: AppSpacing.s5),
              SecondaryButton(label: actionLabel!, onPressed: onAction),
            ],
          ],
        ),
      ),
    );
  }
}

/// The one empty state for "this specialty has no available lawyer".
///
/// It is shared by the consultation journey and the public lawyer directory so the
/// two surfaces cannot drift: the customer sees the same sentence and the same two
/// routes out wherever they hit the state.
///
/// The body is deliberately **only** a message and the two buttons. It once echoed
/// the server's `alternative` object, which stringified a map and printed raw
/// `{reason: ..., suggest: ...}` text under the message — no backend diagnostic,
/// status code or placeholder may reach a customer-facing empty state. If a future
/// edit wants to surface a reason, it belongs in the message copy, reviewed, not in
/// a raw field.
///
/// Both actions are real: [onAskAssistant] opens the AI assistant and [onContact]
/// opens the support/contact page. Neither is a no-op, and neither is disabled when
/// the assistant has no configured provider — the assistant reports its own honest
/// state rather than the empty state pretending an answer is impossible.
class NoLawyerState extends StatelessWidget {
  const NoLawyerState({
    super.key,
    required this.onAskAssistant,
    required this.onContact,
    this.message = 'لا يوجد محامي متاح',
  });

  final VoidCallback onAskAssistant;
  final VoidCallback onContact;

  /// The single message. Overridable only so a caller can localise it; the default
  /// is the exact wording the brief requires.
  final String message;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: SingleChildScrollView(
        padding: const EdgeInsets.all(AppSpacing.s6),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Container(
              width: 68,
              height: 68,
              decoration: BoxDecoration(
                color: context.tokens.infoBg,
                shape: BoxShape.circle,
              ),
              child: const Icon(Icons.search_off, size: 30, color: AppColors.teal),
            ),
            const SizedBox(height: AppSpacing.s4),
            Text(
              message,
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.titleMedium,
            ),
            const SizedBox(height: AppSpacing.s5),
            PrimaryButton(
              label: 'المساعد الذكي',
              icon: Icons.auto_awesome_outlined,
              onPressed: onAskAssistant,
              expand: true,
            ),
            const SizedBox(height: AppSpacing.s3),
            SecondaryButton(
              label: 'تواصل معنا',
              icon: Icons.support_agent_outlined,
              onPressed: onContact,
              expand: true,
            ),
          ],
        ),
      ),
    );
  }
}

/// Error state. Shows the server's own message and offers a retry when the
/// failure is the kind that can succeed later.
class ErrorState extends StatelessWidget {
  const ErrorState({
    super.key,
    required this.message,
    this.onRetry,
    this.icon = Icons.error_outline,
  });

  final String message;
  final VoidCallback? onRetry;
  final IconData icon;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.s8),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Container(
              width: 68,
              height: 68,
              decoration: BoxDecoration(
                color: context.tokens.dangerBg,
                shape: BoxShape.circle,
              ),
              child: Icon(icon, size: 30, color: context.tokens.dangerFg),
            ),
            const SizedBox(height: AppSpacing.s4),
            Text(
              message,
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.bodyMedium,
            ),
            if (onRetry != null) ...[
              const SizedBox(height: AppSpacing.s5),
              SecondaryButton(
                label: 'إعادة المحاولة',
                icon: Icons.refresh,
                onPressed: onRetry,
              ),
            ],
          ],
        ),
      ),
    );
  }
}

/// Shimmer placeholder used while data loads.
class LoadingSkeleton extends StatefulWidget {
  const LoadingSkeleton({super.key, this.height = 76, this.count = 4});

  final double height;
  final int count;

  @override
  State<LoadingSkeleton> createState() => _LoadingSkeletonState();
}

class _LoadingSkeletonState extends State<LoadingSkeleton>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1100),
  )..repeat(reverse: true);

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      children: List.generate(
        widget.count,
        (index) => Padding(
          padding: const EdgeInsets.only(bottom: AppSpacing.s3),
          child: AnimatedBuilder(
            animation: _controller,
            builder: (context, child) => Container(
              height: widget.height,
              decoration: BoxDecoration(
                color: Color.lerp(
                  context.tokens.surface3,
                  context.tokens.surface2,
                  _controller.value,
                ),
                borderRadius: BorderRadius.circular(AppRadius.lg),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// Confirmation dialog for destructive or irreversible actions.
class ConfirmationDialog extends StatelessWidget {
  const ConfirmationDialog({
    super.key,
    required this.title,
    required this.message,
    this.confirmLabel = 'تأكيد',
    this.cancelLabel = 'إلغاء',
    this.destructive = false,
  });

  final String title;
  final String message;
  final String confirmLabel;
  final String cancelLabel;
  final bool destructive;

  /// Returns true when the user confirms.
  static Future<bool> show(
    BuildContext context, {
    required String title,
    required String message,
    String confirmLabel = 'تأكيد',
    String cancelLabel = 'إلغاء',
    bool destructive = false,
  }) async {
    final result = await showDialog<bool>(
      context: context,
      builder: (_) => ConfirmationDialog(
        title: title,
        message: message,
        confirmLabel: confirmLabel,
        cancelLabel: cancelLabel,
        destructive: destructive,
      ),
    );
    return result ?? false;
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(AppRadius.lg),
      ),
      title: Text(title, style: Theme.of(context).textTheme.titleMedium),
      content: Text(message, style: Theme.of(context).textTheme.bodyMedium),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(false),
          child: Text(cancelLabel),
        ),
        FilledButton(
          onPressed: () => Navigator.of(context).pop(true),
          style: destructive
              ? FilledButton.styleFrom(backgroundColor: AppColors.danger)
              : null,
          child: Text(confirmLabel),
        ),
      ],
    );
  }
}

// ---------------------------------------------------------- internals

/// Shared card surface so every list row has identical border, radius and shadow.
class _SurfaceCard extends StatelessWidget {
  const _SurfaceCard({required this.child, this.onTap});

  final Widget child;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: context.tokens.surface,
      borderRadius: BorderRadius.circular(AppRadius.lg),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        child: Container(
          width: double.infinity,
          padding: const EdgeInsets.all(AppSpacing.s4),
          decoration: BoxDecoration(
            border: Border.all(color: context.tokens.border),
            borderRadius: BorderRadius.circular(AppRadius.lg),
            boxShadow: AppShadow.xs,
          ),
          child: child,
        ),
      ),
    );
  }
}

/// Section heading with an optional trailing action.
class SectionHeader extends StatelessWidget {
  const SectionHeader({
    super.key,
    required this.title,
    this.actionLabel,
    this.onAction,
  });

  final String title;
  final String? actionLabel;
  final VoidCallback? onAction;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.s3),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          // Expanded so a long Arabic section title wraps instead of overflowing
          // the row (Row children are laid out at intrinsic width otherwise).
          Expanded(
            child: Text(title, style: Theme.of(context).textTheme.titleMedium),
          ),
          if (actionLabel != null)
            TextButton(onPressed: onAction, child: Text(actionLabel!)),
        ],
      ),
    );
  }
}
