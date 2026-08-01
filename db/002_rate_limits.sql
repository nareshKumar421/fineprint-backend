-- ============================================================
--  Migration 002 — rate limiting counters
--
--  Not part of the client's original schema.sql. Added because the
--  security checklist (docs/08 §1) requires rate limiting on /api/login
--  and /api/register, and that needs somewhere to count.
--
--  A table rather than APCu: APCu is per-process and off by default in
--  CLI, so it would silently fail to limit anything across PHP-FPM
--  workers. This is correct on one server and still correct on three.
--
--  Safe to re-run.
-- ============================================================

CREATE TABLE IF NOT EXISTS rate_limits (
    bucket       VARCHAR(160) PRIMARY KEY,   -- e.g. 'login:203.0.113.5'
    hits         INT         NOT NULL DEFAULT 0,
    window_start TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Supports the nightly purge below.
CREATE INDEX IF NOT EXISTS rate_limits_window_idx ON rate_limits (window_start);
