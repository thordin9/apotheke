# PR 248 restore jobs — re-gate

**Verdict: PASS.** **M-1839 is closed.** An expired restore job is no longer left failed with an empty result when the worker finishes or the upload already says restored. The job the client was given can receive that outcome. **N-1840 is closed.** One low finding remains.

Gated and latest `e3467009fec437e1e4f1aeef28854d5abf28a6a3`. Previous HOLD `3b0b2712e93dcebdda8337bc1d92c744b0d99242`. Branch `cursor/p4-4-restore-job-e9c8`. Base `92f098381d25cdd10803010080f94ac1127e8fd6`. Behind 0. Commits 6. Diff 23 files, +1276/−53. Same at start and end. CI run 42338862511 succeeded on this head, including both test drivers and both upgrade jobs. The pull-request rollup is 21 passed, 0 failed, 0 pending. The deploy workflow did not run; this diff matches none of its path filters.

## Checklist

| Check | Result |
|---|---|
| Restored manifest | On both drivers, recovery stored done and the manifest result. A later completion with a different result did not replace it. |
| Ready and free | The same job returned to queued. The old claim could not complete. |
| Applying and free | The manifest became interrupted and the job failed with that text. The old claim could not complete. |
| Lock still held | A renewed claim stayed running. Recovery did not take the upload lock away from it. |
| Before apply | The lease check runs inside the upload lock, before status becomes applying. A requeued claim does not start the restore. |
| Enqueue | Two concurrent confirms inserted one row and returned the same id, on both drivers. |
| HTTP 202 | A live request with the batch executor returned 202, the job location, and one job. After the worker, that job was done and a later confirm did not restore again. |
| Missing upload | An expired running job whose upload is gone stays running. The old claim can still store a result. L-1841. |
| Migration 0033 | CI4-only index on `jobs`, registered with no new table. Oldest active duplicate is kept. Done and other job types are left. A second active insert is refused. Upgrade and rollback passed on both drivers. |
| Scope | The settings flag is taken when the job is queued. The worker uses that flag. PDO, the page, and the CLI stay in the request. The fence files are unchanged. L-1838 stays tracked. No readiness change. |

## Findings

**M-1839 (closed).** A claim no longer fails an expired `restore.confirm` row the way it fails other jobs. Completion can also replace an expiry failure with the result the worker finished. Recovery copies a restored manifest onto the job and does not run the restore again.

**N-1840 (closed).** The expired-claim test now requires the finished result to remain. The false empty failure is not what that test accepts.

**L-1841 (new).** Recovery treats a missing upload as leave-the-job. An expired running row with no manifest stays running and is not claimed again. A dead worker in that state does not become terminal by itself.

**Tracked.** N-1786 stays deferred. N-1794, N-1795, L-1811, N-1812, and L-1838 stay tracked.

## Security

The job view is id, type, status, progress, result, and error. It has no payload. Confirm requires backup. The job read requires backup and the starting user or an admin; anyone else gets the same not-found as a missing id. API writes stay on the API CSRF filter. The owner key must match the recorded form. The diff has no account, bucket, or host secret.

## Tests

HTTP batch confirm: 1 test, 23 assertions, passed. Restore-job suite, both drivers: 17 tests, 116 assertions, passed, including the bridge, the index, and the dead-claim takeover. Recovery and concurrent enqueue probes on both drivers matched the checklist. Root SQLite: 585 tests, 1 failure. Root PostgreSQL: 586 tests, 1 failure. Nested modules: 1142 and 1611, 2 failures each. Those are the known zip-entry text, the ImageMagick 6 alpha case, and the module wrapper. Worker tests 57 passed. Types match. Upgrade and rollback passed on both drivers. A live kill during an in-progress restore was not performed.

## Verdict

PASS. Next free id is 1842.
