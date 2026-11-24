#!/bin/bash
# The claim-page fixture (plan §5 S1.2b): what v3's `AuthViews::claim()` prints, captured once and
# committed.
#
#     v4/scripts/gen-claim-fixture.sh          # regenerate test/fixtures/php-claim.json
#     v4/scripts/gen-claim-fixture.sh --check  # hash check only (no PHP needed; this is what CI runs)
#
# Requirements: PHP 8.4 CLI. Nothing else — no CI4 boot, no database, no `intl`, because the page is
# one pure function and the generator calls it with a `Page` stub.
#
# Generated on the developer's box, not in CI: v4.yml has no PHP toolchain, which is why the fixture
# carries the hash of every PHP file it read and `--check` fails when one changes.

set -euo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
OUT="$ROOT/v4/test/fixtures/php-claim.json"

if [ "${1:-}" = "--check" ]; then
  exec node "$ROOT/v4/scripts/check-session-fixture.mjs" "$OUT" "claim-page" "v4/scripts/gen-claim-fixture.sh"
fi

PHP=${PHP:-php}
"$PHP" "$ROOT/v4/scripts/gen-claim-fixture.php" > "$OUT"
node "$ROOT/v4/scripts/check-session-fixture.mjs" "$OUT" "claim-page" "v4/scripts/gen-claim-fixture.sh"
