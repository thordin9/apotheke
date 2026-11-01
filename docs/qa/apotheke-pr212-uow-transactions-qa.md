# QA: PR 212, keep units of work whole inside transactions

- PR: thordin9/apotheke#212, `fix/uow-nesting`, by Coder2. The follow-up to #210 (L-1625, L-1626, N-1627 to N-1629).
- Head: `b855f5866a801c674d58b09e5d3229b4e39abfd1` (2 commits)
- Base: main `57192cf` (v3.14.0), 0 behind, mergeable `clean`. Diff: +333/-40 in 15 files.

## Verdict

**PASS.** No H or M findings; two L and two N. All five #210 findings are fixed:
- A unit inside a plain transaction is now kept whole or not at all.
- A closed unit refuses further writes.
- The ranking, version-check and `lc_messages` notes are in place.

The two L findings are a missing test for one branch and a SQLite-only edge where the result is safe: the whole outer transaction rolls back.

## Checklist

| Check | Result |
|---|---|
| CI on the head | 20/20 success, including `Tests (PHP 8.4, sqlite)` and `Tests (PHP 8.4, pgsql)` |
| Local suites (PHP 8.4, own PG17 clusters) | pgsql 453 OK (1 skipped). sqlite 453 OK (6 skipped) |
| Extra skip | `ReleaseTest::testRealChangelogUpgradeNoteLeadsTheNextReleaseNotes` skips because Unreleased starts with a plain note. Simulating the release insert carries the note into the next release section as its lead |
| Diff against main | The PR's 2 commits only. `docs/qa`, `VERSION`, `README.md`, `.github` and `.gitleaks.toml` are equal to main, and the released CHANGELOG sections are byte-identical |
| `routes --check` | Up to date |
| gitleaks 8.30.1, `main..b855f58` | No leaks. No UUIDs or tokens |
| Schema, existing installs | No migration. No call site uses a unit yet, and the top-level unit path is unchanged from #210 |
| D1 and the batch path | The SAVEPOINT is taken only on a connection executor that is already inside a transaction. An executor without a connection is still refused before any SQL. The docs say a unit on D1 is one batch and never nests |
| Targeted mutants | 21 of 24 killed. The survivors are covered by L-1630 and N-1632 |

## #210 findings

- **L-1625, fixed.** A unit that joins an open plain transaction runs under `SAVEPOINT apotheke_uow`. It was checked on both databases:
  - A caught guard or failed statement keeps nothing of the unit, holds included, and the outer transaction then commits its own rows.
  - 200 units in one transaction, with interleaved FK failures and false `assert`s caught, keep exactly the successful units and their children.
  - With two registries, a nested `apotheke_uow` inside an outer unit's savepoint rolls back only the inner failed unit.
  - On PostgreSQL, a failed hold inside a joined unit leaves the outer transaction usable after the rollback to the savepoint.
- **L-1626, fixed.** A unit is closed after `run()` returns, after it throws, and after discard.
  - Later calls to `hold`, `insert`, `update`, `write`, `assert` and `flush` throw `LogicException`, or `WriteFailed` for a discarded unit.
  - Mutants that skip the check on each of these were killed on both databases.
- **N-1627, fixed.** The second layout puts exact names at hits 1 and 501. All four ranking mutants are now killed: first id, first chunk, first two ids and skip-the-first-id.
- **N-1628, fixed.** `database.md`, `deployment-and-operations.md` and the CHANGELOG ask for `lc_messages` = C or English. That is the default of the official image, and the docs note it is superuser-only to set.
- **N-1629, fixed.**
  - The runner checks the version on an in-memory connection before the file is opened.
  - `checkSqliteVersion: false` is there for the P4-3 runner.
  - Injected fake versions mean removing either check, or ignoring the option, fails a test (3 mutants killed).

## Security

- **SQL.** The SAVEPOINT name is a constant, and no new statement takes outside input. The binding paths are unchanged from #210.
- **Fail-safe.** Every new failure path either rolls back to the savepoint or leaves the outer transaction failed. The outer status is restored only when the rollback and release both succeeded and the outer was healthy before the unit. A transaction that already had a failed statement still refuses to commit on both databases (checked; see L-1630).
- **Errors.** Messages name the step only. `LogicException` texts name no values.
- **The SQLite version parameter.** `$sqliteVersion` on `Services::apothekeDb()` can only be set from code, and production callers pass none.
- **Request state.** No new static state. `DatabaseStateTest` still passes, and the registry lifecycle mutants from #210 are still killed.

## Findings

**L-1630: no test covers a unit inside an already-failed outer transaction.**
- Restoring the outer status depends on whether the outer was healthy before the unit started. A mutant that restores it regardless survives every test.
- That mutant would let a transaction with an earlier failed statement commit, once a later unit fails and is caught.
- The current code is correct: checked on both databases, nothing is kept and the commit is refused.
- Add a test: a failed statement, then a unit that fails and is caught, then the outer commit must throw and keep nothing.

**L-1631: on SQLite, a callback that catches its own failed statement rolls back the whole outer transaction.**
- The unit's status check runs in its own `Writes::transaction()` wrapper, after the savepoint has already been released.
- So on SQLite, a failed hold that the callback catches makes the unit throw after release. The outer transaction is left failed and rolls back entirely.
- On PostgreSQL the same case rolls back to the savepoint, and the outer transaction carries on.
- Nothing partial is kept on either database, but the drivers differ, and "the outer transaction carries on" does not hold on SQLite.
- Fix: check `transStatus()` inside the savepoint before `RELEASE`, and treat a failed status like an exception.

**N-1632: two savepoint mutants survive.**
- Always taking a savepoint, even at top level, survives. It is equivalent on PDO.
- Not releasing the savepoint after a successful unit also survives. Many units in one transaction would then pile up open savepoints, which is costly on PostgreSQL (subtransactions).
- A test that the savepoint is gone after a successful joined unit would pin it.

**N-1633: the "parity cases" are not there yet.** `UnitOfWorkTest::modes()` still yields only the transaction mode. The batch cases come with part 3.

## Mutants (24, both databases)

- **Killed (21).**
  - Savepoint: never take it, `transDepth > 1`, no `ROLLBACK TO`, no status restore, swallow the exception.
  - Closed unit: the closed check off, no close in `finally`, `insert`, `write`, `assert`, `update` and `hold` unchecked, flush twice, a discarded unit treated as closed.
  - Ranking: the four mutants.
  - Version check: the app check, the runner check, the runner ignoring the option.
- **Survived (3).** Restore ignoring the prior status (L-1630), always take a savepoint, no release on success (N-1632).
