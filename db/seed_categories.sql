-- ============================================================
--  Starter topics for the interests screen.
--  Safe to re-run: ON CONFLICT (slug) DO NOTHING.
--
--  A category must NOT be left active unless it has >= 5 blogs
--  and >= 20 recent articles — see docs/09 §1. An active category
--  with no content gives users a blank feed, and they will not
--  report it, they will just stop opening the app.
-- ============================================================

INSERT INTO category_master (name, slug, description, icon_name, display_order, is_active) VALUES
  ('Health & Fitness', 'health-fitness', 'Wellness, exercise and nutrition',  'heart',     1, true),
  ('Food',             'food',           'Recipes and cooking',               'utensils',  2, true),
  ('Technology',       'technology',     'Software, gadgets and the web',     'cpu',       3, true),
  ('Business',         'business',       'Startups, work and money',          'briefcase', 4, true),
  ('Travel',           'travel',         'Destinations and travel writing',   'plane',     5, true),
  ('Science',          'science',        'Research and discovery',            'flask',     6, true)
ON CONFLICT (slug) DO NOTHING;
