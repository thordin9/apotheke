#!/usr/bin/env bash
# Apotheke v4 self-host smoke test (plan §11 rule 8, §5 S0.9).
#
# Boots one of the compose files on a clean state and asserts the contract a self-hoster depends on:
#
#   1. /healthz answers 200 as soon as the process is up, and reports the driver the file selects;
#   2. /readyz answers 200 with schema 0036 and seed done on a *fresh* install — the migration chain,
#      the seed phase and the readiness gate all really ran;
#   3. a second boot over the same volumes changes nothing: the admin row, the 64 settings rows and
#      the seed stamp are byte-identical afterwards. The seed phase does run again on every boot (its
#      idempotent half is by design); what must not happen is a duplicate admin, a rewritten bcrypt
#      hash or a rewritten setting, and any of those would show up in the comparison.
#
# Usage: scripts/selfhost-smoke.sh [compose file]      (default: v4/docker-compose.yml)
#
# Exit status: 0 all assertions passed, 1 something failed. It FAILS when the Docker CLI is absent
# instead of skipping: a smoke test that skips is how a green CI job ends up testing nothing.
#
# Environment:
#   SMOKE_KEEP=1        leave the stack running afterwards (for poking at it)
#   SMOKE_TIMEOUT=300   seconds to wait for /healthz and then for /readyz (default 300 each)
set -euo pipefail

compose_file="${1:-v4/docker-compose.yml}"
timeout_s="${SMOKE_TIMEOUT:-300}"
base_url="http://127.0.0.1:9091"

say() { printf '%s\n' "$*"; }
die() { printf 'SMOKE FAILED: %s\n' "$*" >&2; exit 1; }

# ---- preconditions ---------------------------------------------------------------------------

[ -f "$compose_file" ] || die "no such compose file: $compose_file (pass it as the first argument)"
command -v docker > /dev/null 2>&1 || die "the docker CLI is not installed; this script does not skip"
docker compose version > /dev/null 2>&1 || die "'docker compose' (v2) is not available; this script does not skip"
command -v curl > /dev/null 2>&1 || die "curl is required to probe the stack"

case "$(basename "$compose_file")" in
	*sqlite*) default_driver="sqlite" ;;
	*) default_driver="rqlite" ;;
esac

work=$(mktemp -d)
say "compose file: $compose_file  (driver: $default_driver)"

compose() { docker compose -f "$compose_file" "$@"; }

# One trap, not two: `die` exits, and only an EXIT trap sees an exit. (The first version had a
# separate ERR trap, which never fired on `die` — the run that failed at /healthz dumped no logs at
# all and tore the containers down, which is the least useful thing a failing smoke test can do.)
cleanup() {
	status=$?
	if [ "$status" -ne 0 ]; then
		printf '\n--- docker compose ps -a ---\n' >&2
		compose ps -a >&2 || true
		printf '\n--- docker compose logs (tail 300) ---\n' >&2
		compose logs --no-color --tail 300 >&2 || true
	fi
	if [ "${SMOKE_KEEP:-0}" = "1" ]; then
		say "SMOKE_KEEP=1: leaving the stack up"
		return 0
	fi
	compose down -v --remove-orphans > /dev/null 2>&1 || true
	rm -rf "$work"
}
trap cleanup EXIT

# ---- configuration, handed to compose through the environment -------------------------------
#
# Compose interpolates ${VAR:-} from the shell environment as well as from .env, so the stack runs on
# generated values and an operator's own .env is neither read nor overwritten.
export APP_PASSWORD="smoke-$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')"
export APP_KEY=""
export PUBLIC_URL="$base_url"
export APOTHEKE_CLOCK=""
export APOTHEKE_HOSTED=""
export CURRENCY="EUR"
export TZ="UTC"
export PAGE_SIZE="60"
export MAX_UPLOAD_MB="20"
export IMAGE_FULL_MAX="1600"
export IMAGE_THUMB_MAX="480"
export EXPIRY_SOON_DAYS="30"
export RQLITE_READ_CONSISTENCY="weak"
if [ "$default_driver" = "rqlite" ]; then
	# With -auth on, like the rqlite CI job: the default self-host posture (§11 rule 4), and what makes
	# the healthcheck's credential path and the app's 401 handling real rather than assumed.
	export RQLITE_AUTH="smoke:$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')"
	export RQLITE_URL="http://rqlite:4001"
