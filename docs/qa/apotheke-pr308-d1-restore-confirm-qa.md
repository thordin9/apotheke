# PR 308 hosted restore confirm — QA

**Verdict: PASS** at `64bfb992ce70815597b6eee42059ae2cd6ee354e`. Live `refs/pull/308/head` is that commit. No finding. No hold. H-1849 stays closed. N-1850 stays open. L-1885 stays open.

## Checklist

| Check | Result |
|---|---|
| Size | PostgreSQL uses `pg_database_size`. A SQLite connection uses page count. A batch-only database sums stored values with `pragma_table_info`, which the Durable Object allow-list accepts. `page_count` is not used there. |
| Early refuse | With a limit of 1 byte the hosted restore stops before the swap and the catalog is unchanged. A limit above the measured size restores. Limit 0 skips the measure. A query error fails the restore. |
| Pragmas | On the hosted path the remaining statements are `pragma_table_info`, `PRAGMA defer_foreign_keys`, and `sqlite_sequence`. `page_count` and `dbstat` stay on the refused list. |
| Confirm | A failed hosted confirm is 422 with the fixed failure sentence and no command-line hint. The log line is the step and the cause class. |
| Worker | `restorer`, `storedBackups` and `backups` are not persistent. Those classes have no static properties. The fence token and the step are instance fields. |
| #305 | The swap is still one batch with the fence guard. Guests still have neither permission. Users, roles and sessions are still not catalog tables. |
| Docs | One Unreleased bullet, with the #308 link once. From `## [3.42.0]` the changelog matches main. README and VERSION match main (`3.42.0`). |

## Findings

None from 1887. Next free id is 1887.

## Security

The size check runs before staging when `RESTORE_DB_MAX_MB` is set. Measuring zero bytes fails the hosted size test. Skipping the refusal lets the over-limit restore continue, and that test fails too. The page shows the fixed failure sentence. The server log keeps a step name and an exception class, or a database class and a fixed phrase or identifier. It does not include the backup, SQL text, or a bound value. The confirm path passes the hosted flag, so the command-line hint is not shown.

## Tests

SQLite: 638 tests, 10392 assertions, 1 failure, 31 skipped, 1 incomplete. Module suite: 1411 tests, 46812 assertions, 2 failures, 18 skipped. PostgreSQL: 638 tests, 11160 assertions, 1 failure, 15 skipped. Module suite: 1902 tests, 53185 assertions, 2 failures, 4 skipped. Both module failures are the zip-entry helper and the CMYK served-copy check. Those files are not in this diff. The deadlock retry test did not fail. PHP lint, deptrac and PHPStan passed. The edge pragma test passed. CI: Lint, D1 (local workerd), Tests (PHP 8.4, sqlite) and Tests (PHP 8.4, pgsql) succeeded.

## Verdict

PASS.
