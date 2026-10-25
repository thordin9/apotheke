# QA: apotheke #87, fresh sign-in time after a dropped session

- **PR:** [#87](https://github.com/thordin9/apotheke/pull/87), fixes #84's L-3 and N-6. Full gate (auth).
- **Head:** `e618899`; code head `3001aac`. Earlier full review at code head `324a9c4` (base `0d2e3a2`).
- **Verdict: PASS.** 0 High, 0 Medium, 0 Low open; 3 Nits open (from #84).

## Checklist

| Check | Result |
|---|---|
| CI | 10/10 (run 40120425549 on `3001aac`) as reported by Coder; the GitHub API isn't reachable from here. |
| Merge `3970918` | Parents `63b1d32` and `8ba9511` (v2.6.13). `--remerge-diff`: only `CHANGELOG.md` conflicted; the #87 bullet moved from `[2.6.12]` to `[Unreleased]` › Fixed. Changed lines against `main` for `public/index.php`, `src/bootstrap.php` and `tests/integration.php` equal `324a9c4` against `0d2e3a2`. `[2.6.13]` to EOF is byte-identical to `8ba9511`. |
| `3001aac` | `tests/integration.php` only (+70). |
| QA docs | `docs/qa/apotheke-v2.6.12-release-qa.md` (`7cfbba61…0758e`) and `apotheke-v2.6.13-release-qa.md` (`63c807df…43f01e`) byte-identical to the QA copies. No other file changed after `324a9c4` except via the merge. |
| Tests at `e618899` | PHPUnit 476 OK on PHP 8.4 and 8.3, SQLite (13 env skips), and PHP 8.4 PostgreSQL 17 (3 skips). `tests/integration.php`: SQLite 1754/0 on PHP 8.4 and 8.3, PostgreSQL 17 1425/0 on PHP 8.4. |
| Commits, links | `check-commits.sh 0d2e3a2 e618899` ok. `release.php check-links` ok. `check-links.mjs`: 219 links, 0 broken. |
| Security | Unchanged from the `324a9c4` review: a kept time is reused only on an own-session action for the same account. No secrets, hosts or tokens in the diff. |

## Mutations (full SQLite suite, PHP 8.4)

| ID | Mutation | Suite |
|---|---|---|
| K1–K5, K8, K9 | As at `324a9c4` | **Caught** (unchanged code) |
| K10 | No flag on Your account | **Caught** ×2 (was surviving) |
| K11 | No flag on Control Panel › Security | **Caught** ×2 (was surviving) |
| K7 | Turning sign-in on passes `keepSignIn` | **Caught** ×2 (was surviving) |
| K6 | Register passes `keepSignIn` | survives (equivalent: a new account's uid never matches) |

10 of 11 caught; the remaining one is equivalent.

## Findings

| ID | Grade | Status |
|---|---|---|
| L-3 | Low | **Fixed** |
| N-6 | Nit | **Fixed** |
| M-1 | Medium | **Fixed** in `3970918`: the bullet sits under `[Unreleased]` › Fixed; released sections match `main`. |
| N-7 | Nit | **Fixed** in `3001aac`: K10, K11 and K7 are now caught. |
| N-2, N-3, N-5 | Nit | Still open from #84 (downgrade epoch, docs for lowering `session_days`, `$lifeSign` fatal on a failed sign-in). |

**Verdict: PASS** (0H / 0M / 0L / 3N open).
