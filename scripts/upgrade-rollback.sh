#!/usr/bin/env bash
# shellcheck disable=SC2317,SC2329 # cleanup() and pg() run from the EXIT trap
# PLAN step 16 part 2 (§3.6): upgrade and rollback on one data directory and database.
#   legacy@8.3 seeds (demo JPEGs + a PNG and a HEIC with originals, tags, an attachment, a saved filter, a stocktake:
#   every carried table non-empty) → CI4@8.4 on the same data → legacy@8.3 again, then cross-restore: a CI4 backup
#   restored by legacy@8.3 and a legacy backup restored by CI4@8.4, each over the same install and into a fresh data
#   directory and database. After every step the normalised DB dump and the sha256 of images/, thumbs/, originals/
#   (map/ included) and files/ must equal the seeded state, originals must be 0750/0640 and files/ 0750
#   (tests/upgrade/). Last, mutation checks prove that an emptied or dropped table, a changed or missing attachment
#   and a wrong mode (an original, files/) fail.
# Env: DB_DRIVER=sqlite|pgsql (pgsql: TEST_PG_HOST, TEST_PG_PORT, TEST_PG_DB, TEST_PG_USER, TEST_PG_PASSWORD),
#      PHP83 and PHP84 (default php8.3, php8.4), UPGRADE_WORK (a path that must not exist yet; default: a new
#      mktemp -d), UPGRADE_KEEP=1 keeps it. The script removes only the work dir and the upg_<run>_* schemas it made.
#      UPGRADE_LEGACY_REF (default v2.6.26): the legacy release checked out into a temporary worktree inside the
#      work dir (the tag is fetched when missing); UPGRADE_LEGACY_ROOT uses an existing checkout instead.
# Legacy runs its own bin/ tools from that checkout; CI4 runs this tree's bin/ tools. tests/upgrade/ loads the
# legacy code from UPGRADE_LEGACY_ROOT.
set -euo pipefail

ROOT=$(cd "$(dirname "$0")/.." && pwd)
PHP83=${PHP83:-php8.3}
PHP84=${PHP84:-php8.4}
DRIVER=${DB_DRIVER:-sqlite}
WORK=
SCHEMAS=()
SERVER_PID=
LEGACY=
LEGACY_WT=
fail=0

"$PHP83" -r 'exit(PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 3 ? 0 : 1);' || { echo "PHP83 is not PHP 8.3" >&2; exit 2; }
"$PHP84" -r 'exit(PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 4 ? 0 : 1);' || { echo "PHP84 is not PHP 8.4" >&2; exit 2; }

pg() { PGOPTIONS=--client-min-messages=warning PGPASSWORD="$TEST_PG_PASSWORD" psql -X -q -v ON_ERROR_STOP=1 -h "$TEST_PG_HOST" -p "$TEST_PG_PORT" -U "$TEST_PG_USER" -d "$TEST_PG_DB" -c "$1"; }

cleanup() {
  if [ -n "$SERVER_PID" ]; then kill "$SERVER_PID" 2>/dev/null || true; wait "$SERVER_PID" 2>/dev/null || true; fi
  if [ "$DRIVER" = pgsql ]; then for s in "${SCHEMAS[@]}"; do pg "DROP SCHEMA IF EXISTS $s CASCADE" || true; done; fi
  if [ -n "$LEGACY_WT" ]; then git -C "$ROOT" worktree remove --force "$LEGACY_WT" 2>/dev/null || true; git -C "$ROOT" worktree prune; fi
  if [ -z "$WORK" ]; then return; fi
  if [ "${UPGRADE_KEEP:-}" = 1 ]; then echo "kept $WORK"; else rm -rf "$WORK"; fi
}
trap cleanup EXIT

if [ -n "${UPGRADE_WORK:-}" ]; then
  if [ -e "$UPGRADE_WORK" ] || [ -L "$UPGRADE_WORK" ]; then echo "UPGRADE_WORK $UPGRADE_WORK already exists; give a new path" >&2; exit 2; fi
  mkdir -m 0700 "$UPGRADE_WORK"
  WORK=$UPGRADE_WORK
else
  WORK=$(mktemp -d "${TMPDIR:-/tmp}/upgrade-rollback.XXXXXX")
