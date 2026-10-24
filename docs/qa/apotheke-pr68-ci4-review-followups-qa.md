# QA: apotheke PR #68, QA follow-ups from #66 and #64 (tests, CI and CHANGELOG only)

- **PR:** [#68](https://github.com/thordin9/apotheke/pull/68), "test(ci4): QA follow-ups from #66 and #64", branch `ci4/review-followups` into `feature/ci4`, by Coder2. It was a draft when the review was requested and was marked ready during the review.
- **Base:** `feature/ci4` at `8863a5c`. The branch is 6 linear commits on `8863a5c`, 0 behind. No merge commit, no force-push.
- **Head tested:** `1f2da3d1d9fefffab5932ecdda363cb7fb4099f7` (`60dbde5`, `1bbb937`, `c086a3a`, `fb5d315`, `8eedf56`, `1f2da3d`). The head was the same at the start (10:21) and the end (10:30) of the review (Athens time, UTC+3).
- **Changes vs `feature/ci4`:** 10 files, +130 / −38. `ci4/tests/Core/BootMigrationTest.php`, `scripts/legacy-dsn-precedence.php`, `.github/workflows/ci.yml` (step name), `docs/ci.md`, `CHANGELOG.md`, `tests/Unit/{Ci4ConnectFailureLeakTest,Ci4DatabaseDownTest,Ci4DsnCredentialsTest}.php`, and the new `tests/Unit/SkipsOutsideCi.php` (trait) and `tests/Unit/SkipsOutsideCiTest.php`. There are no runtime code changes: nothing under `src/`, `public/`, `bin/`, `migrations/` or `ci4/modules/`.
- **CI:** run 40073364134 at `1f2da3d` (`pull_request`), **22/22 `success`** (10:14–10:28 Athens time, UTC+3).
- **Date:** 2026-10-24 (Europe/Athens).
- **Scope:** read-only.
  - Sources: a private checkout of `1f2da3d` and `8863a5c`, #67's head `442c224` (test merge only), the GitHub API (PR, check runs, job logs) and the QA docs for #64, #65 and #66.
  - Local runs only in scratch copies against a throwaway PostgreSQL 17 with scram-sha-256, in private databases.
  - No comment, review, push or merge was made.
- **Verdict: PASS.** There are no High, Medium or Low findings, only two nits and notes.
  - Every claimed fix is in and does what the finding asked.
  - All 11 mutations are caught, including both `BootMigration::key()` mutations, five `SkipsOutsideCi` mutations, per-process warning dedupe and the e3 flip.
  - e3 gives **env on 8.3.36 and dsn on 8.4.27**, on the real images, the box PHP and in CI.
  - The test merge with #67 (`442c224`) is clean, so #68 needs no merge commit to land.

## 1. Test plan

### 1.1 Environment

- **Docker isn't available on this box**, so I used the same setup as the #66 review.
  - **Real images:** the `php:8.3.36-fpm-alpine` and `php:8.4.27-fpm-alpine` rootfs (linux/amd64, from Docker Hub) with `pdo_pgsql` added, run in a chroot. These ran `scripts/legacy-dsn-precedence.php` the way the CI Docker step does (image PHP, `APP_ROOT` pointing at this tree's `src/`).
  - **Suites:** the box's PHP **8.3.36** and **8.4.27** CLIs (pdo_pgsql, pgsql, pdo_sqlite, gd, imagick), with Composer dev dependencies from the locked `composer.lock` and `ci4/composer.lock`. zbar-tools is installed.
- PostgreSQL **17.11**, scram-sha-256 on TCP, test-only superuser `apotheke` with CI's public test password (written as `<CI test password>` here). Each leg used its own database (`qa2_l83`, `qa2_c84`, `qa2_mut`).
- gitleaks **8.30.1**, downloaded and checked against the sha256 pinned in `ci.yml`.
- Every suite run used `CI=true`, so a `skipOutsideCi()` skip would have been a failure.

### 1.2 Acceptance checks

1. **CI** on the head SHA: 22/22.
2. **Suites:** legacy @ 8.3 and ci4 @ 8.4, each on SQLite and PostgreSQL 17, with the CI env (`APOTHEKE_APP`, `APOTHEKE_TEST_DB`, `TEST_PG_*`), the CLI migration step and the pgsql probe proof. Then the precedence script on 8.3 and 8.4, `scripts/lint.sh` on both PHPs, and `scripts/check-commits.sh origin/feature/ci4 HEAD`.
3. **Mutations:** `BootMigration::key()`, `SkipsOutsideCi`, the warning dedupe scope and e3.
4. **Fix-by-fix check** against #66 N-1–N-5, #64 L-1, N-1 and N-2, and #65 N-2, plus the 8.3 env / 8.4 dsn claim for e3.
5. **Docs and CHANGELOG:** Conventional Commits, links, `docs/ci.md` and the step name, and the warning wording.
6. **Cloudflare phase:** the PR body note, per-request global state, storage behind an interface and DSN-driven DB config.
7. **Security:** secrets, credential output, shell and env handling, and new runtime surface.
8. **#67 interaction:** test merge with `442c224`.

## 2. Results

### 2.1 CI (item 1)

- **Run 40073364134** (`1f2da3d`, `pull_request`): **22/22 `success`**, 10:14–10:28 Athens time (UTC+3). Nothing failed and nothing is pending.
  - Lint, headless mobile checks, Docker build + smoke test (with the a–e3 precedence step), and Visual E2E shards 1–3: each on PHP 8.3 and 8.4.
  - Tests: legacy@8.3, legacy@8.4 and ci4@8.4, each on sqlite and pgsql.
  - deptrac, DI rule, Conventional Commits and Secret scan.
- **Job logs:**
  - Docker 8.3 (job 120811395371): `e3 … expected env got env ok`.
  - Docker 8.4 (job 120811395440): `e3 … expected dsn got dsn ok`.
  - ci4 @ 8.4 pgsql (job 120811395278): `Tests: 476, Assertions: 12289, Skipped: 1` and `apotheke_ci4_pgsql_probe rows=56`.

**PASS**

### 2.2 Suites and scripts (item 2)

| Leg | Command | Result |
|---|---|---|
| legacy @ 8.3.36, SQLite | `CI=true APOTHEKE_APP=legacy APOTHEKE_TEST_DB=sqlite DB_DRIVER=sqlite php8.3 vendor/bin/phpunit` | **OK**, 476 tests, 9916 assertions, 32 skipped (CI4 vendor and PHP 8.4 required, PostgreSQL leg only, php-cgi) |
| legacy @ 8.3.36, PostgreSQL 17 | same with `pgsql`, `TEST_PG_DB=qa2_l83`, `TEST_PG_PASSWORD=<CI test password>` | **OK**, 476 tests, 9923 assertions, 31 skipped (same reasons) |
| ci4 @ 8.4.27, SQLite | `CI=true APOTHEKE_APP=ci4 APOTHEKE_TEST_DB=sqlite DB_DRIVER=sqlite php8.4 vendor/bin/phpunit` | **OK**, 476 tests, 12265 assertions, 5 skipped (PostgreSQL leg only ×4, php-cgi) |
| ci4 @ 8.4.27, PostgreSQL 17 | same with `pgsql`, `TEST_PG_DB=qa2_c84` | **OK**, 476 tests, 12279 assertions, 2 skipped (php-cgi not installed; `HttpSuiteTest`, catalog routes not in CI4 yet). `php scripts/ci4-pgsql-proof.php`: `rows=57`, exit 0 |
| all four legs | CLI migrations as in CI (`bin/migrate.php`, idempotent re-run, `bin/seed.php --demo` twice, `--status`) | OK |
| precedence, 8.3.36 image | `php /qa2/app/scripts/legacy-dsn-precedence.php` (chroot, `APP_ROOT=/qa2/app`, `TEST_PG_PASSWORD=<CI test password>`) | a failure, b env, c env, d failure, e env, e2 env, **e3 env**: all `ok`, exit 0 |
| precedence, 8.4.27 image | same | a dsn, b env, c dsn, d dsn, e failure, e2 failure, **e3 dsn**: all `ok`, exit 0 |
| precedence, box 8.3.36 / 8.4.27 | `php8.3 scripts/legacy-dsn-precedence.php`, `php8.4 …` | identical to the images, exit 0 / 0 |
| lint | `bash scripts/lint.sh` on 8.4, and on 8.3 via a `php` → `php8.3` shim | exit 0 / 0: PHP lint, JS syntax, 233 relative links in 62 files with 0 broken, shellcheck, actionlint, pipefail-safe greps |
| commits | `bash scripts/check-commits.sh origin/feature/ci4 HEAD` | `6 commit(s) checked`, all `ok`, exit 0 |

- `Ci4ModuleSuiteTest` runs `ci4/phpunit.xml.dist` (including `BootMigrationTest`) inside the ci4 legs, so the new key test runs in CI.
- `Ci4DsnCredentialsTest`, `Ci4ConnectFailureLeakTest` and `Ci4DatabaseDownTest` ran with no skip on ci4 @ 8.4 pgsql under `CI=true`. On the 8.3 legs they skip in `needCi4()`/`requireCi4()`, before `skipOutsideCi()`, as in CI.

**PASS**

### 2.3 Mutations (item 3)

Scratch copy only. Each mutation was reverted (`git status` clean afterwards) before the next.

| # | Mutation | Command | Result |
|---|---|---|---|
| B1 | drop the `schema` line from `BootMigration::key()` | `php8.4 vendor/bin/phpunit -c ci4/phpunit.xml.dist --filter BootMigrationTest` | **killed**: `testSchemaAndUserArePartOfTheKey`, "another schema is not marked migrated" |
| B2 | drop the `user` line from `BootMigration::key()` | same | **killed**: `testSchemaAndUserArePartOfTheKey`, "another user is not marked migrated" |
| S1 | `SkipsOutsideCi` accepts `CI=TRUE` (`strtolower`) | `php8.3 vendor/bin/phpunit --filter SkipsOutsideCiTest` | **killed**: `testSkipsElsewhere` (fails instead of skipping) |
| S2 | ignore `GITHUB_ACTIONS` | same | **killed**: `testFailsInCi`, "GITHUB_ACTIONS=true" (got the skip message) |
| S3 | never fail (CI branch neutralised, #65 M2) | same | **killed**: `testFailsInCi`, "CI=true" |
| S4 | also accept `GITHUB_ACTIONS=1` | same | **killed**: `testSkipsElsewhere` |
| S5 | drop `CI=1` | same | **killed**: `testFailsInCi`, "CI=1" |
| W1 | warn once per **process**, not per request (static plus a `getmypid()` flag file, so `php -S` warns once across requests) | `CI=true … php8.4 vendor/bin/phpunit --filter Ci4DsnCredentialsTest` (pgsql) | **killed**: `testDifferingCredentialsLogOneWarningWithoutValues`, "Failed asserting that 1 is identical to 2" |
| W1 control | W1 against `feature/ci4`'s one-request version of the test | same | survives (OK, 8 tests), so the second request is what catches it |
| W2 | warn on every connect (drop the once flag) | same | **killed**: `testRunnerFollowsLegacyOnPhp83OnEveryPhpVersion` (3 vs 1) |
| E1 | flip the e3 expectation (8.3 `dsn`, 8.4 `env`) | precedence script on both images | **killed**: `e3 … MISMATCH`, exit 1 on 8.3.36 and on 8.4.27 |

- About the `SkipsOutsideCiTest` catch blocks: `SkippedWithMessageException` extends `AssertionFailedError`, so a skip in `testFailsInCi` is caught, and the message assertion fails on it (S2, S3, S5). It doesn't pass as a silent skip.

**PASS**

### 2.4 Fix-by-fix check (item 4)

| Finding | Asked for | In #68 | OK |
|---|---|---|---|
| #66 N-1 | case e3: `$withDsn . ";options='-c application_name=user=x'"`, env credentials, expect env on 8.3 and dsn on 8.4 | exactly that row in `$cases` and both `$expected` rows (`1bbb937`) | ✓ |
| #66 N-2 | docblock table lists e, e2, e3 | rows e, e2, e3, plus a note on why 8.4 fails without DSN credentials | ✓ |
| #66 N-3 | CHANGELOG "once per request (php-fpm) or `spark` run" | that wording, word for word, in the #66 credential bullet | ✓ |
| #66 N-4 | a second `GET`, expecting 2 warnings | `boot(…, 2)` sends two `/healthz` requests to one `php -S` server and asserts 2 warnings and no values (W1) | ✓ |
| #66 N-5 | a shared trait for `skipOutsideCi()` | `tests/Unit/SkipsOutsideCi.php`, used by both tests. The extra `$fix` argument keeps each test's own hint. Loaded by the existing `autoload-dev` classmap on `tests/Unit/` | ✓ |
| #65 N-2 | a unit test for the helper's CI branch | `SkipsOutsideCiTest`: fails for `CI=true`/`1` and `GITHUB_ACTIONS=true`, skips for unset, `CI=false`, `CI=TRUE` and `GITHUB_ACTIONS=1`. Restores both variables in `tearDown()` | ✓ |
| #64 L-1 | same DSN with two `DB_SCHEMA` values (optionally two users), `ensure()` true for each | `testSchemaAndUserArePartOfTheKey`: SQLite with one shared DSN (asserted), schema `shop_a`/`shop_b` and user `u1`/`u2`, then `done()` false, `ensure()` true, and the first one is still remembered (B1, B2) | ✓ |
| #64 N-1 | refresh the stale test numbers in #64's body | #64's body now says "458 tests on every leg in CI run 40065131618". That's an edit to #64's body, not to this tree | ✓ |
| #64 N-2 | link #64 on the deviations section | the heading reads `### Intentional deviations (settings CLI, APOTHEKE_APP=ci4, [#64](…/pull/64))` | ✓ |

- **e3 claim (8.3 env, 8.4 dsn): verified** on the real images, the box PHP and in CI (2.1, 2.2).
  - On 8.3, PDO always appends `DB_USER`/`DB_PASSWORD`, and libpq takes the last value, so the result is env.
  - On 8.4, PDO sees `user=` in the DSN and appends nothing, so the DSN's own credentials connect: dsn.
  - On legacy, e3 gives the same result as c on both versions (I-1).

**PASS**

### 2.5 Docs and CHANGELOG (item 5)

- **Commits:** all 6 subjects are Conventional Commits, 45–67 characters, and `check-commits.sh` passes.
- **Unreleased links:**
  - The new `test:` bullet ends with ` ([#68](https://github.com/thordin9/apotheke/pull/68))`.
  - The two edited bullets keep their #66 link.
  - 11 older bullets are still unlinked, the same as `feature/ci4` today (#65 L-1). #67 fixes those (2.9).
  - The `ci:` bullet now describes e3, which #68 adds, but it links only #66 (N-1).
- **`docs/ci.md` and `ci.yml`:** both say a–e3 (`golden cases a-e3` in the step name, `a–e3` in the table). No other a–e2 text is left outside `docs/qa/`.
- **Warning wording:** "once per request (php-fpm) or `spark` run" is accurate.
  - Statics reset per request under php-fpm and `php -S` (W1 shows it), and last one process per spark run.
  - It matches `docs/configuration.md` and the `PdoMigrationRunner` docblock.
  - The `test:` bullet's "pinned to once per request" is also right. The test name still says "One Warning" (N-2).

**PASS**

### 2.6 Cloudflare phase (item 6)

- **PR body:** it has a "Cloudflare phase" section: tests and CI only, no change to storage, request state or DB config, and `BootMigration`'s per-process memo unchanged.
- **Per-request global state:** none added. `BootMigration::$done` (the migrate-once memo) and `PdoMigrationRunner::$warnedCredentialsDiffer` are untouched. The trait reads `getenv()` only.
- **Storage behind an interface:** not touched.
- **DSN-driven DB config:** not touched. The new BootMigration test goes through `ApothekeConfig::load()` and `DsnTranslator`.
- **FrankenPHP worker mode (carry-over, #66 I-2):** both statics live for a worker there. W1 now pins per-request behaviour under `php -S`. If worker mode resets statics per request, the same test applies. Otherwise it needs updating when worker mode lands (I-3).

**PASS**

### 2.7 Security (item 7)

| Check | Result |
|---|---|
| **Secrets in fixtures, CI and logs** | gitleaks 8.30.1 with the repo `.gitleaks.toml`: `1f2da3d` range (6 commits) **no leaks**, and the full history reachable from `1f2da3d` and `feature/ci4` (383 commits) **no leaks**. Manual grep of the 130 added lines: the only URLs are `github.com/thordin9/apotheke/pull/…`. There are no tokens, keys, hosts or IPs. The new literals are placeholders (`shop_a`, `u1`, `u2`, `x`, `%du`/`%dp`). The CI step's `TEST_PG_PASSWORD` is the existing public test value in `ci.yml`, unchanged by #68 |
| **Credential values in output** | The precedence script creates random roles (`prec_<kind>_<hex>`, password `pw<16 hex>`), passes credentials to the child only through its env (not argv), discards the child's stderr, and prints only labels (`env`/`dsn`/`failure`/`other`). The CI logs for both Docker jobs show no role password. `testDifferingCredentialsLogOneWarningWithoutValues` still asserts that no role user or password is in the log, now across two requests. `SkipsOutsideCi` messages are fixed strings |
| **Shell and env handling** | `proc_open()` with an argv array (no shell) and an explicit env (`PGPASSFILE=/nonexistent`, `APOTHEKE_CONFIG=/nonexistent`, no inherited `DB_*`/`PG*`). Role names are generated hex, and the password is `$admin->quote()`d. Roles are dropped in `finally`. The CI step only changed its name. `SkipsOutsideCiTest` saves and restores `CI`/`GITHUB_ACTIONS` |
| **New runtime surface** | **None.** No file under `src/`, `public/`, `bin/`, `migrations/`, `ci4/modules/`, `ci4/public/` or the Dockerfile changes |

**Nothing to report.** There are no security findings at any severity.

**PASS**

### 2.8 Secrets in this doc

No real secret appears here. The CI test password is written as `<CI test password>`.

### 2.9 Interaction with #67 (item 8)

- **#67** (head `442c2245e221e496b0ba3468841faae39061fa80`, open, based on `8863a5c`) changes only `CHANGELOG.md` (links 11 older ci4 bullets) and adds its QA doc.
- **Test merge** of `1f2da3d` with `442c224` in a scratch branch, deleted afterwards:
  - **Clean automatic merge, no conflict in `CHANGELOG.md`.**
  - The merged file keeps #68's three edits (the deviations heading link, the warning wording, the e3 `ci:` text and the #68 `test:` bullet).
  - It has **0** unlinked Unreleased bullets.
  - `scripts/lint.sh` on the merged tree passes (233 links in 63 files, 0 broken).
- **Merge commit:**
  - None is needed for #68 to land after #67. GitHub can merge it as it is.
  - If the Project Manager wants the branch up to date with #67 first (to re-run CI on the combined tree), a plain `git merge origin/feature/ci4` applies cleanly. No rebase.
  - Do that merge **before** the final `docs(qa)` commit, so the QA doc stays the last commit. If it lands after the doc commit, the hash check must be repeated.

## 3. Findings

| Id | Sev | Finding | Suggestion |
|---|---|---|---|
| N-1 | Nit | The `ci:` Unreleased bullet now describes e3 ("plus e, e2 and e3 …, e3 with credentials in both the DSN and the environment"), which #68 adds, but it links only #66. The #68 `test:` bullet doesn't mention e3. | Optional: end the `ci:` bullet with `([#66](…/pull/66), [#68](…/pull/68))`, or move the e3 clause into the #68 bullet. |
| N-2 | Nit | `testDifferingCredentialsLogOneWarningWithoutValues` now asserts **two** warnings over two requests. The docblock was updated, but the name still says "One Warning". | Optional: rename to `testDifferingCredentialsLogOneWarningPerRequestWithoutValues`. |
| I-1 | Info | On legacy, e3 gives the same result as case c on both PHP versions: the DSN carries credentials, so 8.4's PDO appends nothing whether or not `user=` text is also present. e3 doesn't add legacy coverage beyond c. Its value is as the golden for CI4's embed path, where CI4 gives env (#66 2.5). That's what #66 N-1 asked for. | None. |
| I-2 | Info | #68 went from draft to ready during the review. The head didn't move. | None. |
| I-3 | Info | Cloudflare phase: under FrankenPHP worker mode the warn-once static and `BootMigration`'s memo persist per worker (#66 I-2). The new two-request test describes php-fpm and `php -S`. | Recheck the test's expectation when worker mode lands. |
| I-4 | Info | The test merge with #67 (`442c224`) is clean. No merge commit is needed. If one is wanted, use a plain merge, before the `docs(qa)` commit (2.9). | None. |

No High, Medium or Low findings. No security findings.

## 4. Verdict

**PASS at `1f2da3d1d9fefffab5932ecdda363cb7fb4099f7`.**

- **CI:** run 40073364134, 22/22 `success`.
- **Suites:** legacy @ 8.3 and ci4 @ 8.4 are green on SQLite and PostgreSQL 17 under `CI=true`. The precedence script, `lint.sh` and `check-commits.sh` pass.
- **Fixes:** every claimed fix (#66 N-1–N-5, #64 L-1/N-1/N-2, #65 N-2) addresses its finding. e3 is env on 8.3.36 and dsn on 8.4.27.
- **Mutations:** all 11 are caught, and W1's control shows the new second request is what catches per-process dedupe.
- **Cloudflare and security:** no runtime change, no new global state, and no secrets or credential output.
- **#67:** merges cleanly, with no merge commit needed.
- **Open items:** nits N-1 and N-2, both optional.
