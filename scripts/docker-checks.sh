#!/usr/bin/env bash
# Web-server behaviour of the Docker images (Dockerfile: nginx + php-fpm; Dockerfile.frankenphp: FrankenPHP), on top
# of scripts/smoke.sh. Both images must pass it unchanged.
# Usage: scripts/docker-checks.sh [base-url]   (default http://127.0.0.1:9091)
set -euo pipefail
base=${1:-http://127.0.0.1:9091}
fail=0
check() { # name expected actual
  if [[ $2 == "$3" ]]; then echo "ok   $1"; else echo "FAIL $1: expected '$2', got '$3'"; fail=1; fi
}
code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
body=$(mktemp); big=$(mktemp); trap 'rm -f "$body" "$big"' EXIT
hdr() { curl -s -D - -o /dev/null "${@:2}" | tr -d '\r' | awk -v h="$1" 'BEGIN{IGNORECASE=1} index(tolower($0), tolower(h) ":")==1 {sub(/^[^:]*: */, ""); print; exit}'; }

check "front controller"              200 "$(code "$base/")"
check "HEAD / same as GET"            200 "$(code -I "$base/")"
check "healthz"                       200 "$(code "$base/healthz")"
check "unknown route -> app 404"      404 "$(code "$base/no/such/page")"
# /index.php is handled by the app's router (which has no such route), exactly as under Apache
check "/index.php reaches the app"    1 "$(curl -s "$base/index.php" | grep -c 'Back to catalog')"
# nginx answers 400, FrankenPHP 404; either way no file is served and nothing runs
r=$(curl -s -o "$body" -w '%{http_code}' "$base/assets/../../app/Config/App.php" --path-as-is)
check "path traversal never executes" 1 "$([[ $r == 400 || $r == 404 ]] && echo 1 || echo "$r")"
check "path traversal serves no file" 0 "$(grep -c -e '<?php' -e 'namespace Config' "$body" || true)"
check "originals/ blocked"            404 "$(code "$base/originals/guess.jpg")"
check "Originals/ blocked"            404 "$(code "$base/Originals/guess.jpg")"
check "ORIGINALS/ blocked"            404 "$(code "$base/ORIGINALS/guess.jpg")"
check "migrations/ blocked"           404 "$(code "$base/migrations/0001_initial_schema.php")"
check "config.php blocked"            404 "$(code "$base/config.php")"
check "dotfiles blocked"              404 "$(code "$base/.git/config")"
# The code, packages, writable dir, .env and spark sit outside public/ (PLAN step 15)
for p in vendor/autoload.php writable/logs/ .env spark app/Config/App.php modules/Core/Boot/startup.php; do
  check "/$p not served"  404 "$(code "$base/$p")"
done
check "random .php is 404"            404 "$(code "$base/foo.php")"
check "asset served"                  200 "$(code "$base/assets/app.css")"
check "asset cache-control"           "public, max-age=31536000, immutable" "$(hdr Cache-Control "$base/assets/app.css")"
check "asset gzip"                    gzip "$(hdr Content-Encoding -H 'Accept-Encoding: gzip' "$base/assets/app.js")"
check "html gzip"                     gzip "$(hdr Content-Encoding -H 'Accept-Encoding: gzip' "$base/")"
check "manifest type"                 application/manifest+json "$(hdr Content-Type "$base/manifest.webmanifest")"
check "CSP on pages"                  1 "$(hdr Content-Security-Policy "$base/" | grep -c "default-src 'self'")"
check "nosniff on pages"              nosniff "$(hdr X-Content-Type-Options "$base/")"
# nginx sends "Server: nginx" (server_tokens off), FrankenPHP none; never a version
check "no server version"             0 "$(hdr Server "$base/" | grep -c '[0-9]' || true)"
check "no X-Powered-By"               "" "$(hdr X-Powered-By "$base/")"
# Host allow-list (SEC-M3): addresses and localhost work with nothing configured, other names get 421
check "unknown Host refused"          421 "$(code -H 'Host: other.example' "$base/")"
check "localhost Host accepted"       200 "$(code -H 'Host: localhost' "$base/")"
check "IPv6 address Host accepted"    200 "$(code -H 'Host: [::1]:9091' "$base/")"
# sign-in is off in this container: a cross-site form post is refused, a plain one is not
check "cross-site POST refused"       403 "$(code -X POST -H 'Sec-Fetch-Site: cross-site' -d 'name=x' "$base/locations")"
check "plain POST reaches the app"    419 "$(code -b apotheke_consent=v1 -X POST -d 'name=x' "$base/locations")"
check "POST without cookie consent"   403 "$(code -X POST -d 'name=x' "$base/locations")"
check "  and it sets no cookie"       "" "$(hdr Set-Cookie -X POST -d 'name=x' "$base/locations")"
# sessions on demand (SEC-L2): no cookie for a 404 or static branding
check "404 sets no session cookie"    "" "$(hdr Set-Cookie "$base/no/such/page")"
check "favicon sets no cookie"        "" "$(hdr Set-Cookie "$base/favicon.ico")"
check "restore check (sign-in off)"   204 "$(code "$base/backup/restore/check")"
check "upload check (sign-in off)"    204 "$(code "$base/upload/check")"
check "restore check is not public"   404 "$(code "$base/_restore_check")"

head -c $((30 * 1024 * 1024)) /dev/zero > "$big"
check "30 MB body rejected on /save (22m limit)" 413 "$(code -X POST -F "photo=@$big" "$base/save")"
# /backup/restore accepts it at the HTTP layer (the app then rejects the CSRF/zip, but not with 413)
# (sign-in is off here, so the restore check lets it through; the app then refuses the missing CSRF token with 419)
check "30 MB body accepted by /backup/restore" 419 "$(code -b apotheke_consent=v1 -X POST -F "backup=@$big" "$base/backup/restore")"

# M-1454: HTTP/1.1 only (no cleartext HTTP/2, h2c), on both images: with prior knowledge the connection fails, and
# an upgrade request stays on HTTP/1.1 (an HTTP/2 body has no Transfer-Encoding, so it would skip the 411 and the
# sign-in check).
v=$(curl -s -o /dev/null -w '%{http_version} %{http_code}' --max-time 10 --http2-prior-knowledge "$base/healthz" || true)
check "no HTTP/2 with prior knowledge (h2c)" 1 "$([[ $v != 2* ]] && echo 1 || echo "$v")"
check "an h2c upgrade stays on HTTP/1.1" "1.1 200" "$(curl -s -o /dev/null -w '%{http_version} %{http_code}' --max-time 10 --http2 "$base/healthz" || true)"

# M-1420: slow uploads never take the server down. SLOW_UPLOADS (default 4, more than the FrankenPHP image's 2 PHP
# threads) clients send a 60 KB sign-in form at 1 KB/s; while they are in flight, /healthz, the sign-in page and a
# normal form post still answer within 2 s. nginx reads the body before php-fpm sees it; FrankenPHP reads bodies up to
# 64 KiB in Caddy before PHP does (larger ones only after sign-in, see docker-auth-checks.sh). The clients (and the
# chunked GETs below) carry the consent cookie, like a browser after Accept, so the app would act on them if it got
# them; the web server holds them either way.
slow=$(mktemp); { printf 'username=slow&password='; head -c 59000 /dev/zero | tr '\0' a; } > "$slow"
pids=()
for _ in $(seq 1 "${SLOW_UPLOADS:-4}"); do
  curl -s -o /dev/null --max-time 90 --limit-rate 1000 -b apotheke_consent=v1 --data-binary "@$slow" \
    -H 'Content-Type: application/x-www-form-urlencoded' "$base/login" & pids+=($!)
done
sleep 3
fast() { curl -s -o /dev/null -w '%{http_code} %{time_total}' --max-time 10 "$@" || true; }
within() { awk -v t="${1#* }" 'BEGIN { print (t < 2.0) ? "fast" : "slow " t }'; }
r=$(fast "$base/healthz");        check "healthz during ${#pids[@]} slow uploads"      "200 fast" "${r%% *} $(within "$r")"
r=$(fast "$base/login");          check "sign-in page during slow uploads"            "fast" "$(within "$r")"
r=$(fast -b apotheke_consent=v1 -X POST -d 'name=x' "$base/locations"); check "small POST during slow uploads" "419 fast" "${r%% *} $(within "$r")"
alive=0; for p in "${pids[@]}"; do kill -0 "$p" 2>/dev/null && alive=$((alive + 1)); done
check "the slow uploads were still in flight" "${#pids[@]}" "$alive"
kill "${pids[@]}" 2>/dev/null || true; wait 2>/dev/null || true; rm -f "$slow"

# M-1451: the same with slow chunked GETs (no declared length; 70,000 bytes at once, more than Caddy's 64 KiB
# buffer, then a byte a second). Neither image lets them hold a PHP thread: nginx reads the body itself, FrankenPHP
# answers 411 before reading it.
python3 - "$base" "${SLOW_UPLOADS:-4}" <<'PY' & slowget=$!
import socket, sys, threading, time, urllib.parse
u = urllib.parse.urlsplit(sys.argv[1])
def one():
    try:
        s = socket.create_connection((u.hostname, u.port), timeout=30)
        s.sendall(f"GET /login HTTP/1.1\r\nHost: {u.hostname}:{u.port}\r\nTransfer-Encoding: chunked\r\n"
                  "Cookie: apotheke_consent=v1\r\n"
                  "Content-Type: application/x-www-form-urlencoded\r\n\r\n".encode())
        s.sendall(b"%x\r\n" % 70000 + b"a" * 70000 + b"\r\n")
        for _ in range(30):
            time.sleep(1)
            s.sendall(b"1\r\na\r\n")
    except OSError:
        pass
for _ in range(int(sys.argv[2])):
    threading.Thread(target=one, daemon=True).start()
time.sleep(30)
PY
sleep 3
r=$(fast "$base/healthz");        check "healthz during slow chunked GETs"            "200 fast" "${r%% *} $(within "$r")"
r=$(fast "$base/login");          check "sign-in page during slow chunked GETs"       "fast" "$(within "$r")"
r=$(fast -b apotheke_consent=v1 -X POST -d 'name=x' "$base/locations"); check "small POST during slow chunked GETs" "419 fast" "${r%% *} $(within "$r")"
kill "$slowget" 2>/dev/null || true; wait 2>/dev/null || true
exit $fail
