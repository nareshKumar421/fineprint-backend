#!/usr/bin/env bash
#
# driver.sh — build, run, drive and deploy the FinePrint API.
#
# The one handle an agent needs on this service. Every subcommand is
# non-interactive and reports pass/fail by exit code.
#
#   ./driver.sh preflight          tests + syntax + config sanity (no network)
#   ./driver.sh image [tag]        build the PRODUCTION Dockerfile locally
#   ./driver.sh up [port]          run that image, wait until it answers
#   ./driver.sh down               stop and remove it
#   ./driver.sh smoke [base-url]   drive the whole API end to end
#   ./driver.sh verify [base-url]  health + route checks against a live host
#   ./driver.sh jobs               run the nightly pipeline against the database
#   ./driver.sh deploy             push main; Render builds and swaps
#
# Paths are relative to backend/. Run it from there.

set -uo pipefail

HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
ROOT=$(cd "$HERE/../../.." && pwd)
cd "$ROOT"

IMAGE=${IMAGE:-fineprint-backend:local}
CONTAINER=fineprint-local
PROD_URL=https://fineprint-backend-gsgu.onrender.com

pass=0; fail=0
ok()   { pass=$((pass+1)); printf '  \033[32m[PASS]\033[0m %s\n' "$1"; }
bad()  { fail=$((fail+1)); printf '  \033[31m[FAIL]\033[0m %s\n' "$1"; }
info() { printf '\033[2m--- %s\033[0m\n' "$1"; }
die()  { printf '\033[31mFATAL\033[0m %s\n' "$1" >&2; exit 1; }

check() { # check <description> <expected> <actual>
  if [ "$2" = "$3" ]; then ok "$1 ($3)"; else bad "$1 — expected [$2] got [$3]"; fi
}

summary() {
  printf '\n----------------------\npassed: \033[32m%d\033[0m  failed: \033[31m%d\033[0m\n\n' "$pass" "$fail"
  [ "$fail" -eq 0 ]
}

# ---------------------------------------------------------------- #
#  preflight — everything that can fail without leaving the machine
# ---------------------------------------------------------------- #
preflight() {
  info "PHP syntax, every file"
  local bad_files
  bad_files=$(find src jobs public tests db -name '*.php' -print0 2>/dev/null \
              | xargs -0 -n1 php -l 2>&1 | grep -v '^No syntax errors' || true)
  [ -z "$bad_files" ] && ok "all PHP files parse" || bad "syntax errors:\n$bad_files"

  info "autoloader present (bootstrap.php requires it on line 1 of every request)"
  [ -f vendor/autoload.php ] && ok "vendor/autoload.php exists" \
    || bad "vendor/autoload.php MISSING — run: composer install"

  info "unit suites (no database, no network)"
  for suite in test_feedlib test_scoring; do
    if php "tests/$suite.php" >/dev/null 2>&1; then
      ok "$suite"
    else
      bad "$suite — run it directly to see which case"
    fi
  done

  info "production safety switches in .env"
  if [ -f .env ]; then
    local debug dsn
    debug=$(sed -n 's/^APP_DEBUG=//p' .env | tr -d '"'"'"' ' | head -1)
    [ "$debug" = "false" ] && ok "APP_DEBUG=false" \
      || bad "APP_DEBUG=$debug — debug output leaks paths, SQL and stack traces"

    dsn=$(sed -n 's/^FEED_DB_DSN=//p' .env | head -1)
    case "$dsn" in
      *-pooler*) bad "DSN uses Neon's POOLER — it silently discards explicit transactions when PDO uses server-side prepares" ;;
      *)         ok  "DSN is the direct endpoint, not the pooler" ;;
    esac
  else
    bad ".env missing — jobs read it directly, cron does not read your shell profile"
  fi

  # Migrations are applied BY HAND here — there is no runner and no
  # applied-migrations table, so anything not safe to re-run is a foot-gun
  # the first time someone replays the directory on a fresh database.
  #
  # A heuristic, not a proof. `UPDATE ... WHERE <already-done-condition>` is
  # idempotent because the second run matches zero rows, which is why 006
  # counts even though it guards nothing explicitly.
  info "migrations are idempotent (applied by hand, no runner)"
  local nonidem
  nonidem=$(grep -LiE 'IF NOT EXISTS|ON CONFLICT|OR REPLACE|DROP [A-Z]+ IF EXISTS|UPDATE .* WHERE' \
            db/0*.sql 2>/dev/null || true)
  [ -z "$nonidem" ] && ok "every db/0*.sql is safe to re-run" \
    || bad "no re-run guard found in: $(echo $nonidem | tr '\n' ' ')"

  summary
}

