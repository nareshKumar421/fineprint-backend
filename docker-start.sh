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
