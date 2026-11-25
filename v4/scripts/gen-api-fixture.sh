#!/bin/bash
# The /api/v1 session fixture (plan §5 S1.4): what v3's own four routes answer, captured over HTTP.
#
#     v4/scripts/gen-api-fixture.sh          # regenerate test/fixtures/php-api.json
#     v4/scripts/gen-api-fixture.sh --check  # hash check only (no PHP needed; this is what CI runs)
#
# Why a real server and not a hand-written table: this slice's contract is a *shape* (the six keys of
# `state()`, the four of `account()`), a *set of refusals* (status and text) and a *set of headers*.
# All three are cheap to get subtly wrong by reading, and none of them is wrong if v3 answered it.
# So the app boots on a temp data dir, `curl` drives the real routes, and the answers are stored as
# they came — status, the pinned headers, body bytes.
#
# State that changes what the routes answer (a pending account, sign-in off, guest browsing on, the
# registration switch) is written straight into the SQLite file by `api-fixture-state.php`, in the
# same format the settings page would store. That is the one place this script is not "over HTTP",
# and it is deliberate: driving the admin forms instead would make the fixture depend on a surface
# this slice does not own.
#
# Nothing is pinned that is not the API's contract: PHP's own `Expires`/`Pragma` (sent because
# `session_start()` runs) are dropped, and the two values the app cannot repeat — the CSRF token and
# the session id — are normalised to `<csrf>` and `<id>`, with the cookie expiry to `<date>`. The
# writer (`gen-api-fixture.mjs`) does that and records the SHA-256 of every source it read.
#
# Requirements: PHP 8.4 CLI with `intl` and `sqlite3` (v3's own driver), `curl`, `jq`.
# Point `PHP` at the binary when `php` is not on PATH.

set -euo pipefail

PHP=${PHP:-php}
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
OUT="$ROOT/v4/test/fixtures/php-api.json"
PORT=${PORT:-8093}
PASSWORD=${APP_PASSWORD:-fixture-password}

if [ "${1:-}" = "--check" ]; then
  exec node "$ROOT/v4/scripts/check-session-fixture.mjs" "$OUT" "api" "v4/scripts/gen-api-fixture.sh"
fi

for tool in "$PHP" curl jq; do
  command -v "$tool" >/dev/null || { echo "missing $tool" >&2; exit 1; }
done
"$PHP" -r 'exit(extension_loaded("intl") && extension_loaded("sqlite3") ? 0 : 1);' ||
  { echo "$PHP needs the intl and sqlite3 extensions" >&2; exit 1; }

# Every file an answer below is derived from. A change to any of them must regenerate the fixture;
# `--check` is what notices.
SOURCES=(
  "modules/Auth/Controllers/Api/SessionController.php"
  "modules/Auth/Controllers/AuthPages.php"
  "modules/Auth/Controllers/SignInController.php"
  "modules/Auth/Accounts.php"
  "modules/Auth/CurrentUser.php"
  "modules/Auth/PermissionCheck.php"
  "modules/Auth/Permissions.php"
  "modules/Auth/RateLimiter.php"
  "modules/Auth/SessionEpoch.php"
  "modules/Auth/SignInPace.php"
  "modules/Auth/UserRepository.php"
  "modules/Core/Filters/ApiCsrfFilter.php"
  "modules/Core/Filters/ApiFilter.php"
  "modules/Core/Filters/ConsentFilter.php"
  "modules/Core/Filters/SessionCookie.php"
  "modules/Core/Http/ApiCsrf.php"
  "modules/Core/Http/ApiResponse.php"
  "modules/Core/Http/ApiSecurityPolicy.php"
  "modules/Core/Http/CsrfCheck.php"
  "modules/Core/Http/EmptyBody.php"
  "modules/Core/Http/SessionGate.php"
  "modules/Core/Http/SessionToken.php"
  "modules/Core/Http/SameOrigin.php"
  "modules/Core/Libraries/Text.php"
  "modules/Settings/SettingsService.php"
  "app/Config/Filters.php"
  "app/Config/Routes.php"
  "docs/api/openapi.yaml"
)

CAP=$(mktemp -d /tmp/apotheke-api-fixture.XXXXXX)
DATA="$CAP/data"
JAR="$CAP/jar"
SERVER_LOG="$CAP/server.log"
cleanup() {
  if [ -n "${SERVER_PID:-}" ]; then
    kill "$SERVER_PID" 2>/dev/null || true
    wait "$SERVER_PID" 2>/dev/null || true
  fi
  # The captures are the fixture's raw material; keep them when the build fails.
  if [ "${KEEP_CAPTURE:-}" = "1" ]; then echo "capture kept: $CAP" >&2; else rm -rf "$CAP"; fi
}
trap cleanup EXIT

