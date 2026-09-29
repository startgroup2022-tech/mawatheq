<?php
/**
 * إرسال بريد بسيط عبر دالة mail() المدمجة بـPHP — تعمل مباشرة على أغلب
 * استضافات cPanel بدون أي إعداد إضافي (تُرسل عبر Exim على السيرفر نفسه).
 * إن احتجت لاحقاً موثوقية أعلى (SMTP خارجي)، يمكن استبدال هذه الدالة بمكتبة
 * مثل PHPMailer دون تغيير أي كود آخر في المشروع (نفس التوقيع).
 */
function send_mail(string $to, string $subject, string $htmlBody): bool {
    $fromEmail = defined('MAIL_FROM_EMAIL') ? trim((string)MAIL_FROM_EMAIL) : '';
    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $fromEmail)) {
        error_log('Mawatheq mail delivery failed: MAIL_FROM_EMAIL is invalid.');
        return false;
    }
    $fromName = defined('MAIL_FROM_NAME') ? trim((string)MAIL_FROM_NAME) : 'Mawatheq';
    $fromName = preg_replace('/[\r\n]+/', ' ', $fromName);
    $encodedFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $encodedFromName . ' <' . $fromEmail . '>',
        'Reply-To: ' . $fromEmail,
        'X-Mailer: PHP/' . phpversion(),
    ];
    // encode-word للموضوع حتى يظهر صحيحاً بالعربي في عملاء البريد
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    // Set the SMTP envelope sender too; otherwise cPanel may use the server
    // account address (for example user@host.server) and fail domain alignment.
    $sent = @mail($to, $encodedSubject, $htmlBody, implode("\r\n", $headers), '-f' . $fromEmail);
    if (!$sent) error_log('Mawatheq mail delivery handoff failed.');
    return $sent;
}

/** قالب بريد HTML موحّد بسيط لرسائل النظام. */
function render_mail_template(string $title, string $bodyHtml, string $buttonText = '', string $buttonUrl = ''): string {
    $button = '';
    if ($buttonUrl) {
        $button = '<p style="text-align:center;margin:28px 0">
            <a href="' . htmlspecialchars($buttonUrl) . '" style="background:#1E3A5F;color:#fff;padding:13px 28px;border-radius:8px;text-decoration:none;font-weight:700;display:inline-block">' . htmlspecialchars($buttonText) . '</a>
        </p>';
    }
    return '
    <div dir="rtl" style="font-family:Tahoma,Arial,sans-serif;background:#F6F8FA;padding:30px 0">
      <div style="max-width:480px;margin:0 auto;background:#fff;border-radius:12px;padding:32px;border:1px solid #E3E9EF">
        <h2 style="color:#1E3A5F;margin:0 0 16px">' . htmlspecialchars($title) . '</h2>
        <div style="color:#333;line-height:1.9;font-size:.95rem">' . $bodyHtml . '</div>
        ' . $button . '
        <p style="color:#9AA6B2;font-size:.78rem;margin-top:24px;border-top:1px solid #E3E9EF;padding-top:16px">
          ' . htmlspecialchars(SITE_NAME_AR) . ' — ' . htmlspecialchars(SITE_URL) . '
        </p>
      </div>
    </div>';
}
