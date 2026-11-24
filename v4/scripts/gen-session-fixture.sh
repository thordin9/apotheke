#!/bin/bash
# The session fixture (plan §5 S1.2): what v3's own session classes answer, captured once and committed.
#
#     v4/scripts/gen-session-fixture.sh          # regenerate test/fixtures/php-session.json
#     v4/scripts/gen-session-fixture.sh --check  # hash check only (no PHP needed; this is what CI runs)
#
# Requirements: PHP 8.4 CLI with the app's autoloader reachable (vendor/), session support, and the
# `mbstring` extension. Point `PHP` at the binary when `php` is not on PATH.
#
# Generated on the developer's box, not in CI: v4.yml has no PHP toolchain, which is why the fixture
# carries the hash of every PHP file it read and `--check` fails when one changes.

set -euo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
OUT="$ROOT/v4/test/fixtures/php-session.json"

if [ "${1:-}" = "--check" ]; then
  exec node "$ROOT/v4/scripts/check-session-fixture.mjs"
fi

PHP=${PHP:-php}
"$PHP" "$ROOT/v4/scripts/gen-session-fixture.php" > "$OUT"
node "$ROOT/v4/scripts/check-session-fixture.mjs"
