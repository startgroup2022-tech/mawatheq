import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/locale_controller.dart';
import '../../core/network/models.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_tokens.dart';

/// Confirmation screen for a banner that points at an external address.
///
/// The URL is shown in full, and selectable, before anything leaves the app. It is
/// never opened automatically: the app carries no in-app browser, and silently
/// handing a visitor to an arbitrary operator-supplied address is not something a
/// consumer app should do without showing where they are going first.
class BannerLinkScreen extends StatelessWidget {
  const BannerLinkScreen({super.key, required this.banner});

  final HomeBanner banner;

  @override
  Widget build(BuildContext context) {
    final rtl = context.watch<LocaleController>().isRtl;
    final target = banner.linkTarget ?? '';

    return Scaffold(
      backgroundColor: context.tokens.bg,
      appBar: AppBar(
        backgroundColor: context.tokens.surface,
        surfaceTintColor: context.tokens.surface,
        title: Text(banner.title(rtl)),
      ),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          Text(
            banner.subtitle(rtl).isEmpty
                ? banner.title(rtl)
                : banner.subtitle(rtl),
            style: Theme.of(context).textTheme.titleLarge,
          ),
          const SizedBox(height: 14),
          Container(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: context.tokens.surface,
              borderRadius: BorderRadius.circular(AppRadius.lg),
              border: Border.all(color: context.tokens.border),
            ),
            child: Row(
              children: [
                const Icon(Icons.link, color: AppColors.teal),
                const SizedBox(width: 10),
                Expanded(
                  child: SelectableText(
                    target,
                    style: TextStyle(
                        color: context.tokens.textMuted, fontSize: 13),
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 14),
          Text(
            rtl
                ? 'هذا الرابط يقود إلى صفحة خارج التطبيق. تأكد من العنوان قبل المتابعة.'
                : 'This link leads to a page outside the app. Check the address '
                    'before continuing.',
            style: Theme.of(context)
                .textTheme
                .bodySmall
                ?.copyWith(color: context.tokens.textMuted),
          ),
        ],
      ),
    );
  }
}
