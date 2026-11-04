# QA review: PR 227, staged restores wired into Restorer (P4-2b, design B part 2a)

- PR: thordin9/apotheke#227, `feat(backup): wire staged restores into Restorer`
- Head reviewed: `fd292db83241a84b7055038b5d7824e89bb71505` (0 behind main `87cb107`, CLEAN), 9 commits, 12 files, +748/-55
- Gate: full, security and data integrity focus
- Finding IDs: 1707..1711 used

## Design-B conditions and the #223 findings

- [x] **1. Marker CI4 only:** `restore_marker` stays in `UNCARRIED_MIGRATIONS` and `upg_ci4_only()`. The 2.6.26 backup round trip through the staged path is deferred to 2b, as the PR states.
- [x] **2. Staging copies:**
  - The copies are never backed up and are not visible to the app; they use the reserved prefix.
  - The upload sweep drops leftovers only when it can take the lock. A running restore's copies were kept in every probe. A stopped restore's copies are dropped once its lease expires (N-1705 closed).
- [x] **3. Pre-flight:** called on the batch-only path before anything is staged, answering 413 (N-1703 closed). Removing the call fails 4 tests.
- [x] **4. One-batch swap:** pinned by a test (L-1701 closed). Splitting the swap into two batches fails 8 tests. The 100k-row test passes. Real D1 timing at 100k rows has not been measured; it is deferred to 2b.
- [x] **5. Parity:** both modes, both drivers. Rows, ids and sequences match, including a `parent_id` pointing at a later row, and stops before and after the swap. The 2.6.26 backup is deferred to 2b.
- [x] **6. Docs:** ADR-002 now describes design B (L-1702 closed). `docs/database.md`, CHANGELOG and HANDOFF match the code.
- [x] **L-1700, one staged restore at a time:**
  - A lock row with a token and an expiry is checked inside every stage, swap and drop batch, so there is no gap between the check and the write. Removing the check fails 12 tests.
  - In probes, a second restore got 409; a restore that lost the lock could not stage, swap or drop; and a stale lock expired after the lease and was cleared by the sweep.
- [x] **Stopped confirms:** resolved from the marker, to restored or failed. Both mutants on that mapping were killed. One limit applies (L-1707).

## Checklist

- [x] Root suite: SQLite 463 OK, PostgreSQL 17 (own scratch cluster) 463 OK
- [x] Module suite: SQLite 964 OK, both drivers 1,315 OK
- [x] `--filter 'Backup|Restor'`: 117 OK with PostgreSQL, 87 OK on SQLite only
- [x] Root config: it lists only `tests/Unit`, but `Ci4ModuleSuiteTest` and `scripts/coverage.sh` run the module suite. The CI logs show the module suite running on both legs, so this is not a finding
- [x] Staged tests 15+ times on PostgreSQL: one transient failure under shared load (N-1709)
- [x] Mutation run: 17 mutants, 14 killed, 3 survived (L-1708)
- [x] Lint, deptrac (0 violations), `apotheke:routes --check`, gitleaks: clean
- [x] CI on the head: 20/20 green, mergeable

## Findings

### High

None.

### Medium

None.

### Low

- **L-1707: the marker keeps only the last run.** Restore A can stop after its swap, and then a later staged restore B can complete before A's upload is read again. In that case A is resolved as failed, "Nothing was changed", even though its rows were applied and later replaced. Keep a short history of runs, or report "may have been applied" when the marker names a different run.
- **L-1708: lease renewal and expiry have no tests.** Removing the renewal per chunk, or making the lease effectively endless, survives the suite. The probes show both work today. Without renewal, a restore that stages for longer than the lease could lose its lock part way. The `applied('lock')` guard also survives, but that is equivalent, since run ids are hex.

### Note

- **N-1709: the pre-flight test can flake on PostgreSQL.** It sets the limit to the measured database size. `pg_database_size` covers the whole database, so other activity can shift it. It failed once while other suites shared the database, and passed 25 of 25 times on its own.
- **N-1710: the sweep takes the lock on every restore API request on a batch-only backend.** This adds lock and DDL writes to each request. A confirm that overlaps another request's sweep can get a spurious 409. After a crash, new restores get 409 until the lease (5 minutes) expires.
- **N-1711: the lease uses each instance's own clock.** A lock written by an instance whose clock runs far ahead blocks restores until real time catches up, and there is no admin path to clear it.

## Security

- No new route or permission. The API passes the upload id as the run id, and the CLI uses a random one. A run id can never be the lock name.
- Table and column names are allow-listed, columns are checked against the live table, and every value is bound.
- The lock token is random per stage. Release and renewal touch only the stage's own token.
- Copies never enter app backups, and the sweep cannot drop them while a restore holds the lock.
- Self-hosted installs keep the single-transaction path; only an executor without a connection is staged.
- Edge-phase blockers: none new. The stopped-upload check still relies on a local file lock (planned for P4-4). Real D1 timing is not measured.
- gitleaks: no leaks.

## Verdict

**PASS.** No High or Medium findings, and every design-B condition is met for 2a, with the 2.6.26 round trip, value conversion and D1 timing deferred to 2b. L-1707 and L-1708 can be fixed in 2b.
