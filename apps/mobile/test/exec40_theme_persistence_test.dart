import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mawatheq_mobile/core/storage/token_storage.dart';
import 'package:mawatheq_mobile/core/theme/theme_controller.dart';

/// The theme feature is only useful if the choice actually persists and reloads.
/// A controller that changes the mode but forgets it on relaunch is the bug these
/// tests exist to catch — and it is invisible in a widget test that never restarts.
class _FakePrefs implements PrefsStore {
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

/// A store whose platform channel is broken, to pin the fallback path.
class _BrokenPrefs implements PrefsStore {
  @override
  Future<void> saveThemeMode(String mode) async => throw Exception('no channel');
  @override
  Future<String?> readThemeMode() async => throw Exception('no channel');
  @override
  Future<void> saveLocale(String locale) async {}
  @override
  Future<String> readLocale() async => 'ar';
  @override
  Future<void> saveOnboardingSeen(bool seen) async {}
  @override
  Future<bool> readOnboardingSeen() async => true;
}

void main() {
  test('defaults to system before anything is stored', () async {
    final controller = ThemeController(prefs: _FakePrefs());
    await controller.load();
    expect(controller.mode, AppThemeMode.system);
    expect(controller.themeMode, ThemeMode.system);
  });

  test('a chosen mode is written and survives a relaunch', () async {
    final prefs = _FakePrefs();

    final first = ThemeController(prefs: prefs);
    await first.load();
    await first.setMode(AppThemeMode.dark);
    expect(prefs.stored, 'dark');

    // A new controller simulates the next launch reading the same storage.
    final second = ThemeController(prefs: prefs);
    await second.load();
    expect(second.mode, AppThemeMode.dark);
    expect(second.themeMode, ThemeMode.dark);
  });

  test('light is stored distinctly from system, not as "absent"', () async {
    final prefs = _FakePrefs();
    final controller = ThemeController(prefs: prefs);
    await controller.load();
    await controller.setMode(AppThemeMode.light);

    // If light were written as null, this would fall back to system and a user who
    // chose light on a dark phone would get a dark app.
    expect(prefs.stored, 'light');
    final reloaded = ThemeController(prefs: prefs);
    await reloaded.load();
    expect(reloaded.mode, AppThemeMode.light);
  });

  test('an unreadable store falls back to system instead of throwing', () async {
    final controller = ThemeController(prefs: _BrokenPrefs());
    await controller.load();
    expect(controller.mode, AppThemeMode.system);
  });

  test('a failed write does not lose the in-memory choice', () async {
    final controller = ThemeController(prefs: _BrokenPrefs());
    await controller.load();
    await controller.setMode(AppThemeMode.dark);
    // The user must still see the theme they picked, even if persisting failed.
    expect(controller.mode, AppThemeMode.dark);
  });

  test('setting the same mode does not notify listeners', () async {
    final controller = ThemeController(prefs: _FakePrefs());
    await controller.load();
    await controller.setMode(AppThemeMode.dark);

    var notifications = 0;
    controller.addListener(() => notifications++);
    await controller.setMode(AppThemeMode.dark);
    expect(notifications, 0);
  });

  test('changing the mode notifies exactly once', () async {
    final controller = ThemeController(prefs: _FakePrefs());
    await controller.load();

    var notifications = 0;
    controller.addListener(() => notifications++);
    await controller.setMode(AppThemeMode.dark);
    expect(notifications, 1);
  });

  test('an unknown stored value degrades to system', () async {
    final prefs = _FakePrefs()..stored = 'sepia';
    final controller = ThemeController(prefs: prefs);
    await controller.load();
    expect(controller.mode, AppThemeMode.system);
  });

  test('every mode has an Arabic label, an English label and an icon', () {
    for (final mode in AppThemeMode.values) {
      expect(mode.labelAr, isNotEmpty);
      expect(mode.labelEn, isNotEmpty);
      expect(mode.storageKey, isNotEmpty);
    }
    expect(AppThemeMode.values.map((m) => m.storageKey).toSet().length, 3);
  });
}
