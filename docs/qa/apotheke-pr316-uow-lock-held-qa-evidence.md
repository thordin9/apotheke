# PR #316 evidence

Head `135123c1a5506e7ea982e459a917b08ed15ed0e4`. `git ls-remote origin refs/pull/316/head` matched before the verdict. Parents `4eade41c` and `2510138e`. `20a2fbae`’s parent is `85c6ad8c`. Diff vs main: `docs/HANDOFF.md`, `tests/Modules/Core/UnitOfWorkLockTest.php`. `cmp` of README, VERSION, CHANGELOG against `2510138e` matched. VERSION 3.43.0. Production file restored after every mutation (`git diff` empty).

## Mutations of `lockPostgreKeys` / `lockPostgre` / `lockKeys`

SQLite column is the same for every row: exit 0, Tests 9, Assertions 0, Skipped 9, no failure. `setUp` skips unless `pgsql` is in `TestDatabase::drivers()`, then the test opens PostgreSQL only.

| Change | PostgreSQL |
| --- | --- |
| Baseline | exit 0. OK, 9 tests, 108 assertions. |
| (a) return immediately when budget is 0 | exit 1. 4 failures, 60 assertions: `testABusyKeyAnswers503AfterTheWaitAndOtherKeysDoNotWait` (“holds its own key's lock”, true is false), `testALocalBatchTakesTheUnitLock`, `testAMultiKeyUnitTakesEachKeyLock`, `testAStocktakeStartLocksItsLocationOnly` (“took the lock”, true is false). |
| (b) budget 0 uses a one-hour deadline | exit 1. Same four tests, 80 assertions. Message: “a busy key with no wait budget must answer 503, not wait for the other client”. |
| (c) always lock one global key | exit 1. All 9 tests, 43 assertions. |
| (d) `lockPostgreKeys` returns immediately | exit 1. All 9 tests, 43 assertions. |
| (e) new deadline inside the key loop | exit 1. Only `testAMultiKeyUnitSharesOneWaitBudget`. 103 assertions, 1 failure. |
| (f) deadline check removed | exit 1. Five failures, 75 assertions: the four 503 tests plus `testAMultiKeyUnitSharesOneWaitBudget`. |
| (f) deadline multiplied by 20 | exit 1. Same five tests, 80 assertions. Each failed with “must answer 503”. The waiter took the key when the holder released. |
| (g) `lockKeys` ignores the key (per table) | exit 2. Four errors, 52 assertions: the four other-key tests. `UserError` 503, “The database is busy. Try again in a moment.” |

## What the new checks call

`otherGets` uses `$this->other` from `connectAgain()`. The holder is a separate process (`holdInAnotherClient`). The probe query is one `pg_query` with no open transaction, so a successful `pg_try_advisory_xact_lock` ends with the statement.

`heldDuring` runs the unit inside `Writes::transaction` on the test connection, asserts `otherGets` is false for each key before that transaction returns, and asserts it is true after the commit. Used by the local-batch tests (keys `2`, and `3`/`4`) and by the stocktake start (attic). The stocktake path uses `ConnectionExecutor`; an open caller transaction makes `run()` join it, so the lock lasts until that commit.

`refusedAtOnce` calls the same no-wait unit on the key the other process holds and expects code 503. No elapsed assertion.

## Repeats

SQLite, PostgreSQL env unset: 30/30 exit 0. Each run Tests 9, Assertions 0, Skipped 9, Time 00:00.009.

PostgreSQL: 30/30 exit 0. Time min 10.703 s, max 10.991 s, avg 10.811 s.

Same test with four busy-loop processes: 15/15 exit 0. Time min 11.635 s, max 12.003 s, avg 11.781 s.

## Core suite (`tests/Modules/Core`)

SQLite: OK, 697 tests, 6468 assertions, 14 skipped, 23.662 s.

PostgreSQL: OK, 985 tests, 9565 assertions, 3 skipped, 65.100 s.

## CI

Run 43455907275 on `135123c1`, recorded with Tests (PHP 8.4, pgsql) still pending. Tests (PHP 8.4, sqlite) pass, 11m31s. Lint, deptrac, DI, D1, Docker, visual shards, upgrade/rollback, and CodeQL pass. The pending PostgreSQL job must be green on this head before merge.

## Hand-off

The open bullet for N-2103 is removed. The replacement sentence describes the held-key check and the no-wait 503. The note file `docs/qa/apotheke-n2103-unit-of-work-lock-timing-flake.md` is not in the diff and its status line still says open.

Scratch PostgreSQL was stopped with `pg_ctl -D` and the data directory removed.
