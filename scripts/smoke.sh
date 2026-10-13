#!/usr/bin/env bash
# Smoke-test a running Apotheke instance: front page 200, one photo upload.
# Usage: scripts/smoke.sh [base-url]   (default http://127.0.0.1:9091)
set -euo pipefail
base=${1:-http://127.0.0.1:9091}
jar=$(mktemp); img=$(mktemp --suffix=.jpg)
trap 'rm -f "$jar" "$img"' EXIT
for _ in $(seq 1 30); do curl -fsS "$base/healthz" >/dev/null 2>&1 && break; sleep 1; done
code=$(curl -s -o /tmp/apotheke-front.html -w '%{http_code}' -c "$jar" -b "$jar" "$base/")
[[ $code == 200 ]] || { echo "front page returned $code"; exit 1; }
grep -q 'Apotheke' /tmp/apotheke-front.html || { echo "front page lacks title"; exit 1; }
echo "ok front page 200"
token=$(grep -o 'name="csrf" value="[a-f0-9]*"' /tmp/apotheke-front.html | head -1 | grep -o '[a-f0-9]\{64\}' || true)
if [[ -z $token ]]; then
  token=$(curl -fsS -c "$jar" -b "$jar" "$base/new" | grep -o '[a-f0-9]\{64\}' | head -1)
fi
# shellcheck disable=SC2016
php -r '$i=imagecreatetruecolor(320,240); imagefill($i,0,0,imagecolorallocate($i,200,80,40)); imagejpeg($i,$argv[1]);' "$img"
code=$(curl -s -o /tmp/apotheke-save.txt -w '%{http_code}' -c "$jar" -b "$jar" \
  -F "csrf=$token" -F "name=Smoke test item" -F "quantity=1" -F "photo=@$img;type=image/jpeg" "$base/save")
[[ $code == 303 || $code == 200 ]] || { echo "upload returned $code"; cat /tmp/apotheke-save.txt; exit 1; }
curl -fsS -b "$jar" "$base/?q=Smoke" | grep -q 'Smoke test item' || { echo "uploaded item not listed"; exit 1; }
echo "ok upload ($code)"
