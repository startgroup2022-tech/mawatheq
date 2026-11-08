import 'models.dart';

/// A single row in the account's transaction history.
///
/// The server merges four sources (consultations, packages, invoices, invoice
/// payments) into this one shape, so the screen renders a single list and never
/// has to know which table a row came from. [type] is still carried because the
/// filter chips need it and a refund is shown differently from a charge.
class Transaction {
  const Transaction({
    required this.id,
    required this.type,
    required this.status,
    required this.amount,
    required this.currency,
    this.date = '',
    this.reference = '',
    this.title = '',
    this.subtitle = '',
    this.link = '',
  });

  final int id;
  final String type;
  final String status;

  /// Already formatted by the server through the currency helper, so a 3-decimal
  /// legacy currency and a 2-decimal one both render correctly without the app
  /// re-deriving precision.
  final String amount;
  final String currency;
  final String date;
  final String reference;
  final String title;
  final String subtitle;
  final String link;

  bool get isRefund => status == 'refunded' || type == 'refund';

  factory Transaction.fromJson(Map<String, dynamic> map) {
    final j = Json(map);
    return Transaction(
      id: j.intg('id'),
      type: j.str('type', fallback: 'invoice'),
      status: j.str('status', fallback: 'pending'),
      amount: j.str('amount', fallback: '0'),
      currency: j.str('currency', fallback: 'SAR'),
      date: j.str('date'),
      reference: j.str('reference'),
      title: j.str('title'),
      subtitle: j.str('subtitle'),
      link: j.str('link'),
    );
  }
}

/// The history header totals, in the client's own currency.
class TransactionSummary {
  const TransactionSummary({
    this.count = 0,
    this.paid = '0',
    this.pending = '0',
    this.refunded = '0',
    this.currency = 'SAR',
  });

  final int count;
  final String paid;
  final String pending;
  final String refunded;
  final String currency;

  factory TransactionSummary.fromJson(Map<String, dynamic> map) {
    final j = Json(map);
    return TransactionSummary(
      count: j.intg('count'),
      paid: j.str('paid', fallback: '0'),
      pending: j.str('pending', fallback: '0'),
      refunded: j.str('refunded', fallback: '0'),
      currency: j.str('currency', fallback: 'SAR'),
    );
  }
}

/// The transaction history plus its summary, as returned by one request.
class TransactionPage {
  const TransactionPage({this.transactions = const [], required this.summary});

  final List<Transaction> transactions;
  final TransactionSummary summary;
}

/// One line item on an invoice.
class InvoiceItem {
  const InvoiceItem({
    this.description = '',
    this.quantity = 0,
    this.unitPrice = 0,
    this.total = 0,
  });

  final String description;
  final double quantity;
  final double unitPrice;
  final double total;

  factory InvoiceItem.fromJson(Map<String, dynamic> map) {
    final j = Json(map);
    return InvoiceItem(
      description: j.str('description'),
      quantity: j.dbl('quantity'),
      unitPrice: j.dbl('unit_price'),
      total: j.dbl('total'),
    );
  }
}

/// A payment recorded against an invoice.
class InvoicePayment {
  const InvoicePayment({
    this.amount = 0,
    this.currency = 'SAR',
    this.method = '',
    this.paidAt = '',
  });

  final double amount;
  final String currency;
  final String method;
  final String paidAt;

  factory InvoicePayment.fromJson(Map<String, dynamic> map) {
    final j = Json(map);
    return InvoicePayment(
      amount: j.dbl('amount'),
      currency: j.str('currency', fallback: 'SAR'),
      method: j.str('method'),
      paidAt: j.str('paid_at'),
    );
  }
}

/// A full invoice with its items and payments, for the detail screen.
class InvoiceDetail {
  const InvoiceDetail({
    required this.id,
    this.number = '',
    this.description = '',
    this.status = 'unpaid',
    this.total = 0,
    this.amountPaid = 0,
    this.balance = 0,
    this.currency = 'SAR',
    this.issuedAt = '',
    this.party = '',
    this.receiptUrl = '',
    this.items = const [],
    this.payments = const [],
  });

  final int id;
  final String number;
  final String description;
  final String status;
  final double total;
  final double amountPaid;
  final double balance;
  final String currency;
  final String issuedAt;

