# How the feed works

End-to-end behaviour of the feed, from the cron job that collects articles to
the card the reader scrolls past. Two halves that share nothing but the
`articles` table:

| Half | Runs | Code |
|---|---|---|
| **Ingestion** | nightly, from cron, no user present | `jobs/fetch_feeds.php`, `jobs/feedlib.php` |
| **Serving** | on every app open and every scroll | `src/Controllers/FeedController.php`, `src/Services/FeedService.php`, `mobile/src/screens/FeedScreen.js` |

```
blogs' RSS/Atom ──▶ fetch_feeds.php ──▶ articles ──▶ GET /api/feed ──▶ FeedScreen
   (nightly)          parse + dedupe      table       fresh/archive      FlatList
```

---

## 1. Ingestion — how articles get in

### 1.1 What the job iterates over

`fetch_feeds.php` selects **one row per blog + category pair**, not per blog:

```sql
SELECT COALESCE(m.feed_url_override, b.feed_url) AS feed_url, ...
FROM category_blog_map m
JOIN blog_sources b ON b.id = m.blog_source_id
WHERE b.is_active = true
```

So one blog can feed two categories from two different section feeds
(`Health → /category/fitness/feed`, `Food → /category/recipes/feed`). A blog
listed in three categories is fetched three times — that is intentional, and
why the dedupe key includes `category_id`.

Between blogs the job sleeps 500 ms. Politeness, not throttling.

### 1.2 Download

`feed_fetch()` (cURL): follows up to 5 redirects, 15 s timeout, 10 s connect
timeout, gzip accepted, TLS verified, and a **non-empty User-Agent** — some
hosts return 403 for a blank one and the feed looks dead for no visible
reason.

Anything other than HTTP 200, a network error, or an empty body throws.

The **post-redirect URL is written back** to `blog_sources.feed_url` when it
differs, so future runs skip the hop — but only when that mapping has no
`feed_url_override`, because an override is a human's deliberate choice. The
write is wrapped in its own try/catch since `feed_url` is `UNIQUE` and another
blog may already hold the resolved URL; that is not worth failing a run over.

### 1.3 Parsing

`feed_parse()` handles three shapes:

| Shape | Detected by | Items |
|---|---|---|
| RSS 2.0 (WordPress) | `$xml->channel->item` | `<item>` |
| RSS 1.0 / RDF | `$xml->item` | `<item>` |
| Atom | `$xml->entry` | `<entry>` |

Per item:

- **title / url** — Atom keeps the link in `<link href="">`, not the node text.
  An item missing either is skipped as useless.
- **excerpt** — first non-empty of `description`, `summary`, `content:encoded`,
  `content`, then `clean_excerpt()`: strip tags, decode entities, collapse
  whitespace, truncate to `FEED_EXCERPT_CHARS` (default 200) on a word
  boundary, mb-safe. **Truncation is a licensing requirement, not
  politeness** — many feeds carry the full article body and storing it is the
  copyright problem the whole position avoids.
- **image_url** — `media:content` / `media:thumbnail` `url` attribute, then
  `<enclosure>` of an image type, then the first `<img src>` in the body.
  Never throws; a missing thumbnail is a `NULL`, not a failed feed.
- **author** — `dc:creator`, falling back to `<author>` (`<author><name>` for
  Atom), capped at 200 chars.
- **published_at** — see below.
- **guid** — `<guid>` / `<id>`, falling back to the article URL.

### 1.4 Dates

`feed_parse_date()` does two non-obvious things:

1. **Strips the RFC 2822 weekday** before `strtotime()`. Feeds get the weekday
   wrong, and PHP does not ignore the mismatch: `"Mon, 28 Jul 2026"` when the
   28th is a Tuesday resolves to **3 August** — the article silently moves up
   to six days into the future.
2. **Clamps future dates** more than 24 h ahead to `now`. A CMS with a bad
   clock would otherwise pin one post to the top of every feed permanently.

Everything is stored via `gmdate()` — **UTC, always**.

### 1.5 The rule that everything else depends on

> A feed that parses cleanly but yields **zero usable articles is a FAILURE**,
> not a success.

`feed_parse()` throws in that case. If it returned `[]` instead, the caller
would record success, reset `failure_count` to 0, and a permanently broken
blog would never reach 5 consecutive failures, never deactivate, never alert
anyone — showing up in the logs as a healthy run that found "0 new articles".

### 1.6 Storing

```sql
INSERT INTO articles (...) VALUES (...)
ON CONFLICT (guid, category_id) DO NOTHING
```

