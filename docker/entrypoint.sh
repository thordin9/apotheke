#!/bin/sh
# Apotheke container entrypoint (runs under tini as PID 1).
#  - prepares the data volume (writable by www-data even if ./data was created by root on the host)
#  - with no arguments (or "apotheke") runs php-fpm + nginx and supervises both: SIGTERM/SIGINT/SIGQUIT
#    stop both gracefully; if either exits on its own, the other is stopped and the container exits non-zero
#  - any other command is exec'd as-is (e.g. `php /var/www/app/bin/migrate.php`)
#  - refuses to start the app with sign-in off (hardening, SEC-M3): set APP_PASSWORD, keep sign-in on in the
#    database, or set ALLOW_NO_LOGIN=1 to run without sign-in on purpose
#  - APOTHEKE_APP=ci4 serves the CodeIgniter 4 app (PHP 8.4 or later) instead of the legacy one
set -eu
DATA_DIR="${DATA_DIR:-/data}"
case "${APOTHEKE_APP:-legacy}" in
  legacy|'') ROOT=/var/www/app/public ;;
  ci4)
    ROOT=/var/www/app/ci4/public
    php -r 'exit(PHP_VERSION_ID >= 80400 ? 0 : 1);' || { echo "APOTHEKE_APP=ci4 needs the PHP 8.4 image" >&2; exit 64; }
    ;;
  *) echo "APOTHEKE_APP must be legacy, ci4 or empty" >&2; exit 64 ;;
esac
mkdir -p "$DATA_DIR/images" "$DATA_DIR/thumbs" "$DATA_DIR/originals/map" "$DATA_DIR/sessions"
find "$DATA_DIR" \! -user www-data -exec chown www-data:www-data {} + 2>/dev/null || true
chmod 0770 "$DATA_DIR/sessions" 2>/dev/null || true
# The first CI4 start signs everyone out once: legacy sessions are not carried over (PLAN 3.5).
if [ "$ROOT" = /var/www/app/ci4/public ] && [ ! -e "$DATA_DIR/sessions/.ci4" ]; then
  find "$DATA_DIR/sessions" -maxdepth 1 -type f -name 'sess_*' -delete
  touch "$DATA_DIR/sessions/.ci4" && chown www-data:www-data "$DATA_DIR/sessions/.ci4"
fi

if [ "$#" -gt 0 ] && [ "$1" != "apotheke" ]; then exec "$@"; fi

# Sign-in must be on unless ALLOW_NO_LOGIN says otherwise. `auth status` asks the app itself (as www-data, so a new
# SQLite file belongs to it): 0 = on, 3 = off, 4 = APP_PASSWORD too short (it says so on stderr), anything else = no
# answer (PostgreSQL may still be starting).
case "${ALLOW_NO_LOGIN:-}" in
  1|true|yes) echo "apotheke: ALLOW_NO_LOGIN is set, sign-in is not required" ;;
  ''|0|false|no)
    i=0
    while :; do
      rc=0; su -p -s /bin/sh www-data -c 'php /var/www/app/bin/settings.php auth status' >/dev/null || rc=$?
      if [ "$rc" = 0 ] || [ "$rc" = 3 ] || [ "$rc" = 4 ] || [ "$i" -ge 30 ]; then break; fi
      i=$((i + 1)); sleep 1
    done
    if [ "$rc" = 3 ]; then
      echo "apotheke: sign-in is off. Turn it on with: docker compose run --rm -u www-data apotheke php bin/settings.php password" >&2
      echo "apotheke: or set ALLOW_NO_LOGIN=1 to run without sign-in on a trusted network. A new install can also set APP_PASSWORD." >&2
      exit 78
    fi
    if [ "$rc" = 4 ]; then exit 78; fi
    if [ "$rc" != 0 ]; then echo "apotheke: could not read the sign-in setting from the database (exit $rc)" >&2; exit 78; fi
    ;;
  *) echo "ALLOW_NO_LOGIN must be 1, true, yes, 0, false, no or empty" >&2; exit 64 ;;
esac

PORT="${PORT:-9091}"
case "$PORT" in ''|*[!0-9]*) echo "PORT must be a number" >&2; exit 64;; esac
sed -e "s/__PORT__/$PORT/g" -e "s#__ROOT__#$ROOT#g" /etc/nginx/apotheke.conf.template > /etc/nginx/nginx.conf
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
echo "apotheke: nginx on :$PORT ($ROOT), php-fpm on /run/php-fpm.sock"
while [ "$stopping" = 0 ] && kill -0 "$fpm" 2>/dev/null && kill -0 "$ngx" 2>/dev/null; do
  sleep 1 & wait $! || true
done
code=0
if [ "$stopping" = 0 ]; then echo "apotheke: a process exited unexpectedly, stopping" >&2; code=1; stop; fi
wait "$ngx" 2>/dev/null || true
wait "$fpm" 2>/dev/null || true
exit "$code"
