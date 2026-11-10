# PR 251 R2 binding selection — QA

**Verdict: PASS** at `66bb8d2d1b3bb6868137e1458396db992623d7d4`. R2 is selected only when both storage bindings are present. The hosted gate stays unsatisfied.

Head and base matched the claim at start and end. Branch `feat/p4-5-r2-wiring`. Base `915db8de486af34bd39ab13b52fb5840b8dd0598` (v3.27.1). Behind 0. Commits 2. Diff 6 files, +115/−13: `edge/src/index.ts`, `edge/src/r2.ts`, `edge/tests/r2.test.mjs`, `docs/HANDOFF.md`, `docs/adr/002-edge-storage.md`, `CHANGELOG.md`. CI: 22 passed, 1 skipped (the API deploy), 0 failed, 0 pending. The edge dry run passed.

## Checklist

| Check | Result |
|---|---|
| Both bindings | `STORAGE` and `DB` together select R2 and start the container with the existing PHP backend value. Internet stays off. |
| Neither | The container starts with no storage selection. A Worker string, request header, or query does not add one. That env set matches main. |
| One binding | The container is not started. The client gets 503, `Retry-After` and `Cache-Control: no-store`, and the fixed unavailable sentence. The body and headers do not name a binding or include the server log line. |
| Bridge | The internal storage handler returns the existing unbound 503 unless both bindings are present. A storage-only, database-only, or local selection does not call the bucket. |
| No bypass | `EDGE_STORAGE_READY`, a backend string, and a request header or query do not select R2 and do not skip the pair check. |
| Public route | The Worker entry only forwards to the container. Media, marker, and internal-host URLs are not served from the bucket. |
| Unchanged | Workflows, wrangler, the storage fragment, and the readiness variable are not in the diff. The multipart sweep helper is still not called. |
| Docs | The changelog and ADR match the pair check, the closed bridge, and the closed gate. |

## Findings

L-1842. The handoff calls this branch local and not pushed. The reviewed head is the open pull request.

N-1786 stays deferred. N-1794, N-1795, L-1811, and N-1812 stay tracked. Next free id is 1843.

## Security

Selection reads the two bindings and nothing from the request. A half pair is a fixed 503 on the container path and the existing unbound 503 on the bridge. Neither body carries the exception text. No new route, secret, or readiness switch is in the diff.

## Tests

Edge storage tests: 40 passed. Worker types match. An independent probe covered neither, both, each half, null versus missing, header and query, the bridge, and the public forwarder. Mutations killed: one binding treated as enough, the 503 replaced by the exception text, and the bridge guard removed. PHP suites were not run. The diff has no PHP.

## Verdict

PASS. The account lifecycle rule is not changed here, the sweep stays uncalled, and the edge catalog is still the container's own database. The hosted gate stays unsatisfied.
