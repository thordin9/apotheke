#!/bin/sh
# Apotheke container entrypoint (runs under tini as PID 1).
#  - prepares the data volume (writable by www-data even if ./data was created by root on the host)
#  - with no arguments (or "apotheke") runs php-fpm + nginx and supervises both: SIGTERM/SIGINT/SIGQUIT
#    stop both gracefully; if either exits on its own, the other is stopped and the container exits non-zero
#  - next to them it runs the background jobs worker (apotheke-jobs-worker: `php spark jobs:work`, restarted with a
#    backoff) as www-data; APOTHEKE_JOBS_WORKER=0 turns it off. SIGTERM stops it too; it never stops the web server
#  - any other command is exec'd as-is (e.g. `php /var/www/app/bin/migrate.php`)
#  - refuses to start the app with sign-in off (hardening, SEC-M3): set APP_PASSWORD, keep sign-in on in the
#    database, or set ALLOW_NO_LOGIN=1 to run without sign-in on purpose
#  - serves Apotheke 3.0 (CodeIgniter 4); APOTHEKE_APP=legacy is refused, since 3.0 has no legacy app
set -eu
DATA_DIR="${DATA_DIR:-/data}"
case "${APOTHEKE_APP:-}" in
  ''|ci4) ;;
  legacy) echo "APOTHEKE_APP=legacy: Apotheke 3.0 has no legacy app; use a 2.6 image to stay on it" >&2; exit 64 ;;
  *) echo "APOTHEKE_APP must be ci4 or empty" >&2; exit 64 ;;
esac
ROOT=/var/www/app/public
# Root writes into DATA_DIR (owned by www-data) never follow a symbolic link (L-430): the directories below must be
# real ones, chown changes links themselves (-h), and the CI4 marker is written as www-data.
for d in images thumbs originals originals/map sessions; do
  if [ -L "$DATA_DIR/$d" ]; then echo "apotheke: $DATA_DIR/$d must be a directory, not a symbolic link" >&2; exit 78; fi
done
mkdir -p "$DATA_DIR/images" "$DATA_DIR/thumbs" "$DATA_DIR/originals/map" "$DATA_DIR/sessions"
# ./data/postgres is the compose postgres service's own directory (owned by its postgres user): never touched here,
# or PostgreSQL could not start (or initialise) after the app started next to it.
find "$DATA_DIR" -path "$DATA_DIR/postgres" -prune -o \! -user www-data -exec chown -h www-data:www-data {} + 2>/dev/null || true
chmod 0770 "$DATA_DIR/sessions" 2>/dev/null || true
# The first 3.0 start signs everyone out once: legacy sessions are not carried over (PLAN 3.5). A link in place of
# the marker counts as no marker and is replaced by a file.
# shellcheck disable=SC2016 # expanded by the shell that runs it
mark='cd "$1" && if [ -L .ci4 ] || [ ! -e .ci4 ]; then
  find . -maxdepth 1 -type f -name "sess_*" -delete && rm -f .ci4 && : > .ci4; fi'
if [ "$(id -u)" = 0 ]; then su -s /bin/sh www-data -c "$mark" sh "$DATA_DIR/sessions"; else sh -c "$mark" sh "$DATA_DIR/sessions"; fi

if [ "$#" -gt 0 ] && [ "$1" != "apotheke" ]; then exec "$@"; fi

# PostgreSQL needs a password (N-1426): say so now, instead of retrying a connection that cannot work. A server that
# trusts the connection ignores the value, so any DB_PASSWORD works there; a full DB_DSN is left to the driver.
if [ "${DB_DRIVER:-sqlite}" = pgsql ] && [ -z "${DB_PASSWORD:-}" ] && [ -z "${DB_DSN:-}" ]; then
  echo "apotheke: DB_DRIVER=pgsql needs DB_PASSWORD (in .env with docker compose; the postgres profile uses the same value)" >&2
  exit 78
fi

