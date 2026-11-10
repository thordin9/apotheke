# PR 271 container sleep — QA

**Verdict: PASS** at `127ab8f85515476a21c3bda7d5515084772369b9`. Dropping `monitor()` and `waitUntil` does not remove a recovery path. The inactivity timeout is still set on a cold start and when a warm Durable Object is constructed with the container already running.

Branch `fix/container-sleep`. Base `main` `72582532c705064a985684defbadd9e6c2cedc12`. Behind 0. Ahead 2: `fa573518` and the changelog commit. Diff 5 files, +84/−18. CI at review: 7 passed, 1 skipped, 16 still running, 0 failed.

## Checklist

| Check | Result |
|---|---|
| Diff | `fa573518` changes the Worker, its tests, the deploy-script test, and one handoff sentence. The changelog commit adds one bullet under `## [Unreleased]` / `### Fixed`. |
| Recovery | The removed `monitor()` handler only logged exit and stop. The runtime `Container` type has no `onStop` or `onError`. The class keeps no started or ready flag. The next request reads `container.running` and calls `start()` when it is false. A stop during the readiness wait still ends as 503. |
| Timeout | `setInactivityTimeout` runs after `start()` and again in the constructor when the container is already running. A second request on the same start does not call it again and does not call `monitor()`. |
| Pins | Nothing in this class passes a promise to `waitUntil`. There is no alarm. The constructor uses `blockConcurrencyWhile` only while it rebinds a warm container. |
| Package | `@cloudflare/containers` is not a dependency. The binding types come from Wrangler 4.157.0. `monitor()` is a promise that lasts until the container stops. It is no longer called, so it cannot reject in this Worker. |

## Findings

No new finding. The exit and stop log lines are gone with `monitor()`. N-1786 stays deferred. N-1794, N-1795, L-1811, and N-1812 stay tracked. Next free id is 1849.

## Security

Request forwarding, the readiness host, and the forwarded headers are unchanged. A container that is not running is started again. A failed start is still the fixed 503 text.

## Tests

The cold-start, default-timeout, later-request, and warm-constructor tests passed with the forwarding tests: 7 passed. The deploy-script container test passed. No PostgreSQL run and no image rebuild.

## Verdict

PASS. `EDGE_STORAGE_READY` stays off.