fi
RUN=$(od -An -N4 -tx1 /dev/urandom | tr -d ' \n')
SCHEMAS=("upg_${RUN}_main" "upg_${RUN}_restore_legacy" "upg_${RUN}_restore_ci4")

# The legacy side: a released 2.x checkout, outside this tree.
if [ -n "${UPGRADE_LEGACY_ROOT:-}" ]; then
  LEGACY=$(cd "$UPGRADE_LEGACY_ROOT" && pwd)
else
  ref=${UPGRADE_LEGACY_REF:-v2.6.26}
  git -C "$ROOT" rev-parse -q --verify "refs/tags/$ref^{commit}" > /dev/null \
    || git -C "$ROOT" fetch -q --depth=1 origin "refs/tags/$ref:refs/tags/$ref"
  LEGACY="$WORK/legacy-$ref"
  git -C "$ROOT" worktree add -q --detach "$LEGACY" "$ref"
  LEGACY_WT=$LEGACY
fi
if [ ! -f "$LEGACY/src/bootstrap.php" ] || [ ! -f "$LEGACY/bin/seed.php" ]; then echo "$LEGACY is not a legacy 2.x checkout" >&2; exit 2; fi
export UPGRADE_LEGACY_ROOT=$LEGACY

# Environment for one install: its data directory and (pgsql) its own schema.
install_env() {
  local dir=$1 schema=$2
  export DATA_DIR="$WORK/$dir" TZ=Europe/Athens APP_PASSWORD='' APP_KEY='' APP_KEY_FILE='' SEED_DEFAULTS=0 \
    APOTHEKE_CONFIG=/nonexistent DB_DRIVER="$DRIVER"
  unset ORIGINALS_DIR SQLITE_PATH APOTHEKE_APP
  if [ "$DRIVER" = pgsql ]; then
    export DB_HOST="$TEST_PG_HOST" DB_PORT="$TEST_PG_PORT" DB_NAME="$TEST_PG_DB" DB_USER="$TEST_PG_USER" \
      DB_PASSWORD="$TEST_PG_PASSWORD" DB_SCHEMA="$schema"
  fi
  mkdir -p "$DATA_DIR"
}

# files/ mode the apps create (legacy mkdir 0770, CI4 the same) under the umask they run with here (N-643).
UPGRADE_FILES_MODE=$(printf '%04o' $((0770 & ~0$(umask))))
export UPGRADE_FILES_MODE

snap() { "$PHP84" "$ROOT/tests/upgrade/snapshot.php" --scope="$1" > "$WORK/$2.json"; }
check() { "$PHP84" "$ROOT/tests/upgrade/compare.php" "$1" "$WORK/$2.json" "$WORK/$3.json" || fail=1; }
# A mutation must make the check fail with a DIFF or FAIL line that names what it mutated (the table, or the file
# under the data directory); a pass, a crash or a line about something else means the gate is blind to it (N-546).
#   must_fail LABEL TARGET BEFORE AFTER   compares two snapshots;   must_fail LABEL TARGET --guard SNAPSHOT   seed guard
must_fail() {
  local label=$1 target=$2 code=0 caught='' line rest
  if [ "$3" = --guard ]; then
    "$PHP84" "$ROOT/tests/upgrade/compare.php" --guard "$label" "$WORK/$4.json" > "$WORK/mutation.txt" 2>&1 || code=$?
  else
    "$PHP84" "$ROOT/tests/upgrade/compare.php" "$label" "$WORK/$3.json" "$WORK/$4.json" > "$WORK/mutation.txt" 2>&1 || code=$?
  fi
  while IFS= read -r line; do
    case $line in "DIFF $label "* | "FAIL $label"*) ;; *) continue ;; esac
    rest=${line#* }; rest=${rest#"$label"}
    case "$rest " in *" $target "* | *" $target:"*) caught=$line; break ;; esac
  done < "$WORK/mutation.txt"
  if [ "$code" = 1 ] && [ -n "$caught" ]; then
    echo "ok mutation caught: $label: $(printf '%s' "$caught" | cut -c1-140)"
  else
    cat "$WORK/mutation.txt"; echo "FAIL mutation not caught (exit $code, no DIFF or FAIL line names $target): $label"; fail=1
  fi
}

