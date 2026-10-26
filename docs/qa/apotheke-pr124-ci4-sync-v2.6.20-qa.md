# Apotheke PR #124 — CI4 sync v2.6.20 QA (rework)

| | |
|---|---|
| PR | https://github.com/thordin9/apotheke/pull/124 |
| Verified head | `7dd5168d65313b0d6d6882efdc41b759b590dd66` |
| Merge | `7dd5168` ← `9c65eed` (PR side) + `3c67df7` (`feature/ci4` tip at the time) |
| Delta since `f9ba081` | `9b2dae7` (L-330), `20c43fe` (N-331), `9c65eed` (docs) |
| Earlier gate | CHANGES REQUESTED @ `f9ba081` (L-330, N-331, N-332) |
| Scope | PHP 8.4 CI4, SQLite + PG17: delta re-check plus merge-only read |
| Author CI | run 40375929165: 22/22 success (read via gh) |

**Base note:** `feature/ci4` was `3c67df7` when this ran, and #125 was not in it. #125 has since merged (`1d80332`), so #124 needs another plain merge. That new merge needs its own merge-only read.

## Checklist

| Check | Result |
|---|---|
| `git show --remerge-diff 7dd5168` | Pass: conflicts in **CHANGELOG + HANDOFF only** |
| `comm -12` both-parent files | CHANGELOG, HANDOFF, HardeningHttpTest (auto-merged, no manual resolve) |
| Diff-of-diffs (no CHANGELOG/HANDOFF/docs/qa) | Pass: `3c67df7..7dd5168` = `83e733f..f9ba081` + `f9ba081..20c43fe`, hunk for hunk |
| L-330: pad every turn-on 403 | **Pass** (code + timing) |
| N-331: tests catch an N-304 regression | **Pass** (TurnOnPaceTest, SQLite+PG) |
| Lint / gitleaks `f9ba081..7dd5168` / toml sha | Pass / clean / `b5bc9f98…993a8f` |
| CI4 suites @ `7dd5168` | **pgsql 786 OK** (5 skipped); **sqlite 786**, 1 error passed alone (see note), 6 skipped |
| Mutants on the delta | **8/9 killed** |

## Security

**Delta review:** `Accounts::padFailure($started)` calls the same `SignInPace::pad` as `signIn()`. In turn-on, `$checked` is now kept. If `signIn` passed (right password) but the account is not an admin, `padFailure` runs before the 403. A wrong password is padded once inside `signIn()`, so nothing is padded twice. An admin success is not padded. There is still one password check for every name, including a NUL name (N-304 kept). `DUMMY_HASH` is unchanged (L-300 kept).

**Timing matrix:** turn-on with sign-in off, n=20, CI4. PG run confirmed `pdo=pgsql`.

| Case | PG17 median ms | SQLite median ms | status |
|---|---:|---:|---|
| non-admin, right pw (cost 12 / cost 10) | 1088 / 1089 | 1020 / 1020 | 403 |
| non-admin, wrong pw | 1090 | 1019 | 403 |
| admin, wrong pw (cost 12 / cost 10) | 1085 / 1088 | 1019 / 1019 | 403 |
| NUL name (leading / inner) | 1091 / 1091 | 1020 / 1021 | 403 |
| **admin, right pw** | **295** | **229** | 303 |

Every refusal lands at the ~1 s deadline, with p10–p90 inside ±25 ms. The old ~235 ms non-admin gap (L-330) is gone. An admin success is not padded. An unknown name with no NUL takes the existing first-admin create path (303, ~300 ms). That path is by design, not a refusal.

**Suite note:** sqlite `Ci4BranchCiTest::testPgsqlProofSeparatesMissingTableFromOtherFailures` failed once with "probe table already exists". My timing and mutant runs were using the same QA database at the same time. The test passed alone (18/18). This is QA-side interference, like N-262, not a finding.

### Mutants (`ci4/phpunit.xml.dist`, TurnOnPaceTest + SignInAccountsTest, SQLite+PG)

| # | Change | Result |
|---|---|---|
| M3 | turn-on old short-circuit (no check for non-admin/NUL) | **Killed** (was surviving) |
| M8 | `$started = 0` | **Killed** (was surviving) |
| M10 | unknown name skips DUMMY_HASH | **Killed** (was surviving) |
| M11 | `padFailure` body empty | **Killed** |
| M12 | `padFailure` call removed | **Killed** |
| M13 | `$checked !== null` → `=== null` | **Killed** |
| M14 | pad an admin success too | **Killed** |
| M16 | pad unconditionally (double pad on wrong pw) | **Killed** |
| M15 | `padFailure` restarts the deadline (`pad(start())`) | **Survives** (N-336) |

## Findings (IDs from 330)

| ID | Sev | Status | Note |
|---|---|---|---|
| L-330 | L | **Closed** | Turn-on 403 after a right non-admin password now waits for the deadline (~1.09 s PG, ~1.02 s SQLite). |
| N-331 | N | **Closed** | TurnOnPaceTest checks one wait, the deadline, and the password-check cost for every refusal, and no wait on success. M3/M8/M10 are killed. |
| N-332 | N | Open (info) | `comm -12` lists more files than the remerge conflicts (now HardeningHttpTest, auto-merged). The conflicts were CHANGELOG + HANDOFF only. |
| N-336 | N | New | TurnOnPaceTest only sets a lower bound on the wait (>2 s of 3 s). A `padFailure` that restarts the deadline (about deadline + one verify, a small tell) still passes. Optional: add an upper bound or check that the sleep ≈ deadline − elapsed. |

No new H/M/L.

## Verdict

**PASS @ `7dd5168`.** L-330 is fixed and verified on SQLite and PG17. N-331 is closed: the new tests kill the three mutants that survived before and 5 new padFailure ones. The merge is clean, and the diff-of-diffs matches. N-332 and N-336 are optional notes. #125 (`1d80332`) is now on `feature/ci4`, so the next merge commit needs a separate merge-only read before #124 lands.
