#!/usr/bin/env bash
# Lint everything: php -l on all PHP files, node --check on JS, Markdown links, shellcheck on shell scripts.
set -euo pipefail
cd "$(dirname "$0")/.."
echo "== PHP lint"
find public src tests bin migrations config.example.php -name '*.php' -not -path '*/node_modules/*' -print0 | xargs -0 -n1 php -l >/dev/null
echo "   ok"
if command -v node >/dev/null 2>&1; then
  echo "== JS syntax"
  find public -name '*.js' -print0 | xargs -0 -n1 node --check
  echo "   ok"
  echo "== Markdown links"
  node scripts/check-links.mjs
else
  echo "== JS syntax: skipped (node not found)"
fi
if command -v shellcheck >/dev/null 2>&1; then
  echo "== shellcheck"
  find . -name '*.sh' -not -path './data/*' -not -path './.git/*' -not -path '*/node_modules/*' -print0 | xargs -0 shellcheck
  echo "   ok"
else
  echo "== shellcheck: skipped (not installed)"
fi
echo "== pipefail-safe greps"
# `curl … | grep -q` under pipefail fails at random: grep -q exits at the first match and curl gets SIGPIPE (exit 23)
if grep -nE '^[^#]*curl[^|]*\|[[:space:]]*grep[[:space:]]+-[a-zA-Z]*q' scripts/*.sh .github/workflows/*.yml; then echo "   save the response to a file and grep that instead"; exit 1; fi
echo "   ok"
