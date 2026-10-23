import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/auth_controller.dart';
import '../../core/guest_session.dart';
import '../../core/locale_controller.dart';
import '../../core/network/api_exception.dart';
import '../../core/network/api_repository.dart';
import '../../core/network/models.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_tokens.dart';
import '../../shared/specialty_icons.dart';
import '../../shared/widgets/auth_gate.dart';
import '../../shared/widgets/banner_carousel.dart';
import '../../shared/widgets/banner_link_screen.dart';
import '../../shared/widgets/brand_widgets.dart';
import '../../shared/widgets/home_slides.dart';
import '../assistant/assistant_screen.dart';
import '../consultations/consultations_screen.dart';
import '../consultations/journey_screen.dart';
import '../notifications/notifications_screen.dart';

/// The home screen, identical for a guest and a signed-in customer.
///
/// There used to be two homes — one for an account and one for a visitor — which
/// meant the same product looked like two different apps depending on whether you
/// had signed in, and the signed-in one replaced the entire screen with an error
/// page when any single endpoint failed. This is one screen with one layout;
/// authentication changes the greeting, the notification badge and where an action
/// leads, never the structure.
///
/// Section order is fixed and matches the specification: slider, the two primary
/// actions, the legal specialties, then the other services.
class HomeTab extends StatefulWidget {
  const HomeTab({
    super.key,
    this.repository,
    this.onNavigate,
    this.onOpenLawyers,
  });

  /// Injectable for widget tests; production uses the shared client.
  final ApiRepository? repository;

  /// Switches the enclosing shell to a bottom-nav tab, when it has one.
  final ValueChanged<int>? onNavigate;

  /// Opens the lawyer directory. The signed-in shell has no lawyers tab, so it
  /// falls back to a pushed directory screen when this is null.
  final VoidCallback? onOpenLawyers;

  @override
  State<HomeTab> createState() => _HomeTabState();
}

class _HomeTabState extends State<HomeTab> {
  late final ApiRepository _repo = widget.repository ?? ApiRepository();

  List<HomeBanner> _banners = const [];
  List<JourneySpecialty> _specialties = const [];

  int _unread = 0;

  bool _loading = true;
  String? _error;

  /// Whether the current session is authenticated, tracked so the personal parts
  /// of the home refresh when the visitor signs in or out on this screen.
  bool _signedIn = false;

  /// Providers are read tolerantly, like the account screen reads its theme
  /// controller: the shell always supplies them, and a widget test that mounts the
  /// home alone gets the guest view instead of a provider lookup error.
  ///
  /// The listening reads are confined to [build]; everything else uses the
  /// non-listening lookups below, because reading a provider
  /// with listen outside the widget tree is an error.
  AuthController? get _auth => context.read<AuthController?>();


