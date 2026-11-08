import 'package:local_auth/local_auth.dart';

/// The device's biometric capability, behind an interface.
///
/// An interface rather than the plugin directly so widget tests can supply a fake:
/// the platform channel is unavailable in a test process, and a test for "the
/// biometric tile is hidden when the device cannot do biometrics" must be able to
/// answer that question without a device.
abstract class BiometricService {
  /// True when the hardware exists AND at least one biometric is enrolled.
  Future<bool> isAvailable();

  /// Prompts for the device biometric. Returns true only on success; a cancelled
  /// or failed prompt returns false rather than throwing, so the caller can fall
  /// back to the password form.
  Future<bool> authenticate({required String reason});
}

/// Production [BiometricService] backed by `local_auth`.
///
/// Every plugin call is wrapped: `local_auth` throws on a device with no
/// biometric hardware, on an emulator, and when the platform channel is missing.
/// None of those is an error the user should see — they simply mean biometric
/// sign-in is unavailable, so the methods degrade to `false`.
class LocalAuthBiometricService implements BiometricService {
  LocalAuthBiometricService({LocalAuthentication? auth})
    : _auth = auth ?? LocalAuthentication();

  final LocalAuthentication _auth;

  @override
  Future<bool> isAvailable() async {
    try {
      final supported = await _auth.isDeviceSupported();
      final canCheck = await _auth.canCheckBiometrics;
      final enrolled = await _auth.getAvailableBiometrics();
      return supported && canCheck && enrolled.isNotEmpty;
    } catch (_) {
      return false;
    }
  }

  @override
  Future<bool> authenticate({required String reason}) async {
    try {
      return await _auth.authenticate(
        localizedReason: reason,
        // `biometricOnly` keeps the promise the setting makes (no PIN fallback), and
        // `persistAcrossBackgrounding: false` means a backgrounded app must
        // re-authenticate rather than resuming unlocked.
        biometricOnly: true,
        persistAcrossBackgrounding: false,
      );
    } catch (_) {
      return false;
    }
  }
}
