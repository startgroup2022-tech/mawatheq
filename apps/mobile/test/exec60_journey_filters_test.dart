import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http_base;
import 'package:mawatheq_mobile/core/network/api_client.dart';
import 'package:mawatheq_mobile/core/network/api_repository.dart';
import 'package:mawatheq_mobile/core/network/models.dart';
import 'package:mawatheq_mobile/core/storage/token_storage.dart';

/// Execution 60 — the mobile booking filters.
///
/// The app speaks to the same `/api/v1/journey.php` the website does, so these tests pin
/// the client half of the contract: the filters are serialised to the right query
/// parameters, an empty filter is omitted (so the server applies nothing), the
/// dependent city list is read from the country-keyed vocabulary, and the model reads
/// the server's own `count` / `filtered_out` rather than recounting or guessing.
class _FakeTransport extends http_base.BaseClient {
  _FakeTransport(this.handler);

  final Future<(int, Object)> Function(Uri uri) handler;

  @override
  Future<http_base.StreamedResponse> send(http_base.BaseRequest request) async {
    final (status, body) = await handler(request.url);
    final payload = body is String ? body : jsonEncode(body);
    return http_base.StreamedResponse(
      Stream.value(utf8.encode(payload)),
      status,
      headers: {'content-type': 'application/json; charset=utf-8'},
    );
  }
}

class _MemoryTokenStore implements TokenStore {
  String? _token;
  String? _type;

  @override
  Future<void> saveToken(String token) async => _token = token;

  @override
  Future<String?> readToken() async => _token;

  @override
  Future<void> saveAccountType(String type) async => _type = type;

  @override
  Future<String?> readAccountType() async => _type;

  @override
  Future<void> saveDeviceLabel(String label) async {}

  @override
  Future<String?> readDeviceLabel() async => null;

  @override
  Future<void> clear() async {
    _token = null;
    _type = null;
  }
}

const _filtersJson = '{"ok":true,'
    '"countries":[{"code":"SA","name":"السعودية"},{"code":"BH","name":"البحرين"}],'
    '"cities":{"SA":["الرياض","جدة","الدمام"],"BH":["المنامة","المحرق"]},'
    '"experience":['
    '{"key":"under5","label":"أقل من 5 سنوات"},'
    '{"key":"5to10","label":"5 – 10 سنوات"},'
    '{"key":"11to15","label":"11 – 15 سنة"},'
    '{"key":"16to20","label":"16 – 20 سنة"},'
    '{"key":"over20","label":"أكثر من 20 سنة"}]}';

const _filteredMatchJson = '{"ok":true,"lawyers":['
    '{"id":7,"full_name":"محامي جدة","city":"جدة","country_code":"SA",'
    '"location":"جدة، السعودية","years_experience":25,"hourly_rate":900,"rating":4.5,'
    '"availability":{"has_published_hours":true,"slot_count":3}}],'
    '"matched":true,"count":1,"filtered_out":false,"alternative":null}';

const _excludedMatchJson = '{"ok":true,"lawyers":[],"matched":false,"count":0,'
    '"filtered_out":true,"alternative":null}';

ApiRepository _repo(Future<(int, Object)> Function(Uri) handler) => ApiRepository(
      client: ApiClient(
        client: _FakeTransport(handler),
        tokens: _MemoryTokenStore()..saveToken('t'),
      ),
    );

