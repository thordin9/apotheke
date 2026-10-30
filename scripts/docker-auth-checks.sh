#!/usr/bin/env bash
# Web-server behaviour of the Docker image with sign-in on (SEC-L2, SEC-M3): no session for anonymous requests,
# large restore bodies only after sign-in. The container must run with APP_PASSWORD; pass the same value in APP_PW.
# Usage: APP_PW=… [SERVER=nginx|frankenphp] scripts/docker-auth-checks.sh [base-url]   (default http://127.0.0.1:9092)
# SERVER=frankenphp adds the checks only that image's Caddyfile can pass (refusals before the body is read).
set -euo pipefail
base=${1:-http://127.0.0.1:9092}
: "${APP_PW:?set APP_PW to the APP_PASSWORD of the container}"
server=${SERVER:-}
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
# A request line and headers only, no body: the status for a declared Content-Length the client never has to send.
declared() { # method path length [cookie-jar]
  python3 - "$base" "$@" <<'PY'
import http.client, sys, urllib.parse
base, method, path, length = sys.argv[1:5]
jar = sys.argv[5] if len(sys.argv) > 5 else ""
u = urllib.parse.urlsplit(base)
c = http.client.HTTPConnection(u.hostname, u.port, timeout=20)
c.putrequest(method, path)
c.putheader("Content-Length", length)
c.putheader("Content-Type", "multipart/form-data; boundary=x")
if jar:
    cookies = [l.split("\t") for l in open(jar) if l.strip() and (not l.startswith("#") or l.startswith("#HttpOnly_"))]
    c.putheader("Cookie", "; ".join(f[5] + "=" + f[6].strip() for f in cookies if len(f) >= 7))
c.endheaders()
try:
    print(c.getresponse().status)
except OSError:
    print("no answer")
PY
}
# Before sign-in, a large or length-less body is never accepted (nginx reads it, then the app redirects to sign-in).
r=$(code -X POST -F "photo=@$work/big" "$base/save")
check "anonymous 30 MB POST /save refused" 1 "$([[ $r == 401 || $r == 303 || $r == 413 ]] && echo 1 || echo "$r")"
r=$(code -H 'Transfer-Encoding: chunked' -X POST --data-binary 'name=x' "$base/locations")
check "anonymous chunked POST refused" 1 "$([[ $r == 401 || $r == 303 ]] && echo 1 || echo "$r")"
if [[ $server == frankenphp ]]; then
  # The sign-in check comes before the size check (N-1424): a 600 MiB restore is a 401 before sign-in, not a 413
  # (nginx checks the size first).
  check "600 MiB restore before sign-in -> 401" 401 "$(declared POST /backup/restore $((600 * 1024 * 1024)))"
  # M-1420: a body over 64 KiB is refused with /upload/check's 401 before it is read. (Over 256 KiB, so Go doesn't
  # read the rest of an unread body to keep the connection; this request never sends one.)
  check "anonymous 300 KB POST /save -> 401 before the body" 401 "$(declared POST /save 300000)"
  check "anonymous chunked POST -> 401"  401 "$(code -H 'Transfer-Encoding: chunked' -X POST --data-binary 'name=x' "$base/locations")"
fi
check "upload check: not signed in"      401 "$(code "$base/upload/check")"
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
check "upload check: admin"              204 "$(code -b "$jar" "$base/upload/check")"
# over 520 MiB is refused after sign-in too, before the body is read (N-1424)
check "600 MiB restore after sign-in -> 413" 413 "$(declared POST /backup/restore $((600 * 1024 * 1024)) "$jar")"
if [[ $server == frankenphp ]]; then
  check "signed in, PUT /backup/restore over 22 MiB -> 413" 413 "$(declared PUT /backup/restore $((30 * 1024 * 1024)) "$jar")"
fi
curl -s -b "$jar" -o "$work/backup.html" "$base/backup"
tok=$(sed -n 's/.*name="csrf" content="\([a-f0-9]*\)".*/\1/p' "$work/backup.html")
tok=${tok%%$'\n'*}
# signed in: the 30 MB body reaches the app, which rejects the non-zip with 422 (not 401/413)
check "30 MB restore accepted after sign-in" 422 "$(code -b "$jar" -X POST -F "csrf=$tok" -F "backup=@$work/big" "$base/backup/restore")"
# signed in, every other route keeps the 22 MB cap: 413 before the app reads the body (the FrankenPHP image has the
# restore limits in PHP itself, so this cap is the only thing that stops a large body on these routes)
# Only POST on exactly /backup/restore gets 520 MiB (N-1425): not a trailing slash, another case or another path.
for p in /save /item/1/photos /item/1/attachments /settings/logo /settings/import /backup/restore/confirm /api/v1/session /no/such/page /backup/restore/ /Backup/restore /backup/RESTORE /backup/restore/check; do
  check "signed in, 30 MB POST $p -> 413" 413 "$(code -b "$jar" -H "X-CSRF-Token: $tok" -X POST -F "csrf=$tok" -F "file=@$work/big" "$base$p")"
done
# and only POST (N-1424): FrankenPHP answers 413 before reading; nginx's exact location reads it and the app has no
# such route (404). Never accepted.
for m in PUT PATCH; do
  r=$(code -b "$jar" -H "X-CSRF-Token: $tok" -X "$m" -F "csrf=$tok" -F "file=@$work/big" "$base/backup/restore")
  check "signed in, 30 MB $m /backup/restore refused" 1 "$([[ $r == 413 || $r == 404 || $r == 405 ]] && echo 1 || echo "$r")"
done
# A body without Content-Length (chunked, as a proxy such as the edge Worker may send it) has the same 22 MiB cap
# (L-1423): 30 MiB is a 413, while 21 MiB still reaches the app (which refuses the non-image with 4xx, never 413).
# Raising or removing the cap fails one of the two.
check "signed in, 30 MiB chunked POST /save -> 413" 413 "$(code -b "$jar" -H "X-CSRF-Token: $tok" -H 'Transfer-Encoding: chunked' -X POST -F "csrf=$tok" -F "photo=@$work/big" "$base/save")"
head -c $((21 * 1024 * 1024)) /dev/zero > "$work/21m"
r=$(code -b "$jar" -H "X-CSRF-Token: $tok" -H 'Transfer-Encoding: chunked' -X POST -F "csrf=$tok" -F "photo=@$work/21m" "$base/save")
check "signed in, 21 MiB chunked POST /save reaches the app" 1 "$([[ $r != 413 && $r == [2-4][0-9][0-9] ]] && echo 1 || echo "$r")"
exit $fail