export DATA_DIR="$DATA" DB_DRIVER=sqlite APP_PASSWORD="$PASSWORD" TZ=Europe/Athens CI_ENVIRONMENT=production
mkdir -p "$DATA_DIR"
( cd "$ROOT" && "$PHP" spark apotheke:migrate >/dev/null && "$PHP" spark apotheke:seed >/dev/null )

( cd "$ROOT" && exec "$PHP" -S "127.0.0.1:$PORT" -t public public/router.php ) >"$SERVER_LOG" 2>&1 &
SERVER_PID=$!
for _ in $(seq 1 60); do
  curl -fsS -o /dev/null "http://127.0.0.1:$PORT/login" 2>/dev/null && break
  sleep 0.2
done

ORIGIN="http://127.0.0.1:$PORT"
STEPS="$CAP/steps.jsonl"
: >"$STEPS"
TOKEN=""

state() { "$PHP" "$ROOT/v4/scripts/api-fixture-state.php" "$@"; }

# The jar must always carry the consent cookie; curl's -c rewrites the file from what it knows.
ensure_consent() {
  if ! grep -q "apotheke_consent" "$JAR" 2>/dev/null; then
    printf '127.0.0.1\tFALSE\t/\tFALSE\t0\tapotheke_consent\tv1\n' >>"$JAR"
  fi
}

# Capture one answer, and record what was asked so a test can replay it.
#   step <name> <none|consent|session> <METHOD> <path> [--body <text>] [--ct <type>] [--csrf]
#        [--origin <own|evil|none>] [--setup <json>]
step() {
  local name=$1 uses=$2 method=$3 path=$4
  shift 4
  local body="" body_sent=false ct="" csrf=none origin=own setup="[]"
  while [ $# -gt 0 ]; do
    case "$1" in
      --body) body=$2; body_sent=true; shift 2 ;;
      --ct) ct=$2; shift 2 ;;
      --csrf) csrf=csrfsent; shift ;;
      --origin) origin=$2; shift 2 ;;
      --setup) setup=$2; shift 2 ;;
      *) echo "step: bad argument $1" >&2; exit 2 ;;
    esac
  done

  local args=(-sS -D "$CAP/$name.headers" -o "$CAP/$name.body" -X "$method")
  case "$uses" in
    none) ;;
    consent) ensure_consent; args+=(-b "apotheke_consent=v1" -c "$JAR") ;;
    session) ensure_consent; args+=(-b "$JAR" -c "$JAR") ;;
    *) echo "step: bad uses $uses" >&2; exit 2 ;;
  esac
  if [ "$body_sent" = true ]; then args+=(--data-raw "$body"); fi
  if [ -n "$ct" ]; then args+=(-H "Content-Type: $ct"); fi
  case "$origin" in
    own) args+=(-H "Origin: $ORIGIN") ;;
    evil) args+=(-H "Origin: https://evil.example") ;;
    none) ;;
  esac
  if [ "$csrf" = csrfsent ]; then
    if [ -z "$TOKEN" ]; then echo "step $name: no CSRF token to send" >&2; exit 2; fi
    args+=(-H "X-CSRF-Token: $TOKEN")
  fi

  curl "${args[@]}" "$ORIGIN$path"
  ensure_consent

  jq -cn \
    --arg name "$name" --arg uses "$uses" --arg method "$method" --arg path "$path" \
    --arg body "$body" --argjson bodySent "$body_sent" --arg ct "$ct" \
    --argjson csrf "$([ "$csrf" = csrfsent ] && echo true || echo false)" \
    --arg origin "$origin" --argjson setup "$setup" \
    '{name: $name, uses: $uses, setup: $setup,
      request: {method: $method, path: $path,
                body: (if $bodySent then $body else null end),
                contentType: (if $ct == "" then null else $ct end),
                csrf: $csrf, origin: (if $origin == "none" then null else $origin end)}}' >>"$STEPS"

  # A client reads the token from any state answer and uses it for the next write.
  local fresh
  fresh=$(jq -r 'try (.csrf_token // "") catch ""' "$CAP/$name.body" 2>/dev/null || echo "")
  if [ -n "$fresh" ]; then TOKEN=$fresh; fi
}

SETTINGS_BEFORE=$(state settings)
PACING=$(state pacing)

# --- A. no session, no consent ----------------------------------------------------------------
step session_noConsent none GET /api/v1/session
step session_guest consent GET /api/v1/session
step me_guest session GET /api/v1/me

# --- B. the body's refusals, on a valid session's token --------------------------------------
step session_invalidJson session POST /api/v1/session --csrf --ct application/json --body '{"username":'
step session_emptyObject session POST /api/v1/session --csrf --ct application/json --body '{}'
step session_notText session POST /api/v1/session --csrf --ct application/json --body '{"username":5,"password":null}'
step session_missingPassword session POST /api/v1/session --csrf --ct application/json --body '{"username":"admin"}'
step session_emptyBody session POST /api/v1/session --csrf --ct application/json --body ''