  /// False until the first load has been started, so the load happens in
  /// didChangeDependencies (the first point where inherited widgets may be read)
  /// rather than in initState.
  bool _started = false;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    final signedIn = _auth?.isSignedIn ?? false;
    if (!_started || signedIn != _signedIn) {
      _started = true;
      _signedIn = signedIn;
      // Scheduled because didChangeDependencies can run during a build.
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) _load();
      });
    }
  }

  /// Loads the home in two independent halves.
  ///
  /// Public content (banners, specialties) and personal content (assistant state,
  /// unread count) are separate requests, and a failure in either must not remove
  /// the other. Previously all five endpoints were awaited together inside one
  /// `try`, so the first failure — and `ai-status.php` answers 401 to any request
  /// without a session — replaced the whole home with an error screen.
  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    final signedIn = _auth?.isSignedIn ?? false;

    List<HomeBanner> banners = const [];
    List<JourneySpecialty> specialties = const [];
    String? failure;

    try {
      banners = await _repo.banners();
    } on ApiException catch (e) {
      failure = e.message;
    } catch (_) {
      failure = 'تعذّر تحميل المحتوى. أعد المحاولة.';
    }

    try {
      specialties = await _repo.journeySpecialties();
    } on ApiException catch (e) {
      failure ??= e.message;
    } catch (_) {
      failure ??= 'تعذّر تحميل المحتوى. أعد المحاولة.';
    }

    var unread = 0;
    if (signedIn) {
      try {
        unread = (await _repo.notifications()).unreadCount;
      } catch (_) {
        // A badge is decoration; losing it must not affect the home.
      }
    }

    if (!mounted) return;
    setState(() {
      _banners = banners;
      _specialties = specialties;
      _unread = unread;
      _loading = false;
      _error = failure;
    });
  }

  /// The listening reads live here, inside build, so the home rebuilds when the
  /// locale or the session changes. Every other read uses [_auth] / [_locale],
  /// which do not listen — a listening read outside build is an error.
  bool get _rtl => context.watch<LocaleController?>()?.isRtl ?? true;

  /// The slides the carousel shows: the operator's banners when there are any,
  /// otherwise the built-in Mawatheq slides. This is what keeps a genuine slider on
  /// the home screen even on a fresh install where `home_banners` is empty — the
  /// state that previously left only the two action cards at the top of the page.
  List<HomeBanner> get _slides =>
      _banners.isNotEmpty ? _banners : kBuiltInHomeSlides;

  /// The specialty cards. The backend list wins; when it is empty — the state of a
  /// database whose Najiz classification has not been imported — the seven real
  /// categories are shown so the section is never a blank box. The server list has
  /// live lawyer counts, so it is preferred whenever it exists.
  List<JourneySpecialty> get _specialtyCards =>
      _specialties.isNotEmpty ? _specialties : kBuiltInSpecialties;

  @override
  Widget build(BuildContext context) {
    final rtl = _rtl;
    final auth = context.watch<AuthController?>();

    if (_loading) {
      return const Padding(
        padding: EdgeInsets.all(AppSpacing.s4),
        child: LoadingSkeleton(count: 5),
      );
    }

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 28),
        children: [
          // 1. Slider. Always present: an operator banner when one is published,
          // otherwise the built-in Mawatheq slides. It used to render nothing at all
          // when the server sent no banners, which is the normal fresh-install state
          // — so the top of the home screen was just the two action cards.
          //
          // The two primary actions are drawn *inside* each slide rather than in a
          // row beneath it: a pair of cards under the slider is what read as "two
          // static cards instead of a slider". Every slide therefore carries its own
          // احجز استشارة / اسأل المساعد الذكي pair, so they are reachable from the
          // first screenful without competing with the banner for space.
          BannerCarousel(
            banners: _slides,
            onTap: (banner) => _openBanner(banner),
            onBook: _startBooking,
            onAskAssistant: _openAssistant,
          ),
          const SizedBox(height: 18),

          // A failed section is reported inline, above content that still works,
          // with a retry. It must never replace the screen.
          if (_error != null) ...[
            _LoadNotice(message: _error!, rtl: rtl, onRetry: _load),
            const SizedBox(height: 18),
          ],

          // 2. The legal specialties, from the backend.
          SectionHeader(
            title: rtl ? 'اختر تخصصك القانوني' : 'Choose your specialty',
          ),
          Text(
            rtl
                ? 'اختر المجال المناسب لنساعدك في الوصول إلى المحامي المناسب.'
                : 'Pick the right field so we can match you with the right lawyer.',
            style: TextStyle(fontSize: 12.5, color: context.tokens.textMuted),
          ),
          const SizedBox(height: 14),
          _SpecialtyGrid(
            specialties: _specialtyCards,
            rtl: rtl,
            onTap: _openSpecialty,
          ),
          const SizedBox(height: 26),

          // 4. The other services the platform actually implements.
          SectionHeader(title: rtl ? 'خدمات مواثيق' : 'Mawatheq services'),
          const SizedBox(height: 4),
          _ServicesSection(
            rtl: rtl,
            signedIn: auth?.isSignedIn ?? false,
            unread: _unread,
            onOpen: _openService,
          ),
        ],
      ),
    );
  }

  // ------------------------------------------------------------------ actions

  /// The primary conversion action. A guest browses the same categories and
  /// lawyers; the sign-in prompt appears at the point of confirmation, which is
  /// where the server would refuse an anonymous request.
  void _startBooking() {
    if (!(_auth?.isSignedIn ?? false)) {
      requireAccount(context, ProtectedDestination.consultations);
      return;
    }
    _openJourney();
  }

  void _openJourney({JourneySpecialty? specialty}) {
    Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => ConsultationJourneyScreen(
          // Carry the injected repository through. Without this the journey built its
          // own client, so a test that supplied a fake transport was not exercising
          // the journey at all — it was talking to the production host.
          repository: widget.repository,
          initialSpecialty: specialty,
        ),
      ),
    );
  }

  /// Tapping a specialty opens the journey already scoped to it, so the customer
  /// does not pick the same specialty twice.
  ///
  /// A fallback card (shown because the server had no taxonomy) opens the journey at
  /// its own first step instead: the id it carries is not a server id, so pre-scoping
  /// to it would send the customer into an empty step. The journey then shows
  /// whatever the backend actually has.
  void _openSpecialty(JourneySpecialty specialty) {
    if (!(_auth?.isSignedIn ?? false)) {
      // The visitor is told which service needs an account, and their intent is
      // remembered so sign-in can resume it.
      requireAccount(context, ProtectedDestination.consultations);
      return;
    }
    _openJourney(
      specialty: _specialties.isEmpty ? null : specialty,
    );
  }

  /// The assistant entry point.
  ///
  /// A guest is asked to sign in first because the endpoint requires a session.
  /// A signed-in customer opens the assistant, which shows its own honest
  /// unavailable state when the provider is not configured — the app never
  /// pretends a request succeeded.
  void _openAssistant() {
    if (!(_auth?.isSignedIn ?? false)) {
      requireAccount(context, ProtectedDestination.legalAi);
      return;
    }
    final navigate = widget.onNavigate;
    if (navigate != null) {
      navigate(2);
      return;
    }
    Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => AssistantScreen(repository: widget.repository),
      ),
    );
  }

  void _openLawyers() {
    final open = widget.onOpenLawyers;
    if (open != null) {
      open();
      return;
    }
    widget.onNavigate?.call(1);
  }

  void _openService(_ServiceAction action) {
    final signedIn = _auth?.isSignedIn ?? false;
    switch (action) {
      case _ServiceAction.consultations:
        if (!signedIn) {
          requireAccount(context, ProtectedDestination.consultations);
          return;
        }
        Navigator.of(context).push(
          MaterialPageRoute(builder: (_) => const ConsultationsScreen()),
        );
      case _ServiceAction.lawyers:
        _openLawyers();
      case _ServiceAction.cases:
        if (!signedIn) {
          requireAccount(context, ProtectedDestination.cases);
          return;
        }
        widget.onNavigate?.call(1);
      case _ServiceAction.documents:
        if (!signedIn) {
          requireAccount(context, ProtectedDestination.documents);
          return;
        }
        widget.onNavigate?.call(3);
      case _ServiceAction.notifications:
        if (!signedIn) {
          requireAccount(context, ProtectedDestination.notifications);
          return;
        }
        Navigator.of(context).push(
          MaterialPageRoute(builder: (_) => const NotificationsScreen()),
        );
      case _ServiceAction.account:
        if (!signedIn) {
          requireAccount(context, ProtectedDestination.account);
          return;
        }
        widget.onNavigate?.call(4);
      case _ServiceAction.assistant:
        _openAssistant();
    }
  }

  /// A slide destination is resolved server-side; the app only maps the type onto
  /// a local action. An external link is shown in full before leaving the app. A
  /// built-in slide uses the same vocabulary, so there is no special case for it.
  void _openBanner(HomeBanner banner) {
    switch (banner.linkType) {
      case 'url':
        Navigator.of(context).push(
          MaterialPageRoute(builder: (_) => BannerLinkScreen(banner: banner)),
        );
      case 'assistant':
        _openAssistant();
      case 'lawyers':
        _openLawyers();
      case 'category':
      case 'specialty':
        _startBooking();
      default:
        break;
    }
  }
}

