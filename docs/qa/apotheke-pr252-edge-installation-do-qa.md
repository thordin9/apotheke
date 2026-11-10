# PR 252 Installation Durable Object — QA

**Verdict: PASS** at `c18a04257fe63ecb54c7d6dba00dbc8b2cce0945`. The database in storage mode is the Installation object's SQLite. The hosted gate stays unsatisfied.

Head and base matched the claim at start and end. Branch `feat/p4-5-installation-do`. Base `f16307b74bd237096155b79e953daadbd2d7f51f` (v3.28.0). Behind 0. Commits 2. Diff 11 files, +339/−36. `edge/wrangler.jsonc` is not in the diff. The `v2` class list is the existing fragment `edge/storage.jsonc`, also unchanged. CI at the end: 21 passed, 1 skipped (the API deploy), 1 failed. The edge dry run passed.

## PostgreSQL CI

Flaky, not caused by this diff. Attempt 1 failed `Ci4ConnectFailureLeakTest::testCliAndHttpFailuresShowNoValues`: the client recorded status 0 while the development server had accepted the request. The rerun did not fail that test. It failed one other assertion, `StagedRestoreTest` preflight on the batch path, which passed on attempt 1. Neither file is in the diff. Recent main runs of this workflow are green. Local repeats of the leak test passed 5 times on this head with PostgreSQL, 3 times with the SQLite driver, and 3 times on the base. The image change is a build-time module check in the FrankenPHP Dockerfile. The PHPUnit job does not build that image.

## Checklist

| Check | Result |
|---|---|
| Class export | `Installation` is exported from the Worker entry. The fragment's `v2` migration already names it. Wrangler stays on `v1` only. |
| Bridge shape | `/query`, `/batch` and `/exec` keep the same JSON keys. Each call uses one `transactionSync`. A failed batch rolls back. |
| Three bindings | All six partial mixes return 503 with `Retry-After`, `Cache-Control: no-store`, and the fixed sentence. The container does not start. Neither bridge falls back. |
| No bypass | A readiness string, a backend string, a header, and a query do not select storage mode. |
| Object gate | The object returns the unbound response outside storage mode and does not touch its SQLite. |
| Identity | The id helper uses jurisdiction `eu` and the fixed name `primary`. The request is not an input. |
| Public entry | Internal and media URLs are forwarded to the container object only. |
| Curl check | The FrankenPHP image asserts the curl module. Removing that line fails its unit test. The non-edge Dockerfile is unchanged. |
| L-1842 | The old "not pushed" sentence for piece 1 is gone. Piece 1 is described as merged, which matches v3.28.0. |
| Unchanged gate | Workflows, wrangler, the readiness variable, and the sweep helper are untouched. The sweep is still not called. PHP is not pointed at the bridge. |

## Findings

L-1842 closed. L-1843: the handoff says there is no open pull request and that this branch is only local. This head is the open pull request.

The guard's `changes()` was run on local workerd inside `transactionSync`. A missed update fails the guard and leaves the row, a matching update reports one change, and a later error rolls the update back. No finding.

N-1786 stays deferred. N-1794, N-1795, L-1811, and N-1812 stay tracked. Next free id is 1844.

## Security

Selection reads the three bindings, not the request. The container 503 does not include the server log or a binding name. The object's id is a constant name under the EU namespace. No new public route and no readiness switch.

## Tests

Edge suite: 51 passed. Types match. The worker guard accepts the unchanged wrangler config. Dockerfile tests: 11 passed. Mutations killed: one binding treated as enough, the object gate removed, a batch outside `transactionSync`, the 503 replaced by the exception text, and the curl line removed. Full PHP suites were not re-run. The diff does not change PHP behavior; the image check is a unit-tested line.

## Verdict

PASS. Storage mode still does not point PHP at this database, and the sweep stays uncalled. The hosted gate stays unsatisfied.
