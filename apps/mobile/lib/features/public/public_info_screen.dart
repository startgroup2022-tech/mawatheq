import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/locale_controller.dart';
import '../../core/public_content.dart';
import '../../core/theme/app_colors.dart';
import '../../shared/widgets/brand_widgets.dart';

/// Which public information page to show.
enum InfoSection { services, about, contact }

/// Public information pages reachable without an account: the services overview,
/// about MAWATHEQ, and contact/support.
///
/// These are static, app-owned pages. They contain no user data and make no
/// protected API call, so a guest can read them safely.
class PublicInfoScreen extends StatelessWidget {
  const PublicInfoScreen({
    super.key,
    required this.section,
    this.highlight,
  });

  final InfoSection section;

  /// Optional capability the visitor tapped, highlighted in the services list.
  final String? highlight;

  @override
  Widget build(BuildContext context) {
    final rtl = context.watch<LocaleController>().isRtl;
    final title = switch (section) {
      InfoSection.services => rtl ? 'الخدمات والمميزات' : 'Services and features',
      InfoSection.about => rtl ? 'عن مواثيق' : 'About Mawatheq',
      InfoSection.contact => rtl ? 'تواصل معنا' : 'Contact us',
    };

    return Scaffold(
      backgroundColor: AppColors.bg,
      appBar: BrandAppBar(subtitle: title),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: switch (section) {
          InfoSection.services => _services(context, rtl),
          InfoSection.about => _about(context, rtl),
          InfoSection.contact => _contact(context, rtl),
        },
      ),
    );
  }

  List<Widget> _services(BuildContext context, bool rtl) {
    return [
      _card(
        context,
        title: PublicContent.pick(PublicContent.servicesTitle, rtl),
        body: PublicContent.pick(PublicContent.servicesSub, rtl),
      ),
      const SizedBox(height: 16),
      for (final s in PublicContent.services)
        _row(
          context,
          icon: Icons.check_circle_outline,
          label: rtl ? s.$2 : s.$3,
          highlighted: highlight == (rtl ? s.$2 : s.$3),
        ),
      const SizedBox(height: 18),
      SectionHeader(
        title: PublicContent.pick(PublicContent.featuresTitle, rtl),
      ),
      const SizedBox(height: 4),
      for (final f in PublicContent.features)
        _row(
          context,
          icon: Icons.circle_outlined,
          label: rtl ? f.$2 : f.$3,
          highlighted: highlight == (rtl ? f.$2 : f.$3),
        ),
    ];
  }

  List<Widget> _about(BuildContext context, bool rtl) => [
        _card(
          context,
          title: PublicContent.pick(PublicContent.aboutTitle, rtl),
          body: PublicContent.pick(PublicContent.aboutBody, rtl),
        ),
        const SizedBox(height: 16),
        _card(
          context,
          title: PublicContent.pick(PublicContent.securityTitle, rtl),
          body: PublicContent.pick(PublicContent.securityBody, rtl),
          icon: Icons.security_outlined,
        ),
      ];

  List<Widget> _contact(BuildContext context, bool rtl) => [
        _card(
          context,
          title: PublicContent.pick(PublicContent.contactTitle, rtl),
          body: PublicContent.pick(PublicContent.contactBody, rtl),
          icon: Icons.support_agent_outlined,
        ),
        const SizedBox(height: 16),
        _row(
          context,
          icon: Icons.mail_outline,
          label: PublicContent.supportEmail,
          highlighted: false,
        ),
      ];

  Widget _card(
    BuildContext context, {
    required String title,
    required String body,
    IconData? icon,
  }) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(AppSpacing.s5),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(AppRadius.xl),
        border: Border.all(color: AppColors.border),
        boxShadow: AppShadow.xs,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (icon != null) ...[
            Container(
              width: 44,
              height: 44,
              decoration: BoxDecoration(
                color: AppColors.infoBg,
                borderRadius: BorderRadius.circular(AppRadius.md),
              ),
              child: Icon(icon, size: 22, color: AppColors.teal),
            ),
            const SizedBox(height: 12),
          ],
          Text(title,
              style: Theme.of(context)
                  .textTheme
                  .titleLarge
                  ?.copyWith(color: AppColors.navy)),
          const SizedBox(height: 8),
          Text(body,
              style: Theme.of(context).textTheme.bodySmall?.copyWith(height: 1.8)),
        ],
      ),
    );
  }

  Widget _row(
    BuildContext context, {
    required IconData icon,
    required String label,
    required bool highlighted,
  }) {
    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.all(13),
      decoration: BoxDecoration(
        color: highlighted ? AppColors.infoBg : AppColors.surface,
        borderRadius: BorderRadius.circular(AppRadius.md),
        border: Border.all(
          color: highlighted ? AppColors.teal : AppColors.border,
        ),
      ),
      child: Row(
        children: [
          Icon(icon, size: 17,
              color: highlighted ? AppColors.teal : AppColors.textSubtle),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              label,
              style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                    fontWeight: highlighted ? FontWeight.w700 : FontWeight.w500,
                  ),
            ),
          ),
        ],
      ),
    );
  }
}