/// The specialty grid. An unknown icon key falls back rather than throwing, so an
/// operator adding a category cannot break the screen.
class _SpecialtyGrid extends StatelessWidget {
  const _SpecialtyGrid({
    required this.specialties,
    required this.rtl,
    required this.onTap,
  });

  final List<JourneySpecialty> specialties;
  final bool rtl;
  final void Function(JourneySpecialty) onTap;

  @override
  Widget build(BuildContext context) {
    if (specialties.isEmpty) {
      return _EmptySection(
        icon: Icons.category_outlined,
        title: rtl ? 'لا توجد تخصصات منشورة بعد' : 'No specialties published yet',
        body: rtl
            ? 'ستظهر التخصصات القانونية هنا بعد تجهيزها من فريق مواثيق. '
                'اسحب للأسفل للمحاولة مرة أخرى.'
            : 'Legal specialties appear here once the team publishes them. '
                'Pull down to try again.',
      );
    }

    return LayoutBuilder(
      builder: (context, constraints) {
        // Two columns on a phone, three when there is room for them.
        final columns = constraints.maxWidth >= 520 ? 3 : 2;
        return GridView.count(
          crossAxisCount: columns,
          shrinkWrap: true,
          physics: const NeverScrollableScrollPhysics(),
          mainAxisSpacing: 12,
          crossAxisSpacing: 12,
          childAspectRatio: columns == 3 ? 1.12 : 1.02,
          children: [
            for (final specialty in specialties)
              _SpecialtyCard(
                specialty: specialty,
                rtl: rtl,
                onTap: () => onTap(specialty),
              ),
          ],
        );
      },
    );
  }
}

