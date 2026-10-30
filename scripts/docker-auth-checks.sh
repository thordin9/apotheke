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
# an unknown Host on the restore upload gets 421, not nginx's 500 (#70 N-3), with the app's short text (#73 QA N-2)
check "30 MB restore with unknown Host"  421 "$(code -H 'Host: other.example' -X POST -F "backup=@$work/big" "$base/backup/restore")"
check "its 421 body is the app's"        "$(curl -s -H 'Host: other.example' "$base/")" \
  "$(curl -s -H 'Host: other.example' -X POST -F "backup=@$work/big" "$base/backup/restore")"

curl -s -c "$jar" -b "$jar" -o "$work/login.html" "$base/login"
tok=$(sed -n 's/.*name="csrf" value="\([a-f0-9]*\)".*/\1/p' "$work/login.html")
tok=${tok%%$'\n'*}
# FAIL_MS (the container's SIGN_IN_FAIL_MS): a wrong password answers no sooner than that (L-400)
if [[ -n ${FAIL_MS:-} ]]; then
  t=$(curl -s -o /dev/null -w '%{http_code} %{time_total}' -c "$jar" -b "$jar" --data-urlencode "csrf=$tok" --data-urlencode 'username=admin' --data-urlencode 'password=not-the-password' "$base/login")
  check "wrong password answers 401"     401 "${t%% *}"
  check "after SIGN_IN_FAIL_MS ($FAIL_MS ms)" 1 "$(awk -v s="${t#* }" -v m="$FAIL_MS" 'BEGIN { print (s * 1000 >= m - 20 && s * 1000 < m + 1500) ? 1 : s }')"
fi
check "sign-in page has a token"         1 "$([[ ${#tok} == 64 ]] && echo 1 || echo "${#tok}")"
check "sign in"                          303 "$(code -c "$jar" -b "$jar" --data-urlencode "csrf=$tok" --data-urlencode 'username=admin' --data-urlencode "password@$work/pw" "$base/login")"
check "restore check: admin"             204 "$(code -b "$jar" "$base/backup/restore/check")"
curl -s -b "$jar" -o "$work/backup.html" "$base/backup"
tok=$(sed -n 's/.*name="csrf" content="\([a-f0-9]*\)".*/\1/p' "$work/backup.html")
tok=${tok%%$'\n'*}
# signed in: the 30 MB body reaches the app, which rejects the non-zip with 422 (not 401/413)
check "30 MB restore accepted after sign-in" 422 "$(code -b "$jar" -X POST -F "csrf=$tok" -F "backup=@$work/big" "$base/backup/restore")"
# signed in, every other route keeps the 22 MB cap: 413 before the app reads the body (the FrankenPHP image has the
# restore limits in PHP itself, so this cap is the only thing that stops a large body on these routes)
for p in /save /item/1/photos /item/1/attachments /settings/logo /settings/import /backup/restore/confirm /api/v1/session /no/such/page /backup/restore/ /backup/restore/check; do
  check "signed in, 30 MB POST $p -> 413" 413 "$(code -b "$jar" -H "X-CSRF-Token: $tok" -X POST -F "csrf=$tok" -F "file=@$work/big" "$base$p")"
done
# a body without Content-Length (chunked, as a proxy such as the edge Worker may send it) is cut off at the cap:
# nginx answers 413; FrankenPHP stops reading at 22 MB and the app refuses the cut-off request (4xx). Never accepted.
r=$(code -b "$jar" -H "X-CSRF-Token: $tok" -H 'Transfer-Encoding: chunked' -X POST -F "csrf=$tok" -F "photo=@$work/big" "$base/save")
check "signed in, 30 MB chunked POST /save refused" 1 "$([[ $r == 413 || $r == 4[0-9][0-9] ]] && echo 1 || echo "$r")"
exit $fail
