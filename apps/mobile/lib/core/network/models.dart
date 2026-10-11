/// Domain models mirroring the JSON returned by `/api/v1/`.
///
/// Field names follow the backend exactly, so a change on the server is visible
/// here rather than silently absorbed. Parsing is defensive: a missing or
/// unexpected field yields a safe default instead of crashing the screen.
library;

/// A lightweight, type-safe view over a JSON map.
class Json {
  const Json(this.raw);

  final Map<String, dynamic> raw;

  String str(String key, {String fallback = ''}) {
    final value = raw[key];
    if (value == null) return fallback;
    return value.toString();
  }

  int intg(String key, {int fallback = 0}) {
    final value = raw[key];
    if (value is int) return value;
    if (value is num) return value.toInt();
    return int.tryParse(value?.toString() ?? '') ?? fallback;
  }

  double dbl(String key, {double fallback = 0}) {
    final value = raw[key];
    if (value is num) return value.toDouble();
    return double.tryParse(value?.toString() ?? '') ?? fallback;
  }

  bool boolean(String key, {bool fallback = false}) {
    final value = raw[key];
    if (value is bool) return value;
    if (value is num) return value != 0;
    final text = value?.toString().toLowerCase();
    if (text == 'true' || text == '1') return true;
    if (text == 'false' || text == '0') return false;
    return fallback;
  }

  List<Json> list(String key) {
    final value = raw[key];
    if (value is List) {
      return value
          .whereType<Map>()
          .map((e) => Json(Map<String, dynamic>.from(e)))
          .toList();
    }
    return const [];
  }

  Json? object(String key) {
    final value = raw[key];
    if (value is Map) return Json(Map<String, dynamic>.from(value));
    return null;
  }

  Map<String, dynamic> get asMap => raw;
}

/// The signed-in user, whether a client or a lawyer.
class SessionUser {
  const SessionUser({
    required this.type,
    required this.id,
    required this.name,
    this.email = '',
    this.phone = '',
    this.role = '',
    this.jurisdiction = '',
    this.firmId,
    this.firmName = '',
    this.permissions = const <String>[],
  });

  /// `client` or `lawyer` — the two token types `api/v1/me.php` returns.
  final String type;
  final int id;
  final String name;
  final String email;
  final String phone;

  /// Firm member role: owner | partner | lawyer | staff (empty for solo/client).
  final String role;
  final String jurisdiction;
  final int? firmId;
  final String firmName;

  /// Capability list. The server remains the source of truth; this is only used
  /// to decide what to show, never to authorise anything.
  final List<String> permissions;

  bool get isLawyer => type == 'lawyer';
  bool get isClient => type == 'client';
  bool get isFirmMember => firmId != null;

  /// Whether the UI should show a capability. The API enforces it regardless.
  bool can(String permission) {
    if (permissions.isEmpty) return false;
    return permissions.contains(permission);
  }

  factory SessionUser.fromJson(Map<String, dynamic> map) {
    final json = Json(map);
    final profile = json.object('profile') ?? json;

    // The API may nest permissions, or send them flat.
    final perms = <String>[];
    for (final entry in [profile, json]) {
      final list = entry.list('permissions');
      if (list.isNotEmpty) {
        perms.addAll(list.map((e) => e.str('name', fallback: e.str('key'))));
        break;
      }
      final raw = entry.raw['permissions'];
      if (raw is List) {
        perms.addAll(raw.map((e) => e.toString()));
        break;
      }
    }

    return SessionUser(
      type: json.str('type', fallback: 'client'),
      id: profile.intg('id'),
      name: profile.str('full_name', fallback: profile.str('name')),
      email: profile.str('email'),
      phone: profile.str('phone'),
      role: profile.str('member_role', fallback: profile.str('role')),
      jurisdiction: profile.str('jurisdiction'),
      firmId: profile.raw['firm_id'] is num
          ? profile.intg('firm_id')
          : (int.tryParse(profile.str('firm_id'))),
      firmName: profile.str('firm_name'),
      permissions: perms.where((p) => p.isNotEmpty).toList(),
    );
  }

  static const empty = SessionUser(type: 'guest', id: 0, name: '');
}

/// A case from `GET /api/v1/cases.php`.
class CaseSummary {
  const CaseSummary({
    required this.id,
    required this.title,
    required this.status,
    this.caseNumber = '',
    this.clientName = '',
    this.courtName = '',
    this.nextHearing = '',
    this.updatedAt = '',
  });

  final int id;
  final String title;
  final String status;
  final String caseNumber;
  final String clientName;
  final String courtName;
  final String nextHearing;
  final String updatedAt;

  factory CaseSummary.fromJson(Map<String, dynamic> map) {
    final j = Json(map);
    return CaseSummary(
      id: j.intg('id'),
      title: j.str('title', fallback: j.str('case_number')),
      status: j.str('status', fallback: 'open'),
      caseNumber: j.str('case_number'),
      clientName: j.str('client_name', fallback: j.str('user_name')),
      courtName: j.str('court_name'),
      nextHearing: j.str('next_hearing', fallback: j.str('next_hearing_at')),
      updatedAt: j.str('updated_at'),
    );
  }
}

