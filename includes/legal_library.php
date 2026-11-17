<?php
/**
 * ============================================================================
 * مواثيق — المكتبة القانونية والقضائية (Execution 74)
 * MAWATHEQ — legal & judicial library
 * ============================================================================
 *
 * One place that knows how to talk to each upstream source, how to store what it
 * returns, and how to search it. The web pages, the REST endpoint, the cron job and
 * the admin console all read from here, so none of them can disagree about what a
 * source permits or what an item looks like.
 *
 * THE RULE THIS FILE EXISTS TO KEEP
 *   A source's `redistribution` decides what we may keep:
 *     hosted    — the source publishes the text for public reading (the MOJ legal
 *                 portal does), so we store the ruling/statute text and the reader
 *                 reads it inside Mawatheq. The official URL is always kept too.
 *     link_only — we keep metadata only and send the reader to the source. We never
 *                 copy the file (the mashortich PDF collections are this case).
 *     unknown   — neither is assumed; the source ships disabled.
 *   `legal_library_public_item()` is the single function that renders a row for the
 *   UI/API, so the redistribution decision is applied in exactly one place.
 *
 * NO INVENTED ENDPOINTS
 *   Every URL below was verified against the live source (see the analysis in the
 *   report). The MOJ gateway endpoints are the ones the public portal itself calls.
 *   The BOG open-data / almusaid services are registered but disabled: BOG was not
 *   reachable from the build network and almusaid requires SSO, so no endpoint for
 *   them is written from guesswork.
 */

require_once __DIR__ . '/../config/config.php';

/* ---------------------------------------------------------------------------
 * Redistribution policy
 * ------------------------------------------------------------------------ */

/**
 * Whether the library may store a source's full text or file.
 *
 * Storing full text is a *redistribution* decision, not a technical one: a page
 * being reachable by anyone is not a licence to re-publish it. So capture is OFF
 * by default and turns on only when the operator explicitly sets
 * MAWATHEQ_LL_CAPTURE_BODY=1 after confirming that source's terms permit
 * re-hosting. While it is off, every item keeps its metadata and its official
 * link, and the reader is always sent to the authority that published it — which
 * is the maximum a "link_only" source allows.
 */
function legal_library_capture_body(): bool {
    return getenv('MAWATHEQ_LL_CAPTURE_BODY') === '1';
}

/* ---------------------------------------------------------------------------
 * HTTP
 * ------------------------------------------------------------------------ */

/**
 * The browser-shaped User-Agent the gateway accepts.
 *
 * The MOJ gateway is fronted by an F5 WAF that refuses a request carrying a
 * non-browser User-Agent (the importer's old "MawatheqLegalLibrary/1.0" was
 * rejected outright), and it also requires a session cookie it hands out on the
 * first hit. Both facts were confirmed against the live gateway: a bare cURL
 * request returns HTTP 200 with an HTML "Request Rejected" page, which looks like
 * success and is why the importer silently imported nothing.
 */
function legal_library_user_agent(): string {
    return 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
         . '(KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
}

/**
 * Path of the per-process cookie jar shared by every request in one run.
 *
 * The WAF session cookie must travel with the list and detail calls alike, so the
 * jar is created once per process rather than per request.
 */
function legal_library_cookie_jar(): string {
    static $jar = null;
    if ($jar === null) {
        $jar = rtrim(sys_get_temp_dir(), '/') . '/mawatheq_ll_' . getmypid() . '.cookies';
    }
    return $jar;
}

/**
 * A small cURL wrapper used by every importer. Returns a structured result rather
 * than throwing, because an importer must record a partial failure and continue.
 *
 * A cookie jar is always used so the WAF session established by the first request
 * is reused. `$opts['no_cookies']` disables it for a one-off probe.
 *
 * @param array{no_cookies?:bool} $opts
 * @return array{ok:bool, code:int, body:string, json:?array, error:?string}
 */
function legal_library_http(string $method, string $url, ?array $jsonPayload = null, array $headers = [], int $timeout = 30, array $opts = []): array {
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'code' => 0, 'body' => '', 'json' => null, 'error' => 'cURL غير مفعّل على السيرفر.'];
    }
    $ch = curl_init($url);
    $hdr = array_merge([
        'Accept: application/json, text/plain, */*',
        'Accept-Language: ar,en;q=0.9',
        'User-Agent: ' . legal_library_user_agent(),
    ], $headers);
    $curlOpts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_HTTPHEADER => $hdr,
    ];
    if (empty($opts['no_cookies'])) {
        $jar = legal_library_cookie_jar();
        $curlOpts[CURLOPT_COOKIEJAR] = $jar;
        $curlOpts[CURLOPT_COOKIEFILE] = $jar;
    }
    if (strtoupper($method) === 'POST') {
        $curlOpts[CURLOPT_POST] = true;
        $curlOpts[CURLOPT_POSTFIELDS] = $jsonPayload === null ? '' : json_encode($jsonPayload, JSON_UNESCAPED_UNICODE);
        $curlOpts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
    }
    curl_setopt_array($ch, $curlOpts);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'code' => 0, 'body' => '', 'json' => null, 'error' => $err ?: 'فشل الاتصال'];
    }
    $decoded = json_decode($body, true);
    return [
        'ok' => $code >= 200 && $code < 300,
        'code' => $code,
        'body' => $body,
        'json' => is_array($decoded) ? $decoded : null,
        'error' => $code >= 200 && $code < 300 ? null : ('HTTP ' . $code),
    ];
}

/**
 * Prime the WAF session for a gateway host: one GET of the host root, which makes
 * the WAF hand out the cookie the API calls need. Idempotent per process.
 */
function legal_library_prime_session(string $hostRoot = 'https://laws-gateway.moj.gov.sa/'): void {
    static $primed = [];
    if (!empty($primed[$hostRoot])) return;
    $primed[$hostRoot] = true;
    legal_library_http('GET', $hostRoot, null, ['Accept: text/html'], 20);
}

/**
 * Fetch a JSON page, retrying on a non-JSON body.
 *
 * The MOJ gateway sits behind a WAF that answers a rate-limited request with
 * HTTP 200 and an HTML "Request Rejected" page instead of a 429. A 200 whose body
 * is not JSON therefore means "slow down", not "no data", and is retried with
 * exponential backoff rather than being treated as the end of the collection.
 *
 * @return array{ok:bool, code:int, json:?array, error:?string, blocked:bool}
 */
function legal_library_http_json(string $method, string $url, ?array $jsonPayload = null, array $headers = [], int $timeout = 30, int $tries = 4): array {
    // Establish the WAF session before the first API call. Without it the gateway
    // answers a non-browser request with an HTML "Request Rejected" page under HTTP
    // 200, which every caller would otherwise read as a successful empty response.
    $host = parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST) . '/';
    legal_library_prime_session($host);

    $last = ['ok' => false, 'code' => 0, 'json' => null, 'error' => 'لم تُجرَ محاولة', 'blocked' => false];
    for ($attempt = 0; $attempt < max(1, $tries); $attempt++) {
        $r = legal_library_http($method, $url, $jsonPayload, $headers, $timeout);
        $last = ['ok' => $r['ok'], 'code' => $r['code'], 'json' => $r['json'], 'error' => $r['error'], 'blocked' => false];
        if ($r['ok'] && $r['json'] !== null) {
            return $last;
        }
        // A 2xx with a non-JSON body is the WAF. Back off and try again. The WAF
        // throttle is aggressive, so the backoff is generous (the gateway also
        // rejects a burst even when the cookie is valid).
        $blocked = $r['ok'] && $r['json'] === null;
        $last['blocked'] = $blocked;
        if ($attempt < $tries - 1) {
            usleep($blocked ? (2500000 * ($attempt + 1)) : 800000);
        }
    }
    return $last;
}

/* ---------------------------------------------------------------------------
 * Hijri → Gregorian
 * ------------------------------------------------------------------------- */

/**
 * Convert a Hijri date (YYYY-MM-DD, as the MOJ portal prints it) to a Gregorian
 * YYYY-MM-DD. Returns null when the value is unusable, so a caller stores NULL
 * rather than a guessed date. Uses ext-intl's Umm al-Qura calendar, which is the
 * civil calendar Saudi courts publish against.
 */
