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
 * A small cURL wrapper used by every importer. Returns a structured result rather
 * than throwing, because an importer must record a partial failure and continue.
 *
 * @return array{ok:bool, code:int, body:string, json:?array, error:?string}
 */
function legal_library_http(string $method, string $url, ?array $jsonPayload = null, array $headers = [], int $timeout = 30): array {
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'code' => 0, 'body' => '', 'json' => null, 'error' => 'cURL غير مفعّل على السيرفر.'];
    }
    $ch = curl_init($url);
    $hdr = array_merge([
        'Accept: application/json, text/plain, */*',
        'Accept-Language: ar,en;q=0.9',
        'User-Agent: MawatheqLegalLibrary/1.0 (+https://mawatheq.com)',
    ], $headers);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_HTTPHEADER => $hdr,
    ];
    if (strtoupper($method) === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = $jsonPayload === null ? '' : json_encode($jsonPayload, JSON_UNESCAPED_UNICODE);
        $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
    }
    curl_setopt_array($ch, $opts);
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
    $last = ['ok' => false, 'code' => 0, 'json' => null, 'error' => 'لم تُجرَ محاولة', 'blocked' => false];
    for ($attempt = 0; $attempt < max(1, $tries); $attempt++) {
        $r = legal_library_http($method, $url, $jsonPayload, $headers, $timeout);
        $last = ['ok' => $r['ok'], 'code' => $r['code'], 'json' => $r['json'], 'error' => $r['error'], 'blocked' => false];
        if ($r['ok'] && $r['json'] !== null) {
            return $last;
        }
        // A 2xx with a non-JSON body is the WAF. Back off and try again.
        $blocked = $r['ok'] && $r['json'] === null;
        $last['blocked'] = $blocked;
        if ($attempt < $tries - 1) {
            usleep($blocked ? (800000 * ($attempt + 1)) : 500000);
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
    $cols = ['source_id','source_key','external_id','item_type','title','summary','court_name','city',
             'court_type','case_number','decision_number','hijri_year','gregorian_year','decision_date',
             'category','body','source_url','file_url','file_type','file_size_kb','page_count','publisher',
             'published_at','source_updated_at','lang','redistribution','hosted_path','is_published'];

    $st = $pdo->prepare('SELECT id FROM legal_library_items WHERE source_key = ? AND external_id = ?');
    $st->execute([$it['source_key'], $it['external_id']]);
    $existing = $st->fetchColumn();

    // Full text / hosted file is stored only when the operator has confirmed the
    // source permits re-hosting (see legal_library_capture_body()). Otherwise the
    // row keeps its metadata and its official link, and the reader is sent there.
    if (!legal_library_capture_body()) {
        $it['body'] = null;
        $it['hosted_path'] = null;
    }

    $vals = [];
    foreach ($cols as $c) {
        $vals[$c] = $it[$c] ?? null;
    }
    // Never let a re-sync blank a field the source did not send this time.
    if ($existing) {
        $set = [];
        $params = [];
        foreach ($vals as $c => $v) {
            if ($c === 'source_key' || $c === 'external_id') continue;
            $set[] = "`$c` = ?";
            $params[] = $v;
        }
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

/** Normalise one row from the MOJ judgements list into our item shape. */
function legal_library_map_moj_judgement(array $row, array $source, ?array $detail = null): array {
    $num  = trim((string)($row['judgementNumber'] ?? ''));
    $court = trim((string)($row['courtName'] ?? ''));
    $city  = trim((string)($row['city'] ?? ''));
    $hijri = trim((string)($row['judgementDate'] ?? ''));
    $hijriYear = (int)($row['hijriYear'] ?? 0) ?: null;

    $title = $num !== '' ? ('حكم رقم ' . $num) : 'حكم قضائي';
    if ($court !== '') $title .= ' — ' . $court;

    $body = null;
    if ($detail) {
        // The portal exposes the ruling text under one of several keys depending on
        // the decision kind. Take the first non-empty one; never fabricate.
        foreach (['appealTextofRulling','judgmentTextofRulling','judgmentRuling','appealRuling'] as $k) {
            if (!empty($detail[$k])) { $body = (string)$detail[$k]; break; }
        }
    }
    $sourceUrl = 'https://laws.moj.gov.sa/ar/JudicialDecisionsList/1/' . rawurlencode((string)$row['id']);

    return [
        'source_id'       => (int)$source['id'],
        'source_key'      => $source['source_key'],
        'external_id'     => (string)$row['id'],
        'item_type'       => 'judgment',
        'title'           => mb_substr($title, 0, 380),
        'summary'         => null,
        'court_name'      => $court !== '' ? $court : null,
        'city'            => $city !== '' ? $city : null,
        'court_type'      => isset($row['courtType']) ? (int)$row['courtType'] : null,
        'case_number'     => isset($row['caseNumber']) && $row['caseNumber'] !== null ? (string)$row['caseNumber'] : null,
        'decision_number' => $num !== '' ? $num : null,
        'hijri_year'      => $hijriYear,
        'gregorian_year'  => legal_library_gregorian_year_of($hijri),
        'decision_date'   => legal_library_hijri_to_gregorian($hijri),
        'category'        => $court !== '' ? $court : null,
        'body'            => $body,
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
    ];
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
        // FULLTEXT when the index exists, LIKE otherwise. Both are parameterised;
        // the query is never interpolated.
        $where[] = '(i.title LIKE ? OR i.summary LIKE ? OR i.body LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like);
    }
    foreach (['source_key' => 'i.source_key', 'item_type' => 'i.item_type', 'category' => 'i.category',
              'court_name' => 'i.court_name', 'city' => 'i.city', 'file_type' => 'i.file_type'] as $key => $col) {
        $val = trim((string)($f[$key] ?? ''));
        if ($val !== '') { $where[] = "$col = ?"; $params[] = $val; }
    }
    if (!empty($f['year'])) { $where[] = 'i.hijri_year = ?'; $params[] = (int)$f['year']; }

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
                ORDER BY i.decision_date DESC, i.id DESC
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
    $out = ['sources' => [], 'types' => [], 'courts' => [], 'cities' => [], 'years' => [], 'categories' => []];
    try {
        $out['sources'] = $pdo->query("SELECT source_key, name_ar, name_en, item_count FROM legal_library_sources WHERE item_count > 0 ORDER BY item_count DESC")->fetchAll();
        $out['courts'] = array_column($pdo->query("SELECT court_name, COUNT(*) c FROM legal_library_items WHERE court_name IS NOT NULL AND court_name <> '' GROUP BY court_name ORDER BY c DESC LIMIT 40")->fetchAll(), 'court_name');
        $out['cities'] = array_column($pdo->query("SELECT city, COUNT(*) c FROM legal_library_items WHERE city IS NOT NULL AND city <> '' GROUP BY city ORDER BY c DESC LIMIT 40")->fetchAll(), 'city');
        $out['years']  = array_column($pdo->query("SELECT DISTINCT hijri_year FROM legal_library_items WHERE hijri_year IS NOT NULL ORDER BY hijri_year DESC")->fetchAll(), 'hijri_year');
        $out['categories'] = array_column($pdo->query("SELECT DISTINCT category FROM legal_library_items WHERE category IS NOT NULL AND category <> '' ORDER BY category LIMIT 60")->fetchAll(), 'category');
        $out['types'] = array_column($pdo->query("SELECT item_type, COUNT(*) c FROM legal_library_items GROUP BY item_type ORDER BY c DESC")->fetchAll(), 'item_type');
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
        $latest = $pdo->query("SELECT title, id FROM legal_library_items WHERE is_published=1 ORDER BY created_at DESC LIMIT 5")->fetchAll();
    } catch (Throwable $e) {
        return ['total' => 0, 'by_type' => [], 'sources' => 0, 'latest' => []];
    }
    $map = [];
    foreach ($byType as $r) $map[$r['item_type']] = (int)$r['c'];
    return ['total' => $total, 'by_type' => $map, 'sources' => $sources, 'latest' => $latest];
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

    $item = [
        'id'           => (int)$row['id'],
        'type'         => $row['item_type'],
        'type_label'   => legal_library_item_type_label((string)$row['item_type']),
        'title'        => $row['title'],
        'summary'      => $row['summary'],
        'court_name'   => $row['court_name'],
        'city'         => $row['city'],
        'case_number'  => $row['case_number'],
        'decision_number' => $row['decision_number'],
        'hijri_year'   => $row['hijri_year'] !== null ? (int)$row['hijri_year'] : null,
        'gregorian_year' => $row['gregorian_year'] !== null ? (int)$row['gregorian_year'] : null,
        'decision_date'=> $row['decision_date'],
        'category'     => $row['category'],
        'source_key'   => $row['source_key'],
        'source_name'  => is_rtl() ? ($row['source_name_ar'] ?? '') : ($row['source_name_en'] ?? ''),
        'publisher'    => $row['publisher'],
        'source_url'   => $row['source_url'],
        'file_url'     => $row['file_url'],
        'file_type'    => $row['file_type'],
        'file_size_kb' => $row['file_size_kb'] !== null ? (int)$row['file_size_kb'] : null,
        'can_read_here'=> $canReadHere,
        'can_download' => !empty($row['file_url']) || $fileIsHosted,
        // Never a copied file for a link_only source; the download goes to the source.
        'download_url' => $fileIsHosted ? ('/legal-library-file.php?id=' . (int)$row['id']) : ($row['file_url'] ?: null),
        'download_is_external' => !$fileIsHosted && !empty($row['file_url']),
        'redistribution' => $redist,
        'created_at'   => $row['created_at'] ?? null,
    ];
    if ($withBody) {
        // Only ever exposed for a hosted source; a link_only item has no body stored.
        $item['body'] = $canReadHere ? $row['body'] : null;
    }
    if ($userId !== null) {
        $item['is_favorite'] = legal_library_is_favorite($GLOBALS['pdo'], $userId, (int)$row['id']);
    }
    return $item;
}
