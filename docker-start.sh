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

sed -ri "s/^Listen [0-9]+\$/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT}>/" \
    /etc/apache2/sites-available/000-default.conf

# exec, so Apache becomes PID 1 and receives SIGTERM directly. Without
# it the shell holds PID 1, swallows the signal, and every redeploy waits
# out the platform's kill timeout instead of stopping cleanly.
exec apache2-foreground