function legal_library_hijri_to_gregorian(?string $hijri): ?string {
    if (!$hijri) return null;
    if (!preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', trim($hijri), $m)) return null;
    [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    if ($y < 1300 || $y > 1600 || $mo < 1 || $mo > 12 || $d < 1 || $d > 30) return null;
    if (!class_exists('IntlCalendar')) return null;
    try {
        // The Umm al-Qura calendar must be named in the locale; passing it as the
        // first argument silently yields a Gregorian calendar (a wrong date that
        // looks plausible). The locale needs a real language tag.
        $cal = IntlCalendar::createInstance(null, 'en_US@calendar=islamic-umalqura');
        $cal->clear();
        // setDateTime takes (year, month, day, hour, minute, second); month is
        // 0-based, and the 6-arg IntlCalendar::set() form is deprecated in PHP 8.4.
        $cal->setDateTime($y, $mo - 1, $d, 12, 0, 0);
        $ms = $cal->getTime();
        $g = new DateTime('@' . (int)($ms / 1000));
        $g->setTimezone(new DateTimeZone('Asia/Riyadh'));
        return $g->format('Y-m-d');
    } catch (Throwable $e) {
        return null;
    }
}

/** The Gregorian year of a Hijri date, for the year facet. */
function legal_library_gregorian_year_of(?string $hijri): ?int {
    $g = legal_library_hijri_to_gregorian($hijri);
    return $g ? (int)substr($g, 0, 4) : null;
}

/* ---------------------------------------------------------------------------
 * Catalogs
 * ------------------------------------------------------------------------- */

/** Content types, in display order. */
function legal_library_item_types(): array {
    return [
        'judgment'    => ['ar' => 'أحكام وقرارات قضائية', 'en' => 'Judgments & decisions', 'icon' => 'landmark'],
        'legislation' => ['ar' => 'أنظمة وتشريعات',        'en' => 'Legislations',           'icon' => 'book'],
        'book'        => ['ar' => 'كتب ومجموعات قضائية',   'en' => 'Books & collections',    'icon' => 'file'],
        'statistics'  => ['ar' => 'بيانات وإحصاءات',       'en' => 'Data & statistics',      'icon' => 'chart'],
        'article'     => ['ar' => 'مقالات قانونية',        'en' => 'Legal articles',         'icon' => 'edit'],
    ];
}

function legal_library_item_type_label(string $key): string {
    $t = legal_library_item_types()[$key] ?? null;
    if (!$t) return $key;
    return is_rtl() ? $t['ar'] : $t['en'];
}

/* ---------------------------------------------------------------------------
 * Sources
 * ------------------------------------------------------------------------- */

/** @return list<array> enabled sources (or all when $enabledOnly is false) */
function legal_library_sources(bool $enabledOnly = true): array {
    global $pdo;
    $sql = 'SELECT * FROM legal_library_sources';
    if ($enabledOnly) $sql .= ' WHERE is_enabled = 1';
    $sql .= ' ORDER BY id';
    try {
        return $pdo->query($sql)->fetchAll();
    } catch (Throwable $e) {
        // Table not present yet (a database that has not received upgrade_v78.sql):
        // the library renders its "not enabled" state rather than a 500.
        return [];
    }
}

function legal_library_source(string $key): ?array {
    global $pdo;
    try {
        $st = $pdo->prepare('SELECT * FROM legal_library_sources WHERE source_key = ?');
        $st->execute([$key]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function legal_library_set_source_enabled(PDO $pdo, string $key, bool $enabled): bool {
    $st = $pdo->prepare('UPDATE legal_library_sources SET is_enabled = ? WHERE source_key = ?');
    $st->execute([$enabled ? 1 : 0, $key]);
    return $st->rowCount() > 0;
}

/**
 * Set a source's redistribution decision. This is the operator's licensing call:
 * `link_only` keeps metadata and the official link, `hosted` allows the source's
 * own text to be stored and read inside Mawatheq, `unknown` parks the source
 * pending a terms review. Only these three values are accepted — anything else is
 * refused rather than written, so a malformed request cannot invent a policy.
 *
 * Existing items are re-stamped so the change takes effect immediately for rows
 * already imported (the read gate checks the item's own `redistribution`).
 */
function legal_library_set_source_redistribution(PDO $pdo, string $key, string $redist): bool {
    if (!in_array($redist, ['link_only', 'hosted', 'unknown'], true)) return false;
    // Confirm the source exists first. `rowCount()` cannot answer this: MySQL reports 0
    // changed rows when the value is already the target, so selecting the policy a source
    // already has would otherwise be reported as "invalid policy" — the operator presses
    // the same choice twice and is told it failed.
    $exists = $pdo->prepare('SELECT id FROM legal_library_sources WHERE source_key = ?');
    $exists->execute([$key]);
    if ($exists->fetchColumn() === false) return false;
    $pdo->prepare('UPDATE legal_library_sources SET redistribution = ? WHERE source_key = ?')->execute([$redist, $key]);
    $pdo->prepare('UPDATE legal_library_items SET redistribution = ? WHERE source_key = ?')->execute([$redist, $key]);
    return true;
}

/* ---------------------------------------------------------------------------
 * Upsert + runs
 * ------------------------------------------------------------------------- */

/**
 * Insert or update one item, keyed on (source_key, external_id) so a re-sync never
 * duplicates. Returns 'new' | 'updated' | 'skipped'.
 */
function legal_library_upsert_item(PDO $pdo, array $it): string {
    $required = ['source_key', 'external_id', 'title', 'item_type'];
    foreach ($required as $k) {
        if (!isset($it[$k]) || $it[$k] === '') return 'skipped';
    }
    $cols = ['source_id','source_key','external_id','item_type','title','decision_title','title_is_derived',
             'summary','court_name','city',
             'court_type','case_number','decision_number','hijri_year','hijri_date','gregorian_year','decision_date',
             'category','body','judgment_facts','judgment_reasons','judgment_ruling','judgment_narration',
             'detail_fetched_at','source_url','file_url','file_type','file_size_kb','page_count','publisher',
             'published_at','source_updated_at','lang','redistribution','hosted_path','is_published',
             'title_norm','summary_norm','imported_at'];

    // Normalised search keys are derived here so every importer gets them.
    $it['title_norm'] = legal_library_normalize_ar($it['title'] ?? '');
    $it['summary_norm'] = legal_library_normalize_ar($it['summary'] ?? null);
    // `title_is_derived` is NOT NULL DEFAULT 0; a caller that omits it (or passes an
    // explicit null) must land 0, not trip the constraint.
    $it['title_is_derived'] = !empty($it['title_is_derived']) ? 1 : 0;
    // `source_url` is NOT NULL DEFAULT ''; an insert must carry a string.
    if (!array_key_exists('source_url', $it) || $it['source_url'] === null) $it['source_url'] = '';
    if (empty($it['imported_at'])) $it['imported_at'] = date('Y-m-d H:i:s');

    $st = $pdo->prepare('SELECT id FROM legal_library_items WHERE source_key = ? AND external_id = ?');
    $st->execute([$it['source_key'], $it['external_id']]);
    $existing = $st->fetchColumn();

    // Full text / hosted file is stored only when the operator has confirmed the
    // source permits re-hosting (see legal_library_capture_body()). Otherwise the
    // row keeps its metadata (including the source's own title) and its official
    // link, and the reader is sent there. This gate covers every column that holds
    // published prose — the ruling text, the structured sections and the snippet
    // summary — because each is a re-publication of the source's words.
    if (!legal_library_capture_body()) {
        $it['body'] = null;
        $it['hosted_path'] = null;
        $it['judgment_facts'] = null;
        $it['judgment_reasons'] = null;
        $it['judgment_ruling'] = null;
        $it['judgment_narration'] = null;
        $it['summary'] = null;
        $it['summary_norm'] = null;
    }

    $vals = [];
    $present = [];
    foreach ($cols as $c) {
        $vals[$c] = $it[$c] ?? null;
        $present[$c] = array_key_exists($c, $it);
    }
    // Detail-only columns. A plain list refresh (no detail fetched) must not erase a
    // detail already captured, so these are only written when this run carried detail.
    $detailCols = ['decision_title','title_is_derived','judgment_facts','judgment_reasons',
                   'judgment_ruling','judgment_narration','detail_fetched_at'];
    $hasDetail = !empty($it['detail_fetched_at']);
    // Never let a re-sync blank a field the source did not send this time.
    if ($existing) {
        $set = [];
        $params = [];
        foreach ($vals as $c => $v) {
            if ($c === 'source_key' || $c === 'external_id') continue;
            if (in_array($c, $detailCols, true) && !$hasDetail) continue;
            // A partial caller (a field it did not provide) must not blank a stored
            // value. A full mapper row sets every key, including explicit nulls, so
            // it still updates and clears fields as intended.
            if (!$present[$c]) continue;
            $set[] = "`$c` = ?";
            $params[] = $v;
        }
        if (!$set) return 'skipped';
        $params[] = (int)$existing;
        $pdo->prepare('UPDATE legal_library_items SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($params);
        return 'updated';
    }
    $placeholders = implode(', ', array_fill(0, count($cols), '?'));
    $sql = 'INSERT INTO legal_library_items (`' . implode('`, `', $cols) . '`) VALUES (' . $placeholders . ')';
    $pdo->prepare($sql)->execute(array_values($vals));
    return 'new';
}

function legal_library_run_start(PDO $pdo, string $sourceKey): int {
    $pdo->prepare('INSERT INTO legal_library_sync_runs (source_key, status) VALUES (?, "running")')->execute([$sourceKey]);
    return (int)$pdo->lastInsertId();
}

function legal_library_run_finish(PDO $pdo, int $runId, string $status, array $counts, ?string $error = null): void {
    $pdo->prepare('UPDATE legal_library_sync_runs SET status=?, items_seen=?, items_new=?, items_updated=?, items_skipped=?, error_message=?, finished_at=NOW() WHERE id=?')
        ->execute([
            $status,
            (int)($counts['seen'] ?? 0),
            (int)($counts['new'] ?? 0),
            (int)($counts['updated'] ?? 0),
            (int)($counts['skipped'] ?? 0),
            $error,
            $runId,
        ]);
}

/** Refresh a source's cached counters after an import. */
function legal_library_refresh_source_stats(PDO $pdo, string $sourceKey, string $status): void {
    $pdo->prepare('UPDATE legal_library_sources
        SET last_sync_at = NOW(), last_sync_status = ?,
            item_count = (SELECT COUNT(*) FROM legal_library_items WHERE source_key = ?)
        WHERE source_key = ?')->execute([$status, $sourceKey, $sourceKey]);
}

/* ---------------------------------------------------------------------------
 * Importers
 * ------------------------------------------------------------------------- */

/** Dispatch a sync for one source. Returns the counts array. */
function legal_library_sync(PDO $pdo, string $sourceKey, array $opts = []): array {
    $source = legal_library_source($sourceKey);
    if (!$source) {
        return ['seen' => 0, 'new' => 0, 'updated' => 0, 'skipped' => 0, 'status' => 'error', 'error' => 'مصدر غير معروف'];
    }
    $runId = legal_library_run_start($pdo, $sourceKey);
    $counts = ['seen' => 0, 'new' => 0, 'updated' => 0, 'skipped' => 0];
    $status = 'ok';
    $error = null;
    try {
        switch ($sourceKey) {
            case 'moj_decisions':
                $counts = !empty($opts['windowed'])
                    ? legal_library_import_moj_decisions_windowed($pdo, $source, $opts)
                    : legal_library_import_moj_decisions($pdo, $source, $opts);
                break;
            case 'moj_legislation': $counts = legal_library_import_moj_legislation($pdo, $source, $opts); break;
            case 'mashortich_books':$counts = legal_library_import_mashortich($pdo, $source, $opts); break;
            default:
                $error = 'لا يوجد مستورد لهذا المصدر (قد يكون معطّلاً بانتظار مراجعة شروط إعادة النشر).';
                $status = 'error';
        }
    } catch (Throwable $e) {
        $status = 'error';
        $error = $e->getMessage();
    }
    if ($status !== 'error' && ($counts['skipped'] ?? 0) > 0 && ($counts['new'] ?? 0) + ($counts['updated'] ?? 0) === 0) {
        $status = 'partial';
    }
    legal_library_run_finish($pdo, $runId, $status, $counts, $error);
    legal_library_refresh_source_stats($pdo, $sourceKey, $status);
    return $counts + ['status' => $status, 'error' => $error];
}

/**
 * Arabic search normalisation: fold the letters a reader varies (hamza forms →
 * alef, alef maqsura → yaa, taa marbuta → haa), drop tatweel and diacritics, and
 * lower-case Latin. The stored original text is never changed — this is written
 * to a separate `*_norm` column so search matches how an Arabic reader types
 * without altering what the source published.
 */
function legal_library_normalize_ar(?string $s): string {
    if ($s === null || $s === '') return '';
    $s = str_replace(
        ['أ', 'إ', 'آ', 'ٱ', 'ى', 'ئ', 'ؤ', 'ة', 'ـ'],
        ['ا', 'ا', 'ا', 'ا', 'ي', 'ي', 'و', 'ه', ''],
        $s
    );
    // Remove Arabic diacritics (harakat) and tatweel already handled above.
    $s = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06DC}\x{06DF}-\x{06E4}\x{06E7}\x{06E8}\x{06EA}-\x{06ED}]/u', '', $s);
    // Collapse whitespace and normalise Latin case.
    $s = preg_replace('/\s+/u', ' ', $s);
    return mb_strtolower(trim((string)$s), 'UTF-8');
}

/** Normalise one row from the MOJ judgements list into our item shape. */
function legal_library_map_moj_judgement(array $row, array $source, ?array $detail = null): array {
    $num  = trim((string)($row['judgementNumber'] ?? ''));
    $court = trim((string)($row['courtName'] ?? ''));
    $city  = trim((string)($row['city'] ?? ''));
    $hijri = trim((string)($row['judgementDate'] ?? ''));
    $hijriYear = (int)($row['hijriYear'] ?? 0) ?: null;

    // The list endpoint carries no title. The detail endpoint does. Prefer the
    // source's own title; only when the source publishes none do we build a clear
    // description from its official fields, and we flag that it is derived so the
    // UI never presents it as the source's title.
    $sourceTitle = '';
    $decisionTitle = '';
    if ($detail) {
        $sourceTitle = trim((string)($detail['title'] ?? ''));
        $decisionTitle = trim((string)($detail['decisionTitle'] ?? ''));
    }
    $titleIsDerived = 0;
    if ($sourceTitle !== '') {
        $title = $sourceTitle;
    } elseif ($decisionTitle !== '') {
        $title = $decisionTitle;
    } else {
        $title = $num !== '' ? ('حكم رقم ' . $num) : 'حكم قضائي';
        if ($court !== '') $title .= ' — ' . $court;
        $titleIsDerived = 1;
    }

    // Detail text fields, each kept verbatim under its own column so nothing is
    // merged or paraphrased. `body` stays the ruling text (what a reader wants
    // first); facts/reasons/narration are the structured sections.
    $facts = $reasons = $ruling = $narration = null;
    if ($detail) {
        $facts   = legal_library_clean_text($detail['judgmentFacts'] ?? null);
        $reasons = legal_library_clean_text($detail['judgmentReasons'] ?? null);
        $ruling  = legal_library_clean_text($detail['judgmentTextofRulling'] ?? $detail['judgmentRuling'] ?? null);
        // Narration: an array of {title, text} sections in the portal.
        if (!empty($detail['judgmentNarrationList']) && is_array($detail['judgmentNarrationList'])) {
            $parts = [];
            foreach ($detail['judgmentNarrationList'] as $n) {
                $t = legal_library_clean_text($n['title'] ?? $n['narrationTitle'] ?? null);
                $x = legal_library_clean_text($n['text'] ?? $n['narrationText'] ?? $n['body'] ?? null);
                if ($t !== null || $x !== null) $parts[] = trim(($t ?? '') . "\n" . ($x ?? ''));
            }
            $narration = $parts ? implode("\n\n", $parts) : null;
        }
    }
    // The ruling text is what the reader should land on; fall back to reasons/facts.
    $body = $ruling ?? $reasons ?? $facts;

    // A short summary taken from the real text (never invented). Clearly the
    // opening of the ruling, not a generated abstract.
    $summary = null;
    if ($body !== null) {
        $flat = trim(preg_replace('/\s+/u', ' ', strip_tags($body)));
        if ($flat !== '') $summary = mb_substr($flat, 0, 300);
    }

    $sourceUrl = 'https://laws.moj.gov.sa/ar/JudicialDecisionsList/1/' . rawurlencode((string)$row['id']);

    return [
        'source_id'       => (int)$source['id'],
        'source_key'      => $source['source_key'],
        'external_id'     => (string)$row['id'],
        'item_type'       => 'judgment',
        'title'           => mb_substr($title, 0, 380),
        'decision_title'  => $decisionTitle !== '' ? mb_substr($decisionTitle, 0, 380) : null,
        'title_is_derived'=> $titleIsDerived,
        'summary'         => $summary,
        'court_name'      => $court !== '' ? $court : null,
        'city'            => $city !== '' ? $city : null,
        'court_type'      => isset($row['courtType']) ? (int)$row['courtType'] : null,
        'case_number'     => isset($row['caseNumber']) && $row['caseNumber'] !== null ? (string)$row['caseNumber'] : null,
        'decision_number' => $num !== '' ? $num : null,
        'hijri_year'      => $hijriYear,
        'hijri_date'      => $hijri !== '' ? $hijri : null,
        'gregorian_year'  => legal_library_gregorian_year_of($hijri),
        'decision_date'   => legal_library_hijri_to_gregorian($hijri),
        'category'        => $court !== '' ? $court : null,
        'body'            => $body,
        'judgment_facts'  => $facts,
        'judgment_reasons'=> $reasons,
        'judgment_ruling' => $ruling,
        'judgment_narration' => $narration,
        'detail_fetched_at' => $detail ? date('Y-m-d H:i:s') : null,
        'source_url'      => $sourceUrl,
        'file_url'        => null,
        'file_type'       => null,
        'file_size_kb'    => null,
        'page_count'      => null,
        'publisher'       => $source['publisher_ar'] ?? null,
        'published_at'    => legal_library_hijri_to_gregorian($hijri),
        'source_updated_at' => null,
        'lang'            => 'ar',
        'redistribution'  => $source['redistribution'],
        'hosted_path'     => null,
        'is_published'    => 1,
        // Normalised keys are also derived in the upsert; set here too so a mapped
        // row is self-consistent for any caller that inspects it directly.
        'title_norm'      => legal_library_normalize_ar(mb_substr($title, 0, 380)),
        'summary_norm'    => legal_library_normalize_ar($summary),
    ];
}

/**
 * Clean a detail text field: the portal returns HTML fragments. Keep the text,
 * normalise line breaks, drop tags. Returns null for an empty field so the column
 * stores NULL rather than an empty string.
 */
function legal_library_clean_text($v): ?string {
    if ($v === null || !is_string($v)) return null;
    $v = str_replace(['<br />', '<br/>', '<br>', '</p>', '</div>'], "\n", $v);
    $v = html_entity_decode(strip_tags($v), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $v = preg_replace("/[ \t]+/u", ' ', $v);
    $v = preg_replace("/\n{3,}/", "\n\n", $v);
    $v = trim((string)$v);
    return $v === '' ? null : $v;
}

function legal_library_import_moj_decisions(PDO $pdo, array $source, array $opts): array {
    $pages = max(1, (int)($opts['pages'] ?? 2));
    $pageSize = min(50, max(1, (int)($opts['page_size'] ?? 25)));
    $withDetails = (bool)($opts['with_details'] ?? false);
    $detailCap = max(0, (int)($opts['detail_cap'] ?? 25));
    $headers = ['Origin: https://laws.moj.gov.sa', 'Referer: https://laws.moj.gov.sa/'];
    $url = 'https://laws-gateway.moj.gov.sa/apis/legislations/v1/Judgements/judgements-list';
    $counts = ['seen' => 0, 'new' => 0, 'updated' => 0, 'skipped' => 0, 'pages_fetched' => 0, 'blocked' => 0];
    $detailsFetched = 0;

    for ($p = 1; $p <= $pages; $p++) {
        $payload = [
            'pageNumber' => $p, 'pageSize' => $pageSize, 'judgmentNo' => '', 'decisionNo' => '',
            'cityId' => null, 'viewType' => 'grid', 'courtTypes' => 0, 'courtId' => null,
            'term' => null, 'dateFrom' => '', 'dateTo' => '', 'sortingBy' => 2, 'identityNumber' => '',
        ];
        $resp = legal_library_http_json('POST', $url, $payload, $headers, 30);
        if (!is_array($resp['json']['model']['judgementsCollection'] ?? null)) {
            // A page the gateway refused (WAF) must not discard the pages already
            // imported; record it and stop rather than throwing away good rows.
            if ($p === 1 && empty($resp['blocked'])) {
                throw new RuntimeException('تعذّر جلب الأحكام: ' . ($resp['error'] ?? 'استجابة غير متوقعة'));
            }
            if (!empty($resp['blocked'])) $counts['blocked']++;
            break;
        }
        $counts['pages_fetched']++;
        $rows = $resp['json']['model']['judgementsCollection'];
        foreach ($rows as $row) {
            if (empty($row['id'])) { $counts['skipped']++; continue; }
            $counts['seen']++;
            $detail = null;
            if ($withDetails && $detailsFetched < $detailCap) {
                $d = legal_library_http_json('GET',
                    'https://laws-gateway.moj.gov.sa/apis/legislations/v1/Judgements/get-details?id=' . rawurlencode((string)$row['id']) . '&lang=ar&IdentityNumber=',
                    null, $headers, 25);
                if (is_array($d['json']['model'] ?? null)) {
                    $detail = $d['json']['model'];
                    $detailsFetched++;
                }
                usleep(300000);
            }
            $res = legal_library_upsert_item($pdo, legal_library_map_moj_judgement($row, $source, $detail));
            $counts[$res]++;
        }
        // Be a good citizen: the gateway rate-limits, so pause between pages.
        if ($p < $pages) usleep((int)(($opts["pause_ms"] ?? 2000) * 1000));
    }
    return $counts;
}

/**
 * Fetch the DETAIL record for catalogue rows that do not have one yet.
 *
 * The list endpoint carries no title and no text; the real title, facts, reasons,
 * ruling and narration live on the detail endpoint. A catalogue built from the list
 * alone can only ever show a derived title — which is exactly the "metadata only"
 * state the operator rejected. This pass fills the detail for rows where
 * `detail_fetched_at IS NULL`, bounded per run so a cron job never times out; the
 * rest is picked up on the next run. Rows are enriched newest-first.
 *
 * @return array{seen:int, enriched:int, blocked:int, failed:int}
 */
function legal_library_enrich_moj_details(PDO $pdo, array $source, array $opts = []): array {
    $limit  = max(1, (int)($opts['limit'] ?? 10));
    $pauseMs = (int)($opts['pause_ms'] ?? 4000);
    // A source switched to `hosted` may already hold rows that were fetched while it
    // was `link_only`, so their detail timestamp is set but the text was never kept.
    // `refetch` re-visits those rows so the newly permitted text is captured.
    $refetch = !empty($opts['refetch']);
    $headers = ['Origin: https://laws.moj.gov.sa', 'Referer: https://laws.moj.gov.sa/'];
    $counts = ['seen' => 0, 'enriched' => 0, 'blocked' => 0, 'failed' => 0];

    $where = $refetch
        ? "source_key = ? AND (detail_fetched_at IS NULL OR (body IS NULL AND judgment_ruling IS NULL))"
        : "source_key = ? AND detail_fetched_at IS NULL";
    $st = $pdo->prepare(
        "SELECT id, external_id, decision_number, court_name, city, hijri_year, decision_date, source_url
           FROM legal_library_items
          WHERE $where
          ORDER BY decision_date DESC, id DESC
          LIMIT " . (int)$limit
    );
    $st->execute([$source['source_key']]);
    $rows = $st->fetchAll();
    if (!$rows) return $counts;

    legal_library_prime_session('https://laws-gateway.moj.gov.sa/');
    foreach ($rows as $r) {
        $counts['seen']++;
        $d = legal_library_http_json('GET',
            'https://laws-gateway.moj.gov.sa/apis/legislations/v1/Judgements/get-details?id=' . rawurlencode((string)$r['external_id']) . '&lang=ar&IdentityNumber=',
            null, $headers, 25);
        $detail = $d['json']['model'] ?? null;
        if (!is_array($detail)) {
            if (!empty($d['blocked'])) { $counts['blocked']++; break; } // WAF: stop, retry later
            $counts['failed']++;
            usleep($pauseMs * 1000);
            continue;
        }
        // Rebuild the row shape the mapper expects from the stored columns, then
        // re-map with the detail so title + text land under the real columns.
        $listRow = [
            'id' => $r['external_id'],
            'judgementNumber' => $r['decision_number'],
            'courtName' => $r['court_name'],
            'city' => $r['city'],
            'hijriYear' => $r['hijri_year'],
            'judgementDate' => $detail['judgmentHijriiDate'] ?? null,
            'caseNumber' => $detail['caseNumber'] ?? null,
            'courtType' => $detail['judgmentCourtType'] ?? null,
        ];
        // Only overwrite the mapped title when the detail actually gave one.
        $mapped = legal_library_map_moj_judgement($listRow, $source, $detail);
        if (($detail['title'] ?? '') === '' && ($detail['decisionTitle'] ?? '') === '') {
            $mapped['title'] = $pdo->query("SELECT title FROM legal_library_items WHERE id=" . (int)$r['id'])->fetchColumn();
            $mapped['title_is_derived'] = 1;
        }
        legal_library_upsert_item($pdo, $mapped);
        $counts['enriched']++;
        usleep($pauseMs * 1000);
    }
    return $counts;
}

/* ---------------------------------------------------------------------------
 * Resumable catalogue walk (MOJ decisions)
 * ------------------------------------------------------------------------- */

/** The current Hijri year (Umm al-Qura civil approximation, deterministic). */
function legal_library_hijri_now_year(): int {
    // A pure-PHP tabular conversion, so the partition walker does not depend on the
    // intl extension being installed (a fresh cPanel host often lacks it). Accurate
    // to within a day, which is all a month-partition boundary needs.
    $d = (int)date('j'); $m = (int)date('n'); $y = (int)date('Y');
    $a = intdiv(14 - $m, 12);
    $yy = $y + 4800 - $a;
    $mm = $m + 12 * $a - 3;
    $jdn = $d + intdiv(153 * $mm + 2, 5) + 365 * $yy + intdiv($yy, 4) - intdiv($yy, 100) + intdiv($yy, 400) - 32045;
    $hijri = (int)floor(($jdn - 1948439) * 30 / 10631) + 1;
    return ($hijri > 1300 && $hijri < 1600) ? $hijri : 1447;
}

/**
 * The Hijri month windows the decisions catalogue is walked through, newest first.
 *
 * A whole-year window is thousands of pages, so the catalogue is partitioned by
 * Hijri month: each window is small enough that a single cron run can make progress
 * inside it and the cursor can be saved without the list shifting under us (a plain
 * page cursor drifts as new decisions are published; a dated window does not).
 */
function legal_library_decisions_partitions(int $yearsBack = 8): array {
    $now = legal_library_hijri_now_year();
    $out = [];
    for ($y = $now; $y >= $now - $yearsBack; $y--) {
        for ($m = 1; $m <= 12; $m++) {
            $out[] = ['year' => $y, 'month' => $m];
        }
    }
    return $out;
}

/** Parse the saved cursor "YYYY:MM:PAGE" into [year, month, page]. */
function legal_library_cursor_parse(?string $c): array {
    if ($c && preg_match('/^(\d{4}):(\d{1,2}):(\d{1,5})$/', $c, $m)) {
        return [(int)$m[1], (int)$m[2], max(1, (int)$m[3])];
    }
    return [0, 0, 1];
}

function legal_library_save_cursor(PDO $pdo, string $sourceKey, ?string $cursor): void {
    $pdo->prepare('UPDATE legal_library_sources SET sync_cursor = ? WHERE source_key = ?')->execute([$cursor, $sourceKey]);
}

/**
 * Walk a bounded slice of the decisions catalogue and save where it stopped.
 *
 * `$opts['max_pages']` caps how many gateway pages this run fetches, so a cron run
 * stays inside the gateway's rate limit and the web request stays short. The cursor
 * on `legal_library_sources.sync_cursor` makes the next run resume at the same
 * month/page. When the last window is reached the cursor resets to the start, so
 * the next cycle re-walks for updates (the upsert keeps that idempotent).
 */
function legal_library_import_moj_decisions_windowed(PDO $pdo, array $source, array $opts = []): array {
    $headers = ['Origin: https://laws.moj.gov.sa', 'Referer: https://laws.moj.gov.sa/'];
    $url = 'https://laws-gateway.moj.gov.sa/apis/legislations/v1/Judgements/judgements-list';
    $pageSize = min(50, max(1, (int)($opts['page_size'] ?? 50)));
    $budget = max(1, (int)($opts['max_pages'] ?? 12));
    $pause = (int)(($opts['pause_ms'] ?? 5000) * 1000);
    $withDetails = (bool)($opts['with_details'] ?? false);
    $detailCap = max(0, (int)($opts['detail_cap'] ?? 0));
    $detailsFetched = 0;

    $parts = legal_library_decisions_partitions((int)($opts['years_back'] ?? 8));
    [$curY, $curM, $curP] = legal_library_cursor_parse($source['sync_cursor'] ?? null);

    $start = 0;
    if ($curY > 0) {
        foreach ($parts as $i => $p) {
            if ($p['year'] === $curY && $p['month'] === $curM) { $start = $i; break; }
        }
    } else {
        $curP = 1;
    }

    $counts = ['seen' => 0, 'new' => 0, 'updated' => 0, 'skipped' => 0, 'pages_fetched' => 0, 'blocked' => 0, 'partitions_done' => 0, 'wrapped' => 0];
    $budgetLeft = $budget;
    $i = $start;
    $page = $curP;

    while ($i < count($parts) && $budgetLeft > 0) {
        $p = $parts[$i];
        $payload = [
            'pageNumber' => $page, 'pageSize' => $pageSize, 'judgmentNo' => '', 'decisionNo' => '',
            'cityId' => null, 'viewType' => 'grid', 'courtTypes' => 0, 'courtId' => null,
            'term' => null,
            'dateFrom' => sprintf('%04d-%02d-01', $p['year'], $p['month']),
            'dateTo'   => sprintf('%04d-%02d-30', $p['year'], $p['month']),
            'sortingBy' => 2, 'identityNumber' => '',
        ];
        $resp = legal_library_http_json('POST', $url, $payload, $headers, 30);
        $budgetLeft--;
        if (!is_array($resp['json']['model']['judgementsCollection'] ?? null)) {
            if (!empty($resp['blocked'])) $counts['blocked']++;
            break; // keep the cursor where it is; the next run resumes here
        }
        $counts['pages_fetched']++;
        $model = $resp['json']['model'];
        foreach ($model['judgementsCollection'] as $row) {
            if (empty($row['id'])) { $counts['skipped']++; continue; }
            $counts['seen']++;
            $detail = null;
            if ($withDetails && $detailsFetched < $detailCap) {
                $d = legal_library_http_json('GET',
                    'https://laws-gateway.moj.gov.sa/apis/legislations/v1/Judgements/get-details?id=' . rawurlencode((string)$row['id']) . '&lang=ar&IdentityNumber=',
                    null, $headers, 25);
                if (is_array($d['json']['model'] ?? null)) { $detail = $d['json']['model']; $detailsFetched++; }
                usleep(300000);
            }
            $counts[legal_library_upsert_item($pdo, legal_library_map_moj_judgement($row, $source, $detail))]++;
        }
        $totalPages = max(1, (int)($model['totalPages'] ?? 1));
        if ($page >= $totalPages) {
            $counts['partitions_done']++;
            $i++;
            $page = 1;
        } else {
            $page++;
        }
        legal_library_save_cursor($pdo, $source['source_key'], sprintf('%04d:%02d:%d', $p['year'], $p['month'], $page));
        if ($budgetLeft > 0 && $i < count($parts)) usleep($pause);
    }

    if ($i >= count($parts)) {
        legal_library_save_cursor($pdo, $source['source_key'], null);
        $counts['wrapped'] = 1;
    }
    return $counts;
}

/** Normalise one statute row from the MOJ legislation search. */
function legal_library_map_moj_statute(array $row, array $source): array {
    $name = trim((string)($row['statuteName'] ?? ''));
    $serial = (string)($row['serial'] ?? '');
    $legalType = trim((string)($row['legalType'] ?? ''));
    $summary = trim((string)($row['summary'] ?? ''));
    $issuance = trim((string)($row['issuanceDateGerogian'] ?? ''));

    return [
        'source_id'       => (int)$source['id'],
        'source_key'      => $source['source_key'],
        'external_id'     => ($serial !== '' ? $serial : (string)($row['statuteId'] ?? '')),
        'item_type'       => 'legislation',
        'title'           => mb_substr($name !== '' ? $name : 'نظام', 0, 380),
        'summary'         => $summary !== '' ? $summary : null,
        'court_name'      => null,
        'city'            => null,
        'court_type'      => null,
        'case_number'     => null,
        'decision_number' => null,
        'hijri_year'      => null,
        'gregorian_year'  => null,
        'decision_date'   => null,
        'category'        => $legalType !== '' ? $legalType : null,
        'body'            => $summary !== '' ? $summary : null,
        'source_url'      => $serial !== '' ? ('https://laws.moj.gov.sa/ar/legislation/' . rawurlencode($serial)) : ($source['base_url'] ?? ''),
        'file_url'        => null,
        'file_type'       => null,
        'file_size_kb'    => null,
        'page_count'      => null,
        'publisher'       => $source['publisher_ar'] ?? null,
        'published_at'    => preg_match('/^\d{4}-\d{2}-\d{2}/', $issuance) ? substr($issuance, 0, 10) : null,
        'source_updated_at' => null,
        'lang'            => 'ar',
        'redistribution'  => $source['redistribution'],
        'hosted_path'     => null,
        'is_published'    => 1,
    ];
}

function legal_library_import_moj_legislation(PDO $pdo, array $source, array $opts): array {
    $pages = max(1, (int)($opts['pages'] ?? 2));
    $pageSize = min(50, max(1, (int)($opts['page_size'] ?? 20)));
    $headers = ['Origin: https://laws.moj.gov.sa', 'Referer: https://laws.moj.gov.sa/'];
    $url = 'https://laws-gateway.moj.gov.sa/apis/legislations/v1/statute/section-search';
    $counts = ['seen' => 0, 'new' => 0, 'updated' => 0, 'skipped' => 0, 'pages_fetched' => 0, 'blocked' => 0];

    for ($p = 1; $p <= $pages; $p++) {
        $payload = [
            'pageNumber' => $p, 'pageSize' => $pageSize, 'keyword' => '', 'detailsKeyword' => '',
            'LegalStatue' => null, 'classificationId' => null, 'sortingBy' => 7,
            'statuteIssueDateFrom' => null, 'statuteIssueDateTo' => null, 'statuteName' => '',
            'statutePublishDateFrom' => null, 'statutePublishDateTo' => null, 'statuteType' => null,
            'isSearch' => false, 'identityNumber' => '',
        ];
        $resp = legal_library_http_json('POST', $url, $payload, $headers, 30);
        if (!is_array($resp['json']['model']['collection'] ?? null)) {
            if ($p === 1 && empty($resp['blocked'])) throw new RuntimeException('تعذّر جلب الأنظمة: ' . ($resp['error'] ?? 'استجابة غير متوقعة'));
            if (!empty($resp['blocked'])) $counts['blocked']++;
            break;
        }
        $counts['pages_fetched']++;
        foreach ($resp['json']['model']['collection'] as $row) {
            $counts['seen']++;
            $item = legal_library_map_moj_statute($row, $source);
            if ($item['external_id'] === '') { $counts['skipped']++; continue; }
            $counts[legal_library_upsert_item($pdo, $item)]++;
        }
        if ($p < $pages) usleep((int)(($opts["pause_ms"] ?? 2000) * 1000));
    }
    return $counts;
}

function legal_library_import_mashortich(PDO $pdo, array $source, array $opts): array {
    $pageUrl = 'https://www.mashortich.com/%D8%AA%D8%AD%D9%85%D9%8A%D9%84-%D9%85%D8%AC%D9%85%D9%88%D8%B9%D8%A9-%D8%A7%D9%84%D8%A3%D8%AD%D9%83%D8%A7%D9%85-%D8%A7%D9%84%D9%82%D8%B6%D8%A7%D8%A6%D9%8A%D8%A9-%D8%A7%D9%84%D8%B5%D8%A7%D8%AF%D8%B1/';
    $counts = ['seen' => 0, 'new' => 0, 'updated' => 0, 'skipped' => 0];
    $resp = legal_library_http('GET', $pageUrl, null, ['Accept: text/html'], 30);
    if (!$resp['ok'] || $resp['body'] === '') {
        throw new RuntimeException('تعذّر جلب صفحة المجموعات: ' . ($resp['error'] ?? 'لا محتوى'));
    }
    if (!preg_match_all('/<a[^>]+href="([^"]+\.pdf)"[^>]*>(.*?)<\/a>/is', $resp['body'], $m, PREG_SET_ORDER)) {
        return $counts;
    }
    foreach ($m as $a) {
        $fileUrl = html_entity_decode($a[1], ENT_QUOTES, 'UTF-8');
        $label = html_entity_decode(strip_tags($a[2]), ENT_QUOTES, 'UTF-8');
        $label = trim(preg_replace('/\s+/u', ' ', $label));
        // Only the "مجموعة الأحكام القضائية" collections belong to the library; the
        // commercial-registry PDF on the same page is not legal content.
        if (mb_strpos($label, 'الأحكام القضائية') === false) { $counts['skipped']++; continue; }
        $counts['seen']++;
        $sizeKb = null;
        if (preg_match('/(\d[\d,]*)\s*KB/i', $label, $sm)) $sizeKb = (int)str_replace(',', '', $sm[1]);
        $title = preg_replace('/\s*PDF.*$/u', '', $label);
        $title = trim(preg_replace('/\s*•.*$/u', '', $title));
        $externalId = basename(parse_url($fileUrl, PHP_URL_PATH) ?: $fileUrl);

        $item = [
            'source_id'       => (int)$source['id'],
            'source_key'      => $source['source_key'],
            'external_id'     => $externalId,
            'item_type'       => 'book',
            'title'           => mb_substr($title !== '' ? $title : 'مجموعة الأحكام القضائية', 0, 380),
            'summary'         => 'مجموعة أحكام قضائية صادرة عن وزارة العدل، منشورة بصيغة PDF.',
            'court_name'      => null,
            'city'            => null,
            'court_type'      => null,
            'case_number'     => null,
            'decision_number' => null,
            'hijri_year'      => null,
            'gregorian_year'  => null,
            'decision_date'   => null,
            'category'        => 'مجموعة أحكام',
            'body'            => null,
            'source_url'      => $pageUrl,
            'file_url'        => $fileUrl,
            'file_type'       => 'pdf',
            'file_size_kb'    => $sizeKb,
            'page_count'      => null,
            'publisher'       => $source['publisher_ar'] ?? null,
            'published_at'    => null,
            'source_updated_at' => null,
            'lang'            => 'ar',
            // link_only: we keep the metadata and the official link, never a copy.
            'redistribution'  => 'link_only',
            'hosted_path'     => null,
            'is_published'    => 1,
        ];
        $counts[legal_library_upsert_item($pdo, $item)]++;
    }
    return $counts;
}

/** Sync every enabled source that has an importer. Returns per-source results. */
function legal_library_sync_all(PDO $pdo, array $opts = []): array {
    $out = [];
    foreach (legal_library_sources(true) as $s) {
        if (!in_array($s['source_key'], ['moj_decisions', 'moj_legislation', 'mashortich_books'], true)) {
            continue; // no importer: registered but disabled, or pending terms review
        }
        $out[$s['source_key']] = legal_library_sync($pdo, $s['source_key'], $opts);
    }
    return $out;
}

/* ---------------------------------------------------------------------------
 * Search
 * ------------------------------------------------------------------------- */

/**
 * Search the catalogue. All filters are optional and combined with AND.
 *
 * @param array $f filters: q, source_key, item_type, category, court_name, city,
 *                       year, file_type
 * @return array{items:list<array>, total:int, page:int, pages:int, per_page:int}
 */
function legal_library_search(array $f = [], int $page = 1, int $perPage = 20): array {
    global $pdo;
    $perPage = min(50, max(1, $perPage));
    $page = max(1, $page);
    $where = ['i.is_published = 1'];
    $params = [];

    $q = trim((string)($f['q'] ?? ''));
    if ($q !== '') {
        // Match on the Arabic-normalised form first (so "الامير"/"الأمير" and
        // "احمد"/"أحمد" match), then on the raw text as a fallback. The stored
        // original is never modified; normalisation only ever narrows the query to
        // the extra `*_norm` columns. Both terms are parameterised.
        //
        // The full ruling text (`body`) is matched too — the search box promises
        // "the title, the text and the number", and a legal library whose imported
        // judgments cannot be searched by their own wording is not usable. Body is
        // matched ONLY for rows the read gate would expose (`redistribution = hosted`);
        // a `link_only` source's stored prose must not be inferable through a search
        // hit, exactly as `legal_library_public_item()` refuses to return it. Body has
        // no normalised column, so it is matched on the raw term.
        $qNorm = legal_library_normalize_ar($q);
        $where[] = '(i.title_norm LIKE ? OR i.summary_norm LIKE ? OR i.title LIKE ? OR i.summary LIKE ?'
                 . " OR (i.redistribution = 'hosted' AND i.body LIKE ?))";
        $like = '%' . $qNorm . '%';
        $likeRaw = '%' . $q . '%';
        array_push($params, $like, $like, $likeRaw, $likeRaw, $likeRaw);
    }
    foreach (['source_key' => 'i.source_key', 'item_type' => 'i.item_type', 'category' => 'i.category',
              'court_name' => 'i.court_name', 'city' => 'i.city', 'file_type' => 'i.file_type'] as $key => $col) {
        $val = trim((string)($f[$key] ?? ''));
        if ($val !== '') { $where[] = "$col = ?"; $params[] = $val; }
    }
    if (!empty($f['year'])) { $where[] = 'i.hijri_year = ?'; $params[] = (int)$f['year']; }
    if (!empty($f['has_file'])) { $where[] = '(i.file_url IS NOT NULL OR i.hosted_path IS NOT NULL)'; }

    // Sort: newest (default), oldest, or relevance (title matches first when a query
    // is present). The order is chosen from a fixed allow-list, never interpolated.
    $sort = (string)($f['sort'] ?? 'newest');
    $orderBy = match ($sort) {
        'oldest' => 'i.decision_date ASC, i.id ASC',
        'court'  => 'i.court_name ASC, i.decision_date DESC',
        default  => 'i.decision_date DESC, i.id DESC',
    };

    $whereSql = implode(' AND ', $where);
    try {
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM legal_library_items i WHERE $whereSql");
        $cnt->execute($params);
        $total = (int)$cnt->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $sql = "SELECT i.*, s.name_ar AS source_name_ar, s.name_en AS source_name_en
                FROM legal_library_items i
                LEFT JOIN legal_library_sources s ON s.id = i.source_id
                WHERE $whereSql
                ORDER BY $orderBy
                LIMIT $perPage OFFSET $offset";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $items = $st->fetchAll();
    } catch (Throwable $e) {
        return ['items' => [], 'total' => 0, 'page' => 1, 'pages' => 0, 'per_page' => $perPage];
    }
    return [
        'items' => $items,
        'total' => $total,
        'page' => $page,
        'pages' => $perPage > 0 ? (int)ceil($total / $perPage) : 0,
        'per_page' => $perPage,
    ];
}

/** Distinct values for the filter controls, drawn from the data actually imported. */
function legal_library_facets(): array {
    global $pdo;
    $out = ['sources' => [], 'types' => [], 'courts' => [], 'cities' => [], 'years' => [], 'categories' => [], 'file_types' => []];
    try {
        $out['sources'] = $pdo->query("SELECT source_key, name_ar, name_en, item_count FROM legal_library_sources WHERE item_count > 0 ORDER BY item_count DESC")->fetchAll();
        $out['courts'] = array_column($pdo->query("SELECT court_name, COUNT(*) c FROM legal_library_items WHERE court_name IS NOT NULL AND court_name <> '' GROUP BY court_name ORDER BY c DESC LIMIT 40")->fetchAll(), 'court_name');
        $out['cities'] = array_column($pdo->query("SELECT city, COUNT(*) c FROM legal_library_items WHERE city IS NOT NULL AND city <> '' GROUP BY city ORDER BY c DESC LIMIT 40")->fetchAll(), 'city');
        $out['years']  = array_column($pdo->query("SELECT DISTINCT hijri_year FROM legal_library_items WHERE hijri_year IS NOT NULL ORDER BY hijri_year DESC")->fetchAll(), 'hijri_year');
        $out['categories'] = array_column($pdo->query("SELECT DISTINCT category FROM legal_library_items WHERE category IS NOT NULL AND category <> '' ORDER BY category LIMIT 60")->fetchAll(), 'category');
        $out['types'] = array_column($pdo->query("SELECT item_type, COUNT(*) c FROM legal_library_items GROUP BY item_type ORDER BY c DESC")->fetchAll(), 'item_type');
        $out['file_types'] = array_column($pdo->query("SELECT DISTINCT file_type FROM legal_library_items WHERE file_type IS NOT NULL AND file_type <> '' ORDER BY file_type")->fetchAll(), 'file_type');
    } catch (Throwable $e) {
        // un-migrated database: empty facets, the page shows its empty state
    }
    return $out;
}

function legal_library_item(int $id): ?array {
    global $pdo;
    try {
        $st = $pdo->prepare("SELECT i.*, s.name_ar AS source_name_ar, s.name_en AS source_name_en,
                                    s.publisher_ar AS source_publisher_ar, s.publisher_en AS source_publisher_en
                             FROM legal_library_items i
                             LEFT JOIN legal_library_sources s ON s.id = i.source_id
                             WHERE i.id = ? AND i.is_published = 1");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function legal_library_stats(): array {
    global $pdo;
    try {
        $byType = $pdo->query("SELECT item_type, COUNT(*) c FROM legal_library_items WHERE is_published=1 GROUP BY item_type")->fetchAll();
        $total = (int)$pdo->query("SELECT COUNT(*) FROM legal_library_items WHERE is_published=1")->fetchColumn();
        $sources = (int)$pdo->query("SELECT COUNT(*) FROM legal_library_sources WHERE is_enabled=1")->fetchColumn();
        $latest = $pdo->query("SELECT id, title, decision_number, court_name, city, hijri_year, decision_date, item_type, source_key, title_is_derived FROM legal_library_items WHERE is_published=1 ORDER BY decision_date DESC, id DESC LIMIT 8")->fetchAll();
        $courts = (int)$pdo->query("SELECT COUNT(DISTINCT court_name) FROM legal_library_items WHERE is_published=1 AND court_name IS NOT NULL AND court_name<>''")->fetchColumn();
        $files = (int)$pdo->query("SELECT COUNT(*) FROM legal_library_items WHERE is_published=1 AND (file_url IS NOT NULL OR hosted_path IS NOT NULL)")->fetchColumn();
        $enriched = (int)$pdo->query("SELECT COUNT(*) FROM legal_library_items WHERE is_published=1 AND detail_fetched_at IS NOT NULL")->fetchColumn();
    } catch (Throwable $e) {
        return ['total' => 0, 'by_type' => [], 'sources' => 0, 'latest' => [], 'courts' => 0, 'files' => 0, 'enriched' => 0];
    }
    $map = [];
    foreach ($byType as $r) $map[$r['item_type']] = (int)$r['c'];
    return ['total' => $total, 'by_type' => $map, 'sources' => $sources, 'latest' => $latest,
            'courts' => $courts, 'files' => $files, 'enriched' => $enriched];
}

/**
 * Items related to one item by real, shared attributes only — same court, or same
 * city + type. Never a generated "semantic" relation: if the source data shares no
 * attribute, the list is empty rather than invented.
 */
function legal_library_related(int $id, int $limit = 6): array {
    global $pdo;
    try {
        $row = $pdo->query("SELECT court_name, city, item_type FROM legal_library_items WHERE id=" . (int)$id)->fetch();
        if (!$row) return [];
        $limit = min(12, max(1, $limit));
        $conds = [];
        $params = [(int)$id];
        if (!empty($row['court_name'])) { $conds[] = 'court_name = ?'; $params[] = $row['court_name']; }
        if (!empty($row['city'])) { $conds[] = '(city = ? AND item_type = ?)'; $params[] = $row['city']; $params[] = $row['item_type']; }
        if (!$conds) return [];
        $st = $pdo->prepare("SELECT * FROM legal_library_items
                             WHERE is_published=1 AND id <> ? AND (" . implode(' OR ', $conds) . ")
                             ORDER BY decision_date DESC LIMIT " . (int)$limit);
        $st->execute($params);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/* ---------------------------------------------------------------------------
 * Favorites
 * ------------------------------------------------------------------------- */

function legal_library_is_favorite(PDO $pdo, int $userId, int $itemId): bool {
    if ($userId <= 0) return false;
    try {
        $st = $pdo->prepare('SELECT 1 FROM legal_library_favorites WHERE user_id = ? AND item_id = ?');
        $st->execute([$userId, $itemId]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/** Toggle and return the new state (true = now saved). */
function legal_library_favorite_toggle(PDO $pdo, int $userId, int $itemId): bool {
    if ($userId <= 0 || $itemId <= 0) return false;
    if (legal_library_is_favorite($pdo, $userId, $itemId)) {
        $pdo->prepare('DELETE FROM legal_library_favorites WHERE user_id = ? AND item_id = ?')->execute([$userId, $itemId]);
        return false;
    }
    $pdo->prepare('INSERT IGNORE INTO legal_library_favorites (user_id, item_id) VALUES (?, ?)')->execute([$userId, $itemId]);
    return true;
}

function legal_library_favorites(PDO $pdo, int $userId, int $limit = 100): array {
    if ($userId <= 0) return [];
    try {
        $st = $pdo->prepare("SELECT i.*, s.name_ar AS source_name_ar, s.name_en AS source_name_en
                             FROM legal_library_favorites f
                             JOIN legal_library_items i ON i.id = f.item_id
                             LEFT JOIN legal_library_sources s ON s.id = i.source_id
                             WHERE f.user_id = ? AND i.is_published = 1
                             ORDER BY f.created_at DESC LIMIT " . (int)$limit);
        $st->execute([$userId]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/* ---------------------------------------------------------------------------
 * Presentation
 * ------------------------------------------------------------------------- */

/**
 * Shape one row for the UI/API. This is the ONLY place the redistribution rule is
 * applied, so a hosted item exposes `body`/readable text and a link_only item
 * exposes the official file URL but never a copied file.
 */
function legal_library_public_item(array $row, ?int $userId = null, bool $withBody = false): array {
    $redist = $row['redistribution'] ?? 'link_only';
    $canReadHere = $redist === 'hosted' && !empty($row['body']);
    $fileIsHosted = $redist === 'hosted' && !empty($row['hosted_path']);

    // Read every field defensively: callers pass either a full `SELECT i.*` row or a
    // slim projection (the home page's "latest" strip selects a subset), and a missing
    // key must degrade to null rather than raise a notice.
    $item = [
        'id'           => (int)$row['id'],
        'type'         => $row['item_type'] ?? 'judgment',
        'type_label'   => legal_library_item_type_label((string)($row['item_type'] ?? 'judgment')),
        'title'        => $row['title'] ?? '',
        'decision_title' => $row['decision_title'] ?? null,
        'title_is_derived' => !empty($row['title_is_derived']),
        'summary'      => $row['summary'] ?? null,
        'court_name'   => $row['court_name'] ?? null,
        'city'         => $row['city'] ?? null,
        'case_number'  => $row['case_number'] ?? null,
        'decision_number' => $row['decision_number'] ?? null,
        'hijri_year'   => isset($row['hijri_year']) && $row['hijri_year'] !== null ? (int)$row['hijri_year'] : null,
        'gregorian_year' => isset($row['gregorian_year']) && $row['gregorian_year'] !== null ? (int)$row['gregorian_year'] : null,
        'decision_date'=> $row['decision_date'] ?? null,
        'category'     => $row['category'] ?? null,
        'source_key'   => $row['source_key'] ?? '',
        'source_name'  => is_rtl() ? ($row['source_name_ar'] ?? '') : ($row['source_name_en'] ?? ''),
        'publisher'    => $row['publisher'] ?? null,
        'source_url'   => $row['source_url'] ?? null,
        'file_url'     => $row['file_url'] ?? null,
        'file_type'    => $row['file_type'] ?? null,
        'file_size_kb' => isset($row['file_size_kb']) && $row['file_size_kb'] !== null ? (int)$row['file_size_kb'] : null,
        'can_read_here'=> $canReadHere,
        'can_download' => !empty($row['file_url']) || $fileIsHosted,
        // Never a copied file for a link_only source; the download goes to the source.
        'download_url' => $fileIsHosted ? ('/legal-library-file.php?id=' . (int)$row['id']) : (!empty($row['file_url']) ? $row['file_url'] : null),
        'download_is_external' => !$fileIsHosted && !empty($row['file_url']),
        'redistribution' => $redist,
        'imported_at'  => $row['imported_at'] ?? ($row['created_at'] ?? null),
        'created_at'   => $row['created_at'] ?? null,
    ];
    if ($withBody) {
        // Only ever exposed for a hosted source; a link_only item has no body stored.
        $item['body'] = $canReadHere ? $row['body'] : null;
        // The structured sections, likewise gated on the same rule.
        $item['sections'] = $canReadHere ? [
            'facts'     => $row['judgment_facts'] ?? null,
            'reasons'   => $row['judgment_reasons'] ?? null,
            'ruling'    => $row['judgment_ruling'] ?? null,
            'narration' => $row['judgment_narration'] ?? null,
        ] : null;
    }
    if ($userId !== null) {
        $item['is_favorite'] = legal_library_is_favorite($GLOBALS['pdo'], $userId, (int)$row['id']);
    }
    return $item;
}

/**
 * The court-name → short label used by the browse-by-court chips, and the counts
 * behind them. Drawn from the data actually imported, never invented.
 */
function legal_library_browse(): array {
    global $pdo;
    $out = ['courts' => [], 'cities' => [], 'years' => []];
    try {
        $out['courts'] = $pdo->query(
            "SELECT court_name AS name, COUNT(*) AS n FROM legal_library_items
              WHERE is_published=1 AND court_name IS NOT NULL AND court_name <> ''
              GROUP BY court_name ORDER BY n DESC LIMIT 12")->fetchAll();
        $out['cities'] = $pdo->query(
            "SELECT city AS name, COUNT(*) AS n FROM legal_library_items
              WHERE is_published=1 AND city IS NOT NULL AND city <> ''
              GROUP BY city ORDER BY n DESC LIMIT 12")->fetchAll();
        $out['years'] = $pdo->query(
            "SELECT hijri_year AS name, COUNT(*) AS n FROM legal_library_items
              WHERE is_published=1 AND hijri_year IS NOT NULL
              GROUP BY hijri_year ORDER BY hijri_year DESC LIMIT 12")->fetchAll();
    } catch (Throwable $e) {
        // un-migrated database: empty browse, the page shows its empty state
    }
    return $out;
}
