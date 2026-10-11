import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/locale_controller.dart';
import '../../core/theme/app_colors.dart';
import '../../shared/widgets/brand_widgets.dart';
import '../auth/login_screen.dart';

/// Arabic-first onboarding. Three value-proposition slides, then sign-in.
///
/// The claims here mirror the shipped feature set only — no invented capability.
class OnboardingScreen extends StatefulWidget {
  const OnboardingScreen({super.key});

  @override
  State<OnboardingScreen> createState() => _OnboardingScreenState();
}

class _OnboardingScreenState extends State<OnboardingScreen> {
  final _controller = PageController();
  int _page = 0;

  static const _slides = [
    (
      icon: Icons.dashboard_customize_outlined,
      title: 'منصة قانونية متكاملة',
      body:
          'قضايا ومستندات واستشارات ومواعيد وفواتير وصلاحيات فريق — في مساحة واحدة.',
    ),
    (
      icon: Icons.auto_awesome_outlined,
      title: 'مساعد قانوني يعتمد المصادر',
      body:
          'يبحث أولاً في مكتبة الأنظمة، ويعرض المرجع والمادة عند توفّرها.',
    ),
    (
      icon: Icons.shield_outlined,
      title: 'بياناتك محميّة',
      body:
          'رموز مجزّأة، وصلاحيات دقيقة، وعزل بيانات بين المكاتب، وتنزيل محمي للمستندات.',
    ),
  ];

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final locale = context.watch<LocaleController>();

    return Scaffold(
      body: SafeArea(
        child: Column(
          children: [
            Align(
              alignment: AlignmentDirectional.centerEnd,
              child: Padding(
                padding: const EdgeInsets.all(8),
                child: TextButton(
                  onPressed: () => _goToLogin(context),
                  child: const Text('تخطٍّ'),
                ),
              ),
            ),
            Expanded(
              child: PageView.builder(
                controller: _controller,
                itemCount: _slides.length,
                onPageChanged: (i) => setState(() => _page = i),
                itemBuilder: (context, index) {
                  final slide = _slides[index];
                  return Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 28),
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        const BrandLogo(size: 96),
                        const SizedBox(height: 36),
                        Container(
                          width: 76,
                          height: 76,
                          decoration: BoxDecoration(
                            gradient: AppColors.cyanAccent,
                            borderRadius: BorderRadius.circular(22),
                          ),
                          child: Icon(slide.icon,
                              size: 34, color: AppColors.navyDeep),
                        ),
                        const SizedBox(height: 26),
                        Text(
                          slide.title,
                          textAlign: TextAlign.center,
                          style: Theme.of(context).textTheme.headlineMedium,
                        ),
                        const SizedBox(height: 12),
                        Text(
                          slide.body,
                          textAlign: TextAlign.center,
                          style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                                color: AppColors.textMuted,
                              ),
                        ),
                      ],
                    ),
                  );
                },
              ),
            ),
            Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: List.generate(
                _slides.length,
                (i) => AnimatedContainer(
                  duration: const Duration(milliseconds: 220),
                  margin: const EdgeInsets.symmetric(horizontal: 4),
                  width: _page == i ? 22 : 8,
                  height: 8,
                  decoration: BoxDecoration(
                    color: _page == i ? AppColors.teal : AppColors.borderStrong,
                    borderRadius: BorderRadius.circular(999),
                  ),
                ),
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(24, 26, 24, 20),
              child: Column(
                children: [
                  PrimaryButton(
                    label: _page == _slides.length - 1 ? 'ابدأ الآن' : 'التالي',
                    expand: true,
                    onPressed: () {
                      if (_page == _slides.length - 1) {
                        _goToLogin(context);
                      } else {
                        _controller.nextPage(
                          duration: const Duration(milliseconds: 260),
                          curve: Curves.easeOut,
                        );
                      }
                    },
                  ),
                  const SizedBox(height: 10),
                  TextButton(
                    onPressed: () => context.read<LocaleController>().toggle(),
                    child: Text(
                      locale.isRtl ? 'English' : 'العربية',
                      style: const TextStyle(color: AppColors.textMuted),
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  void _goToLogin(BuildContext context) {
    Navigator.of(context).push(
      MaterialPageRoute(builder: (_) => const LoginScreen()),
    );
  }
}
