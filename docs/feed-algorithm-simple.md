# A simple feed algorithm

How to make the feed noticeably better using **arithmetic only** — no machine
learning, no embeddings, no new services, no Composer packages. Everything
here runs in PHP 8 and PostgreSQL, which is all the project has and all it
needs.

Companion to [feed-behaviour.md](feed-behaviour.md) (how the feed works today)
and [feed-scenarios.md](feed-scenarios.md) (how it behaves when things go
wrong).

---

## 1. Where we are

Today's fresh phase ranks on **one signal: age.** From
[FeedService::weightedShuffle](../src/Services/FeedService.php):

```
weight = 1 / (1 + ageDays)
key    = -log(u) / weight        u ∈ (0,1)
sort ascending by key, take limit
```

That is a good mechanism — the randomness is what stops the feed reading as a
static list — but it is fed a poor input. Age is the *only* thing that
distinguishes one article from another.

Three consequences, all observable right now:

| Problem | Why it happens |
|---|---|
| A user who never opens Sports articles keeps getting Sports articles | Nothing records that they didn't. `user_categories` is the only preference signal, and it is coarse — a whole topic, on or off. |
| A thin, image-less, 40-character stub ranks equal to a substantial piece from the same hour | No notion of article quality at all. |
| One source can fill half a page | Diversity is enforced per *category* by the LATERAL query, never per *source*. |

And the root cause underneath all three: **there is no interaction data.** No
taps, no dwell, no dismissals. `user_seen_articles` is written server-side at
serve time, so it records what was *sent*, not what was *read*.

You cannot rank on what users like until you know what users like.

---

## 2. Design constraints

Deliberate limits, chosen to keep this simple enough to maintain:

1. **Arithmetic only.** Addition, multiplication, one exponential decay. No
   matrix maths, no training loop, no gradient anything.
2. **No new infrastructure.** No vector database, no Kafka, no Python service.
   PHP + PostgreSQL + the existing nightly cron.
3. **Precompute nightly, serve with a join.** The feed request must stay in
   its current ~20 ms budget. Aggregation happens in cron, not per request.
4. **Every stage ships alone.** Four stages below, each an improvement on its
   own, none requiring the next.
5. **Explainable.** For any article in any feed you should be able to say, in
   one sentence, why it is there. That is worth more than a small accuracy
   gain, because it is what lets you debug complaints.

---

## 3. The signals to collect

One new table. This is the foundation everything else stands on.

```sql
CREATE TABLE article_events (
    id          BIGSERIAL PRIMARY KEY,
    user_id     BIGINT      NOT NULL REFERENCES users(id)    ON DELETE CASCADE,
    article_id  BIGINT      NOT NULL REFERENCES articles(id) ON DELETE CASCADE,
    event_type  VARCHAR(20) NOT NULL,     -- see below
    dwell_ms    INT,                      -- 'tap' events only
    position    SMALLINT,                 -- rank in the page it was shown at
    session_id  VARCHAR(36),              -- one app-foreground period
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX article_events_user_idx    ON article_events (user_id, created_at DESC);
CREATE INDEX article_events_article_idx ON article_events (article_id, event_type);
```

**Event types — five, no more:**

| Type | Meaning | Sent when |
|---|---|---|
| `impression` | the card was actually on screen | `onViewableItemsChanged`, ≥50% visible for ≥1s |
| `tap` | the article was opened | card press, with `dwell_ms` filled in on return |
| `hide_source` | "show me less from this blog" | long-press menu |
| `not_interested` | "not this article" | long-press menu |
| `share` | shared out of the app | share sheet |

**Dwell is measurable, and it is your best signal.**
[utils/browser.js](../../mobile/src/utils/browser.js) opens articles in Chrome
Custom Tabs / SFSafariViewController, and `InAppBrowser.open()` resolves when
the browser is dismissed. Time the promise:

```
t0 = now
await InAppBrowser.open(url, ...)
dwell_ms = now - t0
```

