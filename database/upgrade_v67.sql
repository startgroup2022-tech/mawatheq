-- ============================================================================
-- مواثيق — جلسات الاتصال الصوتي والمرئي داخل التطبيق (Execution 57)
-- MAWATHEQ — in-app audio/video call sessions
-- upgrade_v67.sql
-- ============================================================================
--
-- WHAT THIS ADDS
--
--     1) call_sessions     — one row per attempted call, tied to a consultation.
--                            Carries the room, the provider, the lifecycle state
--                            (ringing/accepted/ended/missed/rejected), the time
--                            bounds and the end reason.
--     2) call_participants — who is allowed in the room, and what they did
--                            (invited/joined/left). This is the authorisation
--                            list; a caller who is not on it is refused a token.
--     3) call_events       — append-only log of every signalling action, so a
--                            disputed call can be reconstructed.
--
-- WHY A ROW PER CALL AND NOT JUST A room_code ON consultations
--
--   `consultations.room_code` is a stable name for the consultation's room. A call
--   is an *event*: it can be retried, missed, rejected and repeated. Collapsing
--   them would lose the call history the brief asks for ("سجل الاتصال المرتبط
--   بالاستشارة") and make "missed call" unrepresentable.
--
-- WHY THE PARTICIPANT LIST IS THE AUTHORISATION
--
--   A short-lived token proves *when* someone may join; the participant row proves
--   *whether* they may. Issuing a token from the consultation row alone would let a
--   participant whose booking was later cancelled still obtain one, because the
--   consultation is unchanged. The participant row is what the token issuer checks,
--   and it is created only from the consultation's own client/lawyer pair.
--
-- WHY `ended_at` AND `duration_seconds` ARE STORED
--
--   A duration computed at render time from NOW() would keep growing after the call
--   ended, and a client that never called `end` would leave a call "active"
--   forever. Both are written once, when the call ends, by the server.
--
-- Additive and idempotent: safe to replay. No positional AFTER clauses.
-- ============================================================================

CREATE TABLE IF NOT EXISTS call_sessions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    consultation_id INT UNSIGNED NOT NULL,
    firm_id INT UNSIGNED DEFAULT NULL,
    room_code VARCHAR(80) NOT NULL,
    call_type ENUM('voice','video') NOT NULL DEFAULT 'video',
    provider VARCHAR(40) NOT NULL DEFAULT 'webrtc',
    -- ringing: created, waiting for the callee. accepted: both may send media.
    -- ended: finished normally. rejected: callee declined. missed: never answered.
    -- cancelled: caller hung up before the callee answered. failed: setup error.
    status ENUM('ringing','accepted','ended','rejected','missed','cancelled','failed') NOT NULL DEFAULT 'ringing',
    initiated_by ENUM('client','lawyer') NOT NULL,
    initiated_by_id INT UNSIGNED NOT NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    answered_at DATETIME DEFAULT NULL,
    ended_at DATETIME DEFAULT NULL,
    duration_seconds INT UNSIGNED DEFAULT NULL,
    end_reason VARCHAR(80) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_call_consultation (consultation_id, id),
    INDEX idx_call_status (status, started_at),
    INDEX idx_call_firm (firm_id),
    CONSTRAINT fk_call_consultation FOREIGN KEY (consultation_id) REFERENCES consultations(id) ON DELETE CASCADE,
    CONSTRAINT fk_call_firm FOREIGN KEY (firm_id) REFERENCES law_firms(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS call_participants (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    call_id INT UNSIGNED NOT NULL,
    participant_type ENUM('client','lawyer') NOT NULL,
    participant_id INT UNSIGNED NOT NULL,
    -- invited: on the authorisation list. joined: entered the room.
    -- left: left the room. declined: refused the call.
    state ENUM('invited','joined','left','declined') NOT NULL DEFAULT 'invited',
    joined_at DATETIME DEFAULT NULL,
    left_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_call_participant (call_id, participant_type, participant_id),
    INDEX idx_call_participant_lookup (participant_type, participant_id, call_id),
    CONSTRAINT fk_call_participant_call FOREIGN KEY (call_id) REFERENCES call_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS call_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    call_id INT UNSIGNED NOT NULL,
    event_type VARCHAR(40) NOT NULL,
    actor_type ENUM('client','lawyer','system') NOT NULL DEFAULT 'system',
    actor_id INT UNSIGNED DEFAULT NULL,
    detail VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_call_event (call_id, id),
    CONSTRAINT fk_call_event_call FOREIGN KEY (call_id) REFERENCES call_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
