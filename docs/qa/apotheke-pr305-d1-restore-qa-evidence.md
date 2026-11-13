# PR 305 hosted restore — evidence

No commit, push, comment or review. Head `b810761230ec91b4eef87d233f28382bed43bc9e`. It is the full gate at `e47ed52fc925f6b72cf328fd493550d857e793a5` below plus the merge-only read in the last section. At `e47ed52f`: `git ls-remote origin refs/pull/305/head` returned that SHA before the verdict. Diff against merged main `9e4a0ee7`: 52 files, +2201/−242. README and VERSION are not in that diff. Two Unreleased bullets, each with `([#305](https://github.com/thordin9/apotheke/pull/305))` once.

## Commands

`vendor/bin/phpunit --no-coverage` — exit 1. Tests 637, assertions 10390, failures 1, skipped 30, incomplete 1. Nested module suite: 1390 tests, 46599 assertions, failures 2, skipped 18. Failures: `BackupHelpersTest::testAZipIsRefusedOnItsEntriesBeforeItsContents` and `MediaTest::testTheServedCopyStepFlattensToWhiteAndConvertsCmyk` (`no alpha left`). Then the wrapper exit. `D1RestoreTest` and `RestorePermissionTest` are not in the failure list.

Scratch Postgres: `initdb -D /tmp/apotheke-pg-305.* -U apotheke --auth=trust`, `pg_ctl -D <dir> -o '-p 54335 -k /tmp' -w start`, database `apotheke`. `APOTHEKE_CI4_TEST_PGSQL=1 TEST_PG_HOST=127.0.0.1 TEST_PG_PORT=54335 TEST_PG_DB=apotheke TEST_PG_USER=apotheke TEST_PG_PASSWORD=` empty, then the same phpunit. Exit 1. Tests 637, assertions 11157, failures 1, skipped 14. Module suite: 1881 tests, 52953 assertions, failures 2, skipped 4. Same two names. Then `pg_ctl -D <dir> -w stop && rm -rf "$D"`. The directory is gone.

`bash scripts/lint.sh` — exit 0. PHP lint, JS syntax, 383 markdown links, changelog links and the pipefail grep check passed. shellcheck and actionlint were not installed.

`php scripts/ci4-arch.php deptrac` — exit 0, 0 violations. `php scripts/ci4-arch.php di` — `[OK] No errors`.

CI rollup: Analyze (actions), Analyze (javascript-typescript) and CodeQL success. No PHP, D1 or lint job is attached. The only Actions run for the SHA is `PR #305`, id `43320161459`, success.

## Mutation

`Permissions::stored` was changed so a download-only role is stored as `backup`. `vendor/bin/phpunit -c tests/Modules/phpunit.xml.dist --filter testARollbackNeverGrantsMoreThanBefore` — exit 1, 1 test, 7 assertions, 1 failure. The file was restored with `git checkout --`. The tree was clean after that.

## Notes checked in the source

`Backups::tables()` is locations, categories, tags, items, item_photos, item_tags, item_attachments, app_settings, activity, saved_filters, stocktakes, stocktake_lines. `RestoreStage::PREFIX` is `apotheke_stage_`. `swap()` calls `oneBatch` then `exec->run` on that list, and adds `RestoreFence::guard` when a fence token is set. `FreshInstallRoles::statements()` runs after the D1 migration loop as its own `$d1->run`, and inside the PDO migration transaction before `COMMIT`. `RoleAdmin::NOT_FOR_GUESTS` is users, settings, backup, restore. `GET /api/v1/backups` and `GET /api/v1/backups/([^/]+)` use permission `backup`. Restore routes use `restore`. `apicsrf` is before `api` and `api/*`.

L-1885. N-1886. Next free id: 1887.

## Merge-only read at `b8107612`

`git ls-remote origin refs/pull/305/head` gave `b810761230ec91b4eef87d233f28382bed43bc9e`. Main is `1e391e9d` (v3.40.0). `rev-list --count H..origin/main` is 0, and `e47ed52f` is an ancestor. `747dfee6` has parents `e47ed52f` and `1e391e9d`. `git merge-tree --write-tree --name-only e47ed52f 1e391e9d` shows a conflict only in CHANGELOG.md. The diff of `9e4a0ee7..e47ed52f` and of `1e391e9d..b8107612`, excluding CHANGELOG.md, docs/HANDOFF.md and docs/qa and dropping index and @@ lines, is cmp-equal (3494 lines each). CHANGELOG: the preamble and everything from `[3.40.0]` equal main. Unreleased has the two #305 bullets, each linking #305 once. README.md and VERSION equal main. `b8107612` changes only docs/HANDOFF.md, with the #305 description and the v3.40.0 current-state line. Next steps item 1 still says v3.37.0 (N-1886). `app/Config/Services.php` `storedBackups()` builds `StoredBackups` on `[$data] = StorageFactory::forApotheke(...)`. `StoredBackups::PREFIX` is `backups/`. `edge/src/installation.ts` gives the R2 prefix `installations/${doName}/`, which the Worker applies, because the R2 bridge takes only relative keys. CI on `b8107612`: Tests (PHP 8.4, sqlite) and Tests (PHP 8.4, pgsql) pass.
