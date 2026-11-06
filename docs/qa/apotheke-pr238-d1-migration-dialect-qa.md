# QA re-check: PR 238 at 466343c

Prior hold was `67f7dc24c319016221151681ea05a45be94247ea`. This head is `466343c396d5585bb11ba6a69793d51fc9a9c049`. Remote head matches. No movement during the gate. Base `main` `8e9fb9908bf83eb7c7819ecf02d3165062823bf3` (v3.20.1), 0 behind, 5 commits. Diff +1175/−7, 13 files. CI rollup 20/20 success.

## Verdict

**PASS.** No high or medium findings. The hold items are closed. One low residual remains on trigger-body splitting.

## Checklist

| Check | Result |
|---|---|
| Head / base | Remote head is the gated SHA. Base is current main. 0 behind. |
| Merge | Parents are the fix commit and v3.20.1. Conflicts were the changelog and the two hand-off files. No conflict markers remain. Released changelog text matches main byte for byte. The hand-off keeps both the migration note and the merged edge note, and names N-1786 as open and deferred. Diff against main is only this pull request. |
| M-1800 | Closed. `D1Result::getIterator` returns an array iterator. Foreach works. Removing it brings back the uninitialized-statement error on 0028, the foreach test, and the FTS test (3 errors). |
| L-1801 | Closed. The runner loads configuration the same way as the PDO runner. Removing that call fails the real migration run at 0013. |
| L-1802 | Closed for the forms named in the hold. Quoted, schema-qualified, and comment-prefixed `user_version` are skipped. `IF EXISTS`, quoted, schema-qualified, and `temp.` names are refused on exec, query, and prepare. Words inside comments and literals are not. A mixed exec string drops the skipped pragmas and runs the rest. `VACUUM INTO` with a semicolon in the quoted path is skipped. `user_version` reads 0. `foreign_keys` reads 1. |
| L-1803 | Closed. The end-to-end test runs every file under `migrations/` through the adapter on the stand-in: 32 recorded, rerun empty. 24 tests, 38 assertions. |
| N-1805 | Closed. `WriteFailed` extends `PDOException`. 0019's fallback runs when FTS is rejected. Executor failure text stays the fixed sentence; the query wrapper's previous exception is that same sentence. The production `PDOException` catches sit on real driver calls, not on the write layer. SQLite and PostgreSQL suites show no new product failure. |
| N-1786 | Still deferred. `exec` still skips the shared refusals. Callers are this migration channel. Not a hold. |
| Stand-in | The end-to-end test goes through `D1Pdo`. The skip and drop tests use a recording fake, so a stand-in that accepts `user_version` cannot hide a skip failure. The stand-in would re-split a trigger body; no migration has a trigger. |
| SQLite / PostgreSQL | No new product failure. Local env limits are under Counts. |

## Findings

### L-1806 Trigger-body scan

A simple trigger body stays one piece, and a parent drop after a finished trigger is refused. The scan treats the first `END` as the end of the body, so an `END` earlier in the body splits it. A trigger that never ends, when `BEGIN` appears later in the same string, keeps the rest in one chunk, and the parent-drop anchor does not see a later drop. That chunk is forwarded. No migration file contains a trigger.

### N-1807 Other skipped reads

A skipped `foreign_keys` read returns 1, which matches an engine that keeps foreign keys on. A skipped `journal_mode` or `busy_timeout` read returns a `user_version` 0 row. Migrations do not read those two.

### N-1808 Stand-in failure text

The stand-in's SQLite failure path appends the driver message to `WriteFailed`. The production executor does not. The passing FTS test uses the fixed rejection message.

## Closed

M-1800, L-1801, L-1802, L-1803, N-1805. N-1796 is done: the hand-off names N-1786 as open and deferred. N-1794 and N-1795 were not treated as gaps.

## Open

L-1806, N-1807, N-1808. Prior N-1786 stays deferred and is not a hold.

## Security

Failure text from `exec` and `query` is not copied into the exception. On `query` and `prepare`, the previous exception is the sanitized `WriteFailed`. A non-boolean success flag still fails closed. `foreign_keys` OFF is not sent when it is the whole statement, and a read answers 1. The drop guard covers the unquoted, quoted, schema-qualified, and `IF EXISTS` forms, including on `prepare` and `query`. The trigger-body residual can forward a later drop inside an unfinished trigger chunk. Migration SQL is repository code, not request input. `exec` still skips the shared refusals only on this channel. The diff adds no credentials, account identifiers, or database identifiers.

## Counts

PHP 8.4.27. PostgreSQL 16. Measured additions include the tokenizer (203) and the test file (262), which together are 465 lines, and the stand-in (124).

| Run | Result |
|---|---|
| D1MigrationRunnerTest | 24 tests, 38 assertions, pass |
| Module, SQLite | 1110 tests, 43495 assertions, 2 failures, 14 skipped |
| Module, PostgreSQL | 1538 tests, 48982 assertions, 3 failures in parallel; the race passes alone |
| Root, SQLite | 463 tests, 8825 assertions, 2 failures, 7 warnings, 29 skipped, 1 incomplete |
| Root, PostgreSQL | 463 tests, 9927 assertions, 1 error, 2 failures, 7 warnings, 1 skipped |
| Upgrade, both drivers | Green |

The module totals are one above the quoted 1109 and 1537. That test arrived with v3.20.1. Stable module failures are the zip-entry text and the served-copy alpha check. The extra PostgreSQL failure was a busy response under parallel load; alone it is 4 tests, 224 assertions, pass, and the root suite's module rerun did not fail it. The root wrapper failure is the module result. The PostgreSQL error is the login event name. The other root failure and seven warnings are the local `sudo` denial. Upgrade was green on both drivers with the usual file-mode mask.

Mutants, source restored: `getIterator` removed, 3 errors; config load removed, 2 errors; `IF EXISTS` removed from the anchor, 1 failure; identifier limited to a bare word, 7 failures and 1 error; trigger `END` check off, 1 failure; `foreign_keys` answer forced to 0, 1 failure.
