# QA: apotheke PR #61, sync `main` (v2.6.1) into `feature/ci4`

- **PR:** [#61](https://github.com/thordin9/apotheke/pull/61), "chore(ci4): sync main e2628f2 into feature/ci4", branch `sync/main-into-ci4` into `feature/ci4`, by DevOps. Open, not a draft, GitHub reports it mergeable (`clean`).
- **Head tested:** `de597cf0b3860fa6e74b2788fb959b319b91e49b`. It is the merge commit `ecd4ab9` (parents `b6b713e`, the `feature/ci4` tip, and `e2628f2`, the `main` tip) plus four commits: `a82ff30` build(docker): keep one zend.exception_ignore_args line → `d7ec3e9` test(docker): read the PHP pin from the ARG default → `40c0c34` feat(ci4): add autofill_max_image_bytes to the settings schema copy → `de597cf` fix(ci4): report a refused database like main's #58. No force-push.
- **Brings in:** #54, #55, #56, #57, #58, #59, #60 and the v2.5.6, v2.5.7, v2.5.8, v2.6.0 and v2.6.1 release commits (64 commits ahead of `b6b713e`, 78 files, +3627 / −140).
- **CI:** run 40045112520, **22/22 jobs `success`** on attempt 1, all at `de597cf` (03:28–03:38 Athens time, UTC+3).
- **Date:** 2026-10-24 (Europe/Athens).
- **Scope:** read-only. codeload tarballs of `de597cf`, `ecd4ab9`, `40c0c34`, `b6b713e`, `e2628f2`, `6e93bb2` and the merge base `cbf78e4` (each confirmed with `git get-tar-commit-id`), plus the GitHub API (PR, compare, jobs, job logs). Local runs only in scratch copies and a throwaway PostgreSQL 17. No comment, review, push or merge was made.
- **Verdict: PASS.** No High or Medium findings. L-1 is the known CI4 log gap that Coder2's next PR closes. The rest are notes.

## 1. Test plan

### 1.1 Environment

- Tarballs unpacked side by side. A scratch git repo built from them (merge base `cbf78e4`, `feature/ci4` `b6b713e`, `main` `e2628f2`, merge `ecd4ab9`, head `de597cf`) so that git 2.47 `merge-tree --write-tree` can redo the merge.
- PHP **8.4.27** and **8.3.36** CLI, PHPUnit from `composer install` (root and `ci4/`), a throwaway PostgreSQL **17** cluster with `scram-sha-256` for every connection (as the CI service uses for TCP), deptrac, the DI rule, actionlint and shellcheck through `scripts/lint.sh`, gitleaks **8.30.1** (tarball sha256 matches the workflow pin).

### 1.2 Acceptance checks

1. **PHP pins.** `ARG PHP_VERSION` defaults to `8.3.36` and the 8.4 Docker leg builds `8.4.27`. The CI assert and `DockerfileTest` read the ARG case-insensitively, and each image is checked against the version it was built with. The 8.3 leg fails if its build-arg differs from the Dockerfile default. The CI logs show each version. The matrix comments say ci4 requires `^8.4`, so the 8.3 legs test legacy and the runner only.
2. **`zend.exception_ignore_args`** is set exactly once.
3. **Migration 0026.** `autofill_max_image_bytes` is in ci4's `settings_schema` copy, byte-identical to `main`. Counts go 25 → 26 migrations and 62 → 63 keys. A fresh SQLite and PostgreSQL install through CI4 gives 63 keys, with `auto` as the default.
4. **#58 port.** (a) The sanitized connect line. (b) The catch in `Services::apothekeDb` wraps the lazy connect **and** `apply()`. (c) `/healthz` and `/health` with the database down: 500, an empty body and only the sanitized log line. (d) The unwritable data dir and the int CLI exit codes (#58 N-4). (e) The new Core `DatabaseUnavailable` exception, with deptrac clean.
5. **The merge.** Redo it with `merge-tree`, list the conflicts, diff git's merge against `ecd4ab9`, and review every hand resolution. Compare the legacy paths with `main` `e2628f2`. Check #59, #60 and v2.6.1 (VERSION and CHANGELOG) are present, and that `docs/qa/` matches the local QA copies byte for byte.
6. **Suites and mutations.** PHPUnit for ci4@8.4 and legacy@8.3 on SQLite and PostgreSQL 17, plus legacy@8.4. Mutation checks: remove the catch, log the raw libpq text, and five more.
7. **Known gap.** When CI4's own connect is refused, libpq's text naming the DB user reaches `writable/logs` (and possibly the PHP log). Reproduce and size it.
8. **Cloudflare phase.** Storage behind an interface, per-request global or static state in worker mode (including the new catch), DSN-driven DB config. The PR body has the Cloudflare-phase note. Worker-mode flags are deferred to the Settings PR.
9. **#59 deferral.** The client logic deferred to the Autofill module is documented.
10. **Secrets, docs and tests.** gitleaks, a host and token grep of the added lines, and docs and tests in the PR.

## 2. Results

### 2.1 Delta

- `de597cf` is **0 behind** `feature/ci4` (`b6b713e`, the PR base) and **0 behind** `main` (`e2628f2`, still the tip at review time). It is 64 commits ahead of `feature/ci4` and 66 ahead of `main`.
- `e2628f2` is `6e93bb2` (#60's merge) plus `chore(release): v2.6.1`, which changes only `CHANGELOG.md`, `README.md` (the coverage row) and `VERSION`.

### 2.2 Evil-merge check

`git merge-tree --write-tree b6b713e e2628f2` reports conflicts in exactly the 10 files the PR body lists: `.github/workflows/ci.yml`, `CHANGELOG.md`, `Dockerfile`, `README.md`, `bin/migrate.php`, `docs/ci.md`, `docs/deployment-and-operations.md`, `docs/getting-started.md`, `scripts/e2e-container-setup.sh` and `tests/Unit/MigrateDsnTest.php`. Git's tree against `ecd4ab9` differs in those 10 files and nowhere else.

| File | Resolution | Assessment |
|---|---|---|
| `Dockerfile` | `ARG PHP_VERSION=8.3.36` + `FROM php:${PHP_VERSION}-fpm-alpine`, with main's "bump this patch pin" comment | correct |
| `.github/workflows/ci.yml` | lint and mobile: ci4's `['8.3', '8.4']` matrix plus main's `timeout-minutes`. zbar: main's apt timeouts and 3 retries plus ci4's `if: steps.suite.outputs.run == 'true'`. docker: ci4's build-args and per-minor cache scope, now `matrix.php_patch`, plus main's pin assert rewritten for the ARG (2.3) | correct, both sides kept |
| `bin/migrate.php` | main's `catch (PDOException $e)` with `db_connect_failure_message()`; ci4's `APOTHEKE_APP=ci4` hand-off to spark above it is untouched | correct. Against `main` the file differs only by that hand-off block |
| `scripts/e2e-container-setup.sh` | ci4's 8.3/8.4 version switch; main's retry comment merged into ci4's | correct |
| `tests/Unit/MigrateDsnTest.php` | both sides' tests kept; one `migrate()` helper with main's keyed `$ini` and `$envExtra` and ci4's `bool $ci4` last; ci4 call sites moved to the keyed form | correct |
| `CHANGELOG.md` | preamble identical to both parents. `[Unreleased]` is ci4's with "25 migrations" → "26" and one new sync bullet under Maintenance. Everything from `[2.6.1]` down is **byte-identical to `main`** (28,474 B) | correct |
| `README.md` | layout row `php:8.3.36-fpm-alpine by default (ARG PHP_VERSION)` | correct. v2.6.1's coverage row is present |
| `docs/ci.md` | ci4's table, the zbar retry sentence in the Tests row, and the Docker row rewritten for the ARG pin | correct, matches `ci.yml` |
| `docs/deployment-and-operations.md` | main's connect-failure paragraph plus ci4's `APOTHEKE_APP=ci4` sentences and one line on the CI4 port | correct, true at `de597cf` |
| `docs/getting-started.md` | main's `php:8.3.36-fpm-alpine` text plus ci4's build-arg note, now `PHP_VERSION=8.4.27` | correct |

There is no evil merge. Against `main`, 191 paths differ: 166 are exactly `feature/ci4`'s version, and 25 combine both sides (the 10 conflict files, the clean auto-merges `docker/php.ini`, `docs/configuration.md`, `docs/control-panel.md`, `docs/releasing.md` and `docs/user-guide.md`, and the 10 files the four follow-up commits touch). No file from `main` is deleted.

### 2.3 PHP pins (item 1)

- **Dockerfile:** `ARG PHP_VERSION=8.3.36` / `FROM php:${PHP_VERSION}-fpm-alpine`. **PASS**
- **Docker matrix** (`safe_load`): `fail-fast: false`, `include: [{php: '8.3', php_patch: '8.3.36'}, {php: '8.4', php_patch: '8.4.27'}]`. Build-args `PHP_VERSION=${{ matrix.php_patch }}`, cache scope `php${{ matrix.php }}`. The step gets `BUILT: ${{ matrix.php_patch }}`. **PASS**
- **The pin assert, run locally.** I extracted the step from `ci.yml` and replaced only `docker run … apotheke:ci php` with the local `php8.3` (8.3.36) or `php8.4` (8.4.27). Everything else is CI's script. shellcheck is clean.

| Case | Exit | Output |
|---|---|---|
| 8.3 leg, built 8.3.36 | 0 | `PHP_VERSION=8.3.36` |
| 8.4 leg, built 8.4.27 | 0 | `PHP_VERSION=8.4.27` |
| 8.3 leg, but the image is 8.4 | 1 | `expected 8.3.36` |
| build-arg 8.3.35, default 8.3.36 | 1 | `Dockerfile default 8.3.36 differs from the 8.3.35 build-arg in ci.yml` |
| default bumped to 8.3.37, `ci.yml` left at 8.3.36 | 1 | same message |
| lowercase `arg` and `from` | 0 | accepted |
| literal `FROM php:8.3.36-fpm-alpine` | 1 | `does not use the pinned ARG PHP_VERSION` |
| an extra floating `FROM php:8.3-fpm-alpine` stage | 1 | same |
| `ARG PHP_VERSION=8.3`, a CR on the ARG line, two ARG lines | 1 | `does not pin exactly one ARG PHP_VERSION=X.Y.Z` |

  **PASS**
- **`DockerfileTest`** mirrors the step with no hard-coded version (lowercase keywords, trailing space, CRLF, minor-only ARG, missing ARG or FROM, literal FROM, floating stages, duplicates). **`Ci4BranchCiTest`** checks the `include` matrix and that the 8.3 `php_patch` equals the Dockerfile ARG default, so a bump in only one place fails PHPUnit too. Both pass locally. **PASS**
- **CI logs.** Docker 8.3 (job 120724952810): `--build-arg PHP_VERSION=8.3.36`, `BUILT: 8.3.36`, `PHP_VERSION=8.3.36`. Docker 8.4 (job 120724952882): `--build-arg PHP_VERSION=8.4.27`, `BUILT: 8.4.27`, `PHP_VERSION=8.4.27`. **PASS**
- **Matrix comments.** The Tests matrix and the Docker matrix both say ci4 requires PHP `^8.4`, so the 8.3 legs test legacy only, and the ci4 runner goldens run on 8.3 while the `/healthz` goldens run on 8.4. **PASS**

### 2.4 `zend.exception_ignore_args` (item 2)

The merge `ecd4ab9` auto-merged two copies into `docker/php.ini` (main's and ci4's). `a82ff30` keeps one line, `zend.exception_ignore_args = On` at line 13, with both comments merged. It appears nowhere else in `docker/`, the `Dockerfile` or `ci4/`. `php -n -c docker/php.ini` reads it as `1`. **PASS** (N-2: a trailing blank line at end of file)

### 2.5 Migration 0026 and counts (item 3)

- **Schema row.** The `autofill_max_image_bytes` row in `ci4/modules/Migrations/LegacyFunctions.php:230` and `src/settings.php:113` on `main` are byte-identical after leading whitespace (both sha256 `34392ea3…b551`).
- **Validation.** The new `af_bytes` case matches `main`'s rule for rule: `null` is an error, blank or `auto` (any case) gives `auto`, `^\d{1,8}$`, bounds `AUTOFILL_IMAGE_BYTES_MIN` 65536 and `AUTOFILL_IMAGE_BYTES_MAX` 10485760 (the same values as `src/autofill.php`), and the same error text.
- **Migrations.** `migrations/` is identical to `main`'s, and there are 26 files.
- **Tests.** `Ci4MigrateTest` asserts 26 applied and 63 settings rows on SQLite and PG, and 63 in the `settings_schema` golden.
- **Fresh install through `ci4/spark apotheke:migrate`.** SQLite: 26 steps, `schema_migrations` 26, **63 keys**, `autofill_max_image_bytes` = `"auto"`. PostgreSQL 17 (new schema): the same, 26 / **63** / `"auto"`.

**PASS**

### 2.6 #58 port (item 4)

| Item | Result |
|---|---|
| (a) Sanitized line | `PdoMigrationRunner::connectFailure()` now gives `Could not connect to the database (<driver> – <where>).`, the same as legacy `db_connect_failure_message()`. The runner still catches `PDOException` and keeps none of its text. **PASS** |
| (b) The catch in `Services::apothekeDb` | `try { $db = Database::connect(); $factory->apply($db); } catch (DatabaseException)`, so it wraps both the lazy connect and the first query. Only a pgsql connect with `connID === false` is turned into `MigrateConnectException(connectFailure())`. A SQLite error or a query error after a good connect is rethrown. **PASS** |
| (c) `/healthz`, `/health` with the DB down | `HealthController` catches `DatabaseUnavailable`, logs only `getMessage()` (the sanitized line) and answers 500 with an empty body. The headers stay: `text/plain` and `X-Apotheke-Version` on `/healthz`, `application/json` and `no-store` on `/health`. The test compares each with a legacy server on the same refused port. **PASS** |
| (d) Unwritable dir, CLI exit codes | `bin/migrate.php` (legacy and ci4) and `spark apotheke:migrate` exit 1 with `Data directory … is not writable.` and nothing on stdout. The web 500 body equals legacy's. A refused connect gives byte-identical stdout and stderr on all three, with exit 1. `Commands/Migrate.php` has no `exit(` and `ci4/modules` has no `set_exception_handler`. **PASS** |
| (e) Core `DatabaseUnavailable` | `ci4/modules/Core/Exceptions/DatabaseUnavailable.php`, a plain `RuntimeException`. `MigrateConnectException` extends it, so Core catches a Core type. deptrac: **0 violations, 0 errors**. As a mutation, I pointed `HealthController` at `MigrateConnectException` directly, and deptrac failed with `DependsOnDisallowedLayer … Core … must not depend on … Migrations`. The DI rule is OK. **PASS** |

### 2.7 Legacy paths, #59, #60, v2.6.1 and QA docs (item 5)

- **Identical to `main` `e2628f2`:** `src/`, `public/`, `migrations/`, `templates/`, `assets/`, `VERSION` (**2.6.1**), `.gitleaks.toml`, `docker-compose.yml`. `bin/` differs only by ci4's `APOTHEKE_APP=ci4` block in `bin/migrate.php`. `docker/` differs only by the `php.ini` comment merge. `config.example.php`, `composer.json`, `composer.lock` and `.gitignore` are `feature/ci4`'s unchanged versions.
- **#59:** migration 0026, `src/autofill.php` and `src/settings.php` are present (identical to `main`), and so are the CHANGELOG lines. **#60:** the clamp-once, ten-point quality, KB display and docs changes are in `src/` and `docs/` (identical to `main`), and both #60 QA reports are present. **v2.6.1:** `VERSION` is 2.6.1, the `[2.6.1] - 2026-10-24` section is byte-identical to `main`, and so is the README coverage row.
- **`docs/qa/`:** all 25 of `main`'s reports are byte-identical in the head. Of the head's 34 reports, 33 are byte-identical to `/workspace/qa-docs/`. The other, `2.4.0-qa.md`, has no local copy.

**PASS**

### 2.8 Tests (item 6)

| Run (local, at `de597cf`) | Result |
|---|---|
| ci4 @ 8.4.27, PostgreSQL 17 | **OK**, 444 tests, 4115 assertions, 2 skipped (php-cgi not installed; `HttpSuiteTest` stays on legacy by design) |
| ci4 @ 8.4.27, SQLite | 444 tests, 1 failure, 5 skipped. The failure is `Ci4HttpTest::testShippedBootDoesNotWriteThePgsqlProbe`, which connects to a hard-coded `127.0.0.1:5432` (N-4). With the throwaway cluster moved to 5432, `Ci4HttpTest` is OK (6 tests, 2 skipped as PostgreSQL-leg only) on ci4 and legacy |
| legacy @ 8.3.36, SQLite | **OK**, 444 tests, 3740 assertions, 19 skipped (the CI4 tests need 8.4) |
| legacy @ 8.3.36, PostgreSQL 17 | **OK**, 444 tests, 3746 assertions, 18 skipped |
| legacy @ 8.4.27, SQLite | 444 tests, the same single port-5432 failure as ci4 SQLite, which passes on 5432 |
| legacy @ 8.4.27, PostgreSQL 17 (PG on 5432) | **OK**, 444 tests, 4117 assertions, 1 skipped |
| `Ci4DatabaseDownTest` on PG with scram | **OK**, 4 tests, 125 assertions, none skipped |
| deptrac / DI rule | 0 violations / OK |
| `scripts/lint.sh` (git checkout) | all OK: PHP lint, JS, 233 links in 58 files with 0 broken, shellcheck, actionlint, pipefail-safe greps |

**Mutations.** Each was made in a separate scratch copy and reverted afterwards. The tests ran on PostgreSQL 17 with scram auth.

| # | Mutation | Killed by |
|---|---|---|
| M1 | remove the try/catch in `Services::apothekeDb` | `testCi4PostgreConnectRefusalIsTheSanitizedLine` |
| M2 | throw `MigrateConnectException($e->getMessage())`, so the raw libpq text is logged | same |
| M3 | wrap only `Database::connect()`, leaving `apply()` outside the catch | same (CI4 connects lazily, so the catch never fires) |
| M4 | the old line without ` – ` | 5 tests: 3 in `Ci4DatabaseDownTest`, `Ci4MigrateTest::testStatusOutputMatchesLegacy`, `MigrateDsnTest::testCi4MigratePrintsOnlyAllowlistedFields` |
| M5 | the runner appends the PDO message | the health and CLI tests |
| M6 / M7 / M8 | a non-empty 500 body / no `error_log` / 200 while down | both health tests |
| M9 | `MigrateConnectException extends RuntimeException` | both health tests (uncaught, so not the legacy response) |

All 9 mutants are killed. M1–M3 are caught only by `testCi4PostgreConnectRefusalIsTheSanitizedLine`, which skips when PostgreSQL accepts any password. In CI it ran: the ci4@pgsql job (120724952842) reports `Tests: 444, Assertions: 4127, Skipped: 1`, and that one skip is `HttpSuiteTest` (CI has php-cgi), so the scram service did run it (N-1).

### 2.9 Known gap (item 7)

I reproduced it with the test hook (`APOTHEKE_TEST_CI4_WRONG_PASSWORD`), with and without `CI_ENVIRONMENT=production`:

- The HTTP response is 500 with an empty body. The server's stderr log has only `Could not connect to the database (pgsql – host=127.0.0.1 port=… dbname=apotheke (schema t_…)).`
- **`ci4/writable/logs/log-*.log`** gets `ERROR … Error connecting to the database: ErrorException: pg_connect(): Unable to connect … FATAL:  password authentication failed for user "<db user>"` plus a stack trace with absolute paths. CI4's `Postgre\Connection::connect()` writes it through `log_message` before the exception reaches the catch.
- Neither the password nor the DSN appears (I grepped both). In this harness the PHP log had nothing extra, because CI4's error handler turns the `pg_connect` warning into that log entry.

That matches the expected **Low** (L-1): it names the user, not the password, in a server-side file. `testCi4PostgreConnectRefusalIsTheSanitizedLine` asserts `password authentication` is absent only from the response, not from `writable/logs`. Coder2's muted connect in `DatabaseFactory` fixes it, and that PR's `Ci4ConnectFailureLeakTest` covers `writable/logs`.

### 2.10 Cloudflare phase (item 8)

FrankenPHP worker mode in a Cloudflare Container, Postgres through Hyperdrive, uploads in R2.

- **Storage behind an interface:** nothing new. This PR adds no file writes. The data dir is still only preflighted (`StoragePreflight`). #60's clamp-warning file in the data dir is legacy-only here; Coder put it behind the storage interface in the CI4 Autofill module.
- **Per-request global or static state:**
  - The new catch keeps no state. It throws before `BaseService::getSharedInstance()` stores `apothekeDb`, so a refused connect is never cached. The next request rebuilds the service. CI4's `Database::$instances` keeps the connection object with `connID === false`, and `BaseConnection::initialize()` reconnects whenever `connID` is falsy, so a worker recovers once the database is back.
  - `HealthController` holds no fields and `DatabaseUnavailable` is a plain exception.
  - The new `define()`s are process-wide constants, the same in every request.
  - The `Events::on('pre_system')` hook is only in `tests/ci4-router.php`.
  - The existing shared `apothekeDb` (migrate per new instance) and `settings_cache` are unchanged. Their worker-mode flags are deferred to the Settings PR, and the PR body says so.
- **DSN-driven DB config:** unchanged. The CI4 group still comes from `DsnTranslator`, and the failure line from `config_migrate_where()`.
- **Still present, for the Cloudflare phase (I-2):** CI4's `FileHandler` writes to `ci4/writable/logs`. In a container that is ephemeral and nobody reads it, and today it is where L-1's text lands. Logging to stderr would suit the container better. It doesn't block this PR.
- **PR body:** it has the "Cloudflare phase" section covering all three points, and a "Deferred" section for the worker-mode flags. **PASS**

### 2.11 #59 deferral (item 9)

The PR body's "Deferred" section says #59's client logic (the image-size clamp, the 413 retry and so on) goes into Coder's CI4 Autofill module, and this PR adds only the schema row, the validation and the counts. **PASS**

### 2.12 Secrets, docs and tests (item 10)

- **gitleaks 8.30.1** with the tree's `.gitleaks.toml` scans:
  - the head tree (`dir`): no leaks;
  - the scratch repo's history (`git --log-opts="--all"`, every tree above): no leaks.

  Scanning from outside the tree reports the known `SecretMissingEq99` fixture, because the path-scoped allowlist then doesn't match. From the repo root, as CI runs it, it's clean. CI's Secret scan job (full history) is green.
- **Grep of the 3627 added lines:**
  - URLs: only `github.com/thordin9/apotheke` PR, release and compare links, `https://autofill.example` and `http://127.0.0.1:`;
  - IPs: only `127.0.0.1`;
  - no email address, `workers.dev` host, token or account id.
- **Docs and tests:**
  - docs in the PR: `CHANGELOG.md`, `README.md`, `docs/ci.md`, `docs/deployment-and-operations.md`, `docs/getting-started.md`;
  - tests: `Ci4DatabaseDownTest` (new), `DockerfileTest`, `Ci4BranchCiTest`, `Ci4MigrateTest`, `MigrateDsnTest`, plus the `tests/ci4-router.php` hook.

**PASS**

### 2.13 CI

Run 40045112520 (attempt 1, `de597cf`), **22/22 `success`**:
- Lint, mobile checks, Docker build + smoke test, and Visual E2E shards 1–3: each on PHP 8.3 and 8.4;
- Tests: legacy@8.3, legacy@8.4 and ci4@8.4, each on sqlite and pgsql;
- deptrac, DI rule, Conventional Commits and Secret scan.

The jobs ran from 03:28 to 03:38 Athens time (UTC+3). The ci4@pgsql cell runs PHP 8.4.27: `OK, but some tests were skipped! Tests: 444, Assertions: 4127, Skipped: 1`.

## 3. Findings

| Id | Sev | Finding | Suggestion |
|---|---|---|---|
| L-1 | Low (known, on `feature/ci4`) | When CI4's own Postgre connect is refused, CI4 logs libpq's `password authentication failed for user "<db user>"` and a stack trace with absolute paths to `ci4/writable/logs`, in production mode too. No password and no DSN. The response and the server log line are clean. The new test checks only the response for that text. | Coder2's next PR, as planned: mute the connect in `DatabaseFactory` and assert on `writable/logs`, as `Ci4ConnectFailureLeakTest` does. |
| N-1 | Note | M1–M3 (the catch) are caught only by `testCi4PostgreConnectRefusalIsTheSanitizedLine`, which skips under trust auth. It ran in CI this time. | Optional: fail instead of skip when `CI` is set, so a future change to the service's auth can't silently drop it. |
| N-2 | Note | `docker/php.ini` now ends with an extra blank line (main has none). | Drop it with the next touch. |
| N-3 | Note | Old wording is left in two places. `docs/plans/feature12-ci4/php84-legacy-diff.md:42` still says `ARG PHP_VERSION=8.3` and `PHP_VERSION=8.4`, and the older `[Unreleased]` ci bullet still says "`PHP_VERSION=8.4` builds the test image". | Update to `8.3.36` / `8.4.27` in a later docs pass, or mark the plan text as historical. |
| N-4 | Note (pre-existing) | `Ci4HttpTest::testShippedBootDoesNotWriteThePgsqlProbe` connects to `127.0.0.1:5432` and ignores `TEST_PG_HOST` / `TEST_PG_PORT`, so it fails locally when PostgreSQL is on another port. CI uses 5432, so it passes there. | Read the port and host from `test_pg_params()`. |
| I-1 | Info | `scripts/check-commits.sh` was not rerun locally (no git history on the box). CI's Conventional Commits job is green, and the four new subjects are typed and short. | None. |
| I-2 | Info (Cloudflare phase) | CI4 logs to files under `ci4/writable/logs`, which is ephemeral and nobody reads it in a container. | When the Settings or Cloudflare work lands, log to stderr. |

No High or Medium findings.

## 4. Verdict

**PASS at `de597cf0b3860fa6e74b2788fb959b319b91e49b`.**

- **The merge.** Of git's own merge, only the 10 expected conflict files changed, and each is resolved correctly. The released CHANGELOG sections are byte-identical to `main`. The legacy app (`src/`, `public/`, `migrations/`, `templates/`, `assets/`, `VERSION` 2.6.1) is identical to `main` `e2628f2`, and #59, #60 and v2.6.1 are all present.
- **Pins.** The Dockerfile defaults to 8.3.36, the 8.4 leg builds 8.4.27, and the assert checks each image against its own build-arg and fails on a default/build-arg mismatch. `exception_ignore_args` is set once. Migration 0026 is in the CI4 schema copy byte for byte, and fresh SQLite and PG installs give 63 keys with `auto`.
- **#58 port.** It is complete and tested: the sanitized line, the catch around the lazy connect plus `apply()`, the 500 with an empty body on `/healthz` and `/health`, the unwritable dir, the int exit codes, and the Core exception with deptrac clean. All 9 mutants are killed.
- **Tests and CI.** The local suites are green apart from one environmental port case, CI is 22/22, and gitleaks is clean.
- **Cloudflare phase.** Nothing new blocks it.
- **Open items.** L-1 is the known log gap that Coder2's PR closes. The notes are follow-ups.
