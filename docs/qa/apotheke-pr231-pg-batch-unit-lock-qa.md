# QA review: PR #231, unit lock for local PostgreSQL batches

- PR: #231 `fix/pg-batch-unit-lock` (Coder2): "fix(core): take the unit lock for local PostgreSQL batches"
- Head reviewed: `aa4013f7f49987bf3353eb0f669128147a4923bb` (4 commits; base main `64a88a5`, 0 behind)
- Closes L-1743, N-1740 and N-1741 (QA review of #230)
- QA gate, 5 Nov 2026 (Europe/Athens)

## Checklist

| Check | Result |
|---|---|
| Head, 0 behind, mergeable | confirmed, clean |
| CI on head | 20/20 success |
| Root suite, SQLite / PostgreSQL 17 | 463 OK each (6 / 1 skipped) |
| Module suite, SQLite / PostgreSQL env | 1368 OK each |
| lint, deptrac (0), DI, routes --check, check-commits (4), gitleaks | clean |
| Migrations or schema | none |
| Real-parallel probe (6 processes; 8 rounds; adds at the limit and deletes; both modes; both DBs) | 64/64 OK: no extra attachments or files, one history row per delete |
| Joined, failed and multi-guard batches on PostgreSQL (extra probe) | OK |
| New race tests, 25 runs under heavy CPU load / 50 runs under suite load | 25/25 / 48/50 (L-1746) |
| Mutations (9) | all killed by the suite or the parallel probe; 4 survive one of them (N-1747) |

## Verified

- **L-1743.** A local PostgreSQL batch now runs in a transaction that first takes the unit's advisory lock, with the same key, wait, 503 and lock order as transaction mode. With 6 parallel processes, the attachment limit and the single-delete rule both hold.
- **N-1740 and N-1741.** The controller tests cover the skipped delete (no undo token) and an error that isn't a skip. The undo-token and swallow-error mutants are now killed.
- **Joined transaction.** The lock is held until the outer commit. A guard that fails inside a joined unit returns its own error, the outer transaction carries on and commits, and an outer rollback undoes the batch and releases the lock. `transDepth` returns to 0 in every case.
- **SQLite and D1.** The lock wrapper applies only to the local batch executor on PostgreSQL. The edge executor and SQLite get no wrapper and behave as before (the SQLite parallel probe passes as before).
- **Deadlock risk.** Unchanged: caller row locks first, then the unit lock, the same order as the existing unit sites. The batch takes no second unit.
- **503.** Same wait loop and default 5 s wait as transaction mode. The test uses a shorter wait.

## Points raised by the author

1. **Unlocked reads in the callback:** sufficient. They match D1, where reads also run outside the batch. Every decision that affects writes is re-checked by an in-batch guard under the lock (attachment limit, item exists), and the parallel probe confirms it. A read that isn't guarded only produces a pre-check error earlier.
2. **Replays after a failed batch:** they always end with a statement that is forced to fail, so they keep nothing. They run before any PHP side effect, so no files are deleted and no history is written. Because they run without the lock, a concurrent change can make the reported failing step differ (N-1748); the data stays correct.
3. **API surface:** `BatchUnitOfWork` is built in one place only. Its wrapper rolls back on any throw, so no transaction is leaked. `local()` hands out the raw connection (N-1751). deptrac is clean.
4. **Flake risk:** real but low. See L-1746.
5. **Controller tests only in batch mode:** a test gap at most (N-1750). The controller logic doesn't depend on the mode, and the repository's skip path is covered in both modes.

## Findings

### High
None.

### Medium
None.

### Low

- **L-1746.** `testALocalBatchHoldsTheLockWhileItRuns` failed 2 of 50 runs under suite load. The helper process waits a fixed 0.3 s before it tries the lock; if the main process takes longer than that to reach the lock, the helper gets the lock first and the assertion fails. The code under test was correct in those runs. Suggested fix: start the helper's wait only after a signal that the batch holds the lock, or retry its probe for longer.

### Note

- **N-1747.** Removing the transaction around the lock survives the repository suite; only the real-parallel probe kills it. Ignoring the key, undo-skip removal and swallowing any error survive the probe but are killed by the suite. A lock-held assertion run in autocommit would close the first gap.
- **N-1748.** Replays that locate a failed step run without the lock, so under concurrency the error reported can name a different step than the one that failed. Data is unaffected.
- **N-1749.** Advisory lock keys depend only on table and key, not on the schema, so two installs in different schemas of one database share keys. That causes extra waiting only, the same as transaction mode.
- **N-1750.** The controller skip tests run in batch mode only. Adding the connection mode would be cheap.
- **N-1751.** The public `LocalBatchExecutor::local()` returns the connection with no statement filter. It has one in-repo caller. Keep it internal (comment or a narrower type) so tests can't use it to write past the filter.

## Security

- No secrets, tokens or identifiers; gitleaks is clean.
- No routes, permissions, migrations or dependencies change.
- The change affects only the local PostgreSQL test model; edge and self-hosted request paths are unchanged.

## Verdict

**PASS.** No High or Medium findings. L-1746 and the notes can be handled in a follow-up.
