#!/bin/sh
# apotheke-jobs-worker: keeps `php spark jobs:work` (the background jobs: imports, restores) running next to the web
# server. Both images' entrypoints start it as www-data unless APOTHEKE_JOBS_WORKER=0.
#  - jobs:work is long-running: it works the queue and exits 0 after --max-seconds (3600 by default), so it is
#    started again after 1 s;
#  - any other exit (no database yet, a crash) restarts it after a backoff of 1, 2, 4 ... 60 s, back to 1 s after a
#    run of 60 s or more. It never stops the web server, and the web server never waits for it;
#  - SIGTERM or SIGINT is passed on to the running jobs:work (with pcntl its job checkpoints and goes back to the
#    queue), then this script exits 0.
# Logs go to the container's stdout/stderr: these "apotheke-jobs:" lines and jobs:work's one line per job (id, type,
# status). Neither prints a payload, the environment or a credential.
set -u
cd /var/www/app || exit 1
# The sign-in password variable stays in the environment: on a new install jobs:work can run the first migration,
# which seeds the sign-in setting from it (N-1475). Nothing here prints it.
child=0
stopping=0
# shellcheck disable=SC2317,SC2329 # called by the trap
stop() {
  stopping=1
  if [ "$child" != 0 ]; then kill -TERM "$child" 2>/dev/null || true; fi
}
trap stop TERM INT
# Race-free stop (L-1470): a signal that arrives after `&` but before child=$! finds no child to pass on to, so the
# flag is checked again once child is set; one that arrives later is passed on by stop() itself. A trapped signal
# interrupts wait, so wait again until the child has really exited; code is then the child's own status.
spawn() { # command... -> child
  "$@" &
  child=$!
  if [ "$stopping" = 1 ]; then kill -TERM "$child" 2>/dev/null || true; fi
}
reap() { # -> code
  code=0
  wait "$child" || code=$?
  while kill -0 "$child" 2>/dev/null; do code=0; wait "$child" || code=$?; done
  child=0
}
delay=1
echo "apotheke-jobs: worker started (php spark jobs:work, as $(id -un))"
while [ "$stopping" = 0 ]; do
  start=$(date +%s)
  spawn php spark --no-header jobs:work
  reap
  if [ "$stopping" = 1 ]; then break; fi
  # a run of 60 s or more, or a clean exit (after --max-seconds), starts the backoff again at 1 s
  if [ "$code" = 0 ] || [ $(($(date +%s) - start)) -ge 60 ]; then delay=1; fi
  if [ "$code" != 0 ]; then echo "apotheke-jobs: jobs:work exited with code $code, restarting in ${delay}s" >&2; fi
  failed=$code
  spawn sleep "$delay"
  reap
  if [ "$failed" != 0 ]; then
    delay=$((delay * 2))
    if [ "$delay" -gt 60 ]; then delay=60; fi
  fi
done
echo "apotheke-jobs: worker stopped"
exit 0
