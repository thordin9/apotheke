#!/usr/bin/env bash
# Web-server behaviour of the Docker image with sign-in on (SEC-L2, SEC-M3): no session for anonymous requests,
# large restore bodies only after sign-in. The container must run with APP_PASSWORD; pass the same value in APP_PW.
# Usage: APP_PW=… scripts/docker-auth-checks.sh [base-url]   (default http://127.0.0.1:9092)
set -euo pipefail
base=${1:-http://127.0.0.1:9092}
: "${APP_PW:?set APP_PW to the APP_PASSWORD of the container}"
fail=0
check() { # name expected actual
  if [[ $2 == "$3" ]]; then echo "ok   $1"; else echo "FAIL $1: expected '$2', got '$3'"; fail=1; fi
}
work=$(mktemp -d); trap 'rm -rf "$work"' EXIT
jar="$work/jar"
code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
hdr() { curl -s -D - -o /dev/null "${@:2}" | tr -d '\r' | awk -v h="$1" 'BEGIN{IGNORECASE=1} index(tolower($0), tolower(h) ":")==1 {sub(/^[^:]*: */, ""); print; exit}'; }
head -c $((30 * 1024 * 1024)) /dev/zero > "$work/big"
printf '%s' "$APP_PW" > "$work/pw"   # read by curl from a file, so the password is never in a process list

check "anonymous / -> sign-in"           303 "$(code "$base/")"
check "anonymous / sets no cookie"       "" "$(hdr Set-Cookie "$base/")"
check "anonymous 404 sets no cookie"     "" "$(hdr Set-Cookie "$base/no/such/page")"
check "unknown Host refused"             421 "$(code -H 'Host: other.example' "$base/")"
check "restore check: not signed in"     401 "$(code "$base/backup/restore/check")"
# nginx asks the app before it reads the body: refused without an upload
check "30 MB restore refused before sign-in" 401 "$(code -X POST -F "backup=@$work/big" "$base/backup/restore")"
# an unknown Host on the restore upload gets the app's 421, not nginx's 500 (#70 N-3)
check "30 MB restore with unknown Host"  421 "$(code -H 'Host: other.example' -X POST -F "backup=@$work/big" "$base/backup/restore")"

curl -s -c "$jar" -b "$jar" -o "$work/login.html" "$base/login"
tok=$(sed -n 's/.*name="csrf" value="\([a-f0-9]*\)".*/\1/p' "$work/login.html")
tok=${tok%%$'\n'*}
check "sign-in page has a token"         1 "$([[ ${#tok} == 64 ]] && echo 1 || echo "${#tok}")"
check "sign in"                          303 "$(code -c "$jar" -b "$jar" --data-urlencode "csrf=$tok" --data-urlencode 'username=admin' --data-urlencode "password@$work/pw" "$base/login")"
check "restore check: admin"             204 "$(code -b "$jar" "$base/backup/restore/check")"
curl -s -b "$jar" -o "$work/backup.html" "$base/backup"
tok=$(sed -n 's/.*name="csrf" content="\([a-f0-9]*\)".*/\1/p' "$work/backup.html")
tok=${tok%%$'\n'*}
# signed in: the 30 MB body reaches the app, which rejects the non-zip with 422 (not 401/413)
check "30 MB restore accepted after sign-in" 422 "$(code -b "$jar" -X POST -F "csrf=$tok" -F "backup=@$work/big" "$base/backup/restore")"
exit $fail
