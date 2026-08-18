-- ============================================================
--  Migration 006 — activate every category
--
--  Removes the launch gate that 005 applied: a category needed >= 5 blogs
--  AND >= 20 recent articles before it could go live, and anything below
--  that bar was left is_active = false. Eleven of the twenty categories
--  were still hidden under it, including Gaming (213 recent articles) and
--  Sports (150) -- both held back only by having 4 blogs rather than 5.
--
--  The rule was never enforced in code. It lived in the comment at the top
--  of 005 and in the docs/09 §1 checklist, both of which now record that it
--  is gone. This file is what a fresh database needs so it ends up matching
--  production.
--
--  Consequence, stated plainly: a thin category is now reachable. Science
--  has ONE blog, and Food had 5 articles inside the 30-day fresh window at
--  the time this ran. A user who picks only those gets a short feed, drops
--  into the archive quickly, and -- if that single Science blog ever fails
--  five nights running -- an empty one. That is accepted, not overlooked.
--
--  Safe to re-run.
-- ============================================================

UPDATE category_master SET is_active = true WHERE is_active = false;

-- Nothing else has to change. GET /api/categories filters on is_active at
-- the SQL level and the app renders whatever it returns, so the topic list
-- picks these up with no deploy. Icons are served from the database too,
-- so no app release is needed either.
