-- ============================================================
--  Migration 007 — feed scoring
--
--  Implements docs/feed-algorithm-simple.md. Two kinds of table:
--
--    RAW      article_events, user_hidden_sources — written live
--    ROLLUP   *_stats — rebuilt nightly by jobs/rollup_stats.php
--
--  The split is the whole performance story. The feed request joins the
--  rollups and does arithmetic; it never aggregates events. Serving stays
--  a single indexed query no matter how large article_events grows.
--
--  Safe to re-run.
-- ============================================================

-- ---------- raw events ----------

CREATE TABLE IF NOT EXISTS article_events (
    id          BIGSERIAL PRIMARY KEY,
    user_id     BIGINT      NOT NULL REFERENCES users(id)    ON DELETE CASCADE,
    article_id  BIGINT      NOT NULL REFERENCES articles(id) ON DELETE CASCADE,
    event_type  VARCHAR(20) NOT NULL
                CHECK (event_type IN ('impression','tap','hide_source','not_interested','share')),
    dwell_ms    INT         CHECK (dwell_ms IS NULL OR dwell_ms >= 0),
    position    SMALLINT,
    session_id  VARCHAR(36),
    client_event_id VARCHAR(36),          -- retry key, see below
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- The rollup job scans by time; the feed's not_interested filter looks up by
-- (user, type, article). Both are covered here.
CREATE INDEX IF NOT EXISTS article_events_user_time_idx
    ON article_events (user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS article_events_recent_idx
    ON article_events (created_at DESC);
CREATE INDEX IF NOT EXISTS article_events_negative_idx
    ON article_events (user_id, event_type, article_id)
    WHERE event_type IN ('not_interested', 'hide_source');

-- ---------- deduplication ----------
--
-- The app retries a failed flush, so the same event can arrive twice.
-- Counting it twice corrupts every rate in the system, and silently: the feed
-- still works, it is just ranking on inflated numbers.
--
-- Deduplicating on (user, article, type) is WRONG, because a reader genuinely
-- can open the same article twice and the second open is real evidence. What
-- distinguishes a retry from a repeat is the CLIENT: it stamps each event with
-- an id when the event happens, and a retry carries the same id.
ALTER TABLE article_events
    ADD COLUMN IF NOT EXISTS client_event_id VARCHAR(36);

CREATE UNIQUE INDEX IF NOT EXISTS article_events_client_id_uniq
    ON article_events (user_id, client_event_id)
    WHERE client_event_id IS NOT NULL;

-- Second line of defence, for a client that sends no id at all: one
-- impression per user per article per session. Deliberately not applied to
-- taps, for the reason above.
CREATE UNIQUE INDEX IF NOT EXISTS article_events_impression_uniq
    ON article_events (user_id, article_id, session_id)
    WHERE event_type = 'impression';

-- ---------- explicit negative feedback ----------

-- Separate from article_events because it is STATE, not an event: it must be
-- listable and reversible from Profile. An event log answers "what happened";
-- this answers "what is currently hidden".
CREATE TABLE IF NOT EXISTS user_hidden_sources (
    user_id    BIGINT NOT NULL REFERENCES users(id)         ON DELETE CASCADE,
    source_id  INT    NOT NULL REFERENCES blog_sources(id)  ON DELETE CASCADE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (user_id, source_id)
);

-- ---------- rollups ----------
--
-- rate      the smoothed good-tap rate      (raw, comparable across users)
-- rate_norm that rate divided by the user's own best     (0..1, ready to score)
--
-- Normalising in the ROLLUP rather than at request time is what keeps the
-- feed query free of window functions.

CREATE TABLE IF NOT EXISTS user_category_stats (
    user_id     BIGINT NOT NULL REFERENCES users(id)           ON DELETE CASCADE,
    category_id INT    NOT NULL REFERENCES category_master(id) ON DELETE CASCADE,
    impressions INT    NOT NULL DEFAULT 0,
    good_taps   INT    NOT NULL DEFAULT 0,
    rate        REAL   NOT NULL DEFAULT 0,
    rate_norm   REAL   NOT NULL DEFAULT 0,
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (user_id, category_id)
);

CREATE TABLE IF NOT EXISTS user_source_stats (
    user_id         BIGINT  NOT NULL REFERENCES users(id)        ON DELETE CASCADE,
    source_id       INT     NOT NULL REFERENCES blog_sources(id) ON DELETE CASCADE,
    impressions     INT     NOT NULL DEFAULT 0,
    good_taps       INT     NOT NULL DEFAULT 0,
    rate            REAL    NOT NULL DEFAULT 0,
    rate_norm       REAL    NOT NULL DEFAULT 0,
    -- 14-day window, for the fatigue rule (docs §5.6)
    impressions_14d INT     NOT NULL DEFAULT 0,
    good_taps_14d   INT     NOT NULL DEFAULT 0,
    is_fatigued     BOOLEAN NOT NULL DEFAULT false,
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (user_id, source_id)
);

CREATE TABLE IF NOT EXISTS source_stats (
    source_id    INT  PRIMARY KEY REFERENCES blog_sources(id) ON DELETE CASCADE,
    impressions  INT  NOT NULL DEFAULT 0,
    good_taps    INT  NOT NULL DEFAULT 0,
    rate         REAL NOT NULL DEFAULT 0,
    quality_norm REAL NOT NULL DEFAULT 0,
    updated_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Trending. Only rows with a non-zero score are kept, so this stays tiny
-- however many articles exist.
CREATE TABLE IF NOT EXISTS article_stats (
    article_id    BIGINT PRIMARY KEY REFERENCES articles(id) ON DELETE CASCADE,
    good_taps_24h INT  NOT NULL DEFAULT 0,
    trending_norm REAL NOT NULL DEFAULT 0,
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Drives the cold-start switch at 50 impressions (docs §7).
CREATE TABLE IF NOT EXISTS user_feed_stats (
    user_id     BIGINT PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    impressions INT NOT NULL DEFAULT 0,
    good_taps   INT NOT NULL DEFAULT 0,
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- One row, holding the global smoothing prior. Every rate above is smoothed
-- toward it, so it has to be computed once and read by everything.
CREATE TABLE IF NOT EXISTS feed_globals (
    id           SMALLINT PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    global_rate  REAL NOT NULL DEFAULT 0.05,
    impressions  BIGINT NOT NULL DEFAULT 0,
    good_taps    BIGINT NOT NULL DEFAULT 0,
    computed_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- 0.05 is a deliberate cold prior: assume 5% of shown articles get a real
-- read until the data says otherwise. Too high and every unproven source
-- looks good; too low and nothing can ever climb.
INSERT INTO feed_globals (id) VALUES (1) ON CONFLICT (id) DO NOTHING;