  /// The counterparty — the lawyer for a client, the client for a lawyer.
  final String party;
  final String receiptUrl;
  final List<InvoiceItem> items;
  final List<InvoicePayment> payments;

  factory InvoiceDetail.fromJson(
    Map<String, dynamic> map, {
    List<Map<String, dynamic>> items = const [],
    List<Map<String, dynamic>> payments = const [],
    String receiptUrl = '',
    bool isLawyerView = false,
  }) {
    final j = Json(map);
    return InvoiceDetail(
      id: j.intg('id'),
      number: j.str('invoice_number'),
      description: j.str('description'),
      status: j.str(
        'display_status',
        fallback: j.str('status', fallback: 'unpaid'),
      ),
      total: j.dbl('amount'),
      amountPaid: j.dbl('amount_paid'),
      balance: j.dbl('balance'),
      currency: j.str('currency', fallback: 'SAR'),
      issuedAt: j.str('issue_date', fallback: j.str('created_at')),
      party: isLawyerView ? j.str('client_name') : j.str('lawyer_name'),
      receiptUrl: receiptUrl,
      items: items.map(InvoiceItem.fromJson).toList(),
      payments: payments.map(InvoicePayment.fromJson).toList(),
    );
  }
}

/// A support ticket as listed in the account's support centre.
class SupportTicket {
  const SupportTicket({
    required this.id,
    this.number = '',
    this.subject = '',
    this.status = 'open',
    this.priority = 'normal',
    this.categoryAr = '',
    this.categoryEn = '',
    this.updatedAt = '',
    this.unread = false,
  });

  final int id;
  final String number;
  final String subject;
  final String status;
  final String priority;
  final String categoryAr;
  final String categoryEn;
  final String updatedAt;

  /// Whether support has replied since the customer last opened the ticket.
  final bool unread;

  bool get isClosed => status == 'closed';

  factory SupportTicket.fromJson(Map<String, dynamic> map) {
    final j = Json(map);
    return SupportTicket(
      id: j.intg('id'),
      number: j.str('ticket_number'),
      subject: j.str('subject'),
      status: j.str('status', fallback: 'open'),
      priority: j.str('priority', fallback: 'normal'),
      categoryAr: j.str('category_ar'),
      categoryEn: j.str('category_en'),
      updatedAt: j.str('updated_at', fallback: j.str('created_at')),
      unread: j.boolean('unread_for_customer'),
    );
  }
}

/// One message in a ticket thread.
class SupportMessage {
  const SupportMessage({
    required this.id,
    this.authorType = 'customer',
    this.authorName = '',
    this.body = '',
    this.createdAt = '',
  });

  final int id;
  final String authorType;
  final String authorName;
  final String body;
  final String createdAt;

  bool get fromSupport => authorType == 'support';

  factory SupportMessage.fromJson(Map<String, dynamic> map) {
    final j = Json(map);
    return SupportMessage(
      id: j.intg('id'),
      authorType: j.str('author_type', fallback: 'customer'),
      authorName: j.str('author_name'),
      body: j.str('body'),
      createdAt: j.str('created_at'),
    );
  }
}

/// A ticket with its full thread.
class SupportTicketDetail {
  const SupportTicketDetail({
    required this.ticket,
    this.messages = const [],
    this.attachments = const [],
  });

  final SupportTicket ticket;
  final List<SupportMessage> messages;
  final List<SupportAttachment> attachments;
}

/// A file attached to a ticket.
class SupportAttachment {
  const SupportAttachment({
    required this.id,
    this.name = '',
    this.size = 0,
    this.createdAt = '',
  });

  final int id;
  final String name;
  final int size;
  final String createdAt;

  factory SupportAttachment.fromJson(Map<String, dynamic> map) {
    final j = Json(map);
    return SupportAttachment(
      id: j.intg('id'),
      name: j.str('original_name'),
      size: j.intg('file_size'),
      createdAt: j.str('created_at'),
    );
  }
}

/// A support ticket category for the picker.
class SupportCategory {
  const SupportCategory({required this.id, this.nameAr = '', this.nameEn = ''});

  final int id;
  final String nameAr;
  final String nameEn;

  String name(bool isRtl) =>
      isRtl ? nameAr : (nameEn.isEmpty ? nameAr : nameEn);

