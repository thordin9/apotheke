# PR #310 unit-lock test QA

Head `956e2ea683670767cc27af57eeb003d110f8e3dd` (live), a merge of main `c4fee3ce` (v3.42.3) into the gated head `f913015f`, 0 behind. Merge-only read: no conflicts, own diff equal to the gated head's. Gated head parent `87cce869`. Test-only. Commits `951fcd1a`, `f913015f`.

## Checklist

1. Own diff is `docs/HANDOFF.md` (+1) and `tests/Modules/Core/UnitOfWorkLockTest.php` (51 lines, +25/−27). No production file. `README.md`, `VERSION` and `CHANGELOG.md` match main `c4fee3ce` (and matched `87cce869` at the gated head). No changelog bullet.
2. Mutations of `UnitsOfWork` (file restored after each): a global lock and a no-op lock fail all 9 tests. A fresh budget per key fails only the shared-budget test. Never giving up, and a 20× budget, each fail the five 503 tests (`must answer 503` — the waiter takes the key when the holder releases). Wait budget 0 as a one-hour budget, and wait budget 0 as a skip, pass all 9 tests (81 assertions). See L-1888.
3. `UnitOfWorkLockTest` 30/30 on SQLite (9 skipped, ~8 ms). 30/30 on PostgreSQL, 10.588–10.842 s (avg 10.738). 15/15 with four busy cores, 11.363–11.554 s (avg 11.435). No coverage driver (no pcov, no xdebug).
4. Core module suite: SQLite 697 tests, 6476 assertions, 14 skipped, 0 failures. PostgreSQL 985 tests, 9539 assertions, 3 skipped, 0 failures. Zip-entry, CMYK and the deadlock flake did not appear. CI run 43409361922 on this head: Lint, deptrac, DI, D1, PHP 8.4 sqlite and pgsql, Docker, visual, upgrade/rollback, CodeQL all pass.

## What the new checks prove

(a) Different keys stay independent against a lock that ignores the key: the four other-key units use budget 0 and still call the lock, so a global lock answers 503 and those tests fail. They do not show that the other key’s lock was taken.

(b) The same key waits at least the budget and answers 503 while the holder still has it. `testABusyKeyAnswers503AfterTheWaitAndOtherKeysDoNotWait` checks elapsed ≥ 150 ms and `otherGets` is false. A waiter that never gives up, or that waits 20× the budget, takes the key when the holder commits and the test fails. There is no upper wall-clock bound; the holder’s remaining time is the bound.

(c) One budget is shared. Key 1 held 800 ms, key 2 held 1400 ms, budget 1000 ms. A fresh budget per key takes key 2 and `testAMultiKeyUnitSharesOneWaitBudget` fails. `otherGets('items','2')` is false after the 503, so the waiter stopped while key 2 was still held.

Production budget 0 is one try, then 503: the deadline is now, and the check runs after a failed try. A free key is taken on the first try.

## Findings

L-1888. The four other-key checks pass budget 0 and only assert that the unit returns. The message says the other key gets its lock. `otherGets` is not called during that unit. A lock that skips when the budget is 0, and a lock that treats 0 as unlimited, both pass the suite. The hand-off line states the same claim. Same-key 503, give-up while held, and the shared budget stay covered on a positive budget.

Next id 1889.

## Security

`modules/Core/Database/UnitsOfWork.php` is unchanged. No new route, query, or output. The advisory lock, the 503, and the single shared deadline are the same as `87cce869`. L-1888 is a test gap on an uncontended path.

## Verdict

PASS at `956e2ea6` (content gated at `f913015f`). One low finding (L-1888). No high or medium finding.
