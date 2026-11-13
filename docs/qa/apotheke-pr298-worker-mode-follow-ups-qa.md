# QA: PR #298 FrankenPHP worker-mode follow-ups (L-1979, L-1980, N-1981, N-1985)

PR: https://github.com/thordin9/apotheke/pull/298 · **Approved head: `2fc0d42ff1ac6dcad1d8226fecd024b7bc3112f5`** · Main: `df7bfd25` (v3.38.1) · QA: Apotheke QA 2, 2026-11-13 (EET). Earlier versions of this doc are void, including sha256 `ce6e4276…` (`eaa13cae`) and `11ea7eae…` (`ccad4746`).

**Verdict: PASS at `2fc0d42f`** (unchanged from `eaa13cae`). No High or Medium findings are open. L-2039, L-2040, L-2042 and N-2043 to N-2045 are closed.

## Earlier full gate (`bb01e623`; worker code unchanged since, apart from the N-2044 warning)
- **PHP 8.4 suites:** sqlite 621 OK; pgsql (local PG 17) 621 OK.
- **DockerfileTest** with FrankenPHP 1.13.4 on `PATH`: 16 OK, 1 skip (N-1981).
- **Local worker-mode runs:** 2 boots after an error burst and sign-in; 6 boots for 12 requests at a cap of 3.
- **Mutants:** 13 of 14 caught. The one that slipped through became L-2039.

