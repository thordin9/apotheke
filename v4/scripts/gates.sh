#!/usr/bin/env bash
# D30's six gates, then D105's core scan as a REPORT rather than a seventh gate.
#
# The driver lives in the repo beside the checker it drives (D107): resolve the tree from this
# script's own path, so it runs from a fresh clone instead of from one box's home directory.
#
#     bash v4/scripts/gates.sh
#
# Node: the gates need node 22 - this box's default node 18 dies at vitest startup. Found in order:
# $APOTHEKE_NODE_BIN, a `node22/bin` checked out beside the repo, then whatever PATH already has.
#
# Logs: $APOTHEKE_GATE_LOGS, else $HOME; one gate-<name>.log per gate, overwritten each run.
#
# The core scan's `--since` is this run's start in epoch seconds (D105): a core dated before the
# first gate is named `older` and does not fail the run, one dated at or after it is a finding with
# its size and time. Gate colour reads an exit code and the census reads annotations, so a workerd
# or a browser that dies after its verdict is written is invisible to both - only the filesystem
# knows, and only if something looks. It reads v4/, the repo root and $HOME, because a core lands in
# the crashing process's cwd and those are the three cwds used here (D106); the report line names
# every directory it actually read, so a green line is a claim with a list behind it.
set -uo pipefail

HERE=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
ROOT=$(cd -- "$HERE/../.." && pwd)
cd "$ROOT/v4" || exit 1

if [ -n "${APOTHEKE_NODE_BIN:-}" ]; then
	export PATH="$APOTHEKE_NODE_BIN:$PATH"
elif [ -x "$ROOT/../node22/bin/node" ]; then
	export PATH="$ROOT/../node22/bin:$PATH"
fi

LOGDIR="${APOTHEKE_GATE_LOGS:-$HOME}"
START=$(date +%s)

gate() {
	local name="$1"; shift
	local log="$LOGDIR/gate-$name.log"
	"$@" >"$log" 2>&1
	local code=$?
	echo "GATE $name exit=$code  ($(tail -c 300 "$log" | tr '\n' ' ' | tail -c 160))"
	return $code
}

report() {
	local name="$1"; shift
	local log="$LOGDIR/gate-$name.log"
	"$@" >"$log" 2>&1
	local code=$?
	echo "REPORT $name exit=$code  ($(tail -c 300 "$log" | tr '\n' ' ' | tail -c 160))"
	return $code
}

gate tsc npx tsc --noEmit
gate biome npx biome check .
gate depcruise npx depcruise src
gate routes npm run routes:check
gate fixtures npm run fixtures:check
gate vitest npx vitest run
report cores node scripts/check-core-dumps.mjs --since "$START" --dir . --dir .. --dir "$HOME"
