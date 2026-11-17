<?php
  // Run daily from cPanel Cron Jobs:
  //   php /home/CPANEL_USER/public_html/cron/legal-library-sync.php
  //
  // Imports the enabled legal-library sources. Every source's own terms decide what
  // is stored (see includes/legal_library.php); this job only drives the importers
  // and writes the sync-run audit rows the admin console reads.
  //
  // Options (optional argv):
  //   --source=moj_decisions   sync one source only
  //   --pages=5                pages to fetch per source (default 5)
  //   --details                also fetch per-item detail text during the list walk
  //   --enrich=N               enrich up to N catalogue rows with their DETAIL record
  //                            (real title + text). Run this on a schedule: the list
  //                            endpoint has no title, so the catalogue is only useful
  //                            once the details are filled. Default 15 per run.
  //   --no-enrich              skip the enrichment pass
  //
  // A source that fails is recorded as `error` and the others still run; the exit
  // code is non-zero only when every attempted source failed, so a cPanel "email me
  // on failure" rule does not fire for a single transient upstream hiccup.
  if (PHP_SAPI !== 'cli') {
      http_response_code(404);
      exit;
  }

  require_once __DIR__ . '/../config/config.php';
  require_once __DIR__ . '/../includes/legal_library.php';

  $opts = ['pages' => 5, 'page_size' => 25, 'with_details' => false, 'windowed' => true, 'max_pages' => 12, 'pause_ms' => 5000];
  $only = null;
  $enrich = 15;
  foreach (array_slice($argv ?? [], 1) as $arg) {
      if (str_starts_with($arg, '--source='))      $only = substr($arg, 9);
      elseif (str_starts_with($arg, '--pages='))   $opts['pages'] = max(1, (int)substr($arg, 8));
      elseif (str_starts_with($arg, '--max-pages=')) $opts['max_pages'] = max(1, (int)substr($arg, 12));
      elseif (str_starts_with($arg, '--pause-ms='))  $opts['pause_ms'] = max(0, (int)substr($arg, 11));
      elseif (str_starts_with($arg, '--enrich='))    $enrich = max(0, (int)substr($arg, 9));
      elseif ($arg === '--no-enrich')                $enrich = 0;
      elseif ($arg === '--full')                   $opts['windowed'] = false;
      elseif ($arg === '--details')                $opts['with_details'] = true;
  }

  $targets = $only !== null
      ? [$only => legal_library_sync($pdo, $only, $opts)]
      : legal_library_sync_all($pdo, $opts);

  $okCount = 0;
  foreach ($targets as $key => $res) {
      if (($res['status'] ?? 'error') !== 'error') $okCount++;
      fwrite(STDOUT, sprintf(
          '%s: status=%s seen=%d new=%d updated=%d skipped=%d%s' . PHP_EOL,
          $key,
          $res['status'] ?? 'error',
          $res['seen'] ?? 0,
          $res['new'] ?? 0,
          $res['updated'] ?? 0,
          $res['skipped'] ?? 0,
          !empty($res['error']) ? (' error=' . $res['error']) : ''
      ));
  }

  // Enrichment pass: fill the real title and text for catalogue rows that have none.
  // This is what turns a bare number/court list into a readable library, so it runs
  // by default and is bounded so a single cron run never times out.
  if ($enrich > 0) {
      $src = legal_library_source('moj_decisions');
      if ($src) {
          $r = legal_library_enrich_moj_details($pdo, $src, ['limit' => $enrich, 'pause_ms' => 4000]);
          fwrite(STDOUT, sprintf(
              'moj_decisions enrich: seen=%d enriched=%d failed=%d blocked=%d' . PHP_EOL,
              $r['seen'], $r['enriched'], $r['failed'], $r['blocked']
          ));
      }
  }

  if ($targets && $okCount === 0) {
      fwrite(STDERR, 'legal-library-sync: every attempted source failed.' . PHP_EOL);
      exit(1);
  }
  exit(0);
