import 'dart:io';

import 'api_client.dart';
import 'api_exception.dart';
import 'lawyer_models.dart';
import 'models.dart';

/// Reads and writes through the platform's existing `/api/v1/` endpoints.
///
/// Each method maps to exactly one documented endpoint. Nothing here invents a
/// capability the backend does not already provide — where an endpoint is missing
/// (drafting, firm HR/CRM, 2FA) the app documents the gap instead of faking it.
class ApiRepository {
  ApiRepository({ApiClient? client}) : _client = client ?? ApiClient();

  final ApiClient _client;

  ApiClient get client => _client;

  // ---------------------------------------------------------------- auth

  /// `POST /api/v1/auth/login.php`
  ///
  /// Returns `null` when the server answered 202 (email verification still
  /// required): there is no token in that case by design.
  Future<LoginOutcome> login({
    required String email,
    required String password,
    String deviceLabel = 'Flutter',
  }) async {
    final json = await _client.post(
      '/auth/login.php',
      auth: false,
      body: {
        'email': email,
        'password': password,
        'device_label': deviceLabel,
      },
    );

    final token = json['token']?.toString();
    if (token == null || token.isEmpty) {
      return LoginOutcome(verificationRequired: true, user: SessionUser.empty);
    }
    return LoginOutcome(
      verificationRequired: false,
      token: token,
      user: SessionUser.fromJson(json),
    );
  }

  /// `POST /api/v1/auth/lawyer-login.php`
  Future<LoginOutcome> lawyerLogin({
    required String email,
    required String password,
    String deviceLabel = 'Flutter',
  }) async {
    final json = await _client.post(
      '/auth/lawyer-login.php',
      auth: false,
      body: {
        'email': email,
        'password': password,
        'device_label': deviceLabel,
      },
    );
    return LoginOutcome(
      verificationRequired: false,
      token: json['token']?.toString(),
      user: SessionUser.fromJson(json),
    );
  }

  /// `POST /api/v1/auth/register.php` (client accounts)
  ///
  /// `accountType` must be `individual` or `company` — the endpoint rejects
  /// anything else, so it is a required argument here rather than a default.
  /// No token is returned: the backend requires email verification first.
  Future<LoginOutcome> register({
    required String fullName,
    required String email,
    required String password,
    required String accountType,
    String? phone,
  }) async {
    final json = await _client.post(
      '/auth/register.php',
      auth: false,
      body: {
        'full_name': fullName,
        'email': email,
        'password': password,
        'account_type': accountType,
        if (phone != null && phone.isNotEmpty) 'phone': phone,
      },
    );
    return LoginOutcome(
      verificationRequired: json['verification_required'] == true,
      token: json['token']?.toString(),
      user: SessionUser.fromJson(json),
      emailSent: json['email_sent'] is bool ? json['email_sent'] as bool : null,
    );
  }

  /// `GET /api/v1/me.php` — validates the stored token on app start.
  Future<SessionUser> me() async {
    final json = await _client.get('/me.php');
    return SessionUser.fromJson(json);
  }

  /// `POST /api/v1/auth/forgot-password.php`
  ///
  /// Always succeeds from the caller's point of view: the endpoint gives the same
  /// answer whether or not the address exists, so the app must not branch on the
  /// result. The reset link is only ever emailed.
  Future<void> requestPasswordReset({
    required String email,
    String accountType = 'client',
  }) async {
    await _client.post(
      '/auth/forgot-password.php',
      auth: false,
      body: {'email': email, 'account_type': accountType},
    );
  }

  /// `POST /api/v1/auth/reset-password.php`
  ///
  /// Consumes the single-use token from the reset email and sets the new password.
  /// Throws [ApiException] carrying `invalid_token`, `weak_password`, `too_long`
  /// or `mismatch` when the server refuses.
  Future<void> resetPassword({
    required String token,
    required String password,
    required String passwordConfirm,
    String accountType = 'client',
  }) async {
    await _client.post(
      '/auth/reset-password.php',
      auth: false,
      body: {
        'token': token,
        'password': password,
        'password_confirm': passwordConfirm,
        'account_type': accountType,
      },
    );
  }