else
	export RQLITE_AUTH=""
	export RQLITE_URL=""
fi

# ---- assertions ------------------------------------------------------------------------------
#
# Every probe below is bounded by curl's own --max-time/--connect-timeout, and that is load
# bearing: an app that accepts the connection and never answers (an unbootable build that the
# kernel still has listening) blocks curl forever, so the deadline checks between iterations
# never run and the loop's own "did not answer within Ns" message never prints. Run 45367702792
# cost 37 minutes of wall clock and was cancelled to learn nothing a 5 second timeout would have
# said at once.

wait_healthz() {
	deadline=$((SECONDS + timeout_s))
	while [ "$SECONDS" -lt "$deadline" ]; do
		# Fail fast when the container is not even running: waiting the full timeout to say "no
		# answer" when the answer is "it exited at once" wastes the one thing CI gives slowly.
		state=$(compose ps -a --format '{{.Service}}={{.State}}' 2> /dev/null | tr '\n' ' ' || true)
		case "$state" in
			*restarting* | *exited* | *dead*) die "the app container is not running ($state)" ;;
		esac
		if body=$(curl -fsS --max-time 5 --connect-timeout 3 "$base_url/healthz" 2> /dev/null); then
			case "$body" in
				*'"ok":true'*) ;;
				*) die "/healthz answered without ok=true: $body" ;;
			esac
			case "$body" in
				*"\"driver\":\"$default_driver\""*) say "/healthz 200 $body" ;;
				*) die "/healthz reports the wrong driver (want $default_driver): $body" ;;
			esac
			return 0
		fi
		sleep 2
	done
	die "/healthz did not answer within ${timeout_s}s"
}

wait_readyz() {
	deadline=$((SECONDS + timeout_s))
	local code=""
	while [ "$SECONDS" -lt "$deadline" ]; do
		code=$(curl -sS --max-time 5 --connect-timeout 3 -o "$work/ready.json" -w '%{http_code}' "$base_url/readyz" 2> /dev/null || true)
		if [ "$code" = "200" ]; then
			body=$(cat "$work/ready.json")
			case "$body" in
				*'"schema":"0036"'*) ;;
				*) die "/readyz 200 without schema 0036: $body" ;;
			esac
			case "$body" in
				*'"seed":"done"'*) ;;
				*) die "/readyz 200 without seed done: $body" ;;
			esac
			say "/readyz 200 $body"
			return 0
		fi
		sleep 2
	done
	die "/readyz never reached 200 within ${timeout_s}s (last: $code $(cat "$work/ready.json" 2> /dev/null || true))"
}

