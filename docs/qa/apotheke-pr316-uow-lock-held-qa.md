# PR #316 unit-lock held QA

Head `135123c1a5506e7ea982e459a917b08ed15ed0e4` (live). Plain merge of main `2510138e` into `4eade41c`. Own commits `20a2fbae`, `4eade41c`, off `85c6ad8c`. Test-only follow-up to L-1888.

## Checklist

1. Diff against main `2510138e` is `docs/HANDOFF.md` and `tests/Modules/Core/UnitOfWorkLockTest.php` only. No production file. `README.md`, `VERSION` (`3.43.0`) and `CHANGELOG.md` match main.
2. L-1888 is closed. A lock that skips at budget 0 fails the four other-key tests, because a second connection can still take the key. A lock that treats budget 0 as a one-hour budget fails the same four, because the busy key is obtained instead of answering 503. A global lock and a no-op lock fail all 9. A fresh budget per key fails the shared-budget test. Never giving up, and a 20× budget, each fail the five 503 tests. A lock taken per table (key ignored) makes the four other-key units answer 503. On SQLite the class skips all 9 tests, so none of these mutations fail a SQLite test. The class is PostgreSQL only and always opens a PostgreSQL database.
3. The held-key checks are real. `connectAgain()` is a second connection (`application_name=apotheke_second`). `otherGets` is `pg_try_advisory_xact_lock` (no wait). A successful try ends with the statement, so the probe does not keep the lock. The connection-executor test checks the key inside the callback and again after `run()` returns. Batch and stocktake tests go through `heldDuring`, which asserts the keys are refused inside the caller’s transaction and free after that transaction commits. `refusedAtOnce` expects 503 on a key the other process still holds. It has no wall-clock bound.
4. `UnitOfWorkLockTest`: SQLite 30/30 skipped, about 9 ms. PostgreSQL 30/30, 10.703–10.991 s (avg 10.811). Under four busy cores, 15/15, 11.635–12.003 s (avg 11.781). Core suite: SQLite 697 tests, 6468 assertions, 14 skipped. PostgreSQL 985 tests, 9565 assertions, 3 skipped. No failures.
5. The hand-off line matches those checks and drops the open N-2103 bullet. The flaky `< 100` ms assertion is gone and is not replaced by another time bound. `docs/qa/apotheke-n2103-unit-of-work-lock-timing-flake.md` is unchanged and still says open.

## Findings

None. L-1888 is closed. Next id 1889.

## Security

`modules/Core/Database/UnitsOfWork.php` is unchanged. No new route, query, or output. The advisory lock, the 503, and the single shared deadline are the same as main.

## Verdict

PASS at `135123c1`. L-1888 is closed. No high, medium, or low finding.

CI run 43455907275 on this head: Lint, deptrac, DI, D1, Docker, visual, upgrade/rollback, CodeQL, and Tests (PHP 8.4, sqlite, 11m31s) pass. Tests (PHP 8.4, pgsql) was still pending. That job must be green on this head before merge.
