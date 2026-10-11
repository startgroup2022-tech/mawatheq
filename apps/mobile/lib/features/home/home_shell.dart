import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/auth_controller.dart';
import '../../shared/widgets/brand_widgets.dart';
import '../account/account_screen.dart';
import '../assistant/assistant_screen.dart';
import '../cases/cases_screen.dart';
import '../documents/documents_screen.dart';
import '../notifications/notifications_screen.dart';
import 'home_tab.dart';

/// The signed-in shell: brand app bar plus the five primary destinations.
///
/// The tabs are the ones the API actually supports; nothing here links to a
/// screen the backend cannot fill.
class HomeShell extends StatefulWidget {
  const HomeShell({super.key, this.initialIndex = 0});

  final int initialIndex;

  @override
  State<HomeShell> createState() => _HomeShellState();
}

class _HomeShellState extends State<HomeShell> {
  late int _index = widget.initialIndex;


  @override
  Widget build(BuildContext context) {
    final user = context.watch<AuthController>().user;

    final pages = [
      HomeTab(onNavigate: (i) => setState(() => _index = i)),
      const CasesScreen(),
      const AssistantScreen(),
      const DocumentsScreen(),
      const AccountScreen(),
    ];

    return Scaffold(
      appBar: BrandAppBar(
        subtitle: user.name.isEmpty ? null : user.name,
        actions: [
          IconButton(
            tooltip: 'الإشعارات',
            icon: const Icon(Icons.notifications_none),
            onPressed: () => Navigator.of(context).push(
              MaterialPageRoute(builder: (_) => const NotificationsScreen()),
            ),
          ),
        ],
      ),
      body: IndexedStack(index: _index, children: pages),
      bottomNavigationBar: BottomNavigationBar(
        currentIndex: _index,
        onTap: (i) => setState(() => _index = i),
        items: const [
          BottomNavigationBarItem(
            icon: Icon(Icons.home_outlined),
            activeIcon: Icon(Icons.home),
            label: 'الرئيسية',
          ),
          BottomNavigationBarItem(
            icon: Icon(Icons.folder_outlined),
            activeIcon: Icon(Icons.folder),
            label: 'القضايا',
          ),
          BottomNavigationBarItem(
            icon: Icon(Icons.auto_awesome_outlined),
            activeIcon: Icon(Icons.auto_awesome),
            label: 'المساعد',
          ),
          BottomNavigationBarItem(
            icon: Icon(Icons.description_outlined),
            activeIcon: Icon(Icons.description),
            label: 'المستندات',
          ),
          BottomNavigationBarItem(
            icon: Icon(Icons.person_outline),
            activeIcon: Icon(Icons.person),
            label: 'حسابي',
          ),
        ],
      ),
    );
  }
}
