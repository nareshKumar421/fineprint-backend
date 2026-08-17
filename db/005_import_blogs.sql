-- ============================================================
--  Migration 005 — import validated blogs from Top20_Categories_Blogs.xlsx
--
--  100 feeds in the sheet, 73 of which fetch and parse with the project's
--  own feedlib. Only those 73 are here; the 27 that 404'd, 403'd, timed
--  out or returned invalid XML are deliberately left out rather than
--  imported and left to fail nightly. The failure count on blog_sources
--  exists for feeds that BREAK later, not for ones known broken today.
--
--  The 14 new categories are created is_active = false on purpose.
--  docs/09 §1: a category must not be active until it has >= 5 blogs AND
--  >= 20 recent articles, or a user picks it and gets a blank feed. Run
--  jobs/fetch_feeds.php, then activate the ones that clear the bar.
--
--  blog_url is derived from the feed URL's scheme and host — the sheet
--  only carries feed URLs, and the column is NOT NULL.
--
--  Safe to re-run: feed_url is UNIQUE and every insert is ON CONFLICT
--  DO NOTHING.
-- ============================================================

-- ---------- new categories (INACTIVE until they have content) ----------
INSERT INTO category_master (name, slug, description, icon, display_order, is_active)
VALUES ('News', 'news', NULL, '📰', 7, false)
ON CONFLICT (slug) DO NOTHING;
INSERT INTO category_master (name, slug, description, icon, display_order, is_active)
VALUES ('Finance', 'finance', NULL, '💰', 8, false)
ON CONFLICT (slug) DO NOTHING;
INSERT INTO category_master (name, slug, description, icon, display_order, is_active)
VALUES ('AI', 'ai', NULL, '🤖', 9, false)
ON CONFLICT (slug) DO NOTHING;
INSERT INTO category_master (name, slug, description, icon, display_order, is_active)
VALUES ('Education', 'education', NULL, '🎓', 10, false)
ON CONFLICT (slug) DO NOTHING;
INSERT INTO category_master (name, slug, description, icon, display_order, is_active)
VALUES ('Gaming', 'gaming', NULL, '🎮', 11, false)
ON CONFLICT (slug) DO NOTHING;
INSERT INTO category_master (name, slug, description, icon, display_order, is_active)
VALUES ('Sports', 'sports', NULL, '⚽', 12, false)
ON CONFLICT (slug) DO NOTHING;
INSERT INTO category_master (name, slug, description, icon, display_order, is_active)
VALUES ('Entertainment', 'entertainment', NULL, '🎞', 13, false)
ON CONFLICT (slug) DO NOTHING;
INSERT INTO category_master (name, slug, description, icon, display_order, is_active)
VALUES ('Environment', 'environment', NULL, '🌿', 14, false)
ON CONFLICT (slug) DO NOTHING;
INSERT INTO category_master (name, slug, description, icon, display_order, is_active)
VALUES ('Cybersecurity', 'cybersecurity', NULL, '🔒', 15, false)
ON CONFLICT (slug) DO NOTHING;
INSERT INTO category_master (name, slug, description, icon, display_order, is_active)
VALUES ('Marketing', 'marketing', NULL, '📣', 16, false)
ON CONFLICT (slug) DO NOTHING;
INSERT INTO category_master (name, slug, description, icon, display_order, is_active)
VALUES ('Photography', 'photography', NULL, '📷', 17, false)
ON CONFLICT (slug) DO NOTHING;
INSERT INTO category_master (name, slug, description, icon, display_order, is_active)
VALUES ('Design', 'design', NULL, '🎨', 18, false)
ON CONFLICT (slug) DO NOTHING;
INSERT INTO category_master (name, slug, description, icon, display_order, is_active)
VALUES ('Productivity', 'productivity', NULL, '✅', 19, false)
ON CONFLICT (slug) DO NOTHING;
INSERT INTO category_master (name, slug, description, icon, display_order, is_active)
VALUES ('Open Source', 'open-source', NULL, '🐧', 20, false)
ON CONFLICT (slug) DO NOTHING;

