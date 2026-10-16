import 'package:flutter/foundation.dart';

import 'storage/token_storage.dart';

/// Locale state. Arabic is the default and the RTL layout follows from it.
class LocaleController extends ChangeNotifier {
  LocaleController({PrefsStorage? prefs}) : _prefs = prefs ?? PrefsStorage();

  final PrefsStorage _prefs;
  String _locale = 'ar';

  String get locale => _locale;
  bool get isRtl => _locale == 'ar';

  Future<void> load() async {
    _locale = await _prefs.readLocale();
    notifyListeners();
  }

  Future<void> toggle() async {
    _locale = _locale == 'ar' ? 'en' : 'ar';
    await _prefs.saveLocale(_locale);
    notifyListeners();
  }

  Future<void> setLocale(String locale) async {
    if (locale != 'ar' && locale != 'en') return;
    _locale = locale;
    await _prefs.saveLocale(locale);
    notifyListeners();
  }
}
