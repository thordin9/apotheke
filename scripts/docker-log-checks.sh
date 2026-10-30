#!/usr/bin/env bash
# The access log never records the secrets a request carries (L-1422): Cookie, Authorization, the CSRF token and the
# edge proxy's shared secret (X-Apotheke-Proxy). Sends one request with marker values in those headers and in the
# User-Agent, then reads the container's log: the User-Agent marker must be there (so the request was logged), the
# others must not. Works for both images.
# Usage: scripts/docker-log-checks.sh <container> [base-url]   (default http://127.0.0.1:9091)
set -euo pipefail
name=${1:?container name}
base=${2:-http://127.0.0.1:9091}
fail=0
check() { # name expected actual
  if [[ $2 == "$3" ]]; then echo "ok   $1"; else echo "FAIL $1: expected '$2', got '$3'"; fail=1; fi
}
m=$(openssl rand -hex 8)
curl -s -o /dev/null -A "logcheck-ua-$m" \
  -H "Cookie: apotheke=logcheck-cookie-$m" -H "Authorization: Bearer logcheck-auth-$m" \
  -H "X-CSRF-Token: logcheck-csrf-$m" -H "X-Apotheke-Proxy: logcheck-proxy-$m" \
  -X POST -d 'name=x' "$base/locations"
sleep 1
log=$(docker logs "$name" 2>&1)
check "the request is in the access log"   1 "$(grep -c "logcheck-ua-$m" <<<"$log" || true)"
for h in cookie auth csrf proxy; do
  check "access log has no $h value"       0 "$(grep -c "logcheck-$h-$m" <<<"$log" || true)"
done
exit $fail
