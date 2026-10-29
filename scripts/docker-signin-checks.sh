#!/usr/bin/env bash
# The image refuses to start with sign-in off (hardening, SEC-M3).
#   bash scripts/docker-signin-checks.sh apotheke:ci
# Checks: no APP_PASSWORD -> exit 78 with a clear message; a bad ALLOW_NO_LOGIN -> exit 64; a too-short APP_PASSWORD
# -> exit 78; a database that can't be read -> exit 78; APP_PASSWORD -> starts; the same data volume later without
# APP_PASSWORD (sign-in already on in the database) -> starts; ALLOW_NO_LOGIN=1 -> starts.
# Also: APOTHEKE_APP=legacy or an unknown value -> exit 64; a symbolic link in place of a data directory -> exit 78
# (L-430); the first start prunes old sessions once, and a link in place of its marker is replaced, not followed (L-430).
set -euo pipefail
image="${1:?usage: docker-signin-checks.sh IMAGE}"
name="apotheke-signin-$$"
data=$(mktemp -d "${TMPDIR:-/tmp}/apotheke-signin.XXXXXX")
chmod 0777 "$data"
cleanup() {
  docker rm -f "$name" >/dev/null 2>&1 || true
  # the entrypoint hands /data to www-data; give it back so the runner can delete it from the sticky /tmp
  docker run --rm -v "$data:/data" --entrypoint sh "$image" -c "rm -rf /data/* /data/.[!.]*; chown $(id -u):$(id -g) /data" \
    >/dev/null 2>&1 || true
  rm -rf "$data"
}
trap cleanup EXIT

refused() {
  local want=$1; shift
  local out code=0
  out=$(timeout 60 docker run --rm -v "$data:/data" "$@" "$image" 2>&1) || code=$?
  if [[ $code != "$want" ]]; then echo "expected exit $want, got $code: $out" >&2; exit 1; fi
  printf '%s\n' "$out"
}

starts() {
  docker run -d --name "$name" -v "$data:/data" "$@" "$image" >/dev/null
  for _ in $(seq 1 75); do
    if docker exec "$name" wget -q -O /dev/null http://127.0.0.1:9091/healthz 2>/dev/null; then
      docker rm -f "$name" >/dev/null
      return 0
    fi
    if [[ $(docker inspect -f '{{.State.Running}}' "$name") != true ]]; then break; fi
    sleep 0.2
  done
  docker logs "$name" >&2 || true
  echo "the container did not start with: $*" >&2
  exit 1
}

insh() { docker run --rm -v "$data:/data" --entrypoint sh "$image" -c "$1"; }
# a session file left by the legacy app, for the prune checks below, and a dangling link, planted by www-data,
# where the marker goes (L-430)
plant='touch /data/sessions/sess_legacy && ln -s /data/l430-marker /data/sessions/.ci4'
insh "mkdir -p /data/sessions && chown www-data:www-data /data/sessions && su -s /bin/sh www-data -c '$plant'"

echo "== no APP_PASSWORD: refuses to start"
out=$(refused 78)
grep -q 'sign-in is off' <<<"$out"
grep -q 'php bin/settings.php password' <<<"$out"
grep -q 'ALLOW_NO_LOGIN=1' <<<"$out"
echo "   ok"

echo "== a bad ALLOW_NO_LOGIN value: refuses to start"
out=$(refused 64 -e ALLOW_NO_LOGIN=maybe)
grep -q 'ALLOW_NO_LOGIN must be' <<<"$out"
echo "   ok"

echo "== APP_PASSWORD shorter than 8 characters: refuses to start without showing it (#75 QA L-2)"
out=$(refused 78 -e APP_PASSWORD=Ab3-xyz)
grep -q 'APP_PASSWORD is too short' <<<"$out"
if grep -q 'Ab3-xyz' <<<"$out"; then echo "the value was logged" >&2; exit 1; fi
echo "   ok"

echo "== the database can't be read: refuses to start (#75 QA L-1)"
out=$(refused 78 -e DB_DRIVER=pgsql -e DB_HOST=127.0.0.1 -e DB_PORT=1)
grep -q 'could not read the sign-in setting' <<<"$out"
echo "   ok"

echo "== APOTHEKE_APP=legacy or an unknown value: refuses to start (N-432)"
out=$(refused 64 -e APOTHEKE_APP=ci5 -e ALLOW_NO_LOGIN=1)
grep -q 'APOTHEKE_APP must be ci4 or empty' <<<"$out"
out=$(refused 64 -e APOTHEKE_APP=legacy -e ALLOW_NO_LOGIN=1)
grep -q 'Apotheke 3.0 has no legacy app' <<<"$out"
echo "   ok"

echo "== a symbolic link in place of a data directory: refuses to start, the target is not created (L-430)"
insh "rmdir /data/thumbs && su -s /bin/sh www-data -c 'ln -s /data/l430-dir /data/thumbs'"
out=$(refused 78 -e ALLOW_NO_LOGIN=1)
grep -q 'thumbs must be a directory, not a symbolic link' <<<"$out"
insh 'test -L /data/thumbs && test ! -e /data/l430-dir && rm /data/thumbs'
echo "   ok"

echo "== APP_PASSWORD set: starts"
pw=$(openssl rand -hex 16)
starts -e APP_PASSWORD="$pw"
echo "   ok"

echo "== the first start removed the legacy sessions once (PLAN 3.5); later starts keep sessions"
insh 'test ! -e /data/sessions/sess_legacy && test -e /data/sessions/.ci4 && touch /data/sessions/sess_ci4'
starts
insh 'test -e /data/sessions/sess_ci4'
echo "   ok"
echo "== the dangling link in place of the marker was replaced by a www-data file, its target not created (L-430)"
# shellcheck disable=SC2016 # expanded in the container
insh 'test ! -e /data/l430-marker && test ! -L /data/sessions/.ci4 && test -f /data/sessions/.ci4 && test "$(stat -c %U /data/sessions/.ci4)" = www-data'
echo "   ok"

echo "== same data, APP_PASSWORD removed (sign-in on in the database): starts"
starts
echo "   ok"

echo "== sign-in turned off in the database: refuses again"
docker run --rm -v "$data:/data" -u www-data "$image" php /var/www/app/bin/settings.php auth off >/dev/null
refused 78 >/dev/null
echo "   ok"

echo "== ALLOW_NO_LOGIN=1: starts without sign-in"
starts -e ALLOW_NO_LOGIN=1
echo "   ok"
