import 'package:flutter/material.dart';

import '../../core/network/api_exception.dart';
import '../../core/network/api_repository.dart';
import '../../core/network/workspace_models.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_tokens.dart';
import '../../shared/widgets/brand_widgets.dart';

/// The lawyer workspace: firm, clients, templates, assistant and subscription.
///
/// This screen is a *reader*. Every write stays on the web page it already lives on —
/// inviting a member, saving a template, changing a plan — because those carry audit and
/// permission rules that a second implementation would eventually get wrong. What the app
/// can do is answer "what do I have" without a browser, which is the gap this closes.
///
/// Each tab loads independently so one failing endpoint does not blank the whole screen;
/// the failure is shown in its own tab with a retry.
class LawyerWorkspaceScreen extends StatefulWidget {
  const LawyerWorkspaceScreen({super.key, this.repository});

  final ApiRepository? repository;

  @override
  State<LawyerWorkspaceScreen> createState() => _LawyerWorkspaceScreenState();
}

class _LawyerWorkspaceScreenState extends State<LawyerWorkspaceScreen> {
  late final ApiRepository _repo = widget.repository ?? ApiRepository();

  FirmInfo? _firm;
  List<LawyerClient> _clients = const [];
  List<LegalTemplate> _templates = const [];
  FirmChatbot? _chatbot;
  SubscriptionInfo? _subscription;

  // Per-section failure, so a tab can say "could not load" without claiming the list is
  // empty and without taking the other four tabs down with it.
  String? _firmError;
  String? _clientsError;
  String? _templatesError;
  String? _chatbotError;
  String? _subscriptionError;

  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  /// Loads the five sections concurrently and independently.
  ///
  /// They are separate endpoints with separate permissions, and in a partial deployment
  /// some of them 404 while others answer. Awaited in sequence inside one `try`, the
  /// first failure discarded four successful responses and replaced the whole screen with
  /// an error — a lawyer with a firm, clients and a plan was shown nothing.
  Future<void> _load() async {
    setState(() {
      _loading = true;
      _firmError = _clientsError = _templatesError = _chatbotError = _subscriptionError = null;
    });

    await Future.wait([
      _guard(_repo.firm(withMembers: true), (v) => _firm = v, (e) => _firmError = e),
      _guard(_repo.lawyerClients(), (v) => _clients = v, (e) => _clientsError = e),
      _guard(_repo.legalTemplates(), (v) => _templates = v, (e) => _templatesError = e),
      _guard(_repo.firmChatbot(), (v) => _chatbot = v, (e) => _chatbotError = e),
      _guard(_repo.subscription(), (v) => _subscription = v, (e) => _subscriptionError = e),
    ]);

    if (!mounted) return;
    setState(() => _loading = false);
  }

  /// Resolves to completion either way, so `Future.wait` never rethrows and one rejected
  /// endpoint cannot cancel the four that succeeded.
  static Future<void> _guard<T>(
    Future<T> future,
    void Function(T value) onValue,
    void Function(String message) onError,
  ) async {
    try {
      onValue(await future);
    } catch (e) {
      onError(e is ApiException ? e.message : 'تعذّر تحميل البيانات. تحقّق من الاتصال وأعد المحاولة.');
    }
  }

  /// True when nothing at all could be loaded — the only case that earns a full-screen
  /// error, because there is no partial content to show.
  bool get _allFailed =>
      _firm == null &&
      _clients.isEmpty &&
      _templates.isEmpty &&
      _chatbot == null &&
      _subscription == null;

  Widget _sectionError(BuildContext context, String message) => _scroll([
        SizedBox(height: 40),
        EmptyState(
          icon: Icons.cloud_off_outlined,
          title: 'تعذّر تحميل هذا القسم',
          message: message,
        ),
      ]);

  @override
  Widget build(BuildContext context) {
    return DefaultTabController(
      length: 5,
      child: Scaffold(
        backgroundColor: context.tokens.bg,
        appBar: BrandAppBar(
          subtitle: 'مساحة العمل',
          actions: [
            IconButton(
              tooltip: 'تحديث',
              onPressed: _load,
              icon: const Icon(Icons.refresh),
            ),
          ],
          bottom: TabBar(
            isScrollable: true,
            labelColor: context.tokens.heading,
            unselectedLabelColor: context.tokens.textMuted,
            indicatorColor: AppColors.teal,
            tabs: const [
              Tab(text: 'المكتب'),
              Tab(text: 'الموكلون'),
              Tab(text: 'القوالب'),
              Tab(text: 'المساعد'),
              Tab(text: 'الاشتراك'),
            ],
          ),
        ),
        body: _loading
            ? const Padding(padding: EdgeInsets.all(16), child: LoadingSkeleton(count: 5))
            : _allFailed
                ? ErrorState(
                    message: _firmError ??
                        _clientsError ??
                        _templatesError ??
                        _chatbotError ??
                        _subscriptionError ??
                        'تعذّر تحميل مساحة العمل.',
                    onRetry: _load,
                  )
                : TabBarView(
                    children: [
                      _firmTab(context),
                      _clientsTab(context),
                      _templatesTab(context),
                      _chatbotTab(context),
                      _subscriptionTab(context),
                    ],
                  ),
      ),
    );
  }

