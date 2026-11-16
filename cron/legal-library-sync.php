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
  //   --details                also fetch per-item detail text (MOJ decisions)
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

  $opts = ['pages' => 5, 'page_size' => 25, 'with_details' => false];
  $only = null;
  foreach (array_slice($argv ?? [], 1) as $arg) {
      if (str_starts_with($arg, '--source='))      $only = substr($arg, 9);
      elseif (str_starts_with($arg, '--pages='))   $opts['pages'] = max(1, (int)substr($arg, 8));
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

  if ($targets && $okCount === 0) {
      fwrite(STDERR, 'legal-library-sync: every attempted source failed.' . PHP_EOL);
      exit(1);
  }
  exit(0);
