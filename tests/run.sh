#!/usr/bin/env bash
# Apotheke: lint (PHP, JS, shell) then run the integration tests.
# The integration tests start `php -S` on throwaway temp data dirs.
set -euo pipefail
cd "$(dirname "$0")/.."
bash scripts/lint.sh
php tests/integration.php
