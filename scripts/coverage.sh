#!/usr/bin/env bash
# Collect PHP line coverage: the module suite (tests/Modules) and the root suite (tests/Unit, with every php -S request
# its servers answer), merged with phpcov; list the Playwright tests and write the README coverage table. Needs pcov.
# Usage: scripts/coverage.sh [--no-readme]. PHPUNIT_ARGS adds arguments to the root run (CI: --log-junit).
set -euo pipefail
cd "$(dirname "$0")/.."
out=coverage
rm -rf "$out"
mkdir -p "$out/cov-modules" "$out/cov-root" "$out/cov-all" "$out/http-raw"
pcov=(-d pcov.enabled=1 -d pcov.directory="$PWD" -d "pcov.exclude=~/(vendor|tests|writable)/~")
filter=(--coverage-filter app --coverage-filter modules --coverage-filter migrations --coverage-filter public)
php "${pcov[@]}" vendor/bin/phpunit -c tests/Modules/phpunit.xml.dist "${filter[@]}" --no-progress --coverage-php "$out/cov-modules/modules.cov"
# The module suite ran above, so the root run leaves out the test that starts it again.
# shellcheck disable=SC2086 # PHPUNIT_ARGS is a list of arguments
APOTHEKE_COVERAGE_DIR="$PWD/$out/http-raw" php "${pcov[@]}" vendor/bin/phpunit "${filter[@]}" --exclude-filter Ci4ModuleSuiteTest \
  --no-progress --coverage-php "$out/cov-root/root.cov" ${PHPUNIT_ARGS:-}
if ! compgen -G "$out/http-raw/*.raw" > /dev/null; then
  echo "no HTTP coverage dumps were written (re-run with APOTHEKE_COVERAGE_TRACE=1 for a per-request trace)" >&2
  exit 1
fi
php tests/coverage/merge.php "$out/http-raw" "$out/cov-root/http.cov"
cp "$out/cov-modules/modules.cov" "$out/cov-root/root.cov" "$out/cov-root/http.cov" "$out/cov-all/"
vendor/bin/phpcov merge --clover "$out/modules.xml" "$out/cov-modules" > /dev/null
vendor/bin/phpcov merge --clover "$out/root.xml" "$out/cov-root" > /dev/null
vendor/bin/phpcov merge --clover "$out/combined.xml" --html "$out/html" --text "$out/combined.txt" "$out/cov-all" > /dev/null
rm -rf "$out/http-raw"
npx --no-install playwright test --list --reporter=json > "$out/playwright-list.json"
if [[ ${1:-} != --no-readme ]]; then php scripts/coverage-readme.php "$out"; fi
