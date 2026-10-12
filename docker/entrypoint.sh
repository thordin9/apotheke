#!/bin/sh
# Apotheke container entrypoint (runs under tini as PID 1).
#  - prepares the data volume (writable by www-data even if ./data was created by root on the host)
#  - with no arguments (or "apotheke") runs php-fpm + nginx and supervises both: SIGTERM/SIGINT/SIGQUIT
#    stop both gracefully; if either exits on its own, the other is stopped and the container exits non-zero
#  - any other command is exec'd as-is (e.g. `php /var/www/app/bin/migrate.php`)
set -eu
DATA_DIR="${DATA_DIR:-/data}"
mkdir -p "$DATA_DIR/images" "$DATA_DIR/thumbs" "$DATA_DIR/sessions"
find "$DATA_DIR" \! -user www-data -exec chown www-data:www-data {} + 2>/dev/null || true
chmod 0770 "$DATA_DIR/sessions" 2>/dev/null || true

if [ "$#" -gt 0 ] && [ "$1" != "apotheke" ]; then exec "$@"; fi

PORT="${PORT:-80}"
case "$PORT" in ''|*[!0-9]*) echo "PORT must be a number" >&2; exit 64;; esac
sed "s/__PORT__/$PORT/g" /etc/nginx/apotheke.conf.template > /etc/nginx/nginx.conf
mkdir -p /tmp/nginx-body /tmp/nginx-fastcgi
nginx -t -q

php-fpm -F &
fpm=$!
# let php-fpm create its socket first, so nginx never answers 502 during start-up
i=0; while [ ! -S /run/php-fpm.sock ] && [ "$i" -lt 100 ] && kill -0 "$fpm" 2>/dev/null; do sleep 0.1; i=$((i + 1)); done
nginx -g 'daemon off;' &
ngx=$!
stopping=0
stop() { stopping=1; kill -QUIT "$ngx" 2>/dev/null || true; kill -QUIT "$fpm" 2>/dev/null || true; }
trap stop TERM INT QUIT
echo "apotheke: nginx on :$PORT, php-fpm on /run/php-fpm.sock"
while [ "$stopping" = 0 ] && kill -0 "$fpm" 2>/dev/null && kill -0 "$ngx" 2>/dev/null; do
  sleep 1 & wait $! || true
done
code=0
if [ "$stopping" = 0 ]; then echo "apotheke: a process exited unexpectedly, stopping" >&2; code=1; stop; fi
wait "$ngx" 2>/dev/null || true
wait "$fpm" 2>/dev/null || true
exit "$code"