You cannot see scroll depth inside the publisher's page — and a reader mode is
ruled out by the licensing position — but "came back after 4 seconds" versus
"came back after 4 minutes" is the difference between a misleading headline and
a good article, and that is most of what you need.

### 3.1 The bounce rule

> A tap with `dwell_ms < 10000` is **not** a positive signal.

This single rule is what stops the feed drifting toward clickbait. A headline
that gets opened and abandoned in three seconds has actively wasted the
reader's time; counting it as success teaches the feed to find more like it.

Define the effective positive as:

```
good_tap(e) = 1 if e.type = 'tap' AND e.dwell_ms >= 10000
              0 otherwise
```

Everything downstream counts `good_tap`, never raw taps.

### 3.2 Delivery

One endpoint, batched — never one request per impression:

```
POST /api/events
{ "events": [ {type, article_id, position, session_id, dwell_ms, at}, ... ] }
```

The app buffers events in memory and flushes on: 20 events queued, app
backgrounded, or 30 seconds elapsed. The endpoint returns `202` and inserts
with `ON CONFLICT DO NOTHING`.

**Every event carries a client-generated `id`**, stamped when the event
happens rather than when it is sent. This is what makes a retry safe.
Deduplicating on `(user, article, type)` would be wrong — a reader genuinely
can open the same article twice, and the second open is real evidence — so
only the client can tell a retry from a repeat. With ids in place a failed
flush is re-queued rather than discarded; without them, discarding would be
the lesser evil.

> This was discovered in testing, not design. The original plan deduplicated
> impressions with a partial unique index and assumed that covered retries.
> It did not cover taps: a replayed batch re-inserted ten of them and inflated
> the good-tap count by a third, with nothing anywhere to show it had
> happened. See §13.

**Retention:** add to `db/cleanup.sql` —
`DELETE FROM article_events WHERE created_at < NOW() - INTERVAL '90 days'`.
This will become the largest table in the schema.

---

## 4. The score

One formula. Five terms, each normalised to `0…1`, weights summing to `1`, then
one multiplicative penalty.

```
Score(user, article) =
      w_recency  · Recency(article)
    + w_category · CategoryAffinity(user, article.category)
    + w_source   · SourceAffinity(user, article.source)
    + w_quality  · Quality(article)
    + w_explore  · Explore(user, article.source)

FinalWeight = Score × Penalty(user, article)
```

**Starting weights:**

| Term | Weight | Rationale |
|---|---|---|
| `w_recency` | **0.40** | It is a news feed. Freshness dominates, but no longer alone. |
| `w_category` | **0.25** | The strongest personal signal you will have. |
| `w_source` | **0.15** | Finer-grained than category, but noisier. |
| `w_quality` | **0.15** | Works from day one, before any user data exists. |
| `w_explore` | **0.05** | Small on purpose — enough to escape a rut, not enough to derail. |

These are starting points, not truths. Tune them by watching good-tap rate
(§9), one weight at a time.

Then feed `FinalWeight` into the **existing** shuffle in place of
`1/(1+ageDays)`:

```
key = -log(u) / max(FinalWeight, 0.01)
```

The floor matters: a weight of exactly 0 divides by zero. A floored article
still *can* appear, just very rarely — which is the correct behaviour for
"probably not interesting" as opposed to "banned".

This is a deliberately small change to the code. The shuffle machinery,
including its "feels different every pull" property, stays exactly as it is —
only what feeds it changes.

---

## 5. The five terms

### 5.1 Recency — exponential half-life

```
age_hours = (now - published_at) / 3600
Recency   = 0.5 ^ (age_hours / H)          H = 36
```

Read it directly: **the score halves every 36 hours.**

| Age | Recency |
|---|---|
| now | 1.00 |
| 12 h | 0.79 |
| 36 h | 0.50 |
| 3 days | 0.25 |
| 7 days | 0.04 |

Chosen over the current `1/(1+ageDays)` because the decay is tunable by a
single number with an obvious meaning. Raise `H` if the feed feels too
churny; lower it if it feels stale. `H = 36` suits a feed refreshed nightly —
an article stays competitive for about two days.

### 5.2 Category affinity — smoothed click-through

