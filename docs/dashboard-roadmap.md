# Dashboard roadmap

Written from a data audit on 2026-10-10, not from a feature wishlist. Each
item is placed by **what the database can actually show today**, because a
dashboard section with no rows in it is worse than no section — it reads as
broken rather than empty.

## What the data says

| Table | Rows | Verdict |
|---|---|---|
| `articles` | 17,736 | **Rich.** Content analytics are viable now |
| `sync_status` | 63 runs | **Rich.** Operational history is viable now |
| `blog_sources` | 76 | **Rich.** 72 healthy, 1 failing, 3 deactivated |
| `user_categories` | 83 across 10 readers | **Usable.** Topic demand is viable |
| `audit_log` | 9 | Thin but grows with use |
| `app_settings` | 6 | Usable |
| `user_seen_articles` | 160 | Thin |
| **`article_events`** | **81, last 2026-09-27** | **Stalled — see below** |
| `user_feed_stats` | 1 warm reader | Follows from events |
| `donations` | 0 | Nothing to show |
| `user_hidden_sources` | 0 | Nothing to show |

### The finding that shapes everything

**Interaction logging stopped two weeks ago.** All 81 events come from a
single reader, and the newest is 2026-09-27 — before the backend moved, so
the migration did not cause it. Either that reader stopped opening the app,
or the only build with analytics stopped reporting.

Everything personalised depends on this: ranking affinities, "warm" readers,
content performance, engagement charts. **One reader is not a dataset.** Any
engagement section built now would plot a single person's fortnight-old
afternoon.

So the first item below is not a dashboard feature at all. It is finding out
why the signal stopped, because six of the features people usually want from
a dashboard like this are downstream of it.

---

## Tier 0 — before features

**0.1 Diagnose the event drought.** Instrument whether `POST /api/events` is
being reached at all: the client swallows its own errors by design, so a
failing flush is invisible from both ends. An `events in the last 24h`
counter already sits on the Sync page; add the last-seen timestamp per
reader and a nightly warning when it goes quiet. *Small. Unblocks six
features.*

**0.2 Automated backups.** Self-hosting moved this from Neon's problem to
ours and nothing schedules a dump yet. Not a dashboard feature; it is the
thing you will wish existed. *Small.*

---

## Tier 1 — ships on today's data

**1.1 Article browser.** 17,736 rows and no way to look at one. Search by
title, filter by source, category and date, open the original. Delete an
article that should not have been ingested. *The single most useful missing
screen.*

**1.2 Source detail.** A page per source: ingestion over time, article
count, failure history, last error, and the feed URL validated live by
`find_feed.php` before saving. Reaches the three deactivated feeds with
context rather than a toggle.

**1.3 Add a source.** Currently an `INSERT` by hand. Paste a blog URL,
`find_feed.php` discovers and validates the feed, pick categories, save.
Closes the loop that forces SQL.

**1.4 Category management.** 20 categories, editable only by SQL. Rename,
reorder, set the icon, activate or deactivate, see article counts.
*Deactivating one hides it from every reader — worth a confirmation.*

**1.5 Sync detail.** Each run already records ok/failed/added/duration.
Show one run's story: which feeds failed and why. The data is in
`blog_sources.last_error`; it is simply not joined to the run yet.

## Tier 2 — needs the event signal restored (Tier 0.1)

**2.1 Content performance.** Which articles, sources and categories get
opened and read. Needs the 10-second good-tap rule already implemented
server-side. *Pointless until events resume.*

**2.2 Reader analytics.** Active readers, retention, topic popularity by
actual reading rather than by selection.

**2.3 Ranking inspector.** Why a given article scored what it did — the
five-term breakdown `Scoring::breakdown()` already returns and nothing
displays. Excellent for debugging complaints, useless without affinities.

## Tier 3 — needs a queue

**3.1 Trigger sync from the dashboard.** A full fetch takes ~100 s, which
outlasts a web request. Needs a job table and a worker, not a button. The
Sync page says so rather than offering one that times out.

**3.2 Bulk source import.** Same reason: validating 50 feeds is minutes of
outbound HTTP.

## Tier 4 — the CMS

Authored posts, as agreed: drafts, publish, schedule, SEO, media. New tables,
new API, **and a mobile screen that does not exist yet** — the app renders
ingested articles that link out, so authored content has nowhere to appear
until one is built. Largest item here by a wide margin; worth starting only
once Tiers 1–2 have made the dashboard the place you actually work.

---

## Suggested order

1. **0.1** diagnose events, **0.2** backups — small, and everything else leans on them
2. **1.1** article browser, **1.3** add a source — the two that end routine SQL
3. **1.2, 1.4, 1.5** — rounding out management
4. Re-audit. If events have resumed, **Tier 2**; if not, find out why first
5. **Tier 3**, then **Tier 4**

## The rule applied throughout

Build the section when the rows exist. The reference design has a donut, two
charts and three list cards because its data filled them; ours shows the same
shapes only where 17,736 articles and 63 sync runs genuinely fill them, and
says "not enough history yet" where they do not.
