#!/usr/bin/env bash
# apt for CI, retried through short mirror and DNS outages.
#   scripts/ci-apt.sh install PKG...   apt-get update, then apt-get install -y PKG (options such as --no-install-recommends pass through)
#   scripts/ci-apt.sh run CMD...       run CMD (a command that calls apt itself, e.g. playwright install --with-deps)
# Each attempt is capped, a failed attempt is retried up to CI_APT_ATTEMPTS times (default 5) with a growing pause
# (15/30/60/120 s), so an outage of up to about ten minutes doesn't fail the job. `apt-get update` exits 0 when an
# index can't be fetched (for example "Could not resolve 'azure.archive.ubuntu.com'"), so it runs with --error-on=any
# and such a failure is retried rather than ending in "Unable to locate package". After a failed attempt on a
# GitHub-hosted runner, the Azure mirror is dropped from /etc/apt/apt-mirrors.txt so apt goes to archive.ubuntu.com.
# Before every attempt, pin_ubuntu_hosts (below) works around a runner whose DNS can't resolve the Ubuntu mirrors.
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

# TEMPORARY outage workaround (remove with https://github.com/thordin9/apotheke/issues/278): when the runner's own
# DNS can't resolve one of the Ubuntu mirror hosts, pin it in /etc/hosts with addresses from DNS-over-HTTPS. A host that
# resolves natively is left alone. Each host is looked up at Google (dns.google, 8.8.8.8) and Cloudflare
# (cloudflare-dns.com, 1.1.1.1), reached by IP with curl --resolve so the lookup doesn't need the broken resolver.
# Only the final A records count, so a CNAME chain resolves to its end. The addresses both providers return are used.
# If that intersection is empty but both answered with valid A records (geo-steered names such as the Azure mirror can
# differ), the union of the two answers is used. If either provider fails, the step fails with ::error::. apt still
# fetches over http and checks every Release signature, so a wrong address can fail a fetch but can't change a package.
ubuntu_hosts=(azure.archive.ubuntu.com archive.ubuntu.com security.ubuntu.com)
doh_a() {
  # doh_a URL RESOLVE ACCEPT: print the A records of a DoH JSON answer with Status 0, one per line. A resolver whose
  # upstream is flaky answers SERVFAIL (Status 2) now and then, so a non-zero Status or no A record is asked again.
  local body ips try
  for try in 1 2 3 4 5; do
    if body="$(curl -fsS --connect-timeout 10 --max-time 20 --retry 3 --retry-all-errors --resolve "$2" \
      -H "accept: $3" "$1")" && printf '%s' "$body" | grep -qE '"Status": *0[,}]' &&
      ips="$(printf '%s' "$body" | grep -oE '"type": *1, *"TTL": *[0-9]+, *"data": *"[0-9]{1,3}(\.[0-9]{1,3}){3}"' |
        grep -oE '[0-9]{1,3}(\.[0-9]{1,3}){3}' | sort -u)" && [ -n "$ips" ]; then
      printf '%s\n' "$ips"
      return 0
    fi
    [ "$try" -lt 5 ] && sleep 3
  done
  return 1
}
pin_ubuntu_hosts() {
  local host google cloudflare ips rule
  for host in "${ubuntu_hosts[@]}"; do
    if grep -qE "[[:space:]]${host//./\\.} # ci-apt DoH pin\$" /etc/hosts; then
      echo "ci-apt: dns ${host}: DoH pin from an earlier attempt"
      continue
    fi
    if ips="$(timeout 30 getent ahostsv4 "$host" | awk '{print $1}' | sort -u | tr '\n' ' ')" && [ -n "$ips" ]; then
      echo "ci-apt: dns ${host}: native (${ips% })"
      continue
    fi
    google="$(doh_a "https://dns.google/resolve?name=${host}&type=A" dns.google:443:8.8.8.8 application/dns-json)" || google=""
    cloudflare="$(doh_a "https://cloudflare-dns.com/dns-query?name=${host}&type=A" cloudflare-dns.com:443:1.1.1.1 \
      application/dns-json)" || cloudflare=""
    if [ -z "$google" ] || [ -z "$cloudflare" ]; then
      echo "::error::ci-apt: ${host} doesn't resolve natively and DNS-over-HTTPS failed" \
        "(google: $(printf '%s' "${google:-none}" | tr '\n' ' '), cloudflare: $(printf '%s' "${cloudflare:-none}" | tr '\n' ' '))"
      return 1
    fi
    ips="$(comm -12 <(printf '%s\n' "$google") <(printf '%s\n' "$cloudflare"))"
    rule=intersection
    if [ -z "$ips" ]; then
      ips="$(printf '%s\n%s\n' "$google" "$cloudflare" | sort -u)"
      rule=union
    fi
    printf '%s\n' "$ips" | sed "s/\$/ ${host} # ci-apt DoH pin/" | "${sudo[@]}" tee -a /etc/hosts >/dev/null
    echo "ci-apt: dns ${host}: DoH ${rule} pinned in /etc/hosts ($(printf '%s' "$ips" | tr '\n' ' '))"
  done
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
      timeout --kill-after=10 300 "$@"
      ;;
    *)
      echo "usage: $0 install PKG... | run CMD..." >&2
      exit 2
      ;;
  esac
}

[ "$#" -ge 2 ] || { echo "usage: $0 install PKG... | run CMD..." >&2; exit 2; }
for ((n = 1; ; n++)); do
  # A failed pin counts as a failed attempt, so it's retried with the rest; after the last one the step fails.
  if pin_ubuntu_hosts && attempt "$@"; then
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
