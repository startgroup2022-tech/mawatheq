<?php
/**
 * Gateway payment diagnostics (Execution 67).
 *
 * Why this module exists: the customer-facing message "could not start the online
 * payment" is produced by the same line for half a dozen unrelated causes — the
 * gateway is switched off, the API key is rejected, the account is pointed at the
 * wrong environment, the token call returns a bare 400, or the outlet rejects the
 * order. From the UI they are indistinguishable, so every investigation restarts from
 * zero and the only honest advice is "contact us".
 *
 * This module turns one opaque failure into a self-identifying one. Each attempt is
 * recorded with:
 *   - a correlation id the customer can quote (shown with the error),
 *   - the operation and the stage that failed,
 *   - the gateway's own (sanitized) reason and HTTP status,
 *   - a timestamp.
 *
 * Hard rules, because payment logs are the most dangerous logs to get wrong:
 *   - an API key, access token, webhook secret or card field is NEVER recorded;
 *   - a customer email is never recorded (only its presence, as a boolean);
 *   - a diagnostics write never throws into the payment path (best-effort only).
 */

/** A short, uppercase id the customer can read out and the operator can grep. */
function gateway_correlation_id(): string {
    static $id = null;
    if ($id === null) {
        $id = strtoupper(bin2hex(random_bytes(4)));
    }
    return $id;
}

/** Whether the diagnostics table exists (upgrade_v72.sql applied). */
function gateway_diag_schema_ready(PDO $pdo): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $ready = (bool)$pdo->query("SELECT 1 FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gateway_diagnostics' LIMIT 1")->fetchColumn();
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

/**
 * Remove anything credential-shaped from a string before it is stored or shown.
 *
 * The gateway's own error bodies are useful and safe (they name the rejection), but a
 * defensive redactor keeps a future response shape from leaking a token into the log.
 */
function gateway_diag_redact(string $text): string {
    $patterns = [
        // "access_token": "...."  |  api_key=...  |  Authorization: ...  |  token: ...
        // Requires an '=' or ':' assignment so a plain phrase like "token failed" survives
        // (it is a useful reason), while "token=abcdef..." is redacted.
        '/(access[_-]?token|refresh[_-]?token|api[_-]?key|authorization|secret|bearer|token)["\']?\s*[:=]\s*["\']?[A-Za-z0-9._\-]{6,}/i' => '$1=<redacted>',
        // A bare long base64/hex blob (a key or JWT fragment).
        '/\b[A-Za-z0-9_\-]{40,}\b/' => '<redacted>',
        // Card-shaped digit runs (PAN). The gateway never returns one, but do not log it if it did.
        '/\b(?:\d[ -]?){13,19}\b/' => '<redacted>',
    ];
    foreach ($patterns as $re => $rep) {
        $text = preg_replace($re, $rep, $text) ?? $text;
    }
    return $text;
}

/** Collapse to one bounded, printable, redacted line. */
function gateway_diag_sanitize(string $text, int $max = 300): string {
    $text = gateway_diag_redact($text);
    $text = preg_replace('/[^\x20-\x7E\xA0-\xFF]+/u', ' ', $text) ?? $text;
    $text = preg_replace('/\s+/', ' ', $text) ?? $text;
    $text = trim($text);
    return mb_substr($text, 0, $max);
}

/**
 * A human explanation for a machine failure code, in both languages.
 *
 * The customer only ever sees the correlation id (a reason string would leak gateway
 * internals). The operator, however, should not have to memorise that `token_failed`
 * means "the gateway rejected the API key or the environment", so the admin lookup
 * renders this beside the stored row.
 *
 * @return array{ar:string, en:string}
 */
