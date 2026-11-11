#!/usr/bin/env bash
# apt for CI, retried through short mirror and DNS outages.
#   scripts/ci-apt.sh install PKG...   apt-get update, then apt-get install -y PKG (options such as --no-install-recommends pass through)
#   scripts/ci-apt.sh run CMD...       run CMD (a command that calls apt itself, e.g. playwright install --with-deps)
# Each attempt is capped, a failed attempt is retried up to CI_APT_ATTEMPTS times (default 5) with a growing pause
# (15/30/60/120 s), so an outage of up to about ten minutes doesn't fail the job. `apt-get update` exits 0 when an
# index can't be fetched (for example "Could not resolve 'azure.archive.ubuntu.com'"), so it runs with --error-on=any
# and such a failure is retried rather than ending in "Unable to locate package". After a failed attempt on a
# GitHub-hosted runner, the Azure mirror is dropped from /etc/apt/apt-mirrors.txt so apt goes to archive.ubuntu.com.
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
attempts="${CI_APT_ATTEMPTS:-5}"
sudo=()
[ "$(id -u)" -eq 0 ] || sudo=(sudo)
apt_opts=(-o Acquire::Retries=5 -o Acquire::http::Timeout=30 -o Acquire::https::Timeout=30)
mirrors=/etc/apt/apt-mirrors.txt

drop_azure_mirror() {
  if [ -f "$mirrors" ] && grep -q 'azure\.archive\.ubuntu\.com' "$mirrors" && grep -qv 'azure\.archive\.ubuntu\.com' "$mirrors"; then
    "${sudo[@]}" sed -i '/azure\.archive\.ubuntu\.com/d' "$mirrors"
    echo "ci-apt: dropped azure.archive.ubuntu.com from $mirrors" >&2
  fi
}

attempt() {
  case "$1" in
    install)
      shift
      timeout --kill-after=10 180 "${sudo[@]}" apt-get "${apt_opts[@]}" update -qq --error-on=any &&
        timeout --kill-after=10 300 "${sudo[@]}" apt-get "${apt_opts[@]}" install -y -qq "$@"
      ;;
    run)
      shift
      timeout --kill-after=10 600 "$@"
      ;;
    *)
      echo "usage: $0 install PKG... | run CMD..." >&2
      exit 2
      ;;
  esac
}

[ "$#" -ge 2 ] || { echo "usage: $0 install PKG... | run CMD..." >&2; exit 2; }
for ((n = 1; ; n++)); do
  if attempt "$@"; then
    exit 0
  fi
  if [ "$n" -ge "$attempts" ]; then
    echo "ci-apt: '$*' failed after ${attempts} attempts" >&2
    exit 1
  fi
  pause=$((15 * (1 << (n - 1))))
  echo "ci-apt: '$*' failed (attempt ${n}/${attempts}), retrying in ${pause}s" >&2
  drop_azure_mirror
  sleep "$pause"
done
