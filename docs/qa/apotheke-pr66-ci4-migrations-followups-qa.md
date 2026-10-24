# QA: apotheke PR #66, L-D/L-E/N-A–N-C follow-ups to the CI4 migrations port (replaces #63)

- **PR:** [#66](https://github.com/thordin9/apotheke/pull/66), "fix(ci4): L-D/L-E/N-A–N-C follow-ups to the migrations port", branch `ci4/migrations-followups-2` into `feature/ci4`, by Coder2. Draft. Replaces [#63](https://github.com/thordin9/apotheke/pull/63), which is closed.
- **Base:** `feature/ci4` at `a5cdc2f` (#65's merge). The branch starts at `f0956fa` and is 0 behind `a5cdc2f` after its merge commit.
- **Head tested:** `168800fdde6c7abd03ad1cf57504fb3e9075e63d`. That's 19 linear commits on `f0956fa` (`9157fe9` … `81afe1c`), then `168800f`, a plain merge of `origin/feature/ci4` (`a5cdc2f`). No force-push. The review started at `81afe1c`, and the head moved to `168800f` during it (2.1).
- **Changes vs `feature/ci4`:** 18 files, about +1433 / −116. `ci4/modules/Core/Config/{DsnTranslator,DatabaseFactory,PgsqlSchema}.php`, `ci4/modules/Core/Boot/startup.php`, `ci4/modules/Migrations/PdoMigrationRunner.php`, `ci4/tools/PhpStan/bootstrap.php`, `scripts/legacy-dsn-precedence.php`, `.github/workflows/ci.yml`, six tests, `CHANGELOG.md`, and `docs/{ci,configuration,deployment-and-operations}.md`.
- **CI:** run 40065073417 at `168800f`, **22/22 `success`** (08:08–08:18 Athens time, UTC+3). Run 40052538019 at `81afe1c`, also **22/22 `success`** (finished about 05:07 Athens time).
- **Date:** 2026-10-24 (Europe/Athens).
- **Scope:** read-only.
  - Sources: codeload tarballs of `f0956fa`, `95c58fd`, `81afe1c`, `168800f` and `a5cdc2f`, the GitHub API (PR, commits, check runs, job logs) and `main`'s `scripts/release.php`.
  - Local runs only in scratch copies against a throwaway PostgreSQL 17 with scram-sha-256.
  - No comment, review, push or merge was made.
- **Verdict: PASS.** There are no High, Medium or Low findings, only nits and notes.
  - The first 13 commits give exactly the tree of `8909907`.
  - The L-E hybrid behaves the same on real PHP 8.3.36 and 8.4.27 images.
  - Every mutation I tried is caught.
  - CI's e/e2 results (8.3 env/env, 8.4 failure/failure) are correct. They differ from my earlier case e because CI's e has no credentials in the DSN (2.4).

## 1. Test plan

### 1.1 Environment

- Tarballs unpacked side by side, with Composer dev dependencies installed from the locked `composer.lock` and `ci4/composer.lock`.
- The box's PHP **8.4.27** CLI (pdo_pgsql and pgsql), PostgreSQL **17.11** with `scram-sha-256` for TCP, and gitleaks **8.30.1**.
- **Real images.** I pulled the `php:8.3.36-fpm-alpine` and `php:8.4.27-fpm-alpine` rootfs (linux/amd64) from Docker Hub and ran them in a chroot. I added `pdo_pgsql` with `docker-php-ext-install`, against Alpine's **libpq 18.6**, the same as the shipped image.

### 1.2 Acceptance checks

1. **Replacement history.** Tree SHA of commit 13 against `8909907`. `485ab16` not in the history. All subjects 72 characters or fewer. 0 behind the base.
2. **Delta on top of the 13.**
   - The leak test uses `skipOutsideCi` and requires `writable/logs` to be unchanged.
   - The CHANGELOG `ci:` line, and a link to #66 on every new bullet.
   - e and e2 in the Docker precedence step, checked against the actual CI log values. Work out why my earlier case e gave `dsn` on 8.4.
3. **The warn-once flag.** Once per request under a web SAPI, once per spark run, and the runner test pinning one warning across three connects.
4. **L-E hybrid carry-over.**
   - The precedence matrix through the real ci4 runner and CI4's connect string, on both images.
   - Password and user edge cases.
   - Mutations: remove the embed, the mute, the `;` refusal, the once flag and the warning, and make the DSN win.
   - The leak test's CI behaviour.
5. **Merge commit `168800f`.** Three-way check of every file, and the conflict resolutions.
6. **Suites.** Full PHPUnit at `81afe1c` and `168800f`.
7. **Secrets.** gitleaks and a manual grep of the added lines.
8. **Cloudflare phase.** Storage behind an interface, per-request static or global state, DSN-driven DB config, and Hyperdrive `postgres://` (note only).

## 2. Results

### 2.1 History and delta (item 1)

| Check | Result |
|---|---|
| Tree of commit 13 (`95c58fd`) | `d707ae830e…`, **identical** to the tree of `8909907` |
| `485ab16` in history | **no** (compare `f0956fa...81afe1c`) |
| Subjects | the 19 non-merge subjects are 37–71 characters. The merge `168800f` is 81, but `check-commits.sh` skips `Merge …` subjects, and the Conventional Commits job is green |
| Behind base | `f0956fa...81afe1c`: 19 ahead, 0 behind. `168800f` merges `a5cdc2f`, so it's 0 behind current `feature/ci4` |

- **`95c58fd..81afe1c`** (6 commits) changes 8 files: `ci.yml` (step name), `CHANGELOG.md`, `PdoMigrationRunner.php` (once flag), `docs/ci.md`, `docs/configuration.md`, `scripts/legacy-dsn-precedence.php` (e, e2), `Ci4ConnectFailureLeakTest.php` and `Ci4DsnCredentialsTest.php`.
- **`81afe1c..168800f`** brings in #65's 7 commits (2.6).

### 2.2 Leak test, CHANGELOG, links (item 2)

- **`skipOutsideCi()` in `Ci4ConnectFailureLeakTest`** replaces the superuser `markTestSkipped`. I ran the test as a non-superuser role that has CREATEROLE:

| Env | Result |
|---|---|
| `CI=true`, `CI=1`, `GITHUB_ACTIONS=true` | **fails**: "a login event trigger needs a superuser. In CI the refused CI4 connect must run…" |
| no CI vars, `CI=TRUE` | skips (same exact-match rule as #65, see #65 N-1) |
| superuser | OK |

- **`writable/logs`.** The refused-connect case now asserts `assertSame('', $logs, 'CI4 wrote to writable/logs on a refused connect')`, instead of looking for two specific strings. With `pg_connect` unmuted, it fails (M2 below).
- **CHANGELOG.**
  - The `ci:` line is under Maintenance.
  - There are 7 new Unreleased bullets (5 Fixed, 1 Fixed SQLSTATE, 1 Maintenance `ci:`), and **all 7 link #66**.
  - `main`'s `release.php check-links` finds 11 unlinked older ci4 bullets at `168800f`. That's one fewer than `feature/ci4` today, because #66 rewrote one older bullet and linked it. The rest is #65's L-1, which DevOps owns.
- **Docs.** `docs/ci.md` and the CI step name say a–e2. `docs/configuration.md` describes the once-per-process warning.

**PASS**

### 2.3 Docker precedence step: CI values (item 2)

From the job logs at `168800f` (jobs 120786481895 and 120786481882), the same as at `81afe1c`:

| Case | 8.3.36 expected / got | 8.4.27 expected / got |
|---|---|---|
| a DSN user/password only | failure / failure | dsn / dsn |
| b DB_USER/DB_PASSWORD only | env / env | env / env |
| c both set, differing | env / env | dsn / dsn |
| d both set, DB_PASSWORD empty | failure / failure | dsn / dsn |
| e `user=` in `application_name` | env / env | failure / failure |
| e2 `password=` in `options` | env / env | failure / failure |

### 2.4 Why my earlier case e gave `dsn` on 8.4 (item 2)

The DSN credentials in the check are not deliberately wrong. The cases are different:

- **CI's e and e2** use `$base` (host, port and dbname only) plus `application_name='a user=b password=c'` or `options='-c application_name=password=x'`, with `DB_USER`/`DB_PASSWORD` set to the env role.
  - On 8.4, PDO sees `user=` or `password=` text in the DSN and doesn't append the passed credentials.
  - The DSN has no top-level user or password, so libpq falls back to its default user without a password, and the connection fails.
  - On 8.3, PDO always appends them, so the env role connects.
- **My earlier e** had DSN credentials as well (`$withDsn` plus `options` with `user=`). On 8.4 the DSN's own `user=`/`password=` connect, so the result is `dsn`.

I re-ran it through the real legacy `db_connect()` on both images as an extra row, e3 (both differing, plus `options='-c application_name=user=x'`). It gives **8.3.36 env, 8.4.27 dsn**, which matches my earlier matrix. Both results are correct. e3 is the case where CI4's embed path matters most, and the Docker step doesn't cover it (N-1).

**PASS**

### 2.5 L-E hybrid through the real ci4 code (item 4)

**The matrix.** A scratch script calls `PdoMigrationRunner::connect()` (the real runner) and reads back `current_user`. On the box's 8.4 it also opens CI4's connect string from `DsnTranslator::translate()` with `pg_connect`.

- The `pdoArgs` column is what `DsnTranslator::forPdo()` hands PDO as the user and password arguments.
- `env`/`dsn` are throwaway roles, and `x*` are roles whose password is the edge-case value.
- Results are **identical on PHP 8.3.36 (image), 8.4.27 (image) and 8.4.27 (box)**. `pg_connect` exists only on the box.

| Case | Runner | CI4 `pg_connect` | PDO args |
|---|---|---|---|
| a DSN creds only (default user, empty password) | failure | failure | set/set |
| b env only | env | env | set/set |
| c both, differing | **env** | **env** | set/set |
| d both, env password empty | **failure** (env wins, empty password) | failure | set/set |
| e env, `application_name` has `user=`/`password=` | **env** | env | **null/null** (embedded) |
| e2 env, `options` has `password=` | **env** | env | null/null |
| e3 both differing, `options` has `user=` | **env** | env | null/null |
| e4 both differing, `sslpassword` keyword | **env** | env | null/null |
| user with `;`, plain DSN | env role | env role | set/set |
| user with `;`, `options` has `user=` | **refused** (BootError text, no values) | refused | n/a |
| password `''`, plain / embed / embed with `password=` | failure ×3 (empty is no password, as legacy) | failure | set, null/null, null/null |
| password `a'b"c`, plain / embed ×2 | env role ×3 | ✓ | set, null, null |
| password ` a b ` (leading, inner, trailing spaces) | env role ×3 | ✓ | set, null, null |
| password `ab\` (trailing backslash) | env role ×3 | ✓ | set, null, null |
| password `xpassword=y user=z` | env role ×3 | ✓ | set, null, null |
| password unicode `pä§ß` | env role ×3 | ✓ | set, null, null |
| password `a;b`, plain | env role | ✓ | set/set |
| password `a;b`, `options` has `user=` only | env role | ✓ | null/**set** (passed to PDO, since no `password=` text) |
| password `a;b`, `application_name` has `password=` | **refused** | refused | n/a |

- **Warnings.** The whole run (a, c, d, e3 and e4 differ) logged the differing-credentials warning **once**, with no user or password in the log.
- **Refusal text.** The `MigrateConnectException` / BootError message is the fixed `SEMICOLON_CREDENTIAL` text. A grep for every password and user value finds nothing.
- **Mute.** `DatabaseFactory::apply()` wraps `initialize()` in `set_error_handler(static fn (): bool => true)` and restores it in `finally`.

**Mutations** (scratch copy, PostgreSQL 17 scram, filter `Ci4DsnCredentialsTest|Ci4ConnectFailureLeakTest|DsnTranslatorTest|DsnBootErrorTest`, 23 tests):

| # | Mutation | Result |
|---|---|---|
| M1 | never embed (`pdoParts` always passes credentials to PDO) | **killed**: 4 failures (`testSpecialCharacterCredentialsOnBothPaths`, `testEmbeddingIsOnlyUsedWhenTheDsnNeedsIt`, `DsnBootErrorTest` canary, `DsnTranslatorTest` embed) |
| M2 | unmute `pg_connect` (handler returns false) | **killed**: `testCi4PgConnectFailureShowsNoValues` |
| M3 | drop the `;` refusal | **killed**: 4 failures |
| M4 | warn on every connect (drop the once flag) | **killed**: `testRunnerFollowsLegacyOnPhp83OnEveryPhpVersion` (expects 1) |
| M5 | no warning at all | **killed**: 2 failures |
| M6 | DSN user wins | **killed**: 8 failures |

**Hyperdrive (note only).** `DsnTranslator::fromPdoDsn()` still accepts only `sqlite:` and `pgsql:`, so a `postgres://` URL is a bad DSN at boot. Nothing here changes that, and it stays deferred (I-3).

**PASS**

### 2.6 Merge commit `168800f` (item 5)

- **Three-way check.** Base `f0956fa`, PR `81afe1c`, `feature/ci4` `a5cdc2f`. Every file other than the two conflicted ones equals the side that changed it.
- **`tests/Unit/Ci4HttpTest.php`.** Takes #66's side. It has a new `adminEnv()` helper (also used by `adminPdo()`) and `DB_DSN => null`, where #65 used `test_pg_params()`. Both forward the admin connection's host, port and database (#65's N-4), so the intent is the same.
- **`CHANGELOG.md`.** Keeps #65's linked #61 sync bullet and its `test:` bullet, and adds #66's bullets. The older "spaces around `=`" bullet is replaced by #66's linked rewrite.
- **Suites.** Full suites pass at `168800f` (2.8). CI at `168800f` is 22/22.

**PASS**

### 2.7 The warn-once flag (item 3)

- **Code.** `PdoMigrationRunner` has `private static bool $warnedCredentialsDiffer`, set before the first `error_log()`.
- **Once per request.** PHP's statics live for one request under php-fpm and `php -S`, and for one process under spark.
  - With a scratch change to `Ci4DsnCredentialsTest::boot()`, three `GET /healthz` requests to one `php -S` server logged the warning **3 times**, once per request. The unchanged test logs it once for one request.
  - `apotheke:migrate` connects once per run.
- **Runner test.** It resets the flag by reflection, runs a–d (a, c and d differ) and asserts **1** warning. M4 confirms the test catches a per-connect warning.
- **Docs.** `docs/configuration.md` and the PR body say "once per PHP process: once per request under php-fpm, once per `spark` run". The CHANGELOG bullet says only "once per PHP process" (N-3).
- **Cloudflare phase.** Under FrankenPHP worker mode, a static lives for the worker. The warning is then logged once per worker, not once per request. That's acceptable for a warn-once (I-2).

**PASS**

### 2.8 Suites (item 6)

All runs used `CI=true` and PostgreSQL 17 scram.

| Tree | Leg | Result |
|---|---|---|
| `81afe1c` | ci4 @ 8.4.27, PostgreSQL | **OK**, 460 tests, 4633 assertions, 2 skipped (php-cgi not installed; `HttpSuiteTest`) |
| `81afe1c` | ci4 @ 8.4.27, SQLite | **OK**, 460 tests, 4620 assertions, 5 skipped |
| `168800f` | ci4 @ 8.4.27, PostgreSQL | **OK**, 460 tests, 4633 assertions, 2 skipped |
| `168800f` | legacy @ 8.4.27, PostgreSQL | **OK**, 460 tests, 4635 assertions, 1 skipped |
| `81afe1c` | DSN, leak and schema tests (35) | **OK**, 674 assertions |
| CI `168800f` | ci4 @ 8.4, pgsql (job 120786482148) | `OK … Tests: 460, Assertions: 4644, Skipped: 1`. With `CI=true`, a leak-test skip would have been a failure, so it ran |

- `php -l` is clean on 8.3.36 for the four PHP files the precedence step and legacy legs load.
- `node scripts/check-links.mjs` at `168800f` checks 233 links in 60 files and finds 0 broken.

### 2.9 Secrets (item 7)

- **gitleaks 8.30.1** with the repo's `.gitleaks.toml`, run on the 1433 lines added vs `feature/ci4`: **no leaks**.
- **Manual grep.** The only URLs are `github.com/thordin9/apotheke/pull/…` and placeholder `db.example` hosts. There are no tokens, keys, IPs, hostnames or account IDs. Test roles and passwords are random at runtime, and the precedence script never prints them.

**PASS**

### 2.10 Cloudflare phase (item 8)

The target is FrankenPHP worker mode in a Cloudflare Container, Postgres through Hyperdrive, and R2.

- **Storage behind an interface:** no storage code here. Logs still go to files (`writable/logs`, `error_log`), as noted on #61.
- **Per-request static or global state:** one new static, `PdoMigrationRunner::$warnedCredentialsDiffer`. It's deliberate process state, and fine for a warn-once (I-2). Nothing else is added. The `DsnTranslator` methods are pure.
- **DB config DSN-driven:** yes. Both connections derive everything from `DB_DSN` / `DB_*` through `DsnTranslator`, with one credential rule. Hyperdrive `postgres://` URLs are still unsupported and deferred (I-3).

**PASS**

### 2.11 CI

- **Run 40065073417** (`168800f`, `pull_request`): **22/22 `success`**, 08:08–08:18 Athens time (UTC+3).
  - Lint, mobile checks, Docker build + smoke test (with the a–e2 precedence step), and Visual E2E shards 1–3: each on PHP 8.3 and 8.4.
  - Tests: legacy@8.3, legacy@8.4 and ci4@8.4, each on sqlite and pgsql.
  - deptrac, DI rule, Conventional Commits and Secret scan.
- **Run 40052538019** (`81afe1c`): 22/22 `success`. Same precedence values.

## 3. Findings

| Id | Sev | Finding | Suggestion |
|---|---|---|---|
| N-1 | Nit | The Docker precedence step has no row for credentials in both the DSN and env, plus `user=` text in another setting. That's the case where CI4's embed path differs most from legacy 8.4. Locally through `db_connect()`: 8.3.36 env, 8.4.27 dsn. | Add `'e3' => ['both differing + options user=', $withDsn . ";options='-c application_name=user=x'", env user, env password]`, expected `env` on 8.3 and `dsn` on 8.4. |
| N-2 | Nit | The docblock table at the top of `scripts/legacy-dsn-precedence.php` still lists only a–d. | Add e and e2 (and e3) with their 8.3 and 8.4 results. |
| N-3 | Nit | The CHANGELOG bullet says the warning is logged "once per PHP process". Under php-fpm the process outlives the request but statics don't, so it's once per request. `docs/configuration.md` already says that. | Say "once per request (php-fpm) or `spark` run". |
| N-4 | Nit | No test pins that the warning comes back on the next request. Verified manually: 3 requests to one server log 3 warnings. | Optional: a second `GET` in `testDifferingCredentialsLogOneWarningWithoutValues`, expecting 2. |
| N-5 | Nit | `skipOutsideCi()` now exists twice (`Ci4DatabaseDownTest` from #65, `Ci4ConnectFailureLeakTest` here) with the same exact-match CI rule (`CI=TRUE` skips, as #65 N-1). | Optional: a small shared test trait. |
| I-1 | Info | The head moved from `81afe1c` to `168800f` during the review: a plain merge of `feature/ci4` (#65). The `CHANGELOG.md` and `Ci4HttpTest.php` conflicts are resolved correctly (2.6), and suites and CI are green at the new head. | None. |
| I-2 | Info | Cloudflare phase: under FrankenPHP worker mode the `$warnedCredentialsDiffer` static persists across requests, so the warning is logged once per worker. Acceptable for a warn-once, because the config can't change inside a worker. | None now. Recheck when worker mode lands. |
| I-3 | Info | Hyperdrive `postgres://` URLs are still rejected by `DsnTranslator` (only `sqlite:` / `pgsql:`). This PR doesn't touch it. | Stays deferred to the Cloudflare phase. |

No High, Medium or Low findings.

## 4. Verdict

**PASS at `168800fdde6c7abd03ad1cf57504fb3e9075e63d`.**

- **History:** the replacement is clean. Commit 13 has `8909907`'s tree, `485ab16` isn't in the history, and the subjects are within 72 characters.
- **Credentials:** the L-E hybrid gives the same results on real PHP 8.3.36 and 8.4.27 images. That holds for every precedence case, the `;` refusal (no values) and the password edge cases. All six mutations are caught.
- **e/e2:** the CI values are correct, and the 8.4 failure vs my earlier `dsn` comes from the DSN having no credentials in CI's case.
- **Warn-once:** the flag is once per request under php-fpm and once per spark run, and the runner test pins one warning across three connects.
- **Other checks:** the merge resolution is right, suites and CI are green, and gitleaks is clean.
- **Open items:** nits N-1 to N-5, all optional follow-ups.
