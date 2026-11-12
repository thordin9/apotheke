# PR 294 request validation — re-gate evidence

No commit and no push. Head `fb17bae6baaf56be0acba8a9d0c5589d7b5f177c`. Main `91a0c4ae791a98bd25b73f41000a6c125c5635fc`. `git rev-list --left-right --count main...HEAD` is `0 25`. Previous pass `778b3abb`.

## Conflict resolution

`f8ef1493` parents: `7e7db90a` and `3be6990d`. #291 on main is `454f9abb`.

`scripts/docker-auth-checks.sh`: the result keeps `anonymous PUT /backup/restore is the app's` at 405 and `anonymous PUT /backup/restore Allow` equal to `POST`, and adds the six anonymous `/api` checks (70 KB 413, 30 MB 413, chunked 401, each with `application/problem+json`). `git diff 7e7db90a f8ef1493` for this file is that one restored Allow line.

`tests/Unit/Ci4RequestHardeningHttpTest.php` and `tests/Unit/Ci4RouteMethodTest.php` match the pull-request parent. The hardening test is `testCrossSiteWritesAreRefusedWithSignInOffAndOn`: an unknown path stays 404, a cross-site `/theme` with sign-in on is 403, and a `/theme` without a foreign Origin is 303 to `/login`. The route test still has `testADoubledSlashIsTheJson404WhereTheRouterWouldAnswerOtherwise`.

`app/Config/Filters.php` is unchanged since `778b3abb`. Required before order: `routemethod`, `posttoolarge`, `consent`, `session`, `sameorigin`, `permission`. Globals before: `sessioncsrf`, then `formfields`.

## #293

`b49c453b` parents: `f8ef1493` and `5fe1f141`. `ItemController.php` differs from `778b3abb` by one line: `$undo->snapshot([$savedId], true)`. `ItemInput::limit` is still called for name, notes, tags and serial before the save. `UndoTokens::snapshot` takes `bool $withExpired = false`. `SessionController.php` matches `778b3abb`. `fb17bae6` does not change those files.

## Changelog and versions

Unreleased Changed is the #301 bullet. The 11 Fixed bullets each contain `([#294](https://github.com/thordin9/apotheke/pull/294))` once. `CHANGELOG.md` from `## [3.37.2]` matches `91a0c4ae` (182336 bytes). `README.md` and `VERSION` match main. VERSION is `3.37.2`.

## Commands

`vendor/bin/phpunit --no-coverage` on the six unit files plus `RoundTripBudgetTest`: sqlite OK, 53 tests, 1087 assertions. The same with `APOTHEKE_CI4_TEST_PGSQL=1`, `TEST_PG_HOST=127.0.0.1`, `TEST_PG_PORT=54334`, `TEST_PG_DB=apotheke`, `TEST_PG_USER=apotheke`, empty `TEST_PG_PASSWORD`: OK, 53 tests, 1087 assertions.

`vendor/bin/phpunit -c tests/Modules/phpunit.xml.dist --no-coverage` on `FormFieldsTest`, `PostTooLargeTest`, `SameOriginTest`, `MediaTest`, `PlainHandlerTest`, `ReadCacheTest`: sqlite 53 tests, 1323 assertions, 1 failure. PostgreSQL 53 tests, 1325 assertions, 1 failure. Both are `MediaTest::testTheServedCopyStepFlattensToWhiteAndConvertsCmyk` (`no alpha left`, expected false, got true) at `tests/Modules/Media/MediaTest.php:299`. `git diff 778b3abb HEAD` does not include that file.

`php scripts/ci4-arch.php di`: `[OK] No errors`, exit 0.

Scratch Postgres: `initdb -D /tmp/apotheke-pg-294b.* -U apotheke --auth=trust`, `pg_ctl -D <dir> -o '-p 54334 -k /tmp' -w start`, then `pg_ctl -D <dir> -w stop` and `rm -rf` only after it stopped. The directory is gone. No Docker image was present, so `scripts/docker-auth-checks.sh` was not run.

L-1884 closed. Next free id: 1885.