function gateway_diag_reason(string $code): array {
    $map = [
        'no_enabled_gateway'   => ['ar' => 'لا توجد بوابة مفعّلة، أو ينقص رقم المنفذ/مفتاح الحساب.',
                                   'en' => 'No gateway enabled, or the outlet ref / API key is missing.'],
        'gateway_disabled'     => ['ar' => 'البوابة معطّلة في الإعدادات.',
                                   'en' => 'The gateway is switched off in settings.'],
        'unsupported_currency' => ['ar' => 'عملة الطلب/الفاتورة لا تطابق عملة تسوية البوابة (Outlet).',
                                   'en' => 'The order/invoice currency does not match the outlet settlement currency.'],
        'zero_amount'          => ['ar' => 'مبلغ الطلب غير موجب — لا يمكن إنشاء طلب دفع بمبلغ صفر.',
                                   'en' => 'The order amount is not positive — a zero-amount order cannot be created.'],
        'migration_required'   => ['ar' => 'ترحيل قاعدة البيانات المطلوب غير مطبَّق.',
                                   'en' => 'A required database migration has not been applied.'],
        'token_failed'         => ['ar' => 'رفضت البوابة طلب رمز الدخول — تحقق من صحة مفتاح الحساب ومن البيئة (تجريبية/حقيقية).',
                                   'en' => 'The gateway rejected the access-token request — check the API key and the environment (sandbox/live).'],
        'order_failed'         => ['ar' => 'قبلت البوابة المصادقة لكنها رفضت إنشاء الطلب — راجع رقم المنفذ والعملة والمبلغ.',
                                   'en' => 'Authentication succeeded but the gateway rejected the order — check the outlet ref, currency and amount.'],
        'badTokenRequest'      => ['ar' => 'رفضت البوابة مفتاح الحساب (Service Account API Key) — تحقق من صحته ومن أنه من نفس البيئة.',
                                   'en' => 'The gateway rejected the Service Account API Key — check the key and that it belongs to this environment.'],
        'realmNameNotAvailable' => ['ar' => 'رفضت البوابة قيمة realmName — لا تُرسل realmName، فهي تُستخرج من مفتاح الحساب.',
                                   'en' => 'The gateway rejected the realmName — do not send it; it is derived from the API key.'],
        'outletNotAvailable'   => ['ar' => 'رفضت البوابة رقم المنفذ (Outlet Reference) — تحقق من أنه صحيح ومن نفس البيئة.',
                                   'en' => 'The gateway rejected the outlet reference — check it is correct and belongs to this environment.'],
        // The three codes below are the ones a live outage actually produces. Without an
        // entry here they fell through to "unclassified", which is the opaque message this
        // whole layer exists to replace: an operator saw a correlation id and no cause.
        'gateway_rejected'     => ['ar' => 'رفضت البوابة الطلب (4xx) — بيانات الاعتماد أو رقم المنفذ أو العملة أو المبلغ غير مطابق للبيئة.',
                                   'en' => 'The gateway rejected the request (4xx) — credentials, outlet reference, currency or amount do not match this environment.'],
        'connection_failed'    => ['ar' => 'تعذّر الوصول إلى بوابة الدفع (شبكة أو مهلة أو خطأ 5xx من البوابة) — غالباً عطل مؤقت، ويمكن إعادة المحاولة.',
                                   'en' => 'The payment gateway could not be reached (network, timeout, or a 5xx from the gateway) — usually transient, so a retry may succeed.'],
        'timeout'              => ['ar' => 'انتهت مهلة الاتصال ببوابة الدفع قبل الرد — أعد المحاولة، وتحقق من اتصال الخادم بالإنترنت.',
                                   'en' => 'The connection to the payment gateway timed out before a reply — retry, and check the server can reach the internet.'],
        // The credential exists but cannot be read, because MAWATHEQ_ENCRYPTION_KEY is not the
        // key that encrypted it. Without this entry the operator saw the same "token failed"
        // sentence as a genuinely wrong API key and had no reason to look at the key at all.
        'secret_unreadable'    => ['ar' => 'مفتاح الحساب مخزَّن لكن تعذّر فك تشفيره — مفتاح MAWATHEQ_ENCRYPTION_KEY الحالي يختلف عن المفتاح الذي شُفّر به. أعد إدخال مفتاح الحساب في لوحة التحكم.',
                                   'en' => 'The API key is stored but cannot be decrypted — MAWATHEQ_ENCRYPTION_KEY differs from the key that encrypted it. Re-enter the API key in the console.'],
    ];
    return $map[$code] ?? ['ar' => 'سبب غير مصنّف (' . $code . ').', 'en' => 'Unclassified reason (' . $code . ').'];
}

