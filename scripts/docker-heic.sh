#!/usr/bin/env bash
# HEIC support of a built Apotheke image, Imagick colour handling, plus the php-fpm startup refusal.
#   scripts/docker-heic.sh [image]     (default apotheke:ci)
set -euo pipefail
image=${1:-apotheke:ci}
root=$(cd "$(dirname "$0")/.." && pwd)
name="apotheke-heic-refuse-$$"
body=/tmp/heic-refuse-body.txt
codef=/tmp/heic-refuse-code.txt
logf=/tmp/heic-refuse-log.txt

if ! docker info >/dev/null 2>&1; then
  echo "docker is not available" >&2
  exit 1
fi

port=$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1", 0)); print(s.getsockname()[1]); s.close()')

cleanup() { docker rm -f "$name" >/dev/null 2>&1 || true; }
trap cleanup EXIT

echo "== Imagick lists HEIC"
docker run --rm --entrypoint php "$image" -r 'if (!class_exists("Imagick")) { fwrite(STDERR, "Imagick missing\n"); exit(1); } $f = array_map("strtoupper", Imagick::queryFormats()); foreach (["HEIC", "HEIF"] as $want) { if (in_array($want, $f, true)) { echo "ok $want\n"; exit(0); } } fwrite(STDERR, "formats: ".implode(" ", $f)."\n"); exit(1);'

echo "== ImageMagick policy (limits, refused coders; S-M1, S-L1)"
docker run --rm --entrypoint php "$image" /usr/local/lib/apotheke/imagick-policy-check.php

echo "== HEIC upload round-trip"
docker run --rm --entrypoint php \
  -e DATA_DIR=/tmp/heic-data \
  -e SEED_DEFAULTS=0 \
  -e APOTHEKE_CONFIG=/nonexistent \
  -e TZ=UTC \
  -v "$root/tests/fixtures/tiny-gps.heic:/fixture.heic:ro" \
  -v "$root/tests/heic_image_check.php:/heic_image_check.php:ro" \
  "$image" /heic_image_check.php /fixture.heic

echo "== Imagick transparency on white, CMYK as sRGB"
docker run --rm --entrypoint php \
  -e DATA_DIR=/tmp/imagick-color \
  -e SEED_DEFAULTS=0 \
  -e APOTHEKE_CONFIG=/nonexistent \
  -e TZ=UTC \
  -v "$root/tests/imagick_color_check.php:/imagick_color_check.php:ro" \
  "$image" /imagick_color_check.php

echo "== php-fpm refusal hides the filesystem path"
docker run -d --name "$name" -p "127.0.0.1:${port}:9091" \
  -e ORIGINALS_DIR=/var/www/app/public/camera-private \
  "$image" >/dev/null

code=000
for _ in $(seq 1 40); do
  code=$(curl -s -o "$body" -w '%{http_code}' "http://127.0.0.1:${port}/" || true)
  printf '%s' "$code" > "$codef"
  if [[ $code == 500 ]]; then break; fi
  sleep 0.3
done
if [[ $code != 500 ]]; then
  echo "expected 500 from the refusal page, got $code" >&2
  docker logs "$name" > "$logf" 2>&1 || true
  cat "$logf" >&2 || true
  exit 1
fi
grep -q 'public web directory' "$body"
if grep -q '/var/www' "$body"; then
  echo "the refusal page includes a filesystem path" >&2
  exit 1
fi
sleep 0.5
docker logs "$name" > "$logf" 2>&1
grep -q '/var/www/app/public' "$logf"
if grep -q 'Undefined constant' "$logf"; then
  echo "php-fpm hit an undefined stream constant" >&2
  exit 1
fi
echo "ok refusal"
