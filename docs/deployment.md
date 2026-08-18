# Deployment — managed hosting (current setup)

How the FinePrint API is deployed today, how to redeploy it, and the
failures that are worth knowing about before you hit them. For a VPS or
dedicated server instead, see [dedicated-server.md](dedicated-server.md).

---

## 1. What is running where

| Piece | Where | Detail |
|---|---|---|
| API | Render | `fineprint-backend`, service `srv-da0qocugekts73fliau0`, region **Ohio**, free plan |
| Public URL | | `https://fineprint-backend-gsgu.onrender.com` |
| Database | Neon (managed PostgreSQL) | AWS `us-east-2`, **direct** endpoint — not the pooler |
| Source | GitHub | `nareshKumar421/fineprint-backend`, branch `main` |
| Build | Docker | `Dockerfile` in the repo root; Render builds it on every push |
| Keep-alive | GitHub Actions | `.github/workflows/keep-warm.yml`, every 10 minutes |

Ohio is deliberate. The database is in AWS `us-east-2`, and a request
makes several round trips to it, so co-locating the app with the database
matters more than being close to the user. Moving the app from a
Singapore-edge host to Ohio took the health endpoint from ~1.2s to ~107ms.

---

## 2. Deploying

### Routine deploy

Push to `main`. Render builds the Dockerfile and swaps the container when
the new one is healthy.

```bash
git push origin main
```

### First-time setup on a new Render account

```bash
render login
render workspace set <workspace-id>

render services create \
  --name fineprint-backend \
  --type web_service \
  --repo https://github.com/nareshKumar421/fineprint-backend \
  --branch main \
  --runtime docker \
  --plan free \
  --region ohio \
  --health-check-path / \
  --env-var KEY=VALUE ... \
  --confirm
```

Pass every variable from section 3 as its own `--env-var`. Strip the
surrounding quotes from `.env` values first — `.env` quoting is a shell
convention and Render stores the value literally, so a quoted DSN arrives
with the quotes still attached and the connection fails.

### Rolling back

```bash
render deploys list --output json    # find the previous deploy id
```

Or `git revert` the bad commit and push — Render redeploys automatically.

---

## 3. Environment variables

Nineteen variables, all from `.env`. `Env::load()` skips a missing `.env`
file and real environment variables always win, so no `.env` is deployed —
the platform supplies them.

| Variable | Notes |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` — always. Debug output leaks paths, SQL and stack traces |
| `APP_URL` | Must be the real public URL. Instamojo webhook and redirect URLs are built from it |
| `FEED_DB_DSN` | **Must not contain `-pooler`** — see section 5 |
| `FEED_DB_USER` / `FEED_DB_PASS` | Neon credentials |
| `FEED_DB_URL` | libpq URL for `psql`/`pg_dump`; password must be percent-encoded |
| `TOKEN_TTL_DAYS` | Session lifetime |
| `PAYMENTS_ENABLED` | `false` while donations are collected by UPI |
| `DONATION_UPI_ID` / `DONATION_UPI_NAME` | Public by design — shown on screen |
| `INSTAMOJO_*` | Empty until payments go live |
| `FEED_USER_AGENT` | How publishers identify us in their logs |
| `FEED_HTTP_TIMEOUT`, `FEED_MAX_FAILURES`, `FEED_EXCERPT_CHARS` | Fetch job tuning |

`PORT` is **not** set. Render injects it and `docker-start.sh` binds
whatever arrives, falling back to 8080. Some platforms do not inject it —
Railway did not, and the container listened on 8080 while the edge routed
elsewhere, which presents as a 502 from a perfectly healthy container.

Read them back with:

```bash
curl -s -H "Authorization: Bearer $RENDER_API_KEY" \
  "https://api.render.com/v1/services/<service-id>/env-vars?limit=50"
```

---

## 4. Verifying a deploy

Run all of these. The first three passing is not enough — the write path
has failed silently before while reads looked perfect.

```bash
U=https://fineprint-backend-gsgu.onrender.com

# 1. health — must say database: up
curl -s $U/

# 2. public read
curl -s $U/api/categories

# 3. register
TK=$(curl -s -X POST $U/api/register -H 'Content-Type: application/json' \
  -d '{"email":"check@example.com","password":"TestPass12345"}' \
  | python3 -c 'import json,sys; print(json.load(sys.stdin)["token"])')

# 4. THE IMPORTANT ONE — a write inside a transaction, then read it back.
#    If the save reports success and the read-back does not match, the
#    database connection is going through a pooler. See section 5.
curl -s -X POST -H "Authorization: Bearer $TK" -H 'Content-Type: application/json' \
  -d '{"category_ids":[1,3]}' $U/api/user/categories
curl -s -H "Authorization: Bearer $TK" $U/api/user/categories   # must be [1,3]

# 5. feed returns articles
curl -s -H "Authorization: Bearer $TK" "$U/api/feed?limit=2"

# 6. nothing above the document root is served — all must be 404
for p in /.env /src/Env.php /composer.json /db/schema.sql /Dockerfile; do
  echo "$p -> $(curl -s -o /dev/null -w '%{http_code}' $U$p)"