Per user, per category, from the last 30 days of events:

```
                good_taps(u,c) + m · global_rate
CatRate(u,c) = ─────────────────────────────────      m = 20
                impressions(u,c) + m
```

`m` is a smoothing constant — the number of impressions you pretend to have
already seen, at the global average rate. With `m = 20`, a category with 3
impressions barely moves off the global average, while one with 300 is
dominated by the user's real behaviour. That is exactly the property you want:
**confidence grows with evidence, automatically.** No special-casing for new
users, no minimum-sample thresholds.

Normalise across the user's own categories so the term lands in `0…1`:

```
CategoryAffinity(u,c) = CatRate(u,c) / max over the user's categories of CatRate(u,·)
```

The user's best category scores 1.0; one they ignore half as often scores 0.5.

### 5.3 Source affinity — the same, finer

```
                 good_taps(u,s) + m · global_rate
SrcRate(u,s) = ──────────────────────────────────     m = 10
                 impressions(u,s) + m

SourceAffinity(u,s) = SrcRate(u,s) / max over sources of SrcRate(u,·)
```

Lower `m` (10 vs 20) because there are ~76 sources against 20 categories, so
per-source evidence accumulates more slowly and needs to count sooner.

This is what lets the feed learn "likes Technology, but not *that* tech blog"
— which category selection alone can never express.

### 5.4 Quality — no user data required

Three components, all available the moment an article is fetched:

```
Quality = 0.6 · SourceQuality(s) + 0.2 · HasImage + 0.2 · HasRealExcerpt
```

**SourceQuality** — the source's smoothed good-tap rate across *all* users,
scaled against the best source:

```
                  good_taps(s) + 50 · global_rate
SourceRate(s) = ────────────────────────────────
                  impressions(s) + 50

SourceQuality(s) = SourceRate(s) / max over all sources of SourceRate(·)
```

`m = 50` here because this is a global statistic with far more data behind it,
so it can afford to be conservative.

**HasImage** — `1` if `image_url IS NOT NULL`, else `0`. Currently **43% of
your corpus has no image** (752 of 1,740), and those cards render as a compact
text row. A hero card earns attention; this is a real quality difference, not a
cosmetic one.

**HasRealExcerpt** — `1` if `length(excerpt) >= 80`, else `0`. Your average
excerpt is 166 characters, so this mostly catches the stubs.

Before any events exist, `SourceQuality` is the global average for every
source, so `Quality` reduces to the image and excerpt checks — still better
than nothing.

### 5.5 Explore — a small, honest allowance

```
Explore(u,s) = 1 if the user has had fewer than 5 impressions from source s
               0 otherwise
```

At `w_explore = 0.05` this is a nudge, not a lever. Its job is to stop the
feed collapsing onto the four sources a user happened to tap in their first
week. Without some term like this, affinity scores are self-reinforcing:
sources that get shown get tapped, get shown more.

A simpler alternative if even this feels like too much: reserve **2 of every 20
slots** for articles chosen by recency alone, ignoring personalisation
entirely. Cruder, equally effective, and easier to explain.

### 5.6 Penalty — the multiplicative term

```
Penalty(u,a) = 0.3   if fatigued: ≥15 impressions from a.source in 14 days with 0 good taps
             = 1.0   otherwise

hide_source     -> excluded in SQL, not scored
not_interested  -> excluded in SQL, not scored
```

Multiplicative, not additive, so a penalty can genuinely suppress rather than
merely subtract a little.

**Explicit negative feedback is a hard filter, not a weight of zero.** The
original design gave it `Penalty = 0.0`, which contradicts the weight floor in
§4: `max(0 × score, 0.01)` is `0.01`, so a "banned" article stayed reachable
and would eventually resurface. Someone who pressed *not interested* has said
no, and no means zero. Fatigue keeps the soft treatment, because a demoted
source should be able to recover if the reader ever engages again.

The **fatigue** rule is the quiet workhorse. Most users never press a menu
item — they just stop tapping. Fifteen shown, none opened, is a clear enough
statement, and `0.3` demotes without banning, so the source can recover if the
user ever engages again.

