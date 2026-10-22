import 'package:flutter/material.dart';

import '../storage/token_storage.dart';

/// The three theme choices a user can make.
enum AppThemeMode {
  /// Follow the operating system's light/dark setting.
  system,

  light,
  dark;

  /// Stable key used in storage. Kept separate from [name] so renaming an enum
  /// value cannot silently invalidate a stored preference.
  String get storageKey => switch (this) {
        AppThemeMode.system => 'system',
        AppThemeMode.light => 'light',
        AppThemeMode.dark => 'dark',
      };

  static AppThemeMode fromStorage(String? value) => switch (value) {
        'light' => AppThemeMode.light,
        'dark' => AppThemeMode.dark,
        'system' => AppThemeMode.system,
        // No stored choice means a first-time user: they get the light theme.
        // Following the device here would open the app dark on a phone that
        // happens to be in dark mode, which is not the documented default.
        _ => AppThemeMode.light,
      };

  /// The Material theme mode this choice maps to.
  ThemeMode get material => switch (this) {
        AppThemeMode.system => ThemeMode.system,
        AppThemeMode.light => ThemeMode.light,
        AppThemeMode.dark => ThemeMode.dark,
      };

  /// Arabic label for the chooser.
  String get labelAr => switch (this) {
        AppThemeMode.system => 'حسب النظام',
        AppThemeMode.light => 'فاتح',
        AppThemeMode.dark => 'داكن',
      };

  String get labelEn => switch (this) {
        AppThemeMode.system => 'System',
        AppThemeMode.light => 'Light',
        AppThemeMode.dark => 'Dark',
      };

  IconData get icon => switch (this) {
        AppThemeMode.system => Icons.brightness_auto_outlined,
        AppThemeMode.light => Icons.light_mode_outlined,
        AppThemeMode.dark => Icons.dark_mode_outlined,
      };
}

/// Theme state, persisted across launches.
///
/// The default is [AppThemeMode.light]: a first-time user gets the light theme,
/// as the product specification requires. [AppThemeMode.system] remains an
/// explicit user choice, it is just no longer what an unmade choice means. The
/// selection is stored in plain preferences, not the keychain — it is not a
/// secret, and it must survive signing out.
class ThemeController extends ChangeNotifier {
  ThemeController({PrefsStore? prefs}) : _prefs = prefs ?? PrefsStorage();

  final PrefsStore _prefs;

  AppThemeMode _mode = AppThemeMode.light;
  AppThemeMode get mode => _mode;

  ThemeMode get themeMode => _mode.material;

  /// Reads the stored choice. Falls back to [AppThemeMode.light] when nothing is
  /// stored or the platform channel is unavailable, so a first launch and a test
  /// harness both get the documented default instead of throwing.
  Future<void> load() async {
    try {
      _mode = AppThemeMode.fromStorage(await _prefs.readThemeMode());
    } catch (_) {
      _mode = AppThemeMode.light;
    }
    notifyListeners();
  }

  Future<void> setMode(AppThemeMode mode) async {
    final changed = mode != _mode;
    _mode = mode;
    if (changed) notifyListeners();
    // Always persisted, even when the choice matches the in-memory default. The
    // default is light, so an early return here would drop an explicit "light" and
    // a user who picked it on a dark phone would silently get system next launch.
    // Persisted after notifying: the UI must not wait on disk to repaint.
    try {
      await _prefs.saveThemeMode(mode.storageKey);
    } catch (_) {
      // A failed write costs the user the preference on next launch, not the
      // change they just made. Nothing to recover here.
    }
  }
}
