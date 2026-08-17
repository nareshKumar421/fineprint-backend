# Deployment — dedicated server or VPS

Running the FinePrint API on a machine you control, rather than a managed
platform. For the current managed setup see [deployment.md](deployment.md).

Assumes Ubuntu 24.04 and root or sudo. Only `backend/` is deployed —
`mobile/` never reaches the server; it is compiled into an APK and shipped
through the store.

**Why you might want this.** A managed free tier gave us no cron, a
container that sleeps after 15 minutes, and a hostname a mobile carrier
refused to resolve. A server you control has none of those problems: real
cron, always on, and your own domain. The cost is that backups, patching
and TLS renewal become yours.

---

## 1. Two ways to run it

**Docker** — reuses the `Dockerfile` already in this repo, so the PHP
version, extensions and Apache config are identical to what is tested. One
moving part. Recommended unless you have a reason not to.

**Native PHP-FPM + nginx** — fewer layers, marginally faster, and matches
the layout in `docs/08-security-and-operations.md`. More to get right.

Both are documented below. Sections 4 onwards apply to either.

---

## 2. Prepare the machine

```bash
adduser --disabled-password --gecos "" fineprint
apt update && apt upgrade -y

ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw enable
```

Nothing else should be reachable. In particular **do not** expose
PostgreSQL — if the database is on this machine it listens on localhost
only.

Point an `A` record at the server before requesting a certificate:

```
api.yourdomain.com.   A   <server-ip>
```

If the app must work on IPv6-only mobile networks, add an `AAAA` record
too. An IPv4-only host is usually still reachable through the carrier's
NAT64, but that is their infrastructure, not a guarantee — and a dead AAAA
is far worse than none, because clients prefer IPv6 and hang on it.

---

## 3a. Option A — Docker

```bash
apt install -y docker.io docker-compose-v2 git
usermod -aG docker fineprint

su - fineprint
git clone https://github.com/nareshKumar421/fineprint-backend.git app
cd app
```

Create `.env` from `.env.example` with production values, then:

```bash
chmod 600 .env
docker build -t fineprint-api .
docker run -d --name fineprint-api --restart unless-stopped \
  -p 127.0.0.1:8080:8080 -e PORT=8080 --env-file .env fineprint-api
```

Bind to `127.0.0.1`, not `0.0.0.0` — nginx terminates TLS in front and the
container should not be reachable from outside directly.

`--env-file` does **not** strip quotes the way a shell does. Either write
`.env` without quotes for this purpose or pass variables with `-e`. A
quoted DSN arrives with the quotes attached and the connection fails with
a confusing error.

Then nginx as a reverse proxy:

```nginx
server {
    listen 443 ssl;
    http2 on;
    server_name api.yourdomain.com;

    ssl_certificate     /etc/letsencrypt/live/api.yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/api.yourdomain.com/privkey.pem;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host              $host;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}

server {
    listen 80;
    server_name api.yourdomain.com;
    return 301 https://$host$request_uri;
}
```

---

## 3b. Option B — native PHP-FPM + nginx

```bash
apt install -y nginx php8.3-fpm php8.3-pgsql php8.3-curl \
               php8.3-mbstring php8.3-xml composer git
```

PHP 8.3, not 8.5 as `docs/08` suggests — `composer.json` requires `>=8.2`
and 8.3 is what the container runs and what this is tested against.

```bash
mkdir -p /var/www/blogfeed
chown fineprint:fineprint /var/www/blogfeed
su - fineprint
git clone https://github.com/nareshKumar421/fineprint-backend.git /var/www/blogfeed/backend
cd /var/www/blogfeed/backend
composer install --no-dev --optimize-autoloader
```

`vendor/` is gitignored and `bootstrap.php` requires `vendor/autoload.php`
on the first line of every request, so the `composer install` is not
optional.

Create `.env`, then lock it down:

```bash
chmod 600 .env
chown fineprint:fineprint .env
```

nginx:

```nginx
server {
    listen 443 ssl;
    http2 on;
    server_name api.yourdomain.com;

    root /var/www/blogfeed/backend/public;   # public/ ONLY, never the parent
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/api.yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/api.yourdomain.com/privkey.pem;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;

        # Without this EVERY authenticated request 401s in production while
        # working perfectly in development. nginx drops the Authorization
        # header otherwise and the token never reaches PHP.
        fastcgi_param HTTP_AUTHORIZATION $http_authorization;
    }

    location ~ /\. { deny all; }
}

server {
    listen 80;
    server_name api.yourdomain.com;
    return 301 https://$host$request_uri;
}
```

Set the PHP-FPM pool to run as `fineprint` in
`/etc/php/8.3/fpm/pool.d/www.conf`, so the only account that can read
`.env` is the one that needs to.

---

## 4. TLS

```bash
apt install -y certbot python3-certbot-nginx
certbot --nginx -d api.yourdomain.com
systemctl status certbot.timer      # renewal is automatic; confirm it is active
```

---

## 5. Cron — the thing managed free tiers could not give you

This is the main reason to run your own server. Without it the feed
freezes and `last_sync_at` stops moving while the app quietly serves older
and older content.

```bash
su - fineprint
crontab -e
```

