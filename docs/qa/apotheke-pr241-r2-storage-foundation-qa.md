# QA gate: PR 241 R2 storage foundation

**Verdict: PASS.** No high or medium findings.

Gated head `e140d365e68a375e24d64b848d37293c05e05c7d`. Latest remote head is the same SHA. No movement. Base `main` `0c27558bbddd13dddec9ad4cf44ea3ccc9c6a903`, 0 behind, 9 commits. Diff +762/−8, 6 files, 378 of those lines outside tests. CI rollup 21/21 success. No service wiring, migrations, Worker edits, or `docs/qa` edits. `LocalStorage` and its construction sites are unchanged.

## Checklist

| Check | Result |
|---|---|
| Head / base | Matches. 0 behind. 9 commits. |
| Wiring | `R2Storage` is not constructed by services. Disk storage remains the default. |
| Keys | Empty, NUL, controls, backslash, leading slash, `?`, `#`, empty segments, `.`, and `..` are rejected before any request. A leading slash is also an empty segment, so removing only that check still fails the suite. |
| Encoded and look-alike forms | A key whose text is percent-encoded dot-dot is one literal segment and is encoded again on the path. Look-alike dots are not treated as dot segments. This client applies no installation prefix; the hand-off leaves that to the Worker. |
| Target | The bridge accepts an origin-only HTTP URL. Userinfo, a path, a query, and a fragment are rejected. The key is appended as an encoded path, not a host. |
| Redirects and success | The native client does not follow redirects. A non-2xx status fails. `ok` must be boolean true. Missing objects are a 404 or `found: false`. |
| Bounds | A response longer than 64 MiB fails. The native reader stops during the body; the suite kills the check on the returned body. A streamed write copies the whole source into a temporary stream, with RAM above 2 MiB on disk, and closes that stream on failure. There is no write-size ceiling (L-1813). |
| Failures | The exception message is fixed. Transport text is not the message or the previous exception. No authorization header is set or logged. |
| Contract | Invalid keys read as missing and throw on write, as disk storage does. Delete of a missing key is success. Writes replace. The bridge sends one binary content type. |
| Mocks | The 51 tests call `R2Storage` and `R2Bridge` with an injected transport, so validation, status, and JSON checks run. The native redirect flag and the native body callback are not what those tests kill (N-1814). A separate native probe saw a redirect and an oversized body fail closed. |

## Findings

### L-1813 Streamed writes have no size ceiling

`writeStream` copies the entire source before the upload. Memory above 2 MiB spills to a temporary stream, and that stream is closed if the copy or the request fails. Reads stop at 64 MiB. A streamed write does not. The class is not selected by the application.

### N-1814 Native HTTP edges are outside the suite

Turning redirects on, or removing the native body-size callback, leaves the 51 tests passing. Those tests inject the response. On the native client, a redirect was not followed and an oversized body failed with the fixed sentence and no previous exception.

## Closed

None from earlier numbers. L-1811, N-1812, N-1786, N-1794, and N-1795 were not part of this change.

## Open

L-1813, N-1814. The earlier items above stay tracked and are not a hold.

## Security

Keys that would leave a relative path are rejected before the request. The key cannot change the scheme, host, userinfo, or query. The native client does not follow redirects and does not send a credential header. Failures use one fixed sentence and do not chain the transport error. A too-large response is rejected rather than returned. Prefix and tenant separation are not enforced in this client; the hand-off assigns them to a later Worker, and this pull request does not add one. The diff adds no credentials, account identifiers, or database identifiers.

## Counts

PHP 8.4.27. PostgreSQL 16. Deploy scripts were not run; this diff does not touch them.

| Run | Result |
|---|---|
| R2StorageTest | 51 tests, 187 assertions, pass |
| Module, SQLite | 1129 tests, 43996 assertions, 2 failures, 18 skipped |
| Module, PostgreSQL | 1587 tests, 50108 assertions, 2 failures, 4 skipped |
| Root, SQLite | 515 tests, 9044 assertions, 2 failures, 7 warnings, 29 skipped, 1 incomplete |
| Root, PostgreSQL | 515 tests, 10140 assertions, 1 error, 2 failures, 7 warnings, 1 skipped |
| Upgrade, both drivers | Green |

Root is 515, two above the quoted 513. Module SQLite is 1129. Failures are the zip-entry text, the served-copy alpha check, the module wrapper, the login event name, and the local sudo denial with its seven warnings.

Mutants, source restored: dot-segment check removed, 2 errors and 1 failure; response-length check removed, 1 failure; fixed failure sentence changed, 17 failures; leading-slash check removed, still 51/187; redirect following turned on, still 51/187; native body callback removed, still 51/187.
