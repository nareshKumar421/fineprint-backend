# Blog Feed — Backend

PHP 8.5 API and cron jobs for the Blog Feed app. PostgreSQL. No framework, no
runtime dependencies.

This is one of two separate projects:

| Project | Contains |
|---|---|
| **backend** (this repo) | API, cron jobs, SQL schema |
| **mobile** | The React Native app |

They share nothing but the API contract. Planning documents, verification
scripts and the original client specification live outside both, in `extra/`.

---

## Layout

```
public/     index.php — the ONLY web-reachable file
src/        controllers, services, middleware
jobs/       fetch_feeds.php, find_feed.php — run from cron
db/         schema.sql, seed_categories.sql, cleanup.sql, migrations
tests/      test_feedlib.php
```

**Only `public/` is served.** The web root points there, so `src/`, `jobs/`,
`db/` and `.env` are unreachable over HTTP even if the server is
misconfigured. That one decision prevents the most common PHP deployment leak.

## Setup

```bash
composer install                 # generates the autoloader; no packages to fetch
cp .env.example .env             # then fill in the database credentials
psql "$FEED_DB_URL" -f db/schema.sql
psql "$FEED_DB_URL" -f db/seed_categories.sql
psql "$FEED_DB_URL" -f db/002_rate_limits.sql
psql "$FEED_DB_URL" -f db/007_feed_scoring.sql
```

Requires `php-pgsql`. Without it `new PDO('pgsql:...')` fails immediately and
nothing works.

## Running

```bash
php -S 0.0.0.0:8000 -t public    # development only — never in production
```

Production is nginx + PHP-FPM with the root at `public/`.

## The cron job

```bash
php jobs/fetch_feeds.php          # collects articles; safe to run repeatedly
php jobs/rollup_stats.php         # rebuilds the feed's ranking stats
php jobs/find_feed.php <url>      # validates a blog's feed, prints SQL
```

```cron
0  3 * * * /usr/bin/php /var/www/blogfeed/backend/jobs/fetch_feeds.php  >> /var/log/feedjob.log 2>&1
30 3 * * * /usr/bin/php /var/www/blogfeed/backend/jobs/rollup_stats.php >> /var/log/feedjob.log 2>&1
```

`rollup_stats.php` runs **after** the fetch and turns raw interaction events
into the numbers the feed ranks on. Skipping it is not fatal — the feed keeps
working on yesterday's stats — but the ranking stops learning.

Cron does not read your shell profile — `feed_load_env()` reads `.env`
directly, which is what stops the job failing in production only.

## Tests

```bash
php tests/test_feedlib.php           # 35 parser tests, no database or network
php tests/test_scoring.php           # 43 ranking-formula tests, no database
php tests/test_feed_integration.php  # 18 end-to-end tests; NEEDS a database
```

The first two need nothing and run in milliseconds. The third creates its own
throwaway user, exercises events → rollup → ranking, and deletes that user
again — including when it fails.

The phase verification scripts live in `extra/scripts/`.

## Rules that must not be broken

- Every query is a prepared statement. No exceptions.
- Passwords bcrypt; **auth tokens stored as SHA-256, never raw**.
- A feed that parses but yields **zero usable articles is a FAILURE**, not a
  success — otherwise a dead blog is never deactivated and nobody is alerted.
- All dates stored and served in **UTC**.
- Money is `NUMERIC`, never a float.
- Excerpts only, never full article text.
- **A tap under 10 seconds is not a success.** Every rate the feed ranks on
  counts good taps only; break that and it learns to prefer better bait.
- Ranking constants live in **two files that must agree**:
  `src/Services/Scoring.php` and `jobs/rollup_stats.php`.
