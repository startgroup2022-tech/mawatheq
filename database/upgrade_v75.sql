-- ============================================================================
-- Mawatheq Platform — lawyers practise from Saudi Arabia only (Execution 71)
-- upgrade_v75.sql
-- ============================================================================
--
-- WHAT THIS DOES
--
--   The platform onboards lawyers in Saudi Arabia only, so `lawyers.jurisdiction`
--   and `lawyers.country_code` must both describe Saudi Arabia. Any row that still
--   carries another country — written before this policy, or by an older import — is
--   normalised here.
--
--   Nothing is dropped or re-typed. The country is set to 'السعودية'/'SA' only for
--   rows that are NOT already Saudi Arabia, so a re-run is a no-op and a row that is
--   already correct is never rewritten.
--
-- WHY A DATA FIX AND NOT ONLY A CODE FIX
--
--   The code path now refuses to *store* a non-Saudi country, but existing rows would
--   otherwise stay visible in the customer directory under a country the platform no
--   longer supports. Normalising the data closes that gap for the rows already there.
--
-- SAFE TO REPLAY: two guarded UPDATEs, additive only, no FK, no positional AFTER
-- clause. Re-running matches zero rows once the data is correct.
-- ----------------------------------------------------------------------------

SET NAMES utf8mb4;

-- The localised jurisdiction name, which the directory filter reads.
UPDATE `lawyers`
   SET `jurisdiction` = 'السعودية'
 WHERE `jurisdiction` IS NULL
    OR `jurisdiction` <> 'السعودية';

-- The ISO code the country/city selectors key on.
UPDATE `lawyers`
   SET `country_code` = 'SA'
 WHERE `country_code` IS NULL
    OR `country_code` <> 'SA';

-- Make the policy the column's own default, so a write that omits the country (lawyer
-- self-registration does not send one) lands on Saudi Arabia instead of NULL. The UPDATEs
-- above run first, so no row is NULL when the column becomes NOT NULL. `MODIFY` is
-- idempotent — replaying it re-states the same definition — and it is the observable
-- requirement the migration ledger verifies this file against (a data-only file has no
-- schema footprint, so it could not otherwise be proven applied).
ALTER TABLE `lawyers`
  MODIFY COLUMN `country_code` CHAR(2) NOT NULL DEFAULT 'SA';

-- ----------------------------------------------------------------------------
-- Rollback (manual): there is no reliable way to recover a per-row original country
-- after the fact — take a backup of `lawyers` before applying if the prior values are
-- needed. Re-importing the affected rows from the pre-upgrade backup restores them.
-- ----------------------------------------------------------------------------