-- ---------- blog sources ----------
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('TechCrunch', 'https://techcrunch.com', 'https://techcrunch.com/feed/', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('The Verge', 'https://www.theverge.com', 'https://www.theverge.com/rss/index.xml', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('Wired', 'https://www.wired.com', 'https://www.wired.com/feed/rss', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('Creative Commons Tech', 'https://creativecommons.org', 'https://creativecommons.org/category/technology/feed/', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('Mozilla Hacks', 'https://hacks.mozilla.org', 'https://hacks.mozilla.org/feed/', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('Calculated Risk', 'https://www.calculatedriskblog.com', 'https://www.calculatedriskblog.com/feeds/posts/default', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('Naked Capitalism', 'https://www.nakedcapitalism.com', 'https://www.nakedcapitalism.com/feed', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('Monevator', 'https://monevator.com', 'https://monevator.com/feed/', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('Of Dollars And Data', 'https://ofdollarsanddata.com', 'https://ofdollarsanddata.com/feed/', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('NPR', 'https://feeds.npr.org', 'https://feeds.npr.org/1001/rss.xml', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('Democracy Now', 'https://www.democracynow.org', 'https://www.democracynow.org/democracynow.rss', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('Global Voices', 'https://globalvoices.org', 'https://globalvoices.org/feed/', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('Medical Xpress', 'https://medicalxpress.com', 'https://medicalxpress.com/rss-feed/', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('OpenAI News', 'https://openai.com', 'https://openai.com/news/rss.xml', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('Google AI Blog', 'https://blog.google', 'https://blog.google/technology/ai/rss/', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('BAIR', 'https://bair.berkeley.edu', 'https://bair.berkeley.edu/blog/feed.xml', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('Hugging Face', 'https://huggingface.co', 'https://huggingface.co/blog/feed.xml', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('Seth Godin', 'https://seths.blog', 'https://seths.blog/feed/', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('37signals', 'https://world.hey.com', 'https://world.hey.com/dhh/feed.atom', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('Small Business Trends', 'https://smallbiztrends.com', 'https://smallbiztrends.com/feed/', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('ScienceDaily', 'https://www.sciencedaily.com', 'https://www.sciencedaily.com/rss/all.xml', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('Open Education', 'https://creativecommons.org', 'https://creativecommons.org/category/open-education/feed/', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('eLearning Industry', 'https://elearningindustry.com', 'https://elearningindustry.com/feed', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('TeachThought', 'https://www.teachthought.com', 'https://www.teachthought.com/feed/', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('Rock Paper Shotgun', 'https://www.rockpapershotgun.com', 'https://www.rockpapershotgun.com/feed', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('Kotaku', 'https://kotaku.com', 'https://kotaku.com/rss', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('Game Developer', 'https://www.gamedeveloper.com', 'https://www.gamedeveloper.com/rss.xml', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('Indie Games Plus', 'https://indiegamesplus.com', 'https://indiegamesplus.com/feed/', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('Nomadic Matt', 'https://www.nomadicmatt.com', 'https://www.nomadicmatt.com/feed/', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('Uncornered Market', 'https://uncorneredmarket.com', 'https://uncorneredmarket.com/feed/', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('Atlas Obscura', 'https://www.atlasobscura.com', 'https://www.atlasobscura.com/feeds/latest', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('Camping Blogger', 'https://www.campingforwomen.com', 'https://www.campingforwomen.com/feed/', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('Minimalist Baker', 'https://minimalistbaker.com', 'https://minimalistbaker.com/feed/', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('Open Food Facts Blog', 'https://blog.openfoodfacts.org', 'https://blog.openfoodfacts.org/en/feed', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('101 Cookbooks', 'https://www.101cookbooks.com', 'https://www.101cookbooks.com/feed', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('Smitten Kitchen', 'https://smittenkitchen.com', 'https://smittenkitchen.com/feed/', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('Yahoo Sports', 'https://sports.yahoo.com', 'https://sports.yahoo.com/rss/', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('SB Nation', 'https://www.sbnation.com', 'https://www.sbnation.com/rss/index.xml', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('CyclingTips', 'https://cyclingtips.com', 'https://cyclingtips.com/feed/', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('Ultrarunnerpodcast', 'https://ultrarunnerpodcast.com', 'https://ultrarunnerpodcast.com/feed/', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('AV Club', 'https://www.avclub.com', 'https://www.avclub.com/rss', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('Polygon', 'https://www.polygon.com', 'https://www.polygon.com/rss/index.xml', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('IndieWire', 'https://www.indiewire.com', 'https://www.indiewire.com/feed/', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('Open Culture', 'https://www.openculture.com', 'https://www.openculture.com/feed', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('Film Inquiry', 'https://www.filminquiry.com', 'https://www.filminquiry.com/feed/', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('Mongabay', 'https://news.mongabay.com', 'https://news.mongabay.com/feed/', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('Earth.org', 'https://earth.org', 'https://earth.org/feed/', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('Grist', 'https://grist.org', 'https://grist.org/feed/', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('Carbon Brief', 'https://www.carbonbrief.org', 'https://www.carbonbrief.org/feed/', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('Krebs on Security', 'https://krebsonsecurity.com', 'https://krebsonsecurity.com/feed/', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('Schneier on Security', 'https://www.schneier.com', 'https://www.schneier.com/feed/atom/', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('The Hacker News', 'https://feeds.feedburner.com', 'https://feeds.feedburner.com/TheHackersNews', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('Trail of Bits', 'https://blog.trailofbits.com', 'https://blog.trailofbits.com/feed/', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('Mozilla Security', 'https://blog.mozilla.org', 'https://blog.mozilla.org/security/feed/', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('HubSpot', 'https://blog.hubspot.com', 'https://blog.hubspot.com/marketing/rss.xml', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('Moz Blog', 'https://moz.com', 'https://moz.com/blog/feed', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('Copyblogger', 'https://copyblogger.com', 'https://copyblogger.com/feed/', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('Buffer', 'https://buffer.com', 'https://buffer.com/resources/feed/', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('PetaPixel', 'https://petapixel.com', 'https://petapixel.com/feed/', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('Fstoppers', 'https://fstoppers.com', 'https://fstoppers.com/rss.xml', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('Creative Commons Open Culture', 'https://creativecommons.org', 'https://creativecommons.org/category/open-culture/feed/', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('Creative Bloq', 'https://www.creativebloq.com', 'https://www.creativebloq.com/feed', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('Smashing Magazine', 'https://www.smashingmagazine.com', 'https://www.smashingmagazine.com/feed/', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('A List Apart', 'https://alistapart.com', 'https://alistapart.com/main/feed/', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('Design Observer', 'https://designobserver.com', 'https://designobserver.com/feed', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('UX Collective', 'https://uxdesign.cc', 'https://uxdesign.cc/feed', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('Asian Efficiency', 'https://www.asianefficiency.com', 'https://www.asianefficiency.com/feed/', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('Productivityist', 'https://productivityist.com', 'https://productivityist.com/feed/', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('Ness Labs', 'https://nesslabs.com', 'https://nesslabs.com/feed', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('opensource.com', 'https://opensource.com', 'https://opensource.com/feed', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('Linux Foundation', 'https://www.linuxfoundation.org', 'https://www.linuxfoundation.org/blog/rss.xml', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('GitHub Blog', 'https://github.blog', 'https://github.blog/feed/', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;
INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
VALUES ('GNOME', 'https://planet.gnome.org', 'https://planet.gnome.org/rss20.xml', 'import:Top20_Categories_Blogs.xlsx')
ON CONFLICT (feed_url) DO NOTHING;

-- ---------- category <-> blog mapping ----------
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'technology' AND b.feed_url = 'https://techcrunch.com/feed/'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'technology' AND b.feed_url = 'https://www.theverge.com/rss/index.xml'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'technology' AND b.feed_url = 'https://www.wired.com/feed/rss'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'technology' AND b.feed_url = 'https://creativecommons.org/category/technology/feed/'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'technology' AND b.feed_url = 'https://hacks.mozilla.org/feed/'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'finance' AND b.feed_url = 'https://www.calculatedriskblog.com/feeds/posts/default'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'finance' AND b.feed_url = 'https://www.nakedcapitalism.com/feed'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'finance' AND b.feed_url = 'https://monevator.com/feed/'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'finance' AND b.feed_url = 'https://ofdollarsanddata.com/feed/'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'news' AND b.feed_url = 'https://feeds.npr.org/1001/rss.xml'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'news' AND b.feed_url = 'https://www.democracynow.org/democracynow.rss'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'news' AND b.feed_url = 'https://globalvoices.org/feed/'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'health-fitness' AND b.feed_url = 'https://medicalxpress.com/rss-feed/'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'ai' AND b.feed_url = 'https://openai.com/news/rss.xml'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'ai' AND b.feed_url = 'https://blog.google/technology/ai/rss/'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'ai' AND b.feed_url = 'https://bair.berkeley.edu/blog/feed.xml'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'ai' AND b.feed_url = 'https://huggingface.co/blog/feed.xml'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'business' AND b.feed_url = 'https://seths.blog/feed/'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'business' AND b.feed_url = 'https://world.hey.com/dhh/feed.atom'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'business' AND b.feed_url = 'https://smallbiztrends.com/feed/'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'science' AND b.feed_url = 'https://www.sciencedaily.com/rss/all.xml'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'education' AND b.feed_url = 'https://creativecommons.org/category/open-education/feed/'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'education' AND b.feed_url = 'https://elearningindustry.com/feed'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'education' AND b.feed_url = 'https://www.teachthought.com/feed/'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'gaming' AND b.feed_url = 'https://www.rockpapershotgun.com/feed'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'gaming' AND b.feed_url = 'https://kotaku.com/rss'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'gaming' AND b.feed_url = 'https://www.gamedeveloper.com/rss.xml'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'gaming' AND b.feed_url = 'https://indiegamesplus.com/feed/'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'travel' AND b.feed_url = 'https://www.nomadicmatt.com/feed/'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'travel' AND b.feed_url = 'https://uncorneredmarket.com/feed/'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'travel' AND b.feed_url = 'https://www.atlasobscura.com/feeds/latest'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'travel' AND b.feed_url = 'https://www.campingforwomen.com/feed/'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'food' AND b.feed_url = 'https://minimalistbaker.com/feed/'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'food' AND b.feed_url = 'https://blog.openfoodfacts.org/en/feed'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'food' AND b.feed_url = 'https://www.101cookbooks.com/feed'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'food' AND b.feed_url = 'https://smittenkitchen.com/feed/'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'sports' AND b.feed_url = 'https://sports.yahoo.com/rss/'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'sports' AND b.feed_url = 'https://www.sbnation.com/rss/index.xml'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'sports' AND b.feed_url = 'https://cyclingtips.com/feed/'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'sports' AND b.feed_url = 'https://ultrarunnerpodcast.com/feed/'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'entertainment' AND b.feed_url = 'https://www.avclub.com/rss'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'entertainment' AND b.feed_url = 'https://www.polygon.com/rss/index.xml'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'entertainment' AND b.feed_url = 'https://www.indiewire.com/feed/'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'entertainment' AND b.feed_url = 'https://www.openculture.com/feed'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'entertainment' AND b.feed_url = 'https://www.filminquiry.com/feed/'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'environment' AND b.feed_url = 'https://news.mongabay.com/feed/'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'environment' AND b.feed_url = 'https://earth.org/feed/'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'environment' AND b.feed_url = 'https://grist.org/feed/'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'environment' AND b.feed_url = 'https://www.carbonbrief.org/feed/'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'cybersecurity' AND b.feed_url = 'https://krebsonsecurity.com/feed/'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'cybersecurity' AND b.feed_url = 'https://www.schneier.com/feed/atom/'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'cybersecurity' AND b.feed_url = 'https://feeds.feedburner.com/TheHackersNews'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'cybersecurity' AND b.feed_url = 'https://blog.trailofbits.com/feed/'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'cybersecurity' AND b.feed_url = 'https://blog.mozilla.org/security/feed/'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'marketing' AND b.feed_url = 'https://blog.hubspot.com/marketing/rss.xml'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'marketing' AND b.feed_url = 'https://moz.com/blog/feed'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'marketing' AND b.feed_url = 'https://copyblogger.com/feed/'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'marketing' AND b.feed_url = 'https://buffer.com/resources/feed/'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'photography' AND b.feed_url = 'https://petapixel.com/feed/'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'photography' AND b.feed_url = 'https://fstoppers.com/rss.xml'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'photography' AND b.feed_url = 'https://creativecommons.org/category/open-culture/feed/'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'design' AND b.feed_url = 'https://www.creativebloq.com/feed'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'design' AND b.feed_url = 'https://www.smashingmagazine.com/feed/'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'design' AND b.feed_url = 'https://alistapart.com/main/feed/'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'design' AND b.feed_url = 'https://designobserver.com/feed'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'design' AND b.feed_url = 'https://uxdesign.cc/feed'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'productivity' AND b.feed_url = 'https://www.asianefficiency.com/feed/'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'productivity' AND b.feed_url = 'https://productivityist.com/feed/'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'productivity' AND b.feed_url = 'https://nesslabs.com/feed'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'open-source' AND b.feed_url = 'https://opensource.com/feed'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'open-source' AND b.feed_url = 'https://www.linuxfoundation.org/blog/rss.xml'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'open-source' AND b.feed_url = 'https://github.blog/feed/'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
INSERT INTO category_blog_map (category_id, blog_source_id)
SELECT c.id, b.id FROM category_master c, blog_sources b
 WHERE c.slug = 'open-source' AND b.feed_url = 'https://planet.gnome.org/rss20.xml'
ON CONFLICT (category_id, blog_source_id) DO NOTHING;