/**
 * Record one diagnostics event. Never throws: a logging failure must not fail a payment.
 *
 * When the diagnostics table is absent (upgrade_v72.sql not applied) the event is still
 * written to the server error log, because the customer is handed the correlation id
 * regardless. Without this fallback the reference a customer quotes is a dead end: it is
 * shown on screen but exists nowhere an operator can look it up.
 *
 * @param array $event keys: correlation_id, gateway, environment, operation, stage,
 *                      http_status, ok, error_code, detail, order_ref
 */
function gateway_diag_record(PDO $pdo, array $event): void {
    if (!gateway_diag_schema_ready($pdo)) {
        // The reference is still returned to the customer, so it must be findable somewhere.
        error_log('gateway_diag[' . (string)($event['correlation_id'] ?? '?') . ']'
            . ' op=' . (string)($event['operation'] ?? '')
            . ' stage=' . (string)($event['stage'] ?? '')
            . ' ok=' . (($event['ok'] ?? null) === null ? '?' : (($event['ok'] ?? false) ? '1' : '0'))
            . ' code=' . (string)($event['error_code'] ?? '')
            . ' env=' . (string)($event['environment'] ?? '')
            . ' detail=' . gateway_diag_sanitize((string)($event['detail'] ?? ''))
            . ' (gateway_diagnostics table missing — apply upgrade_v72.sql)');
        return;
    }
    $event += [
        'correlation_id' => gateway_correlation_id(),
        'gateway'        => 'ngenius',
        'environment'    => null,
        'operation'      => null,
        'stage'          => null,
        'http_status'    => null,
        'ok'             => null,
        'error_code'     => null,
        'detail'         => null,
        'order_ref'      => null,
    ];
    try {
        $pdo->prepare('INSERT INTO gateway_diagnostics
            (correlation_id, gateway, environment, operation, stage, http_status, ok, error_code, detail, order_ref)
            VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                (string)$event['correlation_id'],
                (string)$event['gateway'],
                $event['environment'] !== null ? (string)$event['environment'] : null,
                $event['operation'] !== null ? (string)$event['operation'] : null,
                $event['stage'] !== null ? (string)$event['stage'] : null,
                $event['http_status'] !== null ? (int)$event['http_status'] : null,
                $event['ok'] === null ? null : ($event['ok'] ? 1 : 0),
                $event['error_code'] !== null ? gateway_diag_sanitize((string)$event['error_code'], 60) : null,
                $event['detail'] !== null ? gateway_diag_sanitize((string)$event['detail']) : null,
                $event['order_ref'] !== null ? gateway_diag_sanitize((string)$event['order_ref'], 100) : null,
            ]);
    } catch (Throwable $e) {
        error_log('gateway diagnostics write failed: ' . $e->getMessage());
    }
}

/**
 * Normalise whatever an adapter put in its `error` field into a short, stable code
 * the diagnostic layer can classify.
 *
 * Adapters are inconsistent by design: the N-Genius token path returns `code`
 * (`badTokenRequest`), the order path returns either the gateway's own error code or a
 * full Arabic sentence with the HTTP status embedded, and the cURL path returns
 * `connection_failed: <curl message>`. The customer message must key on the *code*;
 * the sentence belongs in `detail`, which the admin console shows and the customer does not.
 */
