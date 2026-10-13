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
# test data and server logs go to $out/tmp and are kept (KEEP_TEST_DATA) so a failure can be diagnosed
if ! KEEP_TEST_DATA=1 TMPDIR="$PWD/$out/tmp" APOTHEKE_COVERAGE_DIR="$PWD/$out/http-raw" php tests/integration.php > "$out/integration.log" 2>&1; then
  tail -40 "$out/integration.log"
  echo "== php -S errors"
  tmp=$(TMPDIR="$PWD/$out/tmp" php -r 'echo sys_get_temp_dir();')
  grep -h -m 20 -iE 'fatal|error|warning|exception|collector' "$tmp"/apotheke-*.server.log || echo "(none logged in $tmp)"
  exit 1
fi
tail -1 "$out/integration.log"
if ! compgen -G "$out/http-raw/*.raw" > /dev/null; then
  echo "no HTTP coverage dumps were written (re-run with APOTHEKE_COVERAGE_TRACE=1 for a per-request trace); php -S environment:" >&2
  php -d pcov.enabled=1 -d auto_prepend_file="$PWD/tests/coverage/prepend.php" -r 'var_dump(PHP_VERSION, extension_loaded("pcov"), ini_get("pcov.enabled"), ini_get("pcov.directory"), ini_get("opcache.enable_cli"), ini_get("opcache.jit"), ini_get("auto_prepend_file"), getenv("APOTHEKE_COVERAGE_DIR"));' >&2
  { grep -h -iE 'fatal|error|warning|collector' "$(TMPDIR="$PWD/$out/tmp" php -r 'echo sys_get_temp_dir();')"/apotheke-*.server.log | grep -v JIT | sort -u | head -20; } >&2 || true
  exit 1
fi
php tests/coverage/merge.php "$out/http-raw" "$out/cov-http/http.cov"
cp "$out/cov-unit/unit.cov" "$out/cov-http/http.cov" "$out/cov-all/"
vendor/bin/phpcov merge --clover "$out/unit.xml" "$out/cov-unit" > /dev/null
vendor/bin/phpcov merge --clover "$out/http.xml" "$out/cov-http" > /dev/null
vendor/bin/phpcov merge --clover "$out/combined.xml" --html "$out/html" --text "$out/combined.txt" "$out/cov-all" > /dev/null
rm -rf "$out/http-raw" "$out/tmp"
npx --no-install playwright test --list --reporter=json > "$out/playwright-list.json"
if [[ ${1:-} != --no-readme ]]; then php scripts/coverage-readme.php "$out"; fi
