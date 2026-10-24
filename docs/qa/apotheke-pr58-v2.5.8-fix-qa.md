# QA: apotheke PR #58, keep the database user out of connect errors (v2.5.8)

- **PR:** [#58](https://github.com/thordin9/apotheke/pull/58) into `main`, by Coder. Title: `fix(db): keep the database user out of connect errors`. Open, not a draft, mergeable state `clean`.
- **Head reviewed:** `2e71d933304edb167a123c6e8ac3c032a5faceb9`. 9 ahead / 0 behind `main` at `0bf805a` (the v2.5.7 release commit).
- **Commits:** `cedc2aa` fix(db), `a756960` test (JIT), `d60da73` merge of `main`, `3aec981` fix(release), `6c9c91e` docs(changelog), `f421f02` docs (--pull), `5e714ca` test(docker), `f1c2179` ci(docker), `2e71d93` docs(qa).
- **Diff against `main`:** 18 files, +555/-28 (code: `src/db.php`, `src/bootstrap.php`, `public/index.php`, `bin/migrate.php`, `scripts/release.php`, `docker/php.ini`, `.github/workflows/ci.yml`; docs: `CHANGELOG.md`, `docs/configuration.md`, `docs/deployment-and-operations.md`, `docs/getting-started.md`, `docs/releasing.md`, `docs/ci.md`, the v2.5.7 QA doc; tests: `DbConnectErrorTest` (new), `DockerfileTest`, `MigrateDsnTest`, `ReleaseTest`).
- **CI:** run 39914819004 (`pull_request`, attempt 1) on `2e71d93`: **success**, 10/10 jobs, 09:58 to 10:09 EEST.
- **Verdict: PASS.** No high or medium findings. Two lows and five nits, all follow-ups. The v2.5.7 QA doc hash check passes.

## Test plan

1. **Merge and scope.** Diff the head tree against `main` file by file. Check that the merge `d60da73` carries the fix commits unchanged (compare `85c8c64..a756960` with `0bf805a..d60da73`). Look for unrelated changes.
2. **#55 L-1, live.** Local PHP 8.4.27 CLI (`pdo_pgsql`, `pdo_sqlite`) and a throwaway PostgreSQL 17 instance on loopback, with a QA-only role `canaryuser`. Run `bin/seed.php`, `bin/backup.php`, `bin/restore.php --dry-run`, `bin/settings.php list` and `bin/migrate.php` against 6 failure shapes: a wrong password (real libpq `password authentication failed for user …`), a refused port, an unresolvable host, a missing database, a DSN with the user and password embedded, and a set `DB_SCHEMA`. Run each in two ini modes: `zend.exception_ignore_args=0` with `zend.exception_string_param_max_len=1000000`, and `zend.exception_ignore_args=1`. Every run had `log_errors=1`, `display_errors=stderr` and an `error_log` file. Grep stdout, stderr and the log for `canaryuser`, `canarypw`, `password authentication`, `PDOException`, `Stack trace` and `SQLSTATE`. Run the same matrix on `main` as the baseline. Over `php -S`, check `/healthz`, `/health`, `/`, `/?q=`, `/api/items` and the branding routes, on both the head and `main`. Check that the happy path with the correct password and SQLite (migrate twice, seed `--demo`, settings, backup, unopenable `SQLITE_PATH`) is unchanged.
3. **#55 L-2.** Point `DATA_DIR` into a mode-555 directory and run migrate, seed and settings from the CLI and `/healthz` over `php -S`, on the head and on `main`.
4. **JIT fix.** Diff `DbConnectErrorTest.php` at `cedc2aa` (first version) against the head.
5. **Mutation check.** Eight mutants against the new and changed tests.
6. **#57 L-1 / v2.5.7 L-1.** Use a throwaway git repo (no remotes contacted): the `0bf805a` tree tagged `v2.5.7`, then the head tree as one `fix(db):` commit. Run `scripts/release.php plan` and `apply`. Also call `rel_changelog_insert()` directly on edge cases.
7. **#57 N-1..N-3, v2.5.7 N-1.** Docs read-through. Extract the CI pin-assert step and run it with a fake `docker` on 23 Dockerfile cases, next to `DockerfileTest`'s `pinOk()` logic on the same files.
8. **Suites, lint, secrets, commits.** Unit suite on PHP 8.4.27 (SQLite), `php -l` on the changed PHP files, CR check, commit subjects against `scripts/check-commits.sh`'s regex, gitleaks 8.x on the PR diff and the head tree (default rules and the repo `.gitleaks.toml`).
9. **Hash check.** Fetch `docs/qa/apotheke-v2.5.7-release-qa.md` at `2e71d93` from raw.githubusercontent and from the codeload tarball, then `cmp` it against the QA original.

## Results

### 1. Merge and scope: pass

- `0bf805a..d60da73` touches the same 10 files as `85c8c64..a756960`. Every file except `CHANGELOG.md` is byte-identical to `a756960`. `docs/deployment-and-operations.md` differs only by a line offset from main's `--pull` lines, with the same hunks. `CHANGELOG.md` keeps main's 2.5.7 section and adds the three new Unreleased lines. There are no evil-merge edits.
- The commits after the merge contain only what the PR body lists: the release.php fold, the 2.5.7 changelog note, `--pull` in getting-started, the DockerfileTest/CI alignment, the expected-pin message, and the QA doc. The Dockerfile pin is unchanged. No unrelated changes.

### 2. #55 L-1: connect errors in seed, backup, restore, settings and the web: pass

**Head: 60 of 60 CLI runs are clean** (6 shapes × 2 ini modes × 5 scripts).

- Every run exits **1**, and stderr is exactly one line, for example `Could not connect to the database (pgsql – host=127.0.0.1 port=55432 dbname=keepdb).`, or `… dbname=keepdb (schema qa_s)).` when `DB_SCHEMA` is set. The DSN shape shows the parsed host, port and dbname only.
- None of stdout, stderr or the log contains `canaryuser`, `canarypw`, `password authentication`, `PDOException`, `Stack trace` or `SQLSTATE`.
- The unresolvable-host shape needed about 30 s, because the box's resolver maps it to a non-routable address and the TCP connect times out. Rerun without the harness's 30 s cap, it exits 1 with the sanitized line after 30 s. That is the box's environment, not the PR.

**Web, head:** `/healthz` and `/health` return 500 with an empty body. `/`, `/?q=x` and `/api/items` with `Accept: application/json` return 500 `{"ok":false,"error":"Server error"}`. The error log has only `Could not connect to the database (…)` (health routes, through the new exception handler) and `[apotheke] Could not connect to the database (…)` (front controller), in both ini modes. There are no canaries, traces or `SQLSTATE`.

**`main` baseline, web:** the log holds 31 lines for the same requests, including 4× `PDOException` with `password authentication failed for user "canaryuser"`, stack traces, and `PDO->__construct('pgsql:host=…;dbname=keepdb', 'canaryuser', Object(SensitiveParameterValue), …)` with `ignore_args=0`. The CLI on `main` exits 255 with the uncaught exception, as #55 measured.

**Unchanged behaviour (head = `main`):** with correct credentials on PostgreSQL, migrate runs `Done: 25 step(s).`, a second run prints `Already up to date.`, `seed --demo` creates 8 items, `settings list` and `backup` work, and `/healthz` returns `ok` 200. SQLite gives byte-identical migrate, seed and settings output. An unopenable `SQLITE_PATH` still raises `Uncaught PDOException: SQLSTATE[HY000] [14] unable to open database file` and exits 255.

**Behaviour change (L-2):** with PostgreSQL down, `main` served `/theme.css`, `/labels.css` and `/manifest.webmanifest` with 200, `/login` with 303 and unknown paths with 404. That happened because `settings_all()`, `roles` and others `catch (PDOException)` and silently fell back to defaults. On the head, `DatabaseConnectException` is a `RuntimeException`, so it passes those catches and every route returns 500 with the sanitized log line. Nothing leaks, and failing closed is defensible, but it is not documented and not tested.

`docker/php.ini` now sets `zend.exception_ignore_args = On`, which addresses #55 N-1. The `bin/migrate.php` and `MigrateDsnTest` comments are corrected, which addresses #55 N-2. Both docs now say "When it can't connect to the database" and both carry the stack-trace clause, which addresses #55 N-3.

### 3. #55 L-2: data directory not writable: pass

| Tree | migrate / seed / settings | stdout | `/healthz` |
| --- | --- | --- | --- |
| Head | exit **1** | `Data directory …/data is not writable.` | HTTP 500, same text |
| `main` | exit 0 | same text | HTTP 500 |

The message still goes to stdout with no trailing newline (N-2).

### 4. DbConnectErrorTest JIT fix: pass

`diff` between `cedc2aa` and the head shows exactly one added line, `'-d', 'opcache.jit=disable',` in the healthz `php -S` command. Every assertion is unchanged:

- not the user, not the password, no `password authentication`, `Connection refused`, `SQLSTATE`, `Stack trace` or `PDOException` in headers, body, error log or server log;
- status 500 and an empty body;
- the log must match `^\[…\] Could not connect … \n$` exactly.

The CLI tests still assert exit 1, an empty stdout and an exact stderr. CI's two Tests jobs are now green.

### 5. Mutation check: pass, with one gap

| Mutant | Result |
| --- | --- |
| None | OK, 46 tests (DbConnect, Release, Dockerfile, MigrateDsn) |
| M1: `src/db.php` from `main` | 5 failures (4 CLI tools + healthz) |
| M2: catch prints `$e->getMessage()` | 5 failures |
| M3: front controller logs the whole `$e` again (`public/index.php`) | **survives** (L-2) |
| M4: bootstrap `exit(0)` | `testDataDirectoryNotWritableExits1` fails |
| M5: handler logs `(string)$e` | healthz test fails |
| M6: SQLite also caught | `testSqliteOpenErrorStaysUnchanged` fails |
| M7: `scripts/release.php` from `main` | `testChangelogInsertMovesHandWrittenNotes` fails |
| M8: floating second `FROM php` stage added to the Dockerfile | `testFromLinePinsAFullPatchTag` fails |

M3 survives only because no test hits a non-health route with the DB down. A manual check of that path on the head shows only the sanitized line (section 2).

### 6. #57 L-1 / v2.5.7 L-1: release.php moves hand-written notes: pass

What-if (throwaway repo, `v2.5.7` tag, one `fix(db):` commit carrying the head tree): `plan` gives `current=2.5.7 bump=patch next=2.5.8 release=true`. `apply` writes VERSION `2.5.8` and leaves `## [Unreleased]` empty. Under `## [2.5.8] - 2026-10-23` → `### Fixed`, the generated line comes first, followed by the three hand-written lines (db, bootstrap, docker). The compare links are updated.

- `rel_changelog_insert()` on an empty Unreleased behaves as before. Hand-written `### Fixed` lines join the generated `### Fixed`. A new heading such as `### Changed` is appended, and preface text goes under the version heading. This matches `ReleaseTest::testChangelogInsertMovesHandWrittenNotes`. Blank lines inside hand-written notes are dropped (N-1).
- The release notes file (the GitHub release body) is still built from the generated section only: `### Fixed - **db:** keep the database user out of connect errors` plus the changelog link. The hand-written lines are not in it (L-1).
- `CHANGELOG.md` at the head: the PHP 8.3.36 docker line has moved from Unreleased into 2.5.7 `### Fixed`, and 2.5.7 opens with the rebuild note (`docker compose build --pull && docker compose up -d`, then `php -v` shows 8.3.36). Unreleased holds only the three new lines for 2.5.8. `docs/releasing.md` describes the move.

### 7. #57 N-1..N-3 and v2.5.7 N-1: pass

- **N-1:** `docs/getting-started.md` uses `docker build --pull -t apotheke .`.
- **N-2:** in all 23 cases below, the CI step and `DockerfileTest::pinOk()` give the same verdict. The test now reads the pin from the Dockerfile instead of hard-coding 8.3.36.

| Dockerfile | CI step | Test |
| --- | --- | --- |
| `FROM php:8.3.36-fpm-alpine` (also with no trailing newline; also 8.4.27) | pass | pass |
| pin plus a non-php stage (`FROM alpine:3`) | pass | pass |
| trailing space, CRLF, duplicate pin, two different pins, floating only, commented pin plus floating, `@sha256:` digest, `AS base`, empty, ARG-based `FROM` (ci4 style) | fail "does not pin" | fail |
| pin plus a floating `FROM php:8-` / `php:8.3-` / `php:fpm-alpine` stage | fail | fail |
| pin plus a floating stage written as lowercase `from`, indented, `docker.io/library/php:…` or `--platform=… php:…` | pass | pass (N-5) |

- **N-3:** with a pin of 8.3.36 and a built image of 8.3.35, 8.3.37 or 8.3.36RC1, the step prints `PHP_VERSION=…` and then `expected 8.3.36` on stderr, and exits 1. `docs/ci.md` describes this.
- **N-4 (digest pin):** intentionally skipped, as the PR body says. That is acceptable.
- **N-5 (#57 description):** #57's body was refreshed at 14:53 EEST and now cites the `76f4985` built-image check.
- **v2.5.7 N-1:** the Upgrade note is in the CHANGELOG 2.5.7 section.

### 8. Suites, lint, secrets, commits: pass

- Unit suite on PHP 8.4.27 (SQLite): **OK, 346 tests, 2273 assertions, 23 skipped**. The skips are environment-only: no Imagick or zbar, and the pgsql-only tests. CI's sqlite and pgsql legs are green.
- `php -l` on the 9 changed PHP files: clean. No CR line endings in the changed files.
- The 8 non-merge subjects match `check-commits.sh`'s regex and are all ≤ 64 chars. The merge commit is skipped by design. CI's Conventional Commits job is green.
- gitleaks on the PR diff, with default rules and with the repo config: **no leaks**. On the head tree (no-git mode) it reports only the known `generic-api-key` fixture at `tests/Unit/MigrateDsnTest.php:129`, which is the same on `main` and allowlisted in git mode. The new test's `apotheke_test_user` / `canarypass` are throwaway fixtures, and CI's secret scan is green.
- A grep of the diff for IPs, hostnames, tokens and Worker URLs finds only loopback addresses. No secrets.

### 9. Hash check: pass

`docs/qa/apotheke-v2.5.7-release-qa.md` at `2e71d93` is 7,529 B, sha256 `19ff84109ed990e13c4efbee62f3e33aad5b03aa4d8ad0b50a57c72b1d3b96a3`. `cmp` against the QA original shows it is **byte-identical**, both from raw.githubusercontent and from the codeload tarball. It is added in its own last commit, `2e71d93 docs(qa): …`, which touches only that file.

## Findings

### High

None.

### Medium

None.

### Low

- **L-1: the GitHub release body still omits hand-written notes.** `scripts/release.php apply` now folds Unreleased notes into the CHANGELOG section, but `--notes` is still written from the generated `$section` only. The v2.5.8 release body will say only "keep the database user out of connect errors" and leave out the exit-1, bootstrap and `zend.exception_ignore_args` lines. The same gap is why v2.5.7's Upgrade note had to be added by hand. Suggested fix: build the notes from the folded section, for example by returning it from `rel_changelog_insert()` or by re-reading the new section from the written CHANGELOG. Add a test as well.
- **L-2: with the DB down, every route now returns 500 (undocumented, partly untested).** On `main`, `catch (PDOException)` blocks in `settings_all()`, `roles` and others swallowed the connect failure, so `/theme.css`, `/labels.css` and `/manifest.webmanifest` returned 200, `/login` 303 and unknown paths 404, while the log carried the libpq text. On the head, `DatabaseConnectException` (a `RuntimeException`) passes those catches, so all of them return 500 with the sanitized log line. That is safer and arguably correct, but the CHANGELOG only says "/healthz … answers as before". The `public/index.php` logging change has no test (mutant M3 survives). Suggested fix: one CHANGELOG/docs sentence, and a test that requests a non-health route with a refused DB and asserts the single `[apotheke] Could not connect …` log line.

### Nit

- **N-1:** `rel_changelog_fold()` drops every blank line in hand-written notes. Two preface paragraphs become one, and a fenced code block loses its blank lines. That is fine for today's one-paragraph notes.
- **N-2:** the bootstrap message still goes to stdout with no newline. It exits 1 now, but stderr plus `\n` would suit CLI use better (#55 L-2 suggested stderr).
- **N-3:** CHANGELOG 2.5.7 now has two `docker:` lines for the same fix (the generated terse one and the hand-written one), and 2.5.8 will have the same pairing for `db:`. That is cosmetic. Hand-written lines could replace the generated line for the same scope.
- **N-4:** `db()` calls `exit(1)` directly under the CLI SAPI, so a CLI caller (PHPUnit included) can't catch a connect failure. That is fine for the four bin scripts. Throwing `DatabaseConnectException` everywhere and catching it in a shared CLI wrapper would be cleaner, and it is worth keeping in mind for the CI4 port.
- **N-5:** a floating second php stage written as lowercase `from`, indented, with a registry prefix or with `--platform` passes both the CI step and `DockerfileTest`. They agree, and the built-image `PHP_VERSION` assert still checks the final image, so this is optional hardening.

### Info

- **I-1:** `DbConnectErrorTest` uses a refused port only, so libpq's `password authentication failed` text never occurs in CI. QA's live wrong-password runs cover that case, and the tests' exact-stderr and exact-log assertions still kill the mutants.
- **I-2:** `feature/ci4` has its own DB layer and does not get this `db()` catch, the healthz handler or the bootstrap exit code automatically. Carry them over at the main→ci4 sync and check them in the CI4 follow-up.

## Not covered

- The PostgreSQL leg of the unit suite and the Docker, E2E and mobile jobs were not rerun locally. CI 39914819004 is green for all of them.
- No Docker on the box, so `zend.exception_ignore_args = On` was checked through the ini value (`-d`) rather than in the built image.
