# QA gate: PR 244 multipart marker body cap

**Verdict: PASS.** No high or medium findings. A marker that fits is readable, and one that does not is aborted at begin with the generic failure.

Gated head `c93dd52555c1d5d95da297561d1790ff1a78451b`. Latest remote head is the same SHA. No movement. Branch `fix/p4-4-marker-cap`. Base `main` `9a6d667fb3607baf75ddbcf94d7eae6b7026ed09`, 0 behind, 3 commits. Diff +34/−7, 4 files. CI 22 success and 1 skipped deploy job. The diff does not touch PHP, services, or a Worker resource file. Disk storage stays the default.

## Checklist

| Check | Result |
|---|---|
| Head / base | Matches. 0 behind. 3 commits. |
| Cap value | 3176 bytes: the all-backslash 1024-character id, an unescaped maximum object key, and 64 bytes of margin. That body itself is 3112 bytes. |
| Worst case | Not every legal key. A quote or a three-byte character in a maximum key is larger (L-1828). Begin refuses those, aborts, and returns the generic failure. |
| Begin guard | Over the cap: abort is called, nothing is stored, status 502, body `ok: false`, code `storage_failed`, fixed sentence. The same body if abort throws. |
| Read check | Size is compared before parsing. An over-cap marker is refused and its text is not read. |
| Prior behavior | Digest marker key, retry-until-success, missing-upload cleanup, and the shared dot-segment predicate are unchanged. |
| Tests | 22 Worker tests pass, including the new one. Lowering or raising the margin fails that test. Removing the read check or the begin guard does not (N-1829). |
| Scope | No resource or binding change. No readiness or restore cutover. |

## Findings

### L-1828 The cap is the escaped id plus an unescaped key

The constant matches a 1024-character id of the character JSON encodes as two bytes, a maximum-length object key of a character JSON leaves as one byte, and 64 extra bytes. Object keys may also contain a quote, which JSON encodes as two bytes, or a character that encodes as three bytes. Both are accepted by the key grammar. With a maximum-length id, those bodies are 4136 and 5160 bytes. Begin aborts the new upload and returns the generic failure. It does not store the marker.

### N-1829 The suite does not lock the two size checks

The new test asserts the constant and then begins with an id whose JSON encoding does not grow. Lowering the margin to 0 and raising it to 128 fail that equality. Removing the read-side size check, and removing the begin-time guard, leave all 22 Worker tests passing.

## Closed

L-1827. The two bodies that previously began successfully and then could not be aborted, 2088 and 2098 bytes, are under 3176. A maximum unescaped id with a short key begins, accepts a part, and aborts. A body over the cap is aborted at begin instead of being stored.

## Open

L-1828, N-1829. N-1786 stays deferred. N-1794, N-1795, L-1811, and N-1812 stay tracked and are not part of this verdict. The orphan sweep remains the handoff follow-up.

## Security

The marker name is still a digest under the fixed installation prefix. The stored id and the stored key must both match. A body over the cap is not stored, and the response does not include the failure text even when the follow-up abort throws. An already stored over-cap marker is refused before it is parsed. Selection stays on disk storage. This diff adds no credentials or account, store, or database identifiers.

## Counts

PHP suites were not run; the diff does not touch PHP. No database was started.

| Run | Result |
|---|---|
| Worker | 22 pass |
| Deploy scripts | 32 pass |
| Typecheck and wrangler 4.157.0 types check | Pass, generated types unchanged |

Mutants, source restored: margin 0 and margin 128 were killed. The read-side size check removed, and the begin guard removed, were not.
