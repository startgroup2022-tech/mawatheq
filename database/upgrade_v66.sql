-- ============================================================================
-- مواثيق — محفظة المحامي المالية (Execution 56)
-- MAWATHEQ — lawyer financial wallet
-- upgrade_v66.sql
-- ============================================================================
--
-- WHAT THIS ADDS
--
--   A ledger-backed wallet for every lawyer, so the money a lawyer earns is a
--   recorded fact rather than a number derived at render time.
--
--     1) lawyer_wallets          — one row per lawyer: currency, opening balance,
--                                  payout account details (encrypted), status.
--     2) wallet_transactions     — the append-only ledger. Every credit and debit
--                                  is a row; the balance is the sum, never a
--                                  stored running total that could drift.
--     3) wallet_withdrawals      — a withdrawal request and its review lifecycle.
--     4) wallet_commission_rules — the platform's share, per firm, per service.
--
-- WHY A LEDGER AND NOT A BALANCE COLUMN
--
--   A stored balance is a cache that silently becomes wrong the first time a write
--   half-fails or two requests race. The ledger is the source of truth: the balance
--   is SUM(amount) over the lawyer's rows, computed under the same transaction that
--   wrote them. A payout debits the ledger; it never edits a total. That is also
--   what makes the statement auditable — every figure on the wallet page can be
--   traced to a row an operator can read.
--
-- WHY MINOR UNITS
--
--   `amount_minor` is a BIGINT of the currency's smallest unit (halalas for SAR,
--   fils for BHD). Floats accumulate representation error across a ledger; integers
--   do not. The affiliate engine (v57) made the same choice for the same reason.
--   Conversion happens at the edges via wallet_to_minor()/wallet_from_minor().
--
-- WHY THE COMMISSION IS RESOLVED AT CREDIT TIME
--
--   The rule is read once, when the payment is recorded, and the resulting split is
--   stored on the transaction row (`gross_minor`, `commission_minor`, `net_minor`).
--   If an operator later changes the rate, already-earned money does not move — the
--   rate that applied is on the row. Changing a past commission would be a
--   retrospective pay cut.
--
-- WHY NOTHING IS SEEDED
--
--   No commission rule is inserted. A rate is a commercial decision the operator
--   makes in /admin/wallet-settings.php; inventing one would silently take a cut of
--   a lawyer's fee that nobody agreed to. The default when no rule exists is
--   ZERO commission — the lawyer keeps the whole collected amount — which is the
--   only safe default.
--
-- IDEMPOTENCY / SAFETY
--
--   * CREATE TABLE IF NOT EXISTS throughout, so a replay is a no-op.
--   * No positional AFTER clause names a sibling column: a missing sibling aborts
--     the statement and MySQL stops at the first error, so everything below would
--     silently never run while the file still looked applied (Execution 29).
--   * No existing row is read, rewritten or dropped.
--   * The withdrawal status enum keeps every value at its current index; new values
--     are appended only.
--
-- ============================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- 1) lawyer_wallets — one wallet per lawyer
-- ---------------------------------------------------------------------------
-- `currency` is the wallet's own currency and is fixed at creation. An invoice in
-- a different currency does not silently convert into this wallet: mixing
-- currencies in one integer ledger would sum halalas with fils. The service refuses
-- the mismatch instead, which is the same rule the payment gateway adapter applies.
CREATE TABLE IF NOT EXISTS lawyer_wallets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lawyer_id INT UNSIGNED NOT NULL,
    firm_id INT UNSIGNED DEFAULT NULL,
    currency VARCHAR(3) NOT NULL DEFAULT 'SAR',
    -- Opening balance is a manual adjustment an operator may set when migrating a
    -- lawyer onto the platform. It is a ledger row too (kind='adjustment'), so the
    -- sum stays the single source of truth; this column only records the intent.
    opening_balance_minor BIGINT NOT NULL DEFAULT 0,
    -- Payout account details, encrypted at rest with MAWATHEQ_ENCRYPTION_KEY via
    -- includes/secret_crypto.php. Never returned by the API; the page shows a mask.
    payout_details_encrypted TEXT DEFAULT NULL,
    payout_method VARCHAR(40) DEFAULT NULL,
    payout_holder_name VARCHAR(160) DEFAULT NULL,
    status ENUM('active','frozen','closed') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_lawyer_wallet (lawyer_id),
    KEY idx_wallet_firm (firm_id),
    KEY idx_wallet_status (status),
    CONSTRAINT fk_wallet_lawyer FOREIGN KEY (lawyer_id) REFERENCES lawyers (id) ON DELETE CASCADE,
    CONSTRAINT fk_wallet_firm FOREIGN KEY (firm_id) REFERENCES law_firms (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- 2) wallet_transactions — the append-only ledger
