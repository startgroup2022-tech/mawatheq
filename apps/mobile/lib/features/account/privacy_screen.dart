import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/config/app_config.dart';
import '../../core/locale_controller.dart';
import '../../core/network/account_models.dart';
import '../../core/network/api_exception.dart';
import '../../core/network/api_repository.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_tokens.dart';
import '../../shared/widgets/brand_widgets.dart';

/// Privacy & consent: the policy links, the deletion terms, and the preferences.
///
/// Consent is opt-in and the switch state always reflects the server, never a
/// local assumption. A toggle is disabled while its write is in flight so a
/// double-tap cannot send two conflicting values; on failure the switch is put
/// back where it was, because a switch that stays flipped after a failed write is
/// a lie about what the account has agreed to.
class PrivacyScreen extends StatefulWidget {
  const PrivacyScreen({super.key, this.repository});

  final ApiRepository? repository;

  @override
  State<PrivacyScreen> createState() => _PrivacyScreenState();
}

class _PrivacyScreenState extends State<PrivacyScreen> {
  late final ApiRepository _repo = widget.repository ?? ApiRepository();

  PrivacyInfo? _info;
  List<ConsentPreference> _consents = const [];
  final Set<String> _saving = {};
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
      final info = await _repo.privacyInfo();
      final consents = await _repo.consentPreferences();
      if (!mounted) return;
      setState(() {
        _info = info;
        _consents = consents;
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
      setState(() => _loading = false);
    }
  }

  Future<void> _toggle(ConsentPreference consent, bool value, bool rtl) async {
    if (_saving.contains(consent.key)) return;
    setState(() => _saving.add(consent.key));
    try {
      await _repo.setConsent(key: consent.key, granted: value);
      if (!mounted) return;
      setState(() {
        _consents = _consents
            .map(
              (c) => c.key == consent.key
                  ? ConsentPreference(
                      key: c.key,
                      labelAr: c.labelAr,
                      labelEn: c.labelEn,
                      granted: value,
                    )
                  : c,
            )
            .toList();
      });
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            rtl ? 'تعذّر حفظ التفضيل.' : 'Could not save the preference.',
          ),
        ),
      );
    } finally {
      if (mounted) setState(() => _saving.remove(consent.key));
    }
  }

  Future<void> _openLink(String url) async {
    final target = url.isEmpty ? AppConfig.siteBase : url;
    final uri = Uri.tryParse(target);
    if (uri == null) return;
    try {
      await launchUrl(uri, mode: LaunchMode.externalApplication);
    } catch (_) {
      // A device without a browser simply does nothing here; there is no data to
      // lose, so no error dialog is warranted.
    }
  }

  @override
  Widget build(BuildContext context) {
    final rtl = context.watch<LocaleController>().isRtl;
    return Scaffold(
      appBar: AppBar(
        title: Text(rtl ? 'الخصوصية والموافقات' : 'Privacy & consent'),
      ),
      body: _loading
          ? const Padding(
              padding: EdgeInsets.all(16),
              child: LoadingSkeleton(count: 4, height: 60),
            )
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  if (_error != null)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 12),
                      child: Text(
                        rtl
                            ? 'تعذّر تحميل بعض البيانات. اسحب للتحديث.'
                            : 'Some data could not be loaded. Pull to refresh.',
                        style: Theme.of(context).textTheme.bodySmall,
                      ),
                    ),
                  SectionHeader(title: rtl ? 'المستندات' : 'Documents'),
                  _linkTile(
                    context,
                    rtl,
                    Icons.privacy_tip_outlined,
                    rtl ? 'سياسة الخصوصية' : 'Privacy policy',
                    _info?.privacyUrl ?? '',
                  ),
                  _linkTile(
                    context,
                    rtl,
                    Icons.description_outlined,
                    rtl ? 'الشروط والأحكام' : 'Terms & conditions',
                    _info?.termsUrl ?? '',
                  ),
                  const SizedBox(height: 20),
                  SectionHeader(
                    title: rtl ? 'تفضيلات الموافقة' : 'Consent preferences',
                  ),
                  ..._consents.map((c) => _consentTile(context, rtl, c)),
                  if (_info != null &&
                      (_info!.deletes.isNotEmpty ||
                          _info!.keeps.isNotEmpty)) ...[
                    const SizedBox(height: 20),
                    SectionHeader(
                      title: rtl
                          ? 'عند حذف الحساب'
                          : 'When you delete your account',
                    ),
                    _deletionCard(context, rtl, _info!),
                  ],
                  const SizedBox(height: 16),
                ],
              ),
            ),
    );
  }

  Widget _linkTile(
    BuildContext context,
    bool rtl,
    IconData icon,
    String label,
    String url,
  ) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Material(
        color: context.tokens.surface,
        shape: RoundedRectangleBorder(
          side: BorderSide(color: context.tokens.border),
          borderRadius: BorderRadius.circular(AppRadius.md),
        ),
        child: ListTile(
          onTap: () => _openLink(url),
          leading: Icon(icon, size: 20, color: context.tokens.textMuted),
          title: Text(label, style: Theme.of(context).textTheme.bodyMedium),
          trailing: Icon(
            Icons.open_in_new,
            size: 18,
            color: context.tokens.textSubtle,
          ),
        ),
      ),
    );
  }

  Widget _consentTile(BuildContext context, bool rtl, ConsentPreference c) {
    final saving = _saving.contains(c.key);
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Material(
        color: context.tokens.surface,
        shape: RoundedRectangleBorder(
          side: BorderSide(color: context.tokens.border),
          borderRadius: BorderRadius.circular(AppRadius.md),
        ),
        child: SwitchListTile(
          value: c.granted,
          onChanged: saving ? null : (v) => _toggle(c, v, rtl),
          title: Text(
            c.label(rtl),
            style: Theme.of(context).textTheme.bodyMedium,
          ),
          secondary: saving
              ? const SizedBox(
                  width: 18,
                  height: 18,
                  child: CircularProgressIndicator(strokeWidth: 2),
                )
              : null,
        ),
      ),
    );
  }

  Widget _deletionCard(BuildContext context, bool rtl, PrivacyInfo info) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: context.tokens.surface2,
        borderRadius: BorderRadius.circular(AppRadius.md),
        border: Border.all(color: context.tokens.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (info.deletes.isNotEmpty) ...[
            Text(
              rtl ? 'يُحذف' : 'Deleted',
              style: TextStyle(
                fontWeight: FontWeight.w700,
                color: AppColors.danger,
              ),
            ),
            const SizedBox(height: 4),
            ...info.deletes.map((d) => _bullet(context, d)),
          ],
          if (info.keeps.isNotEmpty) ...[
            const SizedBox(height: 12),
            Text(
              rtl ? 'يبقى للامتثال النظامي' : 'Retained for compliance',
              style: TextStyle(
                fontWeight: FontWeight.w700,
                color: context.tokens.heading,
              ),
            ),
            const SizedBox(height: 4),
            ...info.keeps.map((k) => _bullet(context, k)),
          ],
        ],
      ),
    );
  }

  Widget _bullet(BuildContext context, String text) => Padding(
    padding: const EdgeInsets.only(bottom: 3),
    child: Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          padding: const EdgeInsets.only(top: 6, left: 2, right: 6),
          child: Container(
            width: 5,
            height: 5,
            decoration: BoxDecoration(
              color: context.tokens.textMuted,
              shape: BoxShape.circle,
            ),
          ),
        ),
        Expanded(
          child: Text(text, style: Theme.of(context).textTheme.bodySmall),
        ),
      ],
    ),
  );
}
