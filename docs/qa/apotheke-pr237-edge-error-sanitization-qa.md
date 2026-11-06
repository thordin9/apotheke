# QA: PR 237 at ba3f27d88c584381b5dff0c231f1a590dde80abd

Independent gate of the pinned head. Remote base is `main` at `0c9b8b615aba9d9ac8541a984040106e3467fb98`, 0 behind, 2 commits. Remote head is the same SHA and did not move. Diff is 8 files, +183/−43. Checks: 22 success, 2 skipped (the two deploy jobs). Both database jobs are in the success set.

## Verdict

**PASS.** No H or M findings. Next free ID: 1797.

## Checklist

| Check | Result |
|---|---|
| Head / base | Pinned SHA is the remote head. Base unchanged. Two commits, none behind main. |
| CodeQL | PR check: no new alerts in the changed code, 0 annotations. The stack-trace query ran and the upload succeeded. The alert list was not readable, so alert numbers were not confirmed one by one. |
| Failure bodies | Edge and harness: boolean `ok` false, a stable code, and one fixed sentence. No message, cause, or stack field. Unbound is 503. Bad method, bad JSON, bad route, and bad shape stay on their fixed sentences. |
| Thrown errors | Nested causes, a non-Error string, number, or object, and a non-guard driver error return the generic sentence. Request SQL is not in the body. |
| Guard marker | The body is only the bare marker `isGuardFailure` accepts, plus `guard_failed`. Prefix, suffix, and a second line stay out. A marker on the fifth object in a cause chain is a generic failure and still leaks nothing. |
| PHP mapping | Marker-only maps to guard-failed. Any other failure, including the generic sentence, maps to a plain failure. The exception, its previous, and its trace do not carry handler text. |
| Success JSON | Query, batch, and exec still return boolean true with rows, results, or count. Extra fields are changes and last row id only. |
| #235 / #236 | Production success is boolean true. Redirect following stays off. Query and batch still refuse over the shared limits before any transport. `exec()` still skips those refusals (N-1786). |
| N-1793 | Closed in code. Empty parameter lists are not bound. The harness client requires boolean true and does not copy handler text. A test reads the launcher's local switch and the all-zero placeholder. |
| Logs | The thrown value is logged. A request token, account id, database id, bound value, and statement text were not added to the log. |
| Tooling | `tsc --noEmit` and `wrangler types --check` pass. No live cloud calls. |

## Closed

N-1793.

## Open

### N-1786 `exec()` skips the shared refusals

Unchanged. Deferred with the migration slice. Not a hold.

### N-1794 Local harness client still follows redirects

The production executor disables redirect following and did not use the next body. The harness client leaves the default in place: a redirect was followed and the next body was used. The statement was not resent. Not a hold.

### N-1795 Harness contract is not locked by the committed suite

Empty-list binding, boolean true, and generic harness failures match production under a direct probe. The deploy-scripts suite still passes if the harness mirror puts message and stack back, if it binds an empty list, or if the client treats a truthy ok as success. Removing the launcher's local switch does fail that suite. Not a hold.

### N-1796 Handoff backlog no longer names N-1786

The backlog line in this diff drops N-1786. `exec()` is unchanged. Not a hold.

## Security

Failure responses from the edge handler and the harness mirror are a boolean false, a stable code, and a fixed sentence. The guard sentence is only the bare marker the PHP recogniser accepts. Driver text, cause chains, stacks, and request SQL are not in the body. Details go to the log; the log call does not add a token, account id, or database id. The production executor still maps a marker-only body to guard-failed and every other failure to a generic failure, still requires boolean true, still refuses redirects, and still applies the shared limits on query and batch. The harness client matches the boolean and the generic text. It still follows redirects (N-1794). The diff has no credentials, account ids, or real database ids. No cloud resource was created.

## Counts

PHP 8.4.27. PostgreSQL 16. ImageMagick 6. Deploy-scripts: 32 pass. D1HttpExecutor: 35 tests, 49 assertions, pass. Direct probes on the unmodified tree: 35 handler cases pass; 52 client checks pass.

| Run | Result |
|---|---|
| Module, SQLite | 1086 tests, 43369 assertions, 2 failures, 14 skipped |
| Module inside root, SQLite | 1086 tests, 43365 assertions, same 2 failures, 14 skipped |
| Root, SQLite | 463 tests, 8846 assertions, 1 failure, 29 skipped, 1 incomplete |
| Module, PostgreSQL (both drivers) | 1514 tests, 48884 assertions, same 2 failures |
| Module inside root, PostgreSQL | 1514 tests, 48886 assertions, same 2 failures |
| Root, PostgreSQL | 463 tests, 9931 assertions, 1 error, 1 failure, 2 skipped |
| Upgrade rollback, both drivers | pass |

The two module failures are the zip size case and the served-copy alpha case. Neither file is in this diff. The root failure is that wrapper. The SQLite incomplete case is the connect test with no server on the default port. The PostgreSQL error is the `LOGIN` event name, which this server does not provide. Remote CI for both database jobs is green.

Mutants, sources restored: 4 killed, 3 not killed by the committed suite. Killed: message and stack put back on the edge body (3 tests); guard text echoed instead of the marker (2); cause walk removed (1); local switch removed from the launcher (1). Not killed by that suite, and failed a direct probe: harness body with message and stack (9 harness cases); truthy ok on the harness client (string and numeric ok accepted); harness bind on an empty list (1 case). D1HttpExecutor stayed 35/35 on the truthy-ok mutant.
