# QA: apotheke #81, SEC-M3 follow-up hardening and release notes

- **PR:** [#81](https://github.com/thordin9/apotheke/pull/81), `sec/signin-followups` into `main`, by DevOps
- **Head pinned:** `56c8300cfa5fe2561e394e4021485cab5a8fbf4d` (20 commits, one merge of main v2.6.9). Fully
  reviewed at `f8b6c1ed5b8092712f41c8be634fc30de90a4bcd`; the five commits after it are re-checked in §2.12.
- **Base:** `main` at `3f729a3` (`chore(release): v2.6.9 [skip ci]`), merged in by `bd09656`. The full review ran
  against `c4e9eda` (v2.6.7).
- **Scope:** follow-ups from #75 QA (L-1, L-2, N-1..N-3), #73 QA (L-1, N-1, N-2), v2.6.2 release QA (L-1, N-4),
  v2.6.5 release QA L-1 (release generator) and v2.6.7 release QA I-3 (coverage)
- **Verdict: PASS.** 0 High, 0 Medium, 0 Low open (L-1 fixed and verified), 7 Nits, 9 Info.

## 1. Test plan

1. Diff review of all 23 files against `c4e9eda`. Commit subjects (Conventional Commits, 72 characters at most).
2. The `APP_PASSWORD` rule, run for real: `bin/settings.php auth status`, `bin/migrate.php`, the first-account path
   and the `password` recovery command, on SQLite and PostgreSQL 17, at every boundary in §2.4.
3. The real entrypoint check (the head's `docker/entrypoint.sh` up to the line before `PORT=`, then a `STARTED`
   marker) in a chroot of the image's base (`php:8.3.36-fpm-alpine`, busybox `sh`/`su`, `www-data` 82), mounted
   through a private overlay so the shared rootfs was not changed.
4. PHPUnit and `tests/integration.php` on PHP 8.3 and 8.4, SQLite and PostgreSQL 17. Line coverage with pcov at the
   head and at `c4e9eda`.
5. Mutations: 19 on PHP, shell and nginx config against PHPUnit, plus 4 entrypoint mutants run in the chroot.
6. The release generator: a local merge of #81 onto `c4e9eda` run through `release.php plan` and `apply`, and a
   replay of the v2.6.5 release with the new generator.
7. nginx 1.26 (Debian package, unpacked and run unprivileged, not installed) with the head's
   `@restore_check_failed` block and a stub upstream for the restore check.
8. CHANGELOG gate against `c4e9eda`. Docs read against the code. `check-links`, `check-commits.sh`, `shellcheck`,
   `php -l`. PR body read.
9. CI through the GitHub API.
10. Re-check at `56c8300` (§2.12): the L-1 fix and its test, mutants, the merge diff read against v2.6.9, the
    CHANGELOG gate, the v2.6.9 release doc hash and CI.

**Not run here:** Docker itself, so `scripts/docker-signin-checks.sh`, `scripts/docker-auth-checks.sh`,
`scripts/docker-heic.sh` and the Playwright suites ran in CI only (§2.1). Items 3 and 7 cover the same logic without
Docker.

## 2. Results

### 2.1 CI

| Head | Run | Result |
|---|---|---|
| `f8b6c1e` (pinned) | 40095907948 (`pull_request`) | **`completed` / `success`, 10/10 jobs**, 15:54:58 to 16:04:19 (Athens, UTC+3) |

Jobs: Lint, Tests (PHP 8.3, sqlite), Tests (PHP 8.3, pgsql), Headless mobile checks, Visual E2E 1/3, 2/3 and 3/3,
Docker build + smoke test, Conventional Commits, Secret scan. From the Docker job's log:

- **docker-signin-checks:** all 8 cases ok, including the two new ones. "APP_PASSWORD shorter than 8 characters" exits
  78 without the value. "The database can't be read" exits 78 after about 31 s (connection refused, 30 retries).
- **ImageMagick policy check**, at build time and from `docker-heic.sh`: `ok delegate rules have rights None (1
  rule(s))` and `ok path rules have rights None (1 rule(s))`.
- **docker-auth-checks:** `ok its 421 body is the app's`, along with the other cases.
- **Tests (PHP 8.3, pgsql):** 437 tests, 3,216 assertions, 1 skipped.

### 2.2 Diff and commits

Against `c4e9eda`, the head changes 23 files (+363/−61): `bin/migrate.php`, `bin/settings.php`,
`docker/entrypoint.sh`, `docker/nginx.conf`, `scripts/release.php`, `scripts/imagick-policy-check.php`, the two
docker check scripts, `src/db.php`, `src/settings.php`, `src/users.php`, the new `AppPasswordTest` and
`ImagickPolicyCheckTest`, `AuthAtStartTest`, `DockerfileTest`, `ReleaseTest`, two integration files, one visual
spec, CHANGELOG, README and three docs pages.

All 15 subjects are Conventional Commits, 46 to 69 characters long (`check-commits.sh c4e9eda f8b6c1e`: 15 checked,
ok). There are 5 `fix:` commits (release, auth, and three docker) and 10 `test:`/`docs:` commits. History is linear,
with no merges and no rewrites. `shellcheck` is clean on the entrypoint and both docker scripts, and `php -l` is
clean on every changed PHP file.

### 2.3 How the `APP_PASSWORD` rule works (code read)

- `app_password_problem()` is null when the value is empty, or when `trim()` leaves something and `mb_strlen()` is at
  least `PASSWORD_MIN` (8). Otherwise it returns a fixed message with no value in it. The parameter is
  `#[\SensitiveParameter]`.
- **Start check (`auth status`):** it exits 4 only when `APP_PASSWORD` would change the answer
  (`auth_on_at_start($pdo, $pw) !== auth_on_at_start($pdo, '')`) and the value fails the rule. If an account exists
  with sign-in on, or sign-in is stored off, `APP_PASSWORD` is never used, so a short value is ignored. The
  entrypoint stops retrying on 4 and exits 78. PHP's own stderr line is the message.
- **Seeding:** `settings_seed()` calls `app_password_require()` only while the `password_hash` row doesn't exist yet,
  which means migration 0013 on a new or legacy database. That row is where the hash comes from. `bin/migrate.php`
  catches `AppPasswordError`, prints the one line and exits 1. The migration transaction rolls back.
- **First account:** `auth_enabled()` calls `app_password_require()` just before it inserts `admin`, after the
  `users_count() > 0` return.
- **Timeout:** `auth status` sets `connect_timeout = 5`, and `db_connect()` passes it as `PDO::ATTR_TIMEOUT`. The
  app's own connections are unchanged.

### 2.4 `APP_PASSWORD` boundaries (PHP 8.4, SQLite, new data dir each time)

| Value | `auth status` | `migrate` | Account `admin` |
|---|---|---|---|
| unset / empty | 3 | 0 | none (sign-in off) |
| 1 character, 7 ASCII | **4** | **1** | none (no tables left) |
| 8 ASCII | 0 | 0 | created |
| 12 spaces / 8 tabs / 8 newlines / mixed blanks (10) | **4** | **1** | none |
| 7 × `ä` (7 characters, 14 bytes) | **4** | **1** | none |
| 4 × `ä` (8 bytes) / 2 emoji (8 bytes) | **4** | **1** | none (characters count, not bytes) |
| 8 × `ä` (16 bytes) | 0 | 0 | created |
| `' abcdef '`, `'   ab   '`, `'Abc-xy7 '` (8 characters with spaces) | 0 | 0 | created (spaces count toward the length, and the value is stored as given) |
| 8 × U+00A0 (no-break space) | 0 | 0 | created (N-2) |
| 8 invalid UTF-8 bytes | 0 | 0 | created |

`auth status` never created `catalog.sqlite`. A refused `migrate` leaves an empty 4 KB file and no tables (I-6).

**Existing installs (SQLite):**

| State | Short `APP_PASSWORD` |
|---|---|
| Migrated with a long password (account exists) | `auth status` 0, `migrate` "Already up to date", `users` works |
| Sign-in on, accounts deleted | `auth status` **4**. Empty `APP_PASSWORD`: 3 |
| Sign-in stored off | `auth status` 3 (the value doesn't matter) |
| `password` recovery, sign-in on, no account | **PHP fatal `Uncaught AppPasswordError`, exit 255** (L-1). It works once `APP_PASSWORD` is emptied. |
| `password` on a new data dir | The same fatal, from `settings_seed()` |

**PostgreSQL 17:** on a new database, `auth status` exits **4** with 0 tables before and after. `migrate` exits 1 and
leaves 0 tables. With `DB_SCHEMA` naming a missing schema, `auth status` also exits 4.

**The value is never printed.** Across every run above, and with `zend.exception_ignore_args=0`, neither stdout nor
stderr contained the value. The fatal traces in L-1 show `app_password_require()` with no arguments and
`user_create('...', '...', '...')`.

### 2.5 The real entrypoint (chroot, PHP 8.3.36 Alpine)

| Case | Exit | Notes |
|---|---|---|
| SQLite new, unset | **78** | Both "sign-in is off" lines |
| `APP_PASSWORD` 7 ASCII / 10 spaces / 8 tabs / `ääää` (4 characters, 8 bytes) | **78** | `APP_PASSWORD is too short …` and nothing else. About 40 ms, with no retries. |
| 8 ASCII / 8 × `ä` | 0, started | |
| 7 ASCII with `ALLOW_NO_LOGIN=1` | 0, started | The check is skipped. On the first request the app refuses to seed (500, I-8). |
| Existing DB with an account, then 7 ASCII / empty | 0, started / 0, started | |
| PG new database, 7 ASCII | **78** | |
| PG connection refused (port 1) | **78 after 30.8 s** | `could not read the sign-in setting from the database (exit 1)` (#75 L-1 case) |
| PG non-routable address (no reply) | **78 after 186 s** | 31 tries of about 5 s each, plus 30 × 1 s of sleep. The docs say "up to about 3 minutes". |

**One try against a non-routable address:** 5.06 s at the head and 30.08 s at `c4e9eda`. pdo_pgsql's default timeout
is 30 s, so before this PR the worst case was about 16 minutes, not unbounded (I-3).

### 2.6 Test suites

| Leg | PHPUnit | `tests/integration.php` |
|---|---|---|
| PHP 8.3, SQLite | **437 tests**, 3,205 assertions, 2 skipped | **1,538 passed**, 0 failed |
| PHP 8.4, SQLite | **437 tests**, 3,205 assertions, 2 skipped | **1,538 passed**, 0 failed |
| PHP 8.3, PostgreSQL 17 | **437 tests**, 3,206 assertions, 2 skipped | 1,247 passed, 0 failed |
| PHP 8.4, PostgreSQL 17 | **437 tests**, 3,206 assertions, 2 skipped | 1,247 passed, 0 failed |

- **Skips:** `OriginalsTest::testPhpCgiRefusalHidesThePathAndDoesNotNeedStderr` (no `php-cgi` here) and
  `ImageDecodeTest::testHeicDecoderMemoryLimitIsTheDimensionError` (this machine's libheif). Both are environmental
  and predate the PR.
- **PHPUnit includes the HTTP suite** (`HttpSuiteTest`), so each leg also ran the HTTP integration under its driver.
- **PG integration** runs fewer checks than SQLite by design (the legacy SQLite upgrade sections are skipped), the
  same as in #75 QA.
- **New tests:** `AppPasswordTest` (5), `AuthAtStartTest::testAFailingAccountQueryCountsAsNoAccount`,
  `DockerfileTest::testNginx421HasTheAppsText` plus the new exit-78 regex, `ImagickPolicyCheckTest` (3), and
  `ReleaseTest::testHandwrittenFixedBulletReplacesThatPrsGeneratedFixLines` all pass on every leg.
- **Fixtures:** the integration and visual `APP_PASSWORD` values are now at least 8 characters (`s3cret-pw`,
  `pw-visual`). Nothing shorter is left in tests, CI, compose or the docs (`change-me` is 9).

### 2.7 Mutations

**PHPUnit** (`AppPasswordTest`, `AuthAtStartTest`, `DockerfileTest`, `ReleaseTest`, `ImagickPolicyCheckTest`, or the
whole suite where marked):

| # | Mutation | Result |
|---|---|---|
| M1 | Minimum 8 → 7 | **killed** |
| M2 | `mb_strlen` → `strlen` (bytes) | survives (N-1) |
| M3 | Whitespace-only accepted (the `trim` test dropped) | **killed** |
| M3b | `trim($pw, ' ')` (tabs and newlines accepted) | **killed** |
| M4 | Existing-account bypass dropped (short value always refused) | **killed** |
| M5 | Start-check refusal removed | **killed** |
| M5b | `exit(4)` → `exit(0)` | **killed** |
| M6 | Seed guard removed | **killed** |
| M7 | First-account guard in `auth_enabled()` removed | **killed** |
| M8 | `connect_timeout = 5` removed from `auth status` (whole suite) | survives (N-3) |
| M9 | `PDO::ATTR_TIMEOUT` removed from `db_connect()` (whole suite) | survives (N-3) |
| M10 | Entrypoint `rc != 0 → exit 78` line removed | **killed** (`DockerfileTest`) |
| M13 | Entrypoint: rc 4 starts the app | survives in PHPUnit. CI's docker-signin case expects 78 (I-7). |
| M14 | Generator: PR dedupe call removed | **killed** |
| M15 | Generator: drops every PR's fix lines | **killed** |
| M16 | Generator: PR dedupe in every section, not only Fixed | survives (N-4) |
| M17 | Policy check: a narrower allow rule ignored | **killed** (2 failures) |
| M18 | nginx 421 text changed | **killed** |
| M19 | A failing `users` query counts as 1 account (#75 I-5's survivor) | **killed** |

**Entrypoint in the chroot** (#75 QA L-1: "remove the line and confirm the check fails"):

| Mutant | Behaviour | Caught by |
|---|---|---|
| M10 (the line removed) | PG refused → **exit 0, app starts** (baseline: 78) | `DockerfileTest`. docker-signin's new case would also fail: `refused 78` gets the 60 s timeout's 124. |
| M13 (rc 4 → start) | Short password → **app starts** | docker-signin's L-2 case (CI only) |
| M11 (no early break on 4) | Still 78, but after 31 s | Nothing. It's slower, with the same outcome. |
| M12 (the `rc = 4 → exit 78` line removed) | Still 78, plus a misleading "could not read … (exit 4)" line | Nothing. The outcome is the same. |

### 2.8 Release generator

- **Code:** under `### Fixed` only, `rel_drop_pr_generated()` collects the PR numbers of handwritten bullets that end
  in exactly one `([#N](…/pull/N))` link. It drops generated lines that end in a link to the same PR. Lines for
  other PRs stay (M15 is killed). With no handwritten Fixed bullet, or none with a single trailing link, nothing is
  dropped. A bullet with two links (`[#75], [#81]`) is not matched, so it is conservative.
- **#81's own release**, simulated with a local `Merge pull request #81` onto `c4e9eda`: `plan` gives `bump=patch`,
  `next=2.6.8`, 15 commits. The notes open with the upgrade note. `### Fixed` has exactly the three handwritten
  bullets, and all five generated `fix:` lines are gone. Every generated line in the PR is covered by a handwritten
  bullet's scope anyway (auth, docker, release). `### Maintenance` lists the 10 generated `docs`/`test` lines plus
  the handwritten `docs:` bullet (I-2).
- **v2.6.5 replay:** at `v2.6.5^`, the old generator writes `- **autofill:** refuse an over-limit photo on suggest
  ([#72](…))` next to the three handwritten `images:` bullets. The new one drops it, so v2.6.5 QA L-1 is fixed.

### 2.9 #73 QA, v2.6.2 QA and #75 QA docs items

| Item | Result |
|---|---|
| #73 L-1 (health checks "follow the same rule") | The deployment doc's upgrade notes and Host names section now say "the Host rule from 2.6.2" with a working `#host-names` link. Released CHANGELOG sections are untouched. |
| #73 N-1 (policy check) | Every `delegate`/`path` rule must have rights `None`, and the blanket `delegate *` / `path @*` rules must exist. A rule without a `rights` line counts as allowing, so it fails closed. A local run with an added `path @/tmp/* Read` rule prints `FAIL path rules have rights None (allowed: @/tmp/* Read)` and exits 1. The shipped image passes (CI). |
| #73 N-2 (nginx 421) | nginx 1.26 with the head's block: **421, `text/plain; charset=utf-8`, `Cache-Control: no-store`, 99-byte body byte-identical to `src/hosts.php`'s text plus `\n`**. An upstream 503 still gives 500, and 401 still gives 401. No request data appears in the body. CI's docker-auth-checks compares it with the app's 421. |
| v2.6.2 L-1 (`PUBLIC_URL`) | The deployment doc's upgrade note, Host names and health sections say `PUBLIC_URL` only applies on a new install, and an existing one uses Control Panel → General. Two table rows still use the old wording (N-5). |
| v2.6.2 N-4 (`/health`) | It shows `{"status":"ok","version":"X.Y.Z"}` and says the value comes from `VERSION` |
| #75 N-1 (wording) | `bin/settings.php` comment, `docs/configuration.md` and the deployment doc say "without migrating or seeding", and mention the empty `DB_SCHEMA` and the WAL switch. No "without creating … or changing" is left. |
| #75 N-2 (timeout) | 5 s per try, measured (§2.5). The doc says "30 tries, 1 s apart … up to about 3 minutes", which matches the measured 186 s. |
| #75 N-3 (1.x examples) | README and deployment doc now say `127.0.0.1:8080:9091` / `-p 127.0.0.1:9091:9091`, with a link to Docker defaults. All-interface examples remain only where the text opens the port on purpose. |
| `check-links.mjs` | 205 relative links in 16 files, 0 broken |

### 2.10 v2.6.7 QA I-3 (coverage), measured with pcov on PHP 8.4

| `src/users.php` + `src/roles.php` (lines) | Unit | HTTP | Combined |
|---|---|---|---|
| `c4e9eda` | 93.6 % | 89.9 % | 96.4 % |
| `f8b6c1e` | 94.1 % | 89.3 % | **96.9 %** |

- **The explanation holds.** Over HTTP, the uncovered lines in `src/users.php` include all 13 statements of
  `auth_on_at_start()` (lines 207–223). Its only caller is `bin/settings.php auth status`, which runs from the
  entrypoint.
- **Correction to my own wording.** The v2.6.7 doc called the 94.9 → 89.9 drop "branch coverage". It is the HTTP
  integration line-coverage column.
- **The failing-account-query catch** (lines 220–221) is now covered by the unit suite. M19 is killed.
- **Subprocess-only path.** `auth_enabled()`'s first-account lines (193–196) are covered by neither in-process suite.
  They are exercised in a subprocess by `AppPasswordTest` (M7 is killed), as the PR body says.
- **Small difference from the PR body.** The PR body gives unit coverage as 94.0 %; I measured 94.1 %.

### 2.11 CHANGELOG gate (PM rule)

| Check | Result |
|---|---|
| `## [2.6.7]` to EOF (571 lines, 40,876 bytes) vs `c4e9eda`, `cmp` | **Byte-identical** |
| Header above `[Unreleased]` | Identical |
| New entries | Only under `## [Unreleased]`: the upgrade note first, then `### Fixed` (auth, docker, release) and `### Maintenance` (docs). All 4 bullets link [#81]. |
| `release.php check-links` | Exit 0 |
| `[Unreleased]:` link | `compare/v2.6.7...HEAD`, unchanged |

**Gate: PASS.** Re-run at `56c8300` against v2.6.9 in §2.12.

### 2.12 Re-check at `56c8300`

**Commits after `f8b6c1e`:**

| Commit | Subject | What it does |
|---|---|---|
| `5f3eec8` | `fix(security): handle a short APP_PASSWORD in the settings CLI` | `bin/settings.php`: `catch (UserError $e)` becomes `catch (UserError\|AppPasswordError $e)` (message to stderr, exit 1). The #81 auth bullet now names `bin/settings.php` (exit 1, no stack trace). |
| `63390cd` | `test(auth): settings CLI exits 1 on a short APP_PASSWORD` | `AppPasswordTest::testTheSettingsCliRefusesAShortPasswordWithoutAFatalError`: `password <value>` and `list` with a 6-character `APP_PASSWORD` exit 1, stderr has the too-short message, and the output has no value, `Stack trace`, `#0 `, `Fatal error` or `Uncaught`. |
| `bd09656` | merge of `origin/main` (`3f729a3`, v2.6.9) | Only `CHANGELOG.md` conflicted (`git diff-tree --cc` lists only that file). |
| `8f58790` | `test(auth): give the idle-session fixture an 8-character password` | `tests/integration.php`, 2 lines (+2/−2): #80's "Idle sign-in" fixture `s3cret` becomes `s3cret-pw`, in `$idleBoot` and in `$idleSign`'s login post. Nothing else. |
| `56c8300` | `docs(qa): add v2.6.9 release QA` | Adds only `docs/qa/apotheke-v2.6.9-release-qa.md`. |

**The L-1 fix, run for real** (PHP 8.4, SQLite, a new data dir each time, `zend.exception_ignore_args=0`, random
throwaway values):

| State | Command, short `APP_PASSWORD` | Result |
|---|---|---|
| New data dir | `password <new>`, `password` (prompt), `password --off`, `list`, `users`, `get site_name` | **exit 1**, only `APP_PASSWORD is too short: use at least 8 characters (not only spaces), or leave it empty.` No trace, no `Fatal`/`Uncaught`. `catalog.sqlite` has 0 tables (I-6). |
| Sign-in on, accounts deleted | `auth status` / `password <new>` / `list` | 4 / **1** / **1**, 0 accounts afterwards. `list` prints the settings lines before the message (I-9). |
| The same, `APP_PASSWORD` emptied | `password <new>` | 0, `admin` created, `auth status` 0 (the documented way out still works) |
| Account exists | `auth status`, `list`, `users` | 0, normal output (I-4 unchanged) |
| Sign-in stored off | `list` / `auth status` | 0 / 3 |
| Valid long value, new data dir | `list`, `password <new>`, `auth status`, `users` | 0 each, `admin` created, sign-in on |

Neither the short nor the long value appeared in stdout or stderr in any run. Before the fix, the same
commands ended in `Uncaught AppPasswordError` with exit 255 (§2.4).

**Mutants on the delta:**

| # | Mutation | Result |
|---|---|---|
| M20 | Catch reverted to `UserError` only | **killed** (`testTheSettingsCliRefusesAShortPasswordWithoutAFatalError` fails) |
| M21 | Catch narrowed to `AppPasswordError` only | survives `AppPasswordTest`, `AuthAtStartTest`, `DbConnectErrorTest` and the whole `tests/integration.php` (1,579 passed). By hand, `set no_such_key 1` then ends in `Uncaught UserError` with exit 255 (N-7). |

**Tests at `56c8300`:**

| Run | Result |
|---|---|
| `AppPasswordTest` + `AuthAtStartTest`, PHP 8.3 and 8.4, SQLite | **10 tests, 70 assertions, OK** on each (`AppPasswordTest` is now 6) |
| Full PHPUnit, PHP 8.4, SQLite (includes `HttpSuiteTest`) | **447 tests**, 3,271 assertions, OK. 12 skipped: the 2 environmental ones from §2.6 and 10 PostgreSQL-only tests (no PostgreSQL configured for that run) |
| Those PostgreSQL-only tests (`BackupTest`, `PgsqlSchemaCreateTest`) against a throwaway PostgreSQL 17 database | 28 tests, 110 assertions, OK. The role and database were dropped afterwards. |
| `tests/integration.php`, PHP 8.3 and 8.4, SQLite | **1,579 passed, 0 failed** on each, including all 16 "Idle sign-in" checks that `8f58790` fixes |

The count went from 437 to 447 tests and from 1,538 to 1,579 checks because of v2.6.8 and v2.6.9 (#79, #80)
plus the one new test.

**Merge diff read.** Per file, the changed lines of `3f729a3..8f58790` equal those of `c4e9eda..63390cd` plus
`8f58790`'s, for all 23 files, CHANGELOG included (compared on the `+`/`−` lines of `diff -U0`). As a tree check, I
applied `c4e9eda..63390cd` and `8f58790` to a `3f729a3` worktree: only CHANGELOG needed a 3-way merge, and with
the head's CHANGELOG taken the tree is identical to `8f58790`. The merge adds no code of its own.
`git diff 8f58790 56c8300` is just the one added doc.

**CHANGELOG gate at `56c8300`:**

| Check | Result |
|---|---|
| `## [2.6.9]` to EOF (602 lines, 43,161 bytes, sha256 `7d6c50cf…53a9`) vs `3f729a3`, `cmp` | **Byte-identical** |
| Header above `[Unreleased]` | Identical to `3f729a3` |
| `[Unreleased]` | The upgrade note and the 4 linked #81 bullets, identical to `63390cd`'s |
| `[Unreleased]:` link | `compare/v2.6.9...HEAD`, the same as main |

**Commits.** `check-commits.sh c4e9eda 56c8300`: 34 checked, exit 0 (the release commits are skipped as usual).
The 19 non-merge #81 commits are Conventional Commits of 31 to 69 characters. The merge keeps git's default
subject, which the check skips, as on earlier PRs. No force-push: `f8b6c1e` is an ancestor of the head.

**v2.6.9 release doc** at `56c8300`: `docs/qa/apotheke-v2.6.9-release-qa.md` is 7,443 bytes, sha256
`057c4ddfd91d3a78175958605461e3e6f77e300392ce7be99cd43f833a96ad98`, and `cmp` matches my copy.

**CI at `56c8300`:** run 40100457344 (`pull_request`), **`completed` / `success`, 10/10 jobs**, 16:58:54 to
17:09:20 (Athens, UTC+3). The jobs are the same as in §2.1.

## 3. Findings

| ID | Grade | Finding |
|---|---|---|
| L-1 | Low, **fixed** | **Fixed in `5f3eec8`, tested in `63390cd`, verified at `56c8300` (§2.12):** every command exits 1 with the message only, the value never appears, `APP_PASSWORD` emptied still recovers, and M20 (catch reverted) is killed. Original finding: `bin/settings.php` catches only `UserError`, so `AppPasswordError` ends any command in a PHP fatal with a stack trace (exit 255) while a short `APP_PASSWORD` is still set. That includes the documented way out, `password`, on an install with sign-in on and no account, and every command on a new data dir (§2.4). The first line is still the right message, and no value appears. Nothing is changed, so it fails closed. `bin/migrate.php` already handles it with one line and exit 1. Suggestion: catch `AppPasswordError` next to `UserError` in `bin/settings.php`, and add a test for `password` with a short `APP_PASSWORD`. Not blocking. |
| N-1 | Nit | The rule counts characters, not bytes, but only the accepting side is tested (`ääääääää`). M2 (`strlen`) survives. Suggestion: add `ääää` (4 characters, 8 bytes) to `testProblem` as a refused value. |
| N-2 | Nit | "Not only spaces" is `trim()`, which strips ASCII space, tab, CR, LF, NUL and VT but not Unicode spaces: 8 × U+00A0 is accepted. It's harmless, and stricter than the UI rule (`mb_strlen` only). Either note "spaces, tabs or newlines" or use a Unicode-aware blank check. |
| N-3 | Nit | The 5 s connect timeout has no test. M8 and M9 survive the whole suite and CI, because the docker-signin case uses a refused port, which fails fast. A regression would only bring back 30 s per try, so it still fails closed. Suggestion: a unit test that `auth status` passes `connect_timeout`, or that `db_connect()` sets `PDO::ATTR_TIMEOUT`. |
| N-4 | Nit | The new `ReleaseTest` case doesn't pin "Fixed only". Its Maintenance line for #72 survives only because there is no handwritten Maintenance bullet, so M16 (dedupe in every section) survives. Suggestion: add a handwritten Maintenance bullet linking #72 to the test and assert that the generated `test:` line stays. |
| N-5 | Nit | Two rows still say `PUBLIC_URL`'s host is always accepted: the README env table (`ALLOWED_HOSTS`) and `docs/configuration.md` (`allowed_hosts`). On an existing install, the address is the Control Panel's Public URL (v2.6.2 L-1). The app's and nginx's 421 text "(or PUBLIC_URL)" has the same nuance, and is now pinned identical in both places. |
| N-6 | Nit | `AppPasswordTest::tearDown()` deletes only top-level files, so each run leaves 4 data dirs (with `images/`, `originals/`, `sessions/`, `thumbs/`) in the system temp dir. This review's runs left 116, which I removed. Suggestion: delete recursively. |
| N-7 | Nit | **New at the re-check.** The `UserError` side of `bin/settings.php`'s catch has no exit-code test. M21 (catch only `AppPasswordError`) survives PHPUnit and the HTTP suite. Then a plain user error such as `set no_such_key 1` ends in `Uncaught UserError`, exit 255 and a stack trace. The integration CLI helpers use `shell_exec()`, which drops the exit code and still finds the message in the fatal's text. The gap predates #81. Suggestion: in the new test, also assert that `set no_such_key 1` exits 1 without a trace. |
| I-1 | Info | **All claims verified.** #75 L-1 (case plus `DockerfileTest`, with the mutant shown to start the app), #75 L-2 (start 78, migrate 1, seed and first account refused, value never printed, existing accounts unaffected), N-1..N-3, the generator, #73 L-1/N-1/N-2, v2.6.2 L-1/N-4 and v2.6.7 I-3, with the small exceptions in N-5 and §2.10. |
| I-2 | Info | **The generator drops all of a PR's generated `fix:` lines** once one handwritten Fixed bullet links that PR, including fixes the bullet doesn't describe. Authors must cover every `fix:` commit by hand. #81 does. Maintenance still repeats docs changes (generated lines plus a handwritten `docs:` bullet), the older scope rule from v2.6.2 N-2. |
| I-3 | Info | **The old timeout was bounded.** pdo_pgsql already used a 30 s connect timeout before this PR (measured 30.08 s per try), so the old worst case was about 16 minutes, not unbounded as #75 N-2 implied. It is now 186 s, measured. A host name with several addresses can take 5 s per address. |
| I-4 | Info | **Existing-account bypass.** The short-value exemption applies only when `APP_PASSWORD` can't become a password: an account exists with sign-in on, or sign-in is stored off. In those states `auth_enabled()` returns before it reads the value, and seeding skips the hash once the row exists. Whoever can write the account table already controls every account, so the exemption gives them nothing. A failing `users` query counts as no account, so the rule applies and it fails closed (M19 is killed). |
| I-5 | Info | **Legacy installs.** A pre-v1.0 SQLite install adopted by `migrate` has no account and no `password_hash` row. With a short `APP_PASSWORD`, it is refused at migration 0013 like a new install. That matches the upgrade note ("an install that already has an account is unaffected"). |
| I-6 | Info | **Leftovers from a refused `migrate`.** It prints `applied 0001 … 0012` before the refusal, even though the transaction rolls them back, and it leaves an empty 4 KB `catalog.sqlite`. The next `auth status` still exits 4, and a valid `APP_PASSWORD` then migrates normally. The log wording predates this PR. |
| I-7 | Info | **What CI catches in the entrypoint.** The rc-4 branch is guarded in CI only: M13, where rc 4 starts the app, is caught by docker-signin's L-2 case and not by `DockerfileTest`. M11 and M12 keep exit 78 and only change timing or add a log line. |
| I-8 | Info | **`ALLOW_NO_LOGIN=1` plus a short `APP_PASSWORD`** on a new install: the container starts, the first request's seeding refuses, the app answers 500 and the message is logged. No account with the short value is created. The PR body's Risks section lists this. |
| I-9 | Info | **`list` with a short `APP_PASSWORD` after the accounts are gone** prints the settings lines (from `settings_export()`, the same ones it prints without `APP_PASSWORD`) and then exits 1 with the message, because `auth_enabled()` runs after the loop. No secret or value is printed. It's a cosmetic partial output that predates #81 (it used to end in the fatal). |

## 4. Security

- **Password minimum.** `APP_PASSWORD` can no longer become a password under 8 characters, or one made only of ASCII
  blanks. That holds on every path that turns it into a password: Docker start, CLI `migrate`, first-request seeding
  and the first `admin` account. It is verified on SQLite and PostgreSQL 17 (§2.4, §2.5) and guarded by killed
  mutants M1, M3–M7. The one gap is Unicode spaces (N-2), which still need 8 characters, so they are not a bypass.
- **Timing.** The check compares no secrets. It is a length and blank test on configuration, and the stored value is
  still hashed with `password_hash()`. Timing doesn't matter here.
- **Leaking the value.** The message is a fixed string, the parameter is `#[\SensitiveParameter]`, the fatal traces
  carry no arguments, and the entrypoint adds no variables. Across all runs, including traces and
  `exception_ignore_args=0`, the value never appeared in stdout, stderr or logs. CI's docker case also greps for it.
- **Existing-account exemption.** It is not abusable (I-4). It skips the rule only in states where the value is
  never used, and it fails closed when the account query fails.
- **Start check.** It still fails closed. An unreadable database gives 78 (now tested, M10 killed), a short value
  gives 78 with no retries, and a non-answering PostgreSQL gives 78 within about 3 minutes.
- **nginx.** The 421 body is a fixed string with `no-store`. Nothing from the request is echoed. Status handling for
  401, 403 and other failures is unchanged (§2.9).
- **ImageMagick policy check.** It is stricter: any delegate or `@file` allow rule now fails the build, and a missing
  rights field fails closed.
- **Diff contents.** No secrets, tokens or real host names. Examples use `change-me`, `192.0.2.10`, `<LAN-IP>` and
  `127.0.0.1`, and test passwords are fixtures. CI's Secret scan is green. This review used random throwaway
  passwords and databases, which are not recorded here and were removed.

## 5. Verdict

**PASS** for `56c8300cfa5fe2561e394e4021485cab5a8fbf4d`: 0 High, 0 Medium, 0 Low open (L-1 fixed and verified),
7 Nits (N-1 to N-7), 9 Info.

- **L-1:** fixed in `5f3eec8` with a subprocess test in `63390cd`. Run by hand on new and recovery data dirs, every
  command exits 1 with the message only. M20 is killed. The narrowed-catch mutant M21 survives (N-7).
- **Merge:** `3f729a3..8f58790` equals the reviewed delta plus the L-1 commits and `8f58790`, file by file. The only
  conflict was CHANGELOG, and `[2.6.9]` to EOF is byte-identical to v2.6.9. `8f58790` changes only the 2 fixture
  lines, and `56c8300` adds only the v2.6.9 release doc, which matches byte for byte (7,443 B, `057c4ddf…6a98`).
- **CI:** run 40100457344, 10/10 `success` at `56c8300`. At `f8b6c1e` it was run 40095907948, 10/10 `success`,
  including the new docker-signin and docker-auth cases.
- **CHANGELOG gate:** passed against v2.6.7 `c4e9eda`. `[2.6.7]` to EOF is byte-identical, and four linked #81
  bullets plus the upgrade note are under `[Unreleased]`.
- **Tests:** green on PHP 8.3 and 8.4 with SQLite and PostgreSQL 17: 437 PHPUnit tests on each leg, and HTTP
  integration 1,538 (SQLite) and 1,247 (PostgreSQL).
- **Mutations:** 14 of 19 PHPUnit mutants are killed. The survivors are N-1, N-3 (×2), N-4 and M13, which is caught
  in CI's Docker job.
- **Re-check tests (`56c8300`, SQLite):** 447 PHPUnit tests on PHP 8.4. The L-1 and AppPassword tests pass on 8.3
  and 8.4. HTTP integration passes 1,579 checks on 8.3 and 8.4, including #80's Idle sign-in checks.
- **Follow-up:** the Nits (N-1 to N-7) are small and fit a later PR. None blocks the merge. This doc should be
  committed unchanged as the last `docs(qa):` commit on top of `56c8300`, after the v2.6.9 release doc.