  /// `POST /api/v1/revoke-token.php` — server-side logout.
  Future<void> revokeToken() async {
    try {
      await _client.post('/revoke-token.php');
    } on ApiException {
      // Even if the server call fails the local token is cleared by the caller,
      // so the device never keeps a usable token it has asked to discard.
    }
  }

  /// `POST /api/v1/account/delete.php` — anonymise and deactivate this account.
  ///
  /// Google Play requires an in-app deletion route. The server verifies the password
  /// and refuses while work is in flight; an `ApiException` carries its message so the
  /// screen can show the blockers rather than a generic failure.
  Future<void> deleteAccount({
    required String password,
    String confirm = 'DELETE',
  }) async {
    await _client.post(
      '/account/delete.php',
      body: {'password': password, 'confirm': confirm},
    );
  }

  /// `POST /api/v1/rotate-token.php` — issue a new token for this device.
  Future<String?> rotateToken(String deviceLabel) async {
    final json = await _client.post(
      '/rotate-token.php',
      body: {'device_label': deviceLabel},
    );
    return json['token']?.toString();
  }

  // ------------------------------------------------------------- cases

  Future<List<CaseSummary>> cases() async {
    final json = await _client.get('/cases.php');
    return json['cases'] is List
        ? (json['cases'] as List)
            .whereType<Map>()
            .map((e) => CaseSummary.fromJson(Map<String, dynamic>.from(e)))
            .toList()
        : <CaseSummary>[];
  }

  /// `GET /api/v1/cases.php?id=` — includes timeline, parties, claims, tasks.
  Future<Map<String, dynamic>> caseDetail(int id) =>
      _client.get('/cases.php', query: {'id': id});

  // --------------------------------------------------------- documents

  Future<List<DocumentSummary>> documents() async {
    final json = await _client.get('/documents.php');
    return json['documents'] is List
        ? (json['documents'] as List)
            .whereType<Map>()
            .map((e) => DocumentSummary.fromJson(Map<String, dynamic>.from(e)))
            .toList()
        : <DocumentSummary>[];
  }

  Future<Map<String, dynamic>> documentDetail(int id) =>
      _client.get('/documents.php', query: {'id': id});

  /// Upload + AI analysis. Consent is mandatory server-side: without
  /// `aiConsent: true` the endpoint refuses to send the file to the AI provider.
  ///
  /// Lawyers must pass `caseId` — their documents are reached through the case they
  /// belong to, and the server rejects an upload that is not inside their tenant.
  Future<Map<String, dynamic>> uploadDocument({
    required File file,
    required bool aiConsent,
    String? documentType,
    String? caseId,
  }) =>
      _client.uploadDocument(
        file: file,
        aiConsent: aiConsent,
        documentType: documentType,
        caseId: caseId,
      );

  // ------------------------------------------------------ consultations

  Future<List<Consultation>> consultations() async {
    final json = await _client.get('/consultations.php');
    return json['consultations'] is List
        ? (json['consultations'] as List)
            .whereType<Map>()
            .map((e) => Consultation.fromJson(Map<String, dynamic>.from(e)))
            .toList()
        : <Consultation>[];
  }

  /// `POST /api/v1/consultations.php` (client accounts only).
  ///
  /// `specialtyCategoryId` and `issueTypeId` carry the customer's journey choice so
  /// the lawyer receives the internal legal classification. Both are optional: a
  /// booking that skipped the journey is unchanged.
  ///
  /// `draftToken` is the token the customer's staged recordings/documents were
  /// uploaded against. Passing it binds those files to the consultation the server
  /// creates here; omitting it books exactly as before.
  Future<int> bookConsultation({
    required int lawyerId,
    required String consultationType,
    required String date,
    required String time,
    String? topic,
    int? specialtyCategoryId,
    int? issueTypeId,
    String? draftToken,
  }) async {
    final json = await _client.post(
      '/consultations.php',
      body: {
        'lawyer_id': lawyerId,
        'consultation_type': consultationType,
        'date': date,
        'time': time,
        if (topic != null && topic.isNotEmpty) 'topic': topic,
        if (specialtyCategoryId != null && specialtyCategoryId > 0)
          'specialty_category_id': specialtyCategoryId,
        if (issueTypeId != null && issueTypeId > 0) 'issue_type_id': issueTypeId,
        if (draftToken != null && draftToken.isNotEmpty)
          'draft_token': draftToken,
      },
    );
    return Json(json).intg('consultation_id');
  }

