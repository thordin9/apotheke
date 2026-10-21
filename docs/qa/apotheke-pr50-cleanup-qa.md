# QA: apotheke PR #50, cleanup: in-process DSN banner tests, anchored gitleaks allowlist, QR docblock

- **PR:** [#50](https://github.com/thordin9/apotheke/pull/50) into `main`, by Coder (draft at review time)
- **Head:** `b2dcac4b6b3adfe1e759be7b7f95875c956c73da`. Base `main` = `d8d33792be6a5e2d32c7fb0795f7e8d500458455` (where #48 merged). The head didn't move during the review.
- **CI:** run 39673492582 on `b2dcac4`, **10/10 jobs green**
- **Scope:** the follow-ups from the v2.5.5 release QA (I-1, coverage) and the #44 QA (I-14, allowlist scope), the #45 docblock nit (N-1), and QA's final v2.5.5 release doc as its own `docs(qa):` commit.
- **Verdict: PASS.** There are no high, medium or low findings, one nit and four info notes. Merging cuts no release.

## Test plan

1. **Delta.**
   - Compare `d8d3379...b2dcac4`: status, ahead/behind, files, commits and parents.
   - Every non-merge subject against `scripts/check-commits.sh` (Conventional Commits, 72 characters at most).
   - The merge commit `b200579`: its tree against `git merge-tree` of its parents, and what it brings in from `main`.
   - `scripts/release.php plan` on a replayed `--no-ff` merge of the head into `main`.
2. **QA doc hash check.** The raw `docs/qa/apotheke-v2.5.5-release-qa.md` at `b2dcac4` against QA's file (`wc -c`, `sha256sum`, `cmp`), and `b200579...b2dcac4` must add only that file.
3. **I-1, the new tests.**
   - Read `tests/Unit/ConfigMigrateWhereTest.php`: the calls must be in process (no `proc_open`, `exec` or `bin/migrate.php`), and the cases must cover the branches of `config_migrate_where()`, `config_dsn_public_fields()` and `config_dsn_keywords()`.
   - Run the new class, the full PHPUnit suite and `tests/run.sh` on host PHP 8.4.
   - Hand mutants in `src/config.php`, each run against the new class.
   - Reproduce the README coverage group "Bootstrap, config, QR, seed" for base and head with `scripts/coverage.sh` in a podman PHP 8.3 + pcov container.
4. **I-14, `.gitleaks.toml`.**
   - Review the allowlist and the `docs/ci.md` wording.
   - The pinned gitleaks 8.30.1 binary (tarball SHA-256 checked against the workflow), with CI's arguments, over the full history of a fresh mirror of every branch and tag, checked out at the head.
   - The same scan with only the default rules, to see what the allowlist suppresses.
   - Git-mode canaries, each one commit on a throwaway local branch (never pushed), scanned with the PR's config and with the default rules only.
   - `gitleaks dir` on an extracted head tree, with the same canaries, to reproduce the dir-mode report from DevOps.
   - The gitleaks 8.30.1 source for how each mode applies `paths` under `condition = "AND"`.
5. **The docblock commit.** The diff, and a PHP token comparison (comments and whitespace removed) of both files before and after.
6. **CI.** Run 39673492582 on the head until it finishes. Also the `main` push run 39673060638 on `d8d3379` (#48's merge) and the Release run that follows it.

## Results

### 1. Delta

`d8d3379...b2dcac4`: **ahead 5, behind 0**, 6 files.

| Commit | Parents | Subject (length) | Files |
|---|---|---|---|
| `1852ac5` | `138d593` | test(config): cover the migrate DSN banner in process (53) | `tests/Unit/ConfigMigrateWhereTest.php` +234 (new) |
| `2ca049e` | `1852ac5` | chore(ci): anchor the migrate DSN gitleaks allowlist (52) | `.gitleaks.toml` +5/−2, `docs/ci.md` +4/−2 |
| `9c69faa` | `2ca049e` | chore(qr): restore the function-JIT docblock sentence (53) | `src/qr.php` +4/−1, `tests/Unit/QrCodeTest.php` +1/−1 |
| `b200579` | `9c69faa`, `d8d3379` | Merge branch 'main' into cursor/cleanup-dsn-coverage-9d44 (57) | from `main`: #48's `Dockerfile`, `docs/getting-started.md` and its QA doc |
| `b2dcac4` | `b200579` | docs(qa): add the v2.5.5 release QA report (42) | `docs/qa/apotheke-v2.5.5-release-qa.md` +272 (new) |

- Files against `main`: `.gitleaks.toml`, `docs/ci.md`, `docs/qa/apotheke-v2.5.5-release-qa.md`, `src/qr.php`, `tests/Unit/ConfigMigrateWhereTest.php`, `tests/Unit/QrCodeTest.php`.
- `scripts/check-commits.sh d8d3379 b2dcac4`: 4 commits checked, all `ok`. The merge subject is skipped by design, as in CI.
- `b200579`'s tree (`af524aa`) is identical to `git merge-tree --write-tree 9c69faa d8d3379`, so the merge is clean and adds nothing of its own.
- There are no `feat`, `fix` or `perf` commits. The `release.php plan` replay on a local `--no-ff` merge into `d8d3379` gives `last_tag=v2.5.5`, `commits=8`, `bump=` (empty), **`release=false`**. `main` alone also gives `release=false`.

### 2. QA doc hash check

| Check | Result |
|---|---|
| raw `docs/qa/apotheke-v2.5.5-release-qa.md` at `b2dcac4` | 20002 B, sha256 `c2fc09ea87c89cd04f397b60060c1cd8b079270f73b97e41daafce2d3c5b41d3` |
| QA's file | 20002 B, the same sha256 |
| `cmp` | identical |
| `b200579...b2dcac4` | ahead 1, behind 0, one file: that doc, added, +272/−0 |

### 3. I-1, in-process tests for `config_migrate_where()`

**Review.** The class calls `config_migrate_where()`, `config_dsn_public_fields()` and `config_dsn_keywords()` directly. Nothing starts a process or runs `bin/migrate.php`, so pcov counts the lines. It has 17 test methods, one with a 7-case data provider (23 tests, 98 assertions). They cover:

- the built DSN with and without a schema;
- a keyword DSN: user and password are dropped, keys are case-insensitive, and spaces around `=` are ignored;
- quoted values with spaces, `\'` and `\\`, a quoted dbname as one value, and an escaped space in an unquoted value;
- a password-only DSN, duplicate keys (the last allowlisted value wins), and empty allowlisted values;
- URIs: userinfo and query dropped, port missing, and a path that is only a slash;
- SQLite (the path only);
- seven inputs that must print only `<unparsed dsn>`: junk, an unterminated quote, a missing `=`, a trailing backslash (bare and quoted), a URI that fails to parse, and an empty key;
- the tokeniser's own outputs and rejections.

Every case also asserts that the user and password values are absent from the output.

**Runs** (host PHP 8.4.26):

| Run | Result |
|---|---|
| `phpunit --filter ConfigMigrateWhereTest` | OK, 23 tests, 98 assertions |
| full PHPUnit | OK, 335 tests, 2332 assertions, 16 skipped (pgsql and other optional extensions that aren't installed on the box) |
| `tests/run.sh` | 1423 passed, 0 failed |

**Mutants** in `src/config.php`, each run against the new class:

| Mutant | Result |
|---|---|
| an unparsed DSN returns the raw DSN instead of `<unparsed dsn>` | killed |
| an unterminated quote is accepted | killed |
| an empty key is skipped instead of rejected | the tokeniser loops forever, so the test never finishes (killed in effect) |
| `user` added to the printed keys | survives, but equivalent: `config_dsn_public_fields()` never returns `user` |
| the URI branch returns `[]` instead of null | survives (I-1 below) |
| a `/` path kept as an empty dbname | survives, but equivalent: `config_migrate_where()` drops empty values |

**Coverage, reproduced exactly.** `scripts/coverage.sh` ran in podman: `php:8.3-fpm-alpine` with pcov, gd, exif, zip and pdo_pgsql, `memory_limit=-1`. There were two QA-only changes to the script copy: the Playwright list step was removed, and the unit phase was made non-fatal. One `MigrateDsnTest` subprocess case fails in this container on base and head alike (the container's PHP error output differs from CI's). Subprocess cases don't add coverage either way. The HTTP phase passed 1422 of 1422 on both.

| "Bootstrap, config, QR, seed" (559 lines) | Unit | HTTP | Combined |
|---|---|---|---|
| base `d8d3379` | 339 (60.6 %) | 415 of 545 (76.1 %) | 442 (79.1 %) |
| head `b2dcac4` | 407 (72.8 %) | 415 of 545 (76.1 %) | **510 (91.2 %)** |

Base matches the README row exactly (60.6 / 76.1 / 79.1 %). The head matches Coder's 91.2 %, 510 of 559. `src/config.php` goes from 65 to 133 of 134 lines combined.

### 4. I-14, `.gitleaks.toml`

```toml
[extend]
useDefault = true

[[allowlists]]
description = "Migrate DSN test placeholder flagged by generic-api-key."
condition = "AND"
paths = ['''^tests/Unit/MigrateDsnTest\.php$''']
regexes = ['''^SecretMissingEq99$''']
```

- Both patterns are anchored at both ends. `regexTarget` is left at its default, `secret`, so the regex is matched against the extracted secret, not the line. With only the default rules, history has exactly one finding: `generic-api-key` in `tests/Unit/MigrateDsnTest.php`, line 129, commit `bf5505e`. Its extracted secret is exactly the fixture value (compared in a script, never printed), so the anchored regex matches it and nothing longer.
- The `docs/ci.md` change describes the AND condition correctly. Its statement that the same literal in another file is still reported is confirmed below.

**Full history** (CI's arguments: `gitleaks git --log-opts="--all" --redact --no-banner --exit-code 1`, pinned binary, tarball SHA-256 `551f6fc8…f2470eb` as in the workflow): 293 commits scanned, **no leaks**, exit 0. The mirror holds every branch and tag. Coder reports 286 commits for the scan of the PR checkout, which has fewer refs (I-4).

**Git-mode canaries.** Each is one local commit on top of `b2dcac4`, scanned as that range.

| Canary | PR config | Default rules only |
|---|---|---|
| the fixture literal in another file (`tests/Unit/OtherDsnTest.php`) | **caught** (1) | 1 |
| a different 18-character value in `tests/Unit/MigrateDsnTest.php` | **caught** (1) | 1 |
| the fixture with 3 characters appended, in `MigrateDsnTest.php` | **caught** (1) | 1 |
| the fixture with 3 characters prepended, in `MigrateDsnTest.php` | **caught** (1) | 1 |
| the fixture literal in `tests/Unit/MigrateDsnTest.php.bak` | **caught** (1) | 1 |
| the fixture literal in `qa/tests/Unit/MigrateDsnTest.php` | **caught** (1) | 1 |
| the fixture literal again in `MigrateDsnTest.php`, another line | allowed (0), as designed | 1 |

So in git mode, which is what CI runs, only the exact value in the exact file is allowed.

**Dir mode reproduces DevOps's report** (N-1). On the extracted head tree, `gitleaks dir .` misses the different value in `MigrateDsnTest.php`, and the trace log says `skipping file: global allowlist path=tests/Unit/MigrateDsnTest.php`. The cause is in gitleaks 8.30.1 `sources/common.go`: `shouldSkipPath()` drops a file before it is read whenever any allowlist's `paths` match, without looking at `condition`. Git mode doesn't call it. Its per-finding check in `detect/detect.go` applies AND correctly.

| `gitleaks dir` variant | Result |
|---|---|
| `dir .` from the repo root, different value in the file | **missed** (the whole file is skipped) |
| `dir .`, the fixture literal in another file | caught |
| an absolute or parent-relative source path | the path anchor doesn't match, so both the fixture and the canary are reported |

No workflow or script in the repo runs `gitleaks dir`. CI's secret gate is unaffected.

### 5. Docblock commit `9c69faa`

- `tests/Unit/QrCodeTest.php`: the `testFunctionJitMatchesInterpreter` docblock gets back "Every function-JIT mode is disabled before the encoder loads.", the sentence the #45 QA nit (N-1) asked for. It replaces "The guard runs on every PHP version." (I-2).
- `src/qr.php`: the one-line docblock of `qr_jit_is_tracing()` becomes three lines and gains the same sentence.
- The PHP token streams without comments and whitespace are identical before and after, for both files (sha256 `bcaf1000…bb75f0e0` for `src/qr.php`, `d59b821d…263f03a` for the test). The change is **comment-only**.

### 6. CI

All times are Athens time (UTC+3).

| Run | Commit | Window | Result |
|---|---|---|---|
| CI 39673492582 (pull_request) | `b2dcac4` | 23:48:31–23:57:41 | **10/10 success**: Lint, Conventional Commits, Secret scan, Tests (PHP 8.3, sqlite), Tests (PHP 8.3, pgsql), Docker build + smoke test, Headless mobile checks, Visual E2E shards 1–3 |
| CI 39673060638 (push to `main`, #48's merge) | `d8d3379` | 23:45:29–23:54:07 | **success**: 9 jobs green, and Conventional Commits skipped as it always is on push |
| Release 39674300836 (workflow_run) | `d8d3379` | 23:54:10–23:54:21 | **success**: "Only release the commit CI tested" and "Plan" ran. "Refresh README coverage table", "Update VERSION and CHANGELOG.md, commit, tag and push" and "GitHub Release" were **skipped** |

- After the Release run there is no `v2.5.6` ref (404).
- `v2.5.5` is still the latest release, not a draft and not a prerelease, and its tag still points to `db983c8`.
- `main` is `d8d3379`. The local plan replay on `d8d3379` also gives `release=false`.

## Findings

| ID | Sev | Finding | Suggestion |
|---|---|---|---|
| N-1 | Nit | Local `gitleaks dir` (8.30.1) run from the repo root skips all of `tests/Unit/MigrateDsnTest.php`, so a different secret added to that file is not reported. The dir walker's `shouldSkipPath()` applies the allowlist's `paths` without `condition = "AND"`. Git mode, which CI uses, is unaffected and catches it (section 4). | Optional: one line in `docs/ci.md` saying local scans should use `gitleaks git`, because `gitleaks dir` skips that file. |
| I-1 | Info | The `$fields === [] ? null : $fields` return in the URI branch of `config_dsn_public_fields()` survives a mutant. Every host-less URI probed (`postgresql://`, `…@`, `…@/`, `:///`, `…?q`, `…#f`) makes `parse_url()` return false first, so the line looks unreachable in practice. Either way it leaks nothing. | None. |
| I-2 | Info | The test docblock restores the #45 sentence in place of "The guard runs on every PHP version." That fact is still in the `src/qr.php` header and in `AGENTS.md`. | None. |
| I-3 | Info | Merging cuts no release, so the README coverage row stays at 79.1 % until the next release refreshes it (91.2 % on the current tree). | None. |
| I-4 | Info | The full-history scan covers 293 commits on a mirror with every ref. Coder reports 286 for the PR checkout, which has fewer refs. Both are clean. | None. |

## Summary

- 5 commits: 4 non-merge plus a clean merge of `main`. 6 files. All subjects are Conventional, there's nothing that bumps the version, and the replay gives `release=false`.
- The v2.5.5 QA doc is byte-identical (20002 B, `c2fc09ea…5c41d3`) and is the only file in `b2dcac4`.
- I-1 is closed. The tests run in process, pass, and kill the real mutants. The coverage group reproduces exactly: 79.1 % → 91.2 %, 510 of 559.
- I-14 is closed. In git mode only the exact fixture in the exact file is allowed, every canary is caught, and the full history is clean. The dir-mode quirk is a local-only nit (N-1).
- The #45 nit is closed, and the change is comment-only.
- CI 39673492582 is 10/10. `main` CI 39673060638 is green, and Release 39674300836 skipped tag and release, so there's no `v2.5.6`.
