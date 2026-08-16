-- ============================================================
--  Starter blog sources and their category wiring.
--  Safe to re-run: ON CONFLICT (feed_url) / (category_id, blog_source_id)
--  DO NOTHING.
--
--  Categories are looked up by SLUG, never by hard-coded id — the ids
--  differ between databases and a wrong one wires a blog to the wrong
--  topic silently.
--
--  A category must NOT be left active unless it has >= 5 blogs and
--  >= 20 recent articles (docs/09 §1). The four blogs below do not
--  meet that bar on their own; add more before launch.
-- ============================================================

INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by) VALUES
  ('MyFitnessPal Blog',   'https://blog.myfitnesspal.com/', 'https://blog.myfitnesspal.com/feed/', 'naresh-kumar'),
  ('The Cloudflare Blog', 'https://blog.cloudflare.com/',   'https://blog.cloudflare.com/rss/',    'phase2-test'),
  ('The GitHub Blog',     'https://github.blog/',           'https://github.blog/feed/',           'phase2-test'),
  ('WordPress.com News',  'https://wordpress.com/blog',     'https://wordpress.com/blog/feed/',    'phase2-test')
ON CONFLICT (feed_url) DO NOTHING;

INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id
FROM (VALUES
  ('health-fitness', 'https://blog.myfitnesspal.com/feed/'),
  ('technology',     'https://blog.cloudflare.com/rss/'),
  ('technology',     'https://github.blog/feed/'),
  ('business',       'https://wordpress.com/blog/feed/')
) AS pair(slug, feed_url)
JOIN category_master c ON c.slug     = pair.slug
JOIN blog_sources    b ON b.feed_url = pair.feed_url
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
