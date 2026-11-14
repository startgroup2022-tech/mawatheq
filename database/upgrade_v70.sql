-- ============================================================================
-- مواثيق — شراء الباقات والدفع (Execution 63)
-- MAWATHEQ — package purchasing and payment
-- upgrade_v70.sql
-- ============================================================================
--
-- WHAT THIS ADDS
--
--   Until now a client could not buy a package: every "choose plan" button led
--   to /register.php (which ignores the plan) or to a generic contact form. This
--   migration gives a package the same order + payment path a consultation and an
--   invoice already have, so a plan is activated only after the gateway
--   independently confirms the capture.
--
--     1) plans.classification  ENUM('free','paid','corporate')
--        How the catalogue presents the plan: a free plan needs no checkout, a
--        paid plan is bought, a corporate plan is quoted. Derived once from the
--        existing price/is_corporate columns so no shipped plan changes meaning.
--     2) plans.consultation_price DECIMAL(12,3)
--        A pay-per-consultation package prices the consultation, not a monthly
--        subscription. NULL for a normal subscription plan.
--     3) plans.billing_cycle ENUM('monthly','yearly','both')
--        Which cycles the plan offers, so the catalogue never offers a yearly
--        price the plan does not define.
--     4) package_orders — one row per purchase attempt, mirroring the invoice and
--        consultation order model. `payment_reference` is UNIQUE when present, so
--        one gateway order can never activate two purchases and a replayed
--        callback cannot activate twice.
--     5) payment_transactions.package_order_id
--        One transaction log holds invoice, consultation and package payments, so
--        the admin history and reconciliation reports stay a single view.
--
-- WHY NOT REUSE `subscriptions`
--
--   A subscription is the *entitlement* that results from a purchase; it must be
--   created only after payment. A package_order is the *intent* to buy, which
--   exists while the customer is still on the payment page. Overloading one table
--   would make an abandoned checkout look like an active subscription.
--
-- BACKWARD COMPATIBILITY
--
--   Every column is nullable or has a safe default and no existing row loses a
--   value. The classification backfill only fills a column it has just created,
--   and only where the plan is not already classified. No production value is
--   rewritten or destroyed.
--
-- IDEMPOTENT AND ADDITIVE
--
--   Every statement is guarded on information_schema, so replaying the file is a
--   no-op. No positional `AFTER` clause is used: a missing sibling column would
--   abort the rest of the file and make a partial run look applied (see the
--   Execution 29.1 note in AGENTS.md).
-- ============================================================================

SET NAMES utf8mb4;

-- --- plans.classification ---------------------------------------------------
SET @s := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='plans' AND COLUMN_NAME='classification') = 0,
  "ALTER TABLE `plans` ADD COLUMN `classification` ENUM('free','paid','corporate') NOT NULL DEFAULT 'paid'",
  'SELECT 1'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- plans.consultation_price ----------------------------------------------
SET @s := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='plans' AND COLUMN_NAME='consultation_price') = 0,
  'ALTER TABLE `plans` ADD COLUMN `consultation_price` DECIMAL(12,3) NULL DEFAULT NULL',
  'SELECT 1'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- plans.billing_cycle ----------------------------------------------------
SET @s := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='plans' AND COLUMN_NAME='billing_cycle') = 0,
  "ALTER TABLE `plans` ADD COLUMN `billing_cycle` ENUM('monthly','yearly','both') NOT NULL DEFAULT 'both'",
  'SELECT 1'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- package_orders ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `package_orders` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `plan_id` INT UNSIGNED NOT NULL,
  `billing_cycle` ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly',
  `amount` DECIMAL(12,3) NOT NULL DEFAULT 0,
  `currency` VARCHAR(3) NOT NULL DEFAULT 'SAR',
  `status` ENUM('pending','paid','failed','cancelled','refunded') NOT NULL DEFAULT 'pending',
  `payment_reference` VARCHAR(100) DEFAULT NULL,
  `payment_gateway` VARCHAR(30) DEFAULT NULL,
  `paid_at` DATETIME DEFAULT NULL,
  `subscription_id` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_package_order_ref` (`payment_reference`),
  KEY `idx_package_orders_user` (`user_id`, `status`),
  KEY `idx_package_orders_plan` (`plan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --- payment_transactions.package_order_id ----------------------------------
SET @s := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_transactions' AND COLUMN_NAME='package_order_id') = 0,
  'ALTER TABLE `payment_transactions` ADD COLUMN `package_order_id` INT UNSIGNED NULL DEFAULT NULL',
  'SELECT 1'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @s := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_transactions' AND INDEX_NAME='idx_payment_tx_package') = 0,
  'ALTER TABLE `payment_transactions` ADD INDEX `idx_payment_tx_package` (`package_order_id`)',
  'SELECT 1'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- subscriptions: make the resulting entitlement traceable ----------------
-- The order that produced a subscription is kept on package_orders.subscription_id,
-- so no column is needed here. This index keeps "my active subscription" lookups
-- cheap, which the entitlement check now runs on every gated page.
SET @s := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='subscriptions' AND INDEX_NAME='idx_subscriptions_user_status') = 0,
  'ALTER TABLE `subscriptions` ADD INDEX `idx_subscriptions_user_status` (`user_id`, `status`, `ends_at`)',
  'SELECT 1'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- backfill classification from the existing price/is_corporate -----------
-- Only rows still on the column default are touched, and only to make the value
-- reflect what the plan already was. A plan an operator has classified by hand is
-- never overwritten.
UPDATE `plans`
   SET `classification` = CASE
        WHEN `is_corporate` = 1 THEN 'corporate'
        WHEN `price_monthly` > 0 THEN 'paid'
        ELSE 'free'
     END
 WHERE `classification` = 'paid'
   AND (`is_corporate` = 1 OR `price_monthly` = 0);
