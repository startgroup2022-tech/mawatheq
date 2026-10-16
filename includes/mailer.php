<?php
/**
 * إرسال بريد بسيط. يفضّل SMTP الموثّق عند ضبطه من لوحة التحكم (يشمل STARTTLS)،
 * وإلا يرجع إلى دالة mail() المدمجة عبر Exim/التسليم المحلي.
 *
 * على استضافات cPanel كثيراً ما يفشل mail() بصمت: قد يعيد true دون تسليم فعلي،
 * ولا يدعم المصادقة، فيُرفض أو يُصنَّف كبريد مزعج بلا خطأ ظاهر. ضبط SMTP عبر مزوّد
 * البريد الذي يملك السجلات SPF/DKIM للمجال هو الحل الموثوق.
 */
function mail_last_error(): ?string {
    return $GLOBALS['mawatheq_mail_error'] ?? null;
}

/**
 * The body of the most recent send_mail() call, or null if none was sent.
 *
 * Exists so a test can assert on the message a user would actually receive — the only
 * place a password-reset token exists in plaintext once it is stored hashed. Never
 * written to a log or a response; it lives only for the life of the request.
 */
function mail_last_body(): ?string {
    return $GLOBALS['mawatheq_mail_last_body'] ?? null;
}

function send_mail(string $to, string $subject, string $htmlBody): bool {
    $GLOBALS['mawatheq_mail_error'] = null;
    $GLOBALS['mawatheq_mail_last_body'] = $htmlBody;

    // Prefer an authenticated SMTP relay when the owner has configured one. The SMTP
    // transport lives in a separate, optional module: if a deployment did not ship
    // `smtp.php`, delivery must still work through the original mail() path rather than
    // fail every send. The module is only consulted once it actually exists and loads.
    $smtpFile = __DIR__ . '/smtp.php';
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO && is_file($smtpFile)) {
        require_once $smtpFile;
        if (function_exists('smtp_configured')) {
            $settings = smtp_settings($GLOBALS['pdo']);
            if (smtp_configured($settings)) {
                $smtpError = null;
                if (smtp_send($settings, $to, $subject, $htmlBody, $smtpError)) {
                    return true;
                }
                $GLOBALS['mawatheq_mail_error'] = 'SMTP: ' . (string)$smtpError;
                error_log('Mawatheq SMTP delivery failed: ' . (string)$smtpError);
                return false;
            }
        }
    }

    return send_mail_via_php_mail($to, $subject, $htmlBody);
}

/** The legacy local-MTA path: PHP mail() through the host's sendmail binary. */
function send_mail_via_php_mail(string $to, string $subject, string $htmlBody): bool {
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