`hide_source` is **reversible from Profile** — Profile → Hidden blogs. A
permanent, invisible suppression triggered by one mis-tap is a bug the user
cannot diagnose.

That is also why hidden sources live in their own `user_hidden_sources` table
rather than being derived from the event log. An event log answers "what
happened"; this answers "what is currently true", which is the question both
the feed query and the Profile list actually ask. It also means a hide takes
effect on the **next pull**, without waiting for the nightly rollup.

---

## 6. Diversity — a pass, not a term

Diversity belongs *after* scoring, not inside it. Scores are per article;
diversity is a property of the whole page.

Take the top-scored candidates in order, and skip any article that would break
a cap:

```
for each candidate in score order:
    if page is full: stop
    if count(page, candidate.source)   >= 2: skip
    if count(page, candidate.category) >= 4: skip     (only if user has >2 categories)
    add candidate to page

if page is short, fill from the skipped list in score order
```

**Caps for a 20-card page:** max 2 per source, max 4 per category.

The category cap is skipped below three categories — and the count used is
categories that actually **contributed candidates**, not categories the reader
selected. Someone who picked twenty topics but has articles in two should not
have a per-category cap applied to them.

The refill pass is essential — it guarantees you never return a short page just
because the caps were tight. A user with two categories would otherwise be
capped at 8 articles.

This directly fixes the "one source fills half the page" problem, which the
current LATERAL query cannot: it balances by *category*, and a category with
one dominant blog stays dominated.

---

## 7. Cold start

**New user, no events.** Category affinity and source affinity are undefined,
so drop those terms and renormalise the rest:

```
Score = 0.55 · Recency + 0.30 · Quality + 0.15 · Trending
```

**Trending** — a simple global velocity, computed nightly:

```
                good_taps(a) in the last 24h
Trending(a) = ──────────────────────────────
              max over articles of the same
```

"What are other people actually reading today." It requires no personal data,
which is exactly what a brand-new user lacks. Once a user reaches **50
impressions**, switch to the full formula in §4. No blending, no ramp — a hard
switch is simpler and the difference is not visible to the user.

**New article, no events.** It inherits `SourceQuality` from its blog and picks
up the full `Recency` term, so a fresh article from a good source starts
strong. The `Explore` term covers articles from sources the user has not seen.
No special handling needed — this falls out of the design.

---

## 8. Where it plugs in

Nightly, in cron, after `fetch_feeds.php` — three small rollup tables so the
request path stays a join instead of an aggregation:

```sql
CREATE TABLE user_category_stats (user_id, category_id, impressions, good_taps, rate, PRIMARY KEY (user_id, category_id));
CREATE TABLE user_source_stats   (user_id, source_id,   impressions, good_taps, rate, PRIMARY KEY (user_id, source_id));
CREATE TABLE source_stats        (source_id, impressions, good_taps, rate, trending_score, PRIMARY KEY (source_id));
```

Rebuild them with three `INSERT … SELECT … ON CONFLICT DO UPDATE` statements
over the last 30 days of `article_events`. At your scale this is seconds; at
100× it is still seconds, because it is a grouped scan of one indexed table.

The request path changes in exactly two places in
[FeedService.php](../src/Services/FeedService.php):

1. **`queryCandidates()`** — join the three stats tables and select the
   per-article inputs. Keep the LATERAL structure, the inner `LIMIT`, and the
   30-day window: all three are load-bearing for performance
   ([feed-behaviour.md §2.1](feed-behaviour.md)). Raise `PER_CATEGORY_KEEP`
   from 50 to ~100 so scoring has more to choose from.
2. **`weightedShuffle()`** — compute `Score` per row and use it as the weight.
   The exponential-key sort is unchanged.

Then add the diversity pass (§6) between the shuffle and `markSeen()`.

**Nothing changes in the archive phase.** It stays strictly newest-first —
a back catalogue needs a stable order to page through coherently, and
personalising it would break keyset pagination.

### Pseudocode, end to end

