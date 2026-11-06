# QA gate: PR 239 stand-in and stocktake

**Verdict: PASS.** No high or medium findings.

Gated head `fc0fd021741ba70fc94c7259dd4d0f50a772dc9b`. Latest remote head is the same SHA. No movement. Base `main` `1d5e77fdc520125ea2bf3f95158ab3123b2814c1`, 0 behind, 3 commits. Diff +245/−27, 15 files. CI rollup 20/20 success. Released changelog text matches main.

## Checklist

| Check | Result |
|---|---|
| Head / base | Matches. 0 behind. 3 commits. |
| Allow-list | Direct settings writes are refused, including quoted, mixed-case, and catalog-qualified forms. Tables, unique indexes, views, and ordinary alters and drops are kept. A data-modifying CTE that writes the settings catalog is not classified (L-1809). Temporary tables are refused (N-1810). |
| Upload sweep | The idle decision is taken again under the lock. Refreshing the file there keeps the upload. A still-idle upload is still removed. The recheck does not take a second lock. Removing it fails the new test. |
| REPLACE / ALTER / newline | Both drivers: 204 tests, 964 assertions. REPLACE and ALTER are allowed. A trailing newline on a documented pragma is allowed. |
| `D` modifier | Removing it still passes that suite, including the trailing-newline case. A trailing newline is stripped before the pragma pattern, so it never reaches `$`. A newline before `=` is ordinary whitespace and the value is still read. The flag does not change the decision. |
| Stocktake | Batch starts use one conditional insert and a row-count guard. A failed guard keeps the later line insert out and names the start step. Connection mode still inserts under the per-location lock. Twenty runs, both drivers, each left one open stocktake and one line. |
| L-1806 | Closed. A nested CASE stays inside the trigger, and a parent drop after that trigger is refused. A drop after an unfinished trigger is refused as well. END inside a literal or a comment does not end the body. Two triggers then a drop stay three pieces, and the drop is refused. |
| N-1807 | Closed. `user_version` is 0, `foreign_keys` is 1, and `journal_mode`, `busy_timeout`, and `synchronous` are rows of that name with NULL. |
| N-1808 | Closed for responses. The stand-in message is the fixed sentence. The engine error is the previous exception. The production executor still has no previous exception. The development error page would print a previous message; production display of that page is off, and this stand-in is not on the request path. |
| Migrations | The 32 files still apply through the adapter on the stand-in (28 tests, 52 assertions). |
| N-1786, N-1794, N-1795 | Left as stated. Not a hold. |
| SQLite / PostgreSQL | No new product failure. |

## Findings

### L-1809 Settings write through a CTE

The new check matches a settings write only when the statement starts with the write. A statement that starts with a common-table expression and writes the settings catalog is allowed on the PostgreSQL model. Quoted, mixed-case, and catalog-qualified writes that start with the write are refused. The application does not emit the CTE form.

### N-1810 Temporary tables

`CREATE TEMP` and `CREATE TEMPORARY TABLE` do not match the allowed object pattern, so the PostgreSQL model refuses them. The migration files do not use that form on this path. The module suite did not fail on a legitimate statement.

## Closed

L-1806, N-1807, N-1808. The stand-in, sweep, REPLACE, ALTER, newline, and stocktake claims above are closed.

## Open

L-1809, N-1810. Prior N-1786 stays deferred. N-1794 and N-1795 stay separate.

## Security

The stand-in and the production executor put a fixed sentence in the exception message. The production executor does not attach the engine error. The stand-in does, as the previous exception. The development error view prints previous messages. Production turns that view off, and the stand-in is test-only. The stocktake condition binds the location. The allow-list refuses direct catalog and settings writes. The CTE gap is a model hole, not a request path. The diff adds no credentials, account identifiers, or database identifiers.

## Counts

PHP 8.4.27. PostgreSQL 16. Deploy scripts: 32 pass.

| Run | Result |
|---|---|
| D1MigrationRunnerTest | 28 tests, 52 assertions, pass |
| SqlExecutorTest, each driver | 204 tests, 964 assertions, pass |
| UnitOfWorkTest, each driver | 106 tests, 1311 assertions, pass |
| Stocktake race | 20 runs, 2 tests and 18 assertions each, both drivers, pass |
| Module, SQLite | 1120 tests, 43532 assertions, 2 failures, 14 skipped |
| Module, PostgreSQL | 1566 tests, 49142 assertions, 2 failures |
| Root, SQLite | 463 tests, 8843 assertions, 1 failure, 29 skipped, 1 incomplete |
| Root, PostgreSQL | 463 tests, 9942 assertions, 1 error, 1 failure, 1 skipped |
| Upgrade, both drivers | Green |

Module failures are the zip-entry text and the served-copy alpha check. The root failure is that module result. The PostgreSQL error is the login event name. The quoted unit-of-work matrix of 14 tests was not a separate run; the class as a whole is the count above.

Mutants, source restored: sweep recheck removed, 1 failure; conditional guard removed, 1 failure; trigger parity reverted, 1 failure; drop anchor limited to the start of the chunk, 1 failure; synthetic pragma names removed, 1 failure; driver text put back in the message, 1 failure; allow-list forced open, 9 failures and 6 errors. Removing the pragma `D` flag did not fail its suite (93 tests, 401 assertions, pass).
