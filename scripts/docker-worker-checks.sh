#!/usr/bin/env bash
# FrankenPHP worker mode in the image (L-1980, L-1979): APOTHEKE_PHP_WORKER=1 with sign-in on. Runs the sign-in checks
# (scripts/docker-auth-checks.sh) and a burst of answered errors (404, 401, 403, 421), then requires that no worker
# restarted: each worker logs one "apotheke: PHP worker started" line when it boots (public/frankenphp-worker.php), so
# the count must stay at FRANKENPHP_NUM_THREADS (2). A worker that ended after an error (an exit() in an error
# handler) would log more. A second container with APOTHEKE_WORKER_MAX_REQUESTS=3 must recycle its workers.
# Usage: scripts/docker-worker-checks.sh <image>   (ports 9096 and 9097; removes its containers)
set -euo pipefail
image=${1:?usage: docker-worker-checks.sh <image>}
fail=0
check() { # name expected actual
  if [[ $2 == "$3" ]]; then echo "ok   $1"; else echo "FAIL $1: expected '$2', got '$3'"; fail=1; fi
}
trap 'docker rm -f apotheke-worker apotheke-worker-recycle >/dev/null 2>&1 || true' EXIT
boots() { docker logs "$1" 2>&1 | grep -c 'apotheke: PHP worker started' || true; }
up() { # name port
  for _ in $(seq 1 50); do curl -fs -o /dev/null "http://127.0.0.1:$2/healthz" && return 0; sleep 0.2; done
  docker logs "$1" 2>&1 | tail -20; echo "FAIL $1 did not start"; exit 1
}
code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }

APP_PW=$(openssl rand -hex 16)
export APP_PW
docker run -d --name apotheke-worker -p 9096:9096 -e PORT=9096 -e APP_PASSWORD="$APP_PW" -e APOTHEKE_PHP_WORKER=1 \
  "$image" >/dev/null
up apotheke-worker 9096
docker logs apotheke-worker 2>&1 | grep -F ', 2 PHP threads, worker mode'
check "workers booted once each" 2 "$(boots apotheke-worker)"

base=http://127.0.0.1:9096
for i in $(seq 1 10); do
  check "404 #$i" 404 "$(code -b apotheke_consent=v1 "$base/no/such/page/$i")"
  check "401 #$i" 401 "$(code -b apotheke_consent=v1 "$base/api/v1/me")"
  check "403 #$i" 403 "$(code -b apotheke_consent=v1 -H 'Content-Type: application/json' -d '{}' "$base/api/v1/session")"
  check "421 #$i" 421 "$(code -H 'Host: other.example' "$base/")"
done
SERVER=frankenphp bash scripts/docker-auth-checks.sh "$base"
check "no worker restarted after errors and the sign-in checks" 2 "$(boots apotheke-worker)"
if docker logs apotheke-worker 2>&1 | grep -iE '"level":"(warn|error)".*worker'; then
  echo "FAIL FrankenPHP logged a worker warning"; fail=1
fi

docker run -d --name apotheke-worker-recycle -p 9097:9097 -e PORT=9097 -e APP_PASSWORD="$APP_PW" -e APOTHEKE_PHP_WORKER=1 \
  -e APOTHEKE_WORKER_MAX_REQUESTS=3 "$image" >/dev/null
up apotheke-worker-recycle 9097
docker logs apotheke-worker-recycle 2>&1 | grep -F 'apotheke: PHP worker started (recycles after 3 requests)' >/dev/null
for i in $(seq 1 12); do
  check "recycling: request $i answered" 200 "$(code -b apotheke_consent=v1 "http://127.0.0.1:9097/api/v1/session")"
done
n=$(boots apotheke-worker-recycle)
if (( n >= 5 )); then echo "ok   recycling: $n worker boots for 12 requests at 3 per worker"; else
  echo "FAIL recycling: $n worker boots, expected at least 5"; fail=1; fi

exit "$fail"
