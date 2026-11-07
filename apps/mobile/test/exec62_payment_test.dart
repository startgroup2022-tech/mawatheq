import 'package:flutter_test/flutter_test.dart';
import 'package:mawatheq_mobile/core/network/models.dart';
import 'package:mawatheq_mobile/features/consultations/payment_webview_screen.dart';

/// Execution 62 — the mobile side of consultation payment.
///
/// The web view itself needs a platform, so these tests cover the two pure decisions the
/// payment flow rests on: how a booking response is parsed, and exactly which URL ends the
/// flow. Both are the kind of thing that looks right and silently is not.
void main() {
  group('BookingResult', () {
    test('a free consultation needs no payment', () {
      final r = BookingResult.fromJson({'ok': true, 'consultation_id': 7});
      expect(r.consultationId, 7);
      expect(r.paymentRequired, isFalse);
      expect(r.paymentUrl, isNull);
    });

    test('a priced consultation carries the hosted payment URL', () {
      final r = BookingResult.fromJson({
        'ok': true,
        'consultation_id': 12,
        'payment_required': true,
        'payment_url': 'https://api-gateway.ksa.ngenius-payments.com/hosted/abc',
      });
      expect(r.consultationId, 12);
      expect(r.paymentRequired, isTrue);
      expect(r.paymentUrl, contains('ngenius'));
    });

    test('an empty payment_url is treated as no URL, not an empty string', () {
      final r = BookingResult.fromJson({
        'ok': true,
        'consultation_id': 3,
        'payment_required': true,
        'payment_url': '',
      });
      expect(r.paymentUrl, isNull);
    });
  });

  group('PaymentWebViewScreen.isReturnUrl', () {
    test('the return URL for this consultation ends the flow', () {
      expect(
        PaymentWebViewScreen.isReturnUrl(
          'https://mawatheq.com/consultation-payment-return.php?consultation_id=42',
          42,
        ),
        isTrue,
      );
    });

    test('a return URL for a different consultation does not', () {
      expect(
        PaymentWebViewScreen.isReturnUrl(
          'https://mawatheq.com/consultation-payment-return.php?consultation_id=43',
          42,
        ),
        isFalse,
      );
    });

    test('the gateway page itself is not a return URL', () {
      expect(
        PaymentWebViewScreen.isReturnUrl(
          'https://api-gateway.ksa.ngenius-payments.com/hosted/abc',
          42,
        ),
        isFalse,
      );
    });

    test('a look-alike path without the query id does not match', () {
      expect(
        PaymentWebViewScreen.isReturnUrl(
          'https://mawatheq.com/consultation-payment-return.php',
          42,
        ),
        isFalse,
      );
    });
  });
}