  /// Stages one recording/document against a draft token, before the consultation
  /// exists. Returns the stored metadata so the form can list and remove it.
  Future<StagedAttachment> uploadJourneyAttachment({
    required File file,
    required String draftToken,
  }) async {
    final json = await _client.uploadConsultationAttachment(
      file: file,
      draftToken: draftToken,
    );
    final map = json['attachment'];
    return map is Map
        ? StagedAttachment.fromJson(Map<String, dynamic>.from(map))
        : const StagedAttachment(id: 0, originalName: '', category: '');
  }

  /// Removes a staged attachment the customer decided against.
  Future<void> deleteJourneyAttachment(int attachmentId) =>
      _client.deleteConsultationAttachment(attachmentId);

  /// The files already staged under this draft token, plus the server's own upload
  /// rules — the GET returns both, so the form never has to guess a limit and never
  /// needs a second round trip. Only the uploader can read their own staged files.
  Future<JourneyStagedAttachments> journeyStagedAttachments({
    required String draftToken,
  }) async {
    final json = await _client.get(
      '/consultation-attachments.php',
      query: {'draft_token': draftToken},
    );
    return JourneyStagedAttachments.fromJson(json);
  }

  /// The files attached to a consultation that already exists — what the customer
  /// sees after submitting, and what a lawyer sees on their queue. The server decides
  /// who may read them, so the app does not filter by role itself.
  Future<List<StagedAttachment>> consultationAttachments(int consultationId) async {
    final json = await _client.get(
      '/consultation-attachments.php',
      query: {'consultation_id': consultationId},
    );
    return json['attachments'] is List
        ? (json['attachments'] as List)
            .whereType<Map>()
            .map((e) => StagedAttachment.fromJson(Map<String, dynamic>.from(e)))
            .toList()
        : <StagedAttachment>[];
  }

  // ------------------------------------------------------- consultation journey

  /// The legal specialties the customer chooses from.
  ///
  /// Public, like the lawyer directory. The response carries only customer-facing
  /// names — never the internal classification.
  Future<List<JourneySpecialty>> journeySpecialties({String? query}) async {
    final json = await _client.get(
      '/journey.php',
      auth: false,
      query: {
        'action': 'specialties',
        if (query != null && query.isNotEmpty) 'q': query,
      },
    );
    return json['specialties'] is List
        ? (json['specialties'] as List)
            .whereType<Map>()
            .map((e) => JourneySpecialty.fromJson(Map<String, dynamic>.from(e)))
            .toList()
        : <JourneySpecialty>[];
  }

  /// The plain-language issue types inside one specialty.
  Future<List<JourneyIssueType>> journeyIssueTypes({
    required int categoryId,
    String? query,
  }) async {
    final json = await _client.get(
      '/journey.php',
      auth: false,
      query: {
        'action': 'issue-types',
        'category_id': categoryId,
        if (query != null && query.isNotEmpty) 'q': query,
      },
    );
    return json['issue_types'] is List
        ? (json['issue_types'] as List)
            .whereType<Map>()
            .map((e) => JourneyIssueType.fromJson(Map<String, dynamic>.from(e)))
            .toList()
        : <JourneyIssueType>[];
  }

  /// The approved lawyers who provide one specialty, with a fallback message when
  /// there are none so the customer is never shown a dead end.
  Future<JourneyMatch> journeyLawyers({
    required int categoryId,
    String? jurisdiction,
    String? query,
  }) async {
    final json = await _client.get(
      '/journey.php',
      auth: false,
      query: {
        'action': 'lawyers',
        'category_id': categoryId,
        if (jurisdiction != null && jurisdiction.isNotEmpty) 'jurisdiction': jurisdiction,
        if (query != null && query.isNotEmpty) 'q': query,
      },
    );
    return JourneyMatch.fromJson(json);
  }