# Sign-in must be on unless ALLOW_NO_LOGIN says otherwise. `auth status` asks the app itself (as www-data, so a new
# SQLite file belongs to it): 0 = on, 3 = off, 4 = APP_PASSWORD too short (it says so on stderr), anything else = no
# answer (PostgreSQL may still be starting). With APOTHEKE_APP=ci4 (kept by su -p) bin/settings.php runs the CI4 form,
# `spark apotheke:accounts auth status`: same answers and exit codes, and it does not migrate either.
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

# The background jobs worker (docker/jobs-worker.sh): on unless APOTHEKE_JOBS_WORKER is 0, false or no.
case "${APOTHEKE_JOBS_WORKER:-1}" in
  1|true|yes) jobs_worker=1 ;;
  0|false|no) jobs_worker=0 ;;
  *) echo "APOTHEKE_JOBS_WORKER must be 1, true, yes, 0, false, no or empty" >&2; exit 64 ;;
esac

PORT="${PORT:-9091}"
case "$PORT" in ''|*[!0-9]*) echo "PORT must be a number" >&2; exit 64;; esac
sed -e "s/__PORT__/$PORT/g" -e "s#__ROOT__#$ROOT#g" /etc/nginx/apotheke.conf.template > /etc/nginx/nginx.conf
# nginx temp dirs live under the root-owned /run, not the world-writable /tmp (L-430)
mkdir -p /run/apotheke-nginx
nginx -t -q

# The trap is set before anything starts, and the flag is checked right before each `&` and again once the PIDs are
# known, so a SIGTERM during start-up is never lost (L-1470). A signal sent right after a fork can still be lost (the
# subshell has not run exec yet), so after a stop each process gets its signal again until it has exited (end).
fpm=0
ngx=0
jobs=0
stopping=0
stop() {
  stopping=1
  if [ "$ngx" != 0 ]; then kill -QUIT "$ngx" 2>/dev/null || true; fi
  if [ "$fpm" != 0 ]; then kill -QUIT "$fpm" 2>/dev/null || true; fi
  if [ "$jobs" != 0 ]; then kill -TERM "$jobs" 2>/dev/null || true; fi
}
end() { # signal pid: sends the signal every 0.2 s until the process has exited
  if [ "$2" = 0 ]; then return 0; fi
  while kill -0 "$2" 2>/dev/null; do kill "-$1" "$2" 2>/dev/null || true; sleep 0.2; done
}
trap stop TERM INT QUIT
if [ "$stopping" = 0 ]; then php-fpm -F & fpm=$!; fi
# let php-fpm create its socket first, so nginx never answers 502 during start-up
i=0; while [ "$stopping" = 0 ] && [ ! -S /run/php-fpm.sock ] && [ "$i" -lt 100 ] && kill -0 "$fpm" 2>/dev/null; do sleep 0.1; i=$((i + 1)); done
if [ "$stopping" = 0 ]; then nginx -g 'daemon off;' & ngx=$!; fi
if [ "$jobs_worker" = 1 ]; then
  if [ "$stopping" = 0 ]; then
    if [ "$(id -u)" = 0 ]; then su-exec www-data apotheke-jobs-worker & else apotheke-jobs-worker & fi
    jobs=$!
  fi
else
  echo "apotheke: APOTHEKE_JOBS_WORKER is off, background jobs do not run"
fi
if [ "$stopping" = 1 ]; then stop; fi
echo "apotheke: nginx on :$PORT ($ROOT), php-fpm on /run/php-fpm.sock"
while [ "$stopping" = 0 ] && kill -0 "$fpm" 2>/dev/null && kill -0 "$ngx" 2>/dev/null; do
  sleep 1 & wait $! || true
done
code=0
if [ "$stopping" = 0 ]; then
  # nginx or php-fpm exited on its own: the container stops with that exit status (1 if it was 0), L-1472
  if kill -0 "$ngx" 2>/dev/null; then dead=php-fpm; wait "$fpm" || code=$?; else dead=nginx; wait "$ngx" || code=$?; fi
  echo "apotheke: $dead exited unexpectedly (exit $code), stopping" >&2
  if [ "$code" = 0 ]; then code=1; fi
  stop
fi
end QUIT "$ngx"
end QUIT "$fpm"
end TERM "$jobs"
exit "$code"