  Widget _scroll(List<Widget> children) => RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 28),
          children: children,
        ),
      );

  // ------------------------------------------------------------------ firm

  Widget _firmTab(BuildContext context) {
    if (_firmError != null) return _sectionError(context, _firmError!);
    final firm = _firm;
    if (firm == null) {
      return _scroll(const [
        SizedBox(height: 40),
        EmptyState(
          icon: Icons.business_outlined,
          title: 'لا يوجد مكتب مرتبط بحسابك',
          message: 'يمكنك إنشاء مكتب أو الانضمام إليه من صفحة «المكتب» في الموقع.',
        ),
      ]);
    }
    return _scroll([
      DashboardCard(
        value: firm.name,
        label: firm.role == 'owner' ? 'مالك المكتب' : 'عضو',
        icon: Icons.business_outlined,
      ),
      const SizedBox(height: 12),
      if (firm.email != null) _infoRow(context, Icons.mail_outline, 'البريد', firm.email!),
      if (firm.phone != null) _infoRow(context, Icons.phone_outlined, 'الهاتف', firm.phone!),
      if (firm.city != null) _infoRow(context, Icons.location_on_outlined, 'المدينة', firm.city!),
      if (firm.website != null) _infoRow(context, Icons.link, 'الموقع', firm.website!),
      if (firm.about != null) ...[
        const SizedBox(height: 8),
        Text(firm.about!, style: TextStyle(color: context.tokens.text, height: 1.7)),
      ],
      const SizedBox(height: 18),
      Text('الأعضاء (${firm.members.length})',
          style: TextStyle(fontWeight: FontWeight.w700, color: context.tokens.heading)),
      const SizedBox(height: 8),
      if (firm.members.isEmpty)
        Text('لا يوجد أعضاء آخرون بعد.',
            style: TextStyle(color: context.tokens.textMuted, fontSize: 13))
      else
        ...firm.members.map((m) => _infoRow(
              context,
              Icons.person_outline,
              m.name,
              m.specialization ?? _roleAr(m.role),
            )),
      if (firm.canManage) ...[
        const SizedBox(height: 16),
        Text('إدارة المكتب والأعضاء من صفحة المكتب في الموقع.',
            style: TextStyle(color: context.tokens.textMuted, fontSize: 12)),
      ],
    ]);
  }

  String _roleAr(String role) {
    const map = {'owner': 'مالك', 'partner': 'شريك', 'lawyer': 'محامٍ', 'staff': 'موظف'};
    return map[role] ?? role;
  }

  Widget _infoRow(BuildContext context, IconData icon, String label, String value) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, size: 18, color: context.tokens.textMuted),
          const SizedBox(width: 10),
          SizedBox(
            width: 92,
            child: Text(label, style: TextStyle(color: context.tokens.textMuted, fontSize: 13)),
          ),
          Expanded(
            child: Text(value, style: TextStyle(color: context.tokens.text, fontSize: 13.5)),
          ),
        ],
      ),
    );
  }

  // --------------------------------------------------------------- clients

  Widget _clientsTab(BuildContext context) {
    if (_clientsError != null) return _sectionError(context, _clientsError!);
    if (_clients.isEmpty) {
      return _scroll(const [
        SizedBox(height: 40),
        EmptyState(
          icon: Icons.people_outline,
          title: 'لا يوجد موكلون بعد',
          message: 'يظهر هنا كل موكّل مرتبط بك عبر قضية أو استشارة أو فاتورة.',
        ),
      ]);
    }
    return _scroll(
      _clients
          .map((c) => Card(
                margin: const EdgeInsets.only(bottom: 10),
                color: context.tokens.surface,
                child: ListTile(
                  leading: CircleAvatar(
                    backgroundColor: AppColors.teal.withValues(alpha: 0.15),
                    child: Text(
                      c.name.isNotEmpty ? c.name.characters.first : '؟',
                      style: TextStyle(color: context.tokens.heading),
                    ),
                  ),
                  title: Text(c.name, style: TextStyle(color: context.tokens.heading)),
                  subtitle: Text(
                    '${c.casesCount} قضية · ${c.consultationsCount} استشارة',
                    style: TextStyle(color: context.tokens.textMuted, fontSize: 12),
                  ),
                  trailing: c.email == null
                      ? null
                      : Icon(Icons.mail_outline, size: 18, color: context.tokens.textMuted),
                ),
              ))
          .toList(),
    );
  }

  // ------------------------------------------------------------- templates

  Widget _templatesTab(BuildContext context) {
    if (_templatesError != null) return _sectionError(context, _templatesError!);
    if (_templates.isEmpty) {
      return _scroll(const [
        SizedBox(height: 40),
        EmptyState(
          icon: Icons.description_outlined,
          title: 'لا توجد قوالب متاحة',
          message: 'ستظهر القوالب القانونية هنا عند تفعيل مكتبة القوالب.',
        ),
      ]);
    }
    return _scroll(
      _templates
          .map((t) => Card(
                margin: const EdgeInsets.only(bottom: 10),
                color: context.tokens.surface,
                child: ListTile(
                  leading: Icon(
                    t.canUse ? Icons.description_outlined : Icons.lock_outline,
                    color: t.canUse ? AppColors.teal : context.tokens.textMuted,
                  ),
                  title: Text(t.title, style: TextStyle(color: context.tokens.heading)),
                  subtitle: Text(
                    [
                      if (t.category != null) t.category!,
                      if (!t.canUse) 'يتطلب باقة أعلى',
                    ].join(' · '),
                    style: TextStyle(
                      color: t.canUse ? context.tokens.textMuted : context.tokens.warningFg,
                      fontSize: 12,
                    ),
                  ),
                ),
              ))
          .toList(),
    );
  }

  // --------------------------------------------------------------- chatbot

  Widget _chatbotTab(BuildContext context) {
    if (_chatbotError != null) return _sectionError(context, _chatbotError!);
    final bot = _chatbot;
    if (bot == null || !bot.available) {
      final reason = bot?.reason;
      final message = switch (reason) {
        'no_firm' => 'المساعد الآلي متاح للمكاتب فقط. أنشئ مكتباً أولاً من صفحة المكتب.',
        'migration_required' => 'يلزم تحديث قاعدة البيانات لتفعيل المساعد الآلي.',
        _ => 'المساعد الآلي غير مفعّل لهذا المكتب.',
      };
      return _scroll([
        const SizedBox(height: 40),
        EmptyState(
          icon: Icons.smart_toy_outlined,
          title: 'المساعد الآلي غير متاح',
          message: message,
        ),
      ]);
    }
    return _scroll([
      DashboardCard(
        value: bot.isEnabled ? 'مفعّل' : 'متوقف',
        label: bot.displayName ?? 'المساعد الآلي',
        icon: Icons.smart_toy_outlined,
      ),
      const SizedBox(height: 12),
      _infoRow(context, Icons.chat_bubble_outline, 'المحادثات', '${bot.totalConversations}'),
      _infoRow(context, Icons.mark_chat_unread_outlined, 'مفتوحة', '${bot.openConversations}'),
      if (bot.greeting != null) ...[
        const SizedBox(height: 10),
        Text('رسالة الترحيب', style: TextStyle(color: context.tokens.textMuted, fontSize: 12)),
        const SizedBox(height: 4),
        Text(bot.greeting!, style: TextStyle(color: context.tokens.text, height: 1.6)),
      ],
    ]);
  }

  // ---------------------------------------------------------- subscription

  Widget _subscriptionTab(BuildContext context) {
    if (_subscriptionError != null) return _sectionError(context, _subscriptionError!);
    final sub = _subscription;
    if (sub == null) {
      return _scroll(const [
        SizedBox(height: 40),
        EmptyState(
          icon: Icons.workspace_premium_outlined,
          title: 'لا توجد باقة',
          message: 'اختر باقة من صفحة الباقات في الموقع لتفعيل الحدود والمزايا.',
        ),
      ]);
    }
    return _scroll([
      DashboardCard(
        value: sub.priceMonthly != null ? '${sub.priceMonthly} ${sub.currency}' : '—',
        label: sub.planName ?? 'بلا باقة',
        icon: Icons.workspace_premium_outlined,
      ),
      const SizedBox(height: 6),
      Text('الأسعار شهرياً. تُطبَّق الحدود أدناه على حسابك فعلياً.',
          style: TextStyle(color: context.tokens.textMuted, fontSize: 12)),
      const SizedBox(height: 14),
      ...sub.limits.map((l) => Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Text(l.labelAr,
                          style: TextStyle(color: context.tokens.text, fontSize: 13.5)),
                    ),
                    Text(
                      // An unlimited limit is "بلا حد", never "0".
                      !l.enforced || l.limit == null
                          ? 'بلا حد'
                          : '${l.used ?? 0} / ${l.limit}',
                      style: TextStyle(
                        color: (l.exhausted) ? context.tokens.warningFg : context.tokens.textMuted,
                        fontSize: 12.5,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  ],
                ),
                if (l.enforced && l.limit != null && l.limit! > 0)
                  Padding(
                    padding: const EdgeInsets.only(top: 6),
                    child: ClipRRect(
                      borderRadius: BorderRadius.circular(4),
                      child: LinearProgressIndicator(
                        value: ((l.used ?? 0) / l.limit!).clamp(0.0, 1.0),
                        minHeight: 6,
                        backgroundColor: context.tokens.border,
                        color: AppColors.teal,
                      ),
                    ),
                  ),
              ],
            ),
          )),
    ]);
  }
}
