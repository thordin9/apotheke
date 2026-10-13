#!/usr/bin/env bash
# Installs PHP 8.3 inside the official Playwright image (Ubuntu 24.04 "noble") for the visual E2E tests.
# Used by CI and by scripts/update-snapshots.sh, so baselines and test runs share one environment.
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
if command -v php >/dev/null 2>&1; then php -v | head -1; exit 0; fi
apt-get update -qq
apt-get install -y -qq --no-install-recommends php8.3-cli php8.3-sqlite3 php8.3-pgsql php8.3-gd php8.3-mbstring php8.3-xml php8.3-zip >/dev/null
php -v | head -1
