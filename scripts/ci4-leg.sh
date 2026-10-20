#!/usr/bin/env bash
# Decide whether a CI matrix cell runs PHPUnit.
# stdout is a single run=true or run=false line for GITHUB_OUTPUT.
# The explanation goes to stderr so the Actions log shows why a leg skipped.
set -euo pipefail

app=${1:?usage: ci4-leg.sh <legacy|ci4> [root]}
root=${2:-.}

say() { echo "$*" >&2; }

if [ "$app" != "ci4" ]; then
  echo "run=true"
  exit 0
fi

if [ ! -f "$root/ci4/public/index.php" ]; then
  say "ci4/public/index.php is not in this tree."
  say "The CI4 leg skips PHPUnit until the skeleton exists (Feature 12 PR 4)."
  say "The job stays green so feature/ci4 is not blocked before any CI4 code."
  echo "run=false"
  exit 0
fi

if [ ! -f "$root/tests/lib.php" ] || ! grep -q 'APOTHEKE_APP' "$root/tests/lib.php"; then
  say "ci4/public/index.php exists, but tests/lib.php does not switch servers on APOTHEKE_APP yet."
  say "Refusing to run the legacy suite and report it as the CI4 leg."
  exit 1
fi

echo "run=true"