-- ---------------------------------------------------------------------------
-- `amount_minor` is signed: positive credits the lawyer, negative debits.
-- `net_minor` is what the lawyer actually keeps; `commission_minor` is the
-- platform's share of `gross_minor`. They are stored, not derived, because the
-- commission rate is a point-in-time fact.
CREATE TABLE IF NOT EXISTS wallet_transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    wallet_id INT UNSIGNED NOT NULL,
    lawyer_id INT UNSIGNED NOT NULL,
    firm_id INT UNSIGNED DEFAULT NULL,
    currency VARCHAR(3) NOT NULL DEFAULT 'SAR',
    -- Signed amount in minor units. Positive = credit, negative = debit.
    amount_minor BIGINT NOT NULL,
    gross_minor BIGINT NOT NULL DEFAULT 0,
    commission_minor BIGINT NOT NULL DEFAULT 0,
    net_minor BIGINT NOT NULL DEFAULT 0,
    -- 'payment'        a client payment on an invoice this lawyer owns
    -- 'commission'     the platform's share (a debit paired with a payment credit)
    -- 'refund'         a reversal of an earlier credit
    -- 'withdrawal'     money reserved by an approved withdrawal request
    -- 'withdrawal_reversal' a rejected/failed payout returning the reserved money
    -- 'payout'         the payout was actually sent (the reservation becomes final)
    -- 'adjustment'     an operator correction, always with a reason
    kind ENUM('payment','commission','refund','withdrawal','withdrawal_reversal','payout','adjustment')
         NOT NULL,
    -- 'available' money the lawyer may withdraw; 'pending' money not yet cleared.
    -- The distinction is what makes "available balance" honest.
    state ENUM('available','pending') NOT NULL DEFAULT 'available',
    -- Provenance. A ledger row that cannot be traced to an event is not auditable.
    source_type VARCHAR(40) DEFAULT NULL,
    source_id INT UNSIGNED DEFAULT NULL,
    invoice_id INT UNSIGNED DEFAULT NULL,
    payment_id BIGINT UNSIGNED DEFAULT NULL,
    withdrawal_id INT UNSIGNED DEFAULT NULL,
    description VARCHAR(255) DEFAULT NULL,
    -- Set when the row was produced by an automated hook, so a retry of the same
    -- payment cannot double-credit: UNIQUE below rejects the second insert.
    idempotency_key VARCHAR(80) DEFAULT NULL,
    -- Who or what caused it. 'system' for hooks, 'admin' for manual adjustments.
    actor_type ENUM('system','admin','lawyer','client') NOT NULL DEFAULT 'system',
    actor_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_wallet_tx_idempotency (idempotency_key),
    KEY idx_wallet_tx_wallet (wallet_id, id),
    KEY idx_wallet_tx_lawyer_state (lawyer_id, state),
    KEY idx_wallet_tx_kind (kind),
    KEY idx_wallet_tx_invoice (invoice_id),
    KEY idx_wallet_tx_withdrawal (withdrawal_id),
    CONSTRAINT fk_wallet_tx_wallet FOREIGN KEY (wallet_id) REFERENCES lawyer_wallets (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- 3) wallet_withdrawals — a request and its review lifecycle