class _SpecialtyCard extends StatelessWidget {
  const _SpecialtyCard({
    required this.specialty,
    required this.rtl,
    required this.onTap,
  });

  final JourneySpecialty specialty;
  final bool rtl;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: context.tokens.surface,
      clipBehavior: Clip.antiAlias,
      shape: RoundedRectangleBorder(
        side: BorderSide(color: context.tokens.border),
        borderRadius: BorderRadius.circular(AppRadius.lg),
      ),
      child: InkWell(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(13),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Container(
                width: 38,
                height: 38,
                decoration: BoxDecoration(
                  color: context.tokens.infoBg,
                  borderRadius: BorderRadius.circular(AppRadius.md),
                ),
                child: Icon(
                  specialtyIcon(specialty.iconKey),
                  size: 20,
                  color: AppColors.teal,
                ),
              ),
              const SizedBox(height: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      specialty.name,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                        fontWeight: FontWeight.w700,
                        color: context.tokens.heading,
                        fontSize: 13.5,
                        height: 1.35,
                      ),
                    ),
                    if (specialty.description.isNotEmpty) ...[
                      const SizedBox(height: 3),
                      Expanded(
                        child: Text(
                          specialty.description,
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          style: TextStyle(
                            fontSize: 11,
                            height: 1.4,
                            color: context.tokens.textMuted,
                          ),
                        ),
                      ),
                    ],
                  ],
                ),
              ),
              if (specialty.hasLawyers) ...[
                const SizedBox(height: 4),
                Text(
                  rtl
                      ? '${specialty.lawyerCount} محامٍ'
                      : '${specialty.lawyerCount} lawyers',
                  style: TextStyle(
                    color: context.tokens.textSubtle,
                    fontSize: 11,
                  ),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

/// The services the platform ships. Every entry opens something that exists; none
/// is a placeholder, and the list is not a copy of the bottom navigation.
enum _ServiceAction {
  consultations,
  lawyers,
  cases,
  documents,
  notifications,
  account,
  assistant,
}

class _ServicesSection extends StatelessWidget {
  const _ServicesSection({
    required this.rtl,
    required this.signedIn,
    required this.unread,
    required this.onOpen,
  });

  final bool rtl;
  final bool signedIn;
  final int unread;
  final void Function(_ServiceAction) onOpen;

  @override
  Widget build(BuildContext context) {
    final services = <(IconData, String, String, _ServiceAction)>[
      (
        Icons.forum_outlined,
        'الاستشارات القانونية',
        'Legal consultations',
        _ServiceAction.consultations,
      ),
      (
        Icons.gavel_outlined,
        'دليل المحامين',
        'Lawyer directory',
        _ServiceAction.lawyers,
      ),
      (
        Icons.auto_awesome_outlined,
        'المساعد القانوني',
        'Legal assistant',
        _ServiceAction.assistant,
      ),
      (
        Icons.folder_outlined,
        'إدارة القضايا',
        'Case management',
        _ServiceAction.cases,
      ),
      (
        Icons.description_outlined,
        'إدارة المستندات',
        'Document management',
        _ServiceAction.documents,
      ),
      (
        Icons.notifications_outlined,
        'الإشعارات',
        'Notifications',
        _ServiceAction.notifications,
      ),
    ];

    return Column(
      children: [
        for (final s in services)
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Material(
              color: context.tokens.surface,
              clipBehavior: Clip.antiAlias,
              shape: RoundedRectangleBorder(
                side: BorderSide(color: context.tokens.border),
                borderRadius: BorderRadius.circular(AppRadius.lg),
              ),
              child: InkWell(
                onTap: () => onOpen(s.$4),
                child: Padding(
                  padding: const EdgeInsets.all(14),
                  child: Row(
                    children: [
                      Container(
                        width: 40,
                        height: 40,
                        decoration: BoxDecoration(
                          color: context.tokens.infoBg,
                          borderRadius: BorderRadius.circular(AppRadius.md),
                        ),
                        child: Icon(s.$1, size: 20, color: AppColors.teal),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Text(
                          rtl ? s.$2 : s.$3,
                          style: Theme.of(context)
                              .textTheme
                              .bodyMedium
                              ?.copyWith(fontWeight: FontWeight.w600),
                        ),
                      ),
                      // A lock marks what needs an account, so the visitor knows
                      // before tapping rather than being surprised by a prompt.
                      if (signedIn &&
                          s.$4 == _ServiceAction.notifications &&
                          unread > 0)
                        Container(
                          margin: const EdgeInsets.only(left: 8),
                          padding: const EdgeInsets.symmetric(
                            horizontal: 8,
                            vertical: 2,
                          ),
                          decoration: BoxDecoration(
                            color: context.tokens.brandFill,
                            borderRadius: BorderRadius.circular(AppRadius.pill),
                          ),
                          child: Text(
                            '$unread',
                            style: TextStyle(
                              fontSize: 11,
                              fontWeight: FontWeight.w700,
                              color: context.tokens.onBrand,
                            ),
                          ),
                        ),
                      Icon(
                        signedIn ? Icons.chevron_left : Icons.lock_outline,
                        size: signedIn ? 18 : 16,
                        color: context.tokens.textSubtle,
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
      ],
    );
  }
}

/// A non-blocking notice for a failed section, with a retry.
class _LoadNotice extends StatelessWidget {
  const _LoadNotice({
    required this.message,
    required this.rtl,
    required this.onRetry,
  });

  final String message;
  final bool rtl;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(AppSpacing.s4),
      decoration: BoxDecoration(
        color: context.tokens.dangerBg,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        border: Border.all(color: context.tokens.dangerBorder),
      ),
      child: Column(
        crossAxisAlignment:
            rtl ? CrossAxisAlignment.end : CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(Icons.error_outline, size: 20, color: context.tokens.dangerFg),
              const SizedBox(width: AppSpacing.s3),
              Expanded(
                child: Text(
                  message,
                  style: TextStyle(
                    fontSize: 12.5,
                    height: 1.6,
                    color: context.tokens.dangerFg,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: AppSpacing.s3),
          SecondaryButton(
            label: rtl ? 'إعادة المحاولة' : 'Retry',
            icon: Icons.refresh,
            onPressed: onRetry,
          ),
        ],
      ),
    );
  }
}

/// An explanatory empty state: what is missing and what the visitor can do.
class _EmptySection extends StatelessWidget {
  const _EmptySection({
    required this.icon,
    required this.title,
    required this.body,
  });

  final IconData icon;
  final String title;
  final String body;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(AppSpacing.s5),
      decoration: BoxDecoration(
        color: context.tokens.surface,
        borderRadius: BorderRadius.circular(AppRadius.xl),
        border: Border.all(color: context.tokens.border),
      ),
      child: Column(
        children: [
          Icon(icon, size: 30, color: context.tokens.textSubtle),
          const SizedBox(height: 10),
          Text(
            title,
            textAlign: TextAlign.center,
            style: TextStyle(
              fontWeight: FontWeight.w700,
              color: context.tokens.heading,
            ),
          ),
          const SizedBox(height: 6),
          Text(
            body,
            textAlign: TextAlign.center,
            style: TextStyle(
              fontSize: 12,
              height: 1.7,
              color: context.tokens.textMuted,
            ),
          ),
        ],
      ),
    );
  }
}
