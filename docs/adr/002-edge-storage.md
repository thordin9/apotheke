# ADR-002: Edge storage on Cloudflare (D1, Durable Object SQLite and R2)

## Status

Accepted by the PM on 2026-10-31 as the design for P4 (storage and database adapters). The binding names and the
file ownership below are agreed with DevOps. Nothing on Cloudflare is created yet. The deploy of `apotheke-api`
stays behind the repository variable `EDGE_STORAGE_READY` until the criteria in
[Opening the storage gate](#opening-the-storage-gate) are met.

This ADR is built on the P4(a) spike (a local prototype and a local D1 harness, run under `wrangler dev --local`
only). For the CodeIgniter 4 foundation it builds on, see [ADR-001](001-codeigniter4.md).

## Context

`edge/` runs the Apotheke image (`Dockerfile.frankenphp`) as a Cloudflare Container behind a Durable Object
(`edge/src/index.ts`, class `ApothekeContainer`, one named instance). A container's disk is ephemeral: it starts
from the image after every sleep. So nothing durable can live on its disk. The database and the files have to move
to Cloudflare storage before the first real deploy, and that is what the storage gate in
[deployment and operations](../deployment-and-operations.md#storage-gate-edge_storage_ready) waits for.

The app talks to its database through CodeIgniter's connection and `Core\Database\Writes`, and to its files through
`Core\Storage` plus about 18 direct filesystem users. Self-hosted Docker uses PDO-backed SQLite or PostgreSQL and
`DATA_DIR`. That has to keep working unchanged.

The same image has to serve three cases:

| Mode | Who runs it | App data | Files | Control plane |
|---|---|---|---|---|
| Self-hosted Docker | anyone | SQLite or PostgreSQL (PDO), as today | `DATA_DIR` on local disk | none |
| Self-hosted on Cloudflare (single instance) | one owner, own account | one D1 database | one R2 bucket | none |
| Hosted, multi-tenant | the project | the SQLite storage of one Durable Object per installation | one shared EU R2 bucket, one key prefix per installation | one D1 database: the installations registry |

## Decision

### 1. Tenant-ready from the start, in the EU jurisdiction

- The D1 database and the R2 bucket are created in the **EU jurisdiction**. That means D1 is created with
  `--jurisdiction eu`, and the R2 binding carries `"jurisdiction": "eu"`. A location hint such as `weur` only
  suggests where data is placed. It is not a jurisdiction, and a jurisdiction can't be added to a resource later:
  moving would mean a new resource and a data copy. So the resources are created in the jurisdiction the first time.
- Single-instance resources are laid out so that they can become tenants later: R2 keys already sit under the
  storage layer's prefix (decision 4), and nothing in the app assumes "the one database of the account".

### 2. All SQL behind one executor adapter

- Every write goes through a **unit of work** (`UnitOfWork`: `hold()`, `insert()` returning a `Ref`, `update()`,
  `write(…, expect, fail)` and `assert(condition, …, fail)`). A unit reads first and queues its writes. The writes
  then go out in one piece.
- A **batch executor** (`BatchExecutor`: `run(statements)` and `query(sql, params)`) runs that piece. The app does
  not care whether D1 or a Durable Object's SQLite answers. Both receive the same plain SQLite statements in one
  atomic batch.
- On self-hosted Docker, the PDO path stays: `ConnectionUnitOfWork` runs the same queue inside a real transaction
  on SQLite or PostgreSQL, so behaviour is the same on every backend. PostgreSQL-only code paths (`FOR UPDATE`,
  `LOCK TABLE`, snapshot reads) are simply not taken on the edge.
- Ids that a unit inserts are `Ref`s: a ULID generated up front, resolved after the batch through `uow_refs`
  (`last_insert_rowid()` right after the insert). Ids are read back with one `json_each(?)` parameter.
- Guards make the whole batch fail: an insert into the `NOT NULL` column `uow_guard.ok` that only happens when
  `changes()` is not what was expected, or when an asserted condition is false. After the rollback the failed
  guard is identified by reading again, so the caller's own error (for example a `UserError`) is thrown.
- A unit started inside another one on the same connection joins it: one batch, one commit, all or nothing.

### 3. Installation identity comes from the Worker side only

- On the edge, the installation is derived by the Worker and the Durable Object alone: from the routed host, looked
  up in the control plane, and then from the Durable Object id the Worker picked for it.
- PHP never sends an installation id, a database name or a bucket prefix, and the bridge never reads one from a
  request PHP builds. The bridge answers for the installation whose Durable Object the container belongs to.

### 4. The storage layer prefixes R2 keys itself

- PHP names files by their app key (`media/full/…`, `attachments/…`, `backups/…`), exactly as it names them under
  `DATA_DIR` today.
- The Worker-side storage handler adds the installation's prefix before every R2 call and strips it from listings.
  PHP never builds, sees or chooses the prefix. Keys that try to leave the app namespace (an empty segment, `.` or
  `..`, a leading `/`) are refused by the handler.

### 5. Host-only cookies in hosted mode

- In hosted mode every cookie is host-only: `COOKIE_DOMAIN` is not passed to the container, and the app ignores it
  if it is set there. The `__Host-` session cookie never carries a `Domain` anyway (#188). The consent cookie is then
  host-only too.
- `COOKIE_DOMAIN` stays available for a self-hosted single instance whose owner wants one consent answer across
  their own sub-domains.

### Modes in detail

**Hosted, multi-tenant.** One Container and one Durable Object per installation (`Installation`, a SQLite-backed
Durable Object class). The Durable Object's own SQLite storage holds that installation's app data, using the
same schema as SQLite on Docker. Files go to the shared EU bucket under that installation's prefix. D1 is the
control plane only: the registry that maps a host to an installation and its state. Per-installation data never
lives in D1, so one installation's data is never in another's database.

**Self-hosted on Cloudflare.** One Container and one Durable Object, one D1 database for the app data, and one R2
bucket. The prefix is a fixed one for the single installation. There is no control plane. This is the mode the
[self-hosting guide](../self-host-cloudflare.md) will describe after P4.

The plan's earlier choice of D1 over Durable Object SQLite still holds for the single instance, which keeps D1's
Time Travel and `wrangler d1 export`. In hosted mode a per-installation Durable Object adds what D1 can't give:
isolation per installation and real transactions inside the object (`transactionSync`), which the large paths below
use.

## Bindings

Agreed with DevOps:

| Binding | Kind | Resource | Notes |
|---|---|---|---|
| `DB` | D1 | database `apotheke-api` | created with `--jurisdiction eu` |
| `STORAGE` | R2 | bucket `apotheke-api-storage` | `"jurisdiction": "eu"`, private, no public access |
| `Installation` | Durable Object class | exported from `edge/src` | added with a `new_sqlite_classes` migration |

All three go into `edge/wrangler.jsonc` only behind `EDGE_STORAGE_READY`, so the default deploy doesn't change.
DevOps adds them to the flipped configuration once `edge/src` exports `Installation` (P4-3, wired in P4-5). No
account id, resource id or secret is ever written to the repository.

## D1 constraints and how the design meets them

Found with the local D1 harness (wrangler 4.156.0, `--local`) and the function audit. Remote D1 is checked again
on a preview database before the gate opens.

| Constraint | Design answer |
|---|---|
| `BEGIN`, `COMMIT`, `SAVEPOINT` and `ROLLBACK TO` are refused | The D1 and Durable Object executors never emit them. `Writes::transaction()` maps to a unit of work and one batch. The import preview validates without writing (a dry run, or a scratch in-memory SQLite). |
| At most 100 bound parameters per statement | Long id lists go through one `json_each(?)` parameter (5,000 ids tested). `SearchIndex.php:317-318` (chunks of 500) and `Stocktakes.php:76` (one `?` per sub-location) move to `json_each` in P4-2; `ItemList.php:49` follows for headroom. Chunking is a fallback only. |
| Connection `PRAGMA`s (`journal_mode`, `busy_timeout`, `synchronous`) are not authorised | The edge driver skips them. D1 manages durability and has no locks to wait on. |
| `PRAGMA user_version` is not authorised | The edge runner uses `schema_migrations` only. The `user_version` path is the legacy v1 upgrade, which a new database never takes. |
| `sqlite_version()` and other `sqlite_*()` functions are not authorised | Never called. System info names the engine ("Cloudflare D1") without a version. FTS5 is found with the existing `SELECT rowid FROM search_fts LIMIT 0` probe. |
| `meta.last_row_id` is connection-wide and stale after a non-insert | Never trusted. Ids come from `uow_refs` right after each insert, or `INSERT … RETURNING`, and are read back after the batch. Natural keys (`name_key`) are used where an upsert may insert nothing. |
| `VACUUM INTO` is refused | The pre-migration backup on the edge is a Time Travel bookmark (D1) or a backup zip in R2 (Durable Object), not a file copy. |
| `PRAGMA foreign_keys = OFF` is silently ignored | Foreign keys are always on. A migration never drops a parent table on the edge, because the implicit delete cascades to its children. It uses `ALTER TABLE ADD COLUMN` / `RENAME COLUMN`, or copies and restores the children in the same batch. `PRAGMA defer_foreign_keys = ON` as a batch's first statement is allowed. |
| Several statements in one prepared statement run, but only the last result comes back | Multi-statement migration SQL goes through `exec()` (or is split), never through `prepare()`. |
| 100 KB per statement, 2 MB per row, 30 s per query | The largest generated statement is about 41 KB and moves to `json_each`. Undo payloads over about 512 KB go to R2 with a pointer in the row. |

All the query features the app uses work on D1 unchanged, including FTS5 with `remove_diacritics 2`, `bm25`,
`fts5vocab`, `json_each`, the date modifiers, `ON CONFLICT … DO NOTHING` and `INSERT … SELECT`.

## The `Writes::transaction` sites

The spike counted **29 call sites in 19 files** (three of them wrappers: `UserRepository::atomically()`,
`ItemRepository::atomically()` and `TaxonomyRepository::tx()`). Since then the CSV import job (#185) added a
thirtieth in `Imports.php`, which already runs as one small transaction per row with an idempotent marker, so it
maps to one batch per row. Each site gets one strategy:

| Strategy | What it means | Sites |
|---|---|---|
| Batch | The writes as they are, one batch | role moves and resets, attachments, item delete, favourites (sequence and prune as subqueries), the import job's rows |
| Ref or read-back | A ULID `Ref` or a natural-key upsert in place of `lastInsertId`, then one batch | first admin, tags (`ON CONFLICT (name_key)`), stocktake start, saved filters, taxonomy create |
| Guard | A hold first, then `expect` n changed rows, an `assert` on a condition, or a version compare-and-swap | admin and role changes, item save, photo add, edit, reorder and actions, merge, quantity, undo, lending, settings rewrite, stocktake ticks and finish, taxonomy moves |
| Compensating work | Can't be one batch. Run in chunks with an idempotent retry and a compensating step | backup restore, search index rebuild, bulk actions on very large selections |

The guarded sites are where the edge differs most from PDO. With no reads under a lock, every conditional site has
to carry its own guard, or a concurrent change goes through unseen. The backend matrix therefore forces one guard to
fail on every backend.

**The large paths** run differently by mode:

- **Hosted (Durable Object SQLite):** the restore, the search rebuild and very large bulk actions run inside a
  Durable Object transaction (`transactionSync`). The object owns its storage, so they stay all-or-nothing.
- **Self-hosted on D1:** they run as chunked compensating work.
  - The restore runs behind a maintenance flag, with a Time Travel bookmark taken first and about 1,000 rows per
    batch with `defer_foreign_keys`. On failure, the database goes back to the bookmark.
  - The search rebuild is derived data. It is rebuilt in chunks, and the fingerprint is written last, so a stopped
    rebuild just starts again.
  - Bulk actions keep per-item atomicity, and the undo token is idempotent.
  - Each of these gets a resume test.

## Migrations

- The app schema stays in `migrations/` and is applied by the app's own runner (`PdoMigrationRunner` with an edge
  dialect) through the bridge. There is one path for D1 and for Durable Object SQLite, and no
  `wrangler d1 migrations` step. The runner records versions in `schema_migrations`, sends multi-statement SQL
  through `exec()`, skips the connection `PRAGMA`s, `user_version` and `VACUUM INTO`, and splits large data
  migrations into chunks of about 1,000 rows.
- In hosted mode each installation's Durable Object migrates its own storage the first time it starts on a new
  image, using the same runner.
- `uow_guard` and `uow_refs` are added as ordinary migrations. They are harmless on every backend.
- `edge/migrations/control/` is reserved for the control-plane schema (the installations registry). It's unused
  until hosted mode needs it, and it is never mixed with the app schema.
- Tables that only the CI4 app has stay registered in `Backups::UNCARRIED_MIGRATIONS` and `upg_ci4_only()`. The
  2.6.x tables keep their shape, so rolling back to the Docker image stays possible from a backup zip.

## The bridge

- The container reaches storage only through the Durable Object's outbound handlers, which run in the Workers
  runtime: `http://d1.internal` (`/query`, `/batch`, `/exec`) and `http://r2.internal/<key>` (GET, PUT, HEAD,
  DELETE, list and multipart). The container holds no Cloudflare token, and `enableInternet` stays off.
- On the single instance, `d1.internal` calls the `DB` binding. In hosted mode it calls the installation's own
  Durable Object SQLite. PHP sees the same JSON shape in both cases.
- PHP's side is a CodeIgniter database driver (`Apotheke\Core\Database\D1`, reusing the SQLite3 builder and
  Forge) and an `R2Storage` behind the existing `Core\Storage` interface.

## PR split

Each PR goes through the full QA gate, stays under about 800 lines, and updates `docs/HANDOFF.md`.

1. **P4-1: this ADR.** Docs only.
2. **P4-2: unit of work and executor adapter.** `UnitOfWork`, `Ref`, `BatchExecutor`, the PDO executor first with no
   change in behaviour, `uow_guard` and `uow_refs`, and the `json_each` fixes in `SearchIndex` and `Stocktakes`.
   Then the sites are converted in follow-up parts: batch and Ref first, then guards, then the large paths.
3. **P4-3: edge driver and bridge.** The CodeIgniter `D1` driver, the edge migration dialect, and in `edge/src` the
   outbound handlers and the `Installation` Durable Object class. The local D1 harness cases run in CI under
   `wrangler dev`.
4. **P4-4: storage interface.** `Core\Storage` with a local-disk and an R2 implementation for photos, attachments,
   uploads and backups, moving the remaining direct filesystem users behind it. The Worker-side handler adds the
   installation prefix.
5. **P4-5: hosted wiring.** Durable Object SQLite per installation, host-only cookies in hosted mode, the
   `edge-local` run green with storage, the P4(b) guard cases run again on a preview D1, and the runbook.

## Ownership

| Area | Owner |
|---|---|
| `edge/wrangler.jsonc` (bindings, jurisdiction, `secrets.required`) | DevOps |
| `.github/workflows/deploy-edge.yml`, `.github/workflows/edge-local.yml` | DevOps |
| The flip checklist in `docs/deployment-and-operations.md` | DevOps |
| `tests/Unit/DeployWorkflowsTest.php` | DevOps |
| `edge/src/**` (Worker, Durable Objects, outbound handlers) | Coder2 |
| PHP modules, `migrations/`, the edge driver and the storage adapters | Coder2 |
| This ADR | Coder2 |

A change that touches the other side's files is agreed first and reviewed by that owner.

## Opening the storage gate

`EDGE_STORAGE_READY` is set to `true` only when all of these hold:

1. P4-2 to P4-5 are merged.
2. The manual `edge-local` workflow is green with D1 and R2 (local), including a backup round trip between SQLite
   on Docker and the edge.
3. A preview D1 and R2 pass the smoke test and the guard cases (all-or-nothing batches, `expect` and `assert`
   aborts, concurrent guarded adds, 150 `Ref`s, 101 parameters refused).
4. The one-time steps are done. These are owned by DevOps and Stathis, not by any PR:
   - create the D1 database and the R2 bucket in the EU jurisdiction (DevOps);
   - set the Worker secrets `APP_PASSWORD` (Stathis) and `APP_KEY` (DevOps), with `wrangler secret put`, never
     through GitHub;
   - add the deploy token to the `deploy-edge` environment;
   - set the repository variable `EDGE_STORAGE_READY=true`.

## Consequences

- Self-hosted Docker is unchanged: PDO, SQLite or PostgreSQL, and `DATA_DIR`. The unit of work runs there inside a
  real transaction, and the existing suites keep running on both databases.
- Every SQL write path now has two shapes to test: the PDO transaction and the batch. The backend matrix
  (sqlite, pgsql, d1) is mandatory for any PR that touches writes.
- New SQL has to fit the edge: no transaction statements, no more than 100 parameters (`json_each` for lists), no
  `sqlite_*()` functions, and no dropped parent tables in migrations.
- Ids from `lastInsertId` are never used for writes inside a unit. Callers that need an id at once can't run as a
  joined unit.
- On plain D1 the large paths lose single-transaction atomicity and rely on the bookmark, idempotent rebuilds and
  resume tests. In hosted mode they keep it.
- Installation identity and the R2 prefix live only in `edge/src`. A PHP change can't reach another installation's
  data, because PHP has no say in which data it gets.
