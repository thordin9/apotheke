#!/bin/bash
# Regenerate the seven v3 oracles (plan §5 S1.6, D36a). This is the one step of v4's proof that needs
# PHP, and it never runs in CI.
#
#     npm run fixtures:capture      # rewrite test/fixtures/*.json from v3's own answers
#     npm run fixtures:check        # hash check only: no PHP, and this is the gate
#
# Requirements: PHP 8.4 CLI with `intl` and `sqlite3` (v3's own driver), `curl` and `jq`.
# Point `PHP` at the binary when `php` is not on PATH.
#
# Why it is not a CI step: every generator boots v3 on a temp data dir and captures its answers over
# HTTP. That is reproducible only against the PHP major the captures were taken with, and it would put
# a PHP toolchain on a runner for a step whose *output* is committed — the fixture and its source
# hashes — while the only thing CI needs is the hash check. `fixtures:check` recomputes the SHA-256 of
# every source each fixture recorded and never boots v3, so a stale fixture fails without PHP at all.
#
# Capture ends by running the hash check: a capture that cannot reproduce its own hashes is not one.
set -euo pipefail

if [ -n "${CI:-}" ] || [ -n "${GITHUB_ACTIONS:-}" ]; then
  echo "fixtures:capture never runs in CI (D36): CI runs fixtures:check, which is hash-only." >&2
  echo "Regenerate on a machine with PHP 8.4 and commit the fixture with the source change it captures." >&2
  exit 1
fi

PHP=${PHP:-php}
ROOT=$(cd "$(dirname "$0")/../.." && pwd)

for tool in "$PHP" curl jq; do
  command -v "$tool" >/dev/null || { echo "missing $tool (need PHP 8.4 CLI with intl and sqlite3, curl, jq)" >&2; exit 1; }
done
"$PHP" -r 'exit(extension_loaded("intl") && extension_loaded("sqlite3") ? 0 : 1);' ||
  { echo "$PHP needs the intl and sqlite3 extensions" >&2; exit 1; }

cd "$ROOT"
# Same seven generators as `fixtures:check`, in the same order, without --check.
for gen in gen-html-fixtures gen-session-fixture gen-claim-fixture gen-security-fixture \
           gen-permissions-fixture gen-api-fixture gen-account-fixture; do
  echo "== $gen"
  bash "v4/scripts/$gen.sh"
done

echo "== fixtures:check (the capture must reproduce its own source hashes)"
cd "$ROOT/v4"
npm run --silent fixtures:check
cd "$ROOT"
echo "== changed under v4/test/fixtures"
git status --short v4/test/fixtures || true
