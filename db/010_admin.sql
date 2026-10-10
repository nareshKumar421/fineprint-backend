-- ============================================================
--  Migration 010 — administration: roles, audit, settings
--
--  The application has had no admin concept at all. `users` held readers
--  and nothing else, and every operational change — activating a category,
--  reviving a deactivated blog, deleting an account — was hand-written SQL
--  against production. This is the schema that ends that.
--
--  Reader accounts are REUSED rather than replaced. They already carry
--  bcrypt password hashes and work with the existing token system, so a
--  second identity store would mean a second auth path to keep correct for
--  no gain. An admin is a reader with a role.
--
--  Safe to re-run.
-- ============================================================

-- ---------- roles ----------
--
-- Deliberately a CHECK, not a separate roles table. Two values with no
-- per-role data behind them is a column; a join table here would be
-- ceremony. Revisit if permissions ever become granular.
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS role VARCHAR(16) NOT NULL DEFAULT 'reader';

DO $$
BEGIN
    ALTER TABLE users ADD CONSTRAINT users_role_chk
        CHECK (role IN ('reader', 'admin'));
EXCEPTION WHEN duplicate_object THEN NULL;
END $$;

-- Partial: there will be a handful of admins among many readers, and this
-- index is only ever used to find them.
CREATE INDEX IF NOT EXISTS users_admin_idx ON users (id) WHERE role = 'admin';

-- ---------- browser sessions ----------
--
-- SEPARATE from user_tokens, which belongs to the mobile app.
--
-- They are not the same thing and must not share a table. A mobile token is
-- a long-lived bearer credential sent in a header; a dashboard session is a
-- short-lived cookie that must be bound to its browser and revocable on its
-- own. Sharing the table would mean logging out of the dashboard kills the
-- phone, and a leaked dashboard cookie would carry API rights.
--
-- The id is stored HASHED, exactly as user_tokens does, so a database leak
-- does not hand over live sessions.
CREATE TABLE IF NOT EXISTS admin_sessions (
    id            BIGSERIAL PRIMARY KEY,
    user_id       BIGINT      NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    session_hash  VARCHAR(64) NOT NULL UNIQUE,
    csrf_hash     VARCHAR(64) NOT NULL,
    ip            VARCHAR(45),
    user_agent    TEXT,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    last_seen_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    expires_at    TIMESTAMPTZ NOT NULL
);

CREATE INDEX IF NOT EXISTS admin_sessions_expiry_idx ON admin_sessions (expires_at);
CREATE INDEX IF NOT EXISTS admin_sessions_user_idx   ON admin_sessions (user_id);

-- ---------- audit log ----------
--
-- Every administrative write records who, what, and the before/after. The
-- point is answering "who disabled that feed and when" three weeks later,
-- which no amount of application logging has ever managed.
--
-- user_id is ON DELETE SET NULL: deleting an admin must not erase the
-- record of what they did. That is the opposite of the personal-data
-- cascade on reader rows, and deliberate.
CREATE TABLE IF NOT EXISTS audit_log (
    id          BIGSERIAL PRIMARY KEY,
    user_id     BIGINT REFERENCES users(id) ON DELETE SET NULL,
    actor_email VARCHAR(255),              -- denormalised, survives deletion
    action      VARCHAR(64)  NOT NULL,     -- 'source.disable', 'post.publish'
    entity      VARCHAR(64),               -- 'blog_sources'
    entity_id   TEXT,
    before_json JSONB,
    after_json  JSONB,
    ip          VARCHAR(45),
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS audit_log_time_idx   ON audit_log (created_at DESC);
CREATE INDEX IF NOT EXISTS audit_log_entity_idx ON audit_log (entity, entity_id);
CREATE INDEX IF NOT EXISTS audit_log_actor_idx  ON audit_log (user_id, created_at DESC);

-- ---------- settings ----------
--
-- Values an administrator may change without a deploy. Typed, because a
-- settings table that stores everything as text ends up with 'true', 'True'
-- and '1' all meaning the same thing and nothing validating any of them.
CREATE TABLE IF NOT EXISTS app_settings (
    key         VARCHAR(64) PRIMARY KEY,
    value       TEXT NOT NULL,
    value_type  VARCHAR(16) NOT NULL DEFAULT 'string'
                CHECK (value_type IN ('string', 'int', 'bool', 'json')),
    description TEXT,
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_by  BIGINT REFERENCES users(id) ON DELETE SET NULL
);

-- Seeded from the constants that are currently compiled into PHP. Changing
-- one here does NOT yet change behaviour — the code still reads its own
-- constants. They are registered so the dashboard can show what the system
-- is running on; wiring each one up is a separate, deliberate step per
-- setting, because several are load-bearing (see docs/feed-algorithm-simple).
INSERT INTO app_settings (key, value, value_type, description) VALUES
  ('sync.enabled',        'true', 'bool', 'Master switch for scheduled feed ingestion'),
  ('sync.interval_hours', '24',   'int',  'Hours between scheduled syncs'),
  ('feed.max_failures',   '5',    'int',  'Consecutive failures before a source is deactivated'),
  ('feed.retention_days', '60',   'int',  'Days an article is kept before purging'),
  ('feed.excerpt_chars',  '200',  'int',  'Excerpt truncation length'),
  ('admin.session_hours', '12',   'int',  'Dashboard session lifetime')
ON CONFLICT (key) DO NOTHING;
