<?php
  // Run every 10 minutes from cPanel Cron Jobs:
  //   php /home/CPANEL_USER/public_html/cron/ngenius-reconcile.php
  //
  // Why this exists: a webhook can be missed (host down, firewall, a delivery the gateway
  // never retried). A booking whose customer paid but whose callback never arrived would sit
  // 'pending' forever and its slot would stay reserved. This job re-queries the gateway for
  // every still-pending order and finishes what the callback did not: confirm a real capture,
  // or release a booking whose order has definitively failed or expired.
  //
  // It never marks anything paid on its own — confirmation always comes from the gateway's
  // own answer, through the same consultation_payment_verify_and_confirm() the webhook uses.
  if (PHP_SAPI !== 'cli') {
      http_response_code(404);
      exit;
  }

  require_once __DIR__ . '/../config/config.php';
  require_once __DIR__ . '/../includes/consultation_payment.php';

  $settings = ngenius_settings($pdo);
  if (!ngenius_is_enabled($settings)) {
      fwrite(STDOUT, 'N-Genius is not enabled; nothing to reconcile.' . PHP_EOL);
      exit(0);
  }
  if (!consultation_payment_schema_ready($pdo)) {
      fwrite(STDOUT, 'upgrade_v69.sql has not been applied; nothing to reconcile.' . PHP_EOL);
      exit(0);
  }

  // Older than 2 minutes so an order the customer is still looking at is not released
  // mid-payment; younger than 24 hours so a long-abandoned booking is not re-queried forever.
  $stmt = $pdo->prepare("SELECT id, payment_reference, scheduled_at FROM consultations
      WHERE payment_status = 'pending'
        AND payment_reference IS NOT NULL
        AND created_at < (NOW() - INTERVAL 2 MINUTE)
        AND created_at > (NOW() - INTERVAL 1 DAY)
      ORDER BY id ASC LIMIT 100");
  $stmt->execute();
  $pending = $stmt->fetchAll();

  $confirmed = 0; $released = 0; $stillPending = 0; $errors = 0;
  foreach ($pending as $row) {
      $result = consultation_payment_verify_and_confirm($pdo, (int)$row['id'], (string)$row['payment_reference']);
      if (empty($result['ok'])) {
          $errors++;
          error_log('ngenius-reconcile: consultation ' . $row['id'] . ' verify failed: ' . ($result['error'] ?? 'unknown'));
          continue;
      }
      if (!empty($result['confirmed'])) {
          $confirmed++;
      } elseif (in_array($result['state'], ['FAILED', 'CANCELLED', 'EXPIRED'], true)) {
          // A cancelled/expired order frees the slot; the verify call already stamped the
          // payment_status, and release() only touches a booking that is still pending.
          if (consultation_payment_release($pdo, (int)$row['id'], 'reconcile_' . $result['state'])) $released++;
      } else {
          $stillPending++;
      }
  }

  fwrite(STDOUT, 'N-Genius reconcile: seen=' . count($pending)
      . ' confirmed=' . $confirmed . ' released=' . $released
      . ' pending=' . $stillPending . ' errors=' . $errors . PHP_EOL);