function gateway_diag_reason_code(string $raw): string {
    $raw = trim($raw);
    if ($raw === '') return 'order_failed';

    // The synthetic `http_<status>` code carries no reason, only the status. Classify it by
    // the status before the bare-identifier rule below returns it verbatim: a 4xx is a
    // rejection the customer cannot retry away, and returning `http_400` unmapped made the
    // customer message fall through to the generic "try again" sentence — the exact opaque
    // message this layer exists to replace. 429/5xx are genuinely transient.
    if (preg_match('/^http_(\d{3})$/i', $raw, $m)) {
        $status = (int)$m[1];
        if ($status === 429 || ($status >= 500 && $status <= 599)) return 'connection_failed';
        if ($status >= 400 && $status <= 499) return 'gateway_rejected';
        return 'order_failed';
    }

    // A bare identifier is already a code.
    if (preg_match('/^[A-Za-z][A-Za-z0-9_]{2,60}$/', $raw)) {
        return $raw;
    }

    // `connection_failed: <curl error>` and anything else prefixed with a code.
    if (preg_match('/^([a-z][a-z0-9_]{2,60}):/i', $raw, $m)) {
        return strtolower($m[1]);
    }

    // N-Genius order failures embed the gateway's reason in brackets: "[outletNotAvailable]".
    if (preg_match('/\[([A-Za-z][A-Za-z0-9_]{2,60})\]/', $raw, $m)) {
        return $m[1];
    }

    // A transport failure is the only thing worth offering a retry for.
    if (preg_match('/HTTP\s*5\d\d|HTTP\s*429|تعذّر الاتصال|Connection|timeout|timed out/i', $raw)) {
        return 'connection_failed';
    }
    // Any other gateway rejection is a configuration/credential fault until proven otherwise;
    // calling it transient would trap the customer in a retry loop that cannot succeed.
    if (preg_match('/HTTP\s*4\d\d/', $raw)) {
        return 'gateway_rejected';
    }
    return 'order_failed';
}

/**
 * Record a failed stage and return the fragments a caller merges into its error array:
 * the machine reason plus the correlation id the customer can quote.
 *
 * @return array{error:string, correlation_id:string}
 */
function gateway_diag_failure(PDO $pdo, string $operation, string $stage, string $errorCode, string $detail = '', ?string $environment = null): array {
    $corr = gateway_correlation_id();
    gateway_diag_record($pdo, [
        'correlation_id' => $corr, 'operation' => $operation, 'stage' => $stage,
        'ok' => false, 'error_code' => $errorCode, 'detail' => $detail, 'environment' => $environment,
    ]);
    return ['error' => $errorCode, 'correlation_id' => $corr];
}

/**
 * Turn a structured failure code into the sentence the customer reads.
 *
 * The distinction that matters: a *permanent* fault (currency mismatch, gateway off,
 * missing migration, zero amount) is not fixed by pressing Pay again, so the message
 * must not invite a retry loop — it points at support instead. A *transient* fault
 * (token/order stage) may clear on its own, so a retry is offered.
 *
 * The correlation id is always appended when present, so every message is traceable.
 */
