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
import '../../shared/widgets/auth_gate.dart';
import '../../shared/widgets/brand_widgets.dart';
import 'library_favorites_screen.dart';
import 'library_item_screen.dart';
import 'library_search_screen.dart';

/// «المكتبة القانونية والقضائية» — the hub, from
/// `GET /api/v1/legal-library.php?action=home`.
///
/// Public, like the content it indexes: a guest reads the same catalogue the
/// website serves. Saving an item to "مكتبتي" is the only action that raises the
/// sign-in prompt.
class LegalLibraryScreen extends StatefulWidget {
  const LegalLibraryScreen({super.key, this.repository});

  final ApiRepository? repository;

  @override
  State<LegalLibraryScreen> createState() => _LegalLibraryScreenState();
}

class _LegalLibraryScreenState extends State<LegalLibraryScreen> {
  late final ApiRepository _repo = widget.repository ?? ApiRepository();

  LegalLibraryHome? _home;
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final home = await _repo.legalLibraryHome();
      if (!mounted) return;
      setState(() {
        _home = home;
        _loading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _error = e.message;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _error = 'تعذّر تحميل المكتبة. أعد المحاولة.';
      });
    }
  }

  void _openSearch({Map<String, String> filters = const {}}) {
    Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => LegalLibrarySearchScreen(
          repository: widget.repository,
          initialFilters: filters,
        ),
      ),
    );
  }

  void _openFavorites() {
    final auth = context.read<AuthController?>();
    if (auth == null || !auth.isSignedIn) {
      requireAccount(
        context,
        ProtectedDestination.account,
        message: context.read<LocaleController>().isRtl
            ? 'أنشئ حساباً لحفظ عناصر المكتبة في «مكتبتي».'
            : 'Create an account to save library items to “My library”.',
      );
      return;
    }
    Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => LegalLibraryFavoritesScreen(repository: widget.repository),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final rtl = context.watch<LocaleController>().isRtl;
    final body = _loading
        ? const Padding(
            padding: EdgeInsets.all(AppSpacing.s4),
            child: LoadingSkeleton(count: 5),
          )
        : _error != null
            ? ErrorState(message: _error!, onRetry: _load)
            : _body(rtl);

    return Scaffold(
      backgroundColor: context.tokens.bg,
      appBar: AppBar(
        backgroundColor: context.tokens.surface,
        surfaceTintColor: context.tokens.surface,
        title: Text(rtl ? 'المكتبة القانونية والقضائية' : 'Legal & judicial library'),
      ),
      body: body,
    );
  }

  Widget _body(bool rtl) {
    final home = _home;
    if (home == null) return const SizedBox.shrink();

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 14, 16, 28),
        children: [
          _Hero(total: home.total, sources: home.enabledSources, rtl: rtl),
          const SizedBox(height: AppSpacing.s4),
          _SearchBox(
            rtl: rtl,
            onSubmit: (q) => _openSearch(filters: {'q': q}),
          ),
          const SizedBox(height: AppSpacing.s3),
          Align(
            alignment: AlignmentDirectional.centerStart,
            child: TextButton.icon(
              onPressed: _openFavorites,
              icon: const Icon(Icons.bookmark_border, size: 18),
              label: Text(rtl ? 'مكتبتي المحفوظة' : 'My saved items'),
            ),
          ),
          if (home.sources.isEmpty) ...[
            const SizedBox(height: AppSpacing.s4),
            EmptyState(
              icon: Icons.menu_book_outlined,
              title: rtl ? 'لا يوجد مصدر مفعّل بعد' : 'No source enabled yet',
              message: rtl
                  ? 'طبّق ترقية قاعدة البيانات upgrade_v78.sql ثم شغّل المزامنة من لوحة التحكم.'
                  : 'Apply the database migration upgrade_v78.sql, then run a sync from the console.',
            ),
          ] else ...[
            SectionHeader(title: rtl ? 'المصادر' : 'Sources'),
            for (final s in home.sources) ...[
              _SourceCard(
                source: s,
                rtl: rtl,
                onTap: () => _openSearch(filters: {'source': s.key}),
              ),
              const SizedBox(height: AppSpacing.s2),
            ],
          ],
          if (home.types.isNotEmpty) ...[
            const SizedBox(height: AppSpacing.s3),
            SectionHeader(title: rtl ? 'تصفّح حسب النوع' : 'Browse by type'),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final t in home.types)
                  if (t.count > 0)
                    ActionChip(
                      label: Text('${t.name(rtl)} (${t.count})'),
                      onPressed: () => _openSearch(filters: {'type': t.key}),
                    ),
              ],
            ),
          ],
          if (home.latest.isNotEmpty) ...[
            const SizedBox(height: AppSpacing.s4),
            SectionHeader(title: rtl ? 'أحدث الإضافات' : 'Recently added'),
            for (final it in home.latest)
              Card(
                margin: const EdgeInsets.only(bottom: 8),
                color: context.tokens.surface,
                child: ListTile(
                  title: Text(it.title),
                  trailing: const Icon(Icons.chevron_left, size: 18),
                  onTap: () => Navigator.of(context).push(
                    MaterialPageRoute(
                      builder: (_) => LegalLibraryItemScreen(
                        itemId: it.id,
                        repository: widget.repository,
                      ),
                    ),
                  ),
                ),
              ),
          ],
          const SizedBox(height: AppSpacing.s5),
          _Disclaimer(rtl: rtl),
        ],
      ),
    );
  }
}

