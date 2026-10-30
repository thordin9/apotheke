#!/usr/bin/env bash
# The background jobs worker in the image (docker/jobs-worker.sh, started by the entrypoint). Works for both images.
#  - on by default: apotheke-jobs-worker and `php spark jobs:work` run as www-data, never root;
#  - it works the queue: a job of an unknown type, queued in the database, ends "failed" with "Unknown job type." and
#    one log line (the image has no job types yet, so this is the job any worker can run);
#  - killed (SIGKILL), jobs:work is started again with a growing backoff while the web server keeps answering;
#  - docker stop: the worker stops on SIGTERM and the container exits 0 within 20 s;
#  - APOTHEKE_JOBS_WORKER=0 runs no worker (the job stays queued); a bad value exits 64;
#  - the log never carries APP_PASSWORD.
# Usage: scripts/docker-jobs-checks.sh <image> [port]   (default port 9096)
# shellcheck disable=SC2317,SC2329 # the helpers below are called through wait_for
set -euo pipefail
image=${1:?image}
port=${2:-9096}
name=apotheke-jobs-check
fail=0
check() { # name expected actual
  if [[ $2 == "$3" ]]; then echo "ok   $1"; else echo "FAIL $1: expected '$2', got '$3'"; fail=1; fi
}
cleanup() { docker rm -f "$name" >/dev/null 2>&1 || true; }
trap cleanup EXIT
pw=$(openssl rand -hex 16)
start() { # extra docker run args...
  cleanup
  docker run -d --name "$name" -p "127.0.0.1:$port:9091" -e APP_PASSWORD="$pw" "$@" "$image" >/dev/null
  for _ in $(seq 1 100); do curl -fs -o /dev/null "http://127.0.0.1:$port/healthz" && return 0; sleep 0.2; done
  docker logs "$name" >&2; return 1
}
procs() { docker exec "$name" ps -o user,args | grep -E "$1" | grep -v grep || true; }
jobs_pid() { docker exec "$name" ps -o pid,args | awk '/spark --no-header jobs:work/ && !/awk/ { print $1; exit }'; }
wait_for() { # seconds command...
  local n=$(( $1 * 5 )); shift
  for _ in $(seq 1 "$n"); do if "$@"; then return 0; fi; sleep 0.2; done; return 1
}
queue() { # type -> id; straight into the jobs table, as the app's JobQueue::enqueue does
  docker exec -u www-data -e JOB_TYPE="$1" "$name" php -r '
    $db = new PDO("sqlite:/data/catalog.sqlite");
    $now = gmdate("Y-m-d H:i:s");
    $db->prepare("INSERT INTO jobs (type, status, payload, attempts, max_attempts, run_after, created_at, updated_at) VALUES (?, ?, ?, 0, 3, ?, ?, ?)")
       ->execute([getenv("JOB_TYPE"), "queued", "{}", $now, $now, $now]);
    echo $db->lastInsertId();'
}
status() { # id -> status|error
  docker exec -u www-data -e JOB_ID="$1" "$name" php -r '
    $db = new PDO("sqlite:/data/catalog.sqlite");
    $s = $db->prepare("SELECT status, error FROM jobs WHERE id = ?"); $s->execute([(int)getenv("JOB_ID")]);
    $r = $s->fetch(PDO::FETCH_NUM); echo $r[0], "|", $r[1];'
}

echo "== on by default"
start
has_jobs() { [[ -n $(jobs_pid) ]]; }
wait_for 10 has_jobs || true
check "apotheke-jobs-worker runs as www-data" "www-data" "$(procs 'apotheke-jobs-worker' | awk '{print $1}' | sort -u)"
check "jobs:work runs as www-data"            "www-data" "$(procs 'spark --no-header jobs:work' | awk '{print $1}' | sort -u)"
check "the log says the worker started" 1 "$(docker logs "$name" 2>&1 | grep -c 'apotheke-jobs: worker started (php spark jobs:work, as www-data)' || true)"

id=$(queue docker-check)
job_failed() { [[ $(status "$id") == failed* ]]; }
wait_for 15 job_failed || true
check "a queued job is worked (unknown type -> failed)" "failed|Unknown job type." "$(status "$id")"
check "one log line for the job" 1 "$(docker logs "$name" 2>&1 | grep -c "^job $id (docker-check): failed$" || true)"

restarted() { local p; p=$(jobs_pid); [[ -n $p && $p != "$old" ]]; }
echo "== restarts after a kill, with a backoff, and the web server keeps answering"
for round in 1 2 3; do
  old=$(jobs_pid)
  docker exec "$name" kill -KILL "$old"
  check "healthz while jobs:work is down ($round)" 200 "$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 "http://127.0.0.1:$port/healthz")"
  wait_for 15 restarted || true
  new=$(jobs_pid)
  check "jobs:work started again after kill $round" 1 "$([[ -n $new && $new != "$old" ]] && echo 1 || echo "${new:-none}")"
done
log=$(docker logs "$name" 2>&1)
check "backoff 1 s, then 2 s, then 4 s" "1s 2s 4s" "$(grep -o 'exited with code 137, restarting in [0-9]*s' <<<"$log" | awk '{print $NF}' | head -3 | xargs)"
check "the log never carries APP_PASSWORD" 0 "$(grep -c -- "$pw" <<<"$log" || true)"

echo "== docker stop: SIGTERM stops the worker, exit 0"
t0=$(date +%s)
docker stop -t 20 "$name" >/dev/null
check "stopped within 20 s" 1 "$(( $(date +%s) - t0 < 20 ))"
check "exit code 0" 0 "$(docker inspect -f '{{.State.ExitCode}}' "$name")"
check "the worker said it stopped" 1 "$(docker logs "$name" 2>&1 | grep -c 'apotheke-jobs: worker stopped' || true)"

echo "== APOTHEKE_JOBS_WORKER=0 turns it off"
start -e APOTHEKE_JOBS_WORKER=0
check "no worker process" "" "$(procs 'apotheke-jobs-worker|jobs:work')"
check "the log says it is off" 1 "$(docker logs "$name" 2>&1 | grep -c 'APOTHEKE_JOBS_WORKER is off' || true)"
id=$(queue docker-check)
sleep 3
check "a queued job stays queued" "queued|" "$(status "$id")"
cleanup

echo "== a bad APOTHEKE_JOBS_WORKER exits 64"
code=0; docker run --rm -e ALLOW_NO_LOGIN=1 -e APOTHEKE_JOBS_WORKER=maybe "$image" > /dev/null 2>&1 || code=$?
check "APOTHEKE_JOBS_WORKER=maybe -> 64" 64 "$code"

exit $fail
