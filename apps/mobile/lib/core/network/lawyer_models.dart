import 'models.dart';

/// The lawyer's dashboard figures from `GET /api/v1/lawyer-dashboard.php`.
///
/// Every value is read from the server; nothing here is derived or defaulted to a
/// plausible number. A missing key becomes zero because the server always sends the
/// key, and a zero is the honest reading of "no rows" rather than an invented one.
class LawyerDashboard {
  const LawyerDashboard({
    required this.role,
    required this.isFirm,
    this.firmName = '',
    this.pending = 0,
    this.confirmed = 0,
    this.completed = 0,
    this.cancelled = 0,
    this.totalConsultations = 0,
    this.casesActive = 0,
    this.clients = 0,
    this.tasksOpen = 0,
    this.notificationsUnread = 0,
    this.wallet = const WalletSummary.empty(),
    this.upcoming = const <UpcomingAppointment>[],
    this.activity = const <ActivityItem>[],
  });

  final String role;
  final bool isFirm;
  final String firmName;
  final int pending;
  final int confirmed;
  final int completed;
  final int cancelled;
  final int totalConsultations;
  final int casesActive;
  final int clients;
  final int tasksOpen;
  final int notificationsUnread;
  final WalletSummary wallet;
  final List<UpcomingAppointment> upcoming;
  final List<ActivityItem> activity;

  factory LawyerDashboard.fromJson(Map<String, dynamic> map) {
    final j = Json(map);
    final c = j.object('consultations') ?? const Json(<String, dynamic>{});
    final lawyer = j.object('lawyer') ?? const Json(<String, dynamic>{});
    return LawyerDashboard(
      role: lawyer.str('role', fallback: 'solo'),
      isFirm: lawyer.boolean('is_firm'),
      pending: c.intg('pending'),
      confirmed: c.intg('confirmed'),
      completed: c.intg('completed'),
      cancelled: c.intg('cancelled'),
      totalConsultations: c.intg('total'),
      casesActive: j.intg('cases_active'),
      clients: j.intg('clients'),
      tasksOpen: j.intg('tasks_open'),
      notificationsUnread: j.intg('notifications_unread'),
      wallet: WalletSummary.fromJson(j.object('wallet')?.asMap ?? const <String, dynamic>{}),
      upcoming: j
          .list('upcoming_appointments')
          .map((e) => UpcomingAppointment.fromJson(e.asMap))
          .toList(),
      activity: j.list('recent_activity').map((e) => ActivityItem.fromJson(e.asMap)).toList(),
    );
  }
}

/// A confirmed consultation in the near future.
class UpcomingAppointment {
  const UpcomingAppointment({
    required this.id,
    this.topic = '',
    this.consultationType = 'text',
    this.scheduledAt = '',
    this.clientName = '',
  });

  final int id;
  final String topic;
  final String consultationType;
  final String scheduledAt;
  final String clientName;

  factory UpcomingAppointment.fromJson(Map<String, dynamic> map) {
    final j = Json(map);
    return UpcomingAppointment(
      id: j.intg('id'),
      topic: j.str('topic'),
      consultationType: j.str('consultation_type', fallback: 'text'),
      scheduledAt: j.str('scheduled_at'),
      clientName: j.str('client_name'),
    );
  }
}

/// One row of the dashboard's recent-activity feed.
class ActivityItem {
  const ActivityItem({
    required this.kind,
    required this.id,
    this.title = '',
    this.status = '',
    this.at = '',
  });

  final String kind; // consultation | case
  final int id;
  final String title;
  final String status;
  final String at;

  factory ActivityItem.fromJson(Map<String, dynamic> map) {
    final j = Json(map);
    return ActivityItem(
      kind: j.str('kind', fallback: 'consultation'),
      id: j.intg('id'),
      title: j.str('title'),
      status: j.str('status'),
      at: j.str('at'),
    );
  }
}

