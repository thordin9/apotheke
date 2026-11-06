#!/usr/bin/env bash
# Local D1 harness (P4 spike): wrangler dev --local on 127.0.0.1:8799, runs run.php, then stops.
# Never remote, no Cloudflare login. Usage: ./run.sh [worktree, default this checkout] [script.php, default run.php] [script arguments]
set -euo pipefail
cd "$(dirname "$0")"
state=$(mktemp -d /tmp/coder2-d1state.XXXXXX)
setsid npx -y node@22 node_modules/wrangler/bin/wrangler.js dev --local --ip 127.0.0.1 --port 8799 --persist-to "$state" >"$state/dev.log" 2>&1 &
pid=$!
ready=
cleanup() {
  status=$?
  if [[ $status -ne 0 && -f $state/dev.log ]]; then
    cat "$state/dev.log"
  fi
  kill -- -"$pid" 2>/dev/null || true
  wait "$pid" 2>/dev/null || true
  rm -rf "$state"
}
trap cleanup EXIT
for _ in $(seq 60); do
  if grep -q 'Ready on' "$state/dev.log"; then ready=1; break; fi
  if ! kill -0 "$pid" 2>/dev/null; then cat "$state/dev.log"; exit 1; fi
  sleep 1
done
if [[ -z $ready ]]; then cat "$state/dev.log"; exit 1; fi
${PHP:-php8.4} "${2:-run.php}" "${1:-$(cd ../.. && pwd)}" "${@:3}"
