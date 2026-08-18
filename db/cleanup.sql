-- ============================================================
--  Scheduled housekeeping. Run nightly from cron:
--
--    30 4 * * * psql "$FEED_DB_URL" -f /var/www/blogfeed/backend/db/cleanup.sql
--
--  Without this the tables grow forever.
-- ============================================================

-- Articles older than 60 days.
--
-- COALESCE is load-bearing. The fetch job stores NULL when a feed
-- carries no publish date, and `NULL < anything` is NULL, not true —
-- so a plain `published_at <` comparison silently never deletes an
-- undated article and they accumulate permanently.
-- See docs/05 §3.6.
DELETE FROM articles
WHERE COALESCE(published_at, fetched_at) < NOW() - INTERVAL '60 days';

-- Seen-article history. Only recent history is needed to stop repeats;
-- this is the fastest-growing table in the schema.
DELETE FROM user_seen_articles
WHERE seen_at < NOW() - INTERVAL '14 days';

-- Interaction events. 90 days is well past the 30-day window the rollups
-- read, and this is now the fastest-growing table in the schema — it gains a
-- row per card that appears on a screen, not per user action.
DELETE FROM article_events
WHERE created_at < NOW() - INTERVAL '90 days';

-- Expired auth tokens.
DELETE FROM user_tokens
WHERE expires_at < NOW();

-- Old fetch-job history. Keep 90 days for trend analysis.
DELETE FROM sync_status
WHERE run_at < NOW() - INTERVAL '90 days';

-- Reclaim space and refresh planner statistics after the deletes.
VACUUM ANALYZE articles;
VACUUM ANALYZE user_seen_articles;
VACUUM ANALYZE article_events;
