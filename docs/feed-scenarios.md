# Feed behaviour, situation by situation

What the feed actually does when things go right, go wrong, or go strange.
Companion to [feed-behaviour.md](feed-behaviour.md), which explains the
mechanism; this one answers "what happens if…".

Quick map of the moving parts:

```
nightly cron ─▶ fetch_feeds.php ─▶ articles table ─▶ GET /api/feed ─▶ FeedScreen
                (feedlib.php)      (60-day purge)    fresh │ archive   (+ offline cache)
```

- [A. A blog's feed misbehaves](#a-a-blogs-feed-misbehaves)
- [B. The fetch job hits trouble](#b-the-fetch-job-hits-trouble)
- [C. Duplicates, edits and overlap](#c-duplicates-edits-and-overlap)
- [D. A reader uses the app](#d-a-reader-uses-the-app)
- [E. The reader reaches the end](#e-the-reader-reaches-the-end)
- [F. The reader changes their topics](#f-the-reader-changes-their-topics)
- [G. Network and offline](#g-network-and-offline)
- [H. Time passes — retention and staleness](#h-time-passes--retention-and-staleness)
- [I. Bad input and edge cases](#i-bad-input-and-edge-cases)
- [J. Quick reference](#j-quick-reference)

---

## A. A blog's feed misbehaves

### A1. The blog returns 404, 500, or any non-200

`feed_fetch()` throws `http 404`.

- `failure_count + 1`, `last_error = "http 404"`, `last_fetched_at = NOW()`.
- The run continues to the next blog. **One bad blog never stops the other
  forty.**
- Job exit code becomes `2`.
- Readers see nothing unusual — the articles collected on previous nights are
  still there.

### A2. The blog is slow or unreachable

Bounded by `FEED_HTTP_TIMEOUT` (15 s) and a 10 s connect timeout, so a hung
host costs 15 s, not the whole run. Recorded as `network error: …`, same path
as A1.

### A3. The blog returns 403 to our User-Agent

Recorded as `http 403`. This is why `feed_user_agent()` never returns an empty
string — some hosts 403 a blank UA with no explanation and the feed simply
looks dead.

If a specific host still blocks us, override `FEED_USER_AGENT` in `.env`.

### A4. The feed URL redirects

cURL follows up to 5 redirects. The article is fetched successfully from the
final URL, and `blog_sources.feed_url` is **rewritten to the resolved URL** so
tomorrow's run skips the hop.

Two exceptions:

- If that mapping has a `feed_url_override`, nothing is rewritten. An override
  is a human's deliberate choice.
- If another blog already holds the resolved URL, the `UNIQUE` constraint
  rejects the update, it is caught and ignored, and the fetch still counts as a
  success. The extra hop just happens again tomorrow.

Without redirect following at all, both of the client's verified-working
example feeds look dead — so this is not an optional nicety.

### A5. The feed is valid XML but has zero usable articles

**This counts as a FAILURE, not a success.** `feed_parse()` throws
`feed parsed but contained no usable articles`.

Why it matters: if it were treated as success, `failure_count` would reset to 0
every night. A permanently broken blog would never reach 5 failures, never
deactivate, never alert anyone, and would appear in the logs as a healthy run
that found "0 new articles" — invisible forever.

Same treatment when every item lacks a title or a link (each such item is
skipped, and if all are skipped the list is empty).

### A6. The feed is HTML, not XML

`not valid xml` → failure. Usually means the blog moved its feed, or a WAF is
serving a challenge page.

### A7. The feed has recoverable XML warnings

Parsed anyway (libxml errors are captured, not fatal) and flagged
`malformed: true`. Articles are stored normally. Real-world feeds are messy;
refusing them would cost more content than it saves.

### A8. Items carry no publish date

Stored with `published_at = NULL` — and then **never appear in the feed**.

The fresh-phase query filters on `published_at > NOW() - INTERVAL '30 days'`,
and `NULL > …` is `NULL`, not true. The archive query filters
`published_at IS NOT NULL` outright. So a blog whose every post is undated
contributes **nothing**, silently, while showing green in the job logs.

This is why `find_feed.php` prints a "no publish dates" warning and why such
blogs should not be added at all. (The `UNDATED_AGE_DAYS` constant in
`FeedService` exists so undated articles would *sink* rather than *float* if
the window were ever relaxed — it is currently unreachable.)

### A9. The date has the wrong weekday

`"Mon, 28 Jul 2026"` when the 28th is a Tuesday. PHP does not ignore the
mismatch — `strtotime()` resolves it to **3 August**, moving the article up to
six days into the future.

`feed_parse_date()` strips the leading weekday before parsing. The date itself
is authoritative and the weekday is redundant, so dropping it makes the value
unambiguous.

### A10. The date is far in the future

Anything more than 24 hours ahead is clamped to `now`. A CMS with a bad clock
would otherwise pin one post to the top of every feed permanently — and,
combined with A9, a months-old post could land at "now" and dominate every
reader's feed.

### A11. The feed carries the full article body

Truncated to `FEED_EXCERPT_CHARS` (200) on a word boundary, with `…` appended.

This is a **licensing requirement, not a UI preference.** Storing full article
text is the copyright exposure the whole position avoids. `content:encoded` is
read only as a source for the excerpt.

### A12. Items have no image

`image_url` is `NULL` and the card renders without a thumbnail. Image
extraction never throws — a missing thumbnail must never fail a feed.

Extraction order: `media:content` / `media:thumbnail` `url` attribute →
`<enclosure>` of an image type → first `<img src>` in the body HTML.

### A13. The blog is a WordPress site and we picked its comments feed

`find_feed.php` ranks any candidate URL containing "comment" **last**.
WordPress declares its comments feed in the same `<link rel="alternate">`
block, and it is perfectly valid RSS — accept it by mistake and reader
comments are ingested as articles, with no error anywhere. If a blog's
"articles" look like one-line replies, this is why.

### A14. The blog fails five nights running

On the 5th consecutive failure:

- `is_active = false`
- an `ALERT` log line: `… deactivated after 5 failures - needs a human`

The blog drops out of the job's `SELECT` entirely and will **never be retried
automatically**. Restoring it is a manual `UPDATE blog_sources SET is_active =
true, failure_count = 0`.

Threshold is `FEED_MAX_FAILURES` in `.env`.

### A15. The blog fails three nights, then recovers

`failure_count` resets to **0** and `last_error` to `NULL` on the first
success. Failures must be *consecutive* to deactivate — an intermittently
flaky host is not killed off.

---

## B. The fetch job hits trouble

### B1. The database connection drops mid-run

Common on managed Postgres: this job holds one connection for its whole run,
and most of that wall clock is spent on HTTP, so the socket sits idle exactly
long enough to be reaped.

- The drop is detected by pattern-matching the message (pdo_pgsql reports all
  of these as `HY000`, so there is nothing else to match on).
- It is **not counted against the blog** — it is not that blog's fault.
- Reconnect, up to 3 attempts with backoff (a scaled-to-zero database needs a
  moment to wake), rebuild the prepared statements — they die with their
  connection — and **retry the same feed**.
- If recording a *failure* is what hits the dead connection, that write is
  retried once too; failing that, a `WARN` is logged and the next blog gets its
  turn.

Without this handling, the catch block needs the very connection that just
vanished, throws in turn, and the whole job aborts partway — which is how a
77-feed run was ending after 10 feeds.

### B2. The connection is idle so long the socket is silently dropped

The DSN gains `keepalives`, `connect_timeout=10` and a server-side
`statement_timeout=30000` unless the operator already set them. Without these
the next query blocks on a socket nobody will ever answer, up to the OS TCP
retry limit — observed as a **16-minute silent hang**.

### B3. The database is genuinely down

- At startup: `FATAL: cannot connect to database`, exit `1`, nothing fetched.
- Mid-run, after 3 failed reconnects: the loop stops, and one last reconnect is
  attempted for housekeeping. Articles already fetched are **committed and
  safe**.
- If even that fails: housekeeping and the `sync_status` row are skipped,
  logged as a partial run, exit `2`.

That last reconnect attempt matters: skipping the `sync_status` row makes
`/api/health` report a stale `last_sync_at` even though fresh content did
arrive.

### B4. `.env` is missing or the DSN is unset

`FATAL: FEED_DB_DSN / FEED_DB_USER not set. Is backend/.env present?`, exit
`1`.

Cron does not read your shell profile, which is why `feed_load_env()` reads
`.env` directly. Without it the job fails at the first `getenv()` with a
confusing "cannot connect to database" — **and only in production**.

### B5. The job is killed halfway

Every article insert is its own committed statement, so whatever was collected
stays. Blogs not yet reached simply keep yesterday's `last_fetched_at`. The
next run picks them all up; already-stored articles are rejected by the unique
index.

No `sync_status` row is written, so `/api/health` shows a stale
`last_sync_at` — which is the correct signal.

### B6. Reading the exit code

| Code | Meaning |
|---|---|
| `0` | every feed succeeded |
| `2` | at least one feed failed, or the run was partial |
| `1` | fatal — no config, or the database was unreachable at startup |

---

## C. Duplicates, edits and overlap

### C1. The job runs twice in one day

Harmless. Deduplication is the unique index on `(guid, category_id)` with
`ON CONFLICT DO NOTHING`, so a repeat costs bandwidth only. `rowCount()` is 0
for a conflict, so the "N new" counts stay accurate.

### C2. Two runs overlap

Also safe — the same `ON CONFLICT` handles it at the database level, so there
is no race.

### C3. The blog edits a headline after publishing

**First insert wins.** The conflict is skipped, so the original title, excerpt
and image stay. Articles are a snapshot of what was published, not a mirror of
the blog.

### C4. One blog is listed in three categories

It is fetched **three times** — the job iterates over `category_blog_map`, one
row per blog + category pair, not per blog. Each fetch stores its own copy of
the articles under that `category_id`.

That is deliberate: dedupe is `(guid, category_id)`, and per-category rows are
what let the serving query take a balanced N *per category*.

### C5. A blog needs a different feed for a different category

Set `category_blog_map.feed_url_override` — e.g. `Health →
/category/fitness/feed`, `Food → /category/recipes/feed`. The override wins
over `blog_sources.feed_url` and is never overwritten by redirect resolution
(A4).

### C6. A reader subscribes to two categories carrying the same article

They may see it twice, since the two copies have different `id`s. This is the
accepted cost of per-category storage; the app's de-duplication is by `id`, so
it does not merge them.

---

## D. A reader uses the app

### D1. Brand-new user, no topics chosen

`getMyCategories()` returns `[]`, which is **valid and not an error**. The
feed shows "Pick a few topics" with a button into Interests. The feed query
would return nothing anyway — it joins through `user_categories`.

### D2. First open after choosing topics

`GET /api/feed?limit=20` with no cursor → **FRESH phase**:

1. Up to 200 recent articles scanned per category, 50 kept per category after
   removing anything already seen.
2. Weighted shuffle — a same-day post is roughly **7× more likely** to surface
   than a week-old one, but nothing is guaranteed first.
3. The 20 returned are written to `user_seen_articles` immediately.
4. Response: `phase: "fresh"`, `next_cursor: null`, `has_more: true`.

### D3. The user pulls to refresh

Full reload: `cursor` reset to `null`, `hasMore` to `true`. Because step 3
above already marked the previous batch as seen, **the refresh returns
different articles** — it does not re-shuffle the same twenty.

### D4. Two pulls in a row look different even with no new content

Expected. The shuffle is random every time and seen-tracking removes what was
just shown. The feed is meant not to read as a static list.

### D5. The same account is open on two devices

They share one `user_seen_articles` table, so **whatever one device shows, the
other will not**. Reading on a phone genuinely consumes those articles for the
tablet. This is by design; there is no per-device state.

### D6. The user scrolls; `onEndReached` fires several times in one fling

`loadingMoreRef` guards it. Without the ref, `FlatList` would issue duplicate
requests and burn through the feed several pages at a time.

### D7. The user taps a card

Opens in an in-app browser (`openArticle`), themed to match. Long-press offers
"Open in browser" for the system browser. We never render article bodies — we
only ever stored excerpts (A11).

---

## E. The reader reaches the end

### E1. Fresh articles run out mid-scroll

The server does **not** return an empty list. `FeedController` falls straight
through to the archive: everything in the user's categories, seen or not,
strictly newest-first.

Returning `[]` here is what previously made the feed go blank after a full
scroll — which reads as a broken app, not as "you're up to date".

### E2. The fresh → archive handoff shows duplicates

Handled in the app, and this is the subtlest part of the whole feature.

When fresh runs dry, the server's archive starts from the **newest** article —
which is very likely already on screen. Taking that page would show
duplicates, and after de-duplication it would look like an empty page and stop
the scroll early.

So when the app sees `!cursor && phase === 'archive'`, it **discards that page**
and re-asks from the oldest article *it* is currently displaying — the true
continuation point. Because fresh articles are shuffled, "oldest" is a scan
over the list, not the last element.

Appends are also de-duplicated by `id` against what is already rendered.

### E3. The archive runs out too

`has_more: false`, and only then does the app show:

> **End of feed** — You've reached the oldest article in your topics.

`has_more` from the server is the **only** stop condition. An empty page alone
never ends the scroll.

### E4. The user has read literally everything

Fresh is empty, archive returns every article again (seen or not), newest
first. So the feed is never blank — it becomes a back catalogue. Ordered, not
shuffled, because browsing an archive needs a stable order to be coherent.

### E5. The user's categories contain no articles at all

Both phases return `[]`. That is **valid, not an error**. The app shows
"Nothing here yet — the overnight job adds new ones every morning".

Usually means a brand-new category with no blogs mapped, or every mapped blog
is deactivated (A14) or undated (A8).

### E6. A new article arrives while the user is deep in the archive

Keyset pagination means it does **not** shift the pages. The cursor compares
against `(published_at, id)` of the last row seen, so nothing duplicates or
gets skipped — unlike `OFFSET`, where an insert mid-scroll shifts every
subsequent page.

The new article shows up on the next pull-to-refresh.

---

## F. The reader changes their topics

### F1. Topics saved from the Interests screen

The save **replaces** the whole selection — sending `[1,2]` after `[3]` leaves
exactly `[1,2]`, in one transaction so a mid-way failure cannot leave the user
with no topics.

Interests then hands back a new `refreshAt` param, which retriggers `load()` —
that is how saving topics updates a feed the user is already looking at.

### F2. A topic is added

Its articles are eligible immediately — the query joins live through
`user_categories`. No cron run needed; the articles are already in the table.

### F3. A topic is removed

Its articles disappear from the feed on the next pull. **Seen history is not
deleted**, so re-adding the topic later will not resurface articles already
read within the last 14 days.

### F4. An admin deactivates a category

`GET /api/user/categories` filters on `c.is_active = true`, so it drops out of
the user's list. Its existing articles stay in the table until the 60-day
purge.

---

## G. Network and offline

### G1. Offline at launch

The request fails with `NETWORK_ERROR` / `TIMEOUT`, and the app loads the last
saved feed from AsyncStorage (`blogfeed.cache.feed.v1`, capped at
`CACHE_MAX_ARTICLES`) under a visible banner:

> **Offline — showing articles saved earlier**

Cached content is **never passed off as fresh**, and while `fromCache` is set
pagination is disabled — there is nothing to page.

### G2. Offline with no cache

No banner, an error empty state: "You're offline", with a Try again button.

### G3. Connection drops mid-scroll

Paging stops (`hasMore` set false) but **what is on screen is kept**. A
populated feed is never replaced with an error screen.

### G4. The cache is corrupt

Reads as "no cache" rather than crashing on launch. Same for a failed cache
*write* — it is swallowed, because a caching problem must never break the feed
the user is looking at.

### G5. The token expired

The API client handles auth failures centrally: it clears the token and swaps
the navigation stack. `FeedScreen` deliberately does **not** show an error for
`isAuthFailure`, because the user is already being moved to the login screen.

### G6. Why articles are in AsyncStorage but the token is not

Article headlines are public content anyone can read on the publisher's site.
A token is a live session, so it lives in the Keychain. The storage choice
follows what a leak would cost.

---

## H. Time passes — retention and staleness

### H1. An article turns 60 days old

Deleted by the purge at the end of every fetch run, and again by
`db/cleanup.sql`:

```sql
DELETE FROM articles WHERE COALESCE(published_at, fetched_at) < NOW() - INTERVAL '60 days'
```

The `COALESCE` is load-bearing — `NULL < anything` is `NULL`, not true, so a
plain comparison silently never deletes an undated article and they accumulate
forever.

### H2. Seen history turns 14 days old

Deleted by `cleanup.sql`. In principle an article could then resurface — in
practice it is usually already past the 30-day fresh window or the 60-day
purge, so repeats are rare rather than impossible.

`user_seen_articles` is the fastest-growing table in the schema; this is what
keeps it bounded.

### H3. An article is purged while a user is paging past it

Nothing breaks. The next keyset page simply skips it — the cursor is a
`(timestamp, id)` comparison, not a row reference.

### H4. Cron did not run last night

The feed still works — it serves whatever is in the table. Readers see older
articles and may exhaust fresh sooner. The signal is `/api/health`'s
`last_sync_at`, which is `MAX(run_at)` from `sync_status`; a stale value is
what actually reveals a dead cron job.

### H5. Cron has not run in weeks

Articles age past 30 days, so the fresh phase empties. Every request falls
through to the archive, and readers just page through the back catalogue until
the 60-day purge empties that too.

### H6. `cleanup.sql` never runs

Nothing breaks immediately — the fetch job does its own article purge. But
`user_seen_articles`, expired tokens and `sync_status` grow without bound, and
the anti-join in the fresh query gets progressively slower.

---

## I. Bad input and edge cases

| Situation | Behaviour |
|---|---|
| `?limit=` omitted | defaults to 20 |
| `?limit=1000` | silently capped at 50 |
| `?limit=0` or negative | `400 VALIDATION_ERROR` — "limit must be at least 1" |
| `?limit=abc` | `400 VALIDATION_ERROR` — "limit must be a whole number" |
| `?cursor=garbage` | **restarts from the top of the archive**, never a 500 |
| `?cursor=` over 100 chars | `400 VALIDATION_ERROR` — "Invalid cursor" |
| no auth token | `401`; the app clears the session and shows login |
| mobile `FEED_PAGE_SIZE` > 50 | the app **refuses to start**, naming the key — a value the server would reject is caught at build/boot rather than as an unactionable 400 |
| `markSeen` fails | swallowed and logged. Worst case a user sees an article twice, which beats a 500 on the app's main screen |

---

## J. Quick reference

**What counts as a feed failure**

- non-200, network error, empty body
- not valid XML, or no `item` / `entry` nodes
- **parses fine but yields zero usable articles** ← the one people get wrong

**What does not**

- recoverable XML warnings (`malformed: true`, articles kept)
- missing images, authors, or `guid`s (all have fallbacks)
- individual items missing a title or link (skipped, rest kept)
- a dropped database connection (retried, not charged to the blog)

**Thresholds**

| Thing | Value | Where |
|---|---|---|
| Consecutive failures before deactivation | 5 | `FEED_MAX_FAILURES` |
| Excerpt length | 200 chars | `FEED_EXCERPT_CHARS` |
| Feed download timeout | 15 s | `FEED_HTTP_TIMEOUT` |
| Fresh-phase recency window | 30 days | `FeedService::WINDOW_DAYS` |
| Candidates scanned / kept per category | 200 / 50 | `FeedService` |
| Page size / max | 20 / 50 | `FeedController` |
| Article retention | 60 days | fetch job + `cleanup.sql` |
| Seen-history retention | 14 days | `cleanup.sql` |
| Delay between blogs | 0.5 s | `fetch_feeds.php` |

**Symptom → cause**

| Symptom | Look at |
|---|---|
| A blog shows green but contributes no articles | undated posts (A8) |
| Articles look like one-line replies | comments feed ingested (A13) |
| One post is stuck at the top of every feed | bad date (A9, A10) |
| The feed went blank after a full scroll | the archive fallthrough is broken (E1) |
| Duplicates right where the scroll slows | fresh → archive handoff (E2) |
| The run ends partway with no summary line | database connection dropped (B1, B3) |
| `last_sync_at` is stale but content is fresh | the `sync_status` write was skipped (B3, B5) |
| A user sees an article they already read | seen history pruned at 14 days (H2), or `markSeen` failed (I) |