function gateway_diag_customer_message(string $errorCode, string $corr = '', bool $rtl = true): string {
    $permanent = [
        'unsupported_currency' => $rtl
            ? 'الدفع الإلكتروني غير متاح حالياً لهذه العملة. لم يتم خصم أي مبلغ. تواصل معنا لإتمام الاشتراك.'
            : 'Online payment is not available for this currency right now. No amount was charged. Contact us to complete your subscription.',
        'no_enabled_gateway' => $rtl
            ? 'الدفع الإلكتروني غير مفعّل حالياً. لم يتم خصم أي مبلغ. تواصل معنا لإتمام الاشتراك.'
            : 'Online payment is not enabled right now. No amount was charged. Contact us to complete your subscription.',
        'gateway_disabled' => $rtl
            ? 'الدفع الإلكتروني غير مفعّل حالياً. لم يتم خصم أي مبلغ. تواصل معنا لإتمام الاشتراك.'
            : 'Online payment is not enabled right now. No amount was charged. Contact us to complete your subscription.',
        'migration_required' => $rtl
            ? 'الدفع الإلكتروني غير متاح حالياً. لم يتم خصم أي مبلغ. تواصل معنا.'
            : 'Online payment is not available right now. No amount was charged. Contact us.',
        'zero_amount' => $rtl
            ? 'تعذّر بدء عملية الدفع: مبلغ الطلب غير صالح. لم يتم خصم أي مبلغ. تواصل معنا.'
            : 'Payment could not start: the order amount is not valid. No amount was charged. Contact us.',
        // The gateway rejected our credentials outright. Retrying from the customer's side
        // can never fix it, so this is permanent: it must not offer a retry loop.
        'badTokenRequest' => $rtl
            ? 'الدفع الإلكتروني غير متاح حالياً بسبب خلل في إعداد البوابة. لم يتم خصم أي مبلغ. تواصل معنا لإتمام الاشتراك.'
            : 'Online payment is unavailable right now due to a gateway configuration fault. No amount was charged. Contact us to complete your subscription.',
        'realmNameNotAvailable' => $rtl
            ? 'الدفع الإلكتروني غير متاح حالياً بسبب خلل في إعداد البوابة. لم يتم خصم أي مبلغ. تواصل معنا لإتمام الاشتراك.'
            : 'Online payment is unavailable right now due to a gateway configuration fault. No amount was charged. Contact us to complete your subscription.',
        // The outlet reference is a fixed configuration value; a rejection of it is permanent
        // until an operator corrects it, so it must not offer the customer a retry.
        'outletNotAvailable' => $rtl
            ? 'الدفع الإلكتروني غير متاح حالياً بسبب خلل في إعداد البوابة. لم يتم خصم أي مبلغ. تواصل معنا لإتمام الاشتراك.'
            : 'Online payment is unavailable right now due to a gateway configuration fault. No amount was charged. Contact us to complete your subscription.',
        // A stored-but-unreadable credential is an operator fault, not a customer one, so it
        // must not invite a retry that can never succeed.
        'secret_unreadable' => $rtl
            ? 'الدفع الإلكتروني غير متاح حالياً بسبب خلل في إعداد البوابة. لم يتم خصم أي مبلغ. تواصل معنا لإتمام الاشتراك.'
            : 'Online payment is unavailable right now due to a gateway configuration fault. No amount was charged. Contact us to complete your subscription.',
        // The gateway answered but refused the order. It is not a transport fault, so a
        // retry from the customer's side will not help — say the payment is unavailable
        // rather than inviting a loop.
        'gateway_rejected' => $rtl
            ? 'تعذّر إتمام الدفع الإلكتروني حالياً. لم يتم خصم أي مبلغ. تواصل معنا وسنعالج الأمر.'
            : 'The online payment could not be completed right now. No amount was charged. Contact us and we will resolve it.',
        // The diagnostics probe's own "gateway is off" code; same meaning as gateway_disabled.
        'not_enabled' => $rtl
            ? 'الدفع الإلكتروني غير مفعّل حالياً. لم يتم خصم أي مبلغ. تواصل معنا لإتمام الاشتراك.'
            : 'Online payment is not enabled right now. No amount was charged. Contact us to complete your subscription.',
        // A real transport failure: this one is safe to retry.
        'connection_failed' => $rtl
            ? 'تعذّر الوصول إلى بوابة الدفع. لم يتم خصم أي مبلغ. تحقّق من اتصالك ثم يمكنك المحاولة مجدداً أو التواصل معنا.'
            : 'The payment gateway could not be reached. No amount was charged. Check your connection, then you can retry or contact us.',
        'timeout' => $rtl
            ? 'تعذّر الوصول إلى بوابة الدفع. لم يتم خصم أي مبلغ. تحقّق من اتصالك ثم يمكنك المحاولة مجدداً أو التواصل معنا.'
            : 'The payment gateway could not be reached. No amount was charged. Check your connection, then you can retry or contact us.',
        // `token_failed` and `order_failed` are deliberately left on the retry sentence.
        // Both are catch-alls for a failure the classifier could not name
        // (gateway_diag_reason_code('') -> order_failed; an unexplained token stage ->
        // token_failed), so a customer whose fault is genuinely transient must not be told
        // to stop retrying. The *named* codes above carry the permanent messages.
    ];
    $transient = $rtl
        ? 'تعذّر بدء عملية الدفع الإلكتروني حالياً. لم يتم خصم أي مبلغ. يمكنك المحاولة مجدداً أو التواصل معنا.'
        : 'Online payment could not be started right now. No amount was charged. You can retry or contact us.';

    $msg = $permanent[$errorCode] ?? $transient;
    if ($corr !== '') {
        $msg .= ' ' . ($rtl ? 'الرقم المرجعي:' : 'Reference:') . ' ' . $corr;
    }
    return $msg;
}