```cron
# Fetch feeds nightly, before peak hours. Takes ~20 minutes for 77 feeds.
0 3 * * * /usr/bin/php /var/www/blogfeed/backend/jobs/fetch_feeds.php >> /var/log/feedjob.log 2>&1
```

Docker equivalent:

```cron
0 3 * * * /usr/bin/docker exec fineprint-api php /var/www/html/jobs/fetch_feeds.php >> /var/log/feedjob.log 2>&1
```

Cron does not read your shell profile. `feed_load_env()` reads `.env`
directly, which is what stops the job working by hand and failing silently
from cron.

The job is safe to run more often than needed — duplicate articles are
rejected by the unique index on `(guid, category_id)`, so extra runs cost
bandwidth only.

Log rotation, or `/var/log/feedjob.log` grows without limit:

```
/var/log/feedjob.log {
    weekly
    rotate 8
    compress
    missingok
    notifempty
}
```

### Checking it actually ran

```bash
curl -s https://api.yourdomain.com/ | grep -o '"last_sync_at":"[^"]*"'
```

If that timestamp is older than a day, cron is not running. This is worth
an alert — it fails silently and the app keeps serving stale content, so
nobody reports it.

---

## 6. Database

If you stay on managed Postgres (Neon), nothing changes — keep using the
**direct** endpoint, not the pooler. The pooler silently discards
transactions with server-side prepared statements; see
[deployment.md](deployment.md) §5.

If you move the database onto this server:

```bash
apt install -y postgresql
sudo -u postgres createuser --pwprompt fineprint
sudo -u postgres createdb --owner=fineprint fineprint
psql -U fineprint -d fineprint -f db/schema.sql
psql -U fineprint -d fineprint -f db/seed_categories.sql
```

Then apply the numbered migrations in order:

```bash
for f in db/0*.sql; do echo "-- $f"; psql -U fineprint -d fineprint -f "$f"; done
```

Keep `listen_addresses = 'localhost'` in `postgresql.conf`. A database on
the public internet is found by scanners within hours.

### Backups

Nightly dump, two weeks retained:

```cron
30 2 * * * pg_dump "$FEED_DB_URL" | gzip > /var/backups/fineprint-$(date +\%F).sql.gz
0  4 * * * find /var/backups -name 'fineprint-*.sql.gz' -mtime +14 -delete
```

A backup you have never restored is not a backup. Restore one into a
scratch database and run the verification in section 8 against it.

---

## 7. Updating

```bash
su - fineprint
cd /var/www/blogfeed/backend
git pull
composer install --no-dev --optimize-autoloader   # native only
psql "$FEED_DB_URL" -f db/0NN_whatever.sql        # if a migration is new
sudo systemctl reload php8.3-fpm                  # native
# Docker: docker build -t fineprint-api . && docker restart fineprint-api
```

Migrations in this project are written to be safe to re-run — `ADD COLUMN
IF NOT EXISTS`, `ON CONFLICT DO NOTHING` — so applying one twice is not an
incident.

Rolling back is `git checkout <previous-tag>` plus the same rebuild.
Database migrations do not roll back automatically; that is deliberate.

---

## 8. Verify before calling it done

```bash
U=https://api.yourdomain.com

curl -s $U/                        # database: up
curl -s $U/api/categories          # returns active categories with icons

TK=$(curl -s -X POST $U/api/register -H 'Content-Type: application/json' \
  -d '{"email":"check@example.com","password":"TestPass12345"}' \
  | python3 -c 'import json,sys; print(json.load(sys.stdin)["token"])')

# The write path. A pooled connection makes this report success and change
# nothing, so the read-back is the actual test.
curl -s -X POST -H "Authorization: Bearer $TK" -H 'Content-Type: application/json' \
  -d '{"category_ids":[1,3]}' $U/api/user/categories
curl -s -H "Authorization: Bearer $TK" $U/api/user/categories    # must be [1,3]

curl -s -H "Authorization: Bearer $TK" "$U/api/feed?limit=2"     # returns articles

# Nothing above the document root is served — every one must be 404
for p in /.env /src/Env.php /composer.json /db/schema.sql; do
  echo "$p -> $(curl -s -o /dev/null -w '%{http_code}' $U$p)"
done
```

Delete the test account afterwards.

Then confirm the hostname resolves on a **mobile carrier**, not just your
WiFi. A previous host was unreachable for every Jio user while working
perfectly on broadband, and the DNS zone itself was healthy everywhere
else. Nothing but testing on the actual network finds that.

---

## 9. Security checklist

- `.env` is `chmod 600` and owned by the PHP user. It holds the database
  password and the Instamojo salt.
- `APP_DEBUG=false`. Debug output leaks file paths, SQL and stack traces.
- The web root is `public/`. `src/`, `db/`, `jobs/`, `vendor/` and `.env`
  must all 404 — verify, do not assume.
- PostgreSQL listens on localhost only.
- Only 22, 80 and 443 are open.
- TLS renewal is automatic and `certbot.timer` is active.
- Unattended security upgrades: `apt install unattended-upgrades`.
- Rate limits are enforced in the app (`5/min` on login and register,
  `10/hour` on donations). They are not nginx's job and do not need
  duplicating there.
