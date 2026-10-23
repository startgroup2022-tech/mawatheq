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

  /// A list of plain scalars — e.g. the server's `allowed_extensions`. [list] is for
  /// lists of objects and drops these, so they need their own accessor.
  List<String> strList(String key) {
    final value = raw[key];
    if (value is List) {
      return value
          .where((e) => e != null)
          .map((e) => e.toString())
          .where((e) => e.isNotEmpty)
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

  /// Firm member role: owner | partner | lawyer | staff; `solo` for an independent
  /// lawyer and `client` for a client account.
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

  /// `me.php` returns session-scoped fields (`role`, `firm_id`, `firm_name`,
  /// `permissions`) at the top level, next to `profile`, which holds the user's own
  /// record. Read the top level first and fall back to `profile` so an older server
  /// that nested them still works.
  factory SessionUser.fromJson(Map<String, dynamic> map) {
    final json = Json(map);
    final profile = json.object('profile') ?? json;

    // The API may nest permissions, or send them flat.
    final perms = <String>[];
    for (final entry in [json, profile]) {
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

    // `firm_id` is null for solo lawyers and clients, and a number otherwise. Accept
    // a numeric string too, since a JSON encoder may render an int column that way.
    int? firmId;
    for (final entry in [json, profile]) {
      final raw = entry.raw['firm_id'];
      if (raw is num) {
        firmId = raw.toInt();
        break;
      }
      final parsed = int.tryParse(entry.str('firm_id'));
      if (parsed != null) {
        firmId = parsed;
        break;
      }
    }

    return SessionUser(
      type: json.str('type', fallback: 'client'),
      id: profile.intg('id'),
      name: profile.str('full_name', fallback: profile.str('name')),
      email: profile.str('email'),
      phone: profile.str('phone'),
      role: json.str('role',
          fallback: profile.str('member_role', fallback: profile.str('role'))),
      jurisdiction: profile.str('jurisdiction'),
      firmId: firmId,
      firmName: json.str('firm_name', fallback: profile.str('firm_name')),
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
    this.attachmentCount = 0,
  });

  final int id;
  final String status;
  final String consultationType;
  final String topic;
  final String lawyerName;
  final String clientName;
  final String scheduledDate;
  final String scheduledTime;

  /// How many files are attached, as counted server-side. The list endpoint already
  /// derives it; showing it tells the customer their upload arrived.
  final int attachmentCount;

  String get schedule =>
      [scheduledDate, scheduledTime].where((s) => s.isNotEmpty).join(' · ');

  factory Consultation.fromJson(Map<String, dynamic> map) {
    final j = Json(map);
    return Consultation(
      id: j.intg('id'),
      status: j.str('status', fallback: 'pending'),
      consultationType: j.str('consultation_type', fallback: 'text'),
      topic: j.str('topic', fallback: j.str('subject')),
      attachmentCount: j.intg('attachment_count'),
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

/// A home-screen slider slide.
///
/// An operator banner comes from `GET /api/v1/banners.php` and every field is
/// operator-supplied through `/admin/banners.php`. When the server sends none — the
/// normal state of a fresh install, since `home_banners` ships empty on purpose —
/// the home screen falls back to the built-in Mawatheq slides in
/// `shared/widgets/home_slides.dart`, which are a description of the product rather
/// than a fabricated promotion. The optional `eyebrow`/`iconKey`/`gradientKey`
/// fields are client-only and empty for an operator banner.
class HomeBanner {
  const HomeBanner({
    required this.id,
    required this.titleAr,
    required this.titleEn,
    this.subtitleAr = '',
    this.subtitleEn = '',
    this.imagePath = '',
    this.altAr = '',
    this.altEn = '',
    this.linkType = 'none',
    this.linkTarget,
    this.eyebrowAr = '',
    this.eyebrowEn = '',
    this.iconKey = '',
    this.gradientKey = '',
    this.assetImage = '',
  });

  final int id;
  final String titleAr;
  final String titleEn;
  final String subtitleAr;
  final String subtitleEn;

  /// A short label above the title, e.g. "مواثيق". Client-only: the server never
  /// sends it, and it is empty for an operator banner.
  final String eyebrowAr;
  final String eyebrowEn;

  /// The icon that illustrates a built-in slide, resolved through
  /// `specialty_icons.dart` (`family`, `commercial`, ...). Client-only.
  final String iconKey;

  /// Which brand gradient a built-in slide paints, one of the keys understood by
  /// the carousel (`navy`, `teal`, `gold`, `deep`). Client-only.
  final String gradientKey;

  /// A bundled photograph for a built-in slide, e.g.
  /// `assets/images/banners/banner_library.jpg`. Client-only.
  ///
  /// Built-in slides ship a real photo rather than a flat gradient because a plain
  /// coloured card is what the owner read as "not a real slider". The photo is an
  /// app asset, so it renders offline and cannot be a broken remote URL. A gradient
  /// remains the fallback for an operator banner whose upload is missing.
  final String assetImage;

  /// True for a slide Mawatheq ships itself rather than one an operator uploaded.
  /// A built-in slide has no server image path; it draws a bundled photo or a
  /// branded gradient instead.
  bool get isPromo =>
      imagePath.isEmpty && (iconKey.isNotEmpty || assetImage.isNotEmpty);

  String eyebrow(bool isRtl) =>
      isRtl ? eyebrowAr : (eyebrowEn.isEmpty ? eyebrowAr : eyebrowEn);

  /// Relative to the site root, e.g. `uploads/banners/ab12.jpg`. The client resolves
  /// it against [AppConfig.siteBase] so the server does not have to know which host
  /// the app was built for.
  final String imagePath;

  final String altAr;
  final String altEn;

  /// One of `none`, `category`, `specialty`, `url`. The server has already verified
  /// that the target resolves, so an unresolvable banner arrives as `none`.
  final String linkType;
  final String? linkTarget;

  bool get isTappable => linkType != 'none' && (linkTarget ?? '').isNotEmpty;

  String title(bool isRtl) => isRtl ? titleAr : (titleEn.isEmpty ? titleAr : titleEn);

  String subtitle(bool isRtl) =>
      isRtl ? subtitleAr : (subtitleEn.isEmpty ? subtitleAr : subtitleEn);

  /// Alt text for a screen reader. Falls back to the title so an image is never
  /// announced as unlabelled.
  String alt(bool isRtl) {
    final value = isRtl ? altAr : (altEn.isEmpty ? altAr : altEn);
    return value.isEmpty ? title(isRtl) : value;
  }

  /// Absolute image URL, or null when the banner has no usable path.
  String? imageUrl(String siteBase) {
    if (imagePath.isEmpty) return null;
    final base = siteBase.endsWith('/')
        ? siteBase.substring(0, siteBase.length - 1)
        : siteBase;
    final path = imagePath.startsWith('/') ? imagePath : '/$imagePath';
    return '$base$path';
  }

  factory HomeBanner.fromJson(Map<String, dynamic> map) {
    final j = Json(map);
    final target = j.str('link_target');
    return HomeBanner(
      id: j.intg('id'),
      titleAr: j.str('title_ar'),
      titleEn: j.str('title_en'),
      subtitleAr: j.str('subtitle_ar'),
      subtitleEn: j.str('subtitle_en'),
      imagePath: j.str('image_path', fallback: j.str('image_url')),
      altAr: j.str('alt_ar'),
      altEn: j.str('alt_en'),
      linkType: j.str('link_type', fallback: 'none'),
      linkTarget: target.isEmpty ? null : target,
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

/// One legal specialty the customer can choose, from `GET /api/v1/journey.php?action=specialties`.
///
/// These are the customer-facing names and descriptions. The internal legal
/// classification behind them is deliberately absent from this model — the app
/// never receives it, so it can never render it.
class JourneySpecialty {
  const JourneySpecialty({
    required this.id,
    required this.name,
    this.description = '',
    this.iconKey = '',
    this.lawyerCount = 0,
  });

  final int id;
  final String name;
  final String description;
  final String iconKey;
  final int lawyerCount;

  /// A specialty nobody currently covers is still shown — it just says so.
  bool get hasLawyers => lawyerCount > 0;

  factory JourneySpecialty.fromJson(Map<String, dynamic> map) {
    final j = Json(map);
    return JourneySpecialty(
      id: j.intg('id'),
      name: j.str('name', fallback: j.str('name_ar')),
      description: j.str('description', fallback: j.str('customer_desc_ar')),
      iconKey: j.str('icon_key', fallback: j.str('customer_icon_key')),
      lawyerCount: j.intg('lawyer_count'),
    );
  }
}

/// One plain-language issue type within a specialty, from
/// `GET /api/v1/journey.php?action=issue-types&category_id=..`.
class JourneyIssueType {
  const JourneyIssueType({
    required this.id,
    required this.name,
    this.definition = '',
    this.group = '',
  });

  final int id;
  final String name;
  final String definition;
  final String group;

  factory JourneyIssueType.fromJson(Map<String, dynamic> map) {
    final j = Json(map);
    return JourneyIssueType(
      id: j.intg('id'),
      name: j.str('name', fallback: j.str('name_ar')),
      definition: j.str('definition'),
      group: j.str('group'),
    );
  }
}

/// The result of the journey's lawyer lookup: the matches plus, when there are
/// none, a customer-appropriate alternative sentence instead of an empty screen.
class JourneyMatch {
  const JourneyMatch({
    this.lawyers = const [],
    this.matched = false,
    this.alternative = '',
  });

  final List<LawyerProfile> lawyers;
  final bool matched;
  final String alternative;

  factory JourneyMatch.fromJson(Map<String, dynamic> map) {
    final j = Json(map);
    return JourneyMatch(
      lawyers: j.list('lawyers').map((e) => LawyerProfile.fromJson(e.asMap)).toList(),
      matched: j.boolean('matched'),
      alternative: j.str('alternative'),
    );
  }
}

/// One recording or document staged against a draft token, from
/// `POST/GET /api/v1/consultation-attachments.php`.
///
/// The server classifies the file (`file_category`: document/image/audio/video) after
/// sniffing its bytes, so the app shows the server's category rather than trusting the
/// filename. No stored path is ever exposed.
class StagedAttachment {
  const StagedAttachment({
    required this.id,
    required this.originalName,
    required this.category,
    this.extension = '',
    this.size = 0,
    this.sizeLabel = '',
    this.status = 'staged',
  });

  final int id;
  final String originalName;
  final String category;
  final String extension;
  final int size;
  final String sizeLabel;
  final String status;

  bool get isAudio => category == 'audio';
  bool get isVideo => category == 'video';

  factory StagedAttachment.fromJson(Map<String, dynamic> map) {
    final j = Json(map);
    return StagedAttachment(
      id: j.intg('id'),
      originalName: j.str('name'),
      category: j.str('category'),
      extension: j.str('extension'),
      size: j.intg('size'),
      sizeLabel: j.str('size_label'),
      status: j.str('status', fallback: 'staged'),
    );
  }
}

/// The staged files for one draft token plus the server's upload rules.
///
/// `allowedExtensions` and the per-category MB ceilings come from the server, so the
/// picker filters by the real allow-list and the UI states the real limits.
class JourneyStagedAttachments {
  const JourneyStagedAttachments({
    this.attachments = const [],
    this.allowedExtensions = const [],
    this.maxFiles = 0,
    this.documentMb = 0,
    this.audioMb = 0,
    this.videoMb = 0,
  });

  final List<StagedAttachment> attachments;
  final List<String> allowedExtensions;
  final int maxFiles;
  final int documentMb;
  final int audioMb;
  final int videoMb;

  bool get atLimit => maxFiles > 0 && attachments.length >= maxFiles;

  /// The ceiling that applies to one file of this category, for the pre-upload hint.
  /// Images ride the document limit, matching `consultation_attachment_limits`.
  int limitMbFor(String category) {
    switch (category) {
      case 'audio':
        return audioMb;
      case 'video':
        return videoMb;
      default:
        return documentMb;
    }
  }

  factory JourneyStagedAttachments.fromJson(Map<String, dynamic> map) {
    final j = Json(map);
    final limits = j.object('limits');
    return JourneyStagedAttachments(
      attachments: j
          .list('attachments')
          .map((e) => StagedAttachment.fromJson(e.asMap))
          .toList(),
      allowedExtensions: limits == null
          ? const []
          : limits.strList('allowed_extensions'),
      maxFiles: limits?.intg('max_files') ?? 0,
      documentMb: limits?.intg('document_mb') ?? 0,
      audioMb: limits?.intg('audio_mb') ?? 0,
      videoMb: limits?.intg('video_mb') ?? 0,
    );
  }
}