  // ------------------------------------------------------------ lawyers

  /// Public directory — no token required.
  Future<List<LawyerProfile>> lawyers({
    String? jurisdiction,
    String? specialization,
    String? query,
  }) async {
    final json = await _client.get(
      '/lawyers.php',
      auth: false,
      query: {
        if (jurisdiction != null && jurisdiction.isNotEmpty) 'jurisdiction': jurisdiction,
        if (specialization != null && specialization.isNotEmpty) 'specialization': specialization,
        if (query != null && query.isNotEmpty) 'q': query,
      },
    );
    return json['lawyers'] is List
        ? (json['lawyers'] as List)
            .whereType<Map>()
            .map((e) => LawyerProfile.fromJson(Map<String, dynamic>.from(e)))
            .toList()
        : <LawyerProfile>[];
  }

  /// Public slot list for booking.
  Future<List<String>> availableSlots({
    required int lawyerId,
    required String date,
  }) async {
    final json = await _client.get(
      '/available-slots.php',
      auth: false,
      query: {'lawyer_id': lawyerId, 'date': date},
    );
    return json['slots'] is List
        ? (json['slots'] as List).map((e) => e.toString()).toList()
        : <String>[];
  }

  // ----------------------------------------------------------- calendar

  Future<List<CalendarEvent>> calendar() async {
    final json = await _client.get('/calendar.php');
    final list = json['events'] ?? json['calendar_events'];
    return list is List
        ? list
            .whereType<Map>()
            .map((e) => CalendarEvent.fromJson(Map<String, dynamic>.from(e)))
            .toList()
        : <CalendarEvent>[];
  }

  // ----------------------------------------------------------- invoices

  Future<List<Invoice>> invoices() async {
    final json = await _client.get('/invoices.php');
    return json['invoices'] is List
        ? (json['invoices'] as List)
            .whereType<Map>()
            .map((e) => Invoice.fromJson(Map<String, dynamic>.from(e)))
            .toList()
        : <Invoice>[];
  }

  /// `POST /api/v1/pay-invoice.php` — returns the gateway URL to open in a WebView.
  Future<String?> payInvoice(int invoiceId) async {
    final json = await _client.post('/pay-invoice.php', body: {'invoice_id': invoiceId});
    return json['payment_url']?.toString();
  }

  /// `GET /api/v1/payment-status.php` — the real gateway state, not the cached one.
  Future<String> paymentStatus(int invoiceId) async {
    final json = await _client.get('/payment-status.php', query: {'invoice_id': invoiceId});
    return Json(json).str('status', fallback: 'unpaid');
  }

  // ------------------------------------------------------ notifications

  Future<NotificationPage> notifications() async {
    final json = await _client.get('/notifications.php');
    final list = json['notifications'];
    return NotificationPage(
      unreadCount: Json(json).intg('unread_count'),
      items: list is List
          ? list
              .whereType<Map>()
              .map((e) => AppNotification.fromJson(Map<String, dynamic>.from(e)))
              .toList()
          : <AppNotification>[],
    );
  }

  Future<void> markAllNotificationsRead() =>
      _client.post('/notifications.php', body: {'mark_all_read': true});

  // ---------------------------------------------------------------- AI

  /// `POST /api/v1/ai-chat.php`
  ///
  /// The app calls the platform; the platform performs laws-first retrieval and
  /// calls the configured provider. The app never contacts the model directly.
  Future<AiReply> askAssistant({
    required String message,
    int? conversationId,
  }) async {
    final json = await _client.post(
      '/ai-chat.php',
      body: {
        'message': message,
        if (conversationId != null) 'conversation_id': conversationId,
      },
    );
    return AiReply.fromJson(json);
  }