Deduplication is the unique index `articles_guid_cat_uniq`, so:

- The job is **safe to run more often than scheduled** — extra runs cost
  bandwidth only.
- Overlapping runs cannot race.
- **First insert wins.** An edited headline upstream does not overwrite what
  was stored.
- The same article appearing in two of the blog's category feeds is stored
  **once per category**, which is what makes per-category serving work.

`$stmt->rowCount()` is 0 for a conflict, so "new" counts are accurate.

### 1.7 Failure handling and deactivation

Per blog:

- **Success** → `last_fetched_at = NOW()`, `failure_count = 0`,
  `last_error = NULL`.
- **Failure** → `failure_count + 1`, `last_error` = the exception message
  (truncated to 500 chars), and once `failure_count >= FEED_MAX_FAILURES`
  (default 5) `is_active = false` plus an `ALERT` log line. A deactivated blog
  drops out of the job's SELECT entirely and needs a human to restore.

**One bad blog never stops the other forty.** Everything is caught per blog.

Dropped database connections are handled separately, and deliberately are
**not** counted against the blog. Managed Postgres reaps connections that sit
idle, and this job's connection is idle for most of its run (the wall clock is
mostly HTTP). So:

- The DSN gains `keepalives`, `connect_timeout` and a 30 s server-side
  `statement_timeout` unless the operator already set them — without these a
  dead socket blocks on the OS TCP retry limit, observed as a 16-minute silent
  hang.
- `db_connection_lost()` pattern-matches the message (pdo_pgsql reports all of
  these as `HY000`, so there is nothing else to match on).
- On a drop: reconnect (3 attempts, backing off, since a scaled-to-zero
  database needs a moment to wake), rebuild the prepared statements — they die
  with their connection — and **retry the same feed**, up to 3 times.
- If even recording a failure fails, it reconnects once and retries that write;
  worst case it logs a warning and moves on to the next blog.

### 1.8 End of run

- **Purge**: `DELETE FROM articles WHERE COALESCE(published_at, fetched_at) <
  NOW() - INTERVAL '60 days'`. The `COALESCE` is load-bearing — `NULL <
  anything` is `NULL`, not true, so a plain comparison never deletes an undated
  article and they accumulate forever.
- **`sync_status` row**: ok / failed / added / duration. This is what the
  health endpoint reads, so it is written even after a connection loss
  forced the loop to stop early — the articles already fetched are committed,
  and skipping the row makes a healthy run look stale.

Exit codes: `0` all feeds OK, `2` at least one failed (or a partial run), `1`
fatal (no config, cannot connect at all).

`db/cleanup.sql` runs separately at 04:30 and additionally trims
`user_seen_articles` older than 14 days, expired tokens, and `sync_status`
older than 90 days.

### 1.9 Adding a blog

`find_feed.php <url>` reads the homepage, prefers `<link rel="alternate">`
autodiscovery, and otherwise tries `/feed/`, `/feed`, `/rss/`, `/?feed=rss2`,
`/atom.xml`, `/rss.xml`, `/index.xml`, `/feeds/posts/default`. Candidates whose
URL contains "comment" are ranked **last** — WordPress declares its comments
feed in the same block, it is valid RSS, and accepting it ingests reader
comments as articles.

It validates with the same `feedlib.php` the job uses (so the two can never
disagree about what a working feed is) and **prints SQL rather than writing
it** — adding a source stays a deliberate human action.

---

## 2. Serving — how the feed is built

`GET /api/feed[?limit=][&cursor=]`, authenticated. `limit` defaults to 20 and
is capped at 50.

The endpoint has **two phases**, and the phase is named in the response:

| Phase | When | Content | Order |
|---|---|---|---|
| `fresh` | no cursor | articles the user has **not** seen, last 30 days | weighted shuffle |
| `archive` | a cursor is sent, **or** fresh came back empty | everything in the user's categories, seen or not | strict newest-first |

### 2.1 Fresh — candidate selection

```sql
FROM user_categories uc
CROSS JOIN LATERAL (
    SELECT r.* FROM (
        SELECT ... FROM articles ar
         WHERE ar.category_id = uc.category_id
           AND ar.published_at > NOW() - INTERVAL '30 days'
         ORDER BY ar.published_at DESC
         LIMIT 200                      -- PER_CATEGORY_SCAN
    ) r
    WHERE NOT EXISTS (SELECT 1 FROM user_seen_articles s
                       WHERE s.user_id = uc.user_id AND s.article_id = r.id)
    LIMIT 50                            -- PER_CATEGORY_KEEP
) a
```

