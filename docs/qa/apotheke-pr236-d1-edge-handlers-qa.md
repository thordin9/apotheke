# QA: PR 236 at fbb958c

Independent gate of the pinned head. The remote head is the same SHA. Base is `main` at `f0aa132920d19038b52a5c51bf248b263d38e407`, 0 behind, 2 commits. Diff is 19 files, +2326/−40. Checks: 22 success, 2 skipped (the two deploy jobs). Both database test jobs are in the success set. The head did not move during this gate.

## Verdict

**PASS.** No H or M findings. Next free ID: 1794.

## Checklist

| Check | Result |
|---|---|
| Head / base | Pinned SHA is the remote head. Base unchanged. Two commits, none behind main. |
| JSON shape | Query, batch, and exec match what the executor reads: boolean ok, rows, results with rows, and count. Extra fields are changes and last row id only. |
| Unbound DB | A missing binding returns 503, ok false, and a fixed sentence. No account id, database id, or token field. |
| Wiring | Cold start registers the internal host before the container starts. A warm constructor registers it again, and the next request registers it once more. Internet stays off. The container environment has no database binding. |
| Harness | The launcher is local-only. The remote switch defaults off and is not set. The database id is the all-zero placeholder. Lockfile resolutions are the public registry only. |
| N-1791 | Closed. An empty parent list returns false, including a name that ends in a shadow suffix. A listed parent still matches the five exact suffixes. |
| N-1792 | Closed. The restore guide says a single row over the byte cap is still sent alone. The stage loop does that. |
| N-1786 | Still deferred. `exec()` does not apply the shared refusals. No caller. Not a hold. |
| Sanitization | Failure text, including a suffixed guard and a cause string, is not copied into the exception, its previous, or its trace. A string or numeric ok fails closed. Redirect following stays off. Userinfo in the base is rejected. |
| Leakage | Handler responses omit stack frames, extra error fields, region, and colo. The handler does not log the error body. A start failure returns a fixed sentence. |

## Closed

N-1791, N-1792.

## Open

### N-1786 `exec()` skips the shared refusals

Unchanged. Deferred with the migration slice. Not a hold.

### N-1793 Local harness diverges from the production contract

The Worker skips bind when the parameter list is empty. The harness mirror always binds. The harness client treats any truthy ok as success and puts the handler error string in the exception. Production still requires a boolean true and generic text. No test reads the launcher. The launcher passes the local switch, and the tool's remote switch defaults off. Not a hold.

## Security

The container still has no Cloudflare token and no database binding, and internet access stays off. The unbound response is a fixed sentence with ok false. Failure text stays on the internal hop so a guard can be recognised, and the executor still drops it before the exception. A non-boolean success flag fails closed. Redirect following stays off. Bounds and refusals on query and batch are unchanged. The diff has no credentials, account ids, or real database ids.

## Counts

PHP 8.4.27. PostgreSQL 17. Targeted executor and dump tests, both drivers: 36 tests, 68 assertions, pass. Disposable handler probe: 40 checks, pass. PHP shape and sanitization probe: pass, including one oversized row sent alone.

| Run | Result |
|---|---|
| Module, SQLite | 1513 tests, 48896 assertions, 2 failures |
| Module inside root, SQLite | 1513 tests, 48894 assertions, same 2 failures |
| Root, SQLite | 463 tests, 9913 assertions, 1 failure, 6 skipped |
| Module, PostgreSQL | 1513 tests, 48892 assertions, same 2 failures |
| Module inside root, PostgreSQL | 1513 tests, 48889 assertions, same 2 failures |
| Root, PostgreSQL | 463 tests, 9944 assertions, 1 failure, 2 skipped |
| Upgrade rollback, both drivers | pass |

The two module failures are the zip size case and the served-copy alpha case. Neither file is in this diff. The root failure is that wrapper. Remote CI for both database jobs is green. Edge `tsc --noEmit` and `wrangler types --check` pass. Deploy-scripts tests: 29 pass, including the handler and intercept cases.

Mutants: 8 killed, 2 not killed. Killed: empty list forced true; the old suffix-guess branch; generic worker failure text; unbound body change; intercept host change; database text copied into the exception; redirect following on; string ok. Not killed: deleting the empty-list return, which stays false because the parent map is empty; removing the local switch from the launcher, which no test reads and which does not select remote mode. Sources restored.
