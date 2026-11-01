# QA: PR 214, batch unit of work (P4-2a, 3/3)

- PR: thordin9/apotheke#214, `feat/p4-batch-path`, by Coder2. Builds on #209, #210 and #212.
- Head: `2f4f5bce75393ff788ed87373c5c9e204f39fb3e` (6 commits; the last merges main v3.14.2)
- Base: main `d8d264f`, 0 behind, mergeable `clean`. Diff: +692/-74 in 19 files.

## Verdict

**PASS.** No H or M findings; two L and two N.
- The batch path is atomic, and Refs resolve correctly.
- The guard and ref tables are empty after every unit, and migration 0031 is safe in both directions.
- The #212 items are fixed.

The L findings concern the local D1 model and one misuse that differs between the two modes. Neither blocks the edge phase. N-1640 lists what the P4-3 executors must cover.

## Checklist

| Check | Result |
|---|---|
| CI on the head | 20/20 success, including both `Tests` jobs and both `Upgrade and rollback` jobs (2.6.26 to CI4 and back, SQLite and PostgreSQL) |
| Local suites (PHP 8.4, own PG17 clusters) | pgsql 457 OK (1 skipped). sqlite 457 OK (6 skipped, as on main) |
| lint, deptrac, DI rule, `routes --check` | Clean |
| gitleaks 8.30.1, `main..2f4f5bc` | No leaks. No UUIDs or tokens |
| Merge of main | Only the HANDOFF conflict, resolved with no markers left. CHANGELOG, VERSION, README, `docs/qa`, `.github` and `.gitleaks.toml` are equal to main |
| Migration 0031 | Applies alone on a v3.14.2 database (SQLite and PostgreSQL) and runs once. v3.14.2 then runs with it in place. Same SQL on both databases. Not carried in backups, and the registry test catches a missing entry. 2.6.x round trip: CI only, as PHP 8.3 is not available locally |
| Atomicity | A failing guard or FK error in the middle of a 240-step batch keeps nothing, leaves `uow_refs` and `uow_guard` empty, and throws that step's own error |
| Refs | 150 parent/child pairs resolve to the right ids. A batch inside an open transaction on a second PostgreSQL connection does not block, and gets its own ids |
| Request state | No static state in the new classes. Each Ref gets a ULID from 130 random bits |
| D1 limits | 100 parameters per statement are enforced, the ids come back through one list parameter, and no `sqlite_version()` is used. On D1 a batch counts as one query against the per-invocation limit. The 100 KB statement length is not modelled (N-1640) |
| Targeted mutants | 27 of 31 killed. The 4 survivors are 3 equivalent and 1 test gap (N-1639) |

## #212 findings

- **N-1633, fixed.** `modes()` yields a batch mode for each driver, so every unit-of-work case runs in both modes.
- **L-1630, fixed.** A unit inside an already-failed transaction is refused, and the outer transaction keeps nothing. The restore-regardless mutant is now killed.
- **L-1631, fixed.** The status is checked before `RELEASE`, so a swallowed failure rolls back to the savepoint on both databases (mutant killed).
- **N-1632, fixed.** The savepoint is released on success, and the no-`RELEASE` mutant is now killed. Always taking a savepoint still survives, as expected: it is equivalent.

## Security

- **SQL.** Table and column names must be plain identifiers and are quoted. Values and ULIDs are always bound. The guard and replay statements are constants.
- **Concurrency.** `uow_refs` rows are written and deleted inside the same batch, under random keys, so other transactions never see them. `uow_guard` never holds a row. Both tables belong to the installation's own database or schema.
- **Replay.** It runs only prefixes that end in a failing statement, so a replay never commits. If concurrent writes change the outcome, the original error is thrown.
- **Errors.** The message names the step; the caller's own `UserError` passes through.
- **Production path.** `LocalBatchExecutor` is used only by tests. The PDO path is unchanged, apart from releasing the previous result before a statement (covered by a test).

## Findings

**L-1637: the local D1 model refuses less than its docs say.**
- `LocalBatchExecutor` checks only the start of a statement.
- A statement that opens with a comment, or carries a second statement after a `;`, gets through. So do `START TRANSACTION`, `ABORT`, `ATTACH`, `VACUUM`, `ANALYZE` and `REINDEX`, and `sqlite_*()` names split from their parentheses by a comment.
- Once through, a transaction-control statement can break the batch's atomicity on the local connection, and on SQLite it can leave the connection inside a transaction.
- D1 itself rejects transaction statements, so production is not affected. But the tests and `docs/database.md` rely on the model ("refuses what D1 refuses").
- Fix: strip leading comments, refuse a `;` outside quotes, add the statements above, and match the function name across comments.

**L-1638: a hold that binds an unresolved Ref behaves differently in the two modes.**
- In batch mode, holds run first, so the Ref's id is still NULL and the hold silently matches nothing.
- In transaction mode the same call fails with a PHP `Error`.
- Both are misuse, but the batch result is quietly wrong.
- Fix: refuse an unresolved Ref in `hold()` with a `LogicException` in both modes, and add a test.

**N-1639: mutant survivors.**
- Equivalent: skipping the single-guard shortcut (the replay finds the same step), `MAX(id)` in place of the `RETURNING` row, and always taking a savepoint.
- Test gap: a statement with more binds than placeholders is not tested.

**N-1640: for the edge phase (P4-3: Containers, DO SQLite, D1, R2).** None of this blocks it.
- The D1 and DO executors must map their own error text to `GUARD_FAILED`, checked against real D1 and DO messages. The current recogniser expects the plain SQLite text. If a guard failure is not recognised, the replay names the wrong step and the caller's own error is lost.
- The DO SQL API accepts several statements in one call, so its executor needs its own refusals; it cannot reuse L-1637's.
- Statement length (100 KB) is not checked locally.
- Each failed batch costs up to log2(n) + 1 replay batches on a single-threaded database.

## Mutants (31, both databases)

- **Killed (27).**
  - Executor: the refusals, the parameter limit, `query()` unchecked, no savepoint in a transaction.
  - Batch: holds not first, no `uow_refs` cleanup, no replay, replay off by one, both guard comparisons, the assert inverted, a constant or short ULID, reading the wrong result, quote-blind parsing, `IS NULL` dropped, the empty-batch close.
  - Savepoint: no pre-check, no check before `RELEASE`, no `RELEASE`, no status reset, never taking a savepoint.
  - Elsewhere: the batch unit not closed, the result release dropped, a base Ref, the backup registry.
- **Survived (4).** See N-1639.
