#!/bin/bash
# The golden HTML fixtures (plan §5 S1.1): what the PHP app prints, captured once and committed.
#
#     v4/scripts/gen-html-fixtures.sh          # regenerate test/fixtures/php-html.json
#     v4/scripts/gen-html-fixtures.sh --check  # hash check only (no PHP needed; this is what CI runs)
#
# Why a PHP box and not CI: rendering these pages needs the v3 application to boot — CodeIgniter,
# an `intl` extension, a migrated and seeded SQLite database, and a working `php -S`. v4.yml does not
# carry that toolchain, so the fixture is generated here and the script is committed next to it.
# What CI does hold is the oracle's *sources*: every PHP file and asset the output depends on is
# hashed into the fixture, and `--check` (also `node scripts/check-fixtures.mjs`) fails when one of
# them changed without a regeneration. A golden test whose oracle nobody can regenerate rots
# silently; this one fails loudly.
#
# Reproducibility, and the one thing that is pinned: `modules/Core/Libraries/AssetUrl.php` derives a
# file's `?v=` cache key from `md5(mtime . size)`, and a checkout's mtimes differ on every machine.
# The script pins every bundled asset's mtime to 0 before rendering, so the key becomes a function of
# the committed bytes — `md5("0" . size)` — which is exactly what `scripts/gen-assets.mjs` computes
# for the Worker. The mtimes stay pinned; git does not track them.
#
# Requirements: PHP 8.4 CLI with `intl`, `pdo_sqlite` and `sqlite3`; `curl`. Point `PHP` at the binary.

set -euo pipefail

PHP=${PHP:-php}
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
OUT="$ROOT/v4/test/fixtures/php-html.json"
PORT=${PORT:-8098}

# Every file the fixture's output is derived from. A change to any of them must regenerate it.
SOURCES=(
  "modules/Layout/Page.php"
  "modules/Layout/Theme.php"
  "modules/Layout/Icons.php"
  "modules/Layout/ErrorPage.php"
  "modules/Auth/Views/AuthViews.php"
  "modules/Auth/Controllers/SignInController.php"
  "modules/Auth/Accounts.php"
  "modules/Core/Libraries/AssetUrl.php"
  "modules/Core/Filters/RouteMethodFilter.php"
  "modules/Core/Exceptions/PlainHandler.php"
  "modules/Settings/SettingsSchema.php"
  "app/Config/Consent.php"
  "public/assets/app.css"
  "public/assets/app.js"
  "public/assets/theme.js"
  "public/assets/icon.svg"
  "public/assets/favicon.svg"
  "public/assets/favicon.ico"
  "public/assets/apple-touch-icon.png"
  "public/assets/icon-32.png"
  "public/assets/icon-192.png"
  "public/assets/icon-512.png"
  "public/assets/icon-maskable-512.png"
  "public/assets/mark.svg"
)

hashes() {
  ( cd "$ROOT" && sha256sum "${SOURCES[@]}" )
}

if [ "${1:-}" = "--check" ]; then
  if [ ! -f "$OUT" ]; then
    echo "missing $OUT: run v4/scripts/gen-html-fixtures.sh on a box with PHP 8.4" >&2
    exit 1
  fi
  node "$ROOT/v4/scripts/fixtures.mjs" check "$OUT" "$ROOT"
  exit $?
fi

for tool in "$PHP" curl sha256sum; do
  command -v "$tool" >/dev/null || { echo "missing $tool" >&2; exit 1; }
done
"$PHP" -r 'exit(extension_loaded("intl") && extension_loaded("pdo_sqlite") ? 0 : 1);' ||
  { echo "$PHP needs the intl and pdo_sqlite extensions" >&2; exit 1; }

DATA=$(mktemp -d /tmp/apotheke-fixture.XXXXXX)
SERVER_LOG="$DATA/server.log"
cleanup() {
  if [ -n "${SERVER_PID:-}" ]; then
    kill "$SERVER_PID" 2>/dev/null || true
    wait "$SERVER_PID" 2>/dev/null || true
  fi
  rm -rf "$DATA"
}
trap cleanup EXIT

# The cache keys are a function of the committed bytes, not of when the checkout happened.
find "$ROOT/public/assets" -maxdepth 1 -type f -exec touch -d @0 {} +

export DATA_DIR="$DATA/data"
export DB_DRIVER=sqlite
export APP_PASSWORD=${APP_PASSWORD:-fixture-password}
export TZ=Europe/Athens
export CI_ENVIRONMENT=production
mkdir -p "$DATA_DIR"

( cd "$ROOT" && "$PHP" spark apotheke:migrate >/dev/null && "$PHP" spark apotheke:seed >/dev/null )

( cd "$ROOT" && exec "$PHP" -S "127.0.0.1:$PORT" -t public public/router.php ) >"$SERVER_LOG" 2>&1 &
SERVER_PID=$!
for _ in $(seq 1 50); do
  curl -fsS -o /dev/null "http://127.0.0.1:$PORT/login" 2>/dev/null && break
  sleep 0.2
done

capture() { # name method path
  curl -sS -X "$2" -D "$DATA/$1.headers" -o "$DATA/$1.html" "http://127.0.0.1:$PORT$3"
}

capture login GET /login
capture notFound GET /nope
capture methodNotAllowed POST /settings

node "$ROOT/v4/scripts/fixtures.mjs" write "$DATA" "$OUT" "$ROOT" "$("$PHP" -r 'echo PHP_VERSION;')" "${SOURCES[@]}"