  /// `GET /api/v1/ai-status.php` — the real provider state, so the home screen
  /// can show "available / needs configuration" instead of a fixed indicator.
  Future<AiStatus> aiStatus({bool probe = false}) async {
    final json = await _client.get(
      '/ai-status.php',
      query: probe ? {'probe': 1} : null,
    );
    return AiStatus.fromJson(json);
  }

  // ------------------------------------------------- contract comparison

  Future<Map<String, dynamic>> contractComparisons() =>
      _client.get('/contract-comparisons.php');

  // -------------------------------------------------------------- plans

  /// `GET /api/v1/plans.php` — the published plans, no token required. The
  /// mobile pricing screen reads prices from here rather than hardcoding them.
  Future<List<Plan>> plans() async {
    final json = await _client.get('/plans.php', auth: false);
    return json['plans'] is List
        ? (json['plans'] as List)
            .whereType<Map>()
            .map((e) => Plan.fromJson(Map<String, dynamic>.from(e)))
            .toList()
        : <Plan>[];
  }

  // -------------------------------------------------------------- banners

  /// `GET /api/v1/banners.php` — the home slider, no token required.
  ///
  /// An empty list is a normal answer (a fresh install has no banners), and the
  /// home screen shows its empty state. Nothing is fabricated client-side: the
  /// brief forbids inventing promotions.
  Future<List<HomeBanner>> banners() async {
    final json = await _client.get('/banners.php', auth: false);
    return json['banners'] is List
        ? (json['banners'] as List)
            .whereType<Map>()
            .map((e) => HomeBanner.fromJson(Map<String, dynamic>.from(e)))
            .toList()
        : <HomeBanner>[];
  }

  // -------------------------------------------------------- lawyer surface

  /// `GET /api/v1/lawyer-dashboard.php` — the lawyer's own figures.
  Future<LawyerDashboard> lawyerDashboard() async {
    final json = await _client.get('/lawyer-dashboard.php');
    return LawyerDashboard.fromJson(json);
  }

  // -------------------------------------------------------- lawyer profile

  /// `GET /api/v1/lawyer-profile.php?action=profile`
  Future<LawyerProfileDetail> lawyerProfile() async {
    final json = await _client.get('/lawyer-profile.php', query: {'action': 'profile'});
    return LawyerProfileDetail.fromJson(json);
  }

  /// `GET /api/v1/lawyer-profile.php?action=settings`
  Future<LawyerSettings> lawyerSettings() async {
    final json = await _client.get('/lawyer-profile.php', query: {'action': 'settings'});
    return LawyerSettings.fromJson(json);
  }

  /// `GET /api/v1/lawyer-profile.php?action=specialties`
  Future<List<SpecialtyOption>> specialtyCatalogue() async {
    final json = await _client.get('/lawyer-profile.php', query: {'action': 'specialties'});
    return ((json['specialties'] as List?) ?? const [])
        .whereType<Map>()
        .map((e) => SpecialtyOption.fromJson(Map<String, dynamic>.from(e)))
        .toList();
  }

  /// `POST /api/v1/lawyer-profile.php` action=save-profile
  Future<void> saveLawyerProfile(Map<String, dynamic> fields) async {
    await _client.post('/lawyer-profile.php', body: {'action': 'save-profile', ...fields});
  }

  /// `POST /api/v1/lawyer-profile.php` action=save-specialties
  Future<void> saveSpecialties(List<int> categoryIds) async {
    await _client.post('/lawyer-profile.php', body: {
      'action': 'save-specialties',
      'category_ids': categoryIds,
    });
  }

  /// `POST /api/v1/lawyer-profile.php` action=save-availability
  Future<void> saveAvailability(List<Map<String, dynamic>> slots) async {
    await _client.post('/lawyer-profile.php', body: {
      'action': 'save-availability',
      'slots': slots,
    });
  }

  /// `POST /api/v1/lawyer-profile.php` action=save-settings
  Future<void> saveLawyerSettings({
    required bool inApp,
    required bool email,
    bool? profileVisible,
  }) async {
    await _client.post('/lawyer-profile.php', body: {
      'action': 'save-settings',
      'in_app': inApp,
      'email': email,
      if (profileVisible != null) 'profile_visible': profileVisible,
    });
  }