Measured against 50,000 articles:

| Shape | Time | Plan |
|---|---|---|
| naive `IN (...)` | 32.5 ms | Seq Scan |
| LATERAL, no inner limit | 22.2 ms | Bitmap Index Scan |
| LATERAL with inner LIMIT | **18.2 ms** | Bitmap Index Scan, early stop |

Two things make it fast: `LATERAL` does one indexed lookup **per category**
instead of one filter across the whole table, and the inner `LIMIT` caps the
work before the seen-articles anti-join, which otherwise forces the planner to
materialise every candidate row.

Counter-intuitive but measured: **dropping the 30-day window makes it slower**
(29.5 ms), not faster — it narrows the index range scan. Do not "optimise" it
away.

Taking N per category is also the right product shape: a balanced mix, rather
than one prolific blog dominating the feed.

**Consequence to know:** `published_at > NOW() - INTERVAL '30 days'` is `NULL`
for an undated article, which is not true, so **undated articles never appear
in the fresh phase at all** — a blog whose every post is undated contributes
nothing, silently. That is why `find_feed.php` warns about "no publish dates"
and why such blogs should simply not be added. (`UNDATED_AGE_DAYS` in the
ranking code exists so they *sink* rather than *float* if the window is ever
relaxed.)

### 2.2 Fresh — ranking

Weighted sampling without replacement:

```
ageDays = (now - published_at) / 86400
weight  = 1 / (1 + ageDays)
key     = -log(u) / weight        u ∈ (0,1) exclusive
sort ascending by key, take `limit`
```

An exponential random key scaled by weight. A same-day post is roughly **7×**
more likely to surface than a week-old one, but **nothing is guaranteed
first** — so the feed looks different on every pull instead of reading as a
static list. `u` is forced away from 0 because `log(0)` is `-INF` and would
always sort to the top. `mt_rand()` is fine here: presentation, not security.

### 2.3 Fresh — seen tracking

Everything returned is immediately written to `user_seen_articles`
(`ON CONFLICT DO NOTHING`), which is what stops the same article coming back on
the next pull. That write **swallows its own errors** on purpose: worst case a
user sees an article twice, which beats a 500 on the app's main screen.

Because `cleanup.sql` prunes seen history after 14 days, articles do
eventually become eligible again — but they are usually past the 60-day purge
or the 30-day window by then.

### 2.4 Archive

Fresh deliberately excludes seen articles, so a user who scrolls to the end
would hit a **blank screen** until the next cron run — which reads as a broken
app, not as "you're up to date". So when fresh runs dry, `FeedController` falls
straight through to `archive()` rather than returning `[]`.

Archive is **ordered, not shuffled** — the user is browsing a back catalogue
and a stable order is what makes paging through it coherent — and uses
**keyset** pagination:

```sql
AND (?::timestamptz IS NULL OR (a.published_at, a.id) < (?::timestamptz, ?::bigint))
ORDER BY a.published_at DESC, a.id DESC
LIMIT ? + 1
```

Not `OFFSET`: `OFFSET 200` makes PostgreSQL walk and discard 200 rows every
page (deep scrolling gets progressively slower), and a row inserted mid-scroll
shifts every subsequent page, duplicating or skipping articles. Comparing
against the last row seen has neither problem.

The cursor is `"<iso8601>|<id>"`. The `+1` row is how `has_more` is known
without a second `COUNT`. A **malformed cursor restarts from the top** rather
than 500ing. An empty archive is valid and not an error — it means the user's
categories genuinely contain nothing.

### 2.5 Response

```json
{
  "articles": [{
    "id": 1, "title": "...", "excerpt": "...", "url": "...",
    "image_url": "...", "source": "Blog name", "category": "Health",
    "published_at": "2026-07-28T03:44:00Z"
  }],
  "phase": "fresh",
  "next_cursor": null,
  "has_more": true,
  "generated_at": "2026-07-28T09:00:00Z"
}
```

Dates go out as **ISO 8601 UTC**; the app renders "3 hours ago" itself, because
a pre-formatted relative string is wrong the moment the user scrolls for a
minute.

In the `fresh` phase `has_more` is always `true` and `next_cursor` is `null` —
more fresh articles may remain, and the app finds out by simply asking again,
since seen-tracking excludes what was already shown.

---

## 3. The app — how it behaves on screen

`mobile/src/screens/FeedScreen.js`, a `FlatList` of `ArticleCard`s.