# Starts the app (legacy or ci4) on a free port, checks /healthz, the list and one served photo, then stops it.
serve_check() {
  local app=$1 php=$2 port base docroot front photo
  # shellcheck disable=SC2016 # PHP code, not shell
  port=$("$php" -r '$s = stream_socket_server("tcp://127.0.0.1:0"); echo substr(strrchr(stream_socket_get_name($s, false), ":"), 1);')
  base="http://127.0.0.1:$port"
  if [ "$app" = ci4 ]; then docroot="$ROOT/public" front="$ROOT/public/router.php"; else docroot="$LEGACY/public" front="$LEGACY/public/index.php"; fi
  "$php" -S "127.0.0.1:$port" -t "$docroot" "$front" > "$WORK/server-$app-$port.log" 2>&1 &
  SERVER_PID=$!
  for _ in $(seq 100); do curl -fs -o /dev/null "$base/healthz" && break; sleep 0.1; done
  local ok=1
  [ "$(curl -s -o /dev/null -w '%{http_code}' "$base/healthz")" = 200 ] || { echo "FAIL $app: /healthz is not 200"; ok=0; }
  curl -fs "$base/" > "$WORK/list-$app.html" || { echo "FAIL $app: / did not answer 200"; ok=0; }
  for name in 'Cordless drill' 'Upgrade check'; do
    grep -q "$name" "$WORK/list-$app.html" || { echo "FAIL $app: the list does not show \"$name\""; ok=0; }
  done
  photo=$(find "$DATA_DIR/images" -maxdepth 1 -name '*.jpg' -printf '%f\n' | sort | head -n 1)
  for kind in full:images thumb:thumbs; do
    if [ -z "$photo" ] || ! curl -fs -o "$WORK/media.bin" "$base/media/${kind%%:*}/$photo" || ! cmp -s "$WORK/media.bin" "$DATA_DIR/${kind#*:}/$photo"; then
      echo "FAIL $app: /media/${kind%%:*}/$photo is not the stored file"; ok=0
    fi
  done
  kill "$SERVER_PID"; wait "$SERVER_PID" 2>/dev/null || true; SERVER_PID=
  if [ "$ok" = 1 ]; then echo "ok $app@$("$php" -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;') serves /healthz, the list and a photo with its thumbnail"; else fail=1; fi
}

# bin/migrate.php --status must list every migration as applied (no unknown or pending one after a switch).
status_check() {
  local app=$1 php=$2 out root=$ROOT
  [ "$app" = ci4 ] || root=$LEGACY
  out=$("$php" "$root/bin/migrate.php" --status)
  if [ "$(grep -cE '^ +[0-9]{4} ' <<<"$out")" -lt 28 ] || grep -E '^ +[0-9]{4} ' <<<"$out" | grep -vq ' applied '; then
    echo "$out"; echo "FAIL $app: bin/migrate.php --status shows a migration that is not applied"; fail=1
  else
    echo "ok $app: $(grep -cE '^ +[0-9]{4} ' <<<"$out") migrations applied"
  fi
}

echo "upgrade/rollback: $DRIVER, $("$PHP83" -r 'echo PHP_VERSION;') and $("$PHP84" -r 'echo PHP_VERSION;'), work dir $WORK"

# 1. legacy@8.3 seeds.
install_env data "${SCHEMAS[0]}"
"$PHP83" "$LEGACY/bin/seed.php" --demo
"$PHP83" "$ROOT/tests/upgrade/seed-extra.php"
snap all seeded
snap backup seeded-backup
"$PHP84" "$ROOT/tests/upgrade/compare.php" --guard 'seed' "$WORK/seeded.json" || { echo "upgrade/rollback: the seed is too thin"; exit 1; }
"$PHP83" "$LEGACY/bin/backup.php" "$WORK/legacy.zip"
serve_check legacy "$PHP83"
snap all legacy-served
check 'legacy@8.3 served' seeded legacy-served