```
buildFeed(user, limit):
    candidates = queryCandidates(user)          # LATERAL, 30-day, unseen, + stats join

    if user.impressions < 50:                   # cold start
        for a in candidates:
            a.weight = 0.55·Recency(a) + 0.30·Quality(a) + 0.15·Trending(a)
    else:
        for a in candidates:
            score = 0.40·Recency(a)
                  + 0.25·CategoryAffinity(user, a.category)
                  + 0.15·SourceAffinity(user, a.source)
                  + 0.15·Quality(a)
                  + 0.05·Explore(user, a.source)
            a.weight = max(score · Penalty(user, a), 0.01)

    for a in candidates:                        # existing shuffle, new input
        u = random in (0,1)
        a.key = -log(u) / a.weight
    sort candidates by key ascending

    page = diversityPass(candidates, limit)     # 2/source, 4/category, then refill
    markSeen(user, page)
    return page
```

---

## 9. What to measure

Four numbers, weekly. Everything else is noise at your scale.

| Metric | Definition | Why this one |
|---|---|---|
| **Good-tap rate** | good taps ÷ impressions | The primary number. Uses the 10-second rule, so it cannot be gamed by clickbait. |
| **Session depth** | articles viewed per session | Did people keep scrolling? |
| **D7 return rate** | users active 7 days after a given day | The retention signal. Slow, but the one that actually matters. |
| **Source coverage** | distinct sources appearing in feeds ÷ 76 | Guards against collapse onto a handful of blogs. |

Track raw **tap rate** alongside good-tap rate. If tap rate climbs while
good-tap rate flattens, the feed has learned to bait — stop and re-tune.

**Do not build an A/B framework.** With 12 users it would tell you nothing;
you would be reading noise. Change one weight, watch a week, keep or revert.
Revisit experimentation somewhere north of a few thousand active users.

---

## 10. Rollout

Four stages. Each ships alone and is worth having on its own.

> **All four are implemented** on branch `feed-scoring-algorithm`. Stage 1
> still needs its two weeks of collected data before Stage 3 does anything —
> until `user_feed_stats.impressions` reaches 50 for a reader, they are served
> by the cold formula regardless of what else is deployed.

### Stage 1 — instrumentation *(nothing changes for the user)*
Add `article_events`, `POST /api/events`, and the mobile hooks: viewability
impressions, tap with dwell timing, the long-press menu items. Add the cleanup
rule. **Then wait two weeks.** Every later stage is worthless without this
data, and this stage cannot be rushed.

### Stage 2 — quality and recency *(no personal data needed)*
Swap the recency curve to the 36-hour half-life, add the `Quality` term, add
the diversity pass. This works on day one — no events required for the image
and excerpt checks. Expect the most visible improvement here for the least
work.

### Stage 3 — personalisation *(needs Stage 1 data)*
Nightly rollup tables, category and source affinity, the cold-start formula
and the 50-impression switch.

### Stage 4 — negative feedback
`hide_source` and `not_interested` menu items, the fatigue rule, and an
"unhide" list in Profile.

---

## 11. What this deliberately does not do

Named so nobody wonders whether they were forgotten:

| Not doing | Why | Revisit when |
|---|---|---|
| Embeddings / semantic similarity | pgvector 0.8.6 *is* available on your Postgres 18.4, so this is technically within reach — but it needs an embedding source and adds a pipeline. Category + source affinity gets most of the benefit for none of the cost. | Articles > 100k, or "more like this" becomes a feature |
| Collaborative filtering | Needs overlapping users per item. With 12 users and 1,740 articles the matrix is essentially empty. | > 1,000 active users |
| Learned models of any kind | No labels, no volume, and nothing to serve them with. | > 10,000 users *and* a year of events |
| Real-time updates | The corpus refreshes nightly. Real-time ranking over a once-daily corpus is machinery with nothing to do. | Ingestion becomes continuous |
| A/B testing framework | 12 users. Any result would be noise. | > 5,000 active users |
| Bandits / contextual exploration | The 5% explore term and the 2-random-slots fallback cover the same need with arithmetic. | Explore/exploit becomes a measurable tradeoff |

