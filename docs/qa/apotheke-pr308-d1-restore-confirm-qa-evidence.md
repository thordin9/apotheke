# PR 308 hosted restore confirm — evidence

No commit, push, comment or review. Head `64bfb992ce70815597b6eee42059ae2cd6ee354e`. `git ls-remote origin refs/pull/308/head` returned that SHA before the verdict. Diff against main `eddfe5a6`: 13 files, +348/−24. README and VERSION match main. VERSION is `3.42.0`. Changelog from `## [3.42.0]` matches main. The Unreleased bullet contains `([#308](https://github.com/thordin9/apotheke/pull/308))` once.

## Commands

`vendor/bin/phpunit --no-coverage` — exit 1. Tests 638, assertions 10392, failures 1, skipped 31, incomplete 1. Module suite: 1411 tests, 46812 assertions, failures 2, skipped 18. Names: `BackupHelpersTest::testAZipIsRefusedOnItsEntriesBeforeItsContents` and `MediaTest::testTheServedCopyStepFlattensToWhiteAndConvertsCmyk`.

Scratch Postgres: `initdb -D /tmp/apotheke-pg-308.* -U apotheke --auth=trust`, `pg_ctl -D <dir> -o '-p 54336 -k /tmp' -w start`, database `apotheke`. `APOTHEKE_CI4_TEST_PGSQL=1 TEST_PG_HOST=127.0.0.1 TEST_PG_PORT=54336 TEST_PG_DB=apotheke TEST_PG_USER=apotheke TEST_PG_PASSWORD=` empty, then the same phpunit. Exit 1. Tests 638, assertions 11160, failures 1, skipped 15. Module suite: 1902 tests, 53185 assertions, failures 2, skipped 4. Same two names. `Ci4BackupRestoreChecksTest::testARestoreThatLosesADeadlockIsTriedAgain` did not fail, so it was not repeated. Then `pg_ctl -D <dir> -w stop && rm -rf`. The directory is gone.

`bash scripts/lint.sh` — exit 0 (shellcheck and actionlint not installed). `php scripts/ci4-arch.php deptrac` — exit 0, 0 violations. `php scripts/ci4-arch.php di` — `[OK] No errors`.

`node --test --experimental-strip-types --test-name-pattern 'refuses the pragmas' edge/tests/r2.test.mjs` — exit 0, 1 pass. `page_count` and `dbstat` are refused. `pragma_table_info` is allowed.

## Mutations

Each file was restored with `git checkout --`. The tree was clean.

1. Hosted `liveBytes()` returned 0. `testTheSizeCheckWorksOnTheHostedDatabase` failed: `Failed asserting that 0 is greater than 0`.
2. `preflight()` returned immediately. The same test failed with `over the limit`.
3. `confirm()` omitted `hosted()`. `testAFailedConfirmShowsTheHostedPage` failed. The page then included the command-line restore hint.

## CI

Run `43371707732` (CI), head `64bfb992`. Lint (PHP 8.4) success. D1 (local workerd) success. Tests (PHP 8.4, sqlite) success. Tests (PHP 8.4, pgsql) success.

No finding. Next free id: 1887.
