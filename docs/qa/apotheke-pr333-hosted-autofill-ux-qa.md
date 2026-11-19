# PR #333 hosted autofill UX QA

Head `1013eac7460e163a0b0e3b7864f7e51f88763a34` (live `refs/pull/333/head` at the verdict). Full gate at `22f530d0` (the claimed `c4255a27` plus a changelog and handoff relink). Delta since then: `bd557910` and `3ef99a39` add tests only, and `1013eac7` is a plain merge of main `5803b5e2` (v3.53.1), 0 behind. Apart from those two test files, the branch's own diff equals `22f530d0`'s except the handoff latest-release line. Released changelog sections, README and VERSION match main (`3.53.1`). Not merged.

## Checklist

1. Test connection and the saved-service refresh call `GET /v1/info` only. Nothing under `modules/Autofill` requests `/healthz`. A stub failure is a fixed message (timeout, unreachable, no curl, 401/403, non-200, or a body that is not `/v1/info`).
2. HTTP 429 uses `error.code`. `autofill_limit` is the monthly sentence and is not retried. `rate_limited` is the try-again sentence. Any other code, HTML, or non-JSON is "HTTP 429" and is not retried. A missing `Retry-After` adds no hint. Digits are capped at 86400; a 10-digit or other malformed value is ignored; a negative is ignored; an HTTP-date in the past is 0 and one in the future is the remaining seconds. A hint of 1–60 s is "in N s"; longer is "later" or, for the monthly code, "next month".
3. The photo test and the item form retry `rate_limited` once only when `Retry-After` is 1–60 s and no longer than the timeout. The service does not retry. Dropping that code, or the message cap, fails a test. Dropping the photo test's 60 s check fails the new 61 s case, and dropping its timeout check fails the new 30 s against 5 s case (L-1910 closed).
4. A `rate_limited` 429 is thrown by the autofill worker before `reserveMonthly` and before the daily budget reserve. The retry is a new POST with no idempotency key. The first answer takes no monthly unit; the retry takes at most one, including when that retry succeeds, is rate-limited again, or times out after it has been admitted.
5. A string `warning` is passed through, cleaned to one line of 200 characters, and set with `textContent` on `#af-warning` (muted, not the failure style). The service's error text is not copied into the message. Settings HTML uses `Page::e`. A Playwright spec sends an HTML warning and expects it as literal text with no child elements and no script run. Switching the note to `innerHTML` fails it (L-1911 closed). A payload `<img src=x onerror=alert(1)>` stays out of the 429 message and is text in the note.

## Findings

L-1910 (closed at `bd557910`). `AutofillService::testPhoto` retried even with its `<= 60` or `<= timeout` check removed. Two new tests now fail on each removal.

L-1911 (closed at `3ef99a39`). No test failed if `#af-warning` used `innerHTML`. The new near-limit HTML spec now fails on it.

No new finding. Next id 1912.

## Security

The monthly unit is taken only after the short rate limiter allows the call. A rate-limited retry therefore cannot spend a second unit. The user-facing 429 text is written by this app, not taken from the service body. The warning is assigned as text.

## Verdict

PASS at `1013eac7`. No open finding.

CI on this head, as it stood: 16 checks pass, including Lint, deptrac, DI, D1, CodeQL, secret scan, Headless mobile, Compose, both upgrade jobs and two visual shards. PHP sqlite and pgsql, both Docker builds and visual shard 1/3 are pending.
