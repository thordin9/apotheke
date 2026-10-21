# QA report: PR #44 `fix(db): skip CREATE SCHEMA when DB_SCHEMA already exists`

| | |
|---|---|
| PR | [#44](https://github.com/thordin9/apotheke/pull/44) into `main`, fixes [#41](https://github.com/thordin9/apotheke/issues/41) |
| Final head | `6986578fcee19e1025a1cd317fd6b9d3cc4052f0` `chore(ci): allowlist the migrate DSN placeholder`, on `bf5505e` `fix(migrate): print only allowlisted DSN fields`, on `a600a05` |
| Earlier rounds | `10f4d80b0f242f158fd62274d78c9255ef712dc5` (first review), `645ec22770bb92cb09cdefc7db806147e115b3e9` (recheck), `c035dc0e30d952bcf887461aa926358fe40da4d0` (first merge of `main`), `a600a057f1718c96c7562e0107106ecfbd13ce01` (merge of `main` `7a20646` with #45), `bf5505e5989e9d51dc01e41088942760eb8a0202` (L-4 cause fix) |
| Base | `main` at `7a20646f3331a0a18d0c0e123f1c2e3d36776fcd`, the #45 merge. The first two rounds were reviewed against `c654569`. |
| CI | `10f4d80`: run 39559061598, 10/10. `645ec22`: run 39648199139, 10/10. `c035dc0`: run 39650258599, 10/10. `a600a05`: run 39652000887, 10/10. `bf5505e`: run 39655311295, 9/10 (Secret scan failed, M-1). `6986578`: run 39657178694, 10/10. All were checked through the GitHub API. |
| QA environment | PHP 8.4.26 CLI (`pdo_pgsql` installed for each round and removed afterwards), PostgreSQL 17.11 in a throwaway podman container |
| Date | 2026-10-21 |
| Verdict | **Pass at `6986578`. Nothing blocks.** No High or Medium findings are open. L-1 to L-4 and M-1 are closed, and I-9 is fixed. The remaining items are Info only. The v2.5.5 replay matches. |

## Round 1 at `10f4d80`

### Test plan

1. **Delta.** Diff the `c654569` and `10f4d80` tarballs. Check that `src/config.php`, `src/views.php` and `config.example.php` change no behaviour beyond the fix.
2. **#41 fixed.** Use a role without CREATE on the database, with `DB_SCHEMA` set and the schema already there. Cover two setups: (a) a privileged role created the schema and the app role owns it, and (b) the schema is owned by another role, and the app role has USAGE plus table and sequence privileges. Expect 200 on `/healthz`, `/health` and normal pages, and expect writes to work. Reproduce the 500 on `main` `c654569`.
3. **Schema missing, role without CREATE.** Expect a clear error that names the schema, with no hang.
4. **Schema missing, role with CREATE.** The schema is created and migrations run. Without `DB_SCHEMA`, behaviour matches `main`.
5. **Edge cases.** A mixed-case schema name, special characters and injection attempts in the schema name, a password containing a backslash, and concurrent connections racing to create the schema.
6. **Leak case.** Force connect failures: a bad password containing a marker and a backslash, a password inside `DB_DSN`, a wrong host, a closed port, and a missing schema (with HTML in its name) for a role without CREATE. Request `/`, `/healthz`, `/health`, `/labels`, `/settings` and `/login`, plus a JSON request. The password marker, DSN, host, port, user, SQLSTATE, stack trace and file paths must not appear in any response header or body. The log must get the detail, and HTML in the message must be escaped.
7. **Mutations.** An unconditional CREATE, the lookup using the wrong name, and a case-folding mismatch, plus a few more. The tests must catch them; record any that survive.
8. **Suites and static checks.** PHPUnit on SQLite and PostgreSQL, `tests/run.sh`, and the HTTP integration suite on PostgreSQL. Link checker, shellcheck, actionlint and gitleaks. Confirm the Dockerfile and workflows are unchanged. Spot-check #37's photo behaviour.

### Results

#### 1. Delta

The tarballs were re-verified with `git get-tar-commit-id`: `10f4d80b0f24…`, `c654569b6586…` and `baa0a73a5e7c…`. The review worktrees are byte-identical to the tarballs. The only extra content is `vendor/` and the PHPUnit cache.

The PR changes exactly 12 files: `src/db.php`, `src/config.php`, `src/views.php`, `config.example.php`, `README.md`, four docs (`configuration`, `database`, `deployment-and-operations`, `getting-started`), and the tests (`tests/Unit/PgsqlSchemaCreateTest.php`, `tests/integration.php`, `tests/lib.php`).

- **`src/db.php`.** `db_connect()` calls the new `db_pgsql_use_schema()`. That function looks the name up with a parameterised `SELECT 1 FROM pg_catalog.pg_namespace WHERE nspname = ?`, and runs `CREATE SCHEMA IF NOT EXISTS "<quoted>"` only when the lookup finds nothing.
  - On SQLSTATE 42501 it looks again. If another session created the schema in the meantime, it carries on. Otherwise it throws a `RuntimeException` that names the schema.
  - `SET search_path` uses the same double-quote-escaped identifier as before.
  - The password is moved into a local variable and unset from the config array before connecting.
  - The no-schema path and the SQLite path are unchanged.
- **`src/config.php` and `config.example.php`.** One comment each ("created only when missing"). No code change.
- **`src/views.php`.** `view_error()` wraps `page_start()` and `page_end()` in `try`. On `Throwable`, it logs `[apotheke] error page: …` and prints a minimal page with `h($msg)`.
  - This is needed. `settings()` only catches `PDOException`, so the new `RuntimeException` would make the error page throw inside `index.php`'s top-level `catch`.
  - Proven by reverting just `views.php` (mutant M0 below). With the shipped `display_errors=Off`, the response becomes an empty 500. With `display_errors=On`, the browser gets a PHP fatal that includes the schema text.
  - The fallback runs only when the normal page itself throws. Every other path renders exactly as before.
- **Docs.** They accurately describe the lookup, the privileges needed (USAGE, plus CREATE on the schema for migrations), and the failure message.
  - The new `PostgreSQL role` anchor resolves.
  - The SQL example has a `'…'` placeholder password.
  - L-3 covers one sentence about CLI output.
- **Unchanged.** The Dockerfile, `docker/`, `.github/`, `bin/migrate.php`, migrations and all image and photo code are byte-identical to `main`.

#### 2. #41: role without CREATE, schema exists

The setup has three roles. `app_nc` and `app_g` are `NOSUPERUSER NOCREATEDB NOCREATEROLE`, with CREATE on the database revoked from them and from PUBLIC. `has_database_privilege(…,'CREATE')` returns `f` for both. `app_c` is the same but has `GRANT CREATE ON DATABASE`.

- `shared`: created by the admin role with `AUTHORIZATION app_nc`, and empty. The app role runs all migrations itself.
- `shared_g`: owned by the admin role and migrated by it. `app_g` has USAGE on it plus SELECT, INSERT, UPDATE and DELETE on its tables and USAGE, SELECT and UPDATE on its sequences, and no CREATE on the schema.

| Route | `main` c654569, owner | PR 10f4d80, owner | `main`, granted | PR, granted |
|---|---|---|---|---|
| `/` | **500** | 200 (catalog page) | **500** | 200 |
| `/healthz` | **500** | 200 `ok` | **500** | 200 |
| `/health` | **500** | 200 JSON | **500** | 200 |
| `/labels` | **500** | 200 | **500** | 200 |
| `/settings` | 200 | 200 | 200 | 200 |
| `/login` | 303 | 303 | 303 | 303 |

- **#41 reproduced on `main`.** The log has `SQLSTATE[42501] … permission denied for database` from `db.php:36`, the unconditional `CREATE SCHEMA IF NOT EXISTS`.
- **On the PR.** `app_nc` applied all 25 migrations into `shared` (22 tables, all owned by `app_nc`). Writes through `db_connect()` succeed as both `app_nc` and `app_g`, and `current_schema()` is the configured schema.
- **CLI.** `bin/migrate.php` as `app_g` prints `Already up to date.` and exits 0 on the PR. On `main` it throws an uncaught 42501.

#### 3. Schema missing, role without CREATE

- **PR.** `RuntimeException: PostgreSQL schema "qa_missing1" does not exist, and this database role cannot create it. Create the schema, then grant this role USAGE and CREATE on it.` This takes about 0.01 s on `db_connect()` and 0.01–0.09 s per HTTP request, with no hang.
- **Over HTTP.** Every route returns 500. Page routes get the generic "Something went wrong on the server." page, and `/healthz` and `/health` get an empty 500 body. The log has the full message.
- **CLI.** It exits 255 with the same message (see L-3 for the banner line).
- **`main`.** It gives a raw `42501 permission denied for database`, which doesn't name the schema.

#### 4. Schema missing with CREATE, and no `DB_SCHEMA`

- **Missing schema, `app_c`.** The schema is created and owned by `app_c`. All 25 migrations are applied (22 tables), and a second connect applies 0. Over HTTP, `/`, `/healthz`, `/health` and `/labels` return 200 on a fresh schema.
- **No `DB_SCHEMA`, admin role.** On fresh databases, `main` and the PR each apply 25 migrations into `public` (22 tables). The two `pg_dump -s` outputs are identical (same md5).
- **No `DB_SCHEMA`, `app_c`.** On PostgreSQL 17, `public` has no CREATE for non-owners. Both `main` and the PR fail the same way: `42501 permission denied for schema public` on `schema_migrations`. That's unchanged.

#### 5. Edge cases

**Mixed case.**
- An admin-created `"MixedCase"` owned by `app_nc` works with `DB_SCHEMA=MixedCase`. The lookup matches, no CREATE is attempted, migrations apply, and `current_schema` is `MixedCase`. On `main` the same setup fails with 42501.
- `app_c` with `DB_SCHEMA=NewMixed` creates exactly `NewMixed`, not `newmixed`. The second connect finds it.
- A schema created unquoted as `FoldMe` is stored as `foldme`. `DB_SCHEMA=FoldMe` then fails with the clear error, and `DB_SCHEMA=foldme` works. The lookup, CREATE and SET all use the exact name, so they agree with each other and with `main`'s quoting (I-3).

**Special characters and injection**, all as `app_c`:
- `x"; CREATE TABLE public.pwned(i int); --`
- `o'brien`
- `a\b`
- `x' OR '1'='1`
- `ünï cødé`

Each was created verbatim as a single schema and `current_schema()` returns it unchanged. `public.pwned` does not exist.

As `app_nc`, `shared' OR '1'='1` and `shared"; SET ROLE …; --` do not match `shared`. They get the clear "does not exist" error, so the lookup is parameterised.

**Backslash password.**
- A `DB_PASSWORD` with a backslash in it (`QA-SECRET-MARKER\x`) connects on the host/port path.
- Inside `DB_DSN`, the backslash has to be doubled (libpq conninfo escaping). The single-backslash form fails the same way on `main` and the PR (I-2).

**Race.** Eight processes started together on a fresh schema, 40 rounds, 320 connections per tree, as `app_c`:
- PR: 248 OK and 72 `23505 duplicate key … pg_namespace_nspname_index`.
- `main`: 240 OK and 80 of the same error.

This is pre-existing (L-2). The no-CREATE race path was simulated with a PDO subclass that has the admin role create the schema between the lookup and the CREATE. The 42501 recheck finds the schema and the connect succeeds.

**Schema exists without USAGE.** The connect succeeds and the migration then fails with `3F000 no schema has been selected to create in`. It's a clear database error but not the friendly message (I-6).

#### 6. The PM's leak case

All requests went to `php -S` with the shipped settings from `docker/php.ini` (`display_errors=0`, `log_errors=1`) and `zend.exception_ignore_args=0`, to get the most detail in the log. The needles checked in every response's headers and body were:

- the password marker and its backslash form
- `pgsql:` and `dbname=`
- the DB host and the wrong host
- the DB port and the closed port
- the DB user
- the schema text and raw `<b>x</b>`
- `SQLSTATE`, `PDOException` and `Stack trace`
- file paths

| Scenario | `/` | `/healthz` | `/health` | `/labels` | `/settings` | `/login` | Response leaks | Log |
|---|---|---|---|---|---|---|---|---|
| Bad password (marker + backslash) | 500 generic page | 500 empty | 500 empty | 500 generic | 200 | 303 | none | host, port, user, SQLSTATE, trace; no password |
| Password inside `DB_DSN` | 500 | 500 | 500 | 500 | 200 | 303 | none | as above; no password, no DSN |
| Wrong host (refused) | 500 | 500 | 500 | 500 | 200 | 303 | none | wrong host, port, SQLSTATE |
| Closed port | 500 | 500 | 500 | 500 | 200 | 303 | none | closed port, SQLSTATE |
| Missing schema `qa_missing_<b>x</b>`, no CREATE | 500 fallback page | 500 | 500 | 500 | 500 | 500 | none | full message with schema name |
| Bad password, `Accept: application/json` | 500 `{"ok":false,"error":"Server error"}` | 500 | 500 | 500 JSON | 200 | 200 JSON | none | as bad password |

- **The password marker never appears anywhere,** including the log. The PDO password argument is redacted as `SensitiveParameterValue` in traces, and `db_connect(Array)` doesn't print the array.
- **The fallback page escapes HTML.** It was driven directly with `view_error(500, '<script>alert("qa")</script> & "quote"')` while connect fails. The output is `&lt;script&gt;alert(&quot;qa&quot;)&lt;/script&gt; &amp; &quot;quote&quot;` in a 187-byte page with no `<script>`. The log line is `[apotheke] error page: PostgreSQL schema "qa_missing_fb" does not exist…`.
- **With `display_errors=On`,** `/healthz` and `/health` show the uncaught PDO message (host, port, user, SQLSTATE, paths) with status 200. Page routes stay clean. This happens on `main` too (I-1). The password marker is not shown even then.

#### 7. Mutations

Each mutant ran `PgsqlSchemaCreateTest` plus the #41 section of `tests/integration.php` against the same PostgreSQL 17. The unmutated head passes 2/2 unit tests (9 assertions) and 12/12 integration checks.

| # | Mutant | Unit | Integration (#41 section) | Result |
|---|---|---|---|---|
| M0 | `views.php` reverted to `main` | pass | fail: "browser gets the generic error page" | killed |
| M1a | `if (true)`: always CREATE, with the 42501 recheck kept | pass | pass | survives, equivalent (I-5) |
| M1b | old unconditional `CREATE SCHEMA IF NOT EXISTS` restored | error 42501 + fail | fail | killed |
| M2 | lookup with the quoted name `"name"` | error | fail (`/` 500) | killed |
| M3 | lookup with `strtolower($schema)` | pass | pass | **survives (L-1)** |
| M4 | identifier not quoted in CREATE and SET | pass | pass | **survives (L-1)** |
| M5 | SQL `nspname = lower(?)` | pass | pass | **survives (L-1)** |
| M6 | error message without the schema name | fail | fail | killed |
| M7 | `unset($db['password'])` removed | pass | pass | survives, defensive (I-5) |

The surviving mutants have real effects:
- M3 and M5 make an existing `"MixedCase"` schema unusable for a role without CREATE.
- M4 runs `SET search_path TO inj; CREATE TABLE public.pwned_m4(…)` as two statements. It was stopped only by the role's missing privilege on `public`. It also leaves `current_schema()` NULL for a mixed-case name.

#### 8. Suites and static checks

The suites ran on the PR worktree with PHP 8.4.26 and PostgreSQL 17, with a clean environment.

| Suite | Result |
|---|---|
| PHPUnit, SQLite (with `TEST_PG_*` set, so `PgsqlSchemaCreateTest` runs) | OK: 285 tests, 2133 assertions, 6 skipped |
| PHPUnit, `DB_DRIVER=pgsql` | OK: 285 tests, 2134 assertions, 6 skipped |
| `tests/run.sh` (lint + HTTP integration, SQLite) | 1423 passed, 0 failed |
| `tests/integration.php`, `TEST_DB_DRIVER=pgsql` | 1191 passed, 0 failed, including all 12 #41 checks |
| `scripts/lint.sh` (php -l, node --check, Markdown links, shellcheck, pipefail greps) | ok, 188 relative links in 16 files, 0 broken |
| actionlint on `.github/workflows/*.yml` | clean |
| gitleaks (`--no-git`) on the 12 changed files and on the full delta diff | no leaks |
| CI 39559061598 | 10/10 `success` on `10f4d80`: Lint, Tests sqlite, Tests pgsql (PHPUnit including the HTTP suite), Conventional Commits, mobile, 3× Playwright, Docker build + smoke, secret scan |

- **Skips.** All 6 are `QrCodeTest` round-trips that need `zbarimg`, which isn't installed here. Both CI test legs install zbar and run them.
- **Unchanged files.** The Dockerfile, `docker/` and `.github/` are byte-identical to `main`. No shell scripts changed.
- **#37 spot check.** `src/image-color.php`, `src/items.php`, `src/autofill.php`, the photo views and editor, `scripts/docker-heic.sh` and `tests/imagick_color_check.php` are byte-identical to `main`. `ImagickColorTest` passes 4/4 (74 assertions), including the HEIC writer, because Imagick here lists HEIC. `tests/imagick_color_check.php` prints `ok imagick white background and sRGB`.

### Round 1 findings

These are as found at `10f4d80`. Their current status is in [Finding status at the final head](#finding-status-at-the-final-head).

- **L-1 (Low, test gap): case folding and identifier quoting aren't pinned by the tests.** Mutants M3, M4 and M5 survive because every test schema is a random lowercase `[a-z0-9_]` name, and `test_pg_ident()` refuses anything else. The shipped code is correct (§5). Suggested fix:
  - Add one unit case where the admin role creates a mixed-case schema containing a double quote, for example `S_Mixed"q_<hex>` (mixed case plus a double quote) via `format('%I', …)`, owned by the no-CREATE role.
  - Assert that `db_connect()` succeeds and that `current_schema()` equals the exact name.
  - This kills M3, M4 and M5.
- **L-2 (Low, pre-existing): concurrent first boot with a CREATE-capable role can fail one request.** `CREATE SCHEMA IF NOT EXISTS` can raise `23505` (unique violation on `pg_namespace_nspname_index`) when two sessions create the same missing schema at once. That happened to 72 of 320 connections, against 80 of 320 on `main`. The PR's recheck only handles `42501`, so the losing request gets a 500 and the next request is fine. Suggested fix: treat `23505` and `42P06` like the 42501 branch (recheck `db_pgsql_schema_exists()` and carry on).
- **L-3 (Low, pre-existing, now contradicted by the docs): the `bin/migrate.php` banner prints the full DSN.** The banner (`Database: pgsql – <DSN> (schema …)`) prints the whole DSN, including `password=…` when the password is inside `DB_DSN`. This was confirmed with the marker. The file is unchanged, but the new docs (`configuration.md` and `deployment-and-operations.md`) say the CLI message "does not include the password or the DSN". That's true of the error message but not of the CLI's first line. Suggested fix: redact `password=` (or print only host, port and database) in the banner. Otherwise, narrow the docs sentence to "the error message".
- **I-1 (Info, pre-existing): with `display_errors=On`, `/healthz` and `/health` show connect errors and return 200.** Both routes call `db()` before `index.php`'s `try`. With `display_errors=On` (for example a bare `php -S` with no ini), a connect failure prints the PDO message (host, port, user, SQLSTATE, paths) and the status stays 200. The shipped image sets `display_errors = Off`, which gives an empty 500. `main` behaves the same. A small follow-up could catch the error there, log it and return 503.
- **I-2 (Info, pre-existing): backslashes in a password inside `DB_DSN` must be doubled** (libpq conninfo escaping). `DB_PASSWORD` takes them verbatim. This could be a docs note.
- **I-3 (Info): `DB_SCHEMA` is the exact, case-sensitive name.** That matches `main`'s quoting. A schema created unquoted (`CREATE SCHEMA FoldMe`) is `foldme`. The docs could say so.
- **I-4 (Info, pre-existing): no connect timeout is set.** A DB host whose DNS answer blackholes takes about 30 s per request before the 500, on both `main` and the PR.
- **I-5 (Info): two mutants survive but are equivalent or defensive.** M1a survives because the 42501 recheck rescues an always-CREATE. The outcome is correct, at the cost of one server-side ERROR per connect. M7 survives because PHP already redacts the PDO password argument and frames print `Array`, so the `unset` is defence in depth.
- **I-6 (Info): two minor rough edges.**
  - A schema that exists without USAGE for the role connects and then fails in migration with `3F000 no schema has been selected to create in`. It's clear, but not the friendly message.
  - `test_pg_params()` prefers the app's `DB_*` environment when it's set. A shell with `DB_DRIVER=pgsql` exported but no `DB_PASSWORD` makes the #41 integration section fatal instead of using `TEST_PG_*`. CI is unaffected.

## Round 2: recheck at `645ec22`

There are three commits on top of `10f4d80`:

- `e3765a3` `test(db): cover mixed-case and quoted schema names`
- `ecd909a` `fix(db): ignore duplicate schema create races`
- `645ec22` `fix(migrate): omit passwords from the database line`

The delta is 7 files: `src/db.php`, `src/config.php`, `bin/migrate.php`, `docs/configuration.md`, `docs/deployment-and-operations.md`, `tests/Unit/PgsqlSchemaCreateTest.php` and the new `tests/Unit/MigrateDsnTest.php`. The tarball was re-verified with `git get-tar-commit-id` as `645ec22770bb…`.

### Delta

- **`src/db.php`.** If `CREATE SCHEMA IF NOT EXISTS` raises 42501, 42P06 or 23505, connect runs a fresh `pg_namespace` lookup and continues only if the schema exists now. If not:
  - 42501 still becomes the clear "does not exist, and this database role cannot create it" error.
  - 42P06 and 23505 are rethrown as-is.
  - Any other SQLSTATE is always rethrown.
- **`src/config.php`.** The new `config_redact_password()` tokenises the DSN after the `driver:` prefix into `key=value` pairs, separated by `;` or whitespace, with single-quoted values following libpq's `\'` and `\\` escapes. It drops every key equal to `password` in any letter case, plus the separators after it.
- **`bin/migrate.php`.** The banner prints `config_redact_password(config_dsn(…))`. The SQLite branch is unchanged.
- **Tests.**
  - `PgsqlSchemaCreateTest` grows from 2 to 8 tests: mixed case, a name containing `"`, and 42P06/23505 both with the schema present afterwards and still missing. The race cases use a `PDO` subclass that raises the SQLSTATE.
  - `MigrateDsnTest` runs `bin/migrate.php --status` with 7 DSN and `DB_PASSWORD` shapes.

### 1. The case and quoting mutants (L-1)

They now fail the tests:

| Mutant | Result at `645ec22` |
|---|---|
| M3 lookup `strtolower($schema)` | killed: `testMixedCaseSchemaNameIsCreatedAndReusedExactly` errors |
| M4 identifier not quoted | killed: both the mixed-case and the quote test fail |
| M5 SQL `nspname = lower(?)` | killed: the mixed-case test errors |

The unmutated head passes 8 tests with 24 assertions.

### 2. Concurrency race (L-2)

The setup was the same as round 1: 8 processes per round, 40 rounds, 320 connections, a role with CREATE, and a fresh missing schema each round.

| Tree | OK | Failed |
|---|---|---|
| `645ec22` | **320** | **0** |
| `10f4d80` (control, same run) | 252 | 68 × `23505 … pg_namespace_nspname_index` |

All 80 schemas exist afterwards.

**Other errors still surface:**
- A reserved name (`pg_…`) raises `42939 unacceptable schema name`.
- A role without CREATE and a missing schema still gets the clear `RuntimeException`.
- `Mixed"Q` is created and then reused.

**Race-code mutants:**

| Mutant | Result |
|---|---|
| R1 swallow 42501/42P06/23505 without the fresh lookup | killed (3 failures, including both "still fails when the schema is missing" cases) |
| R2 drop `23505` from the list | killed |
| R3 drop `42P06` from the list | killed |
| R4 continue on *any* SQLSTATE if the schema exists afterwards | survives (I-7) |

So 42P06 and 23505 are swallowed only when the schema really exists afterwards. Otherwise the original `PDOException` with its SQLSTATE surfaces.

### 3. Password redaction in the `bin/migrate.php` banner (L-3)

Each form ran through `bin/migrate.php --status` with a marker password, also with `DB_PASSWORD` set to a second marker. In every row, "rest kept" means the other keys still print; for example `host`, `port`, `dbname` and `user`.

| `DB_DSN` form | Banner contains the password? | Rest kept |
|---|---|---|
| `…;PASSWORD=<marker>;user=…` | no | yes |
| `…;PassWord=<marker>` | no | yes |
| `…;password='a <marker> b\'c';user=…` (quoted, spaces, `\'`) | no | yes |
| `…;password='x\\ <marker>';user=…` | no | yes |
| `host=…;password=<marker>;port=…;dbname=…;user=…` (middle) | no | yes |
| `password=<marker>;host=…` (first) | no | yes |
| `host=… port=… user=… password=<marker> dbname=…` (space-separated) | no | yes |
| tab-separated | no | yes |
| two `password` keys | no | yes |
| `password=` empty, or an unterminated quote | no | yes |
| `DB_PASSWORD` only (no `DB_DSN`) | no; the DSN has no password key | yes |
| `password= <marker>` (space **after** `=`) | **yes** | (L-4) |
| `password =<marker>` / `password = <marker>` (space **before** `=`) | **yes** | (L-4) |
| `password=QA\ <marker…>` (backslash-escaped space, unquoted) | **the part after the space** | (L-4) |
| `postgresql://user:<marker>@…` and `postgresql://…?password=<marker>` (URI) | **yes** | (L-4) |

The second marker set in `DB_PASSWORD` never appeared in any run.

**Which L-4 forms actually connect** through PDO and libpq 17:
- `password= <marker>` and the unquoted `\ ` escape both connect, so they're realistic leaks.
- The space-before-`=` and both URI forms fail to connect here, but the banner prints before connecting, so a failed attempt still echoes the secret.

**Redaction mutants**, checked against `MigrateDsnTest`:

| Mutant | Result |
|---|---|
| D1 `strcasecmp` → `strcmp` | killed (upper-case and mixed-case cases) |
| D2 quoted values parsed as unquoted | killed |
| D3 no `\` escape inside quotes | killed |
| D5 prefix regex never matches (return the DSN unchanged) | killed (6 cases) |
| D8 drop everything after the password key | killed (6 cases) |
| D4 don't skip the separators after the password | survives, cosmetic only (an extra `;`) |
| D6 redact only the first `password` key | survives (I-8) |
| D7 prefix regex accepts lower case only | survives, equivalent for `pgsql:` |

### 4. Docs, suites, CI and commits

- **Docs.** Both updated paragraphs match the behaviour above, with two caveats. "Drops a `password` key … in any letter case, including a single-quoted value" doesn't hold for the L-4 forms. "Prints the driver, host, database, and schema" undersells it: the banner prints every non-password key, such as `port`, `user` and `sslmode`, which is fine but wider than stated (I-9). "It never prints `DB_PASSWORD`" holds.
- **Suites.** These ran with a clean environment (`env -i`), PHP 8.4.26 and PostgreSQL 17.

| Suite | Result |
|---|---|
| PHPUnit, SQLite (with `TEST_PG_*`) | OK: 298 tests, 2203 assertions, 6 skipped |
| PHPUnit, `DB_DRIVER=pgsql` | OK: 298 tests, 2204 assertions, 6 skipped |
| `tests/run.sh` | 1423 passed, 0 failed |
| `tests/integration.php`, PostgreSQL | 1191 passed, 0 failed |
| `scripts/lint.sh` | ok, 188 links, 0 broken; shellcheck ok |
| actionlint | clean |
| gitleaks (`--no-git`) on the 7 changed files and the delta diff | no leaks |

  All 6 skips are the `zbarimg` QR round-trips. The Dockerfile, `docker/` and `.github/` are unchanged from `10f4d80`.
- **CI.** Run 39648199139 at `645ec22`: 10/10 `success`, the last job finishing at 21:05 EEST.
- **Commits.** All four subjects pass `scripts/check-commits.sh --message` (Conventional Commits, 72 characters or fewer). The `Conventional Commits` CI job is green.

## Round 3: merged heads `c035dc0` and `a600a05`, and the v2.5.5 replay

### First merge of `main`: `c035dc0`

- `c035dc0` has parents `645ec22` and `ba4644e` (`main` with #47).
- Compared with `645ec22`, it adds exactly `Dockerfile`, `docs/getting-started.md` and `docs/qa/apotheke-pr46-imagick-pin-qa.md`, byte-identical to `main`.
- `git merge-tree --write-tree 645ec22 ba4644e` gives tree `4f0a94d8…`, which is `c035dc0`'s tree, so the merge has no conflict edits.
- gitleaks on the merge diff finds no leaks.
- At `c035dc0`: `scripts/lint.sh` ok (188 links, 0 broken), PHPUnit on SQLite OK (298 tests, 16 skipped with no PostgreSQL reachable), and `tests/run.sh` 1423 passed.
- CI run 39650258599: 10/10 `success`, Docker build + smoke included. The last job finished at 21:21 EEST.

### Final head: `a600a05`

- `a600a05` has parents `c035dc0` and `7a20646`. `7a20646` is `main` after #45 merged; its parents are `ba4644e` and #45's head `0a35d21`.
- `git rev-list --left-right --count main...a600a05` gives `0 6`: 6 ahead (the 4 PR commits and 2 merges) and 0 behind.
- Compared with `c035dc0`, it adds exactly #45's four files: `AGENTS.md`, `src/qr.php`, `tests/Unit/QrCodeTest.php` and `docs/qa/apotheke-pr45-qr-rename-qa.md`. Each blob is identical to `main`'s.
- `git merge-tree --write-tree c035dc0 7a20646` gives tree `799a7e60…`, which is `a600a05`'s own tree, so there are no conflict edits.
- `git diff main a600a05` is still exactly the PR's own change set: 14 files, +648/−15. It equals `c654569..645ec22` apart from hunk offsets in `docs/getting-started.md`.
- gitleaks on the `c035dc0..a600a05` diff finds no leaks.
- At `a600a05`: `scripts/lint.sh` ok (188 links, 0 broken), PHPUnit on SQLite OK (298 tests, 16 skipped with no PostgreSQL reachable), and `tests/run.sh` 1423 passed.

### v2.5.5 release replay

**Final replay.** On a clone of the real `main` `7a20646`, where #45 is merged, I made one `--no-ff` "Merge pull request #44" commit of `a600a05` and ran `php scripts/release.php plan` and `apply --notes=…`. The results:

- `plan`: `current=2.5.4 last_tag=v2.5.4 commits=12 bump=patch next=2.5.5 release=true`
- The tree after the merge is `799a7e60…`.
- After `apply`, `VERSION` is `2.5.5`, and `apply` touches only `CHANGELOG.md` and `VERSION`.
- The release notes are byte-identical to the simulated replay at `c035dc0`, where #45 was merged first or second:

  - **Fixed:**
    - db: skip CREATE SCHEMA when DB_SCHEMA already exists (#44)
    - db: ignore duplicate schema create races (#44)
    - migrate: omit passwords from the database line (#44)
  - **Changed:** qr: rename qr_php84_jit_is_tracing (#45)
  - **Maintenance:**
    - #47: `build(docker)` fetch imagick from a pinned GitHub tarball, and the `docs(qa)` #46 imagick report
    - #45: QR rename QA report
    - #44: `test(db)` mixed-case and quoted schema names
    - #40: four docs and changelog commits that landed on `main` after the v2.5.4 tag

This report's own `docs(qa):` commit will add one more Maintenance line for #44. It doesn't change the bump.

### CI at `a600a05`

Run 39652000887: 10/10 `success`, all on `a600a05`: Lint, Tests (PHP 8.3, sqlite), Tests (PHP 8.3, pgsql), Conventional Commits, Headless mobile checks, Docker build + smoke test, Visual E2E shards 1/3, 2/3 and 3/3, and Secret scan. The last job finished at 21:32 EEST.

## Round 4: `bf5505e`, the L-4 cause fix

There's one commit on `a600a05`: `bf5505e` `fix(migrate): print only allowlisted DSN fields`.

### Delta

- Exactly 5 files change, +204/−67: `bin/migrate.php`, `src/config.php`, `tests/Unit/MigrateDsnTest.php`, `docs/configuration.md` and `docs/deployment-and-operations.md`.
- `git rev-list --left-right --count main...bf5505e` gives `0 7`.
- The subject passes `scripts/check-commits.sh --message`, and the Conventional Commits CI job is green.
- **`src/config.php`.**
  - `config_redact_password()` is gone. `git grep config_redact_password` at `bf5505e` finds nothing; at `a600a05` its only caller was `bin/migrate.php`.
  - The new `config_migrate_where()` returns the file path for SQLite. Otherwise it parses the DSN with `config_dsn_public_fields()` and prints only `host=`, `port=` and `dbname=`, plus ` (schema X)`. A DSN that doesn't parse prints `<unparsed dsn>`.
  - The parser has two branches:
    - URI DSNs (`scheme://`) go through `parse_url`, keeping host, port and the path as dbname.
    - Keyword DSNs go through a libpq-style tokeniser. It allows whitespace around `=`, handles single-quoted values with `\'` and `\\`, and treats an unquoted `\` as an escape. It returns null (unparsed) on an empty key, a missing `=`, an unclosed quote or a trailing backslash. The last duplicate key wins.
- **`bin/migrate.php`.** The banner is now `Database: <driver> – <config_migrate_where()>`.
- `src/views.php`, `src/db.php` and `public/index.php` are unchanged from `a600a05`, so the round-1 leak paths (§6) are as verified then.
- `MigrateDsnTest` now has 21 cases: 20 DSN shapes plus SQLite.

### 1. Banner matrix (L-4)

About 40 `DB_DSN` shapes ran through the new banner code with a marker password and a marker user. They covered:

- every L-3 and L-4 form: space before or after `=`, an unquoted `\ ` escape, and quoted values with `\'`, `;` and `\\`
- URIs: bare, with userinfo, with `?password=`, with `%40`, with a fragment, and with a socket host
- `pgsql:postgresql://…`
- IPv6 hosts and duplicate keys
- an unterminated quote, a trailing backslash, a missing `=`, an empty key, junk, and `pgsql:` alone
- a mixed-case prefix
- `sslpassword`, `passfile`, `sslkey`, and `options='-c password=…'`
- `hostaddr` and `service`
- Unicode and 100 KB values
- tab and newline separators, and a NUL byte
- `DB_PASSWORD` set with no DSN, and SQLite

**Results:**

- The password marker never appears, except in the two by-design rows below.
- The user never appears, including `USER=`.
- `host`, `port` and `dbname` print correctly or not at all. For URIs, userinfo, query and fragment are dropped. The unparseable shapes print `<unparsed dsn>`.

**Two by-design rows.** `dbname='x password=<marker>'` and `host=h\ password=<marker>` print the whole dbname or host value, marker included. That's correct parsing, not a leak: libpq 17 reads them the same way. The first connects without a password ("no password supplied"). The second tries to resolve the literal host name `h password=…`. `MigrateDsnTest` pins the same expectation. These values are printed verbatim by design (I-11).

**Real `bin/migrate.php` runs.** These used PostgreSQL 17 with `DB_SCHEMA=shared` and a no-CREATE role. The forms were:

- a wrong `DB_PASSWORD`
- a wrong password in `DB_DSN`
- space after `=`, space before `=`
- a quoted value and an unquoted escaped space
- a bare URI
- a closed port
- the correct password

The password appears in no run's banner or error output. The banner is always `host=… port=… dbname=… (schema shared)`. The correct password exits 0 and applies all 25 migrations. The libpq error text still names the user (`for user "…"`), but that's error output, not the banner, as in round 1.

**libpq cross-check.**
- libpq rejects `HOST=` ("invalid connection option"), while the tokeniser lowercases keys and prints it as `host` (I-12; harmless).
- For a duplicate `host`, the last one wins in both.
- `password= x` and `password = x` both connect.

### 2. Tokeniser mutants

Each mutant ran against `MigrateDsnTest`:

| Mutant | Result |
|---|---|
| T1 quotes not recognised | killed |
| T2 no escapes inside quotes | killed |
| T3 no unquoted `\` escape | killed |
| T4 `user` added to the allowlist | killed |
| T5 fallback prints the fields parsed so far | killed |
| T5b fallback prints the raw DSN | killed |
| T6 no whitespace allowed around `=` | killed |
| T7 case-sensitive keys | killed (the tests pin the lowercasing, see I-12) |
| T8 first duplicate wins | killed |
| T9 URI userinfo kept | killed |
| T10 URI query kept in dbname | killed |
| T11 no URI branch | killed |
| T12 `;` not a separator | killed |
| T13 unclosed quote accepted | killed |
| T14 trailing backslash accepted | survives (I-13) |
| T15 empty key accepted | survives (I-13) |

### 3. Docs

Both docs now say that `bin/migrate.php` prints only the driver, host, port, dbname and `DB_SCHEMA`, never the user or password. They also say SQLite prints the file path and an unparseable DSN shows as `<unparsed dsn>`. That matches the banner exactly, so **I-9 is fixed**.

### 4. Suites, lint and gitleaks

These ran with a clean environment (`env -i`), PHP 8.4.26 and PostgreSQL 17:

| Suite | Result |
|---|---|
| PHPUnit, SQLite (with `TEST_PG_*`) | OK: 312 tests, 2261 assertions, 6 skipped |
| PHPUnit, `DB_DRIVER=pgsql` | OK: 312 tests, 2262 assertions, 6 skipped |
| `tests/run.sh` | 1423 passed, 0 failed |
| `tests/integration.php`, PostgreSQL | 1191 passed, 0 failed |
| `scripts/lint.sh` | ok, 188 links in 16 files, 0 broken; shellcheck ok |
| gitleaks (`--no-git`) on the 5 files and the delta diff | **2 findings**, `generic-api-key`, `tests/Unit/MigrateDsnTest.php` line 129 (and its diff line) |
| gitleaks git mode, `a600a05..bf5505e` and full history (`--all`) | **1 finding**, same file and line; history up to `a600a05` is clean |

The finding is a test fixture: the missing-`=` case, whose `password=` value is a made-up word with digits. It isn't a real credential, but it's high-entropy enough to match `generic-api-key`.

### 5. CI at `bf5505e`

Run 39655311295 is **9/10**:

- These pass: Lint, Tests (PHP 8.3, sqlite), Tests (PHP 8.3, pgsql), Conventional Commits, Headless mobile checks, Docker build + smoke test, and Visual E2E shards 1/3, 2/3 and 3/3. The last job finished at 21:53 EEST.
- **Secret scan fails.** The step `gitleaks git --log-opts="--all" --redact --no-banner --exit-code 1` (gitleaks 8.30.1) reports `leaks found: 1` and exits 1, at 21:43 EEST. The local reproduction above names the fixture on line 129. This is M-1.

### 6. v2.5.5 release replay

On a clone of `main` `7a20646`, I made one `--no-ff` "Merge pull request #44" commit of `bf5505e` and ran `plan` and `apply`:

- `plan`: `current=2.5.4 last_tag=v2.5.4 commits=13 bump=patch next=2.5.5 release=true`
- After `apply`, `VERSION` is `2.5.5`, and only `CHANGELOG.md` and `VERSION` change (+27/−2).
- The notes equal the `a600a05` replay plus one **Fixed** line: "migrate: print only allowlisted DSN fields (#44)". It's still a patch release, still 2.5.5.

A follow-up commit for M-1 (`test(…)` or `chore(…)`) would add a Maintenance line at most and doesn't change the bump.

## Round 5: `6986578`, the M-1 fix

There's one commit on `bf5505e`: `6986578` `chore(ci): allowlist the migrate DSN placeholder`.

### Delta

- Compared with `bf5505e`, exactly 2 files change, +13/−4: the new 9-line `.gitleaks.toml` and the Secret scan paragraph in `docs/ci.md`.
- `git rev-list --left-right --count 7a20646...6986578` gives `0 8`.
- The subject passes `scripts/check-commits.sh --message`.
- `.gitleaks.toml` has three parts:
  - a comment explaining the fixture
  - `[extend] useDefault = true`
  - one global `[allowlist]` with `regexes = ['''SecretMissingEq99''']`

### Allowlist scope

Everything here was checked with the pinned gitleaks **8.30.1** binary, using the workflow's own SHA-256.

- **Config pickup.**
  - The workflow runs `gitleaks git --log-opts="--all" --redact --no-banner --exit-code 1` with no `--config` flag and no `GITLEAKS_CONFIG*` variable.
  - gitleaks 8.30.1 auto-loads `.gitleaks.toml` from the scan source, which is the checkout root. The debug log confirms it: "using existing gitleaks config .gitleaks.toml from `(--source)/.gitleaks.toml`" and "extending config with default config".
  - The TOML parses with no error or deprecation warning. The only debug note is the optional `minVersion` hint.
- **Default rules kept.** I scanned a canary corpus with this config and with a plain `useDefault = true` config. The findings were identical apart from the three allowlisted hits, and every exercised default rule still fired: `aws-access-token`, `github-pat` and `generic-api-key`.
- **Target and anchoring.**
  - A global `[allowlist]` regex has no `regexTarget` set, so it uses the default `secret` target. It matches the extracted secret, not the line or the file.
  - The regex is unanchored, so any secret that *contains* `SecretMissingEq99` is suppressed (I-14).

### History scan

These used CI's exact arguments on a scratch clone:

| Scan | Result |
|---|---|
| `6986578`, full history | **no leaks found** |
| `bf5505e` (control, no `.gitleaks.toml`) | 1 finding, the fixture (M-1) |
| Canary commit on `6986578`: a random generic key on a new line in `MigrateDsnTest.php`, a second random key **on the fixture line itself**, and a random AWS access key ID plus a GitHub PAT in another PHP file | 4 findings, all caught: `generic-api-key` ×2, `aws-access-token`, `github-pat`. The fixture alone is still suppressed. |
| Same canary commit, default rules without the allowlist | 7: the same 4, plus the fixture at `bf5505e`, the fixture on the canary line, and a test secret `SecretMissingEq99<random>` in the other file |
| Same, with the regex anchored as `^SecretMissingEq99$` | 5: the superset secret is caught too, and `6986578` is still clean |

So a real key elsewhere in the test file, on the fixture's own line, or in another file is still flagged. Only a secret containing the placeholder string is hidden.

### Docs

The new `docs/ci.md` paragraph is accurate:
- `.gitleaks.toml` allowlists one migrate-banner fixture, `SecretMissingEq99`.
- `generic-api-key` flags it.
- It stays in history.
- The guidance remains "smallest possible pattern with a comment" and "do not allowlist a real-looking secret; remove it from history instead".

The README's Secret scan rows don't mention the allowlist, and they didn't need to. `scripts/lint.sh`: 188 links in 16 files, 0 broken.

### v2.5.5 release replay

On a clone of `main` `7a20646`, I made a `--no-ff` "Merge pull request #44" commit of `6986578`:

- `plan`: `current=2.5.4 last_tag=v2.5.4 commits=14 bump=patch next=2.5.5 release=true`
- After `apply`, `VERSION` is `2.5.5`, and only `CHANGELOG.md` and `VERSION` change.
- The notes equal the `bf5505e` replay plus one **Maintenance** line, "ci: chore: allowlist the migrate DSN placeholder (#44)". The **Fixed** section is unchanged, and the bump is unchanged.

### CI at `6986578`

Run 39657178694: 10/10 `success`, all on `6986578`: Lint, Tests (PHP 8.3, sqlite), Tests (PHP 8.3, pgsql), Conventional Commits, Headless mobile checks, Docker build + smoke test, Visual E2E shards 1/3, 2/3 and 3/3, and **Secret scan** (green at 21:56 EEST). The last job finished at 22:05 EEST.

## Finding status at the final head

| ID | Severity | Status |
|---|---|---|
| L-1 tests don't pin case folding or quoting | Low | **Closed** at `e3765a3`: M3, M4 and M5 are killed |
| L-2 concurrent create race (23505) | Low | **Closed** at `ecd909a`: 320/320 OK, and swallowed only after a fresh lookup finds the schema |
| L-3 `bin/migrate.php` banner prints the password from `DB_DSN` | Low | **Closed** at `645ec22` for the common forms, and fully at `bf5505e` together with L-4 |
| L-4 banner redaction misses some forms | Low | **Closed** at `bf5505e`. The banner now prints only allowlisted `host`, `port` and `dbname`, or `<unparsed dsn>`. All L-3 and L-4 forms and about 40 more shapes leak neither password nor user, including in real runs on PostgreSQL 17, and the tokeniser mutants T1 to T13 are killed. |
| M-1 new test fixture fails the CI Secret scan | Medium | **Closed** at `6986578`. The narrow `.gitleaks.toml` allowlist is picked up automatically by CI's gitleaks, and the full history at `6986578` is clean. Canary keys elsewhere in the same file, on the fixture's line, and in another file are still caught, and the Secret scan job is green. |
| I-1 to I-6 | Info | Unchanged from round 1 |
| I-7 | Info | Race mutant R4 (continue on any SQLSTATE when the schema exists afterwards) survives. It's harmless in practice, but nothing pins "only these three codes". |
| I-8 | Info | Moot at `bf5505e`: D6 targeted the removed `config_redact_password()`. The new code's last-duplicate-wins rule is pinned (T8 killed). |
| I-9 | Info | **Fixed** at `bf5505e`: the docs match the banner exactly. |
| I-10 | Info | Schema names over 63 bytes are truncated by PostgreSQL, so the exact-name lookup never matches. A CREATE-capable role still works through `IF NOT EXISTS`; a no-CREATE role gets the clear error. |
| I-11 | Info | Allowlisted values print verbatim. A `dbname` or `host` value that itself contains `password=…` (quoted, or with an escaped space) prints as-is. libpq reads it the same way, so it isn't a password, and the tests pin it. |
| I-12 | Info | The tokeniser lowercases keys, but libpq keywords are case-sensitive (`HOST=` is rejected). The banner may show a host that libpq would refuse. It's harmless, since the connection fails with libpq's own error. |
| I-13 | Info | Tokeniser mutants T14 (accept a trailing backslash) and T15 (accept an empty key) survive. Neither can leak, because only allowlisted keys print. |
| I-14 | Info | The `.gitleaks.toml` regex is unanchored, so any secret that contains `SecretMissingEq99` is also suppressed. That's narrow in practice, since a real key wouldn't embed this public placeholder. Anchoring it as `^SecretMissingEq99$` (tested: still clean, and the superset is caught) and adding `minVersion = "8.30.1"` would tighten it. |

## Clean-up

The PostgreSQL containers (with their volumes) and `php8.4-pgsql` were removed after each round, including round 4. Round 5 needed neither. Its gitleaks binary and scratch clones were temporary and have been deleted. Other agents' containers were not touched. No credentials, hosts or addresses from the test setup are in this report; the role passwords and markers were throwaway test values.
