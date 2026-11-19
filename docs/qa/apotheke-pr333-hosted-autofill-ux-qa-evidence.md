# PR #333 evidence

Gated head `22f530d0ec788eae00249932f1cfc21c6e55487c`. Live `git ls-remote` returned that SHA immediately before the verdict. The claimed `c4255a27` was the head when the code was reviewed. `22f530d0` changes only `CHANGELOG.md` and `docs/HANDOFF.md`, from pull/000 to pull/333. Main at the verdict was `34dcbce244ad40ee6b4a702d7fe22688be6226d4`, one commit ahead (`#332`). CHANGELOG preamble equals that main. The file from `## [3.53.0]` equals that main. README and VERSION match. `VERSION` is `3.53.0`.

## F1

`AutofillService::test` and `refreshInfo` request `GET` plus the base and `/v1/info`. `rg /healthz modules/Autofill` has no match. `testConnectionUsesOnlyV1Info` expects one call, `GET http://autofill.test/v1/info`.

## F2

`ResponseClassifier::retryAfter`, clock fixed at 1700000000: empty, spaces, `-1`, `abc`, `5s` and a 10-digit number are null. `0` is 0. `61` is 61. `86401` and `999999999` are 86400. An HTTP-date 30 s in the past is 0. One 15 s ahead is 15. `outcome` of `rate_limited` and `autofill_limit` uses the fixed sentences and does not contain an `<img` payload. An unknown code, `text/html`, and `not-json` are `bad_response`. A `rate_limited` body with no `Retry-After` has no "Try again" clause.

`suggest()` does not retry either 429. `testPhoto` retries only `busy` and `rate_limited`.

## Monthly unit

In `apotheke-autofill-service` `worker/src/index.ts`, `suggest` calls `RATE_LIMITER.limit` first and throws 429 `rate_limited` before `reserveMonthly` and before `budget.reserve`. `reserveMonthly` increments only when `used < hard` (`worker/src/budget.ts`). `AutofillClient::request` sends `Accept`, an optional bearer token, and `Content-Type`. No idempotency or request id. A rate-limited first call takes no monthly unit. The retry is a second POST: one unit if it is admitted, none if it is rate-limited again. A timeout after that admission is that same one unit, refunded only when the service errors before charging neurons.

## F3 and escaping

`parseWarning` keeps a string and drops a non-string. `afNote` sets `textContent` and unhides `#af-warning`. CSS is 13 px and muted. `afSay` writes a fixed SVG with `innerHTML`, then `createTextNode` for the message. `AutofillView::resultHtml` escapes with `Page::e` and does not print `warning`. The 429 message does not include `error.message`.

## Mutations

Restored after each. `git diff` empty.

- Drop `rate_limited` from the photo-test retry list: `testPhotoWaitsForAShortRateLimitThenRetriesOnce` fails.
- Drop the `rate_limited` outcome branch: that test and `testRateLimitedSuggestIsNotRetriedByTheService` fail.
- `retryHint` no longer stops at 60 s: `testMonthlyAutofillLimitIsAClearMessageAndIsNotRetried` fails.
- Drop `$wait <= RETRY_AFTER_MAX` or `$wait <= $timeout` in `testPhoto`: the eight filtered tests stay OK (66 assertions).
- `#af-warning` via `innerHTML`: those eight tests stay OK.

`AutofillTest`: OK, 37 tests, 431 assertions, both drivers where the class uses them. Playwright was not run.

CI on `22f530d0`, not waited on: CodeQL, D1, secret scan, Analyze (actions) and Analyze (javascript-typescript) pass. The other listed jobs are pending.

Scratch PostgreSQL 16, port 54351, data dir `/tmp/apotheke-pg-333.*`, was stopped with `pg_ctl -D` that dir `-w stop` and the directory was removed.

## Delta to `1013eac7`

Live head `1013eac7460e163a0b0e3b7864f7e51f88763a34` matches `ls-remote`. Main `5803b5e2` (v3.53.1) is an ancestor, 0 behind. Last PASS was `22f530d0`. New commits: `bd557910` (`tests/Modules/Autofill/AutofillTest.php` only, +62), `3ef99a39` (`tests/visual/autofill-settings.spec.ts` only, +38), and merge `1013eac7` of main.

### Verdict

PASS. L-1910 and L-1911 are closed. No new finding. Next id 1912.

### Checks

1. `git show --stat bd557910 3ef99a39` names only those two test files. The merge is the rest of the diff since `22f530d0`.
2. `APOTHEKE_CI4_TEST_PGSQL=1` `vendor/bin/phpunit -c tests/Modules/phpunit.xml.dist --no-coverage tests/Modules/Autofill/AutofillTest.php`: OK, 39 tests, 449 assertions. The two new methods use the SQLite stack; the class also ran its PostgreSQL cases.
3. L-1910. Removing `$wait <= RETRY_AFTER_MAX` fails `testPhotoDoesNotRetryARateLimitWhenRetryAfterExceedsSixtySeconds` (61 s, timeout 90 s). Removing `$wait <= $timeout` fails `testPhotoDoesNotRetryARateLimitWhenRetryAfterExceedsTheTimeout` (30 s, timeout 5 s). File restored.
4. L-1911. `npx playwright test tests/visual/autofill-settings.spec.ts -g "near-limit warning with HTML" --project=desktop-chromium`: 1 passed. The spec expects the literal string `<img src=x onerror="window.__afXss=1"><b>x</b>`, zero child elements, the same `textContent`, and `window.__afXss` unset. After `afWarn.textContent` was changed to `innerHTML`, `toHaveText` failed. `app.js` restored.
5. The new PHP cases inject the HTTP answers and the sleep callback. They do not open a socket and they do not wait 61 s or 30 s. The Playwright case fulfills `**/autofill/suggest` in the browser and starts only the local echo stub.

CI on this head, not waited on: Lint, deptrac, DI, D1, CodeQL, secret scan, conventional commits, Headless mobile, both Compose jobs, and both upgrade jobs pass. PHP sqlite and pgsql, both Docker builds, and the three visual shards are pending.

Scratch PostgreSQL 16, port 54352, data dir `/tmp/apotheke-pg-333d.*`, was stopped with `pg_ctl -D` that dir `-w stop` and the directory was removed.

### Merge read (inline)

`git merge-base --is-ancestor 22f530d0 1013eac7` holds; `git log --no-merges 22f530d0..1013eac7 ^origin/main` lists only `bd557910` and `3ef99a39`. Normalized own diff (index lines dropped, hunk headers blanked; CHANGELOG, docs/qa and the two test files excluded) of `a4b411a7..22f530d0` versus `5803b5e2..1013eac7` differs only in the `docs/HANDOFF.md` latest-release line (v3.53.0 to v3.53.1). The two test files' own diff equals that of `3ef99a39`. CHANGELOG preamble and released sections equal main; [Unreleased] holds only #333's three bullets. README and VERSION have no diff against main.
