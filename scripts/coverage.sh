#!/usr/bin/env bash
# Collect PHP line coverage (PHPUnit unit suite + HTTP integration suite, merged with phpcov), list the Playwright
# tests and write the README coverage table. Needs the pcov extension. Usage: scripts/coverage.sh [--no-readme]
set -euo pipefail
cd "$(dirname "$0")/.."
out=coverage
rm -rf "$out"
mkdir -p "$out/cov-unit" "$out/cov-http" "$out/cov-all" "$out/http-raw"
php -d pcov.enabled=1 -d pcov.directory="$PWD" -d "pcov.exclude=~/(vendor|tests)/~" vendor/bin/phpunit --testsuite unit --no-progress --coverage-php "$out/cov-unit/unit.cov"
mkdir -p "$out/tmp"
if ! TMPDIR="$PWD/$out/tmp" APOTHEKE_COVERAGE_DIR="$PWD/$out/http-raw" php tests/integration.php > "$out/integration.log" 2>&1; then
  tail -40 "$out/integration.log"
  echo "== php -S errors"
  tmp=$(TMPDIR="$PWD/$out/tmp" php -r 'echo sys_get_temp_dir();')
  grep -h -m 20 -iE 'fatal|error|warning|exception' "$tmp"/apotheke-*.server.log || echo "(none logged in $tmp)"
  exit 1
fi
tail -1 "$out/integration.log"
php tests/coverage/merge.php "$out/http-raw" "$out/cov-http/http.cov"
cp "$out/cov-unit/unit.cov" "$out/cov-http/http.cov" "$out/cov-all/"
vendor/bin/phpcov merge --clover "$out/unit.xml" "$out/cov-unit" > /dev/null
vendor/bin/phpcov merge --clover "$out/http.xml" "$out/cov-http" > /dev/null
vendor/bin/phpcov merge --clover "$out/combined.xml" --html "$out/html" --text "$out/combined.txt" "$out/cov-all" > /dev/null
rm -rf "$out/http-raw" "$out/tmp"
npx --no-install playwright test --list --reporter=json > "$out/playwright-list.json"
if [[ ${1:-} != --no-readme ]]; then php scripts/coverage-readme.php "$out"; fi
