# QA review: PR 221, unit of work item history sites (P4-2b, part 2)

- PR: thordin9/apotheke#221, branch `feat/p4-2b-history`
- Head reviewed: `cdd7ce9b28e606d188c869146b1ca699a27e2ca6` (0 behind main `05ffb6a`, v3.15.4)
- Scope: 13 files, +471/-40, 4 commits before this review
- Gate: full
- Finding IDs: 1666..1669 used

## Checklist

- [x] Root suite, SQLite: 458 tests OK (29 skipped, 1 incomplete, as on main)
- [x] Root suite, PostgreSQL 17 (own scratch cluster): 458 tests OK (2 skipped)
- [x] Module suite, both drivers: 1,217 tests, 46,443 assertions OK
- [x] `UnitOfWorkLockTest` and `UnitOfWorkHistorySitesTest` 5x each on both drivers: all green, no flakes
- [x] Concurrency probes, main vs PR, both drivers: stocktake starts, attachment adds, per-item cap, add/remove churn, different items, concurrent item delete
- [x] Mutation run: 11 mutants on the lock loop, lock keys, history rows, unused-tags delete, `ActivityLogger::queue`; 10 killed, 1 survived (N-1669)
- [x] `scripts/lint.sh`, deptrac (0 violations), `apotheke:routes --check`, gitleaks on the PR range: clean
- [x] CHANGELOG, HANDOFF, `docs/database.md` and PR body match the code, including the sites left and their reasons
- [x] CI on the head: 20/20 green

## What the PR does, verified

- `ItemHistory::queue()` writes history rows through the unit; `ActivityLogger` shares one row shape between `log()` and `queue()`.
- `Attachments::add()`, `Attachments::delete()` and `ItemRepository::delete()` each run as one unit keyed per item.
- `Stocktakes::start()` is keyed per location and reads the open stocktake under the lock. Probe: six concurrent starts gave six open stocktakes on main and one on the PR, on both drivers.
- Add/remove churn on one item: on main under PostgreSQL one run left rows and files out of step with one 404; on the PR rows, files and history all matched on both drivers.
- The PostgreSQL lock is a transaction-level try-lock with back-off, ending in a 503 after `LOCK_WAIT_MS`. Mutants that remove the deadline, change the lock to session level, change the status code or loosen the result check were all killed.
- Different items do not wait on each other.

## Findings

### High

None.

### Medium

None.

### Low

- **L-1666: the per-item attachment cap is checked outside the unit.** Concurrent uploads to one item can exceed the configured maximum. Main does the same, so this is not a regression. Now that each add holds the per-item lock, the count could be checked inside the unit as a follow-up.
- **L-1667: concurrent deletes of one item can each write a "deleted" history row.** The item read happens before the unit and the delete has no row-count expectation, so overlapping requests each log a deletion. The item itself is deleted once. Main does the same on PostgreSQL. A follow-up could expect one affected row, or re-read under the lock.

### Note

- **N-1668: the stocktake open check relies on the lock.** In connection mode the per-location lock makes start safe. The batch path has no guard of its own against a second open stocktake. This is worth covering when the batch driver lands (P4-3).
- **N-1669: the back-off cap has no test.** Raising the 50 ms cap survives the suite. This is harmless, since the deadline still bounds the wait, but a timing test could pin it.

## Security

- No new routes, inputs or permission checks. Routes are unchanged.
- The lock wait now ends in a 503 with a generic message, which removes an unbounded wait on a busy key. No internal detail is exposed.
- Attachment file handling is unchanged: files are stored before the unit and cleaned up on failure.
- gitleaks found no secrets in the PR range.

## Verdict

**PASS.** No High or Medium findings. L-1666 and L-1667 behave the same on main and can follow up. The notes are optional.
