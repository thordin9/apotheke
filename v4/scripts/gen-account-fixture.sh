#!/bin/bash
# The /account fixture (plan §5 S1.5): what v3's own page and its two POSTs answer, captured over HTTP.
#
#     v4/scripts/gen-account-fixture.sh          # regenerate test/fixtures/php-account.json
#     v4/scripts/gen-account-fixture.sh --check  # hash check only (no PHP needed; this is what CI runs)
#
# Why a real server and not a view stub: `/account` is the first page in this port whose document
# includes the *shell's* header and menu, and whose refusals re-render that whole document. A stub
# would prove the section matches and say nothing about the bar above it — and the bar is where the
# account's own role and permissions show. So the app boots on a temp data dir, a cookie jar is a
# browser, and the answers are stored as they came.
#
# Two sessions of the same viewer are kept: `POST /account/signout` has to leave *this* device signed
# in and end the other one, and that is only observable with two jars.
#
# Nothing is pinned that is not the page's contract: the CSRF token (every form field, the meta tag
# and the response bodies) becomes `<csrf>`, and the session cookie's value and expiry become `<id>`
# and `<date>`. The writer (`gen-account-fixture.mjs`) does that and records the SHA-256 of every
# source it read.
#
# Requirements: PHP 8.4 CLI with `intl` and `sqlite3` (v3's own driver), `curl`, `jq`.
# Point `PHP` at the binary when `php` is not on PATH.

set -euo pipefail

PHP=${PHP:-php}
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
OUT="$ROOT/v4/test/fixtures/php-account.json"
PORT=${PORT:-8094}
PASSWORD=${APP_PASSWORD:-fixture-password}
NEW_PASSWORD="fixture-changed"

if [ "${1:-}" = "--check" ]; then
  exec node "$ROOT/v4/scripts/check-session-fixture.mjs" "$OUT" "account" "v4/scripts/gen-account-fixture.sh"
fi

for tool in "$PHP" curl jq; do
  command -v "$tool" >/dev/null || { echo "missing $tool" >&2; exit 1; }
done
"$PHP" -r 'exit(extension_loaded("intl") && extension_loaded("sqlite3") ? 0 : 1);' ||
  { echo "$PHP needs the intl and sqlite3 extensions" >&2; exit 1; }

# Every file an answer below is derived from. A change to any of them must regenerate the fixture;
# `--check` is what notices.
SOURCES=(
  "modules/Auth/Controllers/AccountController.php"
  "modules/Auth/Controllers/AuthPages.php"
  "modules/Auth/Accounts.php"
  "modules/Auth/SignOutEverywhere.php"
  "modules/Auth/Views/AuthViews.php"
  "modules/Layout/Page.php"
  "modules/Layout/Viewer.php"
  "modules/Layout/Icons.php"
  "modules/Auth/Permissions.php"
  "modules/Auth/PermissionCheck.php"
  "modules/Auth/CurrentUser.php"
  "modules/Auth/RateLimiter.php"
  "modules/Auth/SignInPace.php"
  "modules/Auth/UserRepository.php"
  "modules/Auth/RoleRepository.php"
  "modules/Core/Http/CsrfCheck.php"
  "modules/Core/Http/SessionGate.php"
  "modules/Core/Filters/CsrfFilter.php"
  "modules/Auth/Filters/PermissionFilter.php"
  "app/Config/Routes.php"
)

CAP=$(mktemp -d /tmp/apotheke-account-fixture.XXXXXX)
DATA="$CAP/data"
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
STATE=""

state() { "$PHP" "$ROOT/v4/scripts/api-fixture-state.php" "$@"; }

# An account whose password is APP_PASSWORD, as the seed leaves an operator's install.
state user admin admin active >/dev/null
state user viewer viewer active >/dev/null
SETTINGS_BEFORE=$(state settings)

# Every jar carries the consent cookie; curl's -c rewrites the file from what it knows.
CONSENT_COOKIE=apotheke_consent
jar_file() { echo "$CAP/jar.$1"; }
ensure_consent() {
  local jar
  jar=$(jar_file "$1")
  grep -q "$CONSENT_COOKIE" "$jar" 2>/dev/null ||
    printf '127.0.0.1\tFALSE\t/\tFALSE\t0\t%s\tv1\n' "$CONSENT_COOKIE" >>"$jar"
}
for name in a b c d e; do ensure_consent "$name"; done

