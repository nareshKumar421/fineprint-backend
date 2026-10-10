# Deployment — fineprint.nareshkumar.online

The self-hosted deployment, alongside an unrelated application on the same
box. This is the only backend; the mobile app talks to it. The retired
Render/Neon deployment is described in [deployment.md](deployment.md), kept
for the reasons that outlived it.

## What runs where

| Piece | Where |
|---|---|
| App | `fineprint-app` container, Apache + mod_php, **host network**, Apache bound to `127.0.0.1:8081` |
| Database | `fineprint` database inside the **existing** `portfolio-db-1` container (PostgreSQL 18.6) |
| Web | host nginx 1.18 → `fineprint-http.conf`, `fineprint-https.conf` |
| TLS | Let's Encrypt, cert name `fineprint`, expires 2027-01-08, auto-renewing |
| Cron | `~/fineprint/sync.sh` at **03:10 UTC** |
| Host | `ubuntu@129.159.237.197`, Ubuntu 20.04, 2 vCPU, ~950MB RAM + 2GB swap |

## There is no database container

Deliberate. The host already runs PostgreSQL for the neighbouring app and has
under a gigabyte of RAM. A second instance would halve the page cache
available to each and double the backups, upgrades and connection limits for
isolation that **grants already provide**:

```sql
REVOKE CONNECT ON DATABASE portfolio FROM fineprint;   -- this role, by name
REVOKE CONNECT ON DATABASE fineprint FROM PUBLIC;
GRANT  CONNECT ON DATABASE fineprint TO   fineprint;
```

`PUBLIC` holds `CONNECT` on every database by default, so without the first
line the FinePrint credential could open the neighbour's database. Revoking
for *this role by name* leaves `PUBLIC` and the neighbour's own role exactly
as they were. Verified both directions: `fineprint → portfolio` is
`FATAL: permission denied`, and `rolsuper = false`.

Recorded in [`db/011_shared_db_role.sql`](../db/011_shared_db_role.sql).

## Why host networking

The neighbouring Postgres container also uses host networking and binds
`127.0.0.1:5432` — **loopback only**. A bridge-networked container cannot
reach that, and changing its bind address is not ours to change.

Host networking has a sharp edge: published ports mean nothing and the
process binds the host's interfaces directly. `BIND_ADDR=127.0.0.1` in
`docker-compose.yml` is what stops that becoming an internet-facing Apache.
Unset, `docker-start.sh` behaves exactly as before, so Render is unaffected.

Confirm it is loopback-only after any change:

```bash
sudo ss -tlnp | grep :8081     # must show 127.0.0.1:8081, never 0.0.0.0
```

## Deploying

From the workstation, in `app/`:

```bash
chmod +x deploy.sh        # once
./deploy.sh --check       # connectivity + preflight, changes nothing
./deploy.sh               # build, upload, restart, verify
./deploy.sh --rollback    # restore the previous release
```

It builds from `git HEAD` via `git archive`, so **uncommitted work is not
deployed** and the script refuses to run with a dirty tree. `.env` lives only
on the server and is preserved across releases. The previous release is kept
at `~/fineprint/app.prev`, which is what `--rollback` restores.

It checks the neighbouring site before *and* after, and scopes every compose
command with `-p fineprint` so nothing can touch the other project.

Override the key with `FINEPRINT_SSH_KEY=/path/to/key ./deploy.sh`.

## The sync job

`~/fineprint/sync.sh`, 03:10 UTC, logging to `~/fineprint/logs/sync.log`
(rotated weekly, six kept):

1. `fetch_feeds.php` — collect articles
2. `rollup_stats.php` — rebuild ranking stats
3. `purge_sessions.php` — drop expired dashboard sessions
4. `pipeline_report.php` — report, with warnings

Overlap is prevented by `flock -n` **inside the script**, not by the schedule:
a fetch across 76 feeds took 101 s here but can take far longer when upstreams
are slow, and two concurrent runs would double every outbound request to the
publishers. A late run is skipped, not queued — correct for an idempotent job
that comes round again.

Run it by hand any time: `~/fineprint/sync.sh && tail -40 ~/fineprint/logs/sync.log`

A cron that stops is invisible from the box itself. The two GitHub Actions
workflows below exist to notice; neither can run the pipeline, because the
database is bound to the server's loopback and a runner cannot reach it.

## Monitoring (GitHub Actions)

| Workflow | When | What |
|---|---|---|
| `nightly-jobs.yml` | 04:00 UTC daily | Reads `/health`; fails if the newest sync is older than 6 h — i.e. the 03:10 cron did not run or did not finish. Run by hand it asks the looser question, "has it run in the last day". |
| `uptime.yml` | every 15 min | `/health` must answer 200 with `database: up`; three attempts 20 s apart ride out a deploy restart. |

Both are credential-free (`/health` is public and returns nothing sensitive)
and both report by failing: GitHub emails the account that last edited the
workflow file. GitHub pauses scheduled workflows in a repository with no
commits for 60 days — the Actions tab shows a banner and a re-enable button.

A sync that finishes with some feeds failing exits 2. That is the normal
state of a 76-feed corpus — a publisher is always down somewhere — so the
job queue records it as **done**, with the failure count in the summary; the
Sources page lists which ones. Exit 1 is a fatal error and is recorded as
failed.

## Backups

**This is now yours.** Neon did it; a self-hosted database does not.

```bash
docker exec portfolio-db-1 pg_dump -U fineprint -h 127.0.0.1 \
  -d fineprint --no-owner -Fc > fineprint-$(date +%F).dump
```

Nothing schedules this, by decision — dumps were taken out of scope on
2026-10-10, and the dashboard neither takes nor tracks them. The recipe above
is the whole procedure if that changes.

Restore:

```bash
docker cp dump.file portfolio-db-1:/tmp/d
docker exec -e PGPASSWORD=... portfolio-db-1 \
  pg_restore -U fineprint -h 127.0.0.1 -d fineprint --no-owner --exit-on-error /tmp/d
```

## Gotchas

- **nginx here is 1.18**, so `http2 on;` is not a directive — it is a `listen`
  parameter: `listen 443 ssl http2;`. `nginx -t` catches it and `systemctl
  reload` then refuses, leaving the running config untouched. Always `nginx -t`
  before reload; it is what kept the neighbour at 200 when this was got wrong.
- **`crontab -l | grep -v … | crontab -` under `set -e` installs an empty
  crontab.** `grep` exits 1 when it matches nothing, which kills the subshell
  before the new lines are echoed, and `crontab` receives only headers. Install
  from a file instead — `crontab ~/fineprint/fineprint.cron`.
- The ACME location must stay on plain HTTP forever. A blanket redirect breaks
  renewal the moment the certificate it depends on expires.
- The app trusts `X-Forwarded-For` only because `TRUSTED_PROXY=1` is set.
  Without it the rate limiter keys every request to nginx's own address and
  throttles all clients as one.

## Rolling back

```bash
./deploy.sh --rollback          # application code
```

Database changes are not rolled back by that. Migrations in `db/` are additive
and idempotent by policy; to undo one, write a new numbered file that reverses
it rather than editing history.