## Merge read (`eaa13cae` → `2fc0d42f`; light gate)
- [x] **Head and CI:**
  - `2fc0d42f` is a plain merge with parents `eaa13cae` and `df7bfd25` (main: #294 and v3.38.1). It is 0 behind and 18 ahead of main, mergeable CLEAN.
  - CI 43316791184 attempt 1: 22 checks passed and the deploy was skipped. Deploy edge 43316791106 is green.
  - The worker step's log: worker mode, 2 boots after the errors and the sign-in checks, 6 boots for 12 requests.
- [x] **Diff:** for all 14 PR files, the +/- lines of `main...2fc0d42f` equal those of `4786e44d...eaa13cae`. Every file outside the PR is byte-identical to main. The 10 PR files main didn't touch are byte-identical to `eaa13cae`.
- [x] **DockerfileTest:** the head equals `eaa13cae` plus main's 13-line `testOnlyServedAssetsAreCachedForAYear`. That method and the PR's worker-mode test and helper are byte-identical to main's and `eaa13cae`'s.
  - PHP 8.4: 17 tests OK with 2 skips, or 1 skip with FrankenPHP 1.13.4 on `PATH`.
  - WorkerRecycleTest 15 OK; shellcheck clean.
  - Main's `docker-auth-checks.sh` and Caddyfile changes run inside the green worker-mode step.
- [x] **CHANGELOG:** `[Unreleased]` holds only #298's 3 bullets and is identical to `eaa13cae`'s. The header and everything from `[3.38.1]` down are byte-identical to main.

## Delta checklist (`ccad4746` → `eaa13cae`)
- [x] **Head and CI:**
  - 0 behind and 17 ahead of `4786e44d`, mergeable CLEAN.
  - CI 43306119133 attempt 1: 22 checks passed and the deploy was skipped. Deploy edge 43306119471 is green.
  - The worker step's log: worker mode, 2 boots after the errors and the sign-in checks, 6 boots for 12 requests, and no recycle warning.
- [x] **Merge read:**
  - Four plain merges brought in `99b76094` (#293), v3.37.2, #301, #299 and v3.38.0.
  - Every file outside the PR is byte-identical to main.
  - For each PR file, the +/- lines of `main...eaa13cae` equal those of `3be6990d...ccad4746` plus exactly the fix commits `ad7d7fee`, `90bcad9b` and `666702d8`. `ci.yml`, `entrypoint.sh`, `php-worker.caddy` and DockerfileTest are unchanged.
- [x] **Commits:** 68, 66 and 62 chars, Conventional Commits. Merges use git's default subject, which is exempt.
- [x] **CHANGELOG:** `[Unreleased]` holds only #298's 2 Added bullets and 1 Changed bullet, each linking the full PR URL. The header and everything from `[3.38.0]` down are byte-identical to main.
- [x] **L-2039 closed:** `docker-worker-checks.sh:53-55` greps the cap-of-3 container's log.
  - I ran the repo script unchanged through a local `docker` shim (run, logs, rm) on FrankenPHP 1.13.4.
  - Only deviation: the `docker-auth-checks.sh` result doesn't abort, because the host php.ini differs.
  - The head passes: 55 ok, rc 0. A recycle via `exit(1)` now fails it, with FrankenPHP's warning "unexpected termination, restarting".
- [x] **L-2040 closed:**
  - The ticket is now `docs/qa/apotheke-l2041-hosted-container-early-exit.md`, with the pointer at `HANDOFF.md:92`.
  - The old file is gone, and the tree has no `L-1987` or `l1987` left.
- [x] **L-2042 closed:**
  - Placeholders are at ticket lines 15, 18-20, 27 and 35; line 48 says the ids are kept out of the repo.
  - A grep of the head tree, the PR diff and the new commit messages finds none of the 5 known prefixes and no other unexplained hex ids. See N-2094.
- [x] **N-2043 and N-2045 closed:** `configuration.md:112` and `deployment-and-operations.md:478-479` give the 1–9 digit rule, the warning and that hosted always uses 1000.
- [x] **N-2044 closed:**
  - `WorkerRecycle::invalid()` (`:32`) names the rejected value, makes non-printable characters safe and cuts it to 40 characters.
  - The worker logs it at `frankenphp-worker.php:58-61`.
  - At runtime, `abc` and `1234567890` log the warning on each boot and use 1000; `' 5 '` gives 5; empty gives 1000 with no warning.
  - Tests: WorkerRecycleTest and PlainHandlerTest 27 OK; DockerfileTest 16 OK (1 skip on `PATH`); shellcheck clean.
- [x] **N-1986:** accepted as platform behaviour (ticket line 31). **N-2058** is filed (`docs/qa/apotheke-n2058-pgsql-staged-restore-flake.md`), and its timestamps and hypothesis match the CI log.

## Mutants at `eaa13cae` (unit = WorkerRecycleTest; script = the repo script through the shim)
| Mutant | unit | script |
|---|---|---|
| recycle via `exit(1)` | survives | **killed** (recycle warning) |
| + recycle grep removed / pointed at the other container / error-level only | survives | survives (each disables the guard, so the grep is what catches it) |
| `valid()` allows 10 digits / `invalid()` always null / empty counts as invalid / no sanitiser / invalid → 0 | killed | survives |
| worker doesn't write the warning | **survives** | survives (N-2093) |

## Findings
- **L-2039, L-2040, L-2042, N-2043, N-2044, N-2045: closed.** L-1979, L-1980, N-1981, N-1985 and N-1986 stay closed.
- **N-2093 (new):** nothing pins that the worker writes the N-2044 warning. Removing the `file_put_contents` at `frankenphp-worker.php:60` passes the unit test and CI. Add a string assertion in `WorkerRecycleTest::testTheWorkerLoopUsesTheCap` (or a CI container with an invalid value). Optional.
- **N-2094 (new, hardening):** the real container app id prefixes still appear in this branch's earlier commits (the old ticket file up to `ccad4746`) and in #297's PR body (teardown line). The squash keeps them off main. Don't rewrite pushed history. Edit #297's body when convenient.

## Default after merge
- **Hosted `apotheke-api`:** unchanged, classic (`"0"`) until #297. Once it's on, the hosted cap is 1000.
- **Self-hosted FrankenPHP in worker mode:** recycles after 1000 requests per thread unless `APOTHEKE_WORKER_MAX_REQUESTS` is set.
- **Merge order:** #298 first, then #297 catches up (#297 L-2055 and M-2057). The ids used here are 2093–2094; the merge read added none.
