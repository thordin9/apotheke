# PR 293 D1 round trips — evidence

Read-only apart from local tests, a scratch Postgres, and a local D1 harness. Head `6ea3d4a000cbf874f92bb3c92a1462a3c391cc43`. Base `00945588`. Live head matched. Four commits. `README.md` and `VERSION` are not in the diff. One Unreleased bullet, no pull-request link.

## Suites

`vendor/bin/phpunit --no-coverage` — exit 1. Tests 603, assertions 9562, failures 2, skipped 30, incomplete 1. Nested module suite: 1334 tests, 46030 assertions, failures 2, skipped 18. Failures: `BackupHelpersTest::testAZipIsRefusedOnItsEntriesBeforeItsContents`, `MediaTest::testTheServedCopyStepFlattensToWhiteAndConvertsCmyk`, then `ReleaseTest::testUnreleasedHandwrittenBulletNeedsAPrLink`.

Scratch Postgres: `initdb -D /tmp/apotheke-pg-293.* -U apotheke --auth=trust`, `pg_ctl -D <dir> -o '-p 54332 -k /tmp' -w start`, database `apotheke`. `APOTHEKE_CI4_TEST_PGSQL=1 TEST_PG_HOST=127.0.0.1 TEST_PG_PORT=54332 TEST_PG_DB=apotheke TEST_PG_USER=apotheke TEST_PG_PASSWORD= vendor/bin/phpunit --no-coverage` — exit 1. Tests 603, assertions 10327, failures 2, skipped 14. Nested module suite: 1822 tests, 52354 assertions, failures 2, skipped 4. Same three failure names. Then `pg_ctl -D <dir> -w stop` and `rm -rf` only after it stopped.

`vendor/bin/phpunit --filter testD1RequestsStayWithinTheirRoundTrips` — OK (1 test, 34 assertions). With the `GET /` ceiling set to 10 and then restored, the same test failed and reported `{"GET /healthz":1,"GET /api/v1/me":8,"GET /":12,"GET /item/{id}":14,"POST /save (new)":9,"POST /save (edit)":13}`.

## Workerd

Wrangler 4.156.0, `--local`, `127.0.0.1:8792`, a private persist dir, never `--remote`. Stopped and the directory removed.

`Writes::rowsMany` on a D1 connection to that harness, after two rows named `O'Brien` (qty 2) and `c` (qty 3):

- three selects returned `[[{id:1,name:O'Brien},{id:3,name:c}],[{n:1}],[]]`, the same as one-by-one.
- `SELECT 1` plus `SELECT nope FROM item` raised `Database read failed`. It did not return empty rows.
- A raw batch of those two statements: `{"ok":false,"code":"write_failed","error":"the batch"}`.
- `INSERT`, `SELECT last_insert_rowid()`, `SELECT COUNT(*)` in one batch: id 4 and n 4.

## Mutations

Each edit was restored with `git checkout --`. The tree was clean at the end.

1. `WriteClock::wrote` returned immediately. `testAccountsAreReadAgainAfterTheFirstAccountAndEachChange` and `testTheRequestsFirstBatchFillsTheCacheAndAWriteStillWins` failed.
2. The cache stayed on while a transaction was open. `testNothingIsRememberedInsideATransactionOrWhenOff` failed.
3. A failed read batch returned `[]` instead of null. `testTheD1DriverReadsOneByOneWhenABatchWouldNotDo` and `testAFailingStatementFailsAsRowsDoes` failed.
4. `readCache` was added to the persistent services. `testEachRequestGetsAnEmptyCache` failed.
5. `remember()` no longer skipped a null clock. `testSqliteAndPostgresqlReadEveryTime` errored (`stamp()` on null).

## CI

Head `6ea3d4a000cbf874f92bb3c92a1462a3c391cc43`. Rollup: Analyze (actions) success, Analyze (javascript-typescript) success, CodeQL success. No `D1 (local workerd)`, `Tests (PHP 8.4, sqlite)`, or `Tests (PHP 8.4, pgsql)` run is attached. The only Actions run for the SHA is `PR #293`, id `43221600041`, conclusion success. That workflow is not the PHP suite.

## Merge-only read

`a32e8d100d285829c56207f345d4607d618d3e59` (live head matched, 0 behind main `5e2d3471`, v3.37.0): `6ea3d4a0` is an ancestor. New commits are `9d04a764` (`CHANGELOG.md` only: the PR link and 45 to 12) and `a32e8d10` (plain merge of `5e2d3471`). `git diff` of the own changes against main, without `CHANGELOG.md`, `docs/HANDOFF.md`, index and hunk lines, is identical to `00945588..6ea3d4a0`. The changelog diff against main only adds the linked bullet under Unreleased. The handoff paragraph is unchanged. `README.md` and `VERSION` match main.

`cb49ca951476c2dd66cbb2ce4b22ce3e51d794e7` (live head matched, 0 behind main `3be6990d`, v3.37.1): `a32e8d10` is an ancestor. The only new commit is `cb49ca95`, a plain merge of `3be6990d` whose one conflict was `CHANGELOG.md`. The own diff against main, compared the same way, is identical to `00945588..6ea3d4a0`. The Performance bullet sits under Unreleased above `[3.37.1]`, and released sections, the handoff paragraph, `README.md` and `VERSION` match main.

L-1882 and L-1883 closed. Next free id: 1885.
