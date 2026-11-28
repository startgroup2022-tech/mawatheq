// Execution 78 — the pricing screen shows the deployment's currency, not a hardcoded SAR.
//
// The API has always stated the currency at the top of the plans response and, since this
// execution, on every plan row. The screen ignored both and printed 'ر.س' / 'SAR' as a
// literal, which is correct only on a SAR deployment and silently wrong anywhere else.

import 'package:flutter_test/flutter_test.dart';
import 'package:mawatheq_mobile/core/network/models.dart';

Plan _plan({String currency = 'SAR'}) => Plan(
      id: 2,
      nameAr: 'احترافية',
      nameEn: 'Professional',
      descriptionAr: '',
      descriptionEn: '',
      priceMonthly: 149,
      priceYearly: 1490,
      priceMonthlyDisplay: '149.00',
      priceYearlyDisplay: '1,490.00',
      currency: currency,
    );

void main() {
  group('Plan.currencyLabel', () {
    test('SAR renders the riyal sign in Arabic', () {
      expect(_plan(currency: 'SAR').currencyLabel(true), 'ر.س');
    });

    test('SAR renders the ISO code in English', () {
      expect(_plan(currency: 'SAR').currencyLabel(false), 'SAR');
    });

    test('a non-SAR currency is never shown as a riyal', () {
      final p = _plan(currency: 'AED');
      expect(p.currencyLabel(true), 'AED');
      expect(p.currencyLabel(false), 'AED');
    });

    test('a server that omits the field falls back to SAR, not to an empty label', () {
      final p = _plan(currency: '');
      expect(p.currencyLabel(false), 'SAR');
      expect(p.currencyLabel(true), 'ر.س');
    });
  });

  group('Plan.fromJson', () {
    test('reads the per-plan currency', () {
      final p = Plan.fromJson(<String, dynamic>{
        'id': 3,
        'name_ar': 'مؤسسية',
        'name_en': 'Enterprise',
        'description_ar': '',
        'description_en': '',
        'price_monthly': '799.000',
        'price_yearly': '7990.000',
        'price_monthly_display': '799.00',
        'price_yearly_display': '7,990.00',
        'currency': 'SAR',
      });
      expect(p.currency, 'SAR');
      expect(p.monthlyLabel, '799.00');
    });

    test('an older server without the field does not throw', () {
      final p = Plan.fromJson(<String, dynamic>{
        'id': 1,
        'name_ar': 'أساسية',
        'name_en': 'Basic',
        'price_monthly': '0.000',
        'price_yearly': '0.000',
      });
      expect(p.currency, '');
      expect(p.currencyLabel(false), 'SAR');
    });
  });
}