# Prints a normalised snapshot of everything the seed owns. It runs inside the app container, so the
# script needs nothing on the host but docker and curl, and it asserts the row counts itself: a
# snapshot that is identical but wrong is not a pass.
snapshot() {
	if [ "$default_driver" = "rqlite" ]; then
		compose exec -T apotheke node --input-type=module -e '
const url = (process.env.RQLITE_URL || "").replace(/\/+$/, "");
const auth = process.env.RQLITE_AUTH || "";
const headers = { "content-type": "application/json" };
if (auth) headers.authorization = "Basic " + Buffer.from(auth).toString("base64");
const query = async (sql) => {
	const res = await fetch(url + "/db/query?level=strong", {
		method: "POST",
		headers,
		body: JSON.stringify([[sql]]),
	});
	if (!res.ok) throw new Error("query failed " + res.status + ": " + (await res.text()));
	const body = await res.json();
	const result = body.results[0];
	return result.values.map((row) => Object.fromEntries(result.columns.map((c, i) => [c, row[i]])));
};
const fail = (m) => { console.error("SMOKE: " + m); process.exit(1); };
const users = await query("select id, username, username_key, password_hash, role, created_at from users order by id");
const settings = await query("select name, value, updated_at from settings order by name");
const appSettings = await query("select name, value from app_settings order by name");
if (users.length !== 1) fail("expected 1 admin user, found " + users.length);
if (settings.length !== 64) fail("expected 64 settings, found " + settings.length);
const stamp = appSettings.find((r) => r.name === "v4_seed");
if (!stamp || stamp.value !== "done") fail("v4_seed is " + (stamp ? stamp.value : "missing"));
console.log(JSON.stringify({ users, settings, app_settings: appSettings }));
'
	else
		db_file=$(compose exec -T apotheke sh -c '
			find /data/state -name "*.sqlite" ! -name "metadata.sqlite" 2>/dev/null | head -n 1
		' | tr -d '\r')
		[ -n "$db_file" ] || die "no SQLite database under /data/state — did the sqlite stack boot?"
		printf 'database inside the volume: %s\n' "$db_file" >&2
		compose exec -T -e "SMOKE_DB=$db_file" apotheke node --experimental-sqlite --input-type=module -e '
import { DatabaseSync } from "node:sqlite";
const db = new DatabaseSync(process.env.SMOKE_DB, { readOnly: true });
const fail = (m) => { console.error("SMOKE: " + m); process.exit(1); };
const users = db.prepare("select id, username, username_key, password_hash, role, created_at from users order by id").all();
const settings = db.prepare("select name, value, updated_at from settings order by name").all();
const appSettings = db.prepare("select name, value from app_settings order by name").all();
if (users.length !== 1) fail("expected 1 admin user, found " + users.length);
if (settings.length !== 64) fail("expected 64 settings, found " + settings.length);
const stamp = appSettings.find((r) => r.name === "v4_seed");
if (!stamp || stamp.value !== "done") fail("v4_seed is " + (stamp ? stamp.value : "missing"));
console.log(JSON.stringify({ users, settings, app_settings: appSettings }));
'
	fi
}

# ---- the run ---------------------------------------------------------------------------------

say "--- clean state"
compose down -v --remove-orphans > /dev/null 2>&1 || true

say "--- build and start"
compose up -d --build

say "--- first boot"
wait_healthz
wait_readyz
snapshot > "$work/first-boot.json"

# The container healthchecks are part of the contract (§11 rule 7): a self-hoster reads
# `docker compose ps`, and `depends_on: service_healthy` is only as good as the check behind it.
wait_healthy() {
	service="$1"
	deadline=$((SECONDS + 120))
	while [ "$SECONDS" -lt "$deadline" ]; do
		health=$(compose ps --format '{{.Service}}={{.Health}}' 2> /dev/null | grep "^$service=" || true)
		case "$health" in
			*"=healthy"*) say "healthcheck: $health"; return 0 ;;
		esac
		sleep 2
	done
	die "$service never reported healthy (last: $health)"
}
wait_healthy apotheke
if [ "$default_driver" = "rqlite" ]; then
	wait_healthy rqlite
fi

say "--- second boot over the same volumes"
compose restart apotheke > /dev/null
wait_healthz
wait_readyz
snapshot > "$work/second-boot.json"

if ! cmp -s "$work/first-boot.json" "$work/second-boot.json"; then
	diff -u "$work/first-boot.json" "$work/second-boot.json" >&2 || true
	die "the second boot changed the seeded rows — that is a re-seed, not an idempotent no-op"
fi
say "row content identical across the second boot: no duplicate admin, no rewritten settings"

say "SMOKE OK: $compose_file"
