# QA: apotheke PR #55, keep the DB user name out of `bin/migrate.php` connect errors

- **PR:** [#55](https://github.com/thordin9/apotheke/pull/55) into `main`, by Coder (draft at review time). Title: `fix(migrate): keep the DB user name out of connect errors` (57 chars).
- **Head:** `bab8eecfa11f03713e47f116bcd5f076688b689d`, a plain merge of the fix `08f8a78` with `main` at `85a260b` (#54).
- **Diff against `main`:** 5 files, +99/-6: `bin/migrate.php`, `CHANGELOG.md`, `docs/configuration.md`, `docs/deployment-and-operations.md`, `tests/Unit/MigrateDsnTest.php`.
- **CI:** run 39839490728 on `bab8eec`. The run's conclusion is **success**, finished 22:36 (UTC+3).
  - 9 of 10 jobs are completed/success.
  - The tenth, `Lint`, has every step completed/success (done 22:23:53 UTC+3), but GitHub still reports its check run as `in_progress`. See I-2.
- **Verdict: PASS.** No High or Medium findings. The L-2 criterion from the #53 review is met for `bin/migrate.php` on PHP 8.3 and 8.4.
  - The other legacy entry points still print the user name on a refused connection. That is pre-existing, outside this PR's scope, and filed as L-1.
  - `docker/php.ini` has no `zend.exception_ignore_args = On`. The fix doesn't need it (N-1).

## Test plan

1. **Merge integrity.**
   - `git merge-tree --write-tree 08f8a78 85a260b` compared with the head tree.
   - The files that differ from each parent, and the CI and test-infra files compared blob by blob with `main`.
2. **L-2 criteria (hard gate).**
   - Setup: PostgreSQL 17 in podman on 127.0.0.1. Throwaway QA roles and canary user and password strings were created for this run only.
   - PHP 8.3.35 and 8.4.26 images with `pdo_pgsql`.
   - Nine connection shapes:
     - field-based bad auth;
     - a URI DSN;
     - `password = x` with spaces in the DSN;
     - a `;` inside the password;
     - a quoted DSN value;
     - wrong host;
     - wrong port;
     - wrong dbname with a valid user;
     - a valid user with a wrong password.
   - Each shape ran in three ini modes:
     - `zend.exception_ignore_args=0` with `zend.exception_string_param_max_len=1000000`;
     - `zend.exception_ignore_args=1`;
     - `zend.exception_ignore_args=0` at the default max length (the official image's default, since it ships no `php.ini`).
   - Every run had `log_errors=1`, `display_errors=stderr` and `error_log` to a file.
   - stdout, stderr and the error log were grepped for:
     - the canaries, including the split forms of the password;
     - `password authentication failed`;
     - `PDOException`;
     - `Stack trace`.
   - The same matrix ran on `main` at `85a260b` as the pre-fix baseline.
3. **Other paths that print the user name.**
   - Under PHP 8.4, the head ran with bad credentials and both `ignore_args` values through `bin/seed.php`, `bin/backup.php`, `bin/restore.php`, `bin/settings.php`, and `public/index.php` (`/healthz`, `/health`) over `php -S`.
   - The schema-permission path ran after a successful connect.
4. **Behaviour that must not change.**
   - The exit codes.
   - The success banner and an idempotent second run on SQLite and PostgreSQL, plus `--status`.
   - The SQLite open error (an unopenable `SQLITE_PATH`).
   - All compared byte for byte, head against `main`.
5. **Mutation check.** Three mutants against `MigrateDsnTest`:
   - The catch reverted to the `main` code.
   - The catch kept but printing `$e->getMessage()`.
   - The SQLite branch removed, so every driver is caught.
6. **Changelog, docs and release.**
   - The `[Unreleased]` entry and the two doc paragraphs, read against the measured behaviour.
   - `php scripts/release.php plan` at `main` and at the head.
   - `scripts/check-commits.sh 85a260b bab8eec`.
7. **gitleaks and CI.**
   - gitleaks 8.30.1 in git mode on a clone checked out at the head: `--all` and the PR range, with the repo config and with the default rules.
   - CI jobs from the API.
8. **Suites.**
   - `scripts/lint.sh`, and `php -l` on 8.3 and 8.4.
   - The unit suite on the host, and on 8.3 and 8.4 containers with SQLite and PostgreSQL.

## Results

### 1. Merge integrity

- `merge-tree 08f8a78 85a260b` gives tree `d2c1e200…`, the same as the head tree. No conflicts, no extra edits.
- The head differs from `08f8a78` only in #54's three files.
- `.github/workflows/ci.yml`, `scripts/e2e-container-setup.sh` and `docs/ci.md` are blob-identical to `main`.

### 2. L-2: `bin/migrate.php` connect errors

**Head: 54 of 54 runs clean** (9 shapes × 3 ini modes × PHP 8.3 and 8.4).

- Every run exits **1**.
- stdout is only the banner, e.g. `Database: pgsql – host=127.0.0.1 port=… dbname=… (schema app)`.
- stderr is only `Could not connect to the database (pgsql – host=… port=… dbname=… (schema app)).`
- The canaries, `password authentication failed`, `PDOException` and `Stack trace` were found in none of stdout, stderr or the error log.

**`main` (pre-fix): 54 of 54 runs fail uncaught.**

- Every run exits 255 with `Fatal error: Uncaught PDOException` and a stack trace.
- 46 runs print the user canary, through libpq's message or the trace arguments.
- 8 runs print only the exception and the trace.
- 6 runs print the password canary. These are the DSN-embedded shapes in the `ignore_args=0`, max-length-1M mode.
- With the default max length (15), trace arguments are truncated and the password stays hidden. PHP ≥ 8.2 already shows the `$password` parameter as `SensitiveParameterValue`.
- The URI DSN fails with `could not find driver` on legacy (unchanged by this PR).

**The criteria, one by one:**

| Criterion | Result |
| --- | --- |
| `PDOException` caught around `db_connect()` | Yes, for every non-SQLite driver |
| Only allowlisted fields printed | Driver, host, port, dbname, `DB_SCHEMA` (via `config_migrate_where()`, same as the banner) |
| Safe with `zend.exception_ignore_args` Off | Yes. The test and the matrix both run with it Off and the max length at 1M |
| `zend.exception_ignore_args = On` in `docker/php.ini` | **Missing** on `main` and at the head. Not needed for this path (N-1) |
| Test with the setting Off | `testConnectFailureOmitsUserPasswordAndDsn` sets `zend.exception_ignore_args=0` and `zend.exception_string_param_max_len=1000000` |

### 3. Other paths (head, PHP 8.4)

| Entry point | Exit | User name printed | Password printed |
| --- | --- | --- | --- |
| `bin/seed.php`, `bin/backup.php`, `bin/restore.php`, `bin/settings.php` | 255, uncaught `PDOException` | Yes, on stderr (libpq text), with `ignore_args` 0 and 1 | No |
| `public/index.php` `/healthz`, `/health` | HTTP 500, clean body | Yes, in the error log | No |
| `bin/migrate.php`, role without `CREATE` on a missing schema | 255, uncaught `RuntimeException` (the schema message, schema named) | No | No |

All four CLI scripts and the web health checks go through `db()` → `db_connect()`, and there is no global exception handler. The user name is in the exception message, so `zend.exception_ignore_args` cannot hide it. Same behaviour on `main` (L-1).

### 4. Behaviour that must not change

Head and `main` produce byte-identical output, timestamps and schema names aside:

| Case | Exit | Output |
| --- | --- | --- |
| SQLite, first run | 0 | `Database: sqlite – …/catalog.sqlite`, seed line, `Done: 25 step(s).` |
| SQLite, second run | 0 | Banner, `Already up to date.` |
| PostgreSQL, first run (fresh schema) | 0 | Banner with `(schema …)`, seed line, `Done: 25 step(s).` |
| PostgreSQL, second run | 0 | Banner, `Already up to date.` |
| PostgreSQL `--status` | 0 | Banner and the applied list |
| SQLite, unopenable `SQLITE_PATH` | 255 | Banner, then `Uncaught PDOException: SQLSTATE[HY000] [14] unable to open database file` (rethrown as before) |

A data directory that isn't writable exits 0 with `Data directory … is not writable.` on both head and `main` (L-2, pre-existing, in `src/bootstrap.php`).

### 5. Mutation check (`MigrateDsnTest`, 23 tests)

| Mutant | Result |
| --- | --- |
| None (head) | OK, 23 tests, 132 assertions |
| Catch reverted to the `main` code | `testConnectFailureOmitsUserPasswordAndDsn` fails |
| Catch prints `$e->getMessage()` | `testConnectFailureOmitsUserPasswordAndDsn` fails |
| SQLite branch removed (all drivers caught) | `testSqliteOpenErrorStaysUnchanged` fails |

`bin/migrate.php` was restored afterwards; its blob matches the head (`7436a03`).

The test targets 127.0.0.1:5432. In CI's pgsql leg that is a real bad-auth failure; elsewhere it is connection refused. The mutants still die in both cases, because the test also asserts exact stdout and stderr and the absence of `PDOException` and `Stack trace` (I-1).

### 6. Changelog, docs and release

- **`CHANGELOG.md`.** `[Unreleased]` → `### Fixed` → **migrate:** exit 1, only driver/host/port/dbname/`DB_SCHEMA`, no server text, password or DSN, SQLite unchanged. Matches the measured behaviour.
- **`docs/configuration.md` and `docs/deployment-and-operations.md`.** They match the behaviour. Two small wording points are in N-3.
- **Release.**
  - `release.php plan` at `main`: `release=false`. `main`'s 10 commits since v2.5.5 are ci/docs/chore/test/build.
  - At the head: `commits=11 bump=patch next=2.5.6 release=true`. The `fix:` cuts **v2.5.6**. `VERSION` is 2.5.5.
- **Commits.**
  - `check-commits.sh 85a260b bab8eec`: `ok 08f8a78`, 1 commit checked. The merge commit is skipped by `--no-merges`, as designed.
  - The subject and the PR title are both 57 chars (≤ 72).

### 7. gitleaks and CI

| Scan | Result |
| --- | --- |
| Git mode, `--all`, repo config | No leaks |
| Git mode, `--all`, default rules | 1 finding: `generic-api-key` at `tests/Unit/MigrateDsnTest.php:129` in `bf5505e`, already on `main` and covered by the repo's anchored allowlist |
| Git mode, PR range `85a260b..bab8eec`, either config | No leaks |

CI run 39839490728 (`pull_request`, attempt 1):

| Job | State |
| --- | --- |
| Docker build + smoke test | success |
| Secret scan | success |
| Conventional Commits | success |
| Tests (PHP 8.3, sqlite) | success |
| Tests (PHP 8.3, pgsql) | success |
| Headless mobile checks | success |
| Visual E2E (Playwright, shards 1/3, 2/3, 3/3) | success |
| Lint | Every step success; check run still `in_progress` (I-2) |

### 8. Suites

- `scripts/lint.sh`: OK. `php -l` on the two changed PHP files: clean on 8.3 and 8.4.
- Unit suite on the host: OK, 336 tests, 16 skipped. Coder's run reported 336 tests with 23 skipped; skip counts depend on the local tools available.
- Unit suite on the 8.3 and 8.4 containers, SQLite and PostgreSQL: 336 tests.
  - Everything passes except six `QrCodeTest::testRoundTripsThroughARealDecoder` cases.
  - Those six fail the same way on `main` in the same image. It is the QA image's decoder, not this PR, which doesn't touch QR code. CI's test jobs are green.

## Findings

### High

None.

### Medium

None.

### Low

- **L-1: other legacy entry points still print the DB user name on a refused connection (pre-existing).**
  - **Where:** `bin/seed.php`, `bin/backup.php`, `bin/restore.php` and `bin/settings.php` exit 255 with an uncaught `PDOException`. libpq's `password authentication failed for user "…"` and a stack trace go to stderr. `/healthz` and `/health` return a clean 500 but write the same text to the error log.
  - **Scope:** The password is not exposed, and `zend.exception_ignore_args` doesn't help because the user name is in the message. The behaviour is the same on `main`, and the PR only claims `bin/migrate.php`.
  - **Suggested follow-up:** catch `PDOException` once in `db()` (or a shared CLI wrapper) and print the same allowlisted fields.
- **L-2: a data directory that isn't writable exits 0 (pre-existing).**
  - `src/bootstrap.php` uses `exit('Data directory … is not writable.')`, which ends with status 0 and no newline. A deploy step running `bin/migrate.php` would count the failure as success.
  - **Suggested follow-up:** write to stderr and `exit(1)`. Same on `main`; not part of this PR.

### Nit

- **N-1: `docker/php.ini` has no `zend.exception_ignore_args = On`.**
  - **Needed for this fix?** No. The catch keeps `bin/migrate.php` clean with the setting Off and the max length at 1M.
  - **Still worth adding:** It would strip the DSN and user arguments from traces on the L-1 paths and on any future uncaught exception.
  - **Limit:** It doesn't hide libpq's message, so it doesn't fix L-1 by itself.
- **N-2: the code comment overstates the trace.**
  - It says "the trace also includes the DSN, user, and password".
  - On PHP ≥ 8.2 the `$password` argument shows as `SensitiveParameterValue`. The password shows only when it is embedded in the DSN and `zend.exception_string_param_max_len` is raised.
- **N-3: doc wording.**
  - Both docs say "If PostgreSQL refuses the connection". The same message covers an unreachable host, a wrong port and a missing database, and any `PDOException` raised inside `db_connect()` after connecting (`SET TIME ZONE`, `SET search_path`). "If the connection fails" would be exact.
  - `docs/configuration.md` lacks the "including when PHP includes exception arguments in stack traces" clause that `docs/deployment-and-operations.md` has.

### Info

- **I-1:** The test's fixed 127.0.0.1:5432 target means bad auth in CI's pgsql leg and connection refused elsewhere. The test kills all three mutants in both cases.
- **I-2:** GitHub shows the `Lint` check run as `in_progress` even though all its steps finished with success in 17 s and the run's conclusion is success. If `Lint` is a required check, the PR may need a re-run of that job before it can merge.
- **I-3:** `feature/ci4` carries its own copy of `bin/migrate.php` without this catch. It picks up the fix at the next `main` merge.

## Not covered

- MySQL or other PDO drivers. The app supports SQLite and PostgreSQL only.
- A live deploy of v2.5.6.
- The Visual E2E and Docker smoke jobs were not re-run locally; CI covers them.