# ---------------------------------------------------------------- #
#  image / up / down — the REAL production image, locally
# ---------------------------------------------------------------- #
image() {
  local tag=${1:-$IMAGE}
  info "docker build -t $tag ."
  docker build -t "$tag" . || die "image build failed — this is the same build Render runs"
  ok "image built: $tag"
  summary
}

up() {
  local port=${1:-8899}
  [ -f .env ] || die ".env required — the container gets its config from it"

  docker rm -f "$CONTAINER" >/dev/null 2>&1 || true

  info "starting $IMAGE on :$port"
  # --env-file, not -e: nineteen variables, and Render supplies them the
  # same way. Quotes must be stripped — .env quoting is a shell convention
  # and Docker stores the value literally, so a quoted DSN arrives with the
  # quotes attached and the connection fails.
  sed -E 's/^([A-Z_]+)="(.*)"$/\1=\2/; s/^([A-Z_]+)='"'"'(.*)'"'"'$/\1=\2/' .env > /tmp/fineprint.env

  docker run -d --name "$CONTAINER" -p "$port:8080" -e PORT=8080 \
    --env-file /tmp/fineprint.env "$IMAGE" >/dev/null || die "docker run failed"

  info "waiting for it to answer"
  local i
  for i in $(seq 1 45); do
    if curl -fsS --max-time 3 "http://127.0.0.1:$port/" >/dev/null 2>&1; then
      ok "answering on http://127.0.0.1:$port after ${i}s"
      rm -f /tmp/fineprint.env
      summary; return
    fi
    sleep 1
  done

  rm -f /tmp/fineprint.env
  printf '\n--- container logs ---\n'; docker logs "$CONTAINER" 2>&1 | tail -30
  die "never answered — logs above"
}

down() {
  docker rm -f "$CONTAINER" >/dev/null 2>&1 && ok "stopped $CONTAINER" || info "not running"
}

