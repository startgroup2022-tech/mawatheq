import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mawatheq_mobile/core/auth_controller.dart';
import 'package:mawatheq_mobile/core/locale_controller.dart';
import 'package:mawatheq_mobile/core/storage/token_storage.dart';
import 'package:mawatheq_mobile/core/theme/theme_controller.dart';
import 'package:mawatheq_mobile/features/account/account_screen.dart';
import 'package:provider/provider.dart';

/// The theme chooser was wired into the controller but had no entry point, so a
/// user could not actually switch themes. These tests pin the entry point and the
/// fact that choosing an option reaches the controller.

class _Prefs implements PrefsStore {
  String? stored;
  @override
  Future<void> saveThemeMode(String mode) async => stored = mode;
  @override
  Future<String?> readThemeMode() async => stored;
  @override
  Future<void> saveLocale(String locale) async {}
  @override
  Future<String> readLocale() async => 'ar';
  @override
  Future<void> saveOnboardingSeen(bool seen) async {}
  @override
  Future<bool> readOnboardingSeen() async => true;
}

Widget _screen({ThemeController? theme}) {
  return MultiProvider(
    providers: [
      ChangeNotifierProvider(create: (_) => LocaleController()),
      ChangeNotifierProvider(create: (_) => AuthController()),
      if (theme != null) ChangeNotifierProvider<ThemeController>.value(value: theme),
    ],
    child: const MaterialApp(home: Scaffold(body: AccountScreen())),
  );
}

void main() {
  testWidgets('the appearance setting is reachable from the settings list',
      (tester) async {
    final controller = ThemeController(prefs: _Prefs());
    await tester.pumpWidget(_screen(theme: controller));
    await tester.pump();

    expect(find.text('المظهر'), findsOneWidget);
    // The tile shows the current choice, which starts as "follow the system".
    expect(find.text('حسب النظام'), findsOneWidget);
  });

  testWidgets('choosing dark reaches the controller and is persisted',
      (tester) async {
    final prefs = _Prefs();
    final controller = ThemeController(prefs: prefs);
    await tester.pumpWidget(_screen(theme: controller));
    await tester.pump();

    await tester.tap(find.text('المظهر'));
    // pumpAndSettle is unusable here: the screen's invoice placeholder is an
    // animated skeleton, so the frame scheduler never goes idle. Pump enough
    // frames to finish the bottom-sheet transition instead.
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 400));

    // All three options are offered.
    expect(find.text('فاتح'), findsOneWidget);
    expect(find.text('داكن'), findsOneWidget);

    await tester.tap(find.text('داكن'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 400));

    expect(controller.mode, AppThemeMode.dark);
    expect(prefs.stored, 'dark');
  });

  testWidgets('the sheet closes after a choice, leaving the settings list',
      (tester) async {
    final controller = ThemeController(prefs: _Prefs());
    await tester.pumpWidget(_screen(theme: controller));
    await tester.pump();

    await tester.tap(find.text('المظهر'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 400));
    await tester.tap(find.text('فاتح'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 400));

    // Back on the list: the tile now reports the chosen mode.
    expect(find.text('فاتح'), findsOneWidget);
    expect(find.text('داكن'), findsNothing);
  });

  testWidgets('the screen still renders without a ThemeController',
      (tester) async {
    // Widget tests and any embedding that does not provide the controller must not
    // crash; the tile simply hides itself.
    await tester.pumpWidget(_screen());
    await tester.pump();

    expect(find.text('الإعدادات'), findsOneWidget);
    expect(find.text('المظهر'), findsNothing);
    expect(tester.takeException(), isNull);
  });
}
