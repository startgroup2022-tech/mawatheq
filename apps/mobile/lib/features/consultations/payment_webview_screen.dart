
import 'package:flutter/material.dart';
import 'package:webview_flutter/webview_flutter.dart';

import '../../core/theme/app_colors.dart';
import '../../core/theme/app_tokens.dart';

/// The hosted N-Genius payment page, opened in a web view (Execution 62).
///
/// The app never collects card data itself — the gateway's own hosted page does. The
/// customer is redirected back to the site's `consultation-payment-return.php` when the
/// payment finishes; that page re-verifies the capture against the gateway server-side,
/// so the web view only reports "the customer came back", never "it is paid". The
/// consultation list refreshes from the server afterwards and shows the real state.
class PaymentWebViewScreen extends StatefulWidget {
  const PaymentWebViewScreen({
    super.key,
    required this.paymentUrl,
    required this.consultationId,
  });

  final String paymentUrl;
  final int consultationId;

  /// True when [url] is the gateway's return URL for this consultation. Exposed so a
  /// unit test can assert the exact match without a web view.
  static bool isReturnUrl(String url, int consultationId) {
    final path = Uri.tryParse(url)?.path ?? '';
    if (!path.endsWith('/consultation-payment-return.php')) return false;
    final id = Uri.tryParse(url)?.queryParameters['consultation_id'];
    return id == consultationId.toString();
  }

  @override
  State<PaymentWebViewScreen> createState() => _PaymentWebViewScreenState();
}

class _PaymentWebViewScreenState extends State<PaymentWebViewScreen> {
  late final WebViewController _controller;
  bool _loading = true;
  bool _finished = false;

  @override
  void initState() {
    super.initState();
    _controller = WebViewController()
      ..setJavaScriptMode(JavaScriptMode.unrestricted)
      ..setNavigationDelegate(NavigationDelegate(
        onPageStarted: (_) {
          if (mounted) setState(() => _loading = true);
        },
        onPageFinished: (_) {
          if (mounted) setState(() => _loading = false);
        },
        onNavigationRequest: (request) {
          // Stop at the site's return URL: the booking state is settled server-side, so
          // there is nothing further for the customer to do inside the web view.
          if (PaymentWebViewScreen.isReturnUrl(request.url, widget.consultationId)) {
            if (mounted && !_finished) {
              _finished = true;
              Navigator.of(context).pop(true);
            }
            return NavigationDecision.prevent;
          }
          return NavigationDecision.navigate;
        },
      ))
      ..loadRequest(Uri.parse(widget.paymentUrl));
  }

  @override
  Widget build(BuildContext context) {
    final tokens = context.tokens;
    return Scaffold(
      appBar: AppBar(
        title: const Text('إتمام الدفع'),
        backgroundColor: AppColors.navy,
        foregroundColor: Colors.white,
        leading: IconButton(
          icon: const Icon(Icons.close),
          tooltip: 'إغلاق',
          onPressed: () => Navigator.of(context).pop(false),
        ),
      ),
      body: Stack(
        children: [
          WebViewWidget(controller: _controller),
          if (_loading)
            const Center(child: CircularProgressIndicator())
          else
            const SizedBox.shrink(),
          Positioned(
            left: 0,
            right: 0,
            bottom: 0,
            child: Container(
              color: tokens.surface.withValues(alpha: 0.94),
              padding: const EdgeInsets.all(12),
              child: Text(
                'يتم الدفع عبر بوابة N-Genius الآمنة. لا تُدخل بيانات البطاقة في التطبيق.',
                textAlign: TextAlign.center,
                style: TextStyle(fontSize: 12, color: tokens.textMuted),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