/// A document from `GET /api/v1/documents.php`.
class DocumentSummary {
  const DocumentSummary({
    required this.id,
    required this.title,
    required this.status,
    this.documentType = 'general',
    this.caseTitle = '',
    this.createdAt = '',
  });

  final int id;
  final String title;
  final String status;
  final String documentType;
  final String caseTitle;
  final String createdAt;

  bool get isAnalysed => status == 'completed';
  bool get isProcessing => status == 'processing' || status == 'pending';

  factory DocumentSummary.fromJson(Map<String, dynamic> map) {
    final j = Json(map);
    return DocumentSummary(
      id: j.intg('id'),
      title: j.str('title', fallback: j.str('original_name', fallback: j.str('file_name'))),
      status: j.str('status', fallback: 'pending'),
      documentType: j.str('document_type', fallback: 'general'),
      caseTitle: j.str('case_title'),
      createdAt: j.str('created_at'),
    );
  }
}

/// A consultation from `GET /api/v1/consultations.php`.
class Consultation {
  const Consultation({
    required this.id,
    required this.status,
    this.consultationType = 'text',
    this.topic = '',
    this.lawyerName = '',
    this.clientName = '',
    this.scheduledDate = '',
    this.scheduledTime = '',
  });

  final int id;
  final String status;
  final String consultationType;
  final String topic;
  final String lawyerName;
  final String clientName;
  final String scheduledDate;
  final String scheduledTime;

  String get schedule =>
      [scheduledDate, scheduledTime].where((s) => s.isNotEmpty).join(' · ');

  factory Consultation.fromJson(Map<String, dynamic> map) {
    final j = Json(map);
    return Consultation(
      id: j.intg('id'),
      status: j.str('status', fallback: 'pending'),
      consultationType: j.str('consultation_type', fallback: 'text'),
      topic: j.str('topic', fallback: j.str('subject')),
      lawyerName: j.str('lawyer_name'),
      clientName: j.str('client_name'),
      scheduledDate: j.str('scheduled_date', fallback: j.str('date')),
      scheduledTime: j.str('scheduled_time', fallback: j.str('time')),
    );
  }
}

/// An invoice from `GET /api/v1/invoices.php`.
class Invoice {
  const Invoice({
    required this.id,
    required this.status,
    required this.total,
    this.currency = 'SAR',
    this.number = '',
    this.issuedAt = '',
    this.isOverdue = false,
  });

  final int id;
  final String status;
  final double total;
  final String currency;
  final String number;
  final String issuedAt;
  final bool isOverdue;

  factory Invoice.fromJson(Map<String, dynamic> map) {
    final j = Json(map);
    return Invoice(
      id: j.intg('id'),
      status: j.str('status', fallback: 'unpaid'),
      total: j.dbl('total', fallback: j.dbl('amount')),
      currency: j.str('currency', fallback: 'SAR'),
      number: j.str('invoice_number', fallback: j.str('number')),
      issuedAt: j.str('issued_at', fallback: j.str('created_at')),
      isOverdue: j.boolean('is_overdue'),
    );
  }
}

/// A notification from `GET /api/v1/notifications.php`.
class AppNotification {
  const AppNotification({
    required this.id,
    required this.title,
    this.body = '',
    this.isRead = false,
    this.createdAt = '',
  });

  final int id;
  final String title;
  final String body;
  final bool isRead;
  final String createdAt;

  factory AppNotification.fromJson(Map<String, dynamic> map) {
    final j = Json(map);
    return AppNotification(
      id: j.intg('id'),
      title: j.str('title', fallback: j.str('message')),
      body: j.str('body', fallback: j.str('content')),
      isRead: j.boolean('is_read', fallback: j.intg('read') == 1),
      createdAt: j.str('created_at'),
    );
  }
}

/// A lawyer from the public directory `GET /api/v1/lawyers.php`.
class LawyerProfile {
  const LawyerProfile({
    required this.id,
    required this.name,
    this.specialization = '',
    this.jurisdiction = '',
    this.rating = 0,
    this.hourlyRate = 0,
  });

  final int id;
  final String name;
  final String specialization;
  final String jurisdiction;
  final double rating;
  final double hourlyRate;

  factory LawyerProfile.fromJson(Map<String, dynamic> map) {
    final j = Json(map);
    return LawyerProfile(
      id: j.intg('id'),
      name: j.str('full_name', fallback: j.str('name')),
      specialization: j.str('specialization'),
      jurisdiction: j.str('jurisdiction'),
      rating: j.dbl('rating'),
      hourlyRate: j.dbl('hourly_rate'),
    );
  }
}

/// The real assistant availability from `GET /api/v1/ai-status.php`.
///
/// Used instead of a permanently green indicator: the home screen reflects what
/// the server actually reports about the configured provider.
class AiStatus {
  const AiStatus({
    required this.available,
    required this.configured,
    required this.reachable,
    this.blocking,
    this.providerSupported = false,
  });

  final bool available;
  final bool configured;
  final bool reachable;

