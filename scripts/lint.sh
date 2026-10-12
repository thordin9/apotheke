#!/usr/bin/env bash
# Lint everything: php -l on all PHP files, node --check on JS, shellcheck on shell scripts.
set -euo pipefail
cd "$(dirname "$0")/.."
echo "== PHP lint"
find public src tests -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
echo "   ok"
if command -v node >/dev/null 2>&1; then
  echo "== JS syntax"
  find public -name '*.js' -print0 | xargs -0 -n1 node --check
  echo "   ok"
else
  echo "== JS syntax: skipped (node not found)"
fi
if command -v shellcheck >/dev/null 2>&1; then
  echo "== shellcheck"
  find . -name '*.sh' -not -path './data/*' -not -path './.git/*' -print0 | xargs -0 shellcheck
  echo "   ok"
else
  echo "== shellcheck: skipped (not installed)"
fi
