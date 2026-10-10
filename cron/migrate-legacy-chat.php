<?php
  // Run once from cPanel Cron Jobs or a shell: php /home/CPANEL_USER/public_html/cron/migrate-legacy-chat.php
  // Copies legacy AI chat history into the archive tables. Re-running is a no-op. Originals are
  // left untouched: deleting them is a separate, deliberate decision.
  if (PHP_SAPI !== 'cli') {
      http_response_code(404);
      exit;
  }

  require_once __DIR__ . '/../config/config.php';
  require_once __DIR__ . '/../includes/legacy_chat_migration.php';

  $result = legacy_chat_migrate($pdo, 'cli');
  fwrite(STDOUT, 'Legacy chat migration: seen=' . $result['seen']
      . ' migrated=' . $result['migrated']
      . ' messages=' . $result['messages']
      . ' skipped=' . $result['skipped'] . PHP_EOL);