class _Hero extends StatelessWidget {
  const _Hero({required this.total, required this.sources, required this.rtl});

  final int total;
  final int sources;
  final bool rtl;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(AppSpacing.s5),
      decoration: BoxDecoration(
        gradient: AppColors.brandHero,
        borderRadius: BorderRadius.circular(AppRadius.lg),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            rtl ? 'المكتبة القانونية والقضائية الذكية' : 'The smart legal & judicial library',
            style: const TextStyle(
              color: Colors.white,
              fontSize: 18,
              fontWeight: FontWeight.w800,
              height: 1.5,
            ),
          ),
          const SizedBox(height: 8),
          Text(
            rtl
                ? 'ابحث في الأحكام القضائية والأنظمة والتشريعات من مصادر رسمية ومفتوحة.'
                : 'Search judicial decisions, statutes and regulations from official open sources.',
            style: const TextStyle(color: Color(0xFFD6E2EE), height: 1.7),
          ),
          const SizedBox(height: AppSpacing.s4),
          Row(
            children: [
              _Stat(value: '$total', label: rtl ? 'عنصر متاح' : 'items'),
              const SizedBox(width: 24),
              _Stat(value: '$sources', label: rtl ? 'مصادر مفعّلة' : 'sources'),
            ],
          ),
        ],
      ),
    );
  }
}

class _Stat extends StatelessWidget {
  const _Stat({required this.value, required this.label});

  final String value;
  final String label;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(value,
            style: const TextStyle(
                color: Colors.white, fontSize: 20, fontWeight: FontWeight.w800)),
        Text(label,
            style: const TextStyle(color: Color(0xFFB9CADE), fontSize: 12)),
      ],
    );
  }
}

class _SearchBox extends StatefulWidget {
  const _SearchBox({required this.rtl, required this.onSubmit});

  final bool rtl;
  final ValueChanged<String> onSubmit;

  @override
  State<_SearchBox> createState() => _SearchBoxState();
}

class _SearchBoxState extends State<_SearchBox> {
  final _controller = TextEditingController();

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return TextField(
      controller: _controller,
      textInputAction: TextInputAction.search,
      onSubmitted: widget.onSubmit,
      decoration: InputDecoration(
        hintText: widget.rtl ? 'ابحث في المكتبة…' : 'Search the library…',
        prefixIcon: const Icon(Icons.search),
        filled: true,
        fillColor: context.tokens.surface,
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(AppRadius.lg),
          borderSide: BorderSide(color: context.tokens.border),
        ),
      ),
    );
  }
}

class _SourceCard extends StatelessWidget {
  const _SourceCard({required this.source, required this.rtl, required this.onTap});

  final LegalLibrarySource source;
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
          padding: const EdgeInsets.all(AppSpacing.s4),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(source.name(rtl),
                  style: Theme.of(context)
                      .textTheme
                      .bodyMedium
                      ?.copyWith(fontWeight: FontWeight.w700)),
              const SizedBox(height: 4),
              Text('${source.publisher(rtl)} · ${source.itemCount} ${rtl ? "عنصر" : "items"}',
                  style: Theme.of(context).textTheme.bodySmall),
              const SizedBox(height: 8),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 3),
                decoration: BoxDecoration(
                  color: source.isHosted
                      ? context.tokens.infoBg
                      : context.tokens.surface3,
                  borderRadius: BorderRadius.circular(AppRadius.pill),
                ),
                child: Text(
                  source.isHosted
                      ? (rtl ? 'قراءة كاملة هنا' : 'Read in full here')
                      : (rtl ? 'بيانات ووصف ورابط رسمي' : 'Metadata + official link'),
                  style: TextStyle(
                    fontSize: 11,
                    fontWeight: FontWeight.w700,
                    color: source.isHosted ? AppColors.teal : context.tokens.textMuted,
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _Disclaimer extends StatelessWidget {
  const _Disclaimer({required this.rtl});

  final bool rtl;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(AppSpacing.s3),
      decoration: BoxDecoration(
        color: context.tokens.warningBg,
        borderRadius: BorderRadius.circular(AppRadius.md),
        border: Border.all(color: context.tokens.warningBorder),
      ),
      child: Text(
        rtl
            ? 'تنبيه: المحتوى محتوى قانوني عام منشور من الجهات الرسمية. لا يُعدّ استشارة قانونية، وتبقى النسخة الرسمية هي المرجع عند أي اختلاف.'
            : 'Note: the content is public legal material published by official authorities. It is not legal advice, and the official copy governs in case of any difference.',
        style: TextStyle(fontSize: 12, color: context.tokens.warningFg, height: 1.6),
      ),
    );
  }
}
