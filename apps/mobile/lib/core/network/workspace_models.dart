/// Models for the lawyer workspace features added in Execution 68.
///
/// Every field maps to a key the server actually sends. Nothing is computed here: a
/// `can_use` flag, a limit or a count is carried through from the API so the app cannot
/// disagree with the gate that enforces it.

library;

/// `GET /api/v1/firm.php`
class FirmInfo {
  const FirmInfo({
    required this.id,
    required this.name,
    required this.role,
    required this.canManage,
    this.displayName,
    this.email,
    this.phone,
    this.city,
    this.about,
    this.website,
    this.isPublic = false,
    this.publicSlug,
    this.members = const <FirmMember>[],
  });

  final int id;
  final String name;
  final String role;
  final bool canManage;
  final String? displayName;
  final String? email;
  final String? phone;
  final String? city;
  final String? about;
  final String? website;
  final bool isPublic;
  final String? publicSlug;
  final List<FirmMember> members;

  static FirmInfo? fromJson(Map<String, dynamic> json) {
    final firm = json['firm'];
    if (firm is! Map) return null;
    final f = Map<String, dynamic>.from(firm);
    return FirmInfo(
      id: (f['id'] as num?)?.toInt() ?? 0,
      name: (f['name'] ?? f['display_name'] ?? '').toString(),
      role: (json['role'] ?? f['member_role'] ?? '').toString(),
      canManage: json['can_manage'] == true,
      displayName: f['display_name']?.toString(),
      email: f['email']?.toString(),
      phone: f['phone']?.toString(),
      city: f['city']?.toString(),
      about: (f['about_ar'] ?? f['about_en'])?.toString(),
      website: f['website_url']?.toString(),
      isPublic: f['is_public'] == true || f['is_public'] == 1,
      publicSlug: f['public_slug']?.toString(),
      members: ((json['members'] as List?) ?? const [])
          .whereType<Map>()
          .map((m) => FirmMember.fromJson(Map<String, dynamic>.from(m)))
          .toList(),
    );
  }
}

class FirmMember {
  const FirmMember({
    required this.name,
    required this.role,
    this.specialization,
    this.email,
  });

  final String name;
  final String role;
  final String? specialization;
  final String? email;

  factory FirmMember.fromJson(Map<String, dynamic> json) => FirmMember(
        name: (json['full_name'] ?? '').toString(),
        role: (json['member_role'] ?? '').toString(),
        specialization: json['specialization']?.toString(),
        email: json['email']?.toString(),
      );
}

/// `GET /api/v1/clients.php`
class LawyerClient {
  const LawyerClient({
    required this.id,
    required this.name,
    this.email,
    this.phone,
    this.casesCount = 0,
    this.consultationsCount = 0,
  });

  final int id;
  final String name;
  final String? email;
  final String? phone;
  final int casesCount;
  final int consultationsCount;

  factory LawyerClient.fromJson(Map<String, dynamic> json) => LawyerClient(
        id: (json['id'] as num?)?.toInt() ?? 0,
        name: (json['full_name'] ?? '').toString(),
        email: json['email']?.toString(),
        phone: json['phone']?.toString(),
        casesCount: (json['cases_count'] as num?)?.toInt() ?? 0,
        consultationsCount: (json['consultations_count'] as num?)?.toInt() ?? 0,
      );
}

/// `GET /api/v1/templates.php`
class LegalTemplate {
  const LegalTemplate({
    required this.id,
    required this.title,
    required this.planTier,
    required this.canUse,
    this.category,
    this.description,
  });

  final int id;
  final String title;
  final String planTier;
  final bool canUse;
  final String? category;
  final String? description;

  factory LegalTemplate.fromJson(Map<String, dynamic> json) => LegalTemplate(
        id: (json['id'] as num?)?.toInt() ?? 0,
        title: (json['title_ar'] ?? json['title_en'] ?? '').toString(),
        planTier: (json['plan_tier'] ?? 'free').toString(),
        canUse: json['can_use'] == true,
        category: (json['category_ar'] ?? json['category_en'])?.toString(),
        description: (json['description_ar'] ?? json['description_en'])?.toString(),
      );
}

