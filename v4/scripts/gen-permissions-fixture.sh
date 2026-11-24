#!/bin/bash
# The permission matrix fixture (plan §5 S1.3, increment 2b): v3's permission list and the built-in
# roles' permissions, captured once and committed, because `src/auth/permissions.ts` spells them by
# hand and a typo there is a permission granted or withheld silently.
#
#     v4/scripts/gen-permissions-fixture.sh          # regenerate test/fixtures/php-permissions.json
#     v4/scripts/gen-permissions-fixture.sh --check  # hash check only (no PHP needed; this is what CI runs)
#
# Requirements: any PHP 8.4 CLI. No CI4 boot, no database, no extension: the generator reads two PHP
# files' constants and prints them.
#
# Generated on the developer's box, not in CI: v4.yml has no PHP toolchain, which is why the fixture
# carries the hash of every PHP file it read and `--check` fails when one changes.

set -euo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
OUT="$ROOT/v4/test/fixtures/php-permissions.json"

if [ "${1:-}" = "--check" ]; then
  exec node "$ROOT/v4/scripts/check-session-fixture.mjs" "$OUT" "permissions" "v4/scripts/gen-permissions-fixture.sh"
fi

PHP=${PHP:-php}
"$PHP" "$ROOT/v4/scripts/gen-permissions-fixture.php" > "$OUT"
node "$ROOT/v4/scripts/check-session-fixture.mjs" "$OUT" "permissions" "v4/scripts/gen-permissions-fixture.sh"
