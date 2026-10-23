import 'dart:async';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/config/app_config.dart';
import '../../core/locale_controller.dart';
import '../../core/network/models.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_tokens.dart';
import '../specialty_icons.dart';

/// The home-screen promotional carousel.
///
/// This is a real slider: one slide fills the frame at a time, it advances on its
/// own every few seconds, the customer can swipe it by hand, and the page dots
/// track where they are. It is fed by `GET /api/v1/banners.php`; when the operator
/// has published no banners — the normal state, because `home_banners` ships empty
/// — the caller passes the built-in Mawatheq slides so the top of the home screen is
/// never a bare pair of cards.
///
/// Design notes that are load-bearing:
///
///   * Direction follows the ambient [LocaleController]. Arabic is RTL, so a swipe
///     toward the start of the reading direction advances the carousel; a
///     [PageView] inside a `Directionality` already reverses its scroll axis, so no
///     manual offset maths is needed and the two directions cannot disagree.
///   * Auto-advance is a single cancellable [Timer], re-armed after each move. A
///     recursive `Future.delayed` cannot be cancelled and would outlive [dispose].
///   * It is paused when the platform asks for reduced motion (WCAG 2.2.2) and in
///     widget tests, so a pending timer never fails a test.
///   * The text sits on an arbitrary photo, so every slide draws its own scrim; it
///     never relies on the image being dark enough.
class BannerCarousel extends StatefulWidget {
  const BannerCarousel({
    super.key,
    required this.banners,
    this.onTap,
    this.height = 176,
    this.autoAdvance = true,
  });

  final List<HomeBanner> banners;

  /// Invoked with the tapped slide. The caller decides where it goes, because the
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

  /// How long a slide stays on screen before the next one. Inside the 4–5 second
  /// window the brief asks for: long enough to read a headline, short enough that a
  /// visitor sees the slider move without waiting.
  static const _interval = Duration(milliseconds: 4500);
  static const _transition = Duration(milliseconds: 480);

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    // Scheduled here rather than in initState because the reduced-motion preference
    // lives in MediaQuery, which is not readable during initState.
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

  /// A single re-armed timer rather than a recursive `Future.delayed`.
  void _schedule() {
    _timer?.cancel();
    if (widget.banners.length < 2) return;
    _timer = Timer(_interval, () {
      if (!mounted || !_controller.hasClients) return;
      final next = (_page + 1) % widget.banners.length;
      _controller.animateToPage(
        next,
        duration: _transition,
        curve: Curves.easeInOutCubic,
      );
      _schedule();
    });
  }

