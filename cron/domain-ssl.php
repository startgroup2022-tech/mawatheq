<?php
  // Run hourly from cPanel Cron Jobs: php /home/CPANEL_USER/public_html/cron/domain-ssl.php
  // Probes queued TLS jobs for verified custom domains and records the outcome. It never
  // invents a certificate: without an ACME account the job is left as manual_required.
  if (PHP_SAPI !== 'cli') {
      http_response_code(404);
      exit;
  }

  require_once __DIR__ . '/../config/config.php';
  require_once __DIR__ . '/../includes/firm_domains.php';
  require_once __DIR__ . '/../includes/domain_ssl.php';

  $result = domain_ssl_run($pdo);
  fwrite(STDOUT, 'Domain SSL jobs seen=' . $result['seen']
      . ' issued=' . $result['issued']
      . ' pending=' . $result['pending']
      . ' manual=' . $result['manual']
      . ' failed=' . $result['failed'] . PHP_EOL);
