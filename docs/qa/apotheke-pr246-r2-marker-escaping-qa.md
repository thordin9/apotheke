# QA gate: PR 246 escaped marker body cap

**Verdict: PASS.** No high or medium findings. The cap is above every marker the current id and key rules can produce, and the read check rejects a larger stored body before parsing.

Gated head `10ac5f71a0e67788cc774e2038619d6c153f2152`. Latest remote head is the same SHA. No movement. Branch `fix/p4-4-escaped-marker-limit`. Base is current `main` `622fb84d51fa767d36d22fd6ade7d193387ce06d`, 0 behind, 2 commits. The earlier main tip named in the request is an ancestor. Diff +37/−17, 2 files, both under the Worker tests and handler. CI 22 success and 1 skipped deploy job. The diff does not touch PHP or a resource file.

## Checklist

| Check | Result |
|---|---|
| Head / base | Matches. 0 behind current main. 2 commits. |
| Cap | 12325 bytes: six bytes for each of 1024 id units and 1024 key units, plus the 22-byte prefix, plus 15 bytes of syntax. |
| Actual maximum | 5160 bytes. The id is ASCII, so its worst encoding is two bytes. The key's worst encoding under the current grammar is three bytes per unit. The object wrapper is 18 bytes. All of that fits. |
| Quotes | A maximum quoted key with a maximum escaped id is 4136 bytes and is exercised by a real begin, part, and abort. |
| UTF-8 | A key whose full prefixed form is exactly 1024 bytes also begins and aborts. The code-unit limit can be larger in bytes and still fits the cap. |
| Begin guard | No admitted id and key reach it. Removing it leaves the suite green. That limitation is accepted. |
| Read check | An over-cap stored marker is refused before its text is read. Removing the check fails the new test. |
| Prior behavior | Digest names, abort after a failed marker write, retry until the final call succeeds, missing-upload cleanup, and the dot-segment rule are unchanged. |

## Findings

No new findings.

## Closed

L-1828. The previous cap rejected some legal keys. This cap accepts the measured maximum, including a quoted key and a three-byte key.

N-1829. Lowering the cap below the escaped request, and removing the read-side check, fail the new test. The begin-time guard stays defence in depth: valid inputs cannot reach it, so deleting it is not observable. That part of the earlier note is accepted, not left open.

## Open

None from this change. N-1786 stays deferred. N-1794, N-1795, L-1811, and N-1812 stay tracked.

## Security

The marker name remains a digest under the fixed prefix. The stored id and key must still match. A body over the cap is not parsed and is not returned. Begin of an admitted pair stores the marker. Selection and resource files are unchanged. This diff adds no credentials or account, store, or database identifiers.

## Counts

No PHP suites and no database. The diff does not touch PHP.

| Run | Result |
|---|---|
| Worker | 22 pass |
| Deploy scripts | 32 pass |
| Typecheck and wrangler 4.157.0 types check | Pass, generated types unchanged |

Mutants, source restored: cap set to 4000 was killed. The read-side check removed was killed. The begin guard removed was not. A cap of 20000 was not, and was not required to be.
