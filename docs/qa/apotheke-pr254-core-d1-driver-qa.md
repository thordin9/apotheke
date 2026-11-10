# PR 254 D1 database driver — re-gate

**Verdict: PASS** at `beaf1467828e0c17b806898d42c09c847e0f8752`. The merge of main `4dd89b73` into `ccbbfded` adds no unrelated code. M-1844 stays closed. The driver is still not selected.

Branch `feat/p4-5-db-driver`. Base `main` at `4dd89b73ad82c9ee4d872b5ef5cf0a48e07deab3`. Behind 0. Ahead 5. Parents of the merge: `ccbbfdedd114dd89348b58ef802f1386c7c2424b` and `4dd89b73ad82c9ee4d872b5ef5cf0a48e07deab3`. Three-dot diff versus main, outside `CHANGELOG.md`, is byte-identical to `ccbbfded`'s. That diff is the driver work, 16 files, +1169/−31. CI at review: 13 passed, 1 skipped, 9 still running, 0 failed.

## Checklist

| Check | Result |
|---|---|
| Merge | The commit is only `Merge main into feat/p4-5-db-driver`. Against `ccbbfded` it is the two main commits (#255 Node 22 for the bridge tests, #253 edge dry-run) plus the changelog. Both changelog sides are kept: the driver bullet and the maintenance bullet. |
| Same driver diff | Every other file in the pull request diff matches `ccbbfded`. No driver line moved. |
| M-1844 | Unchanged from the `ccbbfded` gate. A commented write is still a write, and it stays queued inside a transaction. |
| L-1845 | Unchanged. The handoff points at the open pull requests. |
| Not selected | No config or DSN path uses the driver. |

## Findings

No new finding. M-1844 and L-1845 stay closed. N-1786 stays deferred. N-1794, N-1795, L-1811, and N-1812 stay tracked. Next free id is 1848.

## Security

The merge does not change classification, the host pin, or the bridge failure text. A comment still cannot turn a write into an immediate query. The driver is still not on the default connection.

## Tests

Re-ran on this head only: `D1DriverTest`, `D1DriverBridgeTest`, `D1HttpExecutorTest`, `D1IntegrationTest`, and `DatabaseStateTest`: 86 tests, 696 assertions, 3 skipped. The skips are the workerd integration tests (`TEST_D1_URL` unset). The CI prove filter `D1DriverBridgeTest` with `--fail-on-skipped` passed: 5 tests, 53 assertions. `DeployWorkflowsTest`: 27 tests, 387 assertions. `scripts/deploy-scripts.test.mjs`: 33 passed, 0 failed. The earlier `ccbbfded` mutations of `leading()` and the `WITH` walk still stand; this merge does not touch that code.

## Verdict

PASS. Nothing selects the driver, and the hosted gate stays unsatisfied. GitHub CI had not finished: the two PHP suites, the three visual shards, and the four Docker jobs were still running, with no failure.