# --- C. the CSRF rule (ApiCsrf), one clause per answer ----------------------------------------
step session_noToken session POST /api/v1/session --ct application/json \
  --body '{"username":"admin","password":"'"$PASSWORD"'"}'
step session_wrongOrigin session POST /api/v1/session --csrf --ct application/json --origin evil \
  --body '{"username":"admin","password":"'"$PASSWORD"'"}'
step session_textPlain session POST /api/v1/session --csrf --ct text/plain --body 'x'

# --- D. a wrong name, a wrong password ---------------------------------------------------------
step session_unknownUser session POST /api/v1/session --csrf --ct application/json \
  --body '{"username":"nobody","password":"'"$PASSWORD"'"}'
step session_wrongPassword session POST /api/v1/session --csrf --ct application/json \
  --body '{"username":"admin","password":"nope-nope"}'

# --- E. an account waiting for approval --------------------------------------------------------
state user waiting viewer pending >/dev/null
step session_pending session POST /api/v1/session --csrf --ct application/json \
  --setup '[{"op":"user","username":"waiting","role":"viewer","status":"pending"}]' \
  --body '{"username":"waiting","password":"'"$PASSWORD"'"}'

# --- F. guest browsing on: the visitor is a role, and `me` is still refused ---------------------
state set auth_guest_disabled false >/dev/null
step session_guestBrowsing session GET /api/v1/session \
  --setup '[{"op":"set","name":"auth_guest_disabled","value":"false"}]'
step me_guestBrowsing session GET /api/v1/me
state set auth_guest_disabled true >/dev/null

# --- G. a viewer signs in, reads `me`, signs out ----------------------------------------------
state user viewer viewer active >/dev/null
step session_signInViewer session POST /api/v1/session --csrf --ct application/json \
  --setup '[{"op":"set","name":"auth_guest_disabled","value":"true"},{"op":"user","username":"viewer","role":"viewer","status":"active"}]' \
  --body '{"username":"viewer","password":"'"$PASSWORD"'"}'
step me_viewer session GET /api/v1/me
step session_deleteViewer session DELETE /api/v1/session --csrf
step session_afterDelete consent GET /api/v1/session

# --- H. the admin signs in --------------------------------------------------------------------
step session_signIn session POST /api/v1/session --csrf --ct application/json \
  --body '{"username":"admin","password":"'"$PASSWORD"'"}'
step me_signedIn session GET /api/v1/me

# --- I. registration open, then sign-in off ---------------------------------------------------
state set auth_register_disabled false >/dev/null
step session_registrationOpen session GET /api/v1/session \
  --setup '[{"op":"set","name":"auth_register_disabled","value":"false"}]'
state set auth_register_disabled true >/dev/null
state set auth_enabled false >/dev/null
step session_signInOff session GET /api/v1/session \
  --setup '[{"op":"set","name":"auth_register_disabled","value":"true"},{"op":"set","name":"auth_enabled","value":"false"}]'
step me_signInOff session GET /api/v1/me
step session_signInOffPost session POST /api/v1/session --csrf --ct application/json \
  --body '{"username":"admin","password":"'"$PASSWORD"'"}'
state set auth_enabled true >/dev/null

# --- J. the rate limit, then the session ends -------------------------------------------------
# Ten wrong attempts for one name are allowed; the eleventh is refused (RateLimiter::LIMITS['login']).
for _ in $(seq 1 10); do
  curl -sS -o /dev/null -b "$JAR" -H "Origin: $ORIGIN" -H "X-CSRF-Token: $TOKEN" \
    -H "Content-Type: application/json" -d '{"username":"lockme","password":"wrong-one"}' \
    "$ORIGIN/api/v1/session"
done
step session_rateLimited session POST /api/v1/session --csrf --ct application/json \
  --setup '[{"op":"set","name":"auth_enabled","value":"true"}]' \
  --body '{"username":"lockme","password":"wrong-one"}'
step session_delete session DELETE /api/v1/session --csrf

# The capture must leave the settings where it found them, or the next `setup` list would be a lie.
if [ "$(state settings | jq -S .)" != "$(echo "$SETTINGS_BEFORE" | jq -S .)" ]; then
  echo "the capture left a settings row changed:" >&2
  diff <(echo "$SETTINGS_BEFORE" | jq -S .) <(state settings | jq -S .) >&2 || true
  exit 1
fi

node "$ROOT/v4/scripts/gen-api-fixture.mjs" write \
  "$CAP" "$OUT" "$ROOT" "$("$PHP" -r 'echo PHP_VERSION;')" \
  "$SETTINGS_BEFORE" "$PACING" "$PASSWORD" "${SOURCES[@]}"
