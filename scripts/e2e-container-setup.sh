#!/usr/bin/env bash
# Installs PHP 8.3 inside the official Playwright image (Ubuntu 24.04 "noble") for the visual E2E tests.
# Used by CI and by scripts/update-snapshots.sh, so baselines and test runs share one environment.
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
# Mirrors return short bursts of errors. apt retries each download with its own backoff,
# and retry() reruns a whole step a few times with a growing pause before the job fails.
apt_opts=(-o Acquire::Retries=5 -o Acquire::http::Timeout=30 -o Acquire::https::Timeout=30)
retry() {
  local n=1 max=4
  until "$@"; do
    if [ "$n" -ge "$max" ]; then
      echo "e2e-container-setup: '$*' failed after ${max} attempts" >&2
      return 1
    fi
    echo "e2e-container-setup: '$*' failed (attempt ${n}/${max}), retrying in $((n * 20))s" >&2
    sleep $((n * 20))
    n=$((n + 1))
  done
}
if command -v php >/dev/null 2>&1; then php -v | head -1; exit 0; fi
retry apt-get "${apt_opts[@]}" update -qq
retry apt-get "${apt_opts[@]}" install -y -qq --no-install-recommends php8.3-cli php8.3-sqlite3 php8.3-pgsql php8.3-gd php8.3-mbstring php8.3-xml php8.3-zip php8.3-curl >/dev/null
php -v | head -1