/** Recent diagnostics rows for the admin console, newest first. */
function gateway_diag_recent(PDO $pdo, int $limit = 50): array {
    if (!gateway_diag_schema_ready($pdo)) return [];
    $limit = max(1, min(200, $limit));
    try {
        return $pdo->query("SELECT * FROM gateway_diagnostics ORDER BY id DESC LIMIT $limit")->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Every diagnostics row recorded for one correlation id, newest first.
 *
 * The customer is shown the reference and told to quote it, so the operator must be able
 * to enter that exact value and see the attempt. Without this the reference the customer
 * reads out is not actionable — the console only lists the last N rows, which a busy
 * deployment scrolls past within minutes. Returns [] for an unknown or malformed id.
 */
function gateway_diag_find(PDO $pdo, string $correlationId): array {
    $correlationId = strtoupper(trim($correlationId));
    if (!gateway_diag_is_correlation_id($correlationId)) return [];
    if (!gateway_diag_schema_ready($pdo)) return [];
    try {
        $stmt = $pdo->prepare('SELECT * FROM gateway_diagnostics WHERE correlation_id = ? ORDER BY id DESC');
        $stmt->execute([$correlationId]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** A correlation id that looks like the ones this module issues. */
function gateway_diag_is_correlation_id(string $value): bool {
    return (bool)preg_match('/^[A-F0-9]{8}$/', strtoupper(trim($value)));
}

/**
 * Run the N-Genius payment path stage by stage and report exactly where it stops.
 *
 * Defaults to a dry run: the token is fetched (a real, harmless call) but no order is
 * created. Pass `dry_run => false` to also create a real unpaid order — this NEVER
 * charges a card; an order only becomes a payment when a human pays it on the hosted
 * page. The probe is safe to run on production.
 *
 * @return array{ok:bool, correlation_id:string, environment:string, base_url:string,
 *               dry_run:bool, verdict:string, stages:array<int,array>, order_reference:?string}
 */
function gateway_diag_probe(PDO $pdo, array $options = []): array {
    $correlationId = (string)($options['correlation_id'] ?? gateway_correlation_id());
    $env = ($options['environment'] ?? '') === 'production' ? 'production' : 'sandbox';
    $dryRun = array_key_exists('dry_run', $options) ? (bool)$options['dry_run'] : true;

    $settings = ngenius_settings($pdo);
    $configuredEnv = ($settings['ngenius_environment'] ?? 'sandbox') === 'production' ? 'production' : 'sandbox';
    $outlet = (string)($settings['ngenius_outlet_ref'] ?? '');
    $hasKey = !empty($settings['ngenius_api_key']);
    $currency = ngenius_currency($settings);
    $baseUrl = ngenius_base_url($configuredEnv);

    $stages = [];
    $record = function (string $stage, bool $ok, ?int $http, ?string $code, ?string $detail)
        use (&$stages, $pdo, $correlationId, $configuredEnv) {
        $stages[] = ['stage' => $stage, 'ok' => $ok, 'http' => $http, 'code' => $code, 'detail' => $detail];
        gateway_diag_record($pdo, [
            'correlation_id' => $correlationId, 'gateway' => 'ngenius', 'environment' => $configuredEnv,
            'operation' => 'probe', 'stage' => $stage, 'http_status' => $http,
            'ok' => $ok, 'error_code' => $code, 'detail' => $detail,
        ]);
    };
    $result = static function (bool $ok, string $verdict, ?string $orderRef = null)
        use ($correlationId, $configuredEnv, $baseUrl, $dryRun, &$stages) {
        return ['ok' => $ok, 'correlation_id' => $correlationId, 'environment' => $configuredEnv,
            'base_url' => $baseUrl, 'dry_run' => $dryRun, 'verdict' => $verdict,
            'stages' => $stages, 'order_reference' => $orderRef];
    };

    // A secret that is present but unreadable is a different fault from one that was never
    // entered, and only the first is fixable by re-entering the key in the console. Naming
    // the status here is what turns an opaque token failure into an actionable cause.
    $keyStatus = (string)($settings['_secret_status']['ngenius_api_key'] ?? 'empty');
    $keyUnreadable = in_array($keyStatus, ['no_key', 'wrong_key', 'corrupt', 'no_openssl'], true);

    if (!ngenius_is_enabled($settings)) {
        if ($keyUnreadable && (string)($settings['ngenius_outlet_ref'] ?? '') !== ''
            && ($settings['ngenius_enabled'] ?? '0') === '1') {
            $record('config', false, null, 'secret_unreadable',
                'the API key is stored but cannot be decrypted (' . $keyStatus . '). '
                . 'MAWATHEQ_ENCRYPTION_KEY differs from the key used to save it — '
                . 're-enter the API key in the console to re-encrypt it under the current key.');
            return $result(false, 'config');
        }
        $record('config', false, null, 'not_enabled',
            'N-Genius is switched off, or the outlet reference / API key is missing.'
            . ($keyUnreadable ? ' (stored API key is unreadable: ' . $keyStatus . ')' : ''));
        return $result(false, 'config');
    }
    $record('config', true, null, null,
        'enabled; environment=' . $configuredEnv . '; currency=' . $currency
        . '; outlet_set=' . ($outlet !== '' ? 'yes' : 'no') . '; key_set=' . ($hasKey ? 'yes' : 'no')
        . '; key_status=' . $keyStatus);

    // A real package order is denominated in the SITE currency and package_payment_start()
    // refuses it when that differs from the outlet currency. This probe charges in the
    // outlet currency directly, so without an explicit comparison it reports "ok" while
    // every actual package purchase fails with unsupported_currency — a check that cannot
    // fail. Compare the two here so the operator sees the mismatch before a customer does.
    $siteCurrency = strtoupper(currency_code());
    if (!ngenius_supports_currency($settings, $siteCurrency)) {
        $record('currency', false, null, 'unsupported_currency',
            'site currency ' . $siteCurrency . ' != outlet settlement currency ' . $currency
            . ' — package and consultation payments will be refused');
        return $result(false, 'currency');
    }
    $record('currency', true, null, null, 'site currency ' . $siteCurrency . ' matches outlet ' . $currency);

    $token = ngenius_get_access_token($configuredEnv, (string)$settings['ngenius_api_key'], (string)($settings['ngenius_realm_name'] ?? ''));
    if (empty($token['ok'])) {
        // Keep the reason the gateway named (badTokenRequest, realmNameNotAvailable,
        // http_401, connection_failed, ...). Flattening every token failure to
        // `token_failed` threw away exactly the distinction that tells a rejected
        // credential from a transport fault, and is why the probe could not answer
        // "why did the payment fail".
        $code = (string)($token['code'] ?? '');
        $record('token', false, null,
            $code !== '' ? gateway_diag_reason_code($code) : 'token_failed',
            (string)($token['error'] ?? 'no access token') . ($code !== '' ? ' [code=' . $code . ']' : ''));
        return $result(false, 'token');
    }
    $record('token', true, 200, null, 'access token issued');

    if ($dryRun) {
        $record('order', true, null, 'skipped_dry_run', 'dry run: no order created');
        return $result(true, 'ok');
    }

    $amount = (float)($options['amount'] ?? 1.00);
    if ($amount <= 0) $amount = 1.00;
    $reference = 'PROBE-' . $correlationId;
    $order = ngenius_create_order(
        $configuredEnv, $outlet, (string)$token['token'], $amount,
        (string)($options['email'] ?? 'probe@mawatheq.com'),
        (string)($options['name'] ?? 'Diagnostic Probe'),
        (string)($options['return_url'] ?? rtrim(SITE_URL, '/') . '/packages.php'),
        rtrim(SITE_URL, '/') . '/packages.php',
        $reference, 'en', $currency, 'SA'
    );
    if (empty($order['ok'])) {
        $code = (string)($order['code'] ?? '');
        $record('order', false, null,
            $code !== '' ? gateway_diag_reason_code($code) : 'order_failed',
            (string)($order['error'] ?? 'order rejected') . ($code !== '' ? ' [code=' . $code . ']' : ''));
        return $result(false, 'order');
    }
    $record('order', true, null, null,
        'order created: ' . $order['order_reference'] . ' (created but NOT paid — no charge)');
    return $result(true, 'ok', (string)$order['order_reference']);
}
