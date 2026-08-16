-- ============================================================
--  Migration 003 — display name
--
--  Users can set a name to be addressed by. Optional: an account is
--  perfectly usable without one, and the app falls back to the part of
--  the email before the @.
--
--  Deliberately NOT NOT NULL and with no default. An empty string and a
--  NULL would mean the same thing to a reader but behave differently in
--  every query, so only NULL is allowed to mean "not set" — the API
--  normalises "" to NULL on the way in.
--
--  Safe to re-run.
-- ============================================================

ALTER TABLE users ADD COLUMN IF NOT EXISTS display_name VARCHAR(80);
