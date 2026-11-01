# QA: apotheke PR #219, "fix(core): lock unit of work reads on PostgreSQL"

**Verdict: PASS.** Reviewed head `282dbc47f056d9019b06e139b8c87a663c97ecf9`, 2026-11-01 (EET): full gate on `c2616fc`, quick re-gate after a plain main merge:
- same repo, base `main` `dc27b4b` (v3.15.3, with #218), 0 behind, 3 commits (one a main merge), 6 files (+151/−15), no schema change;
- CI 20/20 green on the head.

It closes #217's L-1660, L-1661, N-1662 and N-1663.

## Checklist

- [x] **L-1660, lock.** On PostgreSQL a unit takes `pg_advisory_xact_lock(int4, int4)` on its table (and key) before the callback reads, as SQLite's no-op write does. SQLite and the batch path are unchanged; an executor without a connection (D1, Durable Object, local batch) never reaches the lock.
- [x] **Saved filters, with real concurrent requests** (four processes at once, several rounds, both DBs):
  - same name: one saved, three 409s naming the stored name;
  - at the limit: one saved, three 422s, never over the limit.
  - `main` gave 500s and up to limit + 3 on PostgreSQL under the same load; SQLite was already correct.
- [x] **Keys.** `lockKeys()` maps a CRC32 of `apotheke_uow:<table>` and of the key to signed int4 (`roles` exceeds 2³¹ and is converted correctly). The result is deterministic across processes and PHP versions, with no seed.
  - The only other advisory lock is the migration runner's single bigint key. PostgreSQL keeps one-key and two-key locks apart (verified).
  - A CRC collision would only make two tables or lists wait for each other.
- [x] **Lock scope.**
  - Each unit takes exactly one lock; a nested unit joins the open one without locking again.
  - Joined to a caller's transaction, the lock is kept until the outer commit (released if the savepoint rolls back, verified). The only such caller today is `RoleAdmin::delete()`, which is short, holds the admin rows first and then the roles lock. No path takes these in the opposite order, and PostgreSQL reports advisory-lock deadlocks rather than hanging.
  - Throughput: units are short. With the install-wide list, all views queue on one key for the length of one small transaction.
- [x] **N-1663.** Recently viewed locks per scope. With four concurrent processes:
  - four views of one new item all succeed and leave one row;
  - views of four items get distinct consecutive sequence numbers.

  `main` failed three writes and repeated a sequence number.
- [x] **N-1662.** The batch path's 409 reads the stored name (tested with different casing).
- [x] **L-1661.** New tests in both modes on both DBs cover the cross-device prune, the `viewed_at` refresh, the sweep at sequence 50 (not before) and each line's home location. All five #217 survivors are now killed.
- [x] **Concurrency tests:** both passed 20 of 20 runs on PostgreSQL (about 3 s per run, both DBs). The other client always commits after 400 ms, and CI jobs have time limits.
- [x] **Suites:** SQLite 458 OK (29 skipped, 1 incomplete; PostgreSQL-only and environment tests); own PG17 cluster with password auth, 458 OK (2 skipped).
- [x] **Tooling:** lint and deptrac clean (0 violations), routes up to date, gitleaks 8.30.1 on `main..pr219`: no leaks. CHANGELOG and HANDOFF are accurate.
- [x] **Merged-head re-check (`282dbc4`).** The merge touches only CHANGELOG and HANDOFF (clean, no markers); the code diff is identical to `c2616fc`'s; release files, migrations, ADR and `docs/qa` match `main`. Unit-of-work, executor and filter, site and parity tests pass on both DBs; the concurrency tests passed 5 of 5 on PostgreSQL. Lock mutants give the same results: no lock or unit statement is refused by #218's filter.
- [x] **Mutations: 15 on lock placement, type, keys and scope, plus the #217 survivors; 13 killed.**
  - Killed: lock removed, non-waiting, taken after the reads, key ignored, one key for every table, sign conversion dropped, recently viewed without its scope, the stored-name read, and all five #217 survivors.
  - Survivors in L-1664.

## Findings

- **L-1664:** Two lock mutants survive every test; the code itself is correct:
  - a session-level lock in place of the transaction lock. It would never be released while a worker's connection stays open, so later units on that table would wait for good.
  - no lock for a unit joined to a caller's transaction.

  Worth a test that another client can take the lock right after a unit commits, and one with a unit joined to a caller's transaction.
- **N-1665:** For part 2: a unit without a key now serialises all units on its table on PostgreSQL, and waits with no lock timeout. A joined unit keeps the lock until the outer commit.
  - Sites converted later (item save and the like) should pass a key where a row or list is the real scope.
  - Agree a fixed order when an outer transaction holds row locks and then opens a unit.

## Security

- No new endpoint, route, permission or input. The lock arguments are integers derived from constant table names and the server-built list scope, sent as bound parameters.
- The change closes PostgreSQL races that gave over-limit saved filters and duplicate sequence numbers.
- No secrets in the diff. Nothing is H or M.

## Verdict

**PASS.** No H/M findings and no decision needed. L-1660, L-1661, N-1662 and N-1663 are fixed; L-1664 and N-1665 go to the backlog. Next free ID: 1666.
