# PR 305 hosted restore — QA

**Verdict: PASS** at `b810761230ec91b4eef87d233f28382bed43bc9e`. Live `refs/pull/305/head` is that commit. It is the full gate at `e47ed52f` plus a merge-only read of the main catch-up. One low and one note finding. No hold. H-1849 stays closed. N-1850 stays open.

## Checklist

| Check | Result |
|---|---|
| Swap | `RestoreStage::swap` builds one batch: fence guard, row-count guards, delete, insert, sequences, marker. `oneBatch` refuses it before it is sent when it would not fit. A stop before that batch leaves the rows and objects as they were. |
| Fence | `claim` deletes and inserts the fence row in one transaction. A later token owns it. An older token is refused, and the object is not replaced. |
| Permissions | A stored `backup` is both. Download only is `backup-only` and restore only is `restore-only`, names 2.6.x drops. The guest is view only. |
| Fresh install | Editors get both, viewers get download, the guest gets neither. An upgrade does not rewrite roles. |
| Accounts | `users`, `roles` and `sessions` are not catalog tables. A restore leaves the account and the role row. |
| API | `GET /backup` needs backup or restore and shows only what the role may do. Download and `GET /api/v1/backups` need backup. Restore routes need restore. An id must match the stored pattern. |
| Merge-only | `747dfee6` merges main `1e391e9d` (v3.40.0); 0 behind. Only CHANGELOG conflicted. The own diff outside CHANGELOG, HANDOFF and docs/qa is equal to the gated one. CHANGELOG keeps main's releases and adds the two #305 bullets. README and VERSION equal main. `b8107612` changes only docs/HANDOFF.md. |
| Storage with #303 | `Services::storedBackups` takes the data storage from `StorageFactory::forApotheke`. On R2 the keys are relative `backups/…`, and the Worker adds the installation prefix `installations/<do_name>/`. Locally they go to `DATA_DIR/backups/`. |
| Design B | `restore_marker` and `sessions` stay in `UNCARRIED_MIGRATIONS` and `upg_ci4_only()`. Copies are `apotheke_stage_` plus a carried table, swept when no lock is held, and are not in `Backups::tables()`. |

## Findings

**L-1885.** On D1, the new role rows are a batch after migration 0022 has committed. If that later batch does not run, the next boot sees an existing database and does not apply them. The rows then grant less: editors and viewers lack the new permissions, and the guest stays at view only. The admin row is unchanged. A PDO install applies the updates in the same transaction. Reset built-in roles writes the new defaults.

**N-1886.** docs/HANDOFF.md Next steps item 1 still says the current release is v3.37.0. Current state says v3.40.0.

Next free id is 1887.

## Security

The catalog swap does not run unless this restore still owns the fence, and it does not commit a partial table set: the batch is refused whole or sent whole. A failed claim is a busy answer. Download and restore stay separate permissions. Opening `/backup` with either one does not allow the other action. The guest cannot be saved with backup or restore. A download id that is not `YYYYMMDD-HHMMSS` plus six hex digits is not found, and the object key is built only from that id under `backups/`. List and download are GET. Restore writes are POST or DELETE and use the API token check.

## Tests

SQLite: 637 tests, 10390 assertions, 1 failure, 30 skipped, 1 incomplete. Module suite: 1390 tests, 46599 assertions, 2 failures, 18 skipped. PostgreSQL: 637 tests, 11157 assertions, 1 failure, 14 skipped. Module suite: 1881 tests, 52953 assertions, 2 failures, 4 skipped. The two module failures are the zip-entry helper and the CMYK served-copy check. Those files are not in this diff. PHP lint, changelog links, deptrac and PHPStan passed. At `e47ed52f` CI ran only Analyze and CodeQL. At `b8107612` Tests (PHP 8.4, sqlite) and Tests (PHP 8.4, pgsql) are green in CI. Storing download-only as `backup` failed `testARollbackNeverGrantsMoreThanBefore`.

## Verdict

PASS.
