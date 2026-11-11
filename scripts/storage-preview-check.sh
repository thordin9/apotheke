#!/usr/bin/env bash
# Check that an item and its photo survive a container sleep on a storage-mode
# preview (STORAGE, DB and INSTALLATION bound). Signs in through /api/v1/session,
# saves an item with a photo through POST /save, waits past SLEEP_AFTER_MS (or
# --sleep-seconds), cold-starts with /healthz, and compares the sha256 of the
# downloaded photo. Does not deploy and does not call the Cloudflare API.
# Usage: scripts/storage-preview-check.sh --help
#        See docs/storage-preview-check.md
set -euo pipefail

ROOT=$(cd "$(dirname "$0")/.." && pwd)

usage() {
  cat <<'EOF'
Check that an item and its photo survive a container sleep on a storage-mode preview.

The preview must already have STORAGE (R2), DB (D1) and INSTALLATION bound.
This script does not deploy anything and does not call the Cloudflare API.

Usage:
  BASE_URL=https://<preview-host> ADMIN_PASSWORD=<password> scripts/storage-preview-check.sh

Inputs (env or flags; flags win):
  BASE_URL              Preview origin, no trailing slash  (required, or --base-url)
  ADMIN_USER            Sign-in name                        (default: admin, or --user)
  ADMIN_PASSWORD        Sign-in password                    (required; env only,
                        so it stays out of argv and shell history)
  SLEEP_WAIT_SECONDS    Seconds to wait after the save      (optional, or --sleep-seconds)
                        Default: SLEEP_AFTER_MS from
                        edge/wrangler.jsonc, in seconds, plus 30.
                        0 skips the wait (local dry run).

  -h, --help            Show this help and exit 0

Needs curl, jq and sha256sum (or shasum -a 256). Exits non-zero on failure.
Deletes the test item at the end, best effort.
EOF
}

BASE_URL=${BASE_URL:-}
ADMIN_USER=${ADMIN_USER:-admin}
ADMIN_PASSWORD=${ADMIN_PASSWORD:-}
SLEEP_WAIT_SECONDS=${SLEEP_WAIT_SECONDS-}

while [[ $# -gt 0 ]]; do
  case $1 in
    -h|--help)
      usage
      exit 0
      ;;
    --base-url)
      BASE_URL=${2:?--base-url needs a value}
      shift 2
      ;;
    --user)
      ADMIN_USER=${2:?--user needs a value}
      shift 2
      ;;
    --sleep-seconds)
      SLEEP_WAIT_SECONDS=${2:?--sleep-seconds needs a value}
      shift 2
      ;;
    *)
      echo "unknown option: $1" >&2
      usage >&2
      exit 2
      ;;
  esac
done

die() { echo "error: $*" >&2; exit 1; }

need() {
  command -v "$1" >/dev/null 2>&1 || die "needed but not found: $1"
}

need curl
need jq
if command -v sha256sum >/dev/null 2>&1; then
  sha256_file() { sha256sum "$1" | awk '{print $1}'; }
elif command -v shasum >/dev/null 2>&1; then
  sha256_file() { shasum -a 256 "$1" | awk '{print $1}'; }
else
  die "needed but not found: sha256sum or shasum"
fi

[[ -n $BASE_URL ]] || die "BASE_URL or --base-url is required (https://<preview-host>)"
[[ -n $ADMIN_PASSWORD ]] || die "ADMIN_PASSWORD is required (env only)"
[[ $ADMIN_USER == *[![:space:]]* ]] || die "ADMIN_USER is empty"