  /// A manual swipe resets the dwell timer, so a slide the customer chose is not
  /// yanked away a moment later by a tick that was already counting down.
  void _onPageChanged(int i) {
    setState(() => _page = i);
    if (widget.autoAdvance && !MediaQuery.disableAnimationsOf(context)) {
      _schedule();
    }
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
            onPageChanged: _onPageChanged,
            itemBuilder: (context, i) => _slide(widget.banners[i], rtl),
          ),
        ),
        if (widget.banners.length > 1) ...[
          const SizedBox(height: 12),
          _Dots(count: widget.banners.length, active: _page),
        ],
      ],
    );
  }

  Widget _slide(HomeBanner banner, bool rtl) {
    final url = banner.imageUrl(AppConfig.siteBase);
    final title = banner.title(rtl);
    final subtitle = banner.subtitle(rtl);
    final eyebrow = banner.eyebrow(rtl);

    final content = Stack(
      fit: StackFit.expand,
      children: [
        // A built-in slide has no photo; it paints a brand gradient. An operator
        // slide shows the uploaded image and degrades to the same gradient when the
        // file is missing, so the frame is never a red error box.
        _background(banner, url),
        // The text sits on top of an arbitrary photo, so it gets its own scrim
        // rather than relying on the image being dark enough.
        DecoratedBox(
          decoration: BoxDecoration(
            gradient: LinearGradient(
              begin: rtl ? Alignment.centerRight : Alignment.centerLeft,
              end: rtl ? Alignment.centerLeft : Alignment.centerRight,
              colors: [
                AppColors.navyDark.withValues(alpha: 0.90),
                AppColors.navyDark.withValues(alpha: 0.20),
              ],
            ),
          ),
        ),
        // A soft top highlight keeps the brand mark legible over a bright photo.
        DecoratedBox(
          decoration: BoxDecoration(
            gradient: LinearGradient(
              begin: Alignment.topCenter,
              end: Alignment.bottomCenter,
              colors: [
                AppColors.navyDeep.withValues(alpha: 0.35),
                Colors.transparent,
              ],
              stops: const [0.0, 0.45],
            ),
          ),
        ),
        // The copy is bottom-aligned under the eyebrow. It is laid out at the
        // slide's real width (so Arabic wraps where the design intends) and then
        // scaled down only if the text block is taller than the frame allows. A
        // fixed-height frame with four text rows of unknown length would otherwise
        // overflow on a narrow phone — a yellow-and-black bar in debug and clipped
        // text in release.
        LayoutBuilder(
          builder: (context, constraints) {
            final innerWidth =
                (constraints.maxWidth - AppSpacing.s5 * 2).clamp(0.0, double.infinity);
            return Padding(
              padding: const EdgeInsets.all(AppSpacing.s5),
              child: Column(
                // Bottom-aligned: the eyebrow stays at the top and the copy sits on
                // the bottom edge. A `Spacer` here would compete with the `Flexible`
                // below for the free space and shrink the copy to nothing.
                mainAxisAlignment: MainAxisAlignment.end,
                crossAxisAlignment:
                    rtl ? CrossAxisAlignment.end : CrossAxisAlignment.start,
                children: [
                  // The eyebrow badge names what the slide is about.
                  if (eyebrow.isNotEmpty) ...[
                    _eyebrow(eyebrow),
                    const SizedBox(height: 8),
                  ],
                  Flexible(
                    child: FittedBox(
                      fit: BoxFit.scaleDown,
                      alignment:
                          rtl ? Alignment.bottomRight : Alignment.bottomLeft,
                      child: SizedBox(
                        width: innerWidth,
                        child: Column(
                          mainAxisSize: MainAxisSize.min,
                          crossAxisAlignment: rtl
                              ? CrossAxisAlignment.end
                              : CrossAxisAlignment.start,
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
                                  fontSize: 19,
                                  height: 1.3,
                                  letterSpacing: -0.2,
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
                                  color: Colors.white.withValues(alpha: 0.92),
                                  fontSize: 13,
                                  height: 1.5,
                                ),
                              ),
                            ],
                            if (banner.isTappable) ...[
                              const SizedBox(height: 12),
                              _cta(rtl),
                            ],
                          ],
                        ),
                      ),
                    ),
                  ),
                ],
              ),
            );
          },
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
                  // The whole slide is one tap target with one label; without this
                  // a screen reader reads the title and the hint as two controls.
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

  /// The background layer: a photo for an operator banner, a brand gradient for a
  /// built-in slide or a broken image.
  Widget _background(HomeBanner banner, String? url) {
    if (url == null) return _gradientFor(banner.gradientKey, banner.iconKey);
    return Image.network(
      url,
      fit: BoxFit.cover,
      // A banner image is decorative here: the title is drawn over it and is what a
      // screen reader announces, so the image itself is excluded.
      excludeFromSemantics: true,
      loadingBuilder: (context, child, progress) =>
          progress == null ? child : _gradientFor(banner.gradientKey, banner.iconKey),
      errorBuilder: (_, __, ___) =>
          _gradientFor(banner.gradientKey, banner.iconKey),
    );
  }

  /// A brand gradient with a large, low-opacity watermark icon. This is the look of
  /// a built-in slide, and the fallback for an operator photo that fails to load.
  Widget _gradientFor(String gradientKey, String iconKey) {
    final gradient = switch (gradientKey) {
      'teal' => const LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [AppColors.navy, AppColors.teal],
        ),
      'deep' => const LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [AppColors.navyDeep, AppColors.navy],
        ),
      'gold' => const LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [AppColors.navyDeep, AppColors.goldDeep],
        ),
      _ => AppColors.brandHero,
    };
    return DecoratedBox(
      decoration: BoxDecoration(gradient: gradient),
      child: Align(
        // The watermark sits at the trailing edge so it never sits under the copy.
        alignment: Alignment.centerLeft,
        child: Padding(
          padding: const EdgeInsets.only(left: 24),
          child: Icon(
            specialtyIcon(iconKey),
            size: 116,
            color: Colors.white.withValues(alpha: 0.14),
          ),
        ),
      ),
    );
  }

  Widget _eyebrow(String text) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 11, vertical: 5),
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: 0.18),
        borderRadius: BorderRadius.circular(AppRadius.pill),
        border: Border.all(color: Colors.white.withValues(alpha: 0.30)),
      ),
      child: Text(
        text,
        style: const TextStyle(
          color: Colors.white,
          fontWeight: FontWeight.w700,
          fontSize: 11.5,
        ),
      ),
    );
  }

  Widget _cta(bool rtl) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
      decoration: BoxDecoration(
        color: AppColors.teal,
        borderRadius: BorderRadius.circular(AppRadius.pill),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(
            rtl ? 'اعرف المزيد' : 'Learn more',
            style: const TextStyle(
              color: Colors.white,
              fontWeight: FontWeight.w700,
              fontSize: 12.5,
            ),
          ),
          const SizedBox(width: 4),
          Icon(
            rtl ? Icons.arrow_back : Icons.arrow_forward,
            size: 15,
            color: Colors.white,
          ),
        ],
      ),
    );
  }
}

/// The pagination dots. The active dot stretches into a pill and animates between
/// positions, so the current slide is obvious without a number.
class _Dots extends StatelessWidget {
  const _Dots({required this.count, required this.active});

  final int count;
  final int active;

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.center,
      children: List.generate(count, (i) {
        final on = i == active;
        return AnimatedContainer(
          duration: const Duration(milliseconds: 260),
          curve: Curves.easeOutCubic,
          margin: const EdgeInsets.symmetric(horizontal: 3),
          width: on ? 20 : 6,
          height: 6,
          decoration: BoxDecoration(
            color: on ? AppColors.teal : context.tokens.borderStrong,
            borderRadius: BorderRadius.circular(AppRadius.pill),
          ),
        );
      }),
    );
  }
}
