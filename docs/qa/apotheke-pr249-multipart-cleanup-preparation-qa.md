# PR 249 multipart cleanup preparation — QA

**Verdict: PASS.** The change is an uncalled Worker helper. It aborts a marked unfinished upload after one day, then deletes that marker. It does not schedule itself, and it does not satisfy the hosted gate.

Gated and latest `449cb771e2e801602d74587d628f7232b5b61645`. Branch `cursor/p4-4-orphan-sweep-e9c8`. Base `b2b990f593d0173000ed4776dd0775937563cb74` (v3.26.0). Behind 0. Commits 2. Diff 6 files, +392/−12. Same at start and end. CI on that head: 22 passed, 1 skipped (the API deploy), 0 failed, 0 pending. The edge dry run passed.

## Checklist

| Check | Result |
|---|---|
| Not activated | No request handler and no cron call the helper. A sweep action on the storage route is refused. Wrangler has no cron. |
| Eligibility | Age is the marker object's uploaded time. Parts do not refresh it. Exactly one day is eligible. One millisecond younger is kept. A future or missing time is kept. A non-finite clock keeps every marker. |
| Still active | A marker older than one day is eligible even when the upload still has a session. That is the written contract. |
| Valid marker | Abort runs, then the marker is deleted. The completed object is left in place. |
| Already gone | Code 10024, or a message ending in that code, deletes the marker and does not treat the call as a hard failure. |
| Other abort errors | The marker stays. The returned cursor is the page that was passed in, so the page can be retried. |
| Malformed | Bad JSON, the wrong key order, a foreign object key, or an oversized body is deleted and not aborted. |
| Unmarked | An upload with no marker is not listed and is not aborted. |
| Pages | One call reads one page. The default is 50 and the cap is 100. A page of younger markers returns a cursor; the next call reaches an older marker behind them. |
| Prefix | The list prefix is this install's marker prefix. A key outside it is not examined. |
| Docs | The changelog, handoff, ADR, and operations note say the helper is not scheduled, deleting a marker does not abort, unmarked uploads need an account lifecycle rule, and readiness stays off. |
| PHP | This diff does not change PHP or schema. Those suites were not run. |

## Findings

No new finding. L-1838 and L-1841 stay tracked. N-1786 stays deferred. N-1794, N-1795, L-1811, and N-1812 stay tracked.

## Security

The helper takes a bucket binding and a clock. It does not take a client path. Abort uses the id and key read from a marker whose name is the digest of that id, and whose key is under this install. Failures stay inside the helper's result. The storage route's errors stay the fixed sentence. The diff has no account, bucket, or host secret.

## Tests

Worker storage tests: 35 passed. Deploy script tests: 32 passed. Edge types match. Mutations killed: the one-day boundary made inclusive, delete before abort, every abort error treated as retryable, and the prefix check removed. Independent probes matched the checklist on both a failed abort and a following retry. No live bucket was written.

## Verdict

PASS. The account lifecycle rule for unmarked incomplete uploads is not in this change. The hosted gate stays unsatisfied. Next free id is 1842.
