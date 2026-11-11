#!/usr/bin/env bash
# Installs PHP inside the official Playwright image (Ubuntu 24.04 "noble") for the visual E2E tests.
# The CI visual E2E shards pass 8.4 (PHP 8.4 only, as in ci.yml). scripts/update-snapshots.sh and
# snapshots.yml call this with no argument, which stays on 8.3 for the screenshot baselines.
# Ubuntu 24.04 has php8.3 in the distro archive. php8.4 comes from ppa:ondrej/php.
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
export LC_ALL=C.UTF-8
version="${1:-8.3}"
# Mirrors and Launchpad's PPA host return short bursts of errors. Every apt install goes through scripts/ci-apt.sh,
# which refreshes the indexes, fails on any index it can't fetch and retries with a growing pause (and drops the
# Azure mirror where the runner has one); retry() reruns the Launchpad lookup a few times before the job fails.
ci_apt="$(dirname "$0")/ci-apt.sh"
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
# PHP 8.4 comes first from the prebuilt Ubuntu 24.04 build that shivammathur/setup-php installs on the hosted runners
# (the Tests jobs), downloaded from GitHub, so Launchpad is not needed. Only the PHP binary, its config and the
# extensions below are unpacked; the shared libraries they link come from the Ubuntu archive. Xdebug, pcov and the
# other extras in that build stay off, as in the PPA install. If any of it fails, the PPA install below runs instead.
prebuilt_url="https://github.com/shivammathur/php-ubuntu/releases/download/builds/php_8.4-nts+ubuntu24.04.tar.zst"
prebuilt_exts=(calendar ctype curl dom exif ffi fileinfo ftp gd gettext iconv intl mbstring opcache pdo pdo_pgsql
  pdo_sqlite pgsql phar posix readline shmop simplexml sockets sqlite3 sysvmsg sysvsem sysvshm tokenizer xml xmlreader
  xmlwriter xsl zip)
prebuilt_libs=(libargon2-1 libcurl4t64 libedit2 libffi8 libgd3 libicu74 libonig5 libpcre2-8-0 libpq5 libsodium23
  libsqlite3-0 libssl3t64 libxml2 libxslt1.1 libzip4t64 zlib1g zstd)
install_prebuilt_84() {
  local tarball=/tmp/php-8.4-prebuilt.tar.zst ext members=() ini name
  curl -fsSL --retry 5 --retry-all-errors --connect-timeout 20 --max-time 300 -o "$tarball" "$prebuilt_url" || return 1
  members=(./usr/bin/php8.4 ./etc/php/8.4/cli/php.ini)
  for ext in "${prebuilt_exts[@]}"; do
    members+=("./usr/lib/php/20240924/${ext}.so" "./etc/php/8.4/mods-available/${ext}.ini")
  done
  tar -I zstd -xf "$tarball" -C / "${members[@]}" || return 1
  rm -f "$tarball"
  # Enable exactly the listed extensions, with the priority each one's ini file asks for.
  mkdir -p /etc/php/8.4/cli/conf.d
  for ext in "${prebuilt_exts[@]}"; do
    ini="/etc/php/8.4/mods-available/${ext}.ini"
    name="$(sed -n 's/^; priority=\([0-9]*\)$/\1/p' "$ini")-${ext}.ini"
    ln -sf "$ini" "/etc/php/8.4/cli/conf.d/${name}"
  done
  ln -sf /usr/bin/php8.4 /usr/bin/php
  # A missing shared library or extension means this build doesn't fit the image: fall back to the PPA.
  if ldd /usr/bin/php8.4 /usr/lib/php/20240924/*.so | grep -q 'not found'; then
    ldd /usr/bin/php8.4 /usr/lib/php/20240924/*.so | grep 'not found' >&2
    return 1
  fi
  php -n -v >/dev/null || return 1
  if php -v 2>&1 | grep -qiE 'warning|unable to load'; then
    php -v >&2
    return 1
  fi
  for ext in "${prebuilt_exts[@]}"; do
    case "$ext" in opcache) ext="Zend OpCache" ;; esac
    php -m | grep -qixF "$ext" || { echo "e2e-container-setup: prebuilt PHP lacks ${ext}" >&2; return 1; }
  done
}
if [ "$version" = "8.4" ]; then
  # Without apt there is no PHP either way, so a failure here ends the step rather than trying the PPA.
  "$ci_apt" install --no-install-recommends "${prebuilt_libs[@]}" >/dev/null
  if install_prebuilt_84 && [ "$(php_mm)" = "8.4" ]; then
    echo "e2e-container-setup: PHP 8.4 from the prebuilt setup-php build"
    php -v | head -1
    exit 0
  fi
  echo "e2e-container-setup: the prebuilt PHP 8.4 did not install, falling back to ppa:ondrej/php" >&2
  rm -f /usr/bin/php
  "$ci_apt" install --no-install-recommends software-properties-common >/dev/null
  # add-apt-repository looks the PPA up on api.launchpad.net through launchpadlib, which has no read timeout: when
  # Launchpad stalls mid-response the call hangs until the job times out and retry() never gets another go.
  # timeout(1) caps each attempt so a stalled lookup counts as a failed attempt and is retried.
  retry timeout --kill-after=10 120 add-apt-repository -y -n ppa:ondrej/php
fi
"$ci_apt" install --no-install-recommends \
  "php${version}-cli" "php${version}-sqlite3" "php${version}-pgsql" "php${version}-gd" \
  "php${version}-mbstring" "php${version}-xml" "php${version}-zip" "php${version}-curl" >/dev/null
if [ "$(php_mm)" != "$version" ]; then
  update-alternatives --set php "/usr/bin/php${version}"
fi
php -v | head -1
test "$(php_mm)" = "$version"
