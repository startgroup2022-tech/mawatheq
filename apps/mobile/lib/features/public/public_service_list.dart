import 'package:flutter/material.dart';

import '../../core/guest_session.dart';
import '../../core/theme/app_colors.dart';
import '../../shared/widgets/auth_gate.dart';

/// One public service a visitor can read about but must sign in to use.
///
/// Each entry maps to a real capability the platform ships; the destination is used
/// both to name the service in the prompt and to resume it after sign-in.
const publicServices = <(String, IconData, ProtectedDestination, String, String)>[
  (
    'ai',
    Icons.auto_awesome_outlined,
    ProtectedDestination.legalAi,
    'المساعد القانوني الذكي',
    'Legal AI assistant',
  ),
  (
    'cases',
    Icons.folder_outlined,
    ProtectedDestination.cases,
    'إدارة القضايا',
    'Case management',
  ),
  (
    'documents',
    Icons.description_outlined,
    ProtectedDestination.documents,
    'إدارة المستندات',
    'Document management',
  ),
  (
    'consult',
    Icons.forum_outlined,
    ProtectedDestination.consultations,
    'الاستشارات القانونية',
    'Legal consultations',
  ),
  (
    'laws',
    Icons.menu_book_outlined,
    ProtectedDestination.legalAi,
    'البحث في الأنظمة واللوائح',
    'Research in laws and regulations',
  ),
  (
    'draft',
    Icons.edit_document,
    ProtectedDestination.legalAi,
    'صياغة العقود والمذكرات',
    'Contract and memorandum drafting',
  ),
];

/// The list of public services, shared by the homepage and the services tab.
///
/// Every card raises the authentication prompt naming that service; none opens a
/// protected screen and none fires a request the server would reject for a guest.
class PublicServiceList extends StatelessWidget {
  const PublicServiceList({super.key, required this.rtl});

  final bool rtl;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        for (final s in publicServices)
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Material(
              color: AppColors.surface,
              clipBehavior: Clip.antiAlias,
              shape: RoundedRectangleBorder(
                side: const BorderSide(color: AppColors.border),
                borderRadius: BorderRadius.circular(AppRadius.lg),
              ),
              child: InkWell(
                onTap: () => requireAccount(context, s.$3),
                child: Padding(
                  padding: const EdgeInsets.all(14),
                  child: Row(
                    children: [
                      Container(
                        width: 40,
                        height: 40,
                        decoration: BoxDecoration(
                          gradient: AppColors.cyanAccent,
                          borderRadius: BorderRadius.circular(AppRadius.md),
                        ),
                        child: Icon(s.$2, size: 20, color: AppColors.navyDeep),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Text(
                          rtl ? s.$4 : s.$5,
                          style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                                fontWeight: FontWeight.w600,
                              ),
                        ),
                      ),
                      const Icon(Icons.lock_outline,
                          size: 16, color: AppColors.textSubtle),
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
