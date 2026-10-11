import 'package:flutter/material.dart';

import '../../core/network/api_exception.dart';
import '../../core/network/api_repository.dart';
import '../../core/network/models.dart';
import '../../shared/widgets/brand_widgets.dart';

/// Consultations list from `GET /api/v1/consultations.php`.
///
/// The same endpoint returns the lawyer's queue for a lawyer token and the
/// client's requests for a client token, so one screen serves both.
class ConsultationsScreen extends StatefulWidget {
  const ConsultationsScreen({super.key});

  @override
  State<ConsultationsScreen> createState() => _ConsultationsScreenState();
}

class _ConsultationsScreenState extends State<ConsultationsScreen> {
  final _repo = ApiRepository();
  List<Consultation> _all = const [];
  bool _loading = true;
  String? _error;
  String _filter = 'الكل';

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
      final items = await _repo.consultations();
      if (!mounted) return;
      setState(() {
        _all = items;
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

  List<Consultation> get _visible {
    if (_filter == 'الكل') return _all;
    if (_filter == 'مؤكدة') return _all.where((c) => c.status == 'confirmed').toList();
    if (_filter == 'قيد الانتظار') {
      return _all.where((c) => c.status == 'pending').toList();
    }
    return _all;
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: const BrandAppBar(subtitle: 'الاستشارات'),
      body: _loading
          ? const Padding(
              padding: EdgeInsets.all(16),
              child: LoadingSkeleton(count: 5),
            )
          : _error != null
              ? ErrorState(message: _error!, onRetry: _load)
              : Column(
                  children: [
                    Padding(
                      padding: const EdgeInsets.fromLTRB(16, 14, 16, 10),
                      child: FilterChips(
                        options: const ['الكل', 'مؤكدة', 'قيد الانتظار'],
                        selected: _filter,
                        onSelected: (v) => setState(() => _filter = v),
                      ),
                    ),
                    Expanded(
                      child: _visible.isEmpty
                          ? const EmptyState(
                              icon: Icons.forum_outlined,
                              title: 'لا توجد استشارات',
                              message: 'ستظهر هنا استشاراتك عند إنشائها.',
                            )
                          : RefreshIndicator(
                              onRefresh: _load,
                              child: ListView.separated(
                                padding:
                                    const EdgeInsets.fromLTRB(16, 4, 16, 20),
                                itemCount: _visible.length,
                                separatorBuilder: (_, __) =>
                                    const SizedBox(height: 10),
                                itemBuilder: (context, i) =>
                                    ConsultationCard(item: _visible[i]),
                              ),
                            ),
                    ),
                  ],
                ),
    );
  }
}
