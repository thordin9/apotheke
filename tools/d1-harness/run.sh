#!/usr/bin/env bash
# Local D1 harness (P4 spike): wrangler dev --local on 127.0.0.1:8799, runs run.php, then stops.
# Never remote, no Cloudflare login. Usage: ./run.sh [worktree, default this checkout] [script.php, default run.php]
set -euo pipefail
cd "$(dirname "$0")"
state=$(mktemp -d /tmp/coder2-d1state.XXXXXX)
setsid npx -y node@22 node_modules/wrangler/bin/wrangler.js dev --local --ip 127.0.0.1 --port 8799 --persist-to "$state" >"$state/dev.log" 2>&1 &
pid=$!
trap 'kill -- -$pid 2>/dev/null; wait $pid 2>/dev/null || true; sleep 1; rm -rf "$state"' EXIT
for _ in $(seq 60); do grep -q 'Ready on' "$state/dev.log" && break; sleep 1; done
php8.4 "${2:-run.php}" "${1:-$(cd ../.. && pwd)}"
