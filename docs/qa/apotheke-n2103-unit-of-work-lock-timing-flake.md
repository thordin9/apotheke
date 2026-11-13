# N-2103: UnitOfWorkLockTest multi-key timing assertion flakes in CI

Severity **N** · Opened 2026-11-12 (EET) by DevOps from the #297 CI · Status: **open**. Seen once, and it passed on re-run.

## Symptom
`Tests\Core\UnitOfWorkLockTest::testAMultiKeyUnitTakesEachKeyLock` failed with
`Failed asserting that 223.550274 is less than 100.` The assertion is the
`'unrelated keys do not wait'` check in `tests/Modules/Core/UnitOfWorkLockTest.php`, which measures the wall-clock time
of a unit on keys `['3', '4']` while another client holds the advisory lock for key `1`. That unit is expected to finish
in under 100 ms.

## Observed
- CI run 43291117743, attempt 1, job `Tests (PHP 8.4, sqlite)`, PR #297 head 2691f6fe (21:40 EET). The failure was
  logged at 19:40:05 UTC.
- I re-ran the failed job once and it passed at the same head. #297 changes only config, docs and string assertions,
  nothing in units of work or locking.

## Hypothesis
The 100 ms bound is wall-clock time on a shared CI runner. The unit does real database work on
`LocalBatchExecutor`, the PostgreSQL service is busy with other jobs, and a scheduling pause of about 120 ms is
enough to cross the bound. This would mean CI timing noise, not a lock that waits.

## Next steps
- Make the bound relative to the lock timeout under test (150 ms): for example, assert that the unrelated unit takes
  well under 150 ms, or assert that it did not wait by checking that it never hit the timeout path.
- Track further occurrences by run id here.
