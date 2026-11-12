# PR 291 session and routing — evidence

Read-only apart from local tests, a scratch Postgres, and a local D1 harness. Head `fcb89883cb9debee1f4d88277b3a2d9315548b4b`. Base `00945588` (v3.36.6). Live head matched. Seven commits, parent of the first is `00945588`. `README.md` and `VERSION` are not in the diff. `php spark apotheke:routes --check` exited 0. `CompiledRoutesTest` was not among the module failures.

## Suites

`vendor/bin/phpunit --no-coverage` — exit 1. Tests 619, assertions 9867, failures 2, skipped 30, incomplete 1. Nested module suite: 1331 tests, 45947 assertions, failures 2, skipped 18. Failures: `BackupHelpersTest::testAZipIsRefusedOnItsEntriesBeforeItsContents`, `MediaTest::testTheServedCopyStepFlattensToWhiteAndConvertsCmyk`, then `ReleaseTest::testUnreleasedHandwrittenBulletNeedsAPrLink` (five Unreleased bullets, none with a pull-request link).

Scratch Postgres: `initdb -D /tmp/apotheke-pg-291.* -U apotheke --auth=trust`, `pg_ctl -D <dir> -o '-p 54331 -k /tmp' -w start`, database `apotheke`. `APOTHEKE_CI4_TEST_PGSQL=1 TEST_PG_HOST=127.0.0.1 TEST_PG_PORT=54331 TEST_PG_DB=apotheke TEST_PG_USER=apotheke TEST_PG_PASSWORD= vendor/bin/phpunit --no-coverage` — exit 1. Tests 619, assertions 10631, failures 2, skipped 14. Nested module suite: 1820 tests, 52268 assertions, failures 2, skipped 4. Same three failure names. Then `pg_ctl -D <dir> -w stop` and `rm -rf` only after it stopped. The directory was gone.

Edge sources are not in the diff, so `r2.test.mjs` was not run. Local workerd (wrangler 4.156.0, `--local`, `127.0.0.1:8791`, a private persist dir, never `--remote`):

- `SELECT COUNT(*) AS n FROM sqlite_master WHERE type = 'table' AND name = 'settings'` → 200 `{"ok":true,"rows":[{"n":0}]}` while the only table was `_cf_METADATA`.
- After `CREATE TABLE settings`, the same query → `{"n":1}`.
- `SELECT value FROM settings` before the table, and `SELECT * FROM _cf_METADATA`, → `{"ok":false,"code":"write_failed","error":"the batch"}`.
- `PRAGMA table_list` is accepted and lists `settings`, `_cf_METADATA`, and the schema tables.

The harness was stopped and its directory removed. `WriteFailed` extends `PDOException`, so `AuthStatus::d1` replaces it with the connect line. Exit codes in `AuthStatusD1Test` stay 0, 3, 4, and 1, and the stderr of a bridge failure is that line.

## Mutations

Each edit was restored with `git checkout --` before the next. The tree was clean at the end.

1. `signOut` regenerated an id and did not expire the cookie. `testApiSignOutRemovesTheSessionAndExpiresTheCookie` failed (1 test, 8 assertions).
2. The no-consent branch returned without ending the session. `testAnOldSessionCookieEndsWithoutConsent` and `testARequestWithoutConsentEndsTheSession` failed.
3. `consent=decline` was refused. `testDeclineEndsTheSessionAndExpiresBothCookies` failed.
4. Branding URLs were left relative. `testBrandingIsWhatTheSignInPageShows` and `testBrandingUrlsAreAbsoluteOnThePublicBase` failed.
5. `routemethod` was removed from the required filter list. `testAnUnknownPageIs404NotARedirect` failed for both consent states.
6. The Decline button was removed from the notice. `testTheNoticeMatchesTheSiteNotice` failed.
7. The D1 probe was `SELECT 1` and always returned a PDO. `testANewInstallationRunsNoFailingQuery` failed.
8. The early `//` return was removed. `testDoubledSlashApiPathsAreTheJson404` stayed OK (2 tests, 170 assertions). Replacing the collapsed `isApi` check with the raw path failed that test (2 tests, 52 assertions). N-1881.

## CI

Head `fcb89883cb9debee1f4d88277b3a2d9315548b4b`. Rollup: Analyze (actions) success, Analyze (javascript-typescript) success, CodeQL success. No `D1 (local workerd)`, `Tests (PHP 8.4, sqlite)`, or `Tests (PHP 8.4, pgsql)` run is attached. The only Actions run for the SHA is `PR #291`, id `43212437498`, conclusion success. That workflow is not the PHP suite.

## Merge-only reads

`b96e20c9` (parents `a7b5ee78`, the link commit, and main `48d2fbc1`): own diff matched, apart from a hunk offset in `docs/deployment-and-operations.md` from #284.

`ce0b7c5ada2a30eab556524f438dd90c6e0c4dbf` (live head matched, 0 behind main `118d8f1b`, v3.36.8): `b96e20c9` is an ancestor. New commits are `350fdb44` (merge of `644a5d1d`), `9defee43` (`scripts/docker-auth-checks.sh` only, 4 lines), `bc0f4064` (merge of `10ae26b1`) and `ce0b7c5a` (merge of `118d8f1b`). `git diff` of the own changes against main, without `CHANGELOG.md`, `docs/HANDOFF.md`, the smoke script, index and hunk lines, is identical to `b96e20c9`'s. The smoke check expects 405 and `Allow` `POST` for an anonymous `PUT /backup/restore`; `hdr` passes the extra curl arguments. The changelog diff against main only adds the five linked bullets under Unreleased, and `README.md` and `VERSION` match main. At the read, lint, secret scan, deptrac and both upgrade and rollback jobs had passed, and the test, Docker and E2E jobs were pending.

L-1880 closed. N-1881. Next free id: 1885.