BASE_URL=${BASE_URL%/}
if [[ $BASE_URL =~ ^(https?)://([^/]+) ]]; then
  scheme=${BASH_REMATCH[1]}
  hostport=${BASH_REMATCH[2]}
else
  die "BASE_URL must be an http(s) origin, for example https://<preview-host>"
fi
origin="${scheme}://${hostport}"
host=${hostport%%:*}
secure=FALSE
[[ $scheme == https ]] && secure=TRUE

default_sleep_seconds() {
  local ms=900000
  local f=$ROOT/edge/wrangler.jsonc
  if [[ -f $f ]]; then
    local parsed
    parsed=$(sed -n 's/.*"SLEEP_AFTER_MS"[[:space:]]*:[[:space:]]*"\([0-9][0-9]*\)".*/\1/p' "$f" | head -1)
    if [[ -n $parsed ]]; then
      ms=$parsed
    fi
  fi
  echo $((ms / 1000 + 30))
}

if [[ -z $SLEEP_WAIT_SECONDS ]]; then
  SLEEP_WAIT_SECONDS=$(default_sleep_seconds)
fi
[[ $SLEEP_WAIT_SECONDS =~ ^[0-9]+$ ]] || die "SLEEP_WAIT_SECONDS must be a non-negative integer"

WORKDIR=$(mktemp -d "${TMPDIR:-/tmp}/storage-preview-check.XXXXXX")
jar=$WORKDIR/jar
body=$WORKDIR/body
headers=$WORKDIR/headers
photo=$WORKDIR/photo.png
downloaded=$WORKDIR/downloaded.jpg
ITEM_ID=
PHOTO_PATH=
PHOTO_SHA=
CSRF=
NAME="storage-preview-check $(date -u +%Y%m%dT%H%M%SZ)"

cleanup() {
  # Best-effort: never use die() here, and do not change the script's exit status.
  set +e
  if [[ -n ${ITEM_ID:-} ]]; then
    if delete_item; then
      echo "ok deleted item $ITEM_ID"
    else
      echo "warning: could not delete test item $ITEM_ID ($NAME); delete it by hand" >&2
    fi
  fi
  rm -rf "${WORKDIR:-}"
  set -e
}
trap cleanup EXIT

# Netscape cookie line: host, subdomains, path, secure, expiry, name, value (tabs).
printf '%s\tFALSE\t/\t%s\t0\tapotheke_consent\tv1\n' "$host" "$secure" > "$jar"

# Tiny 16x16 PNG, written at runtime (not a binary in the repo).
base64 --decode > "$photo" <<'EOF'
iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAIAAACQkWg2AAAAJklEQVR42mPkEpHbZ8PFQDRgIkk1AwMDEwOJYFTDqIaB0ZDwSAQA754DuhC7q00AAAAASUVORK5CYII=
EOF
[[ -s $photo ]] || die "failed to write the test photo"

http_code() {
  # $1 = output file. Remaining args go to curl. Prints the status code.
  local out=$1
  shift
  curl -sS --max-time 60 -D "$headers" -o "$out" -w '%{http_code}' -c "$jar" -b "$jar" "$@"
}

wait_healthz() {
  local label=$1
  local code start
  start=$(date +%s)
  # Retry for about 2 minutes in total, whatever each request takes.
  while (( $(date +%s) - start < 120 )); do
    code=$(curl -sS --max-time 15 -o "$WORKDIR/healthz" -w '%{http_code}' "$BASE_URL/healthz" || true)
    if [[ $code == 200 ]]; then
      echo "ok $label /healthz 200 after $(( $(date +%s) - start ))s"
      return 0
    fi
    sleep 5
  done
  die "$label /healthz did not return 200 (last HTTP ${code:-none}); waited ~2 min"
}

read_csrf() {
  local raw
  raw=$(jq -r '.csrf_token // empty' "$body")
  [[ -n $raw && $raw != null ]] || die "GET /api/v1/session returned no csrf_token (consent cookie missing, or the body was not session JSON)"
  CSRF=$raw
}

sign_in() {
  local code signed
  code=$(http_code "$body" "$BASE_URL/api/v1/session")
  [[ $code == 200 ]] || die "GET /api/v1/session returned $code: $(head -c 400 "$body")"
  read_csrf
  jq -n --arg username "$ADMIN_USER" --arg password "$ADMIN_PASSWORD" \
    '{username:$username,password:$password}' > "$WORKDIR/login.json"
  code=$(http_code "$body" -H "Origin: $origin" -H "X-CSRF-Token: $CSRF" \
    -H 'Content-Type: application/json' --data-binary @"$WORKDIR/login.json" \
    "$BASE_URL/api/v1/session")
  rm -f "$WORKDIR/login.json"
  if [[ $code != 200 ]]; then
    die "POST /api/v1/session returned $code: $(jq -rc '{status,code,detail,error}' "$body" 2>/dev/null || head -c 400 "$body")"
  fi
  signed=$(jq -r '.signed_in // false' "$body")
  [[ $signed == true ]] || die "POST /api/v1/session did not sign in: $(head -c 400 "$body")"
  read_csrf
  echo "ok signed in as $ADMIN_USER"
}

delete_item() {
  [[ -n $ITEM_ID ]] || return 0
  local code token
  code=$(http_code "$body" "$BASE_URL/api/v1/session") || return 1
  [[ $code == 200 ]] || return 1
  token=$(jq -r '.csrf_token // empty' "$body")
  [[ -n $token && $token != null ]] || return 1
  jq -n --arg username "$ADMIN_USER" --arg password "$ADMIN_PASSWORD" \
    '{username:$username,password:$password}' > "$WORKDIR/login.json"
  code=$(http_code "$body" -H "Origin: $origin" -H "X-CSRF-Token: $token" \
    -H 'Content-Type: application/json' --data-binary @"$WORKDIR/login.json" \
    "$BASE_URL/api/v1/session") || { rm -f "$WORKDIR/login.json"; return 1; }
  rm -f "$WORKDIR/login.json"
  [[ $code == 200 ]] || return 1
  token=$(jq -r '.csrf_token // empty' "$body")
  [[ -n $token && $token != null ]] || return 1
  code=$(http_code "$body" -H "Origin: $origin" -H "X-CSRF-Token: $token" \
    -H 'X-Requested-With: fetch' -F "csrf=$token" \
    "$BASE_URL/item/$ITEM_ID/delete") || return 1
  [[ $code == 200 || $code == 303 ]]
}

echo "base $origin"
wait_healthz start
sign_in

code=$(http_code "$body" -H "Origin: $origin" -H "X-CSRF-Token: $CSRF" \
  -H 'X-Requested-With: fetch' \
  -F "csrf=$CSRF" -F "name=$NAME" -F "quantity=1" \
  -F "photo=@$photo;type=image/png;filename=preview-check.png" \
  "$BASE_URL/save")
if [[ $code != 200 && $code != 303 ]]; then
  die "POST /save returned $code: $(head -c 800 "$body")"
fi

if [[ $code == 200 ]]; then
  loc=$(jq -r '.redirect // empty' "$body")
else
  loc=$(sed -n 's/^[Ll]ocation:[[:space:]]*//p' "$headers" | tr -d '\r' | tail -1)
fi
[[ -n $loc ]] || die "POST /save did not return a redirect (HTTP $code)"
if [[ $loc =~ saved=([0-9]+) ]]; then
  ITEM_ID=${BASH_REMATCH[1]}
elif [[ $loc =~ /item/([0-9]+) ]]; then
  ITEM_ID=${BASH_REMATCH[1]}
else
  die "could not parse item id from redirect: $loc"
fi
echo "ok created item $ITEM_ID ($NAME)"

code=$(http_code "$body" "$BASE_URL/item/$ITEM_ID")
[[ $code == 200 ]] || die "GET /item/$ITEM_ID returned $code after save"
grep -F -q "$NAME" "$body" || die "item page did not contain the item name"
PHOTO_PATH=$(grep -oE '/media/full/[a-f0-9]{32}\.jpg' "$body" | head -1 || true)
[[ -n $PHOTO_PATH ]] || die "item page has no /media/full/<32hex>.jpg photo"

code=$(http_code "$downloaded" "$BASE_URL$PHOTO_PATH")
[[ $code == 200 ]] || die "GET $PHOTO_PATH returned $code after save"
[[ -s $downloaded ]] || die "downloaded photo is empty"
PHOTO_SHA=$(sha256_file "$downloaded")
echo "ok photo $PHOTO_PATH sha256 $PHOTO_SHA"

if [[ $SLEEP_WAIT_SECONDS -eq 0 ]]; then
  echo "skip wait (SLEEP_WAIT_SECONDS=0)"
else
  echo "waiting ${SLEEP_WAIT_SECONDS}s for the container inactivity timeout (SLEEP_AFTER_MS plus a short buffer; no Cloudflare API call)…"
  sleep "$SLEEP_WAIT_SECONDS"
fi

wait_healthz 'cold-start'
sign_in

code=$(http_code "$body" "$BASE_URL/item/$ITEM_ID")
[[ $code == 200 ]] || die "GET /item/$ITEM_ID returned $code after the cold start (item did not survive)"
grep -F -q "$NAME" "$body" || die "item page after the cold start did not contain the item name"
echo "ok item $ITEM_ID survived"

rm -f "$downloaded"
code=$(http_code "$downloaded" "$BASE_URL$PHOTO_PATH")
[[ $code == 200 ]] || die "GET $PHOTO_PATH returned $code after the cold start (photo did not survive)"
[[ -s $downloaded ]] || die "downloaded photo is empty after the cold start"
after=$(sha256_file "$downloaded")
if [[ $after != "$PHOTO_SHA" ]]; then
  die "photo sha256 changed after the cold start (before $PHOTO_SHA, after $after)"
fi
echo "ok photo bytes survived (sha256 $after)"
echo "ok storage-mode preview persistence check"