-- ---------------------------------------------------------------------------
-- The money is reserved by a ledger debit the moment the request is approved, so
-- two approved requests can never spend the same balance. A rejection writes the
-- paired reversal. `paid` is only reachable from `approved`, and only with a
-- transfer reference — the same evidence rule the affiliate payouts use.
CREATE TABLE IF NOT EXISTS wallet_withdrawals (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    wallet_id INT UNSIGNED NOT NULL,
    lawyer_id INT UNSIGNED NOT NULL,
    firm_id INT UNSIGNED DEFAULT NULL,
    amount_minor BIGINT NOT NULL,
    currency VARCHAR(3) NOT NULL DEFAULT 'SAR',
    -- pending   the lawyer asked; no money reserved yet
    -- approved  an admin approved; the money is reserved in the ledger
    -- rejected  an admin refused; nothing was reserved
    -- paid      the transfer was sent; a reference is required
    -- failed    the transfer failed; the reservation is returned
    status ENUM('pending','approved','rejected','paid','failed') NOT NULL DEFAULT 'pending',
    method VARCHAR(40) NOT NULL DEFAULT 'bank_transfer',
    -- Snapshot of the payout account at request time. If the lawyer later edits the
    -- account, the request still describes where the money was meant to go.
    account_snapshot VARCHAR(255) DEFAULT NULL,
    requested_note VARCHAR(500) DEFAULT NULL,
    review_note VARCHAR(500) DEFAULT NULL,
    transfer_reference VARCHAR(120) DEFAULT NULL,
    reviewed_by_admin_id INT UNSIGNED DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    paid_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_wd_wallet (wallet_id, id),
    KEY idx_wd_status (status),
    KEY idx_wd_lawyer (lawyer_id),
    CONSTRAINT fk_wd_wallet FOREIGN KEY (wallet_id) REFERENCES lawyer_wallets (id) ON DELETE CASCADE,
    CONSTRAINT fk_wd_lawyer FOREIGN KEY (lawyer_id) REFERENCES lawyers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- 4) wallet_commission_rules — the platform's share
-- ---------------------------------------------------------------------------
-- Firm-scoped so one firm can have a negotiated rate while the platform default
-- applies elsewhere. `rate_percent` is stored to three decimals, enough for a
-- 2.5% or 0.125% arrangement. A rate of 0 is meaningful and is the default.
CREATE TABLE IF NOT EXISTS wallet_commission_rules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    firm_id INT UNSIGNED DEFAULT NULL,
    -- 'default' applies when no service-specific rule matches.
    service_key VARCHAR(60) NOT NULL DEFAULT 'default',
    rate_percent DECIMAL(6,3) NOT NULL DEFAULT 0.000,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    note VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_commission_scope (firm_id, service_key),
    KEY idx_commission_active (is_active),
    CONSTRAINT fk_commission_firm FOREIGN KEY (firm_id) REFERENCES law_firms (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- 5) notification_preferences — per-account notification choices
-- ---------------------------------------------------------------------------
-- One row per account per channel. A missing row means "the default", which is
-- "on": a new account receives notifications without having to opt in, and an
-- operator reading the table sees only the choices someone actually made.
CREATE TABLE IF NOT EXISTS notification_preferences (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subject_type ENUM('client','lawyer','admin') NOT NULL,
    subject_id INT UNSIGNED NOT NULL,
    -- 'in_app' is the bell in every header; 'email' is outbound mail.
    channel ENUM('in_app','email') NOT NULL DEFAULT 'in_app',
    -- The event key, or 'all' for the master switch for that channel.
    event_key VARCHAR(60) NOT NULL DEFAULT 'all',
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_notif_pref (subject_type, subject_id, channel, event_key),
    KEY idx_notif_pref_subject (subject_type, subject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Verification: the tables must exist now.
-- A migration that reports success on a database that is still broken is worse
-- than no migration (Execution 29.1), so this file ends by failing loudly.
-- ---------------------------------------------------------------------------
SET @n := (SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA=DATABASE()
    AND TABLE_NAME IN ('lawyer_wallets','wallet_transactions','wallet_withdrawals','wallet_commission_rules','notification_preferences'));
SET @msg := CONCAT('VERIFY FAILED: expected 5 wallet/settings tables, found ', @n);
SET @s := IF(@n = 5, 'DO 0', CONCAT('SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''', @msg, ''';'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