# 2. Upgrade: CI4@8.4 on the same data directory and database. Its own migrations (0029 jobs on 3.2) run first, as
# on a deploy or the first request; they only add what 2.6.x ignores (tests/upgrade/lib.php upg_ci4_only()).
"$PHP84" "$ROOT/bin/migrate.php" > "$WORK/migrate-ci4.txt" 2>&1 || { cat "$WORK/migrate-ci4.txt"; echo "FAIL ci4: bin/migrate.php"; fail=1; }
status_check ci4 "$PHP84"
snap all upgraded
check 'upgrade to CI4@8.4' seeded upgraded
serve_check ci4 "$PHP84"
"$PHP84" "$ROOT/bin/backup.php" "$WORK/ci4.zip"
snap all ci4-served
check 'CI4@8.4 served and backed up' seeded ci4-served

# 3. Rollback A: legacy@8.3 on the same data directory and database.
status_check legacy "$PHP83"
serve_check legacy "$PHP83"
snap all rolled-back
check 'rollback to legacy@8.3' seeded rolled-back

# 4. Rollback B (§3.6 step 4) and its reverse: each app restores the other's zip over the same install.
"$PHP83" "$LEGACY/bin/restore.php" "$WORK/ci4.zip" --yes
snap backup restored-in-place-legacy
check 'CI4 backup restored over the install by legacy@8.3' seeded-backup restored-in-place-legacy
"$PHP84" "$ROOT/bin/restore.php" "$WORK/legacy.zip" --yes
snap backup restored-in-place-ci4
check 'legacy backup restored over the install by CI4@8.4' seeded-backup restored-in-place-ci4

# 5. Cross-restore into fresh installs.
install_env restore-legacy "${SCHEMAS[1]}"
"$PHP83" "$LEGACY/bin/restore.php" "$WORK/ci4.zip" --yes
snap backup restored-legacy
check 'CI4 backup restored on legacy@8.3' seeded-backup restored-legacy

install_env restore-ci4 "${SCHEMAS[2]}"
"$PHP84" "$ROOT/bin/restore.php" "$WORK/legacy.zip" --yes
snap backup restored-ci4
check 'legacy backup restored on CI4@8.4' seeded-backup restored-ci4

# 6. Mutation checks on that disposable install: each must fail against the clean m0 and is undone before the next
# one (N-642). The emptied table is undone by restoring the legacy backup again; the dropped table comes last.
mutate() { "$PHP84" "$ROOT/tests/upgrade/mutate.php" "$1"; }
snap backup m0
attachment=$(find "$DATA_DIR/files" -maxdepth 1 -type f -name '*.pdf' | sort | head -n 1)
cp -p "$attachment" "$WORK/attachment.bak"
printf 'x' >> "$attachment"
snap backup m1
must_fail 'changed files/ attachment' "files/${attachment##*/}" m0 m1
cp -p "$WORK/attachment.bak" "$attachment"
rm "$attachment"
snap backup m2
must_fail 'missing files/ attachment' "files/${attachment##*/}" m0 m2
cp -p "$WORK/attachment.bak" "$attachment"
original=$(find "$DATA_DIR/originals" -maxdepth 1 -type f -name '*.png' | head -n 1)
chmod 0644 "$original"
snap backup m3
must_fail 'original readable by others (0644)' "originals/${original##*/}" m0 m3
chmod 0640 "$original"
chmod 0700 "$DATA_DIR/files"
snap backup m4
must_fail 'files/ not legacy mode (0700)' 'mode files' m0 m4
chmod "$UPGRADE_FILES_MODE" "$DATA_DIR/files"
snap backup m4u
check 'file mutations undone' m0 m4u
mutate 'DELETE FROM item_tags'
snap backup m5
must_fail 'emptied carried table (item_tags)' item_tags m0 m5
must_fail 'seed guard on an emptied carried table' item_tags --guard m5
"$PHP84" "$ROOT/bin/restore.php" "$WORK/legacy.zip" --yes > /dev/null
snap backup m6
check 'every mutation undone' m0 m6
mutate 'DROP TABLE saved_filters'
snap backup m7
must_fail 'dropped carried table (saved_filters)' saved_filters m0 m7

if [ "$fail" = 0 ]; then echo "upgrade/rollback: all steps equal"; else echo "upgrade/rollback: differences found"; fi
exit "$fail"