# Capture one answer, and record what was asked so a test can replay it.
#   step <name> <none|a|b|c> <METHOD> <path> [--body <text>] [--csrf <jar>]
#        [--setup <json>]
step() {
  local name=$1 jar=$2 method=$3 path=$4
  shift 4
  local body="" body_sent=false csrf="" setup="[]"
  while [ $# -gt 0 ]; do
    case "$1" in
      --body) body=$2; body_sent=true; shift 2 ;;
      --csrf) csrf=$2; shift 2 ;;
      --setup) setup=$2; shift 2 ;;
      *) echo "step: bad argument $1" >&2; exit 2 ;;
    esac
  done

  local args=(-sS -D "$CAP/$name.headers" -o "$CAP/$name.body" -X "$method")
  case "$jar" in
    none) ;;
    a|b|c|d|e) args+=(-b "$(jar_file "$jar")" -c "$(jar_file "$jar")") ;;
    *) echo "step: bad jar $jar" >&2; exit 2 ;;
  esac
  if [ -n "$csrf" ]; then
    local tok
    eval "tok=\${TOKEN_$csrf:-}"
    if [ -z "$tok" ]; then echo "step $name: no CSRF token for jar $csrf" >&2; exit 2; fi
    body="csrf=$tok${body:+&$body}"
    body_sent=true
  fi
  if [ "$body_sent" = true ]; then args+=(--data-raw "$body"); fi

  local status
  status=$(curl "${args[@]}" -w '%{http_code}' "$ORIGIN$path")
  echo "$status" >"$CAP/$name.status"
  if [ "$jar" != none ]; then ensure_consent "$jar"; fi

  jq -cn \
    --arg name "$name" --arg jar "$jar" --arg method "$method" --arg path "$path" \
    --arg body "$body" --argjson bodySent "$body_sent" --argjson setup "$setup" \
    '{name: $name, jar: $jar, setup: $setup,
      request: {method: $method, path: $path, body: (if $bodySent then $body else null end)}}' \
    >>"$STEPS"

  # A client keeps the token the page it was served carries, which is what a browser's form sends.
  local fresh
  fresh=$(grep -o 'name="csrf" content="[0-9a-f]*"' "$CAP/$name.body" 2>/dev/null |
    head -1 | sed 's/.*content="//;s/"$//' || true)
  if [ -n "$fresh" ]; then
    case "$jar" in
      a) TOKEN_a=$fresh ;;
      b) TOKEN_b=$fresh ;;
      c) TOKEN_c=$fresh ;;
      d) TOKEN_d=$fresh ;;
      e) TOKEN_e=$fresh ;;
    esac
  fi
}

TOKEN_a=""
TOKEN_b=""
TOKEN_c=""
TOKEN_d=""
TOKEN_e=""

# --- A. a guest: the route's `self` permission refuses before the controller ---------------------
# The controller's own guest branch cannot answer these: PermissionFilter answers first (proven by
# these two steps, which are v3's actual answers to a guest).
step guest_noSession none GET /account
step guest_consent a GET /account
# Guest browsing on: the visitor is a role, still not an account, and the route still refuses.
state set auth_guest_disabled false >/dev/null
step guest_guestBrowsing a GET /account \
  --setup '[{"op":"set","name":"auth_guest_disabled","value":"false"}]'
state set auth_guest_disabled true >/dev/null

# --- B. the viewer signs in, in two sessions ----------------------------------------------------
step login_a a GET /login \
  --setup '[{"op":"set","name":"auth_guest_disabled","value":"true"}]' 
step login_a_post a POST /login --body "username=viewer&password=$PASSWORD" --csrf a
step login_b b GET /login
step login_b_post b POST /login --body "username=viewer&password=$PASSWORD" --csrf b