The honest summary: at your current scale, **the wins are instrumentation,
quality filtering, and source diversity** — not algorithmic sophistication.
A simple score over good data beats a sophisticated one over no data, and
right now there is no data at all.

---

## 12. Invariants

Carry these forward alongside the ones in
[feed-behaviour.md §5](feed-behaviour.md):

1. **A tap under 10 seconds is not a success.** Every rate in this document
   counts good taps. Break this and the feed optimises for clickbait.
2. **Weights are floored at 0.01, never zero.** Zero divides by zero in the
   shuffle; a floor means "rare", which is what suppression should mean.
3. **Diversity is a post-pass with a refill.** Never let caps return a short
   page.
4. **The archive stays newest-first.** Personalising it breaks keyset paging.
5. **Rollups are nightly.** Nothing in the request path aggregates events.
6. **`hide_source` is reversible from Profile.** An invisible permanent
   suppression is undebuggable.
7. **The 30-day window and inner `LIMIT` in the candidate query stay.** Both
   are measured performance features, not leftovers.


---

## 13. What shipped, and where it differs

Implemented on branch `feed-scoring-algorithm`. Four things changed during
implementation, each because the code proved the design wrong.

| # | The design said | What shipped | Why |
|---|---|---|---|
| 1 | `ON CONFLICT DO NOTHING` makes a retried flush harmless | every event carries a **client-generated id**, unique per user | The index only covered impressions. A replayed batch re-inserted 10 taps and inflated good-taps by a third — silently, since the feed still worked. Caught by `test_feed_integration.php`. |
| 2 | `hide_source` / `not_interested` set `Penalty = 0.0` | both are **hard filters in SQL** | `max(0 × score, 0.01)` is `0.01`, so "banned" articles stayed reachable. The floor and the zero contradicted each other. |
| 3 | diversity cap keyed on the user's selected categories | keyed on categories that **contributed candidates** | 20 topics selected but 2 with articles would wrongly trigger a cap meant for variety. |
| 4 | `user_feed_stats` sized the cold-start switch off a 30-day window | **lifetime** impressions | Maturity should not expire because someone took a month off; the affinity rollups already handle recency. |

**Files.** Backend: `db/007_feed_scoring.sql`, `src/Services/Scoring.php`,
`src/Services/EventService.php`, `src/Controllers/EventController.php`,
`jobs/rollup_stats.php`, plus edits to `src/Services/FeedService.php`,
`public/index.php` and `db/cleanup.sql`. Mobile: `src/analytics/events.js`,
`src/screens/HiddenSourcesScreen.js`, plus edits to `FeedScreen.js`,
`endpoints.js`, `browser.js`, `ProfileScreen.js` and `RootNavigator.js`.

**Tests.** `tests/test_scoring.php` — 43 cases, no database, covering every
formula. `tests/test_feed_integration.php` — 18 cases against a real database,
creating and destroying its own throwaway user; it is the only thing that
checks events → rollup → changed ranking end to end.

### One measured surprise

The feed request spends almost all of its time waiting on the network, not
working. Against the production Neon instance in `us-east-2`, from a
development machine:

| | |
|---|---|
| `SELECT 1` round trip | **~920 ms** |
| The candidate query, server-side | **6.5 ms** |
| The same query before scoring was added | 4.9 ms |
| Scoring 705 candidates in PHP | 3.6 ms |
| Shuffle + diversity pass | 1.6 ms |

Scoring, shuffling and de-duplicating 705 candidates costs about **5 ms**.
One round trip costs **180× that**.

The practical consequence: **count round trips, not operations.** `build()`
was restructured so the cold-start and category-count inputs ride along on the
candidate rows rather than costing a second query — the same number of trips
as before scoring existed. Adding a term to the score is nearly free; adding a
query is not.

This also means the ~20 ms budget in §2 is only achievable if the API server
is near its database. Whatever host serves this in production should be in
`us-east-2` alongside the Neon instance, or the network cost will dwarf
everything this document is about.
