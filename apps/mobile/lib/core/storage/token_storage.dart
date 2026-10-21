import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// The credential store the app depends on.
///
/// It is an interface rather than a concrete class so tests can substitute an
/// in-memory implementation: the platform keychain is not available inside a
/// unit-test process, and a test that needs a token should not need a device.
abstract class TokenStore {
  Future<void> saveToken(String value);
  Future<String?> readToken();

  /// 'client' or 'lawyer' — which private area the signed-in account belongs to.
  Future<void> saveAccountType(String value);
  Future<String?> readAccountType();

  /// Human label shown against the device in the account's active sessions list.
  Future<void> saveDeviceLabel(String label);
  Future<String?> readDeviceLabel();

  /// Remove every stored credential. Called on sign-out and when the server
  /// rejects a token, so a revoked session cannot be resumed from disk.
  Future<void> clear();
}

/// Production [TokenStore]: the token lives in the platform secure store
/// (Keychain on iOS, EncryptedSharedPreferences on Android) and never in a
/// plain preference file. The account type and device label are not secrets,
/// but they are kept beside the token so sign-out clears them together.
class TokenStorage implements TokenStore {
  TokenStorage({FlutterSecureStorage? storage})
      : _storage = storage ?? const FlutterSecureStorage();

  static const _tokenKey = 'mawatheq.token';
  static const _accountTypeKey = 'mawatheq.account_type';
  static const _deviceLabelKey = 'mawatheq.device_label';

  final FlutterSecureStorage _storage;

  @override
  Future<void> saveToken(String value) =>
      _storage.write(key: _tokenKey, value: value);

  @override
  Future<String?> readToken() => _storage.read(key: _tokenKey);

  @override
  Future<void> saveAccountType(String value) =>
      _storage.write(key: _accountTypeKey, value: value);

  @override
  Future<String?> readAccountType() => _storage.read(key: _accountTypeKey);

  @override
  Future<void> saveDeviceLabel(String label) =>
      _storage.write(key: _deviceLabelKey, value: label);

  @override
  Future<String?> readDeviceLabel() => _storage.read(key: _deviceLabelKey);

  @override
  Future<void> clear() async {
    // Delete each known key rather than deleteAll(): deleteAll() would also wipe
    // keys written by other parts of the app that this store does not own.
    await _storage.delete(key: _tokenKey);
    await _storage.delete(key: _accountTypeKey);
    await _storage.delete(key: _deviceLabelKey);
  }
}

/// Non-secret local preferences (locale, onboarding flag).
///
/// Deliberately separate from [TokenStorage]: these values are not credentials, so
/// they live in SharedPreferences rather than the keychain, and clearing a session
/// must not reset the user's chosen language.
abstract class PrefsStore {
  Future<void> saveLocale(String locale);
  Future<String> readLocale();

  Future<void> saveOnboardingSeen(bool seen);
  Future<bool> readOnboardingSeen();

  /// 'system', 'light' or 'dark'. Returns null when nothing is stored, so the
  /// caller can distinguish "never chose" from "chose the default".
  Future<void> saveThemeMode(String mode);
  Future<String?> readThemeMode();
}

/// Production [PrefsStore] backed by SharedPreferences.
///
/// Every read falls back to the Arabic-first default when the key is absent or the
/// platform channel is unavailable, so a first launch and a test harness both get a
/// usable value instead of a crash or a hang.
class PrefsStorage implements PrefsStore {
  PrefsStorage({Future<SharedPreferences>? prefs}) : _prefs = prefs;

  static const _localeKey = 'mawatheq.locale';
  static const _onboardingKey = 'mawatheq.onboarding_seen';
  static const _themeModeKey = 'mawatheq.theme_mode';

  final Future<SharedPreferences>? _prefs;

  Future<SharedPreferences> get _instance async =>
      _prefs != null ? await _prefs : SharedPreferences.getInstance();

  @override
  Future<void> saveLocale(String locale) async =>
      (await _instance).setString(_localeKey, locale);

  @override
  Future<String> readLocale() async {
    try {
      final stored = (await _instance).getString(_localeKey);
      return stored == 'en' ? 'en' : 'ar';
    } catch (_) {
      // An unavailable platform channel must not stop the app from starting; the
      // documented default is Arabic.
      return 'ar';
    }
  }

  @override
  Future<void> saveOnboardingSeen(bool seen) async =>
      (await _instance).setBool(_onboardingKey, seen);

  @override
  Future<bool> readOnboardingSeen() async {
    try {
      return (await _instance).getBool(_onboardingKey) ?? false;
    } catch (_) {
      return false;
    }
  }

  @override
  Future<void> saveThemeMode(String mode) async =>
      (await _instance).setString(_themeModeKey, mode);

  @override
  Future<String?> readThemeMode() async {
    try {
      // Deliberately NOT defaulted to 'system' here: returning null lets the
      // controller tell "no stored choice" from "stored choice", which is what a
      // future migration between defaults would need.
      return (await _instance).getString(_themeModeKey);
    } catch (_) {
      return null;
    }
  }
}
