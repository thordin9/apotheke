# QA gate: PR 242 Worker R2 operations (third re-check)

**Verdict: PASS.** No high or medium findings. The marker key is a digest, retries keep the marker until the final call succeeds, and listing uses the same dot-segment rule as addressing.

Gated head `38be9cb29332f928b2748627fab3787fafe0f5f0`. Latest remote head is the same SHA. No movement. Base `main` `43a58ef00fa184802f24e823fe9f0ea33fce8230`, 0 behind, 17 commits (one is the merge of that main tip). Diff versus main +1616/−96, 18 files. CI 23 success and 2 skipped deploy jobs. The merged main tip is the Wrangler 4.157.0 type regeneration. Generated types at this head match that tip. No conflict markers. No consumer cutover, readiness change, or Worker resource file.

## Checklist

| Check | Result |
|---|---|
| Head / base | Matches. 0 behind. 17 commits. |
| Opt-in | Disk storage remains the default. Services still construct it. |
| Marker key | Digest name, 64 hex digits. A 1024-character id produces a 91-byte key, under the 1024-byte service limit. |
| Binding | The marker stores the id and the full key, and both must match. Another key is refused before resume. The digest is stored under this installation's prefix. |
| Marker write failure | A rejected or thrown marker write aborts the new upload. If that abort also throws, the response is still the generic failure. |
| Retry | A transient complete or abort leaves the marker. The same call succeeds afterward and then removes it. A later call is refused. |
| Missing upload | Code 10024, or a message ending in that code, removes the marker and still returns the generic failure. |
| Sweep | Still a handoff follow-up. No lifecycle rule or resource was added. |
| Listing | Dot-prefixed segments are omitted. An empty segment still fails the page. The bridge list check now uses the same predicate as addressing. |
| Part bound and ceiling | 5 MiB part cap and the 1–104857600 write ceiling are unchanged. |
| Transport | Redirects stay off. Peer and host verification stay on. |
| Config | No Worker resource or binding file is in the diff versus main. |

## Findings

### L-1827 The marker read cap is below the largest legal body

The marker key fits. The body does not always. A read rejects a marker larger than 2048 bytes, while begin will store one. A 1024-character id of a character that JSON escapes is 2098 bytes with a short object key. A 1024-character id with a 1024-character object key is 2088 bytes. Begin returns success. Later part and abort are refused and do not call abort. Raising the cap leaves the Worker suite green. The 1024-character id covered by the new test stays under the cap.

## Closed

M-1824. The marker name is a digest, so id length no longer affects the key length. The bucket double rejects a key over 1024 bytes. A thrown or rejected marker write aborts the new upload.

N-1825. The marker is removed only after complete or abort succeeds, or when the service reports that the upload is gone. A failed final call can be repeated. Complete after abort is refused. The remaining sweep is the handoff line, not a code gap in this change.

L-1826. Addressing and listing both reject a segment that starts with a dot, including `.`, `..`, and the reserved marker segment. Encoded dot segments are rejected on the request path. Loosening the predicate fails the Worker suite.

## Open

L-1827. N-1786 stays deferred. N-1794, N-1795, L-1811, and N-1812 stay tracked and are not part of this verdict.

## Security

The installation prefix is fixed in the handler. The marker lives under it and names a digest, not the raw id. The stored id and the stored key must both match the request, so one id cannot be completed or aborted for a different key or another installation prefix. A failed marker write does not return the service text, including when the follow-up abort fails. Missing uploads and validation failures use the same generic body. Selection stays on disk storage unless the backend is exactly `r2`. The diff contains no credentials or account, store, or database identifiers.

## Counts

PHP 8.4.27. Scratch PostgreSQL 16 stopped and its data directory removed. Distro cluster left down.

| Run | Result |
|---|---|
| Storage, transport, factory | 87 tests, 319 assertions, pass |
| Worker | 21 pass |
| Deploy scripts | 32 pass |
| Typecheck and wrangler 4.157.0 types check | Pass, generated types unchanged |
| Root, both drivers | 558 tests; SQLite 9912 assertions, PostgreSQL 9955; 1 failure each |
| Module, SQLite | 1129 tests, 44010 assertions, 2 failures, 18 skipped |
| Module, PostgreSQL | 1587 tests, 50117 assertions, 2 failures, 4 skipped |
| Upgrade, both drivers | All steps equal |

The root failure is the module wrapper. The module failures are the known zip-entry text and served-copy alpha check. No login-event, sudo, or busy-database failure.

Mutants, source restored: raw id as the marker key, marker-write failure without abort, delete before the final call, and a loosened dot-segment predicate were killed. Raising the marker body cap was not.