/// The wallet figures from `GET /api/v1/wallet.php?action=summary`.
class WalletSummary {
  const WalletSummary({
    this.currency = 'SAR',
    this.status = 'active',
    this.availableMinor = 0,
    this.pendingMinor = 0,
    this.reservedMinor = 0,
    this.available = '0.00',
    this.pending = '0.00',
    this.reserved = '0.00',
    this.grossEarned = '0.00',
    this.netEarned = '0.00',
    this.commission = '0.00',
    this.withdrawn = '0.00',
    this.payoutDetails = '',
  });

  final String currency;
  final String status;
  final int availableMinor;
  final int pendingMinor;
  final int reservedMinor;
  final String available;
  final String pending;
  final String reserved;
  final String grossEarned;
  final String netEarned;
  final String commission;
  final String withdrawn;
  final String payoutDetails;

  const WalletSummary.empty() : this();

  /// What can actually be requested right now: the settled balance minus what is
  /// already reserved by a pending request. Computed here only for display — the
  /// server refuses an over-request regardless.
  String get requestable => available;

  factory WalletSummary.fromJson(Map<String, dynamic> map) {
    final j = Json(map);
    return WalletSummary(
      currency: j.str('currency', fallback: 'SAR'),
      status: j.str('status', fallback: 'active'),
      availableMinor: j.intg('available_minor'),
      pendingMinor: j.intg('pending_minor'),
      reservedMinor: j.intg('reserved_minor'),
      available: j.str('available', fallback: '0.00'),
      pending: j.str('pending', fallback: '0.00'),
      reserved: j.str('reserved', fallback: '0.00'),
      grossEarned: j.str('gross_earned', fallback: '0.00'),
      netEarned: j.str('net_earned', fallback: '0.00'),
      commission: j.str('commission', fallback: '0.00'),
      withdrawn: j.str('withdrawn', fallback: '0.00'),
      payoutDetails: j.str('payout_details'),
    );
  }
}

/// One ledger row from the wallet statement.
class WalletTransaction {
  const WalletTransaction({
    required this.id,
    required this.kind,
    this.amountMinor = 0,
    this.amount = '0.00',
    this.state = 'available',
    this.description = '',
    this.createdAt = '',
  });

  final int id;
  final String kind;
  final int amountMinor;
  final String amount;
  final String state;
  final String description;
  final String createdAt;

  /// A debit is shown with a leading minus; the ledger stores a signed value, so the
  /// sign is the data, not a display choice.
  bool get isDebit => amountMinor < 0;

  factory WalletTransaction.fromJson(Map<String, dynamic> map) {
    final j = Json(map);
    final minor = j.intg('amount_minor');
    final currency = j.str('currency', fallback: 'SAR');
    return WalletTransaction(
      id: j.intg('id'),
      kind: j.str('kind'),
      amountMinor: minor,
      amount: j.str('amount', fallback: _formatMinor(minor, currency)),
      state: j.str('state', fallback: 'available'),
      description: j.str('description'),
      createdAt: j.str('created_at'),
    );
  }
}

/// A withdrawal request and where it is in the review flow.
class WalletWithdrawal {
  const WalletWithdrawal({
    required this.id,
    required this.status,
    this.amount = '0.00',
    this.amountMinor = 0,
    this.currency = 'SAR',
    this.method = 'bank_transfer',
    this.requestedNote = '',
    this.adminNote = '',
    this.reference = '',
    this.createdAt = '',
    this.reviewedAt = '',
  });

  final int id;
  final String status;
  final String amount;
  final int amountMinor;
  final String currency;
  final String method;
  final String requestedNote;
  final String adminNote;
  final String reference;
  final String createdAt;
  final String reviewedAt;

  factory WalletWithdrawal.fromJson(Map<String, dynamic> map) {
    final j = Json(map);
    final minor = j.intg('amount_minor');
    final currency = j.str('currency', fallback: 'SAR');
    return WalletWithdrawal(
      id: j.intg('id'),
      status: j.str('status', fallback: 'pending'),
      amountMinor: minor,
      amount: j.str('amount', fallback: _formatMinor(minor, currency)),
      currency: currency,
      method: j.str('method', fallback: 'bank_transfer'),
      requestedNote: j.str('requested_note'),
      adminNote: j.str('admin_note'),
      reference: j.str('reference'),
      createdAt: j.str('created_at'),
      reviewedAt: j.str('reviewed_at'),
    );
  }
}