  /// `POST /api/v1/lawyer-profile.php` action=change-password
  Future<void> changeLawyerPassword({
    required String currentPassword,
    required String newPassword,
  }) async {
    await _client.post('/lawyer-profile.php', body: {
      'action': 'change-password',
      'current_password': currentPassword,
      'new_password': newPassword,
    });
  }

  /// `POST /api/v1/lawyer-profile.php` action=sign-out-all
  Future<void> signOutAllDevices() async {
    await _client.post('/lawyer-profile.php', body: {'action': 'sign-out-all'});
  }

  /// `POST /api/v1/consultations.php` with an `action` — confirm, complete or
  /// cancel a booking. The server scopes it to the lawyer's tenant.
  Future<void> changeConsultationStatus({
    required int consultationId,
    required String status,
  }) async {
    await _client.post('/consultations.php', body: {
      'action': 'change-status',
      'consultation_id': consultationId,
      'status': status,
    });
  }

  // --------------------------------------------------------------- wallet

  /// `GET /api/v1/wallet.php?action=summary`
  Future<WalletSummary> walletSummary() async {
    final json = await _client.get('/wallet.php', query: {'action': 'summary'});
    return WalletSummary.fromJson(json);
  }

  /// `GET /api/v1/wallet.php?action=statement`
  Future<WalletStatement> walletStatement({String? from, String? to}) async {
    final json = await _client.get('/wallet.php', query: {
      'action': 'statement',
      if (from != null) 'from': from,
      if (to != null) 'to': to,
    });
    return WalletStatement.fromJson(json);
  }

  /// `GET /api/v1/wallet.php?action=transactions`
  Future<List<WalletTransaction>> walletTransactions({int limit = 50, int offset = 0}) async {
    final json = await _client.get('/wallet.php', query: {
      'action': 'transactions',
      'limit': '$limit',
      'offset': '$offset',
    });
    return ((json['transactions'] as List?) ?? const [])
        .whereType<Map>()
        .map((e) => WalletTransaction.fromJson(Map<String, dynamic>.from(e)))
        .toList();
  }

  /// `GET /api/v1/wallet.php?action=withdrawals`
  Future<List<WalletWithdrawal>> walletWithdrawals() async {
    final json = await _client.get('/wallet.php', query: {'action': 'withdrawals'});
    return ((json['withdrawals'] as List?) ?? const [])
        .whereType<Map>()
        .map((e) => WalletWithdrawal.fromJson(Map<String, dynamic>.from(e)))
        .toList();
  }

  /// `POST /api/v1/wallet.php` action=request-withdrawal
  Future<void> requestWithdrawal({required String amount, String? method, String? note}) async {
    await _client.post('/wallet.php', body: {
      'action': 'request-withdrawal',
      'amount': amount,
      if (method != null) 'method': method,
      if (note != null) 'note': note,
    });
  }

  /// `POST /api/v1/wallet.php` action=save-payout
  Future<void> savePayoutDetails({
    required String method,
    required Map<String, String> details,
    String? holder,
  }) async {
    await _client.post('/wallet.php', body: {
      'action': 'save-payout',
      'method': method,
      if (holder != null) 'holder': holder,
      'details': details,
    });
  }

  // ---------------------------------------------------------------- calls

  /// `GET /api/v1/calls.php?action=state&consultation_id=N`
  Future<CallState> callState(int consultationId) async {
    final json = await _client.get('/calls.php', query: {
      'action': 'state',
      'consultation_id': '$consultationId',
    });
    return CallState.fromJson(json);
  }

  /// `POST /api/v1/calls.php` action=start — begin (or rejoin) a call.
  Future<CallJoinInfo> startCall({required int consultationId, required String callType}) async {
    final json = await _client.post('/calls.php', body: {
      'action': 'start',
      'consultation_id': consultationId,
      'call_type': callType,
    });
    return CallJoinInfo.fromJson(json);
  }