  /// Why it is unavailable: disabled | no_provider | provider_unsupported |
  /// no_endpoint | no_credentials | unreachable. Null when configured.
  final String? blocking;
  final bool providerSupported;

  /// A user-facing explanation, never a credential.
  String get reason {
    switch (blocking) {
      case 'disabled':
        return 'المساعد الذكي معطّل من لوحة الإدارة.';
      case 'no_provider':
        return 'لم يُضبط مزوّد الذكاء الاصطناعي بعد من لوحة الإدارة.';
      case 'provider_unsupported':
        return 'المزوّد المضبوط لا يدعم المحادثة.';
      case 'no_endpoint':
        return 'لم يُضبط عنوان المزوّد المحلي بعد.';
      case 'no_credentials':
        return 'لم يُضبط مفتاح المزوّد بعد من لوحة الإدارة.';
      case 'unreachable':
        return 'تعذّر الوصول إلى مزوّد الذكاء الاصطناعي حالياً.';
      default:
        return '';
    }
  }

  static const unknown = AiStatus(
    available: false,
    configured: false,
    reachable: false,
    blocking: 'unknown',
  );

  factory AiStatus.fromJson(Map<String, dynamic> map) {
    final j = Json(map);
    return AiStatus(
      available: j.boolean('available'),
      configured: j.boolean('configured'),
      reachable: j.boolean('reachable'),
      blocking: j.raw['blocking']?.toString(),
      providerSupported: j.boolean('provider_supported'),
    );
  }
}

/// A published plan from `GET /api/v1/plans.php`.
///
/// Every price and limit is the value stored in the `plans` table, so the app can
/// never show a price the platform does not charge.
class Plan {
  const Plan({
    required this.id,
    required this.nameAr,
    required this.nameEn,
    required this.priceMonthly,
    required this.priceYearly,
    this.descriptionAr = '',
    this.descriptionEn = '',
    this.aiQueriesLimit,
    this.documentAnalysisLimit,
    this.consultationsIncluded,
    this.isCorporate = false,
    this.isFeatured = false,
  });

  final int id;
  final String nameAr;
  final String nameEn;
  final double priceMonthly;
  final double priceYearly;
  final String descriptionAr;
  final String descriptionEn;

  /// Null means the plan does not cap this resource.
  final int? aiQueriesLimit;
  final int? documentAnalysisLimit;
  final int? consultationsIncluded;

  final bool isCorporate;
  final bool isFeatured;

  String get displayName => nameAr.isNotEmpty ? nameAr : nameEn;
  bool get isFree => priceMonthly <= 0;

  factory Plan.fromJson(Map<String, dynamic> map) {
    final j = Json(map);
    int? nullableInt(String key) =>
        j.raw[key] == null ? null : j.intg(key);
    return Plan(
      id: j.intg('id'),
      nameAr: j.str('name_ar'),
      nameEn: j.str('name_en'),
      descriptionAr: j.str('description_ar'),
      descriptionEn: j.str('description_en'),
      priceMonthly: j.dbl('price_monthly'),
      priceYearly: j.dbl('price_yearly'),
      aiQueriesLimit: nullableInt('ai_queries_limit'),
      documentAnalysisLimit: nullableInt('document_analysis_limit'),
      consultationsIncluded: nullableInt('consultations_included'),
      isCorporate: j.boolean('is_corporate'),
      isFeatured: j.boolean('is_featured'),
    );
  }
}

/// A legal-calendar entry from `GET /api/v1/calendar.php`.
class CalendarEvent {
  const CalendarEvent({
    required this.id,
    required this.title,
    required this.eventType,
    this.eventAt = '',
    this.isCompleted = false,
  });

  final int id;
  final String title;
  final String eventType;
  final String eventAt;
  final bool isCompleted;

  factory CalendarEvent.fromJson(Map<String, dynamic> map) {
    final j = Json(map);
    return CalendarEvent(
      id: j.intg('id'),
      title: j.str('title'),
      eventType: j.str('event_type', fallback: 'hearing'),
      eventAt: j.str('event_at', fallback: j.str('event_date')),
      isCompleted: j.boolean('is_completed') || j.str('status') == 'completed',
    );
  }
}

/// The reply from `POST /api/v1/ai-chat.php`.
///
/// `reference` and `referenceArticleId` are only populated when the laws library
/// returned a statutory basis — the UI must not invent one when they are empty.
class AiReply {
  const AiReply({
    required this.reply,
    required this.conversationId,
    this.reference = '',
    this.referenceArticleId,
    this.limitReached = false,
  });

  final String reply;
  final int conversationId;
  final String reference;
  final int? referenceArticleId;
  final bool limitReached;

  bool get hasReference => reference.trim().isNotEmpty;

  factory AiReply.fromJson(Map<String, dynamic> map) {
    final j = Json(map);
    return AiReply(
      reply: j.str('reply', fallback: j.str('message')),
      conversationId: j.intg('conversation_id'),
      reference: j.str('reference'),
      referenceArticleId:
          j.raw['reference_article_id'] is num ? j.intg('reference_article_id') : null,
      limitReached: j.boolean('limit_reached') ||
          j.str('error').contains('حد'),
    );
  }
}