/// The statement for a period: opening, movement and closing, plus the rows.
class WalletStatement {
  const WalletStatement({
    this.opening = '0.00',
    this.movement = '0.00',
    this.closing = '0.00',
    this.rows = const <WalletTransaction>[],
  });

  final String opening;
  final String movement;
  final String closing;
  final List<WalletTransaction> rows;

  factory WalletStatement.fromJson(Map<String, dynamic> map) {
    final j = Json(map);
    final currency = j.str('currency', fallback: 'SAR');
    return WalletStatement(
      opening: _formatMinor(j.intg('opening_minor'), currency),
      movement: _formatMinor(j.intg('movement_minor'), currency),
      closing: _formatMinor(j.intg('closing_minor'), currency),
      rows: ((map['statement']?['rows'] as List?) ?? const [])
          .whereType<Map>()
          .map((e) => WalletTransaction.fromJson(e.cast<String, dynamic>()))
          .toList(),
    );
  }
}

/// Format a minor-unit integer the way the platform does, without the server's help.
///
/// Used only when the server did not send a preformatted string. The currency's
/// decimals are per-currency on this platform (SAR is 2, the legacy BHD is 3), so the
/// decimals come from the code, not a fixed 2.
String _formatMinor(int minor, String currency) {
  const threeDecimal = {'BHD', 'KWD', 'OMR', 'JOD'};
  final decimals = threeDecimal.contains(currency.toUpperCase()) ? 3 : 2;
  final divisor = decimals == 3 ? 1000 : 100;
  final negative = minor < 0;
  final abs = minor.abs();
  final whole = abs ~/ divisor;
  final frac = (abs % divisor).toString().padLeft(decimals, '0');
  return '${negative ? '-' : ''}$whole.$frac';
}

/// A call session, as the API returns it.
class CallSession {
  const CallSession({
    required this.id,
    required this.consultationId,
    this.roomCode = '',
    this.callType = 'video',
    this.status = 'ringing',
    this.initiatedBy = 'client',
    this.startedAt = '',
    this.answeredAt = '',
    this.endedAt = '',
    this.durationSeconds,
    this.endReason = '',
    this.topic = '',
    this.lawyerName = '',
    this.clientName = '',
  });

  final int id;
  final int consultationId;
  final String roomCode;
  final String callType; // voice | video
  final String status; // ringing | accepted | ended | rejected | missed | cancelled | failed
  final String initiatedBy;
  final String startedAt;
  final String answeredAt;
  final String endedAt;
  final int? durationSeconds;
  final String endReason;
  final String topic;
  final String lawyerName;
  final String clientName;

  bool get isVideo => callType == 'video';
  bool get isRinging => status == 'ringing';
  bool get isActive => status == 'ringing' || status == 'accepted';

  /// `mm:ss` for a live or finished call.
  String get durationLabel {
    final s = durationSeconds ?? 0;
    final m = (s ~/ 60).toString().padLeft(2, '0');
    final sec = (s % 60).toString().padLeft(2, '0');
    return '$m:$sec';
  }

  factory CallSession.fromJson(Map<String, dynamic> map) {
    final j = Json(map);
    return CallSession(
      id: j.intg('id'),
      consultationId: j.intg('consultation_id'),
      roomCode: j.str('room_code'),
      callType: j.str('call_type', fallback: 'video'),
      status: j.str('status', fallback: 'ringing'),
      initiatedBy: j.str('initiated_by', fallback: 'client'),
      startedAt: j.str('started_at'),
      answeredAt: j.str('answered_at'),
      endedAt: j.str('ended_at'),
      durationSeconds: map['duration_seconds'] == null ? null : j.intg('duration_seconds'),
      endReason: j.str('end_reason'),
      topic: j.str('topic'),
      lawyerName: j.str('lawyer_name'),
      clientName: j.str('client_name'),
    );
  }
}