  /// `GET /api/v1/calls.php?action=token&call_id=N`
  Future<CallJoinInfo> callToken(int callId) async {
    final json = await _client.get('/calls.php', query: {'action': 'token', 'call_id': '$callId'});
    return CallJoinInfo.fromJson(json);
  }

  /// `POST /api/v1/calls.php` action=accept
  Future<CallSession> acceptCall(int callId) async {
    final json = await _client.post('/calls.php', body: {'action': 'accept', 'call_id': callId});
    return CallSession.fromJson(Map<String, dynamic>.from(json['call'] as Map));
  }

  /// `POST /api/v1/calls.php` action=reject
  Future<void> rejectCall(int callId) async {
    await _client.post('/calls.php', body: {'action': 'reject', 'call_id': callId});
  }

  /// `POST /api/v1/calls.php` action=end
  Future<void> endCall(int callId, {String reason = 'hangup'}) async {
    await _client.post('/calls.php', body: {'action': 'end', 'call_id': callId, 'reason': reason});
  }

  /// `POST /api/v1/calls.php` action=joined
  Future<void> callJoined(int callId, String token) async {
    await _client.post('/calls.php', body: {'action': 'joined', 'call_id': callId, 'token': token});
  }

  /// `POST /api/v1/calls.php` action=signal — relay one SDP/ICE payload.
  Future<int> sendCallSignal({
    required int callId,
    required String token,
    required String kind,
    dynamic data,
  }) async {
    final json = await _client.post('/calls.php', body: {
      'action': 'signal',
      'call_id': callId,
      'token': token,
      'payload': {'kind': kind, 'data': data},
    });
    return json['signal_id'] is num ? (json['signal_id'] as num).toInt() : 0;
  }

  /// `GET /api/v1/calls.php?action=poll` — drain the other party's signals.
  Future<List<CallSignal>> pollCallSignals({
    required int callId,
    required String token,
    required int afterId,
  }) async {
    final json = await _client.get('/calls.php', query: {
      'action': 'poll',
      'call_id': '$callId',
      'token': token,
      'after_id': '$afterId',
    });
    return ((json['signals'] as List?) ?? const [])
        .whereType<Map>()
        .map((e) => CallSignal.fromJson(Map<String, dynamic>.from(e)))
        .toList();
  }

  /// `GET /api/v1/calls.php?action=incoming` — a ringing call for me, if any.
  Future<CallSession?> incomingCall() async {
    final json = await _client.get('/calls.php', query: {'action': 'incoming'});
    final call = json['call'];
    if (call is! Map) return null;
    return CallSession.fromJson(Map<String, dynamic>.from(call));
  }

  /// `GET /api/v1/calls.php?action=history`
  Future<List<CallSession>> callHistory() async {
    final json = await _client.get('/calls.php', query: {'action': 'history'});
    return ((json['calls'] as List?) ?? const [])
        .whereType<Map>()
        .map((e) => CallSession.fromJson(Map<String, dynamic>.from(e)))
        .toList();
  }

  /// `GET /api/v1/calls.php?action=get&call_id=N`
  Future<CallSession> callDetail(int callId) async {
    final json = await _client.get('/calls.php', query: {'action': 'get', 'call_id': '$callId'});
    return CallSession.fromJson(Map<String, dynamic>.from(json['call'] as Map));
  }
}

/// Result of a login/register attempt.
class LoginOutcome {
  const LoginOutcome({
    required this.verificationRequired,
    this.token,
    required this.user,
    this.emailSent,
  });

  /// True when the server accepted the credentials but the email is unverified;
  /// the backend deliberately issues no token in that case.
  final bool verificationRequired;
  final String? token;
  final SessionUser user;

  /// For registration: whether the server reported the verification mail was
  /// handed off for delivery (`email_sent`). Null when the endpoint does not
  /// report it, so the UI never claims a delivery that did not happen.
  final bool? emailSent;
}

/// Notifications plus the server-computed unread count.
class NotificationPage {
  const NotificationPage({required this.unreadCount, required this.items});

  final int unreadCount;
  final List<AppNotification> items;
}
