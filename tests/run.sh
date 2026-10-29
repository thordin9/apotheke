#!/usr/bin/env bash
# Apotheke: lint (PHP, JS, shell) then run the module suite.
# Needs `composer install`.
set -euo pipefail
cd "$(dirname "$0")/.."
bash scripts/lint.sh
vendor/bin/phpunit -c tests/Modules/phpunit.xml.dist
