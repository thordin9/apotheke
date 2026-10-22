# QA: apotheke PR #53, CI4 migrations module (PDO runner + LegacyFunctions)

- **PR:** [#53](https://github.com/thordin9/apotheke/pull/53) into `feature/ci4`, by Coder (draft at review time)
- **Head:** `ff651b0e665c22ba0a589da055eb0ee390690bce`. The head moved three times during the review:
  - The review started at `656d05e`, a plain merge of `55e9a5b` and `feature/ci4` at `1235cb4`.
  - `18f6fd6` (test) and `7a4444f` (fix) followed: 4 files, +80/-7.
  - Then `ff651b0` (fix, trims DSN keys): 5 files, +58/-9.
- **CI:**
  - At `ff651b0`: run 39826909866, **22/22 jobs green** on attempt 1, finished 21:09 (UTC+3). No job hung on apt or zbar. The longest job is the PHP 8.3 Docker smoke at 806 s, 753 s of it in `Playwright @smoke`.
  - At `7a4444f`: run 39824224802, 22/22 green, finished 20:50 (UTC+3). No job hung on apt or zbar. The longest job took 753 s, almost all of it in `Run Playwright`.
  - At `656d05e`: run 39822634936 was cancelled when the head moved.
- **Verdict: PASS.** No High or Medium findings.
  - M-1, L-1 and L-2 from the #51 review are fixed and verified on PostgreSQL 17. M-1's canary tests catch every mutant.
  - The PR fixed three Lows during the review: L-A (`pairs()` trimming), L-B (the boot fixture) and L-C (the `ci4/spark` guard, found by DevOps).
  - Two parity Lows stay open and don't block: L-D (trailing whitespace and quoted values) and L-E (the DSN password vs `DB_PASSWORD`).

## Test plan

1. **Merge integrity.**
   - `git merge-tree --write-tree 55e9a5b 1235cb4` compared with the head tree, and the files that differ from each parent.
   - `docs/ci.md` keeps #52's apt retry line, the "On PHP 8.4" wording fix and the gitleaks-git note.
   - The #51 QA report blob matches `feature/ci4`'s.
   - For `7a4444f` and `ff651b0`: the codeload tarball's `git get-tar-commit-id`, and a content diff against `git archive` of the mirror ref.
2. **M-1 (hard gate).**
   - Setup: `DsnTranslator::bad()` and `startup.php` driven over HTTP (`php -S ci4/public/router.php`, `zend.exception_ignore_args=0`, `error_log` to a file).
   - Eight DSN shapes, each with password and user canaries: URI userinfo, `password = x` with spaces, a `;` inside the password, a quoted value, URL-encoded characters, a short URI, a missing port and a foreign scheme.
   - The body, the error log and server stderr were grepped for the canaries and the raw DSN.
   - Then the PR's canary tests, and four mutants run against them.
3. **L-1.**
   - Setup: PostgreSQL 17.11 in podman, with `CREATE` on the database revoked from `PUBLIC`.
   - Two non-owner roles: `qa_nc` (no `CREATE`) and `qa_cr` (`CREATE` on the database).
   - Each case runs through `/healthz` and `apotheke:migrate`: an owned schema, a missing schema, a schema without `USAGE`, a fresh schema, a mixed-case name, and a wrong password.
   - 12 concurrent `/healthz` requests on a schema that did not exist yet.
4. **L-2.**
   - `docker/php.ini` and the `Dockerfile` `COPY` line.
   - `bin/migrate.php` (legacy path) and `ci4/spark apotheke:migrate` against real PostgreSQL with a bad-credential canary, `zend.exception_ignore_args` at 0 and at 1, and `display_errors=1`.
5. **`apotheke:migrate`.** A fresh schema, an idempotent re-run and `--status`, on PostgreSQL and SQLite, plus the banner content.
6. **N-1.** Check that it is in the docs.
7. **gitleaks 8.30.1.**
   - Git mode with `--log-opts=--all`, with the repo config and with a default-only config. Dir mode.
   - The diff grepped for addresses, hostnames and token prefixes.
8. **Steering items.**
   - The `mysql:` / `pgsql` driver label in `DsnBootErrorTest`, and DevOps' `ci4/spark` guard.
   - DSN keyword whitespace, as a translator edge-case table and on real PostgreSQL, compared with what PDO and libpq do on PHP 8.3 and 8.4.
9. **Suites.**
   - Podman legs: legacy on 8.3, legacy and ci4 on 8.4, each on SQLite and PostgreSQL.
   - `tests/run.sh`, deptrac, the DI rule, `composer audit` and `scripts/check-commits.sh`.

## Results

### 1. Merge integrity

| Check | Result |
|---|---|
| `merge-tree 55e9a5b 1235cb4` | clean. Tree `f32810b` equals `656d05e`'s tree, so there is no evil merge |
| `656d05e` against `55e9a5b` | only `docs/ci.md`, `docs/qa/apotheke-pr51-ci4-sync-qa.md` and `scripts/e2e-container-setup.sh`. That is exactly `feature/ci4`'s delta since `dff1ebe` |
| `docs/ci.md` | has "apt retries each download 5 times" (#52), "Whenever opcache function JIT is on, on PHP 8.2, 8.3 and 8.4", and the `gitleaks git` note. The old "On PHP 8.4, when opcache" text is gone |
| #51 QA report | blob `0d79938` on the head and on `feature/ci4`, identical to the reviewed file |
| behind `feature/ci4` | 0 commits |
| `18f6fd6`, `7a4444f`, `ff651b0` | each touches exactly the files its message describes |
| codeload tarballs of `7a4444f` and `ff651b0` | `git get-tar-commit-id` returns the full sha of each. Contents are identical to `git archive` of the mirror ref |
| `scripts/check-commits.sh feature/ci4 ff651b0` | 5 commits checked, all ok. Subjects are 53 to 57 characters |

### 2. M-1: boot errors

Over HTTP, all eight shapes return 500 with the body `The database settings could not be used.`. No canary and no raw DSN appears in the body, the error log or server stderr. The log line holds only `Database <DB_DRIVER>` plus whichever of host, port and dbname parse:

| `DB_DSN` shape | Log line |
|---|---|
| `postgresql://user:<pw>@host:port/db` | host, port, dbname |
| short URI `pgsql://u:<pw>@h/db` | host, dbname |
| `…;password=<a>;<b>` (`;` inside the password) | `Database pgsql` only |
| a key that does not parse | `Database pgsql` only |
| quoted value | host, port, dbname |
| URL-encoded characters | host, dbname |
| missing port | host, dbname |
| `mysql:` scheme | host, port, dbname, labelled with `DB_DRIVER` (see L-B) |

**CLI.** `apotheke:migrate` and `bin/migrate.php` (`APOTHEKE_APP=ci4`) were run against 7 shapes each, in the production and development environments, with `zend.exception_ignore_args=0`.
- Every run exits with rc 1.
- stderr carries only `Could not connect to the database (pgsql host=… port=… dbname=…).` or `<unparsed dsn>`.
- No canary appears in stdout, stderr or `ci4/writable/logs`.

**Tests in the PR.** At `ff651b0`, `DsnBootErrorTest`, `DsnTranslatorTest`, `MigrateDsnTest`, `ConfigMigrateWhereTest` and `MigrateSparkMissingTest` pass: 56 tests, 456 assertions. `DsnBootErrorTest` covers uri, unsupported-prefix, spaces and semicolon. Mutants run against `DsnBootErrorTest` and `DsnTranslatorTest`:

| Mutant | Result |
|---|---|
| A: the old behaviour (a message built from the DSN with the old `password=` regex redaction, sent as the body and the log) | killed, 2 failures |
| B: `logLine()` prepends the raw DSN | killed, 3 failures |
| C: the public body is the raw DSN | killed, 1 failure |
| D: `pairs()` without the `ff651b0` trimming | killed, 1 error and 1 failure |
| unmutated | OK |

`DsnException` keeps the raw DSN in a public readonly property. Its message is the generic text. No code path logs or dumps the property: `startup.php` passes it only to `logLine()`.

### 3. L-1: schema handling on PostgreSQL 17

| Case | `/healthz` | `apotheke:migrate` |
|---|---|---|
| `qa_nc` owns the schema, no database `CREATE` | 200 `ok` | 25 steps, rc 0. Re-run: `Already up to date.` |
| `qa_nc`, schema missing | 500. Log: `PostgreSQL schema "qa_missing" does not exist, and this database role cannot create it` | the same message plus "Create the schema, then grant this role USAGE and CREATE on it.", rc 1 |
| `qa_nc`, schema exists, no `USAGE` | 500, the same #44 message | not run |
| `qa_cr`, schema missing | 200, schema created | `Already up to date.` (`/healthz` had already migrated it) |
| `qa_cr`, `Qa_Mixed` | 200, schema created with that exact case | not run |
| wrong password | 500, the generic page | `Could not connect to the database (pgsql host=… port=… dbname=… (schema qa_owned)).`, rc 1 |
| 12 concurrent `/healthz`, `qa_cr`, new schema | 12 × 200. The server logged one `duplicate key value violates unique constraint "pg_namespace_nspname_index"` (23505), which was absorbed as the race | n/a |

What the runs show:
- No password appears in any body or log.
- `pg_namespace` is checked before `CREATE SCHEMA`.
- A failed `CREATE` or `SET search_path` is not swallowed: `current_schema()` is checked, and `/healthz` returns 500.
- The banner goes through `config_migrate_where()`.
- On 8.4 against PostgreSQL at `ff651b0`, with ci4, `Ci4SchemaTest`, `Ci4MigrateTest`, `Ci4HttpTest`, `DsnBootErrorTest`, `DsnTranslatorTest`, `MigrateDsnTest` and `MigrateSparkMissingTest` pass: 56 tests, 1215 assertions, 0 skipped.

### 4. L-2: `bin/migrate.php`

| Check | Result |
|---|---|
| `docker/php.ini` | `zend.exception_ignore_args = On`, copied to `conf.d/zz-apotheke.ini` (`Dockerfile` line 53). `ini_get` reads `1` with the file mounted |
| legacy `bin/migrate.php`, bad-credential canaries for the user and the password, with the setting at 0 | rc 1, `Could not connect to the database (pgsql host=… port=… dbname=apotheke).`, no user, no password |
| the same with `exception_ignore_args=1` | identical |
| `MigrateDsnTest` | runs with the setting off and asserts that the user and password are absent. It passes |

**DevOps' evidence.** It agrees with these results and is credited here:
- `php.ini` lands in `zz-apotheke.ini` for both FPM and CLI.
- There are no `Dockerfile` or workflow changes. The image doesn't ship `ci4/` and doesn't migrate on boot.
- In run 39824224802, "Migrations via CLI" ran in all 6 test cells:
  - The banners are allowlist-only: host, port, dbname and schema, or the SQLite path.
  - The re-run says `Already up to date`.
  - The migrate output has no `password=`, no `user=` and no URI.
  - The throwaway CI password appears only in the workflow's env lines.
- The Docker legs are green.
- Their own SQLite runs (fresh, idempotent and `--status`) pass, and four canaries stay clean with the setting off.

### 5. `apotheke:migrate`

- Fresh, re-run and `--status` work on PostgreSQL (section 3, plus the ci4 CLI leg) and on SQLite.
- The banner reads `Database: pgsql – host=… port=… dbname=… (schema …)` or `Database: sqlite – <path>`. It never contains a user, a password or the raw DSN.
- The pg_trgm step runs without superuser rights: the server logs `permission denied to create extension "pg_trgm"` and the run still completes, as in legacy.

### 6. N-1

`docs/ci.md` says to scan locally with `gitleaks git`, because dir mode skips the whole of `tests/Unit/MigrateDsnTest.php` under the path-scoped allowlist.

### 7. gitleaks and the diff

| Scan at `ff651b0` | Result |
|---|---|
| git mode, `--log-opts=--all`, repo config | no leaks (all mirror refs) |
| git mode, `--all`, default rules only | 1 finding: `generic-api-key` in `tests/Unit/MigrateDsnTest.php` line 129, commit `bf5505e`. It is the known `SecretMissingEq99` fixture |
| dir mode | no leaks |
| diff grep | only `127.0.0.1`, `db.example`, `config.example` and the `192.168.1.10` placeholders already in `src/settings.php`. No token prefixes, no private keys, no other hostnames |

Coder's figure of 295 commits counts a different ref set from mine. That does not change the result.

### 8. Suites

| Run | Result |
|---|---|
| podman 8.3 legacy pgsql (`656d05e`) | CLI migrations OK. PHPUnit 409 tests, 7 failures, all environmental: 6 QR round-trips, plus the HTTP suite's 2 zbar checks |
| podman 8.3 legacy sqlite | the same 7 environmental failures |
| podman 8.4 legacy pgsql | CLI migrations OK, 7 environmental failures |
| podman 8.4 ci4 pgsql | CLI migrations OK. The pgsql proof exits 0 (15 probe rows). Only the 6 QR failures |
| podman 8.4 ci4 sqlite | the 6 QR failures, plus `Ci4HttpTest::testShippedBootDoesNotWriteThePgsqlProbe` (N-B, a box artefact) |
| `tests/run.sh` at `7a4444f` and at `ff651b0` (8.4 container) | 1422 passed, 2 failed (zbar). Node, shellcheck and actionlint are not in the image |
| deptrac at `ff651b0` | 0 violations, 0 errors |
| DI rule at `ff651b0` | OK |
| `composer audit` (root and `ci4`) | no advisories |

The QR failures come from Alpine's zbar 0.23.93, which returns an empty decode. CI's runners pass these tests. The `18f6fd6` to `ff651b0` delta touches only `bin/migrate.php`, `DsnTranslator.php`, tests and docs, and those were re-run at the new heads.

### 9. DSN keyword whitespace at `ff651b0`

These results come from `fromPdoDsn()` with an empty user and password, compared with `config_dsn_public_fields()`:

| Shape after `dbname=catalog;` | CI4 password | Public fields | libpq / PDO |
|---|---|---|---|
| `password = PwA1` | `PwA1` | host, port, dbname | `PwA1` |
| tab, `password`, tab, `=`, tab, `PwA1` | `PwA1` | host, port, dbname | `PwA1` |
| `user = UsrB2` | user `UsrB2` | host, port, dbname | `UsrB2` |
| `password=PwA1 ;sslmode=disable` | `PwA1 ` (keeps the space) | host, port, dbname | `PwA1` (L-D) |
| `password = PwA1 ` (at the end) | `PwA1 ` | host, port, dbname | `PwA1` (L-D) |
| `password='Pw A1'` | `'Pw A1'` (keeps the quotes) | host, port, dbname | `Pw A1` (L-D) |
| `password=Pw A1` | `Pw A1` | null, so the log shows only `Database pgsql` | syntax error |
| `password=PwA1;password = PwA2` | `PwA2` (the last one wins) | host, port, dbname | `PwA2` |
| `password =` | empty | host, port, dbname | empty |
| `=PwA1`, ` = `, `password` with no `=` | `bad()`, log `Database pgsql` | null | syntax error |
| `host = db.example ; port = 5432 ; …` | `bad()` (`port` is `5432 ` and fails the digits check) | host, port, dbname | accepted (L-D) |

`config_dsn_public_fields()` already tokenises the libpq way: spaces around `=`, quotes and backslash escapes. Coder's statement holds.

On real PostgreSQL, through the CI4 group, `/healthz` and `apotheke:migrate`, no password value appeared in any body, log or stderr. Only libpq's phrase "password authentication failed for user …" appears. The spaced password now reaches the CI4 group (DsnTranslatorTest).

## Findings

| ID | Severity | Status | Finding |
|---|---|---|---|
| L-A | Low | fixed in `ff651b0` | `DsnTranslator::pairs()` did not trim around `=` |
| L-B | Low | fixed in `18f6fd6` | `DsnBootErrorTest`'s "spaces" case was a `mysql:` DSN |
| L-C | Low (DevOps) | fixed in `7a4444f` | no `ci4/spark` guard in `bin/migrate.php` |
| L-D | Low (parity) | open, not a gate | the CI4 translator keeps trailing whitespace and quotes that libpq and PDO strip |
| L-E | Low (parity) | open, not a gate | a password in `DB_DSN` loses to `DB_PASSWORD` in the PDO runner |
| N-A | Nit | open | `Ci4SchemaSession` derives the SQLSTATE from the message text |
| N-B | Nit | open | `testShippedBootDoesNotWriteThePgsqlProbe` doesn't forward the admin host and port |
| N-C | Nit | open | the `spaces` boot case asserts the body and log only when the status is 500 |
| I-A | Info | none | CI4's connect error names the DB user in the log |

**L-A: `pairs()` did not trim around `=` (Low, fixed).**
- At `7a4444f`, `password = <pw>` was stored under the key `password ` (with a trailing space) and the value ` <pw>` (with a leading space).
- It was then re-emitted as an extra `password =' <pw>'` parameter. libpq takes the last duplicate, so CI4 connected with a leading-space password.
- On PostgreSQL 17, `/healthz` returned 500 (`password authentication failed`) even when both passwords were right. Nothing leaked.
- `ff651b0` trims the key and the whitespace after `=`, and an empty key is still a bad DSN.
- Verified: the edge-case table in section 9, the new unit tests (`password = s9d44` and `user = zzu` reach the CI4 group, and the log line stays on the allowlist), and mutant D is killed.

**L-B: the "spaces" boot fixture (Low, fixed).**
- At `656d05e`, `DsnBootErrorTest`'s "spaces" case used `mysql:…;password = s9d44` but expected `Database pgsql …`.
- That was a fixture problem, not a code bug. `logLine()` labels the configured `DB_DRIVER`, as the legacy banner does, and the foreign scheme took the unsupported-prefix path, so the pgsql spaced-password shape was never tested.
- `18f6fd6` renames the case to `unsupported-prefix` and asserts that `mysql` is absent from the log. It also adds a real pgsql `spaces` case with canary checks, which `ff651b0` extends to spaces around every key.
- Verified: the tests pass and mutants A to D are killed.

**L-C: no `ci4/spark` guard (Low, found by DevOps, fixed).**
- With `APOTHEKE_APP=ci4` in an install without `ci4/`, `bin/migrate.php` used to hand off to the missing script and print `Could not open input file`.
- `7a4444f` checks `file_exists()`, prints `APOTHEKE_APP=ci4 is set but ci4/spark is not in this install.` and exits 1, with `--status` too.
- `MigrateSparkMissingTest` passes, and fails with `Could not open input file` when the guard is disabled.
- `docs/deployment-and-operations.md` documents the message.

**L-D: trailing whitespace and quoted values (Low, parity, open).**
- libpq ends an unquoted value at whitespace and removes quotes from a quoted value. PDO pgsql turns `;` into spaces before handing the string to libpq.
- `pairs()` only `ltrim`s the value and never unquotes it, so `password=<pw> ;…` becomes `<pw> ` and `password='<pw>'` keeps its quotes.
- Reproduced on PostgreSQL 17 with `DB_PASSWORD` empty and both shapes. `bin/migrate.php` (legacy) and `apotheke:migrate` connect (rc 0), but CI4's `/healthz` returns 500 with `password authentication failed`.
- `host = db.example ; port = 5432 ;` fails closed with the generic error in CI4, while legacy accepts it.
- Nothing leaks.
- `DsnTranslatorTest` asserts the `s9d44 ` result, and `docs/configuration.md` says "the value keeps everything after those spaces". Both describe the divergence as intended.
- Fix: parse the value the libpq way (end an unquoted value at whitespace, unquote and unescape a quoted one). The simplest route is to reuse `config_dsn_keywords()` per `;` segment, so both paths share one parser. Then update the assertion and the docs line, and keep the canary checks.

**L-E: DSN password vs `DB_PASSWORD` (Low, parity, open).**
- `PdoMigrationRunner::connect()`, like legacy `db_connect()`, passes `(string)$db['password']` to `new PDO()`. `Services` now runs that runner before CI4 connects.
- PDO adds `password='…'` after the DSN, so `DB_PASSWORD` wins over a password in `DB_DSN`, and an empty `DB_PASSWORD` counts too. On PHP 8.4 the empty value is added only when the DSN has no exact `password=` key. On 8.3 it is always added.
- Measured on 8.4 with an empty `DB_PASSWORD`:
  - `password = <pw>` fails in the runner (`fe_sendauth: no password supplied`) and in legacy, so CI4 boot is still 500 at `ff651b0`.
  - The plain `password=<pw>` works.
- When both are set and differ, the runner uses `DB_PASSWORD` and the CI4 group uses the DSN's value.
- The runner matches legacy, so this isn't a regression. But the `CHANGELOG.md` line ("`password = …` and `user = …` are the password and the user") is true only for the CI4 group.
- Fix: pass `null` instead of `''` when `DB_PASSWORD` or `DB_USER` is empty, in both the runner and legacy. Or document that a password in `DB_DSN` needs `DB_PASSWORD` unset, and that `DB_PASSWORD` wins.

**N-A: SQLSTATE from the message text (Nit).** `Ci4SchemaSession` derives the SQLSTATE from English message text ("permission denied", "already exists", "duplicate key"). A server with a non-English `lc_messages` would miss the 42501, 42P06 and 23505 mapping and fall through to the generic error. Prefer the driver's error code where CI4 exposes it.

**N-B: the probe test on a non-default port (Nit).**
- In a SQLite leg with `TEST_PG_*` set, the test's `$without` server gets neither `DB_HOST` nor `DB_PORT`, so it tries `127.0.0.1:5432`.
- Since #53, `/healthz` connects on boot, so a PostgreSQL on any other port fails the test.
- CI is unaffected: PostgreSQL runs on 5432 there, and the SQLite legs have no PostgreSQL.
- Fix: forward the admin connection's host, port and dbname into `$db`.

**N-C: a conditional assertion in the `spaces` boot case (Nit).** The `onConnect` branch checks the body and the log line only when the status is 500. A 200 or any other status passes, with only the absence checks run. Assert the expected status explicitly.

**I-A: the DB user in CI4's connect error (Info).** When CI4's own `pg_connect` fails, the error log carries libpq's message: `password authentication failed for user "<user>"`. It contains no password. The only part of the DSN in the trace is PHP's 15-character truncated `host='…` argument. This predates #53.

## Not covered

pcov coverage of the new modules was skipped. Most of the new code runs in child processes (`php -S`, `spark`), which line coverage doesn't capture without a prepend harness. The targeted runs above exercise each path.