done
```

Delete the test account afterwards.

---

## 5. Failures that have actually happened

Each of these cost real debugging time. They are listed because none of
them announce themselves.

### A pooled database connection silently discards transactions

Neon's `-pooler` endpoint, combined with PDO server-side prepared
statements (`ATTR_EMULATE_PREPARES => false`, which `Db.php` sets
deliberately), makes explicit transactions do **nothing**. `BEGIN`,
`DELETE`, `INSERT` and `COMMIT` all report success, `inTransaction()`
returns false after the commit, and the same connection then reads back
the old rows. No error is raised anywhere.

Every endpoint using `Db::transaction` was affected — saving topics,
donations, display name, password change. All returned `200 {"success":true}`
and wrote nothing. Registration was unaffected because it uses a plain
`INSERT`, which is why the symptom looked like an empty feed rather than
broken writes.

**Use the direct endpoint.** Emulated prepares also fix it, but they change
how `LIMIT` and cast placeholders bind, which risks breaking the feed
queries in subtler ways.

Because there is no pooler, Apache's worker count is the connection count.
The Dockerfile caps prefork at 16 for exactly this reason — the default of
150 would exhaust Neon's connection limit under load.

### Two Apache MPMs loaded

`apache2: Configuration error: More than one MPM loaded.` Apache refuses to
start, the platform restarts it forever, and from outside it is only ever a
502. The build's `apache2ctl configtest` passed, so this was invisible until
the container logs were read. `docker-start.sh` now disables the extra MPMs
on every start, and logs what it found.

### Listening on IPv4 only

`Listen <port>` binds `0.0.0.0`. Platforms with IPv6-only internal networks
cannot reach that, and answer 502 with a healthy container behind them.
`docker-start.sh` binds `[::]` instead, which serves both families when
`bindv6only=0`.

### The hostname itself being blocked

Jio's mobile DNS does not resolve `*.up.railway.app`, `railway.com` or
`*.fly.dev`, while resolving `*.onrender.com`, `*.koyeb.app`, `*.vercel.app`
and `*.netlify.app` normally. The app worked on WiFi and showed "Offline" on
mobile data for every Jio user.

**Before choosing a host, check the hostname resolves on a mobile carrier.**
A healthy DNS zone proves nothing — Google, Cloudflare, Quad9 and OpenDNS all
resolved the Railway name fine. A custom domain removes this risk entirely
and is the real fix.

### A dead IPv6 address on the host

`php-b50f5.wasmer.app` publishes an AAAA record that never answers. Clients
prefer IPv6, so every request hung until the client timeout. Check both
families before trusting a host:

```bash
curl -4 -s -o /dev/null -w '%{time_total}\n' https://host/
curl -6 -s -o /dev/null -w '%{time_total}\n' https://host/
```

---

## 6. Scheduled work, and one open gap

### The nightly pipeline — CLOSED

`.github/workflows/nightly-jobs.yml` runs at **03:10 UTC**:

1. `jobs/fetch_feeds.php` — collect articles
2. `jobs/rollup_stats.php` — rebuild the feed's ranking stats
3. `db/cleanup.sql` — retention
4. `jobs/pipeline_report.php` — what actually happened

GitHub Actions rather than a Render Cron Job, which is paid, and the
account's 750 instance-hours are already nearly spent on keep-warm.

It logs in as **`fineprint_ci`**, not `neondb_owner` — see
[db/008_ci_role.sql](../db/008_ci_role.sql). This repository is public, so
its Actions secrets hold a credential that cannot read password hashes or
emails, cannot touch donations, cannot delete users and cannot run DDL. If it
leaks, `DROP ROLE fineprint_ci` ends it and the application is unaffected.

Four repository secrets are required: `FEED_DB_DSN`, `FEED_DB_USER`,
`FEED_DB_PASS`, `FEED_DB_URL`. The workflow fails on its first step with a
named error if any is missing, rather than surfacing it later as a confusing
"cannot connect to database".

Check it worked:

```bash
gh run list --workflow nightly-jobs --limit 5
curl -s https://fineprint-backend-gsgu.onrender.com/ | grep -o '"last_sync_at":"[^"]*"'
php jobs/pipeline_report.php        # locally, against the same database
```

### Still open: free-tier sleep

Render's free plan stops the container after ~15 minutes idle, and the next
request pays a 30–50s cold start. The app gives up after
`REQUEST_TIMEOUT_MS` (15s) and shows a stale cached feed with an offline
banner — it looks broken when it is merely asleep. This was reproduced on a
real device.

`keep-warm.yml` pings every 10 minutes to prevent it. Note the arithmetic:
the free plan allows 750 instance-hours a month and a month is ~730 hours,
so staying awake fits with very little spare. **Adding a second free service
will push the account over and something will be suspended.**

GitHub's scheduler is best-effort and can lag by several minutes, so the
occasional cold start is still possible. A paid instance removes both this
and the cron gap.