/// The media configuration the server hands the client for one call.
class CallJoinInfo {
  const CallJoinInfo({
    required this.call,
    required this.token,
    this.expiresAt = '',
    this.iceServers = const <Map<String, dynamic>>[],
  });

  final CallSession call;
  final String token;
  final String expiresAt;
  final List<Map<String, dynamic>> iceServers;

  factory CallJoinInfo.fromJson(Map<String, dynamic> map) {
    final j = Json(map);
    return CallJoinInfo(
      call: CallSession.fromJson(j.object('call')?.asMap ?? const <String, dynamic>{}),
      token: j.str('token'),
      expiresAt: j.str('expires_at'),
      iceServers: ((map['ice_servers'] as List?) ?? const [])
          .whereType<Map>()
          .map((e) => e.cast<String, dynamic>())
          .toList(),
    );
  }
}

/// One relayed SDP/ICE signal from the other party.
class CallSignal {
  const CallSignal({required this.id, this.from = '', this.kind = '', this.data});

  final int id;
  final String from;
  final String kind; // offer | answer | ice | bye | renegotiate
  final dynamic data;

  factory CallSignal.fromJson(Map<String, dynamic> map) {
    final j = Json(map);
    return CallSignal(id: j.intg('id'), from: j.str('from'), kind: j.str('kind'), data: map['data']);
  }
}

/// Whether calls are possible at all, and the ICE servers to use.
class CallState {
  const CallState({
    required this.available,
    this.blocking,
    this.message = '',
    this.turn = false,
    this.iceServers = const <Map<String, dynamic>>[],
  });

  final bool available;
  final String? blocking;
  final String message;
  final bool turn;
  final List<Map<String, dynamic>> iceServers;

  factory CallState.fromJson(Map<String, dynamic> map) {
    final j = Json(map);
    return CallState(
      available: j.raw['available'] == true,
      blocking: j.str('blocking').isEmpty ? null : j.str('blocking'),
      message: j.str('message'),
      turn: j.raw['turn'] == true,
      iceServers: ((map['ice_servers'] as List?) ?? const [])
          .whereType<Map>()
          .map((e) => e.cast<String, dynamic>())
          .toList(),
    );
  }
}

/// The lawyer's professional profile from `GET /api/v1/lawyer-profile.php`.
///
/// `status` and `emailVerified` are read-only: the admin console owns them. They are
/// exposed so the profile screen can explain why an unapproved lawyer is not visible
/// to clients, not so the app can change them.
class LawyerProfileDetail {
  const LawyerProfileDetail({
    required this.id,
    this.fullName = '',
    this.email = '',
    this.phone = '',
    this.specialization = '',
    this.bio = '',
    this.yearsExperience = 0,
    this.licenseNumber = '',
    this.photoPath = '',
    this.qualifications = '',
    this.socialLinkedin = '',
    this.socialTwitter = '',
    this.socialWebsite = '',
    this.jurisdiction = '',
    this.hourlyRate = 0,
    this.rating = 0,
    this.status = 'pending',
    this.emailVerified = false,
    this.createdAt = '',
    this.specialtyIds = const <int>[],
    this.availability = const <AvailabilitySlot>[],
  });

  final int id;
  final String fullName;
  final String email;
  final String phone;
  final String specialization;
  final String bio;
  final int yearsExperience;
  final String licenseNumber;
  final String photoPath;
  final String qualifications;
  final String socialLinkedin;
  final String socialTwitter;
  final String socialWebsite;
  final String jurisdiction;
  final double hourlyRate;
  final double rating;
  final String status;
  final bool emailVerified;
  final String createdAt;
  final List<int> specialtyIds;
  final List<AvailabilitySlot> availability;

  bool get isApproved => status == 'approved';
  bool get isVisibleToClients => isApproved && emailVerified;

