#!/usr/bin/env bash
# Lint everything: php -l on all PHP files, node --check on JS, Markdown links, shellcheck on shell scripts, actionlint on workflows.
set -euo pipefail
cd "$(dirname "$0")/.."
echo "== PHP lint"
find public app modules tools tests bin migrations config.example.php -name '*.php' -not -path '*/node_modules/*' -not -path 'vendor/*' -not -path 'writable/*' -print0 | xargs -0 -n1 php -l >/dev/null
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
if ! command -v actionlint >/dev/null 2>&1 && [ "${CI:-}" = "true" ]; then
  bash scripts/install-actionlint.sh "${RUNNER_TEMP:-/tmp}"
  export PATH="${RUNNER_TEMP:-/tmp}:$PATH"
fi
if command -v actionlint >/dev/null 2>&1; then
  echo "== actionlint"
  actionlint -no-color
  echo "   ok"
else
  echo "== actionlint: skipped (not installed)"
fi
echo "== changelog links"
php scripts/release.php check-links
echo "   ok"
echo "== pipefail-safe greps"
# `curl … | grep -q` under pipefail fails at random: grep -q exits at the first match and curl gets SIGPIPE (exit 23)
if grep -nE '^[^#]*curl[^|]*\|[[:space:]]*grep[[:space:]]+-[a-zA-Z]*q' scripts/*.sh .github/workflows/*.yml; then echo "   save the response to a file and grep that instead"; exit 1; fi
echo "   ok"
