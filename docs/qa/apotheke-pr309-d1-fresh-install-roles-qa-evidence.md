# PR 309 D1 fresh-install roles — evidence

No commit, push, comment or review. Final head `6e016cf6e93bb59ca6036da7b6e0dfe37ca0062d` (merge-only read below). Gated head `3f0ae9af746841de711c8408db94ad5f7cd11fb0`. `git ls-remote origin refs/pull/309/head` returned that SHA before the verdict. Merge base with main `960ab746` is that same commit. Own diff: 5 files, +218/−9. `PdoMigrationRunner.php` is not in it. Changelog from `## [3.42.1]` matches `960ab746`. README and VERSION match. VERSION is `3.42.1`. One Unreleased bullet, one `([#309](https://github.com/thordin9/apotheke/pull/309))`.

## Commands

`vendor/bin/phpunit --no-coverage` — exit 1. Tests 638, assertions 10394, failures 1, skipped 31, incomplete 1. Module suite: 1418 tests, 46854 assertions, failures 2, skipped 18. Failures: `BackupHelpersTest::testAZipIsRefusedOnItsEntriesBeforeItsContents` and `MediaTest::testTheServedCopyStepFlattensToWhiteAndConvertsCmyk`.

Scratch Postgres: `initdb -D /tmp/apotheke-pg-309.* -U apotheke --auth=trust`, `pg_ctl -D <dir> -o '-p 54337 -k /tmp' -w start`, database `apotheke`. `APOTHEKE_CI4_TEST_PGSQL=1 TEST_PG_HOST=127.0.0.1 TEST_PG_PORT=54337 TEST_PG_DB=apotheke TEST_PG_USER=apotheke TEST_PG_PASSWORD=` empty, then the same phpunit. Exit 1. Tests 638, assertions 11165, failures 1, skipped 15. Module suite: 1909 tests, 53227 assertions, failures 2, skipped 4. Same two names. No deadlock retry failure. Then `pg_ctl -D <dir> -w stop && rm -rf`. The directory is gone.

`./tools/d1-harness/run-tests.sh` — exit 0. OK (213 tests, 704 assertions). The harness removed its own state directory. The lockfile change from `npm install` was restored and `node_modules` removed.

## Mutations

Each edit was restored with `git checkout --`. The tree was clean.

1. `freshRoles()` returned only the marker drop. Six failures, including every interruption point and `testTheRoleDefaultsGoInTheBatchThatCreatesTheRoles`.
2. The empty-database setup did not create the marker. Failures at stops `0015`, `0022`, and `roles`. Stops `0023` and `0033` still passed, because 0022 had already committed the updates.
3. The 0022 batch did not drop the marker. `testAnInstallThatGetsTheRolesTableLaterKeepsTheOldDefaults` failed. The full install still had `apotheke_fresh_install` and the later-roles install did not.
4. `$fresh` was forced true. That same test failed: editor was `... "backup"` instead of the 2.6.x list.

## Marker

`Backups::tables()` does not name `apotheke_fresh_install`. `RestoreStage::PREFIX` is `apotheke_stage_`. The marker is not a numbered migration, so it is not in `UNCARRIED_MIGRATIONS` or `upg_ci4_only()`. A failed 0022 batch is not sent: the stand-in throws before `run()`, and `D1Pdo::commit` sends the queue as one `run()`.

## CI

Head `3f0ae9af`. Rollup: Analyze (actions), Analyze (javascript-typescript), CodeQL, all success. The only Actions run is `PR #309`, id `43381790508`, success. No `Tests (PHP 8.4, sqlite)`, `Tests (PHP 8.4, pgsql)`, or `D1 (local workerd)` job is attached.

N-1887. L-1885 closed for installs that receive the marker. Next free id: 1888.

## Merge-only read at `6e016cf6`

Parents `3f0ae9af` and main `daf93c1f` (#300, ci only, no release); 0 behind; `3f0ae9af` is an ancestor. `git merge-tree` of `3f0ae9af` with `daf93c1f`: only `CHANGELOG.md` conflicts; `docs/HANDOFF.md` auto-merges. Own diff (excluding CHANGELOG, HANDOFF, docs/qa; index and @@ lines dropped), `960ab746..3f0ae9af` vs `daf93c1f..6e016cf6`: 282 lines each, `cmp` equal. Main..head touches CHANGELOG, HANDOFF, `D1MigrationRunner.php`, `FreshInstallRoles.php`, `D1FreshInstallRolesTest.php`. CHANGELOG preamble and everything from `## [3.42.1]` equal main; Unreleased adds only the #309 Fixed bullet (one link). README and VERSION equal main. HANDOFF adds only the #309 paragraph.
