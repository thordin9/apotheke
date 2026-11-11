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
# DNS can't resolve one of the Ubuntu mirror hosts that apt uses, pin it in /etc/hosts with addresses from
# DNS-over-HTTPS. A host that resolves natively is left alone. Google, Cloudflare and Quad9 are asked in parallel, each
# by IP with curl --resolve so the lookup doesn't need the broken resolver, and only final A records count (a CNAME
# chain resolves to its end). Rules, logged per host:
#   two-provider     2 or 3 providers answered: pin the intersection of their answers, or their union if that is
#                    empty (geo-steered names such as the Azure mirror can differ between resolvers).
#   single-provider  only one answered after its retries: pin its answer and emit ::warning::.
#   none answered    ::error:: and the attempt fails; after the last attempt the step fails.
# apt still fetches over http and checks every Release signature, so a wrong address can fail a fetch but can't change
# a package.
ubuntu_hosts=(azure.archive.ubuntu.com archive.ubuntu.com security.ubuntu.com)
# name|URL with %s for the host|host:port to pin for curl|anycast IP|second anycast IP
doh_providers=(
  "google|https://dns.google/resolve?name=%s&type=A|dns.google:443|8.8.8.8|8.8.4.4"
  "cloudflare|https://cloudflare-dns.com/dns-query?name=%s&type=A|cloudflare-dns.com:443|1.1.1.1|1.0.0.1"
  "quad9|https://dns.quad9.net:5053/dns-query?name=%s&type=A|dns.quad9.net:5053|9.9.9.9|149.112.112.112"
)
doh_a() {
  # doh_a URL HOST:PORT IP1 IP2: print the A records of a DoH JSON answer with Status 0, one per line. A resolver
  # whose upstream is flaky answers SERVFAIL (Status 2) now and then, so a failed request, a non-zero Status or no A
  # record is asked again (6 tries, alternating the two IPs; at most about 75 s).
  local body ips try ip
  for try in 1 2 3 4 5 6; do
    ip="$3"
    [ $((try % 2)) -eq 0 ] && ip="$4"
    if body="$(curl -fsS --connect-timeout 5 --max-time 10 --resolve "$2:${ip}" -H "accept: application/dns-json" \
      "$1" 2>/dev/null)" && printf '%s' "$body" | grep -qE '"Status": *0[,}]' &&
      ips="$(printf '%s' "$body" | grep -oE '\{[^{}]*\}' | grep -E '"type": *1[,}]' |
        grep -oE '"data": *"[0-9]{1,3}(\.[0-9]{1,3}){3}"' | grep -oE '[0-9]{1,3}(\.[0-9]{1,3}){3}' | sort -u)" &&
      [ -n "$ips" ]; then
      printf '%s\n' "$ips"
      return 0
    fi
    [ "$try" -lt 6 ] && sleep 3
  done
  return 1
}
pin_ubuntu_hosts() {
  local host ips rule tmp entry name url hostport ip1 ip2 failed=0 answered set
  local todo=()
  for host in "${ubuntu_hosts[@]}"; do
    # Only hosts apt is configured to use (sources, or the runner's mirror list) need to resolve.
    if ! grep -rqsE "//${host//./\\.}(/|:|[[:space:]]|\$)" /etc/apt/sources.list /etc/apt/sources.list.d "$mirrors"; then
      continue
    fi
    if grep -qE "[[:space:]]${host//./\\.} # ci-apt DoH pin\$" /etc/hosts; then
      echo "ci-apt: dns ${host}: DoH pin from an earlier attempt" >&2
      continue
    fi
    if ips="$(timeout 30 getent ahostsv4 "$host" | awk '{print $1}' | sort -u | tr '\n' ' ')" && [ -n "$ips" ]; then
      echo "ci-apt: dns ${host}: native (${ips% })" >&2
      continue
    fi
    todo+=("$host")
  done
  [ "${#todo[@]}" -gt 0 ] || return 0
  # Every host at every provider at once, so the step waits for the slowest lookup, not the sum of them.
  tmp="$(mktemp -d)"
  for host in "${todo[@]}"; do
    for entry in "${doh_providers[@]}"; do
      IFS='|' read -r name url hostport ip1 ip2 <<<"$entry"
      # shellcheck disable=SC2059 # the URL template is ours
      ( doh_a "$(printf "$url" "$host")" "$hostport" "$ip1" "$ip2" >"${tmp}/${host}.${name}" ||
        rm -f "${tmp}/${host}.${name}" ) &
    done
  done
  wait
  for host in "${todo[@]}"; do
    answered=()
    ips=""
    for entry in "${doh_providers[@]}"; do
      name="${entry%%|*}"
      [ -s "${tmp}/${host}.${name}" ] || continue
      answered+=("$name")
      if [ -z "$ips" ] && [ "${#answered[@]}" -eq 1 ]; then
        ips="$(cat "${tmp}/${host}.${name}")"
      else
        ips="$(comm -12 <(printf '%s\n' "$ips") "${tmp}/${host}.${name}")"
      fi
    done
    set="$(IFS=+; printf '%s' "${answered[*]}")"
    case "${#answered[@]}" in
      0)
        echo "::error::ci-apt: ${host} doesn't resolve natively and no DNS-over-HTTPS provider answered (google, cloudflare, quad9)" >&2
        failed=1
        continue
        ;;
      1)
        rule="single-provider (${set})"
        echo "::warning::single-provider pin ${host} via ${set}: $(printf '%s' "$ips" | tr '\n' ' ')" >&2
        ;;
      *)
        rule="two-provider (${set}) intersection"
        if [ -z "$ips" ]; then
          ips="$(for name in "${answered[@]}"; do cat "${tmp}/${host}.${name}"; done | sort -u)"
          rule="two-provider (${set}) union"
        fi
        ;;
    esac
    printf '%s\n' "$ips" | sed "s/\$/ ${host} # ci-apt DoH pin/" | "${sudo[@]}" tee -a /etc/hosts >/dev/null
    echo "ci-apt: dns ${host}: DoH ${rule} pinned in /etc/hosts ($(printf '%s' "$ips" | tr '\n' ' '))" >&2
  done
  rm -rf "$tmp"
  [ "$failed" -eq 0 ]
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
