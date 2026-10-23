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
///   * **Text direction is decided by the copy, not by the frame.** Each slide's
///     text is wrapped in its own [Directionality] set from the active locale, so an
///     Arabic headline is right-aligned and reads from the right edge even if the
///     ambient direction were ever wrong. This is separate from the *swipe* axis:
///     the [PageView] follows the ambient direction so a swipe toward the start of
///     the reading direction advances the carousel, and the two cannot disagree
///     because neither is derived from the other.
///   * **The two primary actions live inside every slide.** They used to sit in a
///     pair of cards under the slider, which the owner read as "two static cards
///     instead of a slider". They are now rendered on the banner itself, on every
///     slide, and the row under the slider is gone.
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
    this.onBook,
    this.onAskAssistant,
    this.height,
    this.autoAdvance = true,
  });

  final List<HomeBanner> banners;

  /// Invoked with the tapped slide. The caller decides where it goes, because the
  /// destination may need to leave the current tab.
  final void Function(HomeBanner banner)? onTap;

  /// The in-banner primary action: open the consultation booking flow.
  final VoidCallback? onBook;

  /// The in-banner secondary action: open the AI legal assistant.
  final VoidCallback? onAskAssistant;

  /// Fixed frame height. When null the carousel derives one from its width, so a
  /// narrow phone gets a proportionally shorter banner instead of a letterbox.
  final double? height;

  /// Disabled in widget tests so a pending timer does not outlive the test.
  final bool autoAdvance;

  @override
  State<BannerCarousel> createState() => _BannerCarouselState();
}

class _BannerCarouselState extends State<BannerCarousel> {
  final _controller = PageController();
  Timer? _timer;
  int _page = 0;

  /// How long a slide stays on screen before the next one. Inside the 4-5 second
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

  /// The frame height for a given width.
  ///
  /// The slide has to hold an eyebrow, a two-line headline, a two-line subtitle and
  /// the action row. A fixed height that fits the longest slide would letterbox a
  /// short one, so the height tracks the width and is clamped at both ends: never
  /// shorter than the content needs, never so tall that the slider eats the screen.
  static double _heightFor(double width, double? fixed) {
    if (fixed != null) return fixed;
    return (width * 0.62).clamp(206.0, 268.0);
  }

