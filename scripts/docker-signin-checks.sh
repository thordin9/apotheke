#!/usr/bin/env bash
# The image refuses to start with sign-in off (hardening, SEC-M3).
#   bash scripts/docker-signin-checks.sh apotheke:ci
# Checks: no APP_PASSWORD -> exit 78 with a clear message; a bad ALLOW_NO_LOGIN -> exit 64; APP_PASSWORD -> starts;
# the same data volume later without APP_PASSWORD (sign-in already on in the database) -> starts; ALLOW_NO_LOGIN=1 -> starts.
set -euo pipefail
image="${1:?usage: docker-signin-checks.sh IMAGE}"
name="apotheke-signin-$$"
data=$(mktemp -d)
chmod 0777 "$data"
cleanup() {
  docker rm -f "$name" >/dev/null 2>&1 || true
  docker run --rm -v "$data:/data" --entrypoint sh "$image" -c 'rm -rf /data/* /data/.[!.]*' >/dev/null 2>&1 || true
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

echo "== no APP_PASSWORD: refuses to start"
out=$(refused 78)
grep -q 'sign-in is off' <<<"$out"
grep -q 'ALLOW_NO_LOGIN=1' <<<"$out"
echo "   ok"

echo "== a bad ALLOW_NO_LOGIN value: refuses to start"
out=$(refused 64 -e ALLOW_NO_LOGIN=maybe)
grep -q 'ALLOW_NO_LOGIN must be' <<<"$out"
echo "   ok"

echo "== APP_PASSWORD set: starts"
pw=$(openssl rand -hex 16)
starts -e APP_PASSWORD="$pw"
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
