-- ============================================================
--  Migration 004 — category icon as data, not code
--
--  Until now category_master.icon_name held a NAME ('heart', 'cpu') and
--  the app translated it to a glyph through a hardcoded map in
--  mobile/src/utils/icons.js. That map was the problem: an icon_name the
--  app had never heard of rendered a neutral diamond, and adding a
--  genuinely new icon meant editing the app and shipping a release. The
--  database could describe a category the app could not draw.
--
--  So the glyph itself now lives here. The app renders whatever this
--  column contains and knows nothing about which icons exist.
--
--  VARCHAR(8), not (1): Postgres counts characters, and plenty of emoji
--  are more than one — a variation selector or a ZWJ sequence pushes a
--  single visible mark to 2-7 chars. One is enough today and would be a
--  silent truncation the first time it is not.
--
--  NULL is allowed and means "no icon". The app draws the chip without
--  one and gives the space to the label, so a category inserted without
--  an icon is plain, never broken.
--
--  icon_name is deliberately LEFT IN PLACE. Dropping a column is not
--  reversible and nothing reads it after this migration; drop it in a
--  later migration once this one has been live long enough to trust.
--
--  Safe to re-run.
-- ============================================================

ALTER TABLE category_master ADD COLUMN IF NOT EXISTS icon VARCHAR(8);

-- Backfill from the map the app used to hold, so nothing changes on
-- screen. Only rows that have not been given an icon yet are touched,
-- which is what makes this safe to run twice.
UPDATE category_master
   SET icon = CASE LOWER(icon_name)
                WHEN 'heart'     THEN '♥'
                WHEN 'utensils'  THEN '🍴'
                WHEN 'cpu'       THEN '⌘'
                WHEN 'briefcase' THEN '💼'
                WHEN 'plane'     THEN '✈'
                WHEN 'flask'     THEN '⚗'
                WHEN 'book'      THEN '📖'
                WHEN 'camera'    THEN '📷'
                WHEN 'music'     THEN '♪'
                WHEN 'film'      THEN '🎬'
                WHEN 'globe'     THEN '🌍'
                WHEN 'leaf'      THEN '🌿'
                WHEN 'home'      THEN '⌂'
                WHEN 'star'      THEN '★'
                ELSE NULL
              END
 WHERE icon IS NULL;