  factory SupportCategory.fromJson(Map<String, dynamic> map) {
    final j = Json(map);
    return SupportCategory(
      id: j.intg('id'),
      nameAr: j.str('name_ar'),
      nameEn: j.str('name_en'),
    );
  }
}

/// An open support appointment slot.
class SupportSlot {
  const SupportSlot({
    required this.id,
    this.startsAt = '',
    this.endsAt = '',
    this.remaining = 0,
    this.note = '',
  });

  final int id;
  final String startsAt;
  final String endsAt;
  final int remaining;
  final String note;

  bool get isFull => remaining <= 0;

  factory SupportSlot.fromJson(Map<String, dynamic> map) {
    final j = Json(map);
    return SupportSlot(
      id: j.intg('id'),
      startsAt: j.str('starts_at'),
      endsAt: j.str('ends_at'),
      remaining: j.intg('remaining'),
      note: j.str('note'),
    );
  }
}

/// A customer's support appointment request.
class SupportAppointment {
  const SupportAppointment({
    required this.id,
    this.reference = '',
    this.subject = '',
    this.status = 'pending',
    this.startsAt = '',
    this.adminNote = '',
  });

  final int id;
  final String reference;
  final String subject;
  final String status;
  final String startsAt;
  final String adminNote;

  factory SupportAppointment.fromJson(Map<String, dynamic> map) {
    final j = Json(map);
    return SupportAppointment(
      id: j.intg('id'),
      reference: j.str('reference'),
      subject: j.str('subject'),
      status: j.str('status', fallback: 'pending'),
      startsAt: j.str('starts_at'),
      adminNote: j.str('admin_note'),
    );
  }
}

/// One configured support channel. [type] decides how the app opens it.
class SupportChannel {
  const SupportChannel({
    required this.key,
    required this.type,
    this.value = '',
  });

  final String key;

  /// `email`, `whatsapp`, `tickets` or `appointments`.
  final String type;
  final String value;

  factory SupportChannel.fromJson(Map<String, dynamic> map) {
    final j = Json(map);
    return SupportChannel(
      key: j.str('key'),
      type: j.str('type'),
      value: j.str('value'),
    );
  }
}

/// The support channels plus working hours, as returned by `support.php`.
class SupportSettings {
  const SupportSettings({
    this.channels = const [],
    this.hours = '',
    this.whatsappUrl = '',
  });

  final List<SupportChannel> channels;
  final String hours;
  final String whatsappUrl;

  bool has(String type) => channels.any((c) => c.type == type);

  factory SupportSettings.fromJson(Map<String, dynamic> map) {
    final j = Json(map);
    return SupportSettings(
      channels: j
          .list('channels')
          .map((e) => SupportChannel.fromJson(e.asMap))
          .toList(),
      hours: j.str('hours'),
      whatsappUrl: j.str('whatsapp_url'),
    );
  }
}

/// A privacy consent preference.
class ConsentPreference {
  const ConsentPreference({
    required this.key,
    this.labelAr = '',
    this.labelEn = '',
    this.granted = false,
  });

  final String key;
  final String labelAr;
  final String labelEn;
  final bool granted;

  String label(bool isRtl) =>
      isRtl ? labelAr : (labelEn.isEmpty ? labelAr : labelEn);

  factory ConsentPreference.fromJson(Map<String, dynamic> map) {
    final j = Json(map);
    return ConsentPreference(
      key: j.str('key'),
      labelAr: j.str('label_ar'),
      labelEn: j.str('label_en'),
      granted: j.boolean('granted'),
    );
  }
}

/// The privacy policy links and the account-deletion consequences.
class PrivacyInfo {
  const PrivacyInfo({
    this.privacyUrl = '',
    this.termsUrl = '',
    this.accountUrl = '',
    this.deletes = const [],
    this.keeps = const [],
  });

  final String privacyUrl;
  final String termsUrl;
  final String accountUrl;
  final List<String> deletes;
  final List<String> keeps;

  factory PrivacyInfo.fromJson(Map<String, dynamic> map) {
    final j = Json(map);
    final deletion = j.object('deletion');
    return PrivacyInfo(
      privacyUrl: j.str('privacy_url'),
      termsUrl: j.str('terms_url'),
      accountUrl: j.str('account_url'),
      deletes: deletion?.strList('deletes') ?? const [],
      keeps: deletion?.strList('keeps') ?? const [],
    );
  }
}
