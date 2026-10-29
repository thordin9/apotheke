#!/usr/bin/env bash
# Web-server behaviour of the Docker image (nginx + php-fpm), on top of scripts/smoke.sh.
# Usage: scripts/docker-checks.sh [base-url]   (default http://127.0.0.1:9091)
# APOTHEKE_APP=ci4: the container runs CI4; the same checks apply (its backup pages, PLAN step 14, N-431).
set -euo pipefail
base=${1:-http://127.0.0.1:9091}
fail=0
check() { # name expected actual
  if [[ $2 == "$3" ]]; then echo "ok   $1"; else echo "FAIL $1: expected '$2', got '$3'"; fail=1; fi
}
code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
hdr() { curl -s -D - -o /dev/null "${@:2}" | tr -d '\r' | awk -v h="$1" 'BEGIN{IGNORECASE=1} index(tolower($0), tolower(h) ":")==1 {sub(/^[^:]*: */, ""); print; exit}'; }

check "front controller"              200 "$(code "$base/")"
check "HEAD / same as GET"            200 "$(code -I "$base/")"
check "healthz"                       200 "$(code "$base/healthz")"
check "unknown route -> app 404"      404 "$(code "$base/no/such/page")"
# /index.php is handled by the app's router (which has no such route), exactly as under Apache
check "/index.php reaches the app"    1 "$(curl -s "$base/index.php" | grep -c 'Back to catalog')"
r=$(code "$base/assets/../../src/bootstrap.php" --path-as-is)
check "path traversal never executes" 1 "$([[ $r == 400 || $r == 404 ]] && echo 1 || echo "$r")"
check "src/ blocked"                  404 "$(code "$base/src/bootstrap.php")"
check "originals/ blocked"            404 "$(code "$base/originals/guess.jpg")"
check "Originals/ blocked"            404 "$(code "$base/Originals/guess.jpg")"
check "ORIGINALS/ blocked"            404 "$(code "$base/ORIGINALS/guess.jpg")"
check "migrations/ blocked"           404 "$(code "$base/migrations/0001_initial_schema.php")"
check "config.php blocked"            404 "$(code "$base/config.php")"
check "dotfiles blocked"              404 "$(code "$base/.git/config")"
# CI4's code, packages, writable dir, .env and spark sit outside its public dir (PLAN step 15)
for p in vendor/autoload.php vendor/autoload.php writable/logs/ writable/logs/ .env .env spark spark app/Config/App.php app/Config/App.php; do
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
check "no server version"             nginx "$(hdr Server "$base/")"
check "no X-Powered-By"               "" "$(hdr X-Powered-By "$base/")"
# Host allow-list (SEC-M3): addresses and localhost work with nothing configured, other names get 421
check "unknown Host refused"          421 "$(code -H 'Host: other.example' "$base/")"
check "localhost Host accepted"       200 "$(code -H 'Host: localhost' "$base/")"
check "IPv6 address Host accepted"    200 "$(code -H 'Host: [::1]:9091' "$base/")"
# sign-in is off in this container: a cross-site form post is refused, a plain one is not
check "cross-site POST refused"       403 "$(code -X POST -H 'Sec-Fetch-Site: cross-site' -d 'name=x' "$base/locations")"
check "plain POST reaches the app"    419 "$(code -X POST -d 'name=x' "$base/locations")"
# sessions on demand (SEC-L2): no cookie for a 404 or static branding
check "404 sets no session cookie"    "" "$(hdr Set-Cookie "$base/no/such/page")"
check "favicon sets no cookie"        "" "$(hdr Set-Cookie "$base/favicon.ico")"
check "restore check (sign-in off)"   204 "$(code "$base/backup/restore/check")"
check "restore check is not public"   404 "$(code "$base/_restore_check")"

big=$(mktemp); trap 'rm -f "$big"' EXIT
head -c $((30 * 1024 * 1024)) /dev/zero > "$big"
check "30 MB body rejected on /save (22m limit)" 413 "$(code -X POST -F "photo=@$big" "$base/save")"
# /backup/restore accepts it at the HTTP layer (the app then rejects the CSRF/zip, but not with 413)
# (sign-in is off here, so the restore check lets it through; the app then refuses the missing CSRF token with 419)
check "30 MB body accepted by /backup/restore" 419 "$(code -X POST -F "backup=@$big" "$base/backup/restore")"
exit $fail
