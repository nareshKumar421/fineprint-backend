-- ============================================================
--  Starter topics for the interests screen.
--  Safe to re-run: ON CONFLICT (slug) DO NOTHING.
--
--  A category must NOT be left active unless it has >= 5 blogs
--  and >= 20 recent articles — see docs/09 §1. An active category
--  with no content gives users a blank feed, and they will not
--  report it, they will just stop opening the app.
-- ============================================================

--  icon holds the glyph itself. Pick any character you like when adding a
--  category — the app draws whatever is here and has no list to update.
INSERT INTO category_master (name, slug, description, icon, display_order, is_active) VALUES
  ('Health & Fitness', 'health-fitness', 'Wellness, exercise and nutrition',  '♥',  1, true),
  ('Food',             'food',           'Recipes and cooking',               '🍴', 2, true),
  ('Technology',       'technology',     'Software, gadgets and the web',     '⌘',  3, true),
  ('Business',         'business',       'Startups, work and money',          '💼', 4, true),
  ('Travel',           'travel',         'Destinations and travel writing',   '✈',  5, true),
  ('Science',          'science',        'Research and discovery',            '⚗',  6, true)
ON CONFLICT (slug) DO NOTHING;
