# QA: apotheke #263, keep hosted sessions in the database

- PR: https://github.com/thordin9/apotheke/pull/263
- Reviewed head: `7ac1a4a5cd432e92e60b72c78e60e8eb3cfa3b11` (re-gate of the delta on top of `04972c0d`, which **FAIL**ed on M-1890)
- Merge-only read: `8a929ab99d1553ce225fef4dd1e78194e8489781`. The PR targets `main` @ `e4e9d5af` (#277 squash `edd51dd1`, then the v3.33.0 release). Plain merges only: `c58fe278` brings #262 into `4fc8cc18` (on top of the PASS at `7ac1a4a5`), `e73e74c9` brings #275, and `8a929ab9` brings #277 and v3.33.0 (CI, docs and release only). 0 behind main. The PR's 19 files are unchanged. All 19 have the same added and removed lines as at `e73e74c9`, and the earlier `docs/HANDOFF.md` resolution (#262's text plus #263's two sessions edits) still holds. `CHANGELOG.md` conflicted with the v3.33.0 release section; the #263 sessions bullet is kept under [Unreleased] / ### Added, and everything from [3.33.0] down matches main. CI on this head is 21/21 green, including D1 (local workerd) and Visual E2E.
- Gate: full security (delta `04972c0d...7ac1a4a5`, CI, root suite on sqlite and pgsql, mutations)
- Reviewer: Apotheke QA 2

The PR still targets `feat/p4-5-r2-files` at `cfb203ad` (#259). This re-gate covers the four new commits (7 files, +370/−23) versus `04972c0d`. The earlier review of the two commits versus that base still holds except where the findings below supersede it. `mergeable_state` is clean. When this is merged to main, merge main in as a plain merge.

## Checklist

- [x] Head confirmed unchanged at `7ac1a4a5`. Commits: `fix(core): store session keys as digests` (40), `test(core): cover stale session lock takeover` (45), `docs: list the sessions entry under Added` (41), `fix(core): write sessions only under the holder's claim` (55). All are valid Conventional Commits.
- [x] CI at this head: 18/18 green on the CI workflow (Tests PHP 8.4 sqlite and pgsql, Upgrade and rollback on both databases, Visual E2E 3 shards, Docker build/smoke and Compose for both images, Lint, Secret scan, DI, deptrac, Conventional Commits, Headless mobile, D1 local workerd).
- [x] Local root suite, PHP 8.4.24. PHP 8.3 is not installed, so `upgrade-rollback.sh` was not re-run here; both CI Upgrade and rollback jobs are green. Sqlite: 590 tests, 9,419 assertions, 29 skipped, 1 incomplete (`Ci4BranchCiTest::testPgsqlProofSeparatesMissingTableFromOtherFailures`, Postgres was up for the pgsql leg and the default proof password does not match; not in this delta), no failures. Pgsql (`TEST_PG_HOST=127.0.0.1`): 590 tests, 10,556 assertions, 2 skipped, OK in 11:54. (A first pgsql attempt aborted when the box ran out of inodes from leftover `/tmp` testdirs; that was environmental, not a failure of this PR.)
- [x] M-1890 closed. Every lookup uses `key()` (`hash('sha256', $id)`): read, write/store, destroy, exists/validateId, extend, lock and release. GC does not take an id. Errors stay fixed sentences. Tests assert the row is keyed by the digest, a raw-id decoy is never touched, and no column holds the raw id. Migration 0034 is still CI4-only, uncarried and unreleased (no shipped version has this table), so there are no live raw-id rows to migrate.
- [x] L-1891 closed. `testAStaleLockIsTakenOver` covers `locked_until` already expired and equal to now. Dropping `OR locked_until <= ?` makes that test error (`Could not read the session.`).
- [x] N-1892 closed. The handwritten #263 bullet is under `### Added`. A `release.php` fold of the squash subject `feat(core): keep hosted sessions in the database` leaves a single **core:** line under Added (the generated `feat(core)` line is dropped by scope). `check-links` is clean.
- [x] M-1870 closed. After A claims, the lease expires, B takes over, writes and releases, A's write throws `Could not store the session.` and the row stays B's payload (suite + a local interleaving probe). Store, extend and release on a claimed row require that request's `lock_token`. A stale holder cannot recreate a row that was destroyed or removed by gc; that is the safer side (resurrection after logout would be a security bug). A claim nobody took still stores. Mutating the `lock_token` condition on write, extend or release, resetting the in-memory claim before store, or restoring the old empty/expired upsert, is caught.
- [x] A stale `write()` throws rather than returning false. `session_write_close()` then throws and leaves the session active (L-1894). Returning false only emits a warning and returns normally. The lagged request does not overwrite the newer payload.
- [x] Docs: HANDOFF, ADR-002 and `docs/database.md` now say the row is keyed by the SHA-256 (hex) of the session id, and that a taken-over claim stores nothing. Cloudflare phase is unchanged: `BatchExecutor` / `d1.internal`, no per-request global handler state beyond the instance claim, binding-driven hosted DB config.
- [x] Mutations: 6 of 7 targeted mutations were caught (see the report). Not calling `clearLock()` inside `release()` still leaves the handler suite green (`write()` already clears a successful store).

## Findings

| ID | Grade | Status | Finding |
|---|---|---|---|
| M-1870 | Medium | Closed at `7ac1a4a5` | An expired claim can no longer store after a replacement holder has written and released. |
| M-1890 | Medium, hardening | Closed at `d2ee9811` | The row is keyed by the SHA-256 of the session id. The raw id does not reach the database. |
| L-1891 | Low, tests | Closed at `606d0513` | Stale-lock takeover (`locked_until` expired or equal to now) is tested; dropping the clause is caught. |
| N-1892 | Nit | Closed at `33650d3d` | The handwritten bullet sits under `### Added`. A squash `feat(core)` release keeps a single Added line. |
| L-1894 | Low, reliability | Open | A stale `write()` throws `Could not store the session.`, so `session_write_close()` throws and leaves the session active. On a long-lived worker, the next request's leftover `session_write_close()` in `SessionCookie` can throw again. Return false after a no-op (the newer payload is already kept), or catch the leftover close. |

0 High, 0 Medium open, 1 Low open, 0 Nit open.

## Verdict

**PASS** at `7ac1a4a5`, confirmed by the merge-only read at `8a929ab9`. M-1870, M-1890, L-1891 and N-1892 are closed. L-1894 does not block. Squash-merge with the PR title as the subject.
