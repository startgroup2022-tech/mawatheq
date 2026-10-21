import 'package:flutter/material.dart';


import '../../core/network/api_exception.dart';
import '../../core/network/api_repository.dart';
import '../../core/network/models.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_tokens.dart';
import '../../shared/widgets/brand_widgets.dart';

/// Case list with search and status filtering, backed by `GET /api/v1/cases.php`.
class CasesScreen extends StatefulWidget {
  const CasesScreen({super.key});

  @override
  State<CasesScreen> createState() => _CasesScreenState();
}

class _CasesScreenState extends State<CasesScreen> {
  final _repo = ApiRepository();

  List<CaseSummary> _all = const [];
  bool _loading = true;
  String? _error;
  String _filter = 'الكل';
  String _query = '';

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
      final cases = await _repo.cases();
      if (!mounted) return;
      setState(() {
        _all = cases;
        _loading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _error = e.message;
      });
    }
  }

  /// Filters on values the backend actually produces.
  List<CaseSummary> get _visible {
    final q = _query.trim();
    return _all.where((c) {
      final matchesFilter = _filter == 'الكل' || c.status == _statusForFilter(_filter);
      final matchesQuery = q.isEmpty ||
          c.title.contains(q) ||
          c.caseNumber.contains(q) ||
          c.clientName.contains(q);
      return matchesFilter && matchesQuery;
    }).toList();
  }

  static String _statusForFilter(String label) {
    switch (label) {
      case 'مفتوحة':
        return 'open';
      case 'قيد المراجعة':
        return 'in_review';
      case 'مغلقة':
        return 'closed_won';
      default:
        return '';
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const Padding(
        padding: EdgeInsets.all(16),
        child: LoadingSkeleton(count: 6),
      );
    }
    if (_error != null) return ErrorState(message: _error!, onRetry: _load);

    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 14, 16, 10),
          child: Column(
            children: [
              SearchField(
                hint: 'ابحث في القضايا…',
                onChanged: (v) => setState(() => _query = v),
              ),
              const SizedBox(height: 10),
              FilterChips(
                options: const ['الكل', 'مفتوحة', 'قيد المراجعة', 'مغلقة'],
                selected: _filter,
                onSelected: (v) => setState(() => _filter = v),
              ),
            ],
          ),
        ),
        Expanded(
          child: _visible.isEmpty
              ? EmptyState(
                  icon: Icons.folder_open_outlined,
                  title: 'لا توجد قضايا',
                  message: _all.isEmpty
                      ? 'لم تُضف أي قضية إلى حسابك بعد.'
                      : 'لا توجد نتائج مطابقة للبحث أو التصفية.',
                )
              : RefreshIndicator(
                  onRefresh: _load,
                  child: ListView.separated(
                    padding: const EdgeInsets.fromLTRB(16, 4, 16, 20),
                    itemCount: _visible.length,
                    separatorBuilder: (_, __) => const SizedBox(height: 10),
                    itemBuilder: (context, i) => CaseCard(
                      item: _visible[i],
                      onTap: () => Navigator.of(context).push(
                        MaterialPageRoute(
                          builder: (_) => CaseDetailScreen(caseId: _visible[i].id),
                        ),
                      ),
                    ),
                  ),
                ),
        ),
      ],
    );
  }
}

/// Case detail: parties, timeline and tasks, straight from the API.
class CaseDetailScreen extends StatefulWidget {
  const CaseDetailScreen({super.key, required this.caseId});

  final int caseId;

  @override
  State<CaseDetailScreen> createState() => _CaseDetailScreenState();
}

class _CaseDetailScreenState extends State<CaseDetailScreen> {
  final _repo = ApiRepository();
  Map<String, dynamic>? _data;
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
      final data = await _repo.caseDetail(widget.caseId);
      if (!mounted) return;
      setState(() {
        _data = data;
        _loading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _error = e.message;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: const BrandAppBar(subtitle: 'تفاصيل القضية'),
      body: _loading
          ? const Padding(
              padding: EdgeInsets.all(16),
              child: LoadingSkeleton(count: 4),
            )
          : _error != null
              ? ErrorState(message: _error!, onRetry: _load)
              : _buildBody(),
    );
  }

  Widget _buildBody() {
    final json = Json(_data ?? const {});
    final caseObj = json.object('case') ?? json;

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Container(
          padding: const EdgeInsets.all(16),
          decoration: BoxDecoration(
            gradient: context.tokens.washGradient,
            borderRadius: BorderRadius.circular(AppRadius.lg),
            border: Border.all(color: context.tokens.washBorder),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(
                      caseObj.str('title', fallback: 'قضية #${widget.caseId}'),
                      style: Theme.of(context).textTheme.titleLarge,
                    ),
                  ),
                  StatusBadge(status: caseObj.str('status', fallback: 'open')),
                ],
              ),
              if (caseObj.str('case_number').isNotEmpty) ...[
                const SizedBox(height: 6),
                Text('رقم القضية: ${caseObj.str('case_number')}',
                    style: Theme.of(context).textTheme.bodySmall),
              ],
            ],
          ),
        ),
        const SizedBox(height: 18),

        if (caseObj.str('description').isNotEmpty) ...[
          SectionHeader(title: 'الوصف'),
          Text(caseObj.str('description'),
              style: Theme.of(context).textTheme.bodyMedium),
          const SizedBox(height: 18),
        ],

        ..._listSection('الأطراف', 'parties', (j) => j.str('name'),
            trailing: (j) => j.str('party_type')),
        ..._listSection('الجلسات', 'hearings', (j) => j.str('title'),
            trailing: (j) => j.str('hearing_date')),
        ..._listSection('المهام', 'tasks', (j) => j.str('title'),
            trailing: (j) => j.str('status')),
        ..._listSection('المستندات', 'documents', (j) => j.str('title'),
            trailing: (j) => j.str('status')),
      ],
    );
  }

  /// Renders a section only when the API actually returned entries for it, so the
  /// screen never shows an empty heading.
  List<Widget> _listSection(
    String title,
    String key,
    String Function(Json) primary, {
    String Function(Json)? trailing,
  }) {
    final items = (_data?[key] is List)
        ? (_data![key] as List)
            .whereType<Map>()
            .map((e) => Json(Map<String, dynamic>.from(e)))
            .toList()
        : <Json>[];
    if (items.isEmpty) return const [];

    return [
      SectionHeader(title: title),
      ...items.map((j) => Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: context.tokens.surface,
                border: Border.all(color: context.tokens.border),
                borderRadius: BorderRadius.circular(AppRadius.md),
              ),
              child: Row(
                children: [
                  Expanded(
                    child: Text(
                      primary(j),
                      style: Theme.of(context).textTheme.bodyMedium,
                    ),
                  ),
                  if (trailing != null && trailing(j).isNotEmpty)
                    StatusBadge(status: trailing(j)),
                ],
              ),
            ),
          )),
      const SizedBox(height: 14),
    ];
  }
}