# --- C. the page, its two toasts, and the two refusals that re-render it ------------------------
step viewer_page a GET /account
step viewer_donePw a GET /account?done=pw
step viewer_doneOut a GET /account?done=out
step viewer_wrongCurrent a POST /account \
  --body "current=wrong-one&new=$NEW_PASSWORD&confirm=$NEW_PASSWORD" --csrf a
step viewer_emptyAll a POST /account --body "current=&new=&confirm=" --csrf a
step viewer_shortNew a POST /account \
  --body "current=$PASSWORD&new=short&confirm=short" --csrf a
step viewer_longNew a POST /account \
  --body "current=$PASSWORD&new=$(printf 'x%.0s' $(seq 1 201))&confirm=$(printf 'x%.0s' $(seq 1 201))" --csrf a
step viewer_nullNew a POST /account \
  --body "current=$PASSWORD&new=abcdefgh%00&confirm=abcdefgh%00" --csrf a
step viewer_mismatch a POST /account \
  --body "current=$PASSWORD&new=$NEW_PASSWORD&confirm=other-password" --csrf a

# --- D. CSRF on both POSTs, v3's 419 ------------------------------------------------------------
step viewer_noCsrf a POST /account --body "current=$PASSWORD&new=$NEW_PASSWORD&confirm=$NEW_PASSWORD"
step signout_noCsrf a POST /account/signout

# --- E. sign out everywhere: this device stays, the other one stops authenticating ---------------
step signout a POST /account/signout --csrf a
step viewer_afterSignout a GET /account
step secondSession_afterSignout b GET /account

# --- F. a new password: the epoch rotates and this session stays signed in ----------------------
step viewer_changePw a POST /account \
  --body "current=$PASSWORD&new=$NEW_PASSWORD&confirm=$NEW_PASSWORD" --csrf a
step viewer_afterChange a GET /account?done=pw
step secondSession_afterChange b GET /account
# The password really changed: the old one is refused, the new one signs in.
step login_oldPassword c GET /login
step login_oldPassword_post c POST /login --body "username=viewer&password=$PASSWORD" --csrf c
step login_newPassword c POST /login --body "username=viewer&password=$NEW_PASSWORD" --csrf c
step c_page c GET /account

# --- G. an admin's page: the same document, no body classes, every permission --------------------
# A fresh jar: v3 answers GET /login for a signed-in visitor with a redirect, and a session that is
# already signed in is not the state this step is about.
step login_admin d GET /login
step login_admin_post d POST /login --body "username=admin&password=$PASSWORD" --csrf d
step admin_page d GET /account

# --- H. the throttle: the current-password check is limited exactly as a sign-in is --------------
# The eleventh wrong current password from this address for this account is refused. Its answer is
# the page again, with the limiter's sentence, so a wrong password cannot be tried in a loop here.
state user lockme viewer active >/dev/null
step login_lockme e GET /login \
  --setup '[{"op":"user","username":"lockme","role":"viewer","status":"active"}]'
step login_lockme_post e POST /login --body "username=lockme&password=$PASSWORD" --csrf e
step lockme_page e GET /account
for _ in $(seq 1 10); do
  TOK=$(eval "echo \${TOKEN_e}")
  curl -sS -o /dev/null -b "$(jar_file e)" -c "$(jar_file e)" \
    --data-raw "csrf=$TOK&current=wrong-one&new=$NEW_PASSWORD&confirm=$NEW_PASSWORD" \
    "$ORIGIN/account"
  ensure_consent e
done
step lockme_rateLimited e POST /account \
  --body "current=wrong-one&new=$NEW_PASSWORD&confirm=$NEW_PASSWORD" --csrf e

if [ "$(state settings | jq -S .)" != "$(echo "$SETTINGS_BEFORE" | jq -S .)" ]; then
  echo "the capture left a settings row changed:" >&2
  diff <(echo "$SETTINGS_BEFORE" | jq -S .) <(state settings | jq -S .) >&2 || true
  exit 1
fi

node "$ROOT/v4/scripts/gen-account-fixture.mjs" write \
  "$CAP" "$OUT" "$ROOT" "$("$PHP" -r 'echo PHP_VERSION;')" \
  "$PASSWORD" "$NEW_PASSWORD" "$SETTINGS_BEFORE" "${SOURCES[@]}"