  factory LawyerProfileDetail.fromJson(Map<String, dynamic> map) {
    final j = Json(map);
    final p = j.object('profile') ?? const Json(<String, dynamic>{});
    return LawyerProfileDetail(
      id: p.intg('id'),
      fullName: p.str('full_name'),
      email: p.str('email'),
      phone: p.str('phone'),
      specialization: p.str('specialization'),
      bio: p.str('bio'),
      yearsExperience: p.intg('years_experience'),
      licenseNumber: p.str('license_number'),
      photoPath: p.str('photo_path'),
      qualifications: p.str('qualifications'),
      socialLinkedin: p.str('social_linkedin'),
      socialTwitter: p.str('social_twitter'),
      socialWebsite: p.str('social_website'),
      jurisdiction: p.str('jurisdiction'),
      hourlyRate: p.dbl('hourly_rate'),
      rating: p.dbl('rating'),
      status: p.str('status', fallback: 'pending'),
      emailVerified: p.boolean('email_verified'),
      createdAt: p.str('created_at'),
      // `specialty_ids` is a list of plain integers, so `Json.list` (which keeps only
      // objects) drops them; read the raw list and coerce each entry.
      specialtyIds: ((map['specialty_ids'] as List?) ?? const [])
          .whereType<num>()
          .map((e) => e.toInt())
          .where((v) => v > 0)
          .toList(),
      availability: j.list('availability').map((e) => AvailabilitySlot.fromJson(e.asMap)).toList(),
    );
  }
}

/// One weekly availability window.
class AvailabilitySlot {
  const AvailabilitySlot({
    required this.dayOfWeek,
    required this.startTime,
    required this.endTime,
    this.slotMinutes = 30,
  });

  final int dayOfWeek; // 0 = Sunday, matching the backend
  final String startTime; // HH:MM
  final String endTime;
  final int slotMinutes;

  /// Public so the picker can build the same list the label uses; a private copy in
  /// the screen would be a second source of truth for the day order.
  static const dayNames = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];

  String get dayLabel => (dayOfWeek >= 0 && dayOfWeek < dayNames.length) ? dayNames[dayOfWeek] : '';

  factory AvailabilitySlot.fromJson(Map<String, dynamic> map) {
    final j = Json(map);
    return AvailabilitySlot(
      dayOfWeek: j.intg('day_of_week'),
      startTime: j.str('start_time'),
      endTime: j.str('end_time'),
      slotMinutes: j.intg('slot_minutes', fallback: 30),
    );
  }

  Map<String, dynamic> toJson() => {
        'day_of_week': dayOfWeek,
        'start_time': startTime,
        'end_time': endTime,
        'slot_minutes': slotMinutes,
      };
}

/// The lawyer's account settings from `GET /api/v1/lawyer-profile.php?action=settings`.
class LawyerSettings {
  const LawyerSettings({
    this.notifyInApp = true,
    this.notifyEmail = true,
    this.profileVisible = true,
    this.activeSessions = 0,
  });

  final bool notifyInApp;
  final bool notifyEmail;
  final bool profileVisible;
  final int activeSessions;

  factory LawyerSettings.fromJson(Map<String, dynamic> map) {
    final j = Json(map);
    final s = j.object('settings') ?? const Json(<String, dynamic>{});
    return LawyerSettings(
      notifyInApp: s.boolean('notify_in_app', fallback: true),
      notifyEmail: s.boolean('notify_email', fallback: true),
      profileVisible: s.boolean('profile_visible', fallback: true),
      activeSessions: s.intg('active_sessions'),
    );
  }
}

/// One selectable specialty from the catalogue.
class SpecialtyOption {
  const SpecialtyOption({required this.id, this.nameAr = '', this.nameEn = ''});

  final int id;
  final String nameAr;
  final String nameEn;

  String get label => nameAr.isNotEmpty ? nameAr : nameEn;

  factory SpecialtyOption.fromJson(Map<String, dynamic> map) {
    final j = Json(map);
    return SpecialtyOption(id: j.intg('id'), nameAr: j.str('name_ar'), nameEn: j.str('name_en'));
  }
}
