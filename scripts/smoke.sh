#!/usr/bin/env bash
# Smoke-test a running Apotheke instance: front page 200, one photo upload.
# Usage: scripts/smoke.sh [base-url]   (default http://127.0.0.1:9091)
set -euo pipefail
base=${1:-http://127.0.0.1:9091}
jar=$(mktemp); img=$(mktemp --suffix=.jpg)
page=$(mktemp)
trap 'rm -f "$jar" "$img" "$page"' EXIT
# Cookie consent (docs/deployment-and-operations.md): the jar starts with the banner accepted, as a browser after Accept.
printf '%s\tFALSE\t/\tFALSE\t0\tapotheke_consent\tv1\n' "$(sed -E 's#^[a-z]+://([^/:]+).*#\1#' <<<"$base")" > "$jar"
for _ in $(seq 1 30); do curl -fsS "$base/healthz" >/dev/null 2>&1 && break; sleep 1; done
code=$(curl -s -o /tmp/apotheke-front.html -w '%{http_code}' -c "$jar" -b "$jar" "$base/")
[[ $code == 200 ]] || { echo "front page returned $code"; exit 1; }
grep -q 'Apotheke' /tmp/apotheke-front.html || { echo "front page lacks title"; exit 1; }
echo "ok front page 200"
token=$(grep -o 'name="csrf" value="[a-f0-9]*"' /tmp/apotheke-front.html | head -1 | grep -o '[a-f0-9]\{64\}' || true)
if [[ -z $token ]]; then
  curl -fsS -c "$jar" -b "$jar" -o "$page" "$base/new"
  token=$(grep -o '[a-f0-9]\{64\}' "$page" | head -1 || true)
fi
# shellcheck disable=SC2016
php -r '$i=imagecreatetruecolor(320,240); imagefill($i,0,0,imagecolorallocate($i,200,80,40)); imagejpeg($i,$argv[1]);' "$img"
code=$(curl -s -o /tmp/apotheke-save.txt -w '%{http_code}' -c "$jar" -b "$jar" \
  -F "csrf=$token" -F "name=Smoke test item" -F "quantity=1" -F "photo=@$img;type=image/jpeg" "$base/save")
[[ $code == 303 || $code == 200 ]] || { echo "upload returned $code"; cat /tmp/apotheke-save.txt; exit 1; }
# save the page first: `curl | grep -q` fails under pipefail (grep quits early, curl gets SIGPIPE / exit 23)
curl -fsS -b "$jar" -o "$page" "$base/?q=Smoke"
grep -q 'Smoke test item' "$page" || { echo "uploaded item not listed"; exit 1; }
echo "ok upload ($code)"
