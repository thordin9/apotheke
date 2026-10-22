#!/usr/bin/env bash
# Installs PHP inside the official Playwright image (Ubuntu 24.04 "noble") for the visual E2E tests.
# CI passes 8.3 or 8.4. scripts/update-snapshots.sh calls this with no argument, which stays on 8.3
# so baselines and the PHP 8.3 shards share one environment.
# Ubuntu 24.04 has php8.3 in the distro archive. php8.4 comes from ppa:ondrej/php.
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
export LC_ALL=C.UTF-8
version="${1:-8.3}"
# Launchpad's PPA host returns short bursts of 503s. apt retries each download with its own backoff,
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
case "$version" in
  8.3|8.4) ;;
  *) echo "e2e-container-setup: PHP ${version} is not supported (use 8.3 or 8.4)" >&2; exit 2 ;;
esac
php_mm() { php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;'; }
if command -v php >/dev/null 2>&1 && [ "$(php_mm)" = "$version" ]; then
  php -v | head -1
  exit 0
fi
retry apt-get "${apt_opts[@]}" update -qq
if [ "$version" = "8.4" ]; then
  retry apt-get "${apt_opts[@]}" install -y -qq --no-install-recommends software-properties-common >/dev/null
  retry add-apt-repository -y -n ppa:ondrej/php
  retry apt-get "${apt_opts[@]}" update -qq
fi
retry apt-get "${apt_opts[@]}" install -y -qq --no-install-recommends \
  "php${version}-cli" "php${version}-sqlite3" "php${version}-pgsql" "php${version}-gd" \
  "php${version}-mbstring" "php${version}-xml" "php${version}-zip" "php${version}-curl" >/dev/null
if [ "$(php_mm)" != "$version" ]; then
  update-alternatives --set php "/usr/bin/php${version}"
fi
php -v | head -1
test "$(php_mm)" = "$version"
