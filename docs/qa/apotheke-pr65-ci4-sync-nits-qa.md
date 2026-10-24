# QA: apotheke PR #65, fix the #61 QA nits on `feature/ci4`

- **PR:** [#65](https://github.com/thordin9/apotheke/pull/65), "chore(ci4): fix the #61 QA nits", branch `ci4/sync-nits` into `feature/ci4`, by DevOps. Open, not a draft.
- **Base:** `feature/ci4` at `f0956fa` (#61's merge). The PR is 0 behind it.
- **Head tested:** `aa64ac63d587f5b2873c57a6be7646e52661970c`. Five linear commits on `f0956fa`: `9a5af18` test(ci4): fail the refused-connect test in CI instead of skipping → `a6c965a` test(ci4): read the PG host and port from TEST_PG_* in the probe test → `34a7f5b` build(docker): drop the trailing blank line in php.ini → `7f0eb94` docs(ci4): name the exact PHP pins and log the #61 QA nits → `aa64ac6` docs(changelog): link the #61 and #65 Unreleased bullets. No force-push. The review started at `7f0eb94`, and `aa64ac6` changes only `CHANGELOG.md` (2.1).
- **Changes:** 5 files, +25 / −7. `CHANGELOG.md`, `docker/php.ini`, `docs/plans/feature12-ci4/php84-legacy-diff.md`, `tests/Unit/Ci4DatabaseDownTest.php`, `tests/Unit/Ci4HttpTest.php`. No app code, `src/` or `ci4/` change.
- **CI:** run 40051299482 at `aa64ac6`, **22/22 `success`** (attempt 1, 04:46–04:56 Athens time, UTC+3). Run 40050317527 at `7f0eb94`, also **22/22 `success`** (04:33–04:44 Athens time).
- **Date:** 2026-10-24 (Europe/Athens).
- **Scope:** read-only. codeload tarballs of `f0956fa`, `7f0eb94` and `aa64ac6` (each confirmed with `git get-tar-commit-id`), the GitHub API (PR, commits, jobs, job logs) and `main`'s `scripts/release.php` from raw.githubusercontent. Local runs only in scratch copies, with a throwaway PostgreSQL 17 using scram-sha-256 and a second throwaway cluster using trust auth. No comment, review, push or merge was made.
- **Verdict: PASS.** No High or Medium findings. N-1 to N-4 from the #61 report are fixed. One Low (L-1): the next `main` → `feature/ci4` sync will fail `main`'s new changelog link check on 12 older ci4 bullets. The rest are notes.

## 1. Test plan

### 1.1 Environment

- Tarballs unpacked side by side. The vendor trees from the #61 review are reused, because `composer.lock` and `ci4/composer.lock` are byte-identical at `de597cf`, `f0956fa` and the head.
- PHP **8.4.27** and **8.3.36** CLI, PHPUnit from the locked vendor trees, PostgreSQL **17** with `scram-sha-256` for every connection on a non-default port, a second PostgreSQL 17 cluster with `trust` auth, and gitleaks **8.30.1**.

### 1.2 Acceptance checks

1. **N-1, refused-connect test in CI.** Read how CI is detected. Run `testCi4PostgreConnectRefusalIsTheSanitizedLine` against trust auth, an unreachable port and scram, with no CI variables, with `CI=true`, `CI=1`, `GITHUB_ACTIONS=true`, and with near-miss values (`CI=false`, `CI=TRUE`, `CI=yes`, `GITHUB_ACTIONS=1`). Check that the test can't silently skip in GitHub Actions, and that the ci4@pgsql CI log shows it ran. Mutation checks: remove the catch in `Services::apothekeDb`, and neutralise the CI branch.
2. **N-2, `docker/php.ini`.** No trailing blank line, the file still parses, the directive values don't change, and the only difference from `main` is #61's comment merge.
3. **N-3, stale pins.** The plan doc and the older `[Unreleased]` ci bullet name `8.3.36` and `8.4.27`. Released CHANGELOG sections are byte-identical to the base. Grep for leftover `PHP_VERSION=8.3` / `8.4` wording outside the QA history.
4. **N-4, `TEST_PG_PORT`.** `testShippedBootDoesNotWriteThePgsqlProbe` on ci4 SQLite and PostgreSQL with PostgreSQL on a non-5432 port, base version against head version.
5. **CHANGELOG and the link check.** Run `main`'s `php scripts/release.php check-links` (added by #62) against the base and both heads, and count the bullets it would reject after a sync.
6. **Suites.** PHPUnit on ci4@8.4, legacy@8.3 and legacy@8.4, each on SQLite and PostgreSQL 17, all with `CI=true`.
7. **Lint, secrets, commits.** `php -l` on 8.3 and 8.4, the Markdown link check, gitleaks on the head tree and on the added lines, a grep of the added lines for URLs, IPs, hosts and tokens, and the commit subjects.
8. **Cloudflare phase.** Storage behind an interface, per-request global or static state, DSN-driven DB config.

## 2. Results

### 2.1 Delta

- `f0956fa..7f0eb94` changes exactly the 5 files listed above.
- `7f0eb94..aa64ac6` changes only `CHANGELOG.md`, and only two lines. It appends ` ([#61](https://github.com/thordin9/apotheke/pull/61))` to the sync bullet and ` ([#65](https://github.com/thordin9/apotheke/pull/65))` to the new test bullet. So every test result at `7f0eb94` also holds at `aa64ac6`.

### 2.2 N-1: the refused-connect test fails in CI (item 1)

`skipOutsideCi()` replaces the two `markTestSkipped()` calls: PostgreSQL unreachable, and PostgreSQL accepting a wrong password. CI is detected as `CI` equal to `true` or `1`, or `GITHUB_ACTIONS` equal to `true`. In CI the test calls `fail()` with a message telling you to give the service password auth. Otherwise it skips as before. The `requireCi4()` skip (PHP below 8.4 or no CI4 vendor) is unchanged, so the legacy@8.3 legs still skip the test by design.

| PostgreSQL | No CI vars | `CI=true` | `CI=1` | `GITHUB_ACTIONS=true` | `CI=false` / `CI=TRUE` / `CI=yes` / `GITHUB_ACTIONS=1` |
|---|---|---|---|---|---|
| trust auth | skip | **fail** ("accepts any password (trust auth). In CI the refused CI4 connect must run…") | **fail** | **fail** | skip |
| unreachable port | skip | **fail** ("is not reachable. In CI…") | **fail** | **fail** | skip |
| scram | OK, 8 assertions | OK, 8 assertions | – | – | – |

- **No silent skip in GitHub Actions.** The runner sets both `CI=true` and `GITHUB_ACTIONS=true`. Each one is enough on its own, so a skip there becomes a failure. The near-miss values skip (N-1 below), but GitHub never uses them.
- **CI evidence.** The ci4@pgsql job at `7f0eb94` (120741314542) runs the `postgres:17` service with `POSTGRES_PASSWORD` set and no `POSTGRES_HOST_AUTH_METHOD`, plus `CI=true` and `GITHUB_ACTIONS=true`. It reports `OK, but some tests were skipped! Tests: 444, Assertions: 4127, Skipped: 1`. The same count as #61, and that one skip is `HttpSuiteTest`. Under `CI=true` a skip of this test would have been a failure, so it ran. ci4@pgsql at `aa64ac6` is also green.
- **Mutations** (scratch copy, PostgreSQL 17 scram, `CI=true`):

| # | Mutation | Result |
|---|---|---|
| M1 | remove the try/catch in `Services::apothekeDb` (connect and `apply()` unwrapped) | **killed** by `testCi4PostgreConnectRefusalIsTheSanitizedLine` (4 tests, 1 failure) |
| M2 | `skipOutsideCi()` never fails (`if (false && $ci)`), run under trust auth with `CI=true` | survives: skipped, green. This is expected, because the guard itself is the safety net. No test pins it (N-2) |

**PASS**

### 2.3 N-2: `docker/php.ini` (item 2)

- The file now ends with `session.gc_divisor = 1000\n`, with one newline and no blank line.
- `php -n -c docker/php.ini` still reads `zend.exception_ignore_args` = `1` and `session.gc_divisor` = `1000`.
- Against `main`'s `docker/php.ini`, the only difference is #61's merged two-line comment above `zend.exception_ignore_args`.

**PASS**

### 2.4 N-3: stale pins (item 3)

- `docs/plans/feature12-ci4/php84-legacy-diff.md` now says the shipped image stays on PHP 8.3, pinned by `ARG PHP_VERSION=8.3.36` (`FROM php:${PHP_VERSION}-fpm-alpine`), and that CI's 8.4 leg passes `PHP_VERSION=8.4.27`. That matches the Dockerfile and `ci.yml` at the head.
- The older `[Unreleased]` ci bullet now says `ARG PHP_VERSION=8.3.36`, and that the 8.4 leg passes `PHP_VERSION=8.4.27`.
- **Released sections untouched.** Everything from `## [2.6.1]` down is byte-identical between `f0956fa` and `aa64ac6`.
- **Leftovers.** A grep for `PHP_VERSION=8.3`, `PHP_VERSION=8.4` or `php:8.3-fpm` without a patch finds only historical QA reports under `docs/qa/`, which shouldn't be rewritten.

**PASS**

### 2.5 N-4: `TEST_PG_PORT` (item 4)

The no-CREATE-role servers in `testShippedBootDoesNotWriteThePgsqlProbe` now get `DB_HOST`, `DB_PORT` and `DB_NAME` from `test_pg_params()`, which reads `TEST_PG_HOST`, `TEST_PG_PORT` (default 5432) and `TEST_PG_DB`. Results with PostgreSQL on a non-5432 port:

| Test version | ci4 SQLite | ci4 PostgreSQL |
|---|---|---|
| `f0956fa` (base) | **fails**: `Failed asserting that 500 is identical to 200` | OK, 13 assertions |
| `7f0eb94` / `aa64ac6` | **OK**, 13 assertions | OK, 13 assertions |

The ci4 SQLite and legacy SQLite suites below are now green on a non-default port. #61 recorded them as failing (its N-4). **PASS**

### 2.6 CHANGELOG and `main`'s link check (item 5)

- **The new bullet.** `7f0eb94` adds one `[Unreleased]` → `### Maintenance` bullet, `- **test:** in CI (CI or GITHUB_ACTIONS set) the refused CI4 PostgreSQL connect test fails…`. It's accurate. At `7f0eb94` it had no PR link. At `aa64ac6` it links `#65`, and the #61 sync bullet links `#61`.
- **`main`'s check.** `main` now has #62's `php scripts/release.php check-links`, and `scripts/lint.sh` runs it. It rejects every `- ` line in `## [Unreleased]` without a `([#N](https://github.com/…/pull/N))` link. I ran `main`'s `release.php` against each tree's CHANGELOG:

| CHANGELOG | Exit | Unlinked Unreleased bullets |
|---|---|---|
| `f0956fa` (base) | 1 | 13 |
| `7f0eb94` | 1 | 14 (the 13, plus the new `test:` bullet) |
| `aa64ac6` | 1 | **12** (the #61 and #65 bullets are now linked) |

So the new bullet won't fail the check, but the next `main` → `feature/ci4` sync brings `check-links` into `lint.sh`, and Lint will then fail on the **12 older ci4 bullets**. Those are the Added CI4 skeleton and Migrations bullets, four Fixed bullets and six Maintenance bullets from earlier ci4 PRs. That's L-1. `feature/ci4` has no `check-links` yet, so Lint is green today.

### 2.7 Suites (item 6)

Local runs at `7f0eb94`, the same code as `aa64ac6`. Every run used `CI=true` and PostgreSQL 17 with scram on a non-default port.

| Leg | Result |
|---|---|
| ci4 @ 8.4.27, PostgreSQL 17 | **OK**, 444 tests, 4116 assertions, 2 skipped (php-cgi not installed; `HttpSuiteTest` stays on legacy) |
| ci4 @ 8.4.27, SQLite | **OK**, 444 tests, 4103 assertions, 5 skipped |
| legacy @ 8.3.36, PostgreSQL 17 | **OK**, 444 tests, 3746 assertions, 18 skipped (the CI4 tests need 8.4) |
| legacy @ 8.3.36, SQLite | **OK**, 444 tests, 3740 assertions, 19 skipped |
| legacy @ 8.4.27, PostgreSQL 17 | **OK**, 444 tests, 4118 assertions, 1 skipped |
| legacy @ 8.4.27, SQLite | **OK**, 444 tests, 4105 assertions, 4 skipped |
| `Ci4DatabaseDownTest`, PostgreSQL 17 scram, `CI=true` | **OK**, 4 tests, 125 assertions |

All six legs are green, including the two SQLite legs that failed on the non-default port in #61.

### 2.8 Lint, secrets, commits (item 7)

- `php -l` is clean on 8.3 and 8.4 for both changed tests. `node scripts/check-links.mjs` checks 233 relative links in 59 files and finds 0 broken.
- **gitleaks 8.30.1:** `dir` on the head tree with its `.gitleaks.toml` finds no leaks. On the 30 added lines it also finds no leaks.
- **Added lines:** the only URLs are `https://github.com/thordin9/apotheke/pull/61` and `/pull/65`. There are no IPs, email addresses, hostnames, tokens or account IDs.
- **Commit subjects:** 5 subjects, all typed, 54 to 69 characters (max 72). CI's Conventional Commits job is green at `aa64ac6`.

**PASS**

### 2.9 Cloudflare phase (item 8)

The target is FrankenPHP worker mode in a Cloudflare Container, Postgres through Hyperdrive, and uploads in R2.

- **Storage:** no change. The PR adds no file writes.
- **Per-request global or static state:** none added. `skipOutsideCi()` is a test helper. The `php.ini` change is whitespace only.
- **DB config:** no change. It still comes from the DSN through `DsnTranslator`. The N-4 change only forwards `TEST_PG_*` into a test server's environment.
- **PR body:** it has a "Cloudflare phase" section saying there is no effect, and that's correct.

**PASS**

### 2.10 CI

- **Run 40051299482** (`pull_request`, attempt 1, `aa64ac6`): **22/22 `success`**, 04:46–04:56 Athens time (UTC+3).
  - Lint, mobile checks, Docker build + smoke test, and Visual E2E shards 1–3: each on PHP 8.3 and 8.4.
  - Tests: legacy@8.3, legacy@8.4 and ci4@8.4, each on sqlite and pgsql.
  - deptrac, DI rule, Conventional Commits and Secret scan.
- **Run 40050317527** (`7f0eb94`): 22/22 `success`, 04:33–04:44 Athens time. ci4@pgsql: `Tests: 444, Assertions: 4127, Skipped: 1`.

## 3. Findings

| Id | Sev | Finding | Suggestion |
|---|---|---|---|
| L-1 | Low | `main`'s `check-links` (from #62) rejects every unlinked `[Unreleased]` bullet. `aa64ac6` links the #61 and #65 bullets, but 12 older ci4 bullets on `feature/ci4` have no PR link. The next `main` → `feature/ci4` sync brings the check into `lint.sh`, so Lint will fail there. | In that sync PR, add the `([#N](…/pull/N))` link to each of the 12 bullets (they come from the earlier ci4 PRs), or merge bullets where that's clearer. Ask the open ci4 PRs to link their new bullets now. |
| N-1 | Note | CI detection is an exact match: `CI` must be `true` or `1`, and `GITHUB_ACTIONS` must be `true`. `CI=TRUE`, `CI=yes` or `GITHUB_ACTIONS=1` still skip. GitHub Actions sets both to `true`, so nothing can silently skip today. | Optional: treat any non-empty `CI` other than `false` / `0` as CI. |
| N-2 | Note | No test pins `skipOutsideCi()` itself. If its CI branch is neutralised (M2), CI goes back to a silent skip under trust auth. The `requireCi4()` skip also stays a plain skip in CI, but the ci4 legs install the CI4 vendor in an earlier step that fails on its own. | Optional: a small unit test that calls the helper with `CI=true` and expects a failure. |
| I-1 | Info | The head moved from `7f0eb94` to `aa64ac6` during the review. The delta is two link suffixes in `CHANGELOG.md` (2.1), and CI is green on both heads. | None. |

No High or Medium findings.

## 4. Verdict

**PASS at `aa64ac63d587f5b2873c57a6be7646e52661970c`.**

- **N-1:** in GitHub Actions the refused-connect test fails instead of skipping when PostgreSQL is down or uses trust auth, and it still skips locally. The CI log shows it ran on scram, and removing the catch is caught.
- **N-2:** `php.ini` ends cleanly and matches `main` apart from #61's comment.
- **N-3:** the plan doc and the old Unreleased bullet name 8.3.36 and 8.4.27, and the released sections are untouched.
- **N-4:** the probe test honours `TEST_PG_*`. It fails before the change and passes after it on a non-default port.
- **Tests and CI:** all six local legs are green, CI is 22/22 on the final head, and gitleaks is clean.
- **Cloudflare phase:** no effect.
- **Open item:** L-1, linking the 12 older ci4 changelog bullets, belongs to the next sync from `main`.
