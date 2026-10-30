#!/usr/bin/env bash
# The background jobs worker in the image (docker/jobs-worker.sh, started by the entrypoint). Works for both images.
#  - on by default: apotheke-jobs-worker and `php spark jobs:work` run as www-data, never root;
#  - it works the queue: a job of an unknown type, queued in the database, ends "failed" with "Unknown job type." and
#    one log line (the image has no job types yet, so this is the job any worker can run);
#  - killed (SIGKILL), jobs:work is started again after the measured waits 1, 2, 4 and 8 s (L-1471), while the web
#    server keeps answering; a clean exit (0) brings the wait back to 1 s, and so does a run of 60 s or more;
#  - docker stop: the worker stops on SIGTERM and the container exits 0 within 20 s, also when the stop arrives while
#    jobs:work is being restarted or the backoff sleep is starting (L-1470);
#  - the web server dying on its own stops the container with its exit status (FrankenPHP; nginx and php-fpm), L-1472;
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

# restart <signal> <old pid> -> "<new pid> <seconds>": sends the signal to jobs:work, then times (inside the container,
# polling every 20 ms) how long until a new jobs:work runs. The wait is the worker's sleep plus a few milliseconds.
restart() {
  docker exec "$name" php -r '
    [$sig, $old] = [$argv[1], (int)$argv[2]];
    exec("kill -" . $sig . " " . $old);
    $t0 = microtime(true);
    while (microtime(true) - $t0 < 30) {
      foreach (glob("/proc/[0-9]*/cmdline") as $f) {
        $pid = (int)basename(dirname($f));
        if ($pid === $old || $pid === getmypid()) { continue; }
        $args = explode("\0", (string)@file_get_contents($f));
        if (in_array("spark", $args, true) && in_array("jobs:work", $args, true)) {
          printf("%d %.2f", $pid, microtime(true) - $t0); exit;
        }
      }
      usleep(20000);
    }
    echo "0 none";' -- "$1" "$2"
}
within() { # seconds low high -> 1 or the seconds
  awk -v t="$1" -v lo="$2" -v hi="$3" 'BEGIN { if (t != "none" && t + 0 >= lo && t + 0 <= hi) print 1; else print t }'
}
waited() { # name signal expected-low expected-high; kills the current jobs:work, sets pid
  local r; r=$(restart "$2" "$pid"); pid=${r%% *}
  check "$1 (${r#* }s)" 1 "$(within "${r#* }" "$3" "$4")"
}
echo "== restarts after a kill, after the measured backoff, and the web server keeps answering"
pid=$(jobs_pid)
# the wait is the sleep (1, 2, 4, 8 s) plus the time to notice the exit and start php: allow -0.1 s and +0.9 s
for d in 1 2 4 8; do
  waited "SIGKILL: jobs:work started again after ${d} s" KILL "$(awk -v d=$d 'BEGIN{print d-0.1}')" "$(awk -v d=$d 'BEGIN{print d+0.9}')"
  check "healthz after the restart ($d s)" 200 "$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 "http://127.0.0.1:$port/healthz")"
done
log=$(docker logs "$name" 2>&1)
check "the log says 1 s, 2 s, 4 s, 8 s" "1s 2s 4s 8s" "$(grep -o 'exited with code 137, restarting in [0-9]*s' <<<"$log" | awk '{print $NF}' | head -4 | xargs)"
# a clean exit (SIGTERM to jobs:work alone: it exits 0, as after --max-seconds) restarts after 1 s, not 16 s; the
# time includes jobs:work's own exit (up to its 1 s poll)
sleep 1.5
waited "SIGTERM: jobs:work exited 0 and started again after 1 s, not 16 s" TERM 0.9 3.5
check "a clean exit is not logged as a failure" 4 "$(docker logs "$name" 2>&1 | grep -c 'exited with code' || true)"
waited "the next failure waits 1 s again (reset by the clean exit)" KILL 0.9 1.9
waited "then 2 s" KILL 1.9 2.9
# a run of 60 s or more resets the backoff: the next wait would be 4 s, it is 1 s
sleep 61
waited "after a 61 s run the wait is 1 s again, not 4 s" KILL 0.9 1.9
check "the log never carries APP_PASSWORD" 0 "$(docker logs "$name" 2>&1 | grep -c -- "$pw" || true)"

echo "== docker stop: SIGTERM stops the worker, exit 0"
t0=$(date +%s)
docker stop -t 20 "$name" >/dev/null
check "stopped within 20 s" 1 "$(( $(date +%s) - t0 < 20 ))"
check "exit code 0" 0 "$(docker inspect -f '{{.State.ExitCode}}' "$name")"
check "the worker said it stopped" 1 "$(docker logs "$name" 2>&1 | grep -c 'apotheke-jobs: worker stopped' || true)"

echo "== docker stop while jobs:work is restarting or the backoff sleep is starting (L-1470)"
# jobs:work is killed, so the worker sleeps 1 s and then starts it again; the stop lands at, around and after that
for at in 0 0.5 0.9 0.95 1.0 1.05 1.1 1.2; do
  start
  wait_for 10 has_jobs || true
  docker exec "$name" kill -KILL "$(jobs_pid)"
  sleep "$at"
  t0=$(date +%s)
  docker stop -t 15 "$name" >/dev/null
  check "stop ${at} s after a kill: exit 0" 0 "$(docker inspect -f '{{.State.ExitCode}}' "$name")"
  check "stop ${at} s after a kill: within 10 s" 1 "$(( $(date +%s) - t0 < 10 ))"
done

echo "== the web server dies: the container stops with its exit status (L-1472)"
dies() { # label process-pattern
  start
  wait_for 10 has_jobs || true
  local p; p=$(docker exec "$name" ps -o pid,args | awk -v re="$2" '$0 ~ re && !/awk/ { print $1; exit }')
  docker exec "$name" kill -KILL "$p"
  local code; code=$(timeout 20 docker wait "$name" || echo timeout)
  check "$1 killed: the container exits with 137" 137 "$code"
  check "$1 killed: the log says so" 1 "$(docker logs "$name" 2>&1 | grep -c "exited unexpectedly (exit 137)" || true)"
  check "$1 killed: the worker stopped too" 1 "$(docker logs "$name" 2>&1 | grep -c 'apotheke-jobs: worker stopped' || true)"
}
if docker run --rm --entrypoint sh "$image" -c 'command -v frankenphp' >/dev/null 2>&1; then
  dies frankenphp 'frankenphp run'
else
  dies nginx 'nginx: master'
  dies php-fpm 'php-fpm: master'
fi

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
