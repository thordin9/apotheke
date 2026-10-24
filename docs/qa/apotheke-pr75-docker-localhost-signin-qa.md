# QA: apotheke #75, Docker listens on localhost and needs sign-in (SEC-M3)

- **PR:** [#75](https://github.com/thordin9/apotheke/pull/75), `sec/docker-localhost-password` into `main`, by DevOps
- **Head pinned:** `c5b6c39ce47cb60b9d0a00f3c930dabbc2c55da0` (the head when this doc was written)
- **Code reviewed in full:** `a1c9027` plus `a31268e` (the exit-78 log line). Everything after `a31268e` was diff-read:
  `f182cf1` and `c5b6c39` add only the v2.6.3 and v2.6.4 release QA docs, and `df82800` merges main (v2.6.4, `205ed23`).
- **Base:** `main` at `205ed23` (`chore(release): v2.6.4 [skip ci]`), the second parent of `df82800`
- **Scope:** SEC-M3 as approved: Docker installs listen only on localhost by default and need an app password
- **Verdict: PASS.** 0 High, 0 Medium, 2 Low, 3 Nits, 9 Info.

## 1. Test plan

1. Code from the codeload tarballs of `1dbe986` (the old base), `a1c9027`, `a31268e`, `df82800`, `c5b6c39`'s files
   and `main` `205ed23`. Diff review, and commit subjects (Conventional Commits, 72 characters at most).
2. `auth status` and the entrypoint check, run for real. The box has no Docker, so the head's `docker/entrypoint.sh`
   ran under the image's own base (the `php:8.3.36-fpm-alpine` rootfs: busybox `sh` and `su`, user `www-data` 82) in a
   chroot, against SQLite and PostgreSQL 17. The copy is the real file up to the line before `PORT=`, with the app
   path changed to the bind-mounted head. Everything after that point (nginx and php-fpm) is replaced by a `STARTED`
   marker, so each run shows what the check decided and its exit code.
3. The documented way out (`bin/settings.php password`) on the same databases, including with guest browsing allowed.
4. PHPUnit (unit and integration) and `tests/integration.php` on PHP 8.3 and 8.4, on SQLite and PostgreSQL 17.
5. Mutations on `auth_on_at_start()` (PHPUnit) and on the entrypoint (chroot runs), each set against the tests and
   the cases in `scripts/docker-signin-checks.sh`.
6. `shellcheck` on the entrypoint and the new script. compose files, docs and examples read against the code.
7. CHANGELOG gate against `205ed23`, with a check that `df82800` resolved its conflicts correctly. QA docs compared
   byte for byte with `/workspace/qa-docs`. PR body read.
8. CI through the GitHub API.

**Environment.** PostgreSQL 17 with a throwaway role and databases (dropped afterwards). The PHPUnit and
`integration.php` legs ran on the box's PHP 8.3 and 8.4 builds, because the Alpine rootfs has no `git`, which two
`ReleaseTest` cases need. The entrypoint runs used the Alpine rootfs. The image build, nginx and the Playwright
suites were not repeated: CI's Docker job built the image and ran `docker-signin-checks.sh` on it (§2.1).

## 2. Results

### 2.1 CI

| Head | Run | Result |
|---|---|---|
| `c5b6c39` (pinned) | 40089238822 (`pull_request`) | **`completed` / `success`, 10/10 jobs**, 14:16:26 to 14:26:55 (Athens, UTC+3) |
| `a1c9027` (code head) | 40087491909 | `success`, 10/10. The Docker job's "Sign-in required at start (SEC-M3 …)" step passed. |
| `f182cf1` | 40088314105 | `failure`: only Visual E2E shard 1/3. That commit adds only a QA doc to `a1c9027`, which was green, and the same shard passed at `c5b6c39`, so this looks like a flake (I-7) |
| `a31268e`, `df82800` | none / 40089118048 | No run at `a31268e` (overtaken by later pushes). `df82800`'s run was cancelled by the `c5b6c39` push. |

The jobs at `c5b6c39` are Lint, Tests (PHP 8.3, sqlite), Tests (PHP 8.3, pgsql), Headless mobile checks, Visual E2E
1/3, 2/3 and 3/3, Docker build + smoke test, Conventional Commits, and Secret scan. The Docker job is the one that runs
`docker-signin-checks.sh` against the real image, so it now also checks the new exit-78 text.

### 2.2 Diff and commits

Against `205ed23`, the head changes 22 files: CI, AGENTS.md, CHANGELOG, README, `bin/settings.php`,
`config.example.php`, `docker-compose.yml`, `docker/entrypoint.sh`, five docs pages, `scripts/docker-heic.sh`, the new
`scripts/docker-signin-checks.sh`, `src/users.php`, the new `tests/Unit/AuthAtStartTest.php`, `DockerfileTest.php`,
`tests/visual/fixtures.ts`, and the v2.6.3 and v2.6.4 QA docs.

| Commit | Length | Subject |
|---|---|---|
| `e7163d5` | 63 | `fix(docker): require sign-in and listen on localhost by default` |
| `7d82575` | 62 | `test(docker): check the sign-in requirement at container start` |
| `acd9273` | 58 | `docs(docker): localhost port and required sign-in (SEC-M3)` |
| `82c02e0` | 50 | `docs(changelog): record the SEC-M3 Docker defaults` |
| `179f7c9` | 61 | `test(docker): hand the scratch volume back before deleting it` |
| `a1c9027` | 59 | `test(release): check the whole upgrade note leads the notes` (the same change as #73's `959d0e1`, I-9) |
| `f182cf1` | 42 | `docs(qa): add the v2.6.3 release QA report` |
| `a31268e` | 52 | `fix(docker): print both ways out when sign-in is off` |
| `df82800` | 77 | Merge of `origin/main` (two parents, `a31268e` + `205ed23`; `check-commits.sh` skips merges) |
| `c5b6c39` | 42 | `docs(qa): add the v2.6.4 release QA report` |

All non-merge subjects are Conventional Commits and 72 characters or shorter. History is linear apart from the merge,
with no rewrites. The PR body has the label note: "SEC-M3 now covers two changes: the Host allow-list and
same-origin rule from #70, and the Docker defaults here".

**`a31268e` in full.** The entrypoint's single exit-78 `echo` became two `echo … >&2` lines with fixed, double-quoted
text and no variables. Line 1 names `docker compose run --rm -u www-data apotheke php bin/settings.php password`.
Line 2 names `ALLOW_NO_LOGIN=1` and says a new install can also set `APP_PASSWORD`. `exit 78` is unchanged (§2.4).
`docker-signin-checks.sh` adds `grep -q 'php bin/settings.php password'` next to the existing `ALLOW_NO_LOGIN=1`
grep, and the deployment doc says the log names both ways out. `shellcheck` is clean on both scripts.

### 2.3 QA docs in the PR

| File | Size | sha256 | vs `/workspace/qa-docs` |
|---|---|---|---|
| `docs/qa/apotheke-v2.6.3-release-qa.md` | 10,375 B | `101da7fa…db4b` | **byte-identical** |
| `docs/qa/apotheke-v2.6.4-release-qa.md` | 8,476 B | `67a0104d…d615` | **byte-identical** |

Each sits in its own `docs(qa):` commit. `a1c9027..f182cf1` and `df82800..c5b6c39` each add only that one file. This
doc should be committed unchanged as the **last** commit on top of `c5b6c39`.

### 2.4 `auth status` and the start check (chroot, real entrypoint text)

`auth status` (`bin/settings.php`) opens no database when the SQLite file is missing. Otherwise it connects with
`db_connect()` and calls `auth_on_at_start()`, which runs two `SELECT`s. It does not migrate, seed or call
`auth_enabled()`, the call that inserts `admin`. The exit codes are 0 for on, 3 for off and 1 when the connection
fails; any other failure is PHP's 255. The entrypoint starts the app only on 0, exits 78 on 3, and retries anything
else every second for 30 retries before it exits 78.

| Case | Exit | Notes |
|---|---|---|
| Fresh SQLite, `APP_PASSWORD` unset | **78** | Both lines printed. **No `catalog.sqlite` created** (the data dir held only the entrypoint's own sub-dirs) |
| Fresh, `ALLOW_NO_LOGIN` = empty, `0`, `false`, `no` | **78** each | |
| `ALLOW_NO_LOGIN` = `maybe`, `TRUE`, ` 1`, `Yes`, `2`, `on` | **64** each | `ALLOW_NO_LOGIN must be 1, true, yes, 0, false, no or empty` (I-4) |
| `ALLOW_NO_LOGIN` = `1`, `true`, `yes` | 0, started | Logs `sign-in is not required`. The check is skipped. |
| Fresh, `APP_PASSWORD` set | 0, started | Still no database file afterwards |
| Fresh, `APP_PASSWORD=` (empty) | **78** | |
| Fresh, `APP_PASSWORD` of spaces only | 0, started | The same answer as the app, which then makes the spaces the admin password (L-2) |
| Existing DB, sign-in on, admin exists, no `APP_PASSWORD` | 0, started | The file's sha256 is the same before and after the check |
| Existing DB, sign-in on, **no account**, no `APP_PASSWORD` | **78** | sha256 unchanged |
| The same with `APP_PASSWORD` | 0, started | The app creates `admin` on the first request (`auth_enabled()`) |
| Sign-in on, the only account is a **pending non-admin** | 0, started | The app agrees: `settings.php users` says "sign-in is on". `users_count()` counts every status, so this fails closed: nobody can sign in, and nothing is open. |
| Existing DB, sign-in **off**, `APP_PASSWORD` set | **78** | As the upgrade note says, `APP_PASSWORD` alone doesn't help |
| `auth_enabled` row missing, without / with `APP_PASSWORD` | 78 / 0 | Matches the setting's `cfg_auth` default |
| PG, fresh empty DB, without / with `APP_PASSWORD` | 78 / 0 | **0 tables before and after** |
| PG, fresh, `DB_SCHEMA` naming a missing schema | 78 | The empty schema **was created** (N-1) |
| PG, wrong DB password | **78 after 31 s** | 31 × `Could not connect to the database (pgsql – host=… port=… dbname=…)`, then `could not read the sign-in setting from the database (exit 1)` |
| PG, connection refused | **78 after 31 s** | The same lines |
| PG, filtered address (no reply) | — | A single `auth status` was still waiting after 25 s (killed). There is no connect timeout, so the check fails closed but can take far longer than 30 s (N-2). |

**Passwords in logs:** across all runs, the captured output contained neither `APP_PASSWORD` nor the DB password.
`auth status` writes `on`/`off` to stdout, which the entrypoint discards. On stderr it writes only the fixed message
with driver, host, port and database name. `db_connect()` drops the password before it can appear in a trace.

**The two `auth_on_at_start()` answers match `auth_enabled()`.** Both read the stored setting first. Both count every
account (`users_count()` without a role) and accept any non-empty `APP_PASSWORD` when there is no account. Disabled
accounts don't exist in this schema; `status` is only `active` or `pending`. So a pending or non-admin account can't
make the check say "on" while the app says "off".

**Entrypoint shell.** `set -eu` stays. `su … -c '…' >/dev/null || rc=$?` keeps `set -e` from ending the script on
the exit-3 answer. Every expansion is quoted, and the `case` falls through to 64 for anything not listed. The check
sits after the `exec "$@"` line, so `docker compose run … php bin/settings.php …` skips it (recovery works), and before
`php-fpm -F &` (`DockerfileTest` pins both positions). Exit codes 78 and 64 leave through `exit`, before any child
process exists. The main processes are supervised, not `exec`'d, which was already the case and is not changed here.

### 2.5 The documented way out, and the CLI guest bug

`bin/settings.php password` without an argument prompts `New password:` / `Repeat:` and reads both from STDIN
(`fgets`). `docker compose run` gives the container a TTY and attaches stdin by default, so the command works as
written, with no `-it`. With `-T` or from a script, piping two lines works too (tested). The prompt doesn't hide what
is typed (I-3). The command sets the password of `admin` (or creates it), makes it an active admin, and turns
sign-in on.

Coder's main bug (on the CLI, with sign-in **and** guest browsing on, `actor_is_admin()` sees the guest and refuses
changes to admin accounts) reproduced: `password` failed with "Only an admin can change an admin account." **That state
never reaches exit 78**, though. `auth status` exits 3 only when `auth_enabled()` is false, and then
`current_user()` is the anonymous admin, so `actor_is_admin()` is true. Guest browsing also needs `auth_enabled()`
(`guest_browsing()`). Tested with guest browsing allowed in the stored settings:

| Exit-78 state | `password` | Next start |
|---|---|---|
| Sign-in off, `admin` exists | ok | 0, started |
| Sign-in off, no account | ok (creates `admin`) | 0, started |
| Sign-in on, no account, no `APP_PASSWORD` | ok (creates `admin`) | 0, started |

`auth status` never calls `current_user()` or `actor_is_admin()`. **#75 does not depend on Coder's fix.** Only one
sentence touches the bug: the deployment doc's "Other commands … skip the check, so recovery always works". Recovery
with sign-in on and guests allowed fails today, and the sentence holds once the fix lands (I-2).

### 2.6 Test suites (code at `a31268e`; the merge only adds main's #73 files)

| Leg | PHPUnit | `tests/integration.php` |
|---|---|---|
| PHP 8.3, SQLite | 404 tests, 2,911 assertions, 1 skipped | 1,533 passed, 0 failed |
| PHP 8.4, SQLite | 404 tests, 2,911 assertions, 1 skipped | 1,533 passed, 0 failed |
| PHP 8.3, PostgreSQL 17 | 404 tests, 2,912 assertions, 1 skipped | 1,247 passed, 0 failed |
| PHP 8.4, PostgreSQL 17 | 404 tests, 2,912 assertions, 1 skipped | 1,247 passed, 0 failed |
| Merge head `df82800` (= `c5b6c39` code), PHP 8.4, SQLite | 404 tests, 2,911 assertions, 1 skipped | — |

- **PG integration:** run with `TEST_DB_DRIVER=pgsql`. It runs fewer checks than SQLite (1,247 vs 1,533, 49 vs 58
  sections). The log says it skips the legacy SQLite upgrade tests, and those predate this PR.
- **Skip:** `OriginalsTest::testPhpCgiRefusalHidesThePathAndDoesNotNeedStderr`, because `php-cgi` isn't on the box, as
  in earlier runs.
- **New tests:** `AuthAtStartTest` (3) and the two `DockerfileTest` cases pass on every leg. The focused run at
  `df82800` (`ReleaseTest`, `AuthAtStart`, `Dockerfile`, 29 tests) passes against the v2.6.4 CHANGELOG.

### 2.7 Mutations

**`auth_on_at_start()`, then `AuthAtStartTest`.** The baseline passes (3 tests, 12 assertions).

| Mutation | Result |
|---|---|
| Account-exists check dropped (`return true` once the setting is on) | **killed** |
| Missing `settings` table fails open (`return true`) | **killed** |
| Missing `auth_enabled` row fails open | **killed** |
| No database fails open | **killed** |
| Sign-in off ignored when `APP_PASSWORD` is set | **killed** |
| An empty `APP_PASSWORD` counts | **killed** (3 failures) |
| An error on the `users` query counts as one account | survives (I-5) |

**Entrypoint (chroot runs), and what would catch each one.**

| Mutation | Behaviour | Caught by |
|---|---|---|
| **Fail-closed default flipped:** the `rc != 0 → exit 78` line removed | DB unreachable → **app starts** | **Nothing.** `DockerfileTest` only looks for the string `exit 78`, which the exit-3 branch still has, and `docker-signin-checks.sh` has no unreachable-DB case (L-1) |
| **Bad `ALLOW_NO_LOGIN` accepted** (`*)` starts) | `maybe` → app starts | `docker-signin-checks.sh` case 2 (expects 64; it would get the 60 s timeout's 124) in CI's Docker job. No unit test. |
| **Account-exists check dropped** | see above | `AuthAtStartTest` ("on, but no account and no APP_PASSWORD"). The docker script has no such case. |
| Exit-3 branch removed (falls through to the generic 78) | 78 with the wrong text | `docker-signin-checks.sh` case 1 (`grep 'sign-in is off'`, the `password` command, `ALLOW_NO_LOGIN=1`) |
| `-p` dropped from `su` | Still starts: busybox `su` without `-l` keeps the environment | `DockerfileTest` asserts the literal `su -p -s /bin/sh www-data` |
| `auth status` mapped to exit 0 regardless | — | `docker-signin-checks.sh` cases 1 and 5 (expect 78) |

### 2.8 compose, docs and examples

| Check | Result |
|---|---|
| compose files | Only `docker-compose.yml` exists. The app publishes only `"127.0.0.1:9091:9091"` (`DockerfileTest` pins this as the single entry). The `postgres` service publishes nothing. |
| Docs and examples that publish the app | README quick start, getting started (`-p 127.0.0.1:9091:9091`), the deployment section, AGENTS.md: all `127.0.0.1` by default, with `"9091:9091"` / `"192.0.2.10:9091:9091"`-style lines only as the deliberate opt-in. CI and `docker-heic.sh` publish `9091:9091` on throwaway runners, with `ALLOW_NO_LOGIN=1`. |
| Leftovers | The 1.x upgrade notes (README `ports: ["8080:9091"]` and `-p 9091:9091` / `-p 8080:9091`, deployment doc `ports: ["8080:9091"]`) still show all-interface examples (N-3). `php -S 0.0.0.0:9091` is the non-Docker dev server and out of scope. |
| Opening it on purpose | README, getting started, the deployment section and the compose comment all explain `"9091:9091"` (all interfaces) or one LAN address, and that a same-host reverse proxy needs no change. Examples use `192.0.2.10` (a documentation range) and `<LAN-IP>`. |
| Upgrade note: `APP_PASSWORD` alone doesn't help on an existing DB | True (§2.4) |
| Upgrade note: the `password` command | Exact command `docker compose run --rm -u www-data apotheke php bin/settings.php password`. `WORKDIR` is `/var/www/app`, so the relative path resolves. The one-off container skips the check. Works (§2.5). |
| Deployment doc: "waits up to 30 s … then exits with code 78 too" | True for errors that come back quickly. A filtered address can wait longer (N-2). |
| Deployment doc: "reads the database without creating, migrating or changing it" | True for SQLite (no file created; sha256 unchanged) and for PG without `DB_SCHEMA`. With a missing `DB_SCHEMA`, the empty schema gets created (N-1). |
| `docs/ci.md`, configuration table, README env table, `config.example.php`, architecture | Match the code |

### 2.9 CHANGELOG gate (PM rule) and the main merge

| Check | Result |
|---|---|
| The head contains main's latest release commit `205ed23` (v2.6.4) | **Yes.** It is the second parent of `df82800`. Main's `#73` merge `a94b76a` is in. |
| `## [2.6.4]` and every older section, plus the link references (520 lines, 36,261 bytes, from `## [2.6.4]` to EOF), compared with `cmp` | **Byte-identical** to main's |
| Header above `[Unreleased]` | Identical |
| New entries | Only under `## [Unreleased]`: the upgrade note first, then `### Fixed` with one bullet linking #75 |
| Link refs | `[Unreleased]: …/compare/v2.6.4...HEAD`, the same as main |
| The expected `[Unreleased]` clash with #73's upgrade note | **Gone.** v2.6.4 moved #73's note into `[2.6.4]`, so #75's note is now the only one under `[Unreleased]` and leads the next release notes (`ReleaseTest` at the head passes). |
| `df82800`'s conflict resolutions | Every code file is byte-identical to `a31268e`. Every #73 file is byte-identical to main. README and `docs/configuration.md` equal a clean three-way merge. `docs/ci.md` keeps main's two #73 changes in the Docker row and adds #75's sentence. The deployment doc keeps main's health-check paragraph, and #75's section and lines are unchanged. Nothing was lost. |

**Gate: PASS.**

**PM conditions for accepting the upgrade break as a `fix`:**
1. *The upgrade note leads `[Unreleased]` (and so the release body) and names both ways out:* **met.** It is the first
   paragraph under `[Unreleased]` and names `docker compose run --rm -u www-data apotheke php bin/settings.php password`
   and `ALLOW_NO_LOGIN=1`.
2. *The exit-78 log prints the same two:* **met since `a31268e`** (§2.2). CI's `docker-signin-checks.sh` checks both
   strings.

## 3. Findings

| ID | Grade | Finding |
|---|---|---|
| L-1 | Low | The fail-closed path for "the database can't be read" isn't covered by any test. If the `rc != 0 → exit 78` line were dropped, a container whose database is unreachable would start the app without the sign-in check, and neither `DockerfileTest` (it only looks for the string `exit 78`) nor `docker-signin-checks.sh` would notice (§2.7). The code is right today (§2.4). Suggestion: a seventh case in `docker-signin-checks.sh`, e.g. `DB_DRIVER=pgsql DB_HOST=127.0.0.1 DB_PORT=1` → 78 with `could not read the sign-in setting`, or a `DockerfileTest` assertion on that line. Not blocking. |
| L-2 | Low | (Not new.) `APP_PASSWORD` isn't held to the 8-character minimum (`PASSWORD_MIN`). `auth_enabled()` and the 0018 migration hash whatever is set, so `APP_PASSWORD=x`, or spaces only, becomes the admin password (verified: a one-character password signs in). The new check, correctly, gives the same answer as the app, so SEC-M3's "requires an app password" is met by a trivial one. Suggestion for a follow-up: refuse, or log a warning for, a non-empty `APP_PASSWORD` shorter than `PASSWORD_MIN` (or with only whitespace) at start and at seeding. Not blocking. |
| N-1 | Nit | "Reads the database without creating, migrating or changing it" is slightly broad. With `DB_SCHEMA` naming a missing schema, `db_connect()` creates the empty schema during `auth status` (verified). On SQLite, `db_connect()` sets `journal_mode = WAL`, a header write when an older file isn't already in WAL mode. Both are harmless (the app does the same a moment later). The wording could say "without migrating or seeding it". |
| N-2 | Nit | "Waits up to 30 s" holds only for errors that come back quickly (refused, bad password: 31 s measured). For an address that never answers, each attempt waits for the TCP connect timeout, because no `connect_timeout` is set, so the container can sit for a long time before its 78. It still fails closed. Suggestion: `connect_timeout=5` for the check, or a line in the docs. |
| N-3 | Nit | The 1.x upgrade notes still show all-interface publishes: README `ports: ["8080:9091"]` and "`-p 9091:9091` (or `-p 8080:9091`)", and the deployment doc's `ports: ["8080:9091"]`. Suggestion: `127.0.0.1:8080:9091` and so on, or a pointer to the new section. |
| I-1 | Info | Both PM conditions are met (§2.9), and the PR body carries the SEC-M3 two-changes label note. |
| I-2 | Info | Coder's CLI guest bug in `actor_is_admin()` is not on #75's path: every exit-78 state has sign-in effectively off, so `password` runs as the anonymous admin (§2.5). #75 does not depend on the upcoming `fix(auth)` PR. Until it lands, the deployment doc's "recovery always works" is not true for an install with sign-in **and** guest browsing on (that install starts normally and never needs this way out). |
| I-3 | Info | (Not new.) `password` reads the new password from STDIN with visible echo. It works under `docker compose run` as written (TTY by default) and through a pipe. |
| I-4 | Info | `ALLOW_NO_LOGIN` is case-sensitive and exact: `TRUE`, `Yes` and ` 1` exit 64, as do `on` and `2`. This fails closed, the docs list the exact values, and the error message names them. |
| I-5 | Info | The `catch` on the `users` query (`$users = 0`) has no test (that mutant survives). The branch is reachable only with a `settings` table but no `users` table, i.e. a database stopped mid-migration, and the current value fails closed. |
| I-6 | Info | The upgrade break (an existing no-login Docker install stops starting) is a product decision the PM accepted as a `fix`. It is documented in the CHANGELOG upgrade note, the deployment doc ("Upgrading an install that runs without sign-in"), the README, getting started, the compose comment and the exit-78 log. Not a code defect. |
| I-7 | Info | CI at `f182cf1` failed on Visual E2E shard 1/3 only. That commit added only a QA doc to a green head, and the same shard is green at `c5b6c39`. Most likely a flake; no code was involved. |
| I-8 | Info | With `restart: unless-stopped`, a refused container restarts in a loop with Docker's back-off, and each PG attempt logs the connect line (31 per start). The lines carry no credentials. This is how a refusal should look, and the docs point to `docker compose logs`. |
| I-9 | Info | `a1c9027` makes the same `ReleaseTest` change as #73's `959d0e1`. After the main merge the file is identical to main's, with no conflict. |

## 4. Security

- **Exposure:** the shipped compose file now publishes the app on `127.0.0.1` only. Opening it to a network is an
  explicit, documented edit. No compose service or doc example publishes on all interfaces by default (N-3 covers
  the old 1.x notes).
- **Sign-in at start:** the entrypoint refuses to start nginx and php-fpm unless the app's own logic says sign-in will
  be on, or the operator sets `ALLOW_NO_LOGIN` to an exact opt-in value. The check runs as `www-data`, uses only
  `SELECT`s, and gives the same answer as `auth_enabled()` in every combination tested (§2.4), including no account,
  a pending non-admin account, sign-in off with `APP_PASSWORD`, and empty, missing or whitespace passwords. It fails
  closed on an unreadable database (78), an unknown opt-out value (64) and any unexpected PHP failure (retried, then
  78). Its tests have one gap, the unreadable-database branch (L-1). L-2 is an older weakness in how `APP_PASSWORD`
  is accepted, not a way around the check.
- **Recovery:** the documented way out runs outside the check, works in every state that reaches exit 78, and doesn't
  need the pending CLI guest fix (§2.5).
- **Logs:** no password or DSN secret is printed in any tested path. The connect-failure line shows only the driver,
  host, port and database name.
- **Diff contents:** no secrets, tokens, account IDs or real host names. Examples use `change-me`, `192.0.2.10`,
  `<LAN-IP>` and `127.0.0.1`. CI's Secret scan job is green at `c5b6c39`. The QA runs used random throwaway passwords,
  which aren't recorded here.

## 5. Verdict

**PASS** for `c5b6c39ce47cb60b9d0a00f3c930dabbc2c55da0`: 0 High, 0 Medium, 2 Low (L-1, L-2), 3 Nits, 9 Info.

- **CI:** run 40089238822, 10/10 `success` at the pinned head.
- **CHANGELOG gate:** passed against v2.6.4. `[2.6.4]` and older are byte-identical, and the single new note and
  bullet are under `[Unreleased]`.
- **PM conditions:** both met. The note leads `[Unreleased]` with both ways out, and so does the exit-78 log.
- **Tests:** green on PHP 8.3 and 8.4 with SQLite and PostgreSQL 17. The real entrypoint check behaved correctly in
  every start case in §2.4.
- **Mutations:** every mutant on the decision logic is killed except two: L-1 (the unreadable-database branch) and
  I-5.
- **Next:** L-1 is a small test addition, and L-2 fits a follow-up. Neither blocks the merge. #75 does not depend on
  Coder's `fix(auth)` PR.
