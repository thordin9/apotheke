# QA: PR #150, CI4 step 16 part 3 (compiled routes, p95 enforcement)

**Head** `a5549c712e8abeb6255d6168d2c9d4a745944ee5` (sync round; full review at `36f1308`) · **Base** `feature/ci4` `767126a`, 0 behind · **CI** 25/25 green (read-only `gh`) · PHP 8.4.27 / 8.3.36, PostgreSQL 17, throwaway `qa150_` roles and DBs · 8 cores, load average 0.6 to 3.4 during the timing runs · 2026-10-28

## Verdict

**PASS** at `a5549c7`, with notes N-640 to N-643 and N-645 and no blocking findings. N-644 is fixed by `332394e`.

## Checklist

- [x] **#144 equivalence.** The tree of `8c9840d` equals the tree of closed #144's `4403453` (`3c91f00a…`).
- [x] **Gate sampling.** At least 200 timed requests per area and app (`PARITY_GATE_MIN_SAMPLES`; `--rounds` can only raise it); measured 200 to 210 per area. The app asked first alternates each round.
- [x] **Gate enforcement.** CI sets PG `PARITY_P95_MAX_PCT=20` plus a +20 ms median guard, and SQLite `PARITY_P95_MAX_ABS_MS=3` with `PARITY_BUDGET_STAT=median`. The job is not `continue-on-error`, and any area over budget exits 1. Mutation: a 4 ms sleep in CI4 fails 12/12 SQLite areas (median +4.9 ms); a 5 ms sleep fails 9/12 PG areas (p95 +26 to +28 %).
- [x] **Re-measured, 2 runs each, 15 rounds.** 196 steps, 0 diffs once asset mtimes are aligned (N-490). SQLite median gap +0.7 to +1.6 ms (bar +3). PG p95 -7 to +11 % (bar +20 %), median -0.9 to +0.7 ms. The two runs agree within about 0.3 ms at the median.
- [x] **Compiled routes.** `apotheke:routes --check` is up to date. No closures, host or subdomain options, or environment branches in `Routes.php`. `CompiledRoutesTest` compares every property with a plain load and covers stale files and pre-added routes. It runs in CI through `Ci4ModuleSuiteTest`.
- [x] **Stale compiled file.** Merged with `7924841` (scratch, not committed): `--check` and two `CompiledRoutesTest` cases fail, and the runtime falls back to `Routes.php`. `spark routes` gives the same 174 lines with the stale or the regenerated file.
- [x] **Cache handler (dummy).** Nothing in the app or modules uses `cache()`, the cache service or the throttler. The service is `DummyHandler`.
- [x] **SQLite pragmas.** `foreign_keys=1`, `busy_timeout=5000`, `journal_mode=wal`, `synchronous=1` (NORMAL, as legacy) after the single pragma.
- [x] **Menu fix (`fea8e12`).** `Viewer::allows()` is `can()` with the user read once; same closures, same order. Parity: 0 diffs. Mutant (menu checks with no user): the gate shows 32 diffs in 9 areas, while the `ci4/tests/Layout` suite stays green (N-640).
- [x] **files/ 0750 hard check.** On the head, the upgrade/rollback gate (SQLite) passes: all steps equal and 7 mutations caught, including `files/ 0700`. With the `files` rule removed from `compare.php`, the mutation is reported as not caught.
- [x] **N-546 (`b3ed3bc`).** A mutation now counts only if a DIFF/FAIL line names its target. Mutant: an image changed instead of the attachment gives exit 1 with a DIFF that names something else, and it is reported as not caught (the old rule would have passed it). See N-641 and N-642.
- [x] **Suites.** Root suite with the CI4 app (includes the CI4 module suite): SQLite 873 tests OK (10 skipped: PG-only, environment and app-gated tests); PG 873 OK (5 skipped). Lint, `ci4-arch di`, `check-commits` (14 commits) and gitleaks are clean.
- [x] **Sync round (`36f1308..a5549c7`).** `0812061` (merge of `7924841`) and `a5549c7` (merge of `767126a`): remerge-diff empty and HANDOFF-only respectively; diff-of-diffs empty, excluding HANDOFF, CHANGELOG and `docs/qa`. `332394e` touches only `RoutesCompiled.php`, which is byte-identical to a fresh `apotheke:routes` at the head. `--check` is clean and `CompiledRoutesTest` passes 6/6. `.gitleaks.toml` equals `767126a`'s; gitleaks on the range and on `--all` is clean. There is no other change and no `docs/qa` file for PR 150.
- [x] **Cloudflare phase.** No new per-request global state; compiled routes and flags live on the collection instance. N-645.

## Findings

| ID | Grade | Finding | Suggested change |
|---|---|---|---|
| N-640 | Note | The menu permission checks are covered only by the parity gate and the visual suite. A unit-level mutant passes `ci4/tests/Layout`. | Add a Layout test that renders the menu for two roles. |
| N-641 | Note | The N-546 match for the bare target `files` passes only because the summary line reads "37 files," with a comma. A summary without it would match. | Match `mode files:` for that mutation, or anchor targets on the DIFF field. |
| N-642 | Note | Mutations in `upgrade-rollback.sh` accumulate: the 0644 original from m5 is still present in m6, so the `files/` comparison also fails for that reason. The N-546 rule keeps the result correct. | Undo each mutation before the next one. |
| N-643 | Note | `compare.php` takes the wanted `files/` mode from its own umask, not from the app's. | Pass the expected umask explicitly, or document it. |
| N-644 | Note | **Fixed** `332394e`. After #147 the compiled routes were stale; the runtime fell back safely and the test caught it. Now regenerated and checked. | None. |
| N-645 | Note | Cloudflare phase: each request hashes `Routes.php` and `require`s the compiled file from `APPPATH` (filesystem). | Bundle the compiled array at build time for Workers. |
| N-490 | Note (open) | Manifest `?v=` diff from asset mtimes on a fresh checkout. | As before. |

## Cleanup

Servers stopped, `qa150_` DBs and roles dropped, scratch worktrees and directories removed. Nothing committed or pushed.