void main() {
  group('JourneyFilters serialise to the server query', () {
    test('an empty filter set sends no filter parameters', () {
      final q = const JourneyFilters().toQuery();
      expect(q, isEmpty);
      expect(const JourneyFilters().isEmpty, isTrue);
    });

    test('only the set fields are sent', () {
      const f = JourneyFilters(country: 'SA', city: 'جدة', experience: 'over20');
      final q = f.toQuery();
      expect(q['country'], 'SA');
      expect(q['city'], 'جدة');
      expect(q['experience'], 'over20');
      expect(q.containsKey('min_fee'), isFalse);
      expect(q.containsKey('max_fee'), isFalse);
      expect(q.containsKey('available_only'), isFalse);
      expect(f.isEmpty, isFalse);
    });

    test('the fee range and availability flag use the server names', () {
      const f = JourneyFilters(minFee: '100', maxFee: '500', availableOnly: true);
      final q = f.toQuery();
      expect(q['min_fee'], '100');
      expect(q['max_fee'], '500');
      expect(q['available_only'], '1');
    });

    test('copyWith can clear the city when the country changes', () {
      const f = JourneyFilters(country: 'SA', city: 'جدة');
      final next = f.copyWith(country: 'BH', city: '');
      expect(next.country, 'BH');
      expect(next.city, '');
    });
  });

  group('The repository sends the filters to journey.php', () {
    test('journeyLawyers includes the filter parameters', () async {
      late Uri seen;
      final repo = _repo((uri) async {
        seen = uri;
        return (200, _filteredMatchJson);
      });
      const f = JourneyFilters(country: 'SA', city: 'جدة', experience: 'over20', availableOnly: true);
      await repo.journeyLawyers(categoryId: 1, filters: f);
      expect(seen.queryParameters['action'], 'lawyers');
      expect(seen.queryParameters['category_id'], '1');
      expect(seen.queryParameters['country'], 'SA');
      expect(seen.queryParameters['city'], 'جدة');
      expect(seen.queryParameters['experience'], 'over20');
      expect(seen.queryParameters['available_only'], '1');
    });

    test('journeyLawyers with no filters sends only the category', () async {
      late Uri seen;
      final repo = _repo((uri) async {
        seen = uri;
        return (200, _filteredMatchJson);
      });
      await repo.journeyLawyers(categoryId: 3);
      expect(seen.queryParameters['category_id'], '3');
      expect(seen.queryParameters.containsKey('country'), isFalse);
      expect(seen.queryParameters.containsKey('experience'), isFalse);
    });

    test('journeyFilters hits action=filters and parses the vocabulary', () async {
      late Uri seen;
      final repo = _repo((uri) async {
        seen = uri;
        return (200, _filtersJson);
      });
      final opts = await repo.journeyFilters();
      expect(seen.queryParameters['action'], 'filters');
      expect(opts.countries.length, 2);
      expect(opts.countries.first.code, 'SA');
      expect(opts.cities['SA'], contains('الرياض'));
      expect(opts.cities['BH'], contains('المنامة'));
      expect(opts.cities['BH'], isNot(contains('الرياض')));
      expect(opts.experience.length, 5);
      expect(opts.experience.first.key, 'under5');
      expect(opts.experience.last.key, 'over20');
    });
  });

  group('The match model reads the server count and the filtered-out flag', () {
    test('a filtered match carries the server count and the lawyer', () async {
      final repo = _repo((_) async => (200, _filteredMatchJson));
      final match = await repo.journeyLawyers(categoryId: 1);
      expect(match.matched, isTrue);
      expect(match.count, 1);
      expect(match.filteredOut, isFalse);
      final lw = match.lawyers.single;
      expect(lw.name, 'محامي جدة');
      expect(lw.city, 'جدة');
      expect(lw.countryCode, 'SA');
      expect(lw.location, 'جدة، السعودية');
      expect(lw.yearsExperience, 25);
      expect(lw.hourlyRate, 900);
      expect(lw.nextSlotCount, 3);
    });

    test('a filtered-out result is distinct from "no lawyer at all"', () async {
      final repo = _repo((_) async => (200, _excludedMatchJson));
      final match = await repo.journeyLawyers(categoryId: 1);
      expect(match.matched, isFalse);
      expect(match.count, 0);
      expect(match.filteredOut, isTrue);
      expect(match.lawyers, isEmpty);
    });

    test('a lawyer whose row predates the migration parses with empty location', () {
      final lw = LawyerProfile.fromJson(jsonDecode(
          '{"id":9,"full_name":"قديم","jurisdiction":"السعودية"}') as Map<String, dynamic>);
      expect(lw.city, '');
      expect(lw.countryCode, '');
      expect(lw.yearsExperience, 0);
      expect(lw.hasPublishedHours, isFalse);
    });

    test('the availability summary is read from the server, never invented', () {
      final lw = LawyerProfile.fromJson(jsonDecode(
          '{"id":9,"full_name":"س","availability":{"has_published_hours":false,"slot_count":0}}')
          as Map<String, dynamic>);
      expect(lw.hasPublishedHours, isFalse);
      expect(lw.nextSlotCount, 0);
    });
  });
}