/// `GET /api/v1/chatbot.php`
class FirmChatbot {
  const FirmChatbot({
    required this.available,
    this.reason,
    this.displayName,
    this.greeting,
    this.isEnabled = false,
    this.publicKey,
    this.totalConversations = 0,
    this.openConversations = 0,
  });

  final bool available;
  final String? reason;
  final String? displayName;
  final String? greeting;
  final bool isEnabled;
  final String? publicKey;
  final int totalConversations;
  final int openConversations;

  factory FirmChatbot.fromJson(Map<String, dynamic> json) {
    final bot = json['bot'];
    final b = bot is Map ? Map<String, dynamic>.from(bot) : const <String, dynamic>{};
    final stats = json['stats'];
    final s = stats is Map ? Map<String, dynamic>.from(stats) : const <String, dynamic>{};
    return FirmChatbot(
      available: json['available'] == true,
      reason: json['reason']?.toString(),
      displayName: (b['display_name_ar'] ?? b['display_name_en'])?.toString(),
      greeting: (b['greeting_ar'] ?? b['greeting_en'])?.toString(),
      isEnabled: b['is_enabled'] == true,
      publicKey: b['public_key']?.toString(),
      totalConversations: (s['total'] as num?)?.toInt() ?? 0,
      openConversations: (s['open'] as num?)?.toInt() ?? 0,
    );
  }
}

/// One row of `GET /api/v1/subscription.php` → `limits`.
class PlanLimit {
  const PlanLimit({
    required this.key,
    required this.enforced,
    this.limit,
    this.used,
    this.remaining,
    this.exhausted = false,
  });

  final String key;
  final bool enforced;
  final int? limit;
  final int? used;
  final int? remaining;

  /// Server-computed: the gate will refuse the next create. Carried through rather than
  /// re-derived from `remaining`, so the app and the enforcement point cannot drift.
  final bool exhausted;

  factory PlanLimit.fromJson(Map<String, dynamic> json) => PlanLimit(
        key: (json['key'] ?? '').toString(),
        enforced: json['enforced'] == true,
        limit: (json['limit'] as num?)?.toInt(),
        used: (json['used'] as num?)?.toInt(),
        remaining: (json['remaining'] as num?)?.toInt(),
        exhausted: json['exhausted'] == true,
      );

  /// Arabic label for the limit key. Unknown keys fall back to the raw key so a new
  /// limit added on the server still renders instead of disappearing.
  String get labelAr {
    const map = {
      'max_staff': 'عدد الموظفين',
      'max_clients': 'عدد الموكلين',
      'max_active_cases': 'القضايا النشطة',
      'storage_mb': 'مساحة التخزين (م.ب)',
      'max_templates': 'القوالب',
      'ai_queries_limit': 'استعلامات الذكاء الاصطناعي',
      'document_analysis_limit': 'تحليل المستندات',
    };
    return map[key] ?? key;
  }
}

class SubscriptionInfo {
  const SubscriptionInfo({
    required this.currency,
    this.planName,
    this.priceMonthly,
    this.priceYearly,
    this.limits = const <PlanLimit>[],
  });

  final String currency;
  final String? planName;

  /// Preformatted by the server's currency helper (`currency_number`). The raw
  /// `plans.price_monthly` is a DECIMAL(10,3) and reads back as "149.000" even when the
  /// currency has two decimals, so the display value is what a screen must print.
  final String? priceMonthly;
  final String? priceYearly;
  final List<PlanLimit> limits;

  factory SubscriptionInfo.fromJson(Map<String, dynamic> json) {
    final plan = json['plan'];
    final p = plan is Map ? Map<String, dynamic>.from(plan) : const <String, dynamic>{};
    return SubscriptionInfo(
      currency: (json['currency'] ?? 'SAR').toString(),
      planName: (p['name_ar'] ?? p['name_en'])?.toString(),
      priceMonthly: (p['price_monthly_display'] ?? p['price_monthly'])?.toString(),
      priceYearly: (p['price_yearly_display'] ?? p['price_yearly'])?.toString(),
      limits: ((json['limits'] as List?) ?? const [])
          .whereType<Map>()
          .map((l) => PlanLimit.fromJson(Map<String, dynamic>.from(l)))
          .toList(),
    );
  }
}