# ---------------------------------------------------------------- #
#  smoke — drive the whole API, then delete the account it created
# ---------------------------------------------------------------- #
smoke() {
  local base=${1:-http://127.0.0.1:8899}
  local email="smoke-$(openssl rand -hex 4)@example.invalid"
  local pw='CorrectHorse9!'
  local j='Content-Type: application/json'

  info "health"
  local health status
  health=$(curl -s --max-time 90 "$base/")
  status=$(echo "$health" | jq -r '.status // "unparseable"')
  check "GET / reports ok" "ok" "$status"
  check "database reachable" "up" "$(echo "$health" | jq -r '.database // "?"')"

  info "privacy policy is public (both app stores fetch this URL)"
  check "GET /privacy" "200" "$(curl -s -o /dev/null -w '%{http_code}' --max-time 60 "$base/privacy")"

  info "register"
  local token
  token=$(curl -s --max-time 60 -X POST "$base/api/register" -H "$j" \
          -d "{\"email\":\"$email\",\"password\":\"$pw\"}" | jq -r '.token // empty')
  [ -n "$token" ] && ok "registered $email" || { bad "register failed"; summary; return 1; }
  local auth="Authorization: Bearer $token"

  info "topics"
  local cats
  cats=$(curl -s --max-time 60 "$base/api/categories" | jq -r '[.categories[].id] | @csv' | tr -d '"')
  [ -n "$cats" ] && ok "categories listed" || bad "no categories"
  local pick
  pick=$(echo "$cats" | cut -d, -f1-4)
  check "save topics" "true" \
    "$(curl -s --max-time 60 -X POST "$base/api/user/categories" -H "$auth" -H "$j" \
        -d "{\"category_ids\":[$pick]}" | jq -r '.success // false')"

  info "feed"
  local feed n phase first
  feed=$(curl -s --max-time 60 "$base/api/feed?limit=20" -H "$auth")
  n=$(echo "$feed" | jq '.articles | length')
  phase=$(echo "$feed" | jq -r '.phase')
  first=$(echo "$feed" | jq -r '.articles[0].id // empty')
  [ "$n" -gt 0 ] && ok "feed returned $n articles (phase=$phase)" || bad "feed empty (phase=$phase)"

  # The per-source diversity cap is the one feed invariant a smoke test can
  # check cheaply, and it has regressed in production before.
  local maxsrc
  maxsrc=$(echo "$feed" | jq '[.articles[].source] | group_by(.) | map(length) | max // 0')
  [ "$maxsrc" -le 2 ] && ok "no source exceeds the cap of 2 (max $maxsrc)" \
                      || bad "one source took $maxsrc of $n slots — diversity pass regressed"

  if [ -n "$first" ]; then
    info "events"
    check "events accepted" "2" \
      "$(curl -s --max-time 60 -X POST "$base/api/events" -H "$auth" -H "$j" \
          -d "{\"events\":[{\"id\":\"s1\",\"type\":\"impression\",\"article_id\":$first,\"position\":0,\"session_id\":\"sm\"},{\"id\":\"s2\",\"type\":\"tap\",\"article_id\":$first,\"dwell_ms\":30000}]}" \
          | jq -r '.accepted')"

    # A retried flush must store nothing. Double-counted events corrupt every
    # rate the ranking uses, silently.
    check "replayed batch accepts 0" "0" \
      "$(curl -s --max-time 60 -X POST "$base/api/events" -H "$auth" -H "$j" \
          -d "{\"events\":[{\"id\":\"s1\",\"type\":\"impression\",\"article_id\":$first,\"position\":0,\"session_id\":\"sm\"}]}" \
          | jq -r '.accepted')"

    info "hidden sources"
    curl -s --max-time 60 -X POST "$base/api/events" -H "$auth" -H "$j" \
      -d "{\"events\":[{\"id\":\"s3\",\"type\":\"hide_source\",\"article_id\":$first}]}" >/dev/null
    local hidden
    hidden=$(curl -s --max-time 60 "$base/api/user/hidden-sources" -H "$auth" | jq '.sources | length')
    [ "$hidden" -ge 1 ] && ok "hiding a source is listed back ($hidden)" || bad "hide_source did not register"
  fi

  info "validation"
  check "limit=abc rejected" "400" \
    "$(curl -s -o /dev/null -w '%{http_code}' --max-time 60 "$base/api/feed?limit=abc" -H "$auth")"
  check "garbage cursor restarts rather than 500ing" "200" \
    "$(curl -s -o /dev/null -w '%{http_code}' --max-time 60 "$base/api/feed?cursor=nonsense" -H "$auth")"
  check "no token is 401" "401" \
    "$(curl -s -o /dev/null -w '%{http_code}' --max-time 60 "$base/api/feed")"

  info "cleanup — the smoke account deletes itself through the public API"
  check "wrong password refused" "401" \
    "$(curl -s -o /dev/null -w '%{http_code}' --max-time 60 -X DELETE "$base/api/user/account" \
        -H "$auth" -H "$j" -d '{"password":"wrong"}')"
  check "account deleted" "true" \
    "$(curl -s --max-time 60 -X DELETE "$base/api/user/account" -H "$auth" -H "$j" \
        -d "{\"password\":\"$pw\"}" | jq -r '.success // false')"
  check "token is dead afterwards" "401" \
    "$(curl -s -o /dev/null -w '%{http_code}' --max-time 60 "$base/api/feed" -H "$auth")"

  summary
}

# ---------------------------------------------------------------- #
#  verify — cheap checks against a live host, no account created
# ---------------------------------------------------------------- #
verify() {
  local base=${1:-$PROD_URL}
  info "health ($base)"

  # First request may pay a cold start: the free tier sleeps after ~15
  # minutes and takes 30-50s to wake. 90s covers it.
  local t health
  t=$(curl -s -o /tmp/health.json -w '%{time_total}' --max-time 90 "$base/") || die "unreachable"
  health=$(cat /tmp/health.json)
  check "status" "ok" "$(echo "$health" | jq -r '.status // "?"')"
  check "database" "up" "$(echo "$health" | jq -r '.database // "?"')"
  printf '  first response: %ss %s\n' "$t" \
    "$(awk -v t="$t" 'BEGIN{print (t>10)?"(cold start — the container was asleep)":""}')"

  local sync
  sync=$(echo "$health" | jq -r '.content.last_sync_at // "never"')
  printf '  articles: %s   last sync: %s\n' "$(echo "$health" | jq -r '.content.articles // "?"')" "$sync"

  info "routes exist (401 proves the route is there and auth runs)"
  for route in /api/feed /api/user/categories /api/user/hidden-sources /api/user/profile; do
    check "GET $route" "401" "$(curl -s -o /dev/null -w '%{http_code}' --max-time 60 "$base$route")"
  done
  check "POST /api/events" "401" \
    "$(curl -s -o /dev/null -w '%{http_code}' --max-time 60 -X POST "$base/api/events")"
  check "DELETE /api/user/account" "401" \
    "$(curl -s -o /dev/null -w '%{http_code}' --max-time 60 -X DELETE "$base/api/user/account")"
  check "GET /privacy is public" "200" \
    "$(curl -s -o /dev/null -w '%{http_code}' --max-time 60 "$base/privacy")"
  check "unknown route is 404" "404" \
    "$(curl -s -o /dev/null -w '%{http_code}' --max-time 60 "$base/api/nope")"

  summary
}

# ---------------------------------------------------------------- #
#  jobs — the nightly pipeline, against whatever .env points at
# ---------------------------------------------------------------- #
jobs() {
  info "rollup_stats.php"
  php jobs/rollup_stats.php || die "rollup failed"
  info "pipeline_report.php"
  php jobs/pipeline_report.php
}

# ---------------------------------------------------------------- #
#  deploy — push main; Render builds the Dockerfile and swaps
# ---------------------------------------------------------------- #
deploy() {
  local branch; branch=$(git branch --show-current)
  [ "$branch" = "main" ] || die "on '$branch' — Render only builds main. Merge first."
  [ -z "$(git status --porcelain)" ] || die "working tree dirty — commit first"

  info "preflight before pushing"
  preflight || die "preflight failed — not pushing"

  info "git push origin main"
  git push origin main || die "push failed"

  info "waiting for Render to swap the container (build takes a few minutes)"
  local before after i
  before=$(curl -s --max-time 90 "$PROD_URL/" | jq -r '.time // ""')
  for i in $(seq 1 60); do
    sleep 15
    after=$(curl -s --max-time 30 "$PROD_URL/" | jq -r '.status // ""')
    [ "$after" = "ok" ] && { ok "service healthy after $((i*15))s"; break; }
    printf '  .'
  done
  echo
  verify "$PROD_URL"
}

case "${1:-help}" in
  preflight) shift; preflight "$@" ;;
  image)     shift; image "$@" ;;
  up)        shift; up "$@" ;;
  down)      shift; down "$@" ;;
  smoke)     shift; smoke "$@" ;;
  verify)    shift; verify "$@" ;;
  jobs)      shift; jobs "$@" ;;
  deploy)    shift; deploy "$@" ;;
  *) sed -n '3,20p' "${BASH_SOURCE[0]}" | sed 's/^# \?//' ;;
esac
