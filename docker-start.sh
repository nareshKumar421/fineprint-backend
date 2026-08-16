#!/bin/sh
# ============================================================
#  Bind Apache to the port the platform hands us.
#
#  Railway (and most PaaS) inject $PORT at RUN time, not build time, so
#  this cannot be baked into the image. If the container listens on 80
#  while the platform routes to $PORT, the edge finds nothing behind the
#  domain and answers an empty 404 — which looks identical to a failed
#  deploy from outside.
#
#  8080 is the fallback so `docker run` works locally with no env set.
# ============================================================
set -eu

PORT="${PORT:-8080}"

# ---- Exactly one MPM, enforced at RUN time --------------------------
# The Dockerfile already pins mpm_prefork and the build runs
# `apache2ctl configtest`, which passes. Despite that, the deployed
# container still died on "More than one MPM loaded" — so the image the
# platform runs does not always match the one the build validated.
#
# Rather than keep guessing at why, fix it where it actually matters: on
# the way up, every time. This is idempotent and costs a few
# milliseconds. The echo is deliberate — the MPM list is the one piece of
# evidence that says whether the theory was right, and a container that
# dies before Apache starts writes nothing else to the log.
for extra in mpm_event mpm_worker; do
    if [ -e "/etc/apache2/mods-enabled/${extra}.load" ]; then
        echo "start.sh: found extra MPM ${extra} — disabling"
        a2dismod "${extra}" >/dev/null 2>&1 || true
    fi
done

if [ ! -e /etc/apache2/mods-enabled/mpm_prefork.load ]; then
    echo "start.sh: mpm_prefork missing — enabling"
    a2enmod mpm_prefork >/dev/null 2>&1 || true
fi

echo "start.sh: MPMs enabled -> $(ls /etc/apache2/mods-enabled/ 2>/dev/null | grep -E '^mpm_.*\.load$' | tr '\n' ' ')"
echo "start.sh: PORT=${PORT}"

# Listen on IPv6 where it exists, NOT the bare `Listen <port>` Apache
# defaults to.
#
# `Listen 8080` binds 0.0.0.0 only — IPv4. Railway's internal network is
# IPv6, so its edge cannot open a connection to an IPv4-only socket and
# answers "Application failed to respond" with x-railway-fallback: true,
# even though the container is healthy and Apache is running.
#
# A [::] socket serves BOTH families when bindv6only=0, which is the
# Linux default and true on Railway, so this covers IPv4 as well rather
# than trading one family for the other. Binding both explicitly would
# risk "Address already in use" against that same dual-stack socket.
#
# The guard keeps the image runnable where IPv6 is genuinely absent —
# Apache exits rather than starts degraded if it cannot bind.
if [ -f /proc/net/if_inet6 ]; then
    LISTEN="Listen [::]:${PORT}"
else
    LISTEN="Listen ${PORT}"
fi

printf '%s\n' "${LISTEN}" > /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT}>/" \
    /etc/apache2/sites-available/000-default.conf

# exec, so Apache becomes PID 1 and receives SIGTERM directly. Without
# it the shell holds PID 1, swallows the signal, and every redeploy waits
# out the platform's kill timeout instead of stopping cleanly.
exec apache2-foreground