  @override
  Widget build(BuildContext context) {
    if (widget.banners.isEmpty) return const SizedBox.shrink();

    final rtl = context.watch<LocaleController>().isRtl;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        LayoutBuilder(
          builder: (context, constraints) {
            final h = _heightFor(constraints.maxWidth, widget.height);
            return SizedBox(
              height: h,
              child: PageView.builder(
                controller: _controller,
                itemCount: widget.banners.length,
                onPageChanged: _onPageChanged,
                itemBuilder: (context, i) => _slide(widget.banners[i], rtl),
              ),
            );
          },
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
    // A built-in slide is always tappable; an operator banner may have no destination.
    // The hint follows the same rule as the InkWell, so a slide never advertises a tap
    // it does not handle.
    final showMore = banner.isTappable && widget.onTap != null;
    // Arabic copy is right-aligned even if the frame were ever laid out LTR. The
    // direction is taken from the locale, and the copy on a slide is always in the
    // active locale, so the two cannot disagree.
    final textDirection = rtl ? TextDirection.rtl : TextDirection.ltr;

    final content = Stack(
      fit: StackFit.expand,
      children: [
        // A built-in slide carries a bundled photo; an operator slide shows the
        // uploaded image and degrades to a brand gradient when the file is missing,
        // so the frame is never a red error box.
        _background(banner, url),
        // The text sits on top of an arbitrary photo, so it gets its own scrim
        // rather than relying on the image being dark enough. It runs from the
        // reading edge — the right in Arabic — where the copy is anchored.
        DecoratedBox(
          decoration: BoxDecoration(
            gradient: LinearGradient(
              begin: rtl ? Alignment.centerRight : Alignment.centerLeft,
              end: rtl ? Alignment.centerLeft : Alignment.centerRight,
              colors: [
                AppColors.navyDark.withValues(alpha: 0.93),
                AppColors.navyDark.withValues(alpha: 0.42),
                AppColors.navyDark.withValues(alpha: 0.18),
              ],
              stops: const [0.0, 0.55, 1.0],
            ),
          ),
        ),
        // A bottom band keeps the action row legible over a bright lower third.
        DecoratedBox(
          decoration: BoxDecoration(
            gradient: LinearGradient(
              begin: Alignment.bottomCenter,
              end: Alignment.topCenter,
              colors: [
                AppColors.navyDeep.withValues(alpha: 0.55),
                Colors.transparent,
              ],
              stops: const [0.0, 0.55],
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
              stops: const [0.0, 0.40],
            ),
          ),
        ),
        LayoutBuilder(
          builder: (context, constraints) {
            // A single consistent inline padding, applied on both edges, so the
            // Arabic copy starts at a predictable distance from the right edge.
            const pad = AppSpacing.s5;
            final innerWidth =
                (constraints.maxWidth - pad * 2).clamp(0.0, double.infinity);
            return Padding(
              padding: const EdgeInsets.symmetric(
                horizontal: pad,
                vertical: AppSpacing.s3,
              ),
              child: Directionality(
                textDirection: textDirection,
                child: Column(
                  // Bottom-aligned: the eyebrow stays at the top and the copy sits on
                  // the bottom edge, with the actions beneath it.
                  mainAxisAlignment: MainAxisAlignment.end,
                  // `start`, never `end`: the cross axis of a Column is horizontal,
                  // and this Column is inside a [Directionality], so `start` resolves
                  // to the right in Arabic and the left in English — the reading
                  // edge in both. `end` is the opposite and put an Arabic headline
                  // hard against the LEFT edge, but only when it did not wrap: a
                  // title long enough to fill the width hides the misalignment,
                  // which is why the shorter slides were the ones that looked wrong.
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    // The top row: the eyebrow on the reading edge, and — only for a
                    // slide that actually has a destination — the "learn more" hint on
                    // the far edge. The hint is the visual affordance for the whole
                    // slide being tappable; without it a tappable banner looks the same
                    // as a decorative one.
                    if (eyebrow.isNotEmpty || showMore) ...[
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          if (eyebrow.isNotEmpty)
                            Flexible(child: _eyebrow(eyebrow))
                          else
                            const SizedBox.shrink(),
                          if (showMore) _learnMore(rtl),
                        ],
                      ),
                      const SizedBox(height: 6),
                    ],
                    // Only the copy is allowed to scale: the action row keeps its
                    // real size so its touch targets are never shrunk to fit.
                    Flexible(
                      child: FittedBox(
                        fit: BoxFit.scaleDown,
                        alignment:
                            rtl ? Alignment.bottomRight : Alignment.bottomLeft,
                        child: SizedBox(
                          width: innerWidth,
                          child: Column(
                            mainAxisSize: MainAxisSize.min,
                            // Same rule as the outer column: `start` is the reading
                            // edge once the ambient [Directionality] resolves it.
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Semantics(
                                header: true,
                                child: Text(
                                  title,
                                  textAlign:
                                      rtl ? TextAlign.right : TextAlign.left,
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
                                const SizedBox(height: 5),
                                Text(
                                  subtitle,
                                  textAlign:
                                      rtl ? TextAlign.right : TextAlign.left,
                                  maxLines: 2,
                                  overflow: TextOverflow.ellipsis,
                                  style: TextStyle(
                                    color: Colors.white.withValues(alpha: 0.92),
                                    fontSize: 12.5,
                                    height: 1.45,
                                  ),
                                ),
                              ],
                            ],
                          ),
                        ),
                      ),
                    ),
                    const SizedBox(height: AppSpacing.s3),
                    // Both primary actions, inside the banner, on every slide.
                    _actions(rtl),
                  ],
                ),
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

  /// The two primary actions, drawn on the banner itself.
  ///
  /// A [Row] of [Flexible] children rather than [Expanded]: each button takes the
  /// width its label needs and only shrinks if the pair cannot fit, so on a normal
  /// phone neither label is truncated and on a very narrow one neither overflows the
  /// frame. That keeps the banner one row tall — stacking them would make it
  /// noticeably taller on exactly the screens with the least room.
  Widget _actions(bool rtl) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Flexible(
          child: _SlideButton(
            icon: Icons.event_available_outlined,
            label: rtl ? 'احجز استشارة' : 'Book a consultation',
            primary: true,
            onTap: widget.onBook,
          ),
        ),
        const SizedBox(width: AppSpacing.s2),
        Flexible(
          child: _SlideButton(
            icon: Icons.auto_awesome_outlined,
            label: rtl ? 'اسأل المساعد الذكي' : 'Ask the AI assistant',
            primary: false,
            onTap: widget.onAskAssistant,
          ),
        ),
      ],
    );
  }

  /// The background layer: a bundled photo for a built-in slide, an uploaded photo
  /// for an operator banner, or a brand gradient as the fallback.
  Widget _background(HomeBanner banner, String? url) {
    if (url != null) {
      return Image.network(
        url,
        fit: BoxFit.cover,
        // A banner image is decorative here: the title is drawn over it and is what
        // a screen reader announces, so the image itself is excluded.
        excludeFromSemantics: true,
        loadingBuilder: (context, child, progress) => progress == null
            ? child
            : _gradientFor(banner.gradientKey, banner.iconKey),
        errorBuilder: (_, __, ___) =>
            _gradientFor(banner.gradientKey, banner.iconKey),
      );
    }
    if (banner.assetImage.isNotEmpty) {
      return Image.asset(
        banner.assetImage,
        fit: BoxFit.cover,
        excludeFromSemantics: true,
        errorBuilder: (_, __, ___) =>
            _gradientFor(banner.gradientKey, banner.iconKey),
      );
    }
    return _gradientFor(banner.gradientKey, banner.iconKey);
  }

  /// A brand gradient with a large, low-opacity watermark icon. This is the fallback
  /// for an operator photo that fails to load, and for a built-in slide whose bundled
  /// asset is missing.
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

  /// The "learn more" affordance shown on a slide that has a destination.
  Widget _learnMore(bool rtl) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Text(
          rtl ? 'اعرف المزيد' : 'Learn more',
          style: TextStyle(
            color: Colors.white.withValues(alpha: 0.94),
            fontWeight: FontWeight.w700,
            fontSize: 11.5,
          ),
        ),
        const SizedBox(width: 3),
        Icon(
          rtl ? Icons.arrow_back : Icons.arrow_forward,
          size: 14,
          color: Colors.white.withValues(alpha: 0.94),
        ),
      ],
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
}

/// A compact primary-action button drawn on a banner.
///
/// Deliberately not a stock `FilledButton`: the default minimum height and padding
/// are sized for a full-width form button and would make the banner tall. This keeps
/// a 40pt control inside a 44pt tap target, which clears WCAG 2.5.8 with room to
/// spare while staying visually light over a photograph.
class _SlideButton extends StatelessWidget {
  const _SlideButton({
    required this.icon,
    required this.label,
    required this.primary,
    required this.onTap,
  });

  final IconData icon;
  final String label;
  final bool primary;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      label: label,
      child: Material(
        color: primary ? AppColors.teal : Colors.white.withValues(alpha: 0.16),
        borderRadius: BorderRadius.circular(AppRadius.pill),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: onTap,
          child: Container(
            constraints: const BoxConstraints(minHeight: 40),
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 9),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(AppRadius.pill),
              border: primary
                  ? null
                  : Border.all(color: Colors.white.withValues(alpha: 0.55)),
            ),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(icon, size: 16, color: Colors.white),
                const SizedBox(width: 6),
                Flexible(
                  child: Text(
                    label,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                      color: Colors.white,
                      fontWeight: FontWeight.w700,
                      fontSize: 12.5,
                    ),
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
