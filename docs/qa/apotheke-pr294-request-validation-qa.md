# PR 294 request validation — re-gate

**Verdict: PASS** at `dfd150912926af616900278d90e40b7f459d65a0`. It is 0 behind main `4786e44d` (v3.38.0, with #299). The targeted re-gate passed at `fb17bae6` and the full gate at `778b3abb`. L-1884 is closed. No new finding. No hold. H-1849 stays closed. N-1850 stays open.

## Checklist

| Check | Result |
|---|---|
| `f8ef1493` | The script keeps #291's `PUT /backup/restore` answer of 405 and its `Allow: POST` check, and #294's signed-out `/api` 413 and 401 checks. |
| Tests | `Ci4RequestHardeningHttpTest` keeps the unknown-path 404 and adds the sign-in-on cross-site 403. `Ci4RouteMethodTest` keeps the 405 and `Allow` cases and the doubled-slash JSON 404. |
| Filters | `routemethod` runs before `posttoolarge`, `sameorigin` and `permission`. `formfields` runs after the CSRF filter. A wrong method is 405 before a 403 or a 422. |
| #293 | `ItemController` still refuses an over-long name, notes, tags and serial before it saves. The undo snapshot after a save uses #293's second argument. `SessionController` is unchanged from the pass. |
| Merge-only | `dfd15091` is a plain merge of `82431e44` and main `4786e44d` with no conflicts. Outside `CHANGELOG.md`, `docs/HANDOFF.md` and `docs/qa/`, the PR's diff against main is line-identical to its diff at `fb17bae6` against `91a0c4ae` (1617 lines). The `docs/HANDOFF.md` hunk is identical too. |
| Changelog | The 11 #294 Fixed bullets are under [Unreleased], each with the full pull-request link, unchanged from `fb17bae6`. From `## [3.38.0]` down, the file matches main, and so does the preamble. |
| README, VERSION | Both match main. VERSION is `3.38.0`. |

## Findings

None from 1885. L-1884 is closed: each request-validation bullet links to `https://github.com/thordin9/apotheke/pull/294`. Next free id is 1885.

## Security

A path with no route is 404, and a route asked with another method is 405 with `Allow`, before the origin check, the token check and the list-field check. A real form write from another `Origin` or `Referer` is 403 with sign-in on, and the token is still required when those headers are absent. A list in a single-value field, including `id`, is 422 only after those checks. A body past `post_max_size` is refused before the controller. `/media` still requires `view`. The length limits run on the submitted text before the item is written. The #293 read cache does not keep a value that failed that check.

## Tests

SQLite unit: 53 tests, 1087 assertions, pass. SQLite module: 53 tests, 1323 assertions, 1 failure. PostgreSQL unit: 53 tests, 1087 assertions, pass. PostgreSQL module: 53 tests, 1325 assertions, 1 failure. The failure is `MediaTest::testTheServedCopyStepFlattensToWhiteAndConvertsCmyk` on both. That file is unchanged since `778b3abb`. CI's `Tests (PHP 8.4, sqlite)` and `Tests (PHP 8.4, pgsql)` passed on `fb17bae6`, so the local failure comes from the review machine's image library, not the PR. PHPStan (`php scripts/ci4-arch.php di`) reported no errors. The Docker auth script was not run: no image was available.

## Verdict

PASS.
