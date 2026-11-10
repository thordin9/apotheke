# PR 250 fence claim and job recovery — QA

**Verdict: PASS.** L-1838 and L-1841 are closed. The hosted gate stays unsatisfied: this change does not activate the sweep, add a scheduler, create the account lifecycle rule, or turn readiness on.

Gated and latest `a283120534321afeaea9def68af0b7c8372c16ea`. Branch `cursor/p4-4-fence-job-followups-e9c8`. Base `4661e5f03c9e015da3a871250c6c196e5bd81ac3` (v3.27.0). Behind 0. Commits 2. Diff 9 files, +153/−23. Same at start and end. CI on that head: 21 passed, 0 failed, 0 pending.

## Checklist

| Check | Result |
|---|---|
| L-1838 wait | A SQLite claim blocked by an open fence transaction waited the documented 60s, then stopped with a write failure. The token stayed with the holder. No row was written for the waiter. |
| Timeout restore | After that wait, the same connection's busy timeout was 5s again. A following contended write waited 5s and failed. A new connection was also at 5s. |
| Commit order | A claim made inside an open transaction was not visible to another connection until that outer transaction committed. The raised timeout was already back to 5s inside that transaction. |
| PostgreSQL | The SQLite branch is not used. A held row was waited out and then taken. Statement and lock timeouts stayed unset. |
| Settings, undo, map | The existing fence checks still reject a stale token inside the settings import and the undo prune. A held bridge write still blocks the takeover and keeps the bytes already stored. |
| L-1841 missing upload | An expired `restore.confirm` whose upload is missing becomes failed with `Restore upload not found.` on both drivers. The result stays empty. |
| Later complete | After that failure, `complete()` does not store a result. A second recovery leaves the same row. |
| Renewed lease | A lease renewed by a checkpoint stays running, with no error, even when the upload is already missing. |
| Not a transport error | A thrown storage error and an HTTP 500 leave the job running and do not use the missing-upload sentence. An HTTP 404 does. |
| Finished worker | A completion that lands while the row is still running stores its result. Recovery leaves that result in place. |
| Scope | No Worker, route, cron, binding, readiness, or schema file is in the diff. |

## Residuals

1. A holder that still has the fence row after 60s makes the SQLite claim give up without writing. That is the cap the change documents. It is not the old 10s wait.
2. A completion that lands before recovery can still store its result. Recovery does not replace a stored result, and it does not leave a missing upload running once it has seen the 404.

## Findings

L-1838 closed. L-1841 closed. No new finding. N-1786 stays deferred. N-1794, N-1795, L-1811, and N-1812 stay tracked. Next free id is 1842.

## Security

The wait is an integer on the SQLite handle that made the claim, and it is put back in a `finally`. The job update binds the fixed sentence. A storage failure is not relabeled as a missing upload. The diff adds no route and no secret.

## Tests

Targeted fence and restore-job tests: 25 passed, both drivers. Root SQLite and PostgreSQL ran. The module suites ran inside them: SQLite 1152 tests and both-drivers 1615 tests. The only product-unrelated failures were the known zip-entry text, the ImageMagick 6 served-copy check, the module wrapper those two cause, and one PostgreSQL 16 login-event error. Upgrade and rollback passed on both drivers. DI reported no errors. Five mutations were killed: a 1s wait, a timeout left raised, a missing upload left running, a completion after that failure storing a result, and a stored result overwritten.

## Verdict

PASS. The account lifecycle rule is still not in this change, and the hosted gate stays unsatisfied.
