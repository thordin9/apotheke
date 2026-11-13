# PR 309 D1 fresh-install roles — QA

**Verdict: PASS** at `6e016cf6e93bb59ca6036da7b6e0dfe37ca0062d`, a merge of main `daf93c1f` (#300) into the gated head `3f0ae9af`, 0 behind. Live `refs/pull/309/head` is that commit. Merge-only read: own diff equal to the gated head's. One note. No hold. L-1885 is closed for an install that starts under this code. H-1849 stays closed. N-1850 stays open.

## Checklist

| Check | Result |
|---|---|
| Batch | On a fresh install the role updates and `DROP TABLE apotheke_fresh_install` are in the same D1 batch as migration 0022's `schema_migrations` row. |
| Resume | A stop at 0015, 0022, 0023, 0033, or the defaults batch finishes, after the next run, with the same role rows and tables as an install that did not stop. |
| Existing | A database that already has migrations and no marker does not get the marker. 0022 then keeps the 2.6.x rows. Guests stay at view only. |
| Restore | The marker is not in `Backups::tables()`. A catalog swap does not create or remove it. The stage sweep matches `apotheke_stage_`, not this name. |
| PDO | `PdoMigrationRunner` is not in the diff. It still applies the updates inside its one transaction. |
| Docs | One Unreleased bullet, with the #309 link once. From `## [3.42.1]` the changelog matches main `daf93c1f`. README and VERSION match that main (`3.42.1`). |

## Findings

**N-1887.** A marker created after 0022 is already recorded is left in place. The next run does not treat that database as fresh and does not rewrite roles.

Next free id is 1888.

## Security

The marker is created only when `schema_migrations` has no rows yet. A later run is fresh only while 0022 is still pending and the marker is present. After 0022's batch commits, the marker is gone and a resume does not apply the updates again. An existing install is not rewritten. The guest row is not in the update list. Editor and viewer gain download, and editor gains restore, only on that fresh path. Those are the new-install defaults, not extra permissions beyond them. A catalog restore does not carry the marker and does not clear `schema_migrations`, so it cannot make a migrated database look fresh.

## Tests

SQLite: 638 tests, 10394 assertions, 1 failure, 31 skipped, 1 incomplete. Module suite: 1418 tests, 46854 assertions, 2 failures, 18 skipped. PostgreSQL: 638 tests, 11165 assertions, 1 failure, 15 skipped. Module suite: 1909 tests, 53227 assertions, 2 failures, 4 skipped. Both module failures are the zip-entry helper and the CMYK served-copy check. Those files are not in this diff. The deadlock retry test did not fail. Local workerd: 213 tests, 704 assertions, pass. CI at the gated head `3f0ae9af` was Analyze and CodeQL only. Tests (PHP 8.4, sqlite) and Tests (PHP 8.4, pgsql) must be green on the final head before merge.

Removing the updates failed six tests. Skipping the marker failed the stops before 0022 commits. Leaving the marker in the 0022 batch failed the existing-install test. Forcing every database to be fresh failed that test and gave the editor the new backup permission.

## Verdict

PASS.
