# PR #310 evidence

Final head `956e2ea683670767cc27af57eeb003d110f8e3dd` (merge-only read below). Gated head `f913015fe9c52dfa80d660c05fced6a8806bfcf9`. Parent `87cce869`. `git ls-remote origin refs/pull/310/head` matched before the verdict. Diff vs parent: `docs/HANDOFF.md` +1, `tests/Modules/Core/UnitOfWorkLockTest.php` 51 lines +25/−27. `cmp` of README, VERSION, CHANGELOG against `87cce869` matched. VERSION 3.42.2. `git status` clean after the mutations were restored.

## Mutations (`modules/Core/Database/UnitsOfWork.php`, restored after each)

| Change | Result |
| --- | --- |
| Always lock one global key | exit 1. All 9 tests fail. 41 assertions. |
| New deadline inside the key loop | exit 1. Only `testAMultiKeyUnitSharesOneWaitBudget`. 9 tests, 76 assertions, 1 failure. |
| Deadline check removed (never gives up) | exit 1. Five failures: `testABusyKeyAnswers503AfterTheWaitAndOtherKeysDoNotWait`, `testALocalBatchTakesTheUnitLock`, `testAMultiKeyUnitTakesEachKeyLock`, `testAMultiKeyUnitSharesOneWaitBudget`, `testAStocktakeStartLocksItsLocationOnly`. 53 assertions. |
| `lockPostgreKeys` returns immediately | exit 1. All 9 tests fail. 41 assertions. |
| Budget 0 uses a one-hour deadline | exit 0. OK, 9 tests, 81 assertions. |
| Budget 0 returns before locking | exit 0. OK, 9 tests, 81 assertions. |
| Deadline multiplied by 20 | exit 1. Same five tests as never-give-up. Each failed with “must answer 503”. 9 tests, 53 assertions, 5 failures. The run finished in about 11 s: the waiter took the key when the holder released, it did not sit for the inflated budget. |

Baseline before mutations: OK, 9 tests, 81 assertions.

## Repeats

SQLite, drivers without PostgreSQL: 30/30 exit 0. Each run Tests 9, Assertions 0, Skipped 9, Time about 00:00.008.

PostgreSQL: 30/30 exit 0, OK 9 tests, 81 assertions. Time min 10.588 s, max 10.842 s, avg 10.738 s.

Same test with four busy-loop processes: 15/15 exit 0. Time min 11.363 s, max 11.554 s, avg 11.435 s.

`php -m` has no pcov and no xdebug, so coverage was not run.

## Surrounding suite (`tests/Modules/Core`)

SQLite: OK, 697 tests, 6476 assertions, 14 skipped, 23.073 s. The 9 lock tests are among the skips.

PostgreSQL: OK, 985 tests, 9539 assertions, 3 skipped, 63.518 s.

No failure in either run. The known local zip-entry and CMYK failures live outside this suite. The PostgreSQL deadlock retry test did not fail.

## CI

Run 43409361922 on `f913015f`, all completed checks pass, including Tests (PHP 8.4, sqlite) 14m53s and Tests (PHP 8.4, pgsql) 14m34s. CodeQL pass. PR open.

## Lock notes

`lockPostgreKeys` sets one deadline, `hrtime(true) + lockWaitMs * 1_000_000`, and each key uses that deadline. `lockPostgre` try-locks, then throws UserError 503 when `hrtime` is past the deadline. Budget 0 is one attempt: a free key succeeds before the loop; a busy key fails the try and then sees the deadline.

The new other-key calls are budget 0 in `testABusyKeyAnswers503AfterTheWaitAndOtherKeysDoNotWait` (`d:b`), `testALocalBatchTakesTheUnitLock` (key `2`), `testAMultiKeyUnitTakesEachKeyLock` (keys `3`,`4`) and `testAStocktakeStartLocksItsLocationOnly` (attic). Give-up while held is `otherGets` false in the busy-key test and in the shared-budget test. The shared-budget holds are 800 ms and 1400 ms against a 1000 ms budget.

Scratch PostgreSQL was stopped with `pg_ctl -D` and the data directory removed after these runs.

## Merge-only read at `956e2ea6`

Parents `f913015f` and main `c4fee3ce` (v3.42.3); 0 behind; `f913015f` is an ancestor. `git merge-tree` of `f913015f` with `c4fee3ce`: no conflicts. Own diff (excluding CHANGELOG and docs/qa; index and @@ lines dropped), `87cce869..f913015f` vs `c4fee3ce..956e2ea6`: 139 lines each, `cmp` equal. Main..head: `docs/HANDOFF.md` +1, `UnitOfWorkLockTest.php` +25/−27. README, VERSION and CHANGELOG equal main.
