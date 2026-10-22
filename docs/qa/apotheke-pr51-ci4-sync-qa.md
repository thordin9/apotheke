# QA: apotheke PR #51, sync `main` into `feature/ci4`

- **PR:** [#51](https://github.com/thordin9/apotheke/pull/51) into `feature/ci4`, by Coder (draft at review time)
- **Head:** merge commit `dff1ebe275a0fc183c3f0aa2fef3006862261fd3`. Parents: `2d715bd` (`feature/ci4`) and `cbf78e4` (`main`, where #50 merged, pinned to `e973795`). The head didn't move during the review.
- **Brings in:** #44, #45, #47, #48, #49 and #50 (21 non-merge commits, 11 merges).
- **CI:** run 39682738871, **22/22 jobs green on attempt 3**, all at `dff1ebe`. That attempt re-ran every job after `ppa:ondrej/php` came back. Attempts 1 and 2 were 19/22 because of the PPA outage (I-1).
- **Verdict: PASS.** The merge is clean and introduces no findings, and CI is 22/22. M-1 (Medium), L-1 and L-2 were already on a parent branch and the merge doesn't make them worse, so under the room's scoping they don't block this sync. M-1 has to be fixed before CI4 serves anything.
- **PR 5:** M-1, L-1 and L-2 are PR 5's gates, per the PM.

## Test plan

1. **Delta.** Parents, and behind/ahead counts against both branch tips. `scripts/check-commits.sh 2d715bd dff1ebe`, and subject lengths.
2. **Evil-merge check.**
   - `git merge-tree --write-tree 2d715bd cbf78e4` (git 2.4x), with conflicts listed, then that tree diffed against `dff1ebe`.
   - Every file that differs from either parent, classified as from `main`, from `feature/ci4`, or resolved (differs from both).
3. **Specific checks.**
   - `CHANGELOG.md` split into preamble, `[Unreleased]` and released sections. `[2.5.5]` against `main`, and `[Unreleased]` against `feature/ci4`.
   - `Dockerfile` against both parents.
   - `.gitleaks.toml`, `VERSION`, `bin/migrate.php` and the other `src/` files against `main`. `ci4/` and `.github/` against `feature/ci4`.
   - The old QR helper name across code, tests and docs, and any `ci4/` caller of legacy functions.
4. **The CI4 connection path** (the room's request).
   - `DatabaseFactory` against #44's `db_pgsql_use_schema()`, reproduced against PostgreSQL 17.
   - A grep of all of `ci4/` for anything that prints or logs a DSN or password.
   - Probes of `DsnTranslator`'s error message, unit-level and over HTTP.
   - A connection failure with a wrong password.
5. **Tests at `dff1ebe`.**
   - Host PHP 8.4: PHPUnit legacy and ci4 on SQLite, `tests/run.sh` in a git checkout, deptrac, the DI rule, `composer audit` for `ci4/`, and `scripts/ci4-leg.sh`.
   - podman (`php:8.3-fpm-alpine` and `php:8.4-fpm-alpine` with the CI extensions) against `postgres:17`: the CI cell steps for legacy@8.3, legacy@8.4 and ci4@8.4 on pgsql. That is the CLI migrations (fresh, idempotent re-run, seed twice, `--status`), full PHPUnit, and `scripts/ci4-pgsql-proof.php` for ci4.
6. **Secrets.** Pinned gitleaks 8.30.1 with CI's arguments (`git --log-opts="--all" --redact`) on a fresh mirror of every branch and tag plus `refs/pull/51/head`, checked out at `dff1ebe`.
7. **Release safety.** The `release.yml` triggers and guards.
8. **CI.** Run 39682738871, every attempt, by job and failing step.

## Results

### 1. Delta

- `dff1ebe` has parents `2d715bd` and `cbf78e4`, which are the current tips of `feature/ci4` and `main`.
- It is **0 behind** `feature/ci4` and **0 behind** `main`, and 33 commits ahead of `feature/ci4`. The merge base is `c654569`.
- `check-commits.sh 2d715bd dff1ebe`: 21 commits checked, 20 `ok`, and `db983c8 chore(release): v2.5.5` skipped as a release commit.
- Merge subjects are skipped by design. The only subject longer than 72 characters is #48's existing merge commit `5ccb90e`, which is already on `main`.

### 2. Evil-merge check

`git merge-tree` reports conflicts in exactly the three files Coder named: `CHANGELOG.md`, `docs/configuration.md` and `docs/getting-started.md`. `AGENTS.md`, `Dockerfile`, `README.md`, `docs/ci.md` and `tests/lib.php` auto-merge.

The auto-merged tree `d19872f` against `dff1ebe` differs in 4 files and nowhere else:

| File | Difference | Assessment |
|---|---|---|
| `CHANGELOG.md` | conflict markers removed. `feature/ci4`'s `[Unreleased]` body comes first, then a blank line, then `main`'s `[2.5.5]` onwards | correct |
| `docs/configuration.md` | `db.schema` row: `main`'s "Created only when missing." plus `feature/ci4`'s `trust_proxy` sentence | correct, and both facts are true at `dff1ebe` |
| `docs/getting-started.md` | `feature/ci4`'s `PHP_VERSION=8.4` sentence plus `main`'s Imagick pin paragraph (GitHub tag, PECL second source, two sha256 pins). The DB_SCHEMA paragraph auto-merged | correct |
| `docs/ci.md` | Docker row: "install Imagick (`pecl imagick-3.8.0`)" becomes "build Imagick from the pinned GitHub tarball, with the sha-pinned PECL fallback and https-only curl" | correct, and it matches the merged `Dockerfile` |

There is no evil merge. Of the 190 paths that differ from either parent, 161 are `feature/ci4`'s version, 21 are `main`'s, and 8 are combined:

- the 4 manually resolved files above;
- 4 clean auto-merges:
  - `AGENTS.md` (#45's QR line)
  - `README.md` (#44's `DB_SCHEMA` row and the v2.5.5 coverage table)
  - `Dockerfile` (below)
  - `tests/lib.php` (#44's `test_pg_*` helpers, added beside `feature/ci4`'s `APOTHEKE_APP` switching)

### 3. Specific checks

| Check | Result |
|---|---|
| `CHANGELOG.md` preamble | identical to both parents |
| `[Unreleased]` | byte-identical to `feature/ci4`'s (2228 B) |
| `[2.5.5]` | byte-identical to `main`'s |
| everything from `[2.5.5]` down | byte-identical to `main`'s (19874 B) |
| `Dockerfile` against `main` | only `ARG PHP_VERSION=8.3`, its comment and `FROM php:${PHP_VERSION}-fpm-alpine` from `feature/ci4` |
| `Dockerfile` against `feature/ci4` | #47/#48's block: `IMAGICK_VERSION=3.8.0`, `IMAGICK_SHA256=a964e54a…1e1a59`, `IMAGICK_PECL_SHA256=bda67461c854f20d6105782b769c524fc37388b75d4481d951644d2167ffeec6`, `--proto '=https' --proto-redir '=https'` with retries, the GitHub source first and PECL second, and the `phpversion('imagick')` check. `pecl install` is gone |
| `.gitleaks.toml`, `VERSION` (2.5.5), `bin/migrate.php`, `src/config.php`, `src/db.php`, `src/qr.php` | blob-identical to `main` |
| `ci4/` and `.github/` | no changes against `feature/ci4` |
| old QR name `qr_php84_jit_is_tracing` | not in any code, test, script or current doc. It appears only in `CHANGELOG.md` history and in older `docs/qa/` reports |
| `ci4/`, `tests/ci4-router.php`, `tests/parity/`, `scripts/ci4-*` | no calls to `qr_*` or other legacy `src/` helpers |

### 4. The CI4 connection path

The merge doesn't touch `ci4/`, so everything in this section was already on `feature/ci4` at `2d715bd`.

**`DatabaseFactory::apply()` still has the pre-#44 behaviour (L-1).**
- For PostgreSQL it runs `CREATE SCHEMA IF NOT EXISTS` unconditionally, then `SET search_path`.
- PostgreSQL 17 refuses that statement for a role without CREATE on the database, even when the schema exists (checked with `psql`). That is #41.
- CI4 swallows the failure:
  - With an existing schema owned by such a role, ci4 `/healthz` returns 200. The server log shows `permission denied for database` on the `CREATE SCHEMA` statement.
  - With the schema **missing**, ci4 `/healthz` also returns 200 "ok". Legacy returns 500 with #44's "does not exist … cannot create" log line.
- So ci4 doesn't break for the #41 user, but it hides a missing schema, which legacy's #44 test treats as an error.

**Grep of `ci4/` for DSN or password output.**
- The only places a message leaves the process are:
  - `BootError::send()`, which writes the public HTTP 500 body and `error_log`;
  - `PlainHandler`, which gives a generic "Server error" body and logs `getMessage()`;
  - the PHP-version messages in `spark` and `public/index.php`.
- `TrustProxyReader` connects with a warning-suppressed `pg_connect()` and logs nothing.
- There are no `log_message` calls and no Commands.
- A connection failure with a wrong password gives HTTP 500 "Server error". The log carries CodeIgniter's `Unable to connect … password authentication failed for user "…"`, with no password, and nothing lands in `ci4/writable/logs`.

**`DsnTranslator::bad()` does print passwords (M-1).** A DSN that doesn't parse is reported as `Unsupported database DSN "<dsn>"`. The only redaction is `preg_replace('/(password=)[^;]*/i', '$1***', $dsn)`. `startup.php` passes that message to `BootError::send()` as **both** the public body and the log line. Probes, each with a canary password:

| `DB_DSN` shape | Leaks? |
|---|---|
| `postgresql://user:<pw>@host:port/db` (URI userinfo) | **yes**: HTTP 500 body and server log, reproduced over HTTP with `php -S ci4/public/router.php` |
| `pgsql:…;password =<pw>;…` (space before `=`) | **yes**: HTTP 500 body, reproduced over HTTP |
| `pgsql:…;password=ab;<rest>;…` (a `;` inside the password) | **yes**: everything after the first `;` |
| `pgsql:…;PASSWORD=<pw>;…`, `mysql:…;password=<pw>` | no |
| libpq style `pgsql:host=… password=<pw> dbname=…` | no (accepted) |

The existing tests (`DsnTranslatorTest::testPasswordIsRedactedInTheBootMessage` and `Ci4HttpTest::testBadDsnFailsAtBootAndRedactsThePassword`) cover only the `password=` form.

### 5. Tests at `dff1ebe`

| Run | Result |
|---|---|
| host 8.4, legacy, SQLite, PHPUnit | OK: 389 tests, 2649 assertions, 19 skipped, 1 incomplete |
| host 8.4, ci4, SQLite, PHPUnit | OK: 389 tests, 2647 assertions, 20 skipped, 1 incomplete |
| host 8.4, `tests/run.sh` (git checkout, so actionlint runs) | 1423 passed, 0 failed |
| deptrac (`scripts/ci4-arch.php deptrac`) | 0 violations, 0 errors |
| DI rule (`scripts/ci4-arch.php di`) | OK |
| `composer audit --working-dir=ci4` | no advisories |
| `scripts/ci4-leg.sh legacy` and `ci4` | `run=true` for both |
| podman legacy@8.3 pgsql | CLI migrations OK. PHPUnit 389 tests, 8 failures, all environmental (below). HTTP suite 1190 passed, 2 failed (zbar) |
| podman legacy@8.4 pgsql | CLI migrations OK. PHPUnit 389 tests, 7 failures, all environmental. HTTP suite 1190 passed, 2 failed (zbar) |
| podman ci4@8.4 pgsql | CLI migrations OK. PHPUnit 389 tests, 6 failures, all environmental. `ci4-pgsql-proof.php` passes (probe rows present) |

The environmental failures:

- **zbar.** The 6 `QrCodeTest::testRoundTripsThroughARealDecoder` cases and the 2 zbarimg checks in the HTTP suite fail because Alpine's `zbar 0.23.93` returns an empty decode. CI uses Ubuntu's `zbar-tools`, and those cases pass there. `src/qr.php` and `src/qr-encode.php` are byte-identical to `main`.
- **`Ci4DiRuleTest` on 8.3.** The `ci4/vendor` I copied in was installed under 8.4, so Composer's platform check rejects 8.3. CI installs `ci4/` dependencies only on 8.4.
- **INI dependence.** On the first container passes, `MigrateDsnTest` "user is never printed" and the legacy `/healthz` 500 check failed. Both passed once the container ini matched setup-php's production defaults (`display_errors=Off`, `zend.exception_ignore_args=On`). The first is L-2 below. The second is PHP's documented behaviour of answering 200 when display_errors is on.

### 6. Secrets

gitleaks 8.30.1 (tarball SHA-256 matches the workflow) scanned 294 commits: **no leaks**, exit 0. The tree's `.gitleaks.toml` is `main`'s path-scoped AND allowlist.

### 7. Release safety

`release.yml` runs on `workflow_run` of CI with `branches: [main]` and on `workflow_dispatch`. The job additionally requires `workflow_dispatch`, or a successful CI run whose event was `push`. It checks out `ref: main`, and "Only release the commit CI tested" stops if `main` has moved. A push to `feature/ci4` triggers CI (`push: branches: [main, feature/ci4]`) but not Release. A manual dispatch always plans from `main`.

### 8. CI

All times are Athens time (UTC+3).

| Attempt | Result |
|---|---|
| 1 (from 00:56) | 19/22. The three "Visual E2E (Playwright, PHP 8.4, shard N/3)" jobs failed in "Install PHP 8.4" (apt exit 100, a 503 from `ppa:ondrej/php`) |
| 2 (E2E 8.4 shards rerun 01:08–01:15) | 19/22, the same three jobs failing in the same step. At 01:22 the box also got 503 from the PPA's `InRelease`. It answered 200 again at 01:24 |
| 3 (all 22 jobs re-run, 02:41–02:52) | **22/22 success**, at head `dff1ebe`. The PHP 8.4 E2E shards finished at 02:49:21 (shard 3/3, job 119550097221), 02:50:40 (shard 2/3, job 119550097582) and 02:51:56 (shard 1/3, job 119550097503) |

**Green in every attempt:**
- deptrac and the DI rule;
- Lint on 8.3 and 8.4;
- Conventional Commits and Secret scan;
- all 6 Tests cells: legacy@8.3, legacy@8.4 and ci4@8.4, each on sqlite and pgsql;
- Headless mobile checks on 8.3 and 8.4;
- Visual E2E PHP 8.3, all 3 shards;
- both Docker legs.

**Docker legs:** in attempt 1, 8.3 is job 119509041213 and 8.4 is job 119509041129. Attempt 3 re-ran both green, as jobs 119550137047 (8.3) and 119550099083 (8.4). Every step is green, including "HEIC (Imagick lists HEIC) and startup refusal", both smoke tests, the web server checks, graceful stop and Playwright `@smoke`. DevOps read the logs: Imagick came from the GitHub tarball on both legs, and every image check passed (HEIC, round-trip, sRGB and refusal). The job logs need a sign-in, so I didn't re-read them myself.

## Findings

All of these were already present on a parent branch. None was introduced or made worse by the merge.

| ID | Sev | Finding | Where | Suggestion |
|---|---|---|---|---|
| M-1 | Medium (on `feature/ci4`, not on `main`; the shipped image runs legacy) | `DsnTranslator::bad()` redacts only `password=<value up to ;>`. A URI DSN with userinfo, `password =` with a space, or a password containing `;` puts the password, or part of it, into the **public HTTP 500 body** and the error log. `startup.php` sends the same message to both. Reproduced over HTTP (section 4). | `ci4/modules/Core/Config/DsnTranslator.php`, `ci4/modules/Core/Boot/startup.php` | Fix before CI4 serves anything, in PR 5 or a separate fix PR. Keep the public body generic ("Unsupported database DSN. Use sqlite:<path> or pgsql:…"), never echo the DSN, and log only an allowlisted description (scheme plus host, port and dbname, as `config_migrate_where()` does). Add tests for the URI, space and `;` shapes. |
| L-1 | Low (on `feature/ci4`) | `DatabaseFactory::apply()` runs `CREATE SCHEMA IF NOT EXISTS` unconditionally: the #41 pattern. CI4 swallows the `permission denied`, so an existing schema works, but a **missing** schema also gives `/healthz` 200 instead of legacy's 500 with the #44 message. | `ci4/modules/Core/Config/DatabaseFactory.php` | Tracked for PR 5 (Coder). Acceptance criteria: (1) a `pg_namespace` lookup before CREATE, creating only when missing; (2) 42501 with the schema still missing fails with #44's "does not exist … cannot create" message, and 42P06/23505 races continue when the schema is there afterwards; (3) a failed CREATE or `SET search_path` is not swallowed, and `/healthz` returns 500 as legacy does; (4) the migrate banner goes through `config_migrate_where()`; (5) #44's `PgsqlSchemaCreateTest` and integration cases are ported or parameterised to run against ci4. |
| L-2 | Low (on `main`, from #44) | When `bin/migrate.php` can't connect, the uncaught `PDOException` trace includes the `PDO::__construct` arguments, so the **DB user** is printed. The password is a `SensitiveParameter` and is not printed. This happens wherever `zend.exception_ignore_args` is off, including the shipped image (`docker/php.ini` doesn't set it, so the trace goes to stderr and `docker logs`). `MigrateDsnTest` "user is never printed" passes in CI only because setup-php's ini sets `exception_ignore_args=On`. | `bin/migrate.php`, `docker/php.ini` | Catch `PDOException` in `bin/migrate.php` and print one line without the trace, and/or set `zend.exception_ignore_args = On` in `docker/php.ini`. Also run that test with `-d zend.exception_ignore_args=0`. |
| I-1 | Info | The PHP 8.4 E2E shards depend on `ppa:ondrej/php`, which returned 503 on attempts 1 and 2. Attempt 3 passed after the PPA recovered. DevOps opened #52, `ci(e2e): retry apt and PPA steps in e2e-container-setup`, into `feature/ci4`. It is still open. | `scripts/e2e-container-setup.sh` | Land #52 (retry `add-apt-repository` and `apt-get update` with backoff). Optionally add a fallback, for example a cached `.deb` set or a prebuilt 8.4 Playwright image. |
| I-2 | Info | The local container failures (Alpine zbar, the 8.3 `Ci4DiRuleTest` platform check, ini-dependent results) are environmental. All of these cases pass in CI. | — | None. |

## Summary

- The merge is clean: 0 behind both tips, and the only differences from git's own merge are the three conflict files plus the `docs/ci.md` Docker row, all correctly resolved.
- `[2.5.5]` is byte-identical to `main`'s, and `[Unreleased]` to `feature/ci4`'s.
- `Dockerfile` is `main`'s with only `feature/ci4`'s `PHP_VERSION` ARG and `FROM`, and the tarball pin, PECL pin and https-only curl are intact.
- `.gitleaks.toml`, `VERSION`, `bin/migrate.php` and `src/` are identical to `main`, and `ci4/` and `.github/` are untouched. The QR rename is complete.
- Every local test that can run here passes, apart from environmental failures. deptrac, the DI rule and the audit are clean, gitleaks is clean, and feature/ci4 pushes can't release.
- CI is 22/22 on attempt 3.
- M-1, L-1 and L-2 were already on the parent branches, and the merge doesn't make them worse. They are PR 5's gates.
