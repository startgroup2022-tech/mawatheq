import 'package:flutter/material.dart';

import '../../core/theme/app_colors.dart';
import '../../core/theme/app_tokens.dart';
import '../../shared/widgets/brand_widgets.dart';

/// The branded splash shown while the stored session is validated.
///
/// It uses the owner's original logo unchanged on a very light brand surface with
/// a restrained cyan/turquoise wash, and plays one short entrance animation (fade
/// plus a gentle scale and rise). There is no artificial hold: the caller swaps to
/// the next screen as soon as the session check finishes, so the splash is never
/// the reason the app feels slow.
class SplashScreen extends StatefulWidget {
  const SplashScreen({super.key, this.onAnimationComplete});

  /// Called once the entrance animation has finished, so the caller can route
  /// even when the session check resolved earlier.
  final VoidCallback? onAnimationComplete;

  @override
  State<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends State<SplashScreen>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 720),
  );

  late final Animation<double> _fade = CurvedAnimation(
    parent: _controller,
    curve: const Interval(0.0, 0.7, curve: Curves.easeOut),
  );

  late final Animation<double> _scale = Tween<double>(begin: 0.86, end: 1.0).animate(
    CurvedAnimation(parent: _controller, curve: Curves.easeOutBack),
  );

  late final Animation<double> _rise = Tween<double>(begin: 14, end: 0).animate(
    CurvedAnimation(parent: _controller, curve: Curves.easeOutCubic),
  );

  @override
  void initState() {
    super.initState();
    _controller.forward().whenComplete(() => widget.onAnimationComplete?.call());
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: context.tokens.surface,
      body: DecoratedBox(
        decoration: BoxDecoration(gradient: context.tokens.splashGradient),
        child: SafeArea(
          child: Center(
            child: AnimatedBuilder(
              animation: _controller,
              builder: (context, _) {
                return Opacity(
                  opacity: _fade.value,
                  child: Transform.translate(
                    offset: Offset(0, _rise.value),
                    child: Transform.scale(
                      scale: _scale.value,
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          // The original mark, unchanged.
                          const BrandLogo(size: 104),
                          const SizedBox(height: 22),
                          Text(
                            'مواثيق',
                            style: TextStyle(
                              fontSize: 30,
                              fontWeight: FontWeight.w800,
                              color: context.tokens.heading,
                              letterSpacing: 0.4,
                            ),
                          ),
                          const SizedBox(height: 8),
                          Text(
                            'منصة قانونية متكاملة',
                            style: TextStyle(
                              fontSize: AppFontSize.sm,
                              color: context.tokens.textMuted,
                              fontWeight: FontWeight.w500,
                            ),
                          ),
                          const SizedBox(height: 30),
                          // A thin brand rule instead of a generic spinner: the
                          // wait is short, so a quiet accent is enough.
                          SizedBox(
                            width: 132,
                            height: 3,
                            child: ClipRRect(
                              borderRadius: BorderRadius.circular(AppRadius.pill),
                              child: const _BrandProgress(),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                );
              },
            ),
          ),
        ),
      ),
    );
  }
}

/// A subtle cyan→turquoise sweep used as the splash progress accent.
class _BrandProgress extends StatefulWidget {
  const _BrandProgress();

  @override
  State<_BrandProgress> createState() => _BrandProgressState();
}

class _BrandProgressState extends State<_BrandProgress>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1100),
  )..repeat();

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: _controller,
      builder: (context, _) {
        return Stack(
          children: [
            Container(color: context.tokens.surface3),
            FractionallySizedBox(
              widthFactor: 0.45,
              alignment: Alignment(-1 + 2 * _controller.value, 0),
              child: Container(
                decoration: const BoxDecoration(gradient: AppColors.cyanAccent),
              ),
            ),
          ],
        );
      },
    );
  }
}
