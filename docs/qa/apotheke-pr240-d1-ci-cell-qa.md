# QA gate: PR 240 local D1 CI cell

**Verdict: PASS.** No high or medium findings. Making the new job a required status check is a ruleset decision, not a hold.

Gated head `b15563bf0a3aae1835b0eebb1ced3810dc650026`. Latest remote head is the same SHA. No movement. Base `main` `092ed31abc53cdd9d1162a6fa883de1eaa7d23ed`, 0 behind, 3 commits. Diff +482/−61, 17 files. CI rollup 21/21 success, including `D1 (local workerd)`. No schema or `docs/qa` edits. Released changelog text matches main.

## Checklist

| Check | Result |
|---|---|
| Head / base | Matches. 0 behind. 3 commits. |
| Workflow privilege | Workflow permission is contents read. The new job does not widen it, does not set continue-on-error, and does not use `pull_request_target`. Run steps do not interpolate pull-request text. Token variables in the job are empty, and the script unsets them. No secrets context. |
| Pins | The job uses the same major action tags as the rest of this workflow, not commit SHAs. Wrangler 4.156.0 and its workerd dependency are locked with integrity hashes. `npm ci` installs that lockfile. |
| Local only | The harness script passes the local flag and a loopback bind. The config has the placeholder database id and no account id. The driver refuses any other id and any set token. Adding an account binding to the job fails the structural test. |
| Cell result | Local run of the cell script: 296 tests, 1195 assertions, pass, about 8 seconds of PHPUnit. The guard-mapping, pre-transport refusal, replay, and all-migrations tests passed on that run. |
| N-1640 | Guard failure keeps the fixed sentence and the guard code, and the failed batch leaves no guard row. Refused shapes throw before the transport (11 assertions, zero calls). Seven steps replay as one attempt plus three prefixes and surface the caller's own error. |
| L-1809 | Closed for the added cases: a settings write after a CTE, after several CTEs, and after a comment, including a quoted name. A CTE whose name is the settings relation, and whose write is an ordinary table, is allowed. |
| N-1810 | Closed. Temporary and temp tables are allowed. A temporary view is refused. A temporary trigger is not allowed. |
| Replay mutant | Reversing the search branch attributes the failure to the first insert, so the caller error is lost. That mutant is detected. Rounding the midpoint upward does not finish on this seven-step case. It is not a silent pass. |
| N-1786 | Still deferred. Migration SQL now goes through the prepared batch, which checks the limits. `exec` remains for the harness and a few drops. |
| SQLite / PostgreSQL | No new product failure. |

## Findings

### L-1811 A leading write inside a CTE hides a later settings write

The check uses the first write keyword. When that keyword is inside parentheses and is not a settings write, a later settings write outside the parentheses is allowed. The forms added in this pull request, where the settings write is the first write, are refused. A benign CTE that only shares the name is allowed. The application does not emit the hidden form.

### N-1812 The structural test reads the job, not the harness script

Adding an account binding to the job fails the test, as do the other forbidden job strings. The local-only flag lives in the harness script, which that test does not parse. The driver still rejects a non-placeholder database id and any set token before the suite runs.

## Closed

L-1809 for the cases this pull request adds. N-1810. The N-1640 cell tests above.

## Open

L-1811, N-1812. Prior N-1786 stays deferred. N-1794 and N-1795 stay separate.

## Security

The job cannot write with the workflow token, does not receive secrets, and does not run on `pull_request_target`. A fork pull request therefore cannot push or deploy through this job. The harness response for a failed write stays the generic batch or guard sentence; a real local run included the existing test that a plain failure does not return driver text. The development error page can print a previous exception, and the stand-in still attaches the engine error there, but that stand-in is not this cell's request path. The cell's own guard test checks the fixed sentence. The diff adds no credentials, account identifiers, or database identifiers.

## Counts

PHP 8.4.27. PostgreSQL 16. Edge `wrangler types --check` and `tsc --noEmit` pass on wrangler 4.156.0. Deploy scripts were not re-run; this diff does not change them.

| Run | Result |
|---|---|
| Local D1 cell | 296 tests, 1195 assertions, pass |
| Module, SQLite | 1129 tests, 43997 assertions, 2 failures, 18 skipped |
| Module, PostgreSQL | 1587 tests, 50100 assertions, 2 failures, 4 skipped |
| Root, SQLite | 464 tests, 8858 assertions, 1 failure, 29 skipped, 1 incomplete |
| Root, PostgreSQL | 464 tests, 9962 assertions, 1 error, 1 failure, 1 skipped |
| Upgrade, both drivers | Green |

The four extra SQLite skips, and the PostgreSQL skips, are the real-workerd tests, which run in the cell. Module failures are the zip-entry text and the served-copy alpha check. The root failure is that module result. The PostgreSQL error is the login event name. The root total is one higher because of the new structural test.

Mutants, source restored: job account binding added, 1 failure; search branch reversed, caller error lost; midpoint rounded up, did not finish and was stopped.
