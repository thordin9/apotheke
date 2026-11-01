# QA: PR 210, unit of work and its per-request registry (P4-2a, part 2 of 3)

- PR: thordin9/apotheke#210, `feat/p4-unit-of-work`, by Coder2
- Head: `b373f5de1518d09f3fdba5b683893e9d29f9b073` (28 commits, including #209's history and main merges)
- Base: main `386afde` (v3.13.0), 0 behind, mergeable `clean`. Diff: +1055/-50 in 22 files.

## Verdict

**PASS.** No H or M findings; two L and three N. The registry is per request with nothing static, the unit's ordering, guards and nesting behave as documented, and the #209 carry-overs are fixed. L-1625 should be fixed before P4-2b starts converting sites.

## Checklist

| Check | Result |
|---|---|
| CI on the head | 20/20 success, including `Tests (PHP 8.4, sqlite)` and `Tests (PHP 8.4, pgsql)` |
| Local suites (PHP 8.4, own PG17 cluster) | pgsql 453 OK. sqlite 453 with 1 failure (`Ci4HttpTest::testShippedBoot…`, a cluster-wide count), from my two suites sharing one cluster; it passes alone |
| Diff against main is #210 only | Yes. Main's #209 squash `08aebbc` has #209's tree (`a6f375e`), and `a6f375e→head` equals `main→head` outside the release files |
| Released CHANGELOG sections, `VERSION`, `docs/qa`, `.github`, `.gitleaks.toml` | Equal to main. The CHANGELOG adds only an Unreleased upgrade note. `README.md` adds "SQLite 3.38+" |
| `routes --check` | Up to date |
| gitleaks 8.30.1, `main..b373f5d` | No leaks. No UUIDs or tokens in the diff |
| Schema | No migration. `uow_guard` and `uow_refs` exist only in the test schema, and are marked planned for part 3 |
| Existing installs | No call site uses a unit or the batch yet. New behaviour is the SQLite ≥3.38 refusal (N-1623 below) |
| Targeted mutants | 20 of 23 killed. 3 survivors (N-1627, N-1629) |

## #209 carry-overs

- **L-1620, fixed.** `testRankingScoresEveryIdOfEachChunk` kills first-id-only, first-chunk-only and first-two-ids on both databases. Skip-the-first-id survives on both here, not only pgsql (N-1627).
- **N-1621, fixed.** The guard matches the whole first line, and these are ordinary failures (code 0) on both databases:
  - a unique violation whose value is the exact guard text, with or without `ERROR:` and newlines;
  - a failed integer cast of that text (PostgreSQL puts the value in its primary message).
  - The real guard still gets `GUARD_FAILED`, and the substring mutant was killed.
  - A German or French server message is not a guard, so it stays an ordinary, rolled-back failure. This is safe (N-1628).
- **N-1622, fixed.** `UnitsOfWork` and `Services::unitsOfWork()` exist; the ADR, `database.md`, `architecture.md` and `UowSchema` mark part 3 as planned and no call site as using a unit yet, which matches the code.
- **N-1623, fixed.** `Dialect::requireSqlite('3.38.0')` runs at app connect (`SQLite3::version()`) and in `PdoMigrationRunner::connect()`. Forcing the minimum to 9.0.0:
  - `apotheke:migrate` and `bin/migrate.php` print `SQLite 9.0.0 or newer is required (JSON functions); this PHP has SQLite 3.46.1.` and exit 1;
  - the web answers the generic 500 page or problem JSON, with the line in the server log only;
  - `/healthz` answers 500 with an empty body, as for a refused connect.
  - The images are Alpine; CI and the Docker jobs are green.
  - Invalid UTF-8 now throws `WriteFailed` (mutant killed).

## Security

- **Binding.** `insert()` and `update()` go through the query builder with bound values. `write()` and `assert()` take SQL from code with binds, and `Ref`s become ints at flush.
- **Injection, tested on both drivers.** A quote, a backslash, `'); DROP TABLE …; --`, `?` and an emoji round-trip exactly through insert, update (a `Ref` in the where clause), `assert` and `write` with `expect`.
- **Write lock.** The SQLite lock (`DELETE FROM <escaped table> WHERE 0 = 1`) uses an escaped identifier from code.
- **Errors.** Messages name the step only. A joined failure carries the inner exception as `previous`, and that is a `UserError` or `WriteFailed`, never database text.
- **Request state.**
  - No static properties or static variables in `Core\Database` (tested).
  - `sqlExecutor` and `unitsOfWork` are not persistent (tested). A second worker request gets fresh units (tested).
  - The registry is released in a `finally`; `RequestCleanup` discards a unit a fatal error left open, unflushed. The four lifecycle mutants were killed.

## Findings

**L-1625: a unit that joins a plain `Writes::transaction()` can be kept partly.**
- Nesting is flat. When a guard fails at flush, the steps that already ran stay in the outer transaction. If the outer caller catches the error and carries on, they commit.
- Tested on both drivers: an outer `Writes::transaction` → unit (`insert`, then `write` with `expect` 1 that fails) → caught. The result is that `inner` is committed.
- This breaks the "one atomic unit" contract, and differs from a D1 batch, which keeps nothing. Unit-in-unit is protected (`fail()`); a unit inside a plain transaction is not.
- No call site does this today (`Imports::run()` uses a per-row SAVEPOINT; the job path makes each row its own outer transaction). P4-2b will mix the two styles.
- Fix: when joined on PDO, run the flush under a SAVEPOINT (allowed on PDO), or fail the outer transaction. Add a test and a parity case.

**L-1626: writes queued after the unit ran are dropped silently.**
- A `UnitOfWork` kept outside the callback still accepts `insert()` and the rest after `run()` returned, and those writes never run, with no error.
- Tested on both drivers: no error, no late row.
- Fix: mark the unit closed at flush (and at discard), and throw a `LogicException` on any further call.

**N-1627: one ranking mutant survives (skip-the-first-id).**
- The two exact "Bolt" items sit at positions 250 and 550, never first in their chunk.
- Moving them to positions 1 and 501 kills this mutant on both databases. But that alone loses first-id-only on pgsql.
- So keep the current case and add a second layout with an exact name at a chunk's first id. Between them, all four rank mutants are killed on both databases (tried locally).

**N-1628: a localized PostgreSQL turns guard failures into ordinary failures.**
- With a non-English `lc_messages`, a guard failure becomes code 0. The unit still rolls back, so this is safe.
- Callers that map `GUARD_FAILED` to 409 would answer 500 instead. CodeIgniter exposes no SQLSTATE.
- Note `lc_messages` = C or English in the PostgreSQL docs, or treat `GUARD_FAILED` as a hint only. Checked with the German and French texts; the box has no PostgreSQL translations.

**N-1629: small notes on the SQLite version check.**
- `PdoMigrationRunner` now calls `sqlite_version()`, which the ADR lists as not allowed on D1. That's fine on PDO, but the edge runner in P4-3 must skip the check.
- A refused `apotheke:migrate` leaves a harmless 0-byte `catalog.sqlite`.
- Removing either `requireSqlite()` call survives every test (2 mutants). A boot test with a forced minimum would pin them.

## Mutants (23, both databases)

Killed (20): rank first id, first chunk, first two ids; no SQLite lock, no flush, joined failure ignored, nested without `fail()`; `expect` unchecked, `assert` never fails; `Ref` unresolved, resolved twice; release only on success, no cleanup registration, discard ignored, discard keeps open; flush reversed; guard substring, `JsonException` leaks; minimum 3.0, `<=`. Survived (3): rank skip first id (N-1627), and removing either `requireSqlite()` call (N-1629).
