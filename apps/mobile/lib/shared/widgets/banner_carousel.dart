import 'dart:async';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/config/app_config.dart';
import '../../core/locale_controller.dart';
import '../../core/network/models.dart';
import '../../core/theme/app_colors.dart';

/// The home-screen banner carousel, fed by `GET /api/v1/banners.php`.
///
/// Renders nothing at all when the server sends no banners. That is deliberate: a
/// fresh install has none, and the brief forbids filling the gap with an invented
/// promotion or a placeholder graphic. The home screen simply continues with the
/// content below, which is what a consumer expects — not an empty framed box.
///
/// Images are operator-uploaded and served from the site root, so a broken or
/// missing file degrades to a titled colour block rather than a red error box.
class BannerCarousel extends StatefulWidget {
  const BannerCarousel({
    super.key,
    required this.banners,
    this.onTap,
    this.height = 168,
    this.autoAdvance = true,
  });

  final List<HomeBanner> banners;

  /// Invoked with the tapped banner. The caller decides where it goes, because the
  /// destination may need to leave the current tab.
  final void Function(HomeBanner banner)? onTap;

  final double height;

  /// Disabled in widget tests so a pending timer does not outlive the test.
  final bool autoAdvance;

  @override
  State<BannerCarousel> createState() => _BannerCarouselState();
}

class _BannerCarouselState extends State<BannerCarousel> {
  final _controller = PageController();
  Timer? _timer;
  int _page = 0;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    // Scheduled here rather than in initState because the reduced-motion preference
    // lives in MediaQuery, which is not readable during initState. Auto-advancing
    // content must also be pausable (WCAG 2.2.2), and a visitor who has asked the
    // system for less motion gets a static, swipeable banner.
    if (widget.autoAdvance && !MediaQuery.disableAnimationsOf(context)) {
      _schedule();
    } else {
      _timer?.cancel();
      _timer = null;
    }
  }

  @override
  void didUpdateWidget(covariant BannerCarousel old) {
    super.didUpdateWidget(old);
    // A shorter list can leave the controller past its last page; without this the
    // carousel shows a blank slide after an operator hides a banner mid-session.
    if (_page >= widget.banners.length) {
      _page = 0;
      if (_controller.hasClients) _controller.jumpToPage(0);
    }
  }

  /// A single re-armed timer rather than a recursive `Future.delayed`. A pending
  /// delay cannot be cancelled, so it would outlive [dispose] and — in a widget test —
  /// fail the test with a pending timer even though the widget is gone.
  void _schedule() {
    _timer?.cancel();
    if (widget.banners.length < 2) return;
    _timer = Timer(const Duration(seconds: 6), () {
      if (!mounted || !_controller.hasClients) return;
      final next = (_page + 1) % widget.banners.length;
      _controller.animateToPage(
        next,
        duration: const Duration(milliseconds: 420),
        curve: Curves.easeOutCubic,
      );
      _schedule();
    });
  }

  @override
  void dispose() {
    _timer?.cancel();
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (widget.banners.isEmpty) return const SizedBox.shrink();

    final rtl = context.watch<LocaleController>().isRtl;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        SizedBox(
          height: widget.height,
          child: PageView.builder(
            controller: _controller,
            itemCount: widget.banners.length,
            onPageChanged: (i) => setState(() => _page = i),
            itemBuilder: (context, i) => _slide(widget.banners[i], rtl),
          ),
        ),
        if (widget.banners.length > 1) ...[
          const SizedBox(height: 10),
          Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: List.generate(widget.banners.length, (i) {
              final active = i == _page;
              return AnimatedContainer(
                duration: const Duration(milliseconds: 220),
                margin: const EdgeInsets.symmetric(horizontal: 3),
                width: active ? 18 : 6,
                height: 6,
                decoration: BoxDecoration(
                  color: active ? AppColors.teal : AppColors.borderStrong,
                  borderRadius: BorderRadius.circular(AppRadius.pill),
                ),
              );
            }),
          ),
        ],
      ],
    );
  }

  Widget _slide(HomeBanner banner, bool rtl) {
    final url = banner.imageUrl(AppConfig.siteBase);
    final title = banner.title(rtl);
    final subtitle = banner.subtitle(rtl);

    final content = Stack(
      fit: StackFit.expand,
      children: [
        if (url != null)
          Image.network(
            url,
            fit: BoxFit.cover,
            // A banner image is decorative here: the title is drawn over it and is
            // what a screen reader announces, so the image itself is excluded.
            excludeFromSemantics: true,
            loadingBuilder: (context, child, progress) =>
                progress == null ? child : _placeholder(title),
            errorBuilder: (_, __, ___) => _placeholder(title),
          )
        else
          _placeholder(title),
        // The text sits on top of an arbitrary operator photo, so it gets its own
        // scrim rather than relying on the image being dark enough.
        DecoratedBox(
          decoration: BoxDecoration(
            gradient: LinearGradient(
              begin: rtl ? Alignment.centerRight : Alignment.centerLeft,
              end: rtl ? Alignment.centerLeft : Alignment.centerRight,
              colors: [
                AppColors.navyDark.withValues(alpha: 0.88),
                AppColors.navyDark.withValues(alpha: 0.25),
              ],
            ),
          ),
        ),
        Padding(
          padding: const EdgeInsets.all(AppSpacing.s5),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.end,
            crossAxisAlignment:
                rtl ? CrossAxisAlignment.end : CrossAxisAlignment.start,
            children: [
              Semantics(
                header: true,
                child: Text(
                  title,
                  textAlign: rtl ? TextAlign.right : TextAlign.left,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    color: Colors.white,
                    fontWeight: FontWeight.w800,
                    fontSize: 18,
                    height: 1.3,
                  ),
                ),
              ),
              if (subtitle.isNotEmpty) ...[
                const SizedBox(height: 6),
                Text(
                  subtitle,
                  textAlign: rtl ? TextAlign.right : TextAlign.left,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    color: Colors.white.withValues(alpha: 0.9),
                    fontSize: 13,
                    height: 1.5,
                  ),
                ),
              ],
              if (banner.isTappable) ...[
                const SizedBox(height: 10),
                Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(
                      rtl ? 'اعرف المزيد' : 'Learn more',
                      style: const TextStyle(
                        color: AppColors.cyan,
                        fontWeight: FontWeight.w700,
                        fontSize: 13,
                      ),
                    ),
                    Icon(
                      rtl ? Icons.arrow_back : Icons.arrow_forward,
                      size: 16,
                      color: AppColors.cyan,
                    ),
                  ],
                ),
              ],
            ],
          ),
        ),
      ],
    );

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 2),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(AppRadius.xl),
        child: Material(
          color: AppColors.navy,
          child: banner.isTappable && widget.onTap != null
              ? InkWell(
                  onTap: () => widget.onTap!(banner),
                  // The whole banner is one tap target with one label; without this
                  // a screen reader reads the title and the "learn more" hint as two.
                  child: Semantics(
                    button: true,
                    label: banner.alt(rtl),
                    child: content,
                  ),
                )
              : content,
        ),
      ),
    );
  }

  /// Shown while the image loads, and when it fails. Carries the title so the slide
  /// is never a blank rectangle.
  Widget _placeholder(String title) {
    return DecoratedBox(
      decoration: const BoxDecoration(gradient: AppColors.brandHero),
      child: Center(
        child: Icon(Icons.balance_outlined,
            size: 34, color: Colors.white.withValues(alpha: 0.35)),
      ),
    );
  }
}
