#!/usr/bin/env bash
# Web-server behaviour of the Docker image (nginx + php-fpm), on top of scripts/smoke.sh.
# Usage: scripts/docker-checks.sh [base-url]   (default http://127.0.0.1:8080)
set -euo pipefail
base=${1:-http://127.0.0.1:8080}
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
check "migrations/ blocked"           404 "$(code "$base/migrations/0001_initial_schema.php")"
check "config.php blocked"            404 "$(code "$base/config.php")"
check "dotfiles blocked"              404 "$(code "$base/.git/config")"
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

big=$(mktemp); trap 'rm -f "$big"' EXIT
head -c $((30 * 1024 * 1024)) /dev/zero > "$big"
check "30 MB body rejected on /save (22m limit)" 413 "$(code -X POST -F "photo=@$big" "$base/save")"
# /backup/restore accepts it at the HTTP layer (the app then rejects the CSRF/zip, but not with 413)
r=$(code -X POST -F "backup=@$big" "$base/backup/restore")
check "30 MB body accepted by /backup/restore" 1 "$([[ $r != 413 && $r != 000 ]] && echo 1 || echo "$r")"
exit $fail
