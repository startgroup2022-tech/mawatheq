import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mawatheq_mobile/core/theme/app_colors.dart';
import 'package:mawatheq_mobile/core/theme/app_theme.dart';
import 'package:mawatheq_mobile/core/theme/app_tokens.dart';
import 'package:mawatheq_mobile/shared/widgets/brand_widgets.dart';

/// Contrast maths on the tokens proves the palette is sound; these tests prove the
/// widgets actually consume it. A token that is correct but unused is exactly the
/// bug that shipped here (the dark badge helper existed and nothing called it), and
/// only a rendered widget catches that.

Widget _under(ThemeData theme, Widget child) => MaterialApp(
      theme: theme,
      home: Scaffold(body: Center(child: child)),
    );

/// Reads the resolved colour of a [Text] after theme inheritance.
Color? _textColor(WidgetTester tester, String text) =>
    tester.widget<Text>(find.text(text)).style?.color;

void main() {
  testWidgets('the dashboard number uses the dark heading, not navy',
      (tester) async {
    await tester.pumpWidget(_under(
      AppTheme.dark(),
      const DashboardCard(value: '12', label: 'قضية', icon: Icons.folder_outlined),
    ));

    // The regression was navy-on-dark, which is invisible. The number must come
    // from the theme instead.
    expect(_textColor(tester, '12'), AppThemeTokens.dark.heading);
    expect(_textColor(tester, '12'), isNot(AppColors.navy));
  });

  testWidgets('the dashboard number is unchanged in the light theme',
      (tester) async {
    await tester.pumpWidget(_under(
      AppTheme.light(),
      const DashboardCard(value: '12', label: 'قضية', icon: Icons.folder_outlined),
    ));

    // Non-regression: light mode must still show the navy it always did.
    expect(_textColor(tester, '12'), AppColors.navy);
  });

  testWidgets('an explicit accent still wins over the theme', (tester) async {
    await tester.pumpWidget(_under(
      AppTheme.dark(),
      const DashboardCard(value: '3', label: 'استشارة', accent: AppColors.gold),
    ));

    expect(_textColor(tester, '3'), AppColors.gold);
  });

  testWidgets('the status badge paints the dark wash, not the light pastel',
      (tester) async {
    await tester.pumpWidget(_under(
      AppTheme.dark(),
      const StatusBadge(status: 'paid'),
    ));

    final box = tester.widget<Container>(find.byType(Container).first);
    final decoration = box.decoration as BoxDecoration;
    expect(decoration.color, AppThemeTokens.dark.successBg);
    expect(decoration.color, isNot(AppColors.successBg));
  });

  testWidgets('the status badge keeps its light pastel in the light theme',
      (tester) async {
    await tester.pumpWidget(_under(
      AppTheme.light(),
      const StatusBadge(status: 'paid'),
    ));

    final box = tester.widget<Container>(find.byType(Container).first);
    final decoration = box.decoration as BoxDecoration;
    expect(decoration.color, AppColors.successBg);
  });

  testWidgets('a representative screen renders in dark mode without error',
      (tester) async {
    // Guards against the theme being wired only halfway: these composites read
    // tokens through the extension, and a missing provider or a null lookup would
    // throw here.
    await tester.pumpWidget(_under(
      AppTheme.dark(),
      Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          const GradientHeroCard(
            eyebrow: 'مواثيق',
            title: 'أهلاً بك',
            body: 'المساعد القانوني متاح',
            ctaLabel: 'ابدأ',
          ),
          const SizedBox(height: 12),
          const DashboardCard(value: '5', label: 'قضية'),
          const SizedBox(height: 12),
          const StatusBadge(status: 'in_review'),
        ],
      ),
    ));

    expect(tester.takeException(), isNull);
    expect(find.text('أهلاً بك'), findsOneWidget);
    expect(find.text('قيد المراجعة'), findsOneWidget);
  });

  testWidgets('both themes build without falling back to an error theme',
      (tester) async {
    for (final theme in [AppTheme.light(), AppTheme.dark()]) {
      expect(theme.colorScheme.brightness, theme.brightness);
      expect(theme.scaffoldBackgroundColor, isNotNull);
      expect(theme.textTheme.bodyMedium?.color, isNotNull);
    }
  });

  testWidgets('the theme actually drives the primary button fill',
      (tester) async {
    // The comment claimed dark mode lifted the navy button, but the code used the
    // brand navy in both themes. Assert the resolved fill differs per theme.
    await tester.pumpWidget(_under(
      AppTheme.dark(),
      FilledButton(onPressed: () {}, child: const Text('متابعة')),
    ));

    // The button itself carries no style and inherits from the theme, which is
    // exactly why the theme value is what has to be correct here.
    final darkFill =
        AppTheme.dark().filledButtonTheme.style?.backgroundColor?.resolve({});
    final lightFill =
        AppTheme.light().filledButtonTheme.style?.backgroundColor?.resolve({});

    expect(darkFill, AppThemeTokens.dark.brandFill);
    expect(lightFill, AppThemeTokens.light.brandFill);
    expect(darkFill, isNot(lightFill));
  });
}
