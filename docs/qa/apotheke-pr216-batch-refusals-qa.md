# QA: PR 216, refuse unsupported batch statements and unknown refs

- PR: thordin9/apotheke#216, `fix/batch-executor-guards`, by Coder2. The follow-up to #214 (L-1637, L-1638, N-1639) and #215 (N-1641, N-1642).
- Head: `f4f4cc695bb2012756c36f612228ef68f61aa40b` (8 commits, one of them a merge of main v3.15.1)
- Base: main `4de885d`, 0 behind, mergeable `clean`. Diff: +318/-31 in 12 files; no migration.

## Verdict

**PASS.** No H or M findings; one L and three N.
- L-1638, N-1639, N-1641 and N-1642 are fixed.
- L-1637 is fixed for SQLite's own quoting. Two quoting forms the matcher does not know still let a second statement through (L-1643). As with L-1637, this affects only the local test model, since D1 rejects those statements itself.
- N-1644 lists statements that D1 accepts but the model refuses. Restore will need them before it moves onto the batch path.

## Checklist

| Check | Result |
|---|---|
| CI on the head | 20/20 success, including both `Tests` jobs and both `Upgrade and rollback` jobs |
| Local suites (PHP 8.4, own PG17 clusters) | pgsql 458 OK (1 skipped). sqlite 458 OK (6 skipped, as on main) |
| lint, deptrac, DI rule, `routes --check` | Clean. The OpenAPI document validates |
| gitleaks 8.30.1, `main..f4f4cc6` | No leaks. No UUIDs or tokens |
| Merge of main | Only HANDOFF conflicted, with no markers left. CHANGELOG, VERSION, README, migrations, `docs/qa` and `.github` are equal to main |
| Matcher probes (about 40 cases) | Correct: nested comments, `--` inside a literal, doubled quotes, backslashes in standard strings, unterminated comments and literals, keywords as column names or quoted names, CTEs, `RETURNING`, a trailing `;` with a comment, and `sqlite_*()` quoted, spaced, commented or schema-qualified. Escapes found: see L-1643 |
| Full suites with every statement run through the matcher | Apart from the expected `PRAGMA` and `SAVEPOINT` statements, the only refusals are those in N-1644. No ordinary app statement is refused |
| Bind counting | Refused in both modes. No production call site uses a unit of work yet (`Services::unitsOfWork()` has no callers), so the change on the transaction path affects no caller |
| Worker mode | No static properties added: the matcher and the checks are pure static methods |
| Targeted mutants | 36 of 41 killed. The 5 survivors are 2 equivalent and 3 test gaps (N-1646) |

## Prior findings

- **L-1637, partly fixed.** Comments are taken out, and SQLite `'`, `"` and backtick literals are emptied before matching. A second or an empty statement is refused, along with every keyword in the docblock (each keyword mutant was killed). The cases left open are in L-1643.
  - A leading comment before an allowed statement stays allowed, as decided.
- **L-1638, fixed.** A hold that binds the Ref of a row not yet written throws a `LogicException` naming the table, in both modes and on both databases. A Ref that is already resolved binds as its id, in transaction mode too.
- **N-1639, fixed.** Too many or too few binds are refused in both modes, before anything runs. A `?` inside a `'` or `"` literal is not counted; for `?` in comments see N-1645.
- **N-1641, fixed.** All six restore operations reference the new shared `ServerError` response.
- **N-1642, fixed.** `Services::restoreOwner()` keeps the old order and results:
  - a guest or anonymous user gets null;
  - with sign-in off the owner is `local`;
  - a deleted account gets null, and its upload and read are refused with 403, with nothing stored.

  The mutants for a driver exception read as no account, for anonymous users not refused, and for a deleted account read as `local` are all killed.

## Security

- **Scope.** `LocalBatchExecutor` is a test model. The PDO path and production code paths do not use it. D1 enforces its own statement rules.
- **New checks fail closed.** The bind and Ref checks only refuse more. They run before any SQL, and the messages name no values.
- **Restore owner.** Unchanged for existing accounts. An account deleted during a request is treated as a guest, so nothing is stored under a key that is no longer valid.

## Findings

**L-1643: two quoting forms still hide a second statement from the matcher.**
- The matcher only knows SQLite's quoting.
- On PostgreSQL, an `E''` literal with a backslash-escaped quote, or a dollar-quoted string, can make it misread where a literal ends.
- On SQLite, so can a `[...]` name that contains a quote. A `[...]`-quoted `sqlite_*()` call is not refused either.
- Once through, a second `COMMIT` breaks the batch's atomicity on the local connection; on SQLite it can also leave the connection inside a transaction.
- The docblock's claim that these forms are "refused, never let through" does not hold.
- The refusal tests run on SQLite only.
- Fix: refuse `E'`, dollar quotes and `[` outside literals (or parse them), and run the refusal cases on PostgreSQL too.

**N-1644: the model refuses statements that D1 accepts.**
- D1 documents support for `PRAGMA table_info`, `foreign_keys`, `defer_foreign_keys` and `optimize`; the model refuses every `PRAGMA`.
- A write to the `sqlite_sequence` table is read as a `sqlite_*()` call, because the table name is followed by a column list.
- A `CREATE TRIGGER` body is refused by the one-statement rule.
- Nothing uses the batch path for these yet. But restore uses `defer_foreign_keys` and `sqlite_sequence`, so the model needs an allow-list before P4-2b or P4-3 moves restore onto it.
- `database.md` ("refuses what D1 refuses") overstates the match in both directions.

**N-1645: `?` counting edges. All of them fail safe.**
- A `?` in a comment is counted, consistently with CI4 and the batch binder, so it needs a bind.
- A `?` in a `"..."` name is skipped by the check but counted by CI4, so the statement fails with `WriteFailed` in both modes.
- CI4 did not ignore extra binds before: on a mismatch it sent the statement unbound.

**N-1646: mutant survivors.**
- Test gaps: an unterminated block comment, backtick quoting, and `?` inside `"..."`.
- Equivalent: a literal emptied to a space, and a comment replaced by nothing.

## Mutants (41, both databases)

- **Killed (36).**
  - Matcher: no stripping, quoted `;` kept, a trailing `;` not stripped, empty and multiple statements allowed, the `sqlite_` quote form, no line or block comments, the parameter limit, and each of the 14 keywords removed in turn.
  - Binds: the checks removed from write, assert and hold; either direction allowed; literals counted.
  - Refs: the Ref guard removed, ids not converted in a transaction-mode hold, checks skipped in either mode.
  - Restore owner: the three mutants above.
- **Survived (5).** See N-1646.
