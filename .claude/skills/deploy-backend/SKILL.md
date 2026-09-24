---
name: deploy-backend
description: Build, run, drive, test and deploy the FinePrint backend API. Use when asked to deploy the backend, ship to production, run or start the API locally, build the Docker image, smoke-test the API, verify production health, check routes, or run the nightly feed jobs.
---

# Deploy the FinePrint backend

PHP 8 API, no framework, PostgreSQL (Neon, `us-east-2`). Deploying means
**pushing to `main`** — Render builds `Dockerfile` and swaps the container.
There is no build artifact to upload and no deploy script on the server.

Everything is driven by one script. Paths below are relative to `backend/`.

```
.claude/skills/deploy-backend/driver.sh
```

## Prerequisites

Nothing needed installing in this container. Verify rather than assume —
`pdo_pgsql` is the one that matters, because `new PDO('pgsql:...')` fails
immediately without it and nothing else in the app can run:

```bash
php -m | grep -iE "^(pdo_pgsql|pgsql|curl|mbstring|simplexml|dom)$"
```

Also needs `docker`, `psql`, `curl`, `jq`, `git`. `vendor/autoload.php` must
exist (`composer install`) — `bootstrap.php` requires it on the first line of
every request.

`.env` must be present. It is gitignored and never deployed; Render supplies
the same nineteen variables itself.

## Run (agent path)

```bash
./.claude/skills/deploy-backend/driver.sh preflight
```

Tests, syntax, `APP_DEBUG`, the Neon pooler trap, migration re-run safety.
No network. **7 checks, ~5s.**

### Run the actual production image

This is the same build Render runs, so a failure here is a failure there —
caught in seconds instead of in a build log after a push.

```bash
docker build -t fineprint-backend:local .            # ~2 min cold, 743MB
./.claude/skills/deploy-backend/driver.sh up 8899    # answers in ~3s
```

### Drive it

```bash
./.claude/skills/deploy-backend/driver.sh smoke http://127.0.0.1:8899
```

**17 checks.** Registers a throwaway account, picks topics, pulls a feed,
asserts the per-source diversity cap holds, posts interaction events, replays
the same batch to prove dedup, hides a source, checks validation and auth —
then **deletes the account through the public API**, which doubles as the
test for `DELETE /api/user/account`. Leaves nothing behind, needs no `psql`.

```bash
./.claude/skills/deploy-backend/driver.sh down
```

### Against a live host

```bash
./.claude/skills/deploy-backend/driver.sh verify
```

**10 checks** against production by default, or pass a URL. Creates no
account. Reports article count, last sync, and flags a cold start.

`smoke` also works against production — but it registers a real account and
then deletes it. Prefer `verify` unless you specifically need the write paths.

### Nightly jobs

```bash
./.claude/skills/deploy-backend/driver.sh jobs
```

`rollup_stats.php` then `pipeline_report.php`. The report warns about the
states that look healthy from outside and are not — feeds failing, blogs
deactivated, no interaction events ever recorded.

### Deploy

```bash
./.claude/skills/deploy-backend/driver.sh deploy
```

Refuses unless on `main` with a clean tree, runs preflight, pushes, waits for
Render to swap, then runs `verify`.

> **The push itself was not exercised in this session** — it would have
> deployed unreviewed work. The guard was: run from a feature branch, it
> exits 1 with `on 'feed-view-caps' — Render only builds main. Merge first.`
> Everything after the push is `verify`, which was run.

**Migrations are not part of the deploy.** There is no runner and no
applied-migrations table. Apply by hand, *before* pushing code that needs
them:

```bash
psql "$FEED_DB_URL" -f db/009_view_caps.sql
```

## Run (human path)

```bash
php -S 0.0.0.0:8000 -t public
```

Development only. Skips Apache, the MPM config and the document-root
rewriting, so it cannot catch the class of failure the Dockerfile exists to
prevent. Use `driver.sh up` when the answer matters.

## Gotchas

- **`.env` quotes must be stripped before Docker sees them.** `.env` quoting
  is a shell convention; `--env-file` stores the value literally, so a quoted
  DSN arrives with quotes attached and the connection fails. `driver.sh up`
  strips them into a temp file — do not hand `.env` to `docker run` directly.

- **The first request to production takes 30–50 seconds.** The free tier
  stops the container after ~15 minutes idle. Measured 44s once and 12.5s in
  this session. Every curl in the driver uses `--max-time 90` for this reason;
  a 15s timeout reports a healthy service as unreachable.

- **The DSN must not contain `-pooler`.** Neon's pooler silently discards
  explicit transactions when PDO uses server-side prepared statements — every
  category save, donation and password change reports success and writes
  nothing. `preflight` checks for it.

- **`docker build` succeeding is not the same as the container starting.**
  Apache dies on "More than one MPM loaded" and the platform restarts it in a
  loop; from outside that is a 502 identical to a build that never ran. Only
  `driver.sh up` catches it, because it waits for an actual HTTP answer and
  dumps `docker logs` if none arrives.

- **A `401` from a route is a pass, not a failure.** `verify` uses it to prove
  the route exists and auth middleware runs. A `404` there means the route is
  missing — which is how you tell an old container from a new one.

- **The idempotency check is a heuristic.** It greps for `IF NOT EXISTS`,
  `ON CONFLICT`, `OR REPLACE`, `DROP … IF EXISTS` and `UPDATE … WHERE`. It
  flagged `006_activate_all_categories.sql` as unsafe on the first run; that
  file is one `UPDATE … WHERE is_active = false`, which is idempotent because
  the second run matches zero rows. Widening the pattern fixed it. A new
  migration that guards itself some other way will trip it again — read the
  file before believing the check.

- **Only `public/` is web-reachable.** The Dockerfile enforces it by rewriting
  the document root. `src/`, `jobs/`, `db/` and `.env` sit one level above and
  cannot be fetched over HTTP.

## Troubleshooting

| Symptom | Cause / fix |
|---|---|
| `driver.sh up` → "never answered", logs show Apache exiting | More than one MPM loaded. `docker-start.sh` disables the extras at runtime; if you edited it, that is why. |
| `preflight` → "no re-run guard found in: db/0NN.sql" | Either genuinely unsafe, or the heuristic does not recognise the guard. Read the file. |
| `preflight` → "vendor/autoload.php MISSING" | `composer install`. Never committed — `bootstrap.php` requires it on line 1. |
| `smoke` → "feed empty (phase=archive)" | The account exhausted its topics, or those topics have no recent articles. Check `articles … inside the 30-day feed window` in `driver.sh jobs`. |
| `smoke` → "one source took N of 20 slots" | The per-source diversity cap regressed. It has happened in production before. See `docs/feed-algorithm-simple.md` §6. |
| `verify` → first response >10s | Cold start, not an outage. The driver labels it. |
| `deploy` → "on '<branch>' — Render only builds main" | Working as intended. Merge first. |
| `jobs` → "no interaction events have EVER been recorded" | Expected until a mobile build with analytics ships. Until then every reader gets the cold-start ranking formula. |

## Related

- `docs/deployment.md` — Render/Neon specifics, env vars, rollback
- `docs/feed-behaviour.md`, `docs/feed-scenarios.md` — what the API does
- `.github/workflows/nightly-jobs.yml` — the scheduled version of `driver.sh jobs`
