-- ============================================================
--  Migration 009 — cap how many times an article can be shown
--
--  Reported by a reader: the same articles kept coming back. The fresh
--  phase never repeats — it excludes user_seen_articles outright — but the
--  ARCHIVE phase has no seen-filter at all, by design. Once fresh ran dry it
--  served everything in the reader's topics, seen or not, forever.
--
--  That design existed for a good reason: returning an empty list made the
--  feed go blank after a full scroll, which reads as a broken app. So the
--  fix is not "exclude seen from the archive" — it is a CAP, plus an honest
--  end state when the cap is reached.
--
--  TWO COUNTERS, because they mean different things:
--
--    times_shown   the server sent this article in a page
--    times_viewed  the reader actually had it on screen
--
--  They are not interchangeable. markSeen() marks all 20 articles of a page
--  as shown even when the reader scrolled past three, so counting serves
--  alone burns through the catalogue far faster than a reader experiences
--  it. Real impressions are accurate but only exist once a mobile build
--  with analytics has shipped.
--
--  So the feed uses whichever the reader actually has:
--
--    effective_views = user_feed_stats.impressions > 0
--                      ? times_viewed        -- app reports impressions
--                      : times_shown         -- fall back to serves
--
--  Per user, and it upgrades itself the moment their app starts reporting.
--
--  Safe to re-run.
-- ============================================================

ALTER TABLE user_seen_articles
    ADD COLUMN IF NOT EXISTS times_shown   INT NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS times_viewed  INT NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS last_shown_at TIMESTAMPTZ NOT NULL DEFAULT NOW();

-- The archive's cap check looks up (user_id, article_id) and reads both
-- counters. The primary key already covers the lookup; this makes it an
-- index-only scan by carrying the counters along.
CREATE INDEX IF NOT EXISTS user_seen_counts_idx
    ON user_seen_articles (user_id, article_id)
    INCLUDE (times_shown, times_viewed);

-- Existing rows pre-date the counters. They were shown at least once, which
-- the DEFAULT already records; there is nothing to backfill. Stated so the
-- next person does not go looking for a backfill that was skipped by
-- mistake rather than on purpose.