### 3.1 Initial load and refresh

On mount (and again whenever the Interests screen hands back a new
`refreshAt`, which is how saving topics updates a feed already on screen) it
fires `getMyCategories()` and `getFeed(PAGE_SIZE)` in parallel, resets
`cursor` to `null` and `hasMore` to `true`, and caches the result. Pull-to-
refresh runs the same path.

### 3.2 Pagination state

- `cursor` — `null` while still on FRESH, a `"<iso8601>|<id>"` string once
  paging the ARCHIVE.
- `hasMore` — set **only** from the server's `has_more`. It is the single stop
  condition, and the only thing that shows "End of feed".
- `loadingMoreRef` — a ref guard, because `FlatList` fires `onEndReached`
  several times during one fling and duplicate requests would burn through the
  feed.

### 3.3 The fresh → archive handoff

The one genuinely tricky piece. While `cursor` is null the server sends unseen
articles; when those run out it falls through to the archive **starting from
the newest article**, which is very likely already on screen. Taking that page
would show duplicates, and after de-duplication would look like an empty page
and stop the scroll early.

So on that transition (`!cursor && page.phase === 'archive'`) the app
**discards the server's page** and re-asks from the oldest article *it* is
currently showing — the true continuation point. Because fresh articles are
shuffled, "oldest" is a scan over the list, not the last element.

Appends are also de-duplicated by id against what is already rendered.

### 3.4 Offline

On `NETWORK_ERROR` / `TIMEOUT`, the app loads the last saved feed from
AsyncStorage (`blogfeed.cache.feed.v1`, capped at `CACHE_MAX_ARTICLES`) and
shows it under a visible **"Offline — showing articles saved earlier"** banner.
Cached content is never passed off as fresh, and while `fromCache` is set
pagination is disabled — there is nothing to page.

AsyncStorage is correct for articles and wrong for the auth token: headlines
are public content anyone can read on the publisher's site; a token is a live
session, and lives in the Keychain. A corrupt cache reads as "no cache" rather
than crashing on launch.

On a hard failure mid-scroll, paging stops but **what is already on screen is
kept** — a populated feed is never replaced with an error.

### 3.5 Empty states — three reasons, three messages

| Condition | Message |
|---|---|
| no categories chosen | "Pick a few topics" → Interests |
| an error | "Something went wrong" / "You're offline" → Try again |
| categories chosen, no articles | "Nothing here yet — the overnight job adds new ones every morning" |

"End of feed" appears **only** once the server has confirmed the archive is
exhausted, never merely because a page came back empty.

---

## 4. Configuration

| Key | Where | Default | Effect |
|---|---|---|---|
| `FEED_EXCERPT_CHARS` | backend `.env` | 200 | excerpt truncation length |
| `FEED_HTTP_TIMEOUT` | backend `.env` | 15 | per-feed download timeout (s) |
| `FEED_USER_AGENT` | backend `.env` | `BlogFeedApp/1.0 …` | never leave blank |
| `FEED_MAX_FAILURES` | backend `.env` | 5 | consecutive failures before deactivation |
| `WINDOW_DAYS` | `FeedService` | 30 | fresh-phase recency window |
| `PER_CATEGORY_SCAN` / `KEEP` | `FeedService` | 200 / 50 | candidates per category |
| `DEFAULT_LIMIT` / `MAX_LIMIT` | `FeedController` | 20 / 50 | page size |
| `FEED_PAGE_SIZE` | mobile `.env` | 20 | requested page size (must be ≤ 50) |
| `CACHE_MAX_ARTICLES` | mobile `.env` | — | offline cache cap |

Retention: articles 60 days, seen history 14 days, `sync_status` 90 days.

---

## 5. Invariants — do not break these

1. A feed that parses but yields **zero articles is a failure**. Ingestion,
   validation and alerting all rest on this.
2. `find_feed.php` and `fetch_feeds.php` must agree on what a usable feed is —
   that is why both go through `feedlib.php`.
3. All dates stored and served in **UTC**.
4. **Excerpts only, never full article text.**
5. `COALESCE(published_at, fetched_at)` in every purge — `NULL <` comparisons
   silently never match.
6. Dedupe is `(guid, category_id)`, never `guid` alone.
7. The 30-day window in the candidate query is a **performance** feature as
   well as a product one. Removing it makes the query slower.
8. `has_more` from the server is the only stop condition in the app.
9. A `feed_url_override` is a human decision and is never overwritten by
   redirect resolution.
