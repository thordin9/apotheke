# ADR-002: Edge storage on Cloudflare (D1, Durable Object SQLite and R2)

## Status

Accepted. This is the design for P4 (storage and database adapters). It follows the project owner's own decisions
(QA's finding M-1540 on #203 asked for them to be cited here):

- **2026-10-29:** the project owner chose edge storage (D1 or Durable Object SQLite, plus R2), with Docker
  self-hosting kept.
- **2026-10-31:** the owner chose one Container and one Durable Object per installation, with SQLite, an EU R2 key
  prefix and a D1 control plane.

These replace the 2026-10-22 phase (a Container with Postgres through Hyperdrive, plus R2), which stays as
[plan B](#plan-b-postgres-through-hyperdrive). The binding names and the file ownership below are agreed with
DevOps (#204). Nothing on Cloudflare is created yet. The deploy of `apotheke-api`
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

The phase was first planned with Postgres through Hyperdrive (the 2026-10-22 decision). The P4(a) spike
(2026-10-29) found that Hyperdrive can't be used from inside a Container. This is unsupported, with no date given,
and is tracked upstream in the open issue [cloudflare/containers#97](https://github.com/cloudflare/containers/issues/97).
Hyperdrive can still be bound to the
Worker and relayed to the container through an outbound handler, the same way as `d1.internal`. In that setup each
statement of an interactive transaction costs one relayed round trip while it holds its locks. The spike also
showed that 26 of the 29 write sites it counted fit D1's atomic batches with guards. On that basis the owner chose
the direction in the Status section (the owner's decisions of 2026-10-29 and 2026-10-31).

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
- In hosted mode, every `Installation` Durable Object id comes from the EU-jurisdiction namespace: the Worker calls
  `env.INSTALLATION.jurisdiction("eu")` and then `idFromName()` or `newUniqueId()` on that namespace, never on
  `env.INSTALLATION` itself. The jurisdiction is fixed when the id is created and can't be changed later. An object
  created outside it would need a new id and a data copy. `edge/src` has one helper for this, and a test checks
  that no other code path creates an `Installation` id.
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

- In hosted mode every cookie is host-only. The flipped configuration drops `COOKIE_DOMAIN` (#204), and `edge/src`
  stops forwarding it to the container in hosted mode (P4-5). An empty value already means host-only in
  `Config\Consent`, so this only keeps it explicit. The `__Host-` session cookie never carries a `Domain` anyway
  (#188), and the consent cookie is host-only too.
- `COOKIE_DOMAIN` stays available for a self-hosted single instance whose owner wants one consent answer across
  their own sub-domains.

### Modes in detail

**Hosted, multi-tenant.** One Container and one Durable Object per installation. The Durable Object is the class
`Installation`, SQLite-backed and bound as `INSTALLATION`, with ids from its EU-jurisdiction namespace. The Durable Object's own SQLite storage holds that installation's app data, using the
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

### Plan B: Postgres through Hyperdrive

Plan B is a Container with PostgreSQL through Hyperdrive, plus R2. Because Hyperdrive can't be reached from inside a
Container, it is bound to the Worker and relayed to the container through an outbound handler, so the container
never holds database credentials. The PostgreSQL connection an interactive transaction holds lives in that Worker
relay (its Hyperdrive client), never in the container, and the relay releases it at the end of each request: a
request's transaction never outlives the request. The app needs a driver for that relay but no SQL change, because PostgreSQL
already works on PDO. Plan B applies only if the project owner decides so, for example if:

- the guard cases fail on remote D1 or Durable Object SQLite and can't be fixed in the executor;
- more than about 5 write sites turn out not to fit a batch without weakening their guarantees (the spike's exit
  criterion);
- the Hyperdrive limitation above goes away upstream and the owner reopens the choice.

The executor adapter (decision 2) keeps this switch open, because the PDO path stays complete.

## Bindings

Agreed with DevOps, with the same names as `edge/storage.jsonc` in #204:

| Binding | Kind | Resource | Notes |
|---|---|---|---|
| `DB` | D1 | database `apotheke-api` | created with `--jurisdiction eu` (with a jurisdiction, a location hint is ignored) |
| `STORAGE` | R2 | bucket `apotheke-api-storage` | `"jurisdiction": "eu"`, private, no public access |
| `INSTALLATION` | Durable Object namespace | class `Installation`, exported from `edge/src` | SQLite-backed, migration tag `v2` (`new_sqlite_classes`); ids from `jurisdiction("eu")` |

The existing binding `APOTHEKE` (class `ApothekeContainer`, migration `v1`) stays as it is. The repository's deploy
guard refuses `env` sections and a second wrangler config, so the storage bindings (`DB`, `STORAGE`, and
`INSTALLATION` with its `v2` `new_sqlite_classes` migration) live in a fragment, `edge/storage.jsonc`. The deploy
job merges it into the runner's copy of `wrangler.jsonc` (`scripts/edge-storage-config.mjs`), and only when
`EDGE_STORAGE_READY` is `true`. `edge/wrangler.jsonc` itself stays unchanged, so the default config, the pull request
dry run and `wrangler dev` don't change. A deploy with the merged config fails until `edge/src` exports
`Installation` (P4-3, wired in P4-5). Once it does, DevOps adds a dry run of the merged config to the pull request
check job. No account id, database id or secret is ever written to the repository: the
database is named `apotheke-api`, and the deploy supplies its id from a repository variable.

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

## The transaction sites

On `main` (v3.9.1) there are **34 transaction sites**: 30 `Writes::transaction` call sites in 20 files, plus 4 that
open a transaction directly.

- The spike counted 29 `Writes::transaction` sites in 19 files. Three of them are wrappers:
  `UserRepository::atomically()`, `ItemRepository::atomically()` and `TaxonomyRepository::tx()`.
- The CSV import job (#185) has since added a thirtieth, `Imports::commitRows()`. It already runs as one small
  transaction per row with an idempotent marker, so it maps to one batch per row.

Each `Writes::transaction` site gets one strategy:

| Strategy | What it means | Sites |
|---|---|---|
| Batch | The writes as they are, one batch | role moves and resets, attachments, item delete, favourites (sequence and prune as subqueries), the import job's rows |
| Ref or read-back | A ULID `Ref` or a natural-key upsert in place of `lastInsertId`, then one batch | first admin, tags (`ON CONFLICT (name_key)`), stocktake start, saved filters, taxonomy create |
| Guard | A hold first, then `expect` n changed rows, an `assert` on a condition, or a version compare-and-swap | admin and role changes, item save, photo add, edit, reorder and actions, merge, quantity, undo, lending, settings rewrite, stocktake ticks and finish, taxonomy moves |
| Compensating work | Can't be one batch. Run in chunks with an idempotent retry and a compensating step | backup restore, search index rebuild, bulk actions on very large selections |

The four direct transactions map as follows:

| Site | PDO (Docker) | D1 and Durable Object SQLite |
|---|---|---|
| `Imports::run()` (`Imports.php:369`): the HTML import, which holds the import and then writes its rows (commit) or rolls them back (preview) | unchanged | The commit runs as per-row batches with the marker, as the job does. The preview validates without writing: a dry run, or a scratch in-memory SQLite. |
| `CsvExport.php:115`: a read snapshot for the export cursor (PostgreSQL only) | keeps its `REPEATABLE READ, READ ONLY` snapshot | A read inside `transactionSync` (Durable Object), or one batch of reads (D1). If one batch would pass D1's limits, the export reads in id-ordered chunks, which gives up the single snapshot on D1. |
| `Backups.php:347`: a read snapshot for the backup cursor (PostgreSQL only) | keeps its `REPEATABLE READ, READ ONLY` snapshot | The same as the export. A chunked backup on D1 runs behind the maintenance flag, so no write lands between its chunks. |
| `PdoMigrationRunner.php:147` (`BEGIN IMMEDIATE` on SQLite) | unchanged | The edge runner sends each migration as one `exec()` or batch (see [Migrations](#migrations)). |

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

## Request state (FrankenPHP)

The edge image runs FrankenPHP in **classic mode**. `docker/frankenphp/Caddyfile` has a plain `php_server` with no
`worker` directive, so each request starts with fresh PHP state, as under php-fpm. Worker mode
(`app/Config/WorkerMode.php` is in the tree) is a later optimisation, behind its own QA gate.

The storage code is written for worker mode now, so that turning it on later changes nothing:

- **Executor and bridge client:** built per request by `Config\Services` and never listed in
  `WorkerMode::$persistentServices`. They hold no static property, and no request or connection state survives the
  request.
- **Installation context:** PHP holds none (decision 3). The only installation context is in the Worker and the
  Durable Object, which are per installation by construction. Nothing in PHP caches an installation id, a prefix or
  a database handle.
- **Unit of work:** opened and closed inside one request. The registry of the open unit is `Core\Database\UnitsOfWork`,
  one per executor and so per connection (`Config\Services::unitsOfWork()`, per request, with no static state), and
  must be empty at the end of every request. A unit still open then is an error: it is discarded,
  never flushed.
- **Tests:** a state test in the style of ADR-001's filter rules
  (`FilterStateTest`) checks that `Core\Database` and the storage adapters declare no static mutable state. A worker
  test runs two requests through the same process with the CodeIgniter worker reset in between. It checks that the
  second request sees no unit, executor or storage client from the first. Both run in P4-2 and P4-3.

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
   P4-2a is three stacked parts: the executor adapter (`SqlExecutor`, its default `ConnectionExecutor`, `Dialect`
   and the `json_each` fixes), the unit of work on a connection (`UnitsOfWork`, the per-request registry), and the
   batch path (planned: `BatchUnitOfWork`, the `uow_guard` and `uow_refs` migration, the parity test). P4-2b converts
   the sites; until then no call site uses the executor's batch or a unit of work.
   **PostgreSQL parity gate:** every P4-2 part keeps the full PHPUnit suites (root and module) green on SQLite and
   PostgreSQL 17. A parity test also runs each converted site through the unit of work on PDO and through the batch
   executor, and checks that both give the same rows and the same ids on SQLite and on PostgreSQL.
3. **P4-3: edge driver and bridge.** The CodeIgniter `D1` driver, the edge migration dialect, and in `edge/src` the
   outbound handlers and the `Installation` Durable Object class. The local D1 harness cases run in CI under
   `wrangler dev`.
4. **P4-4: storage interface.** `Core\Storage` with a local-disk and an R2 implementation for photos, attachments,
   uploads and backups, moving the remaining direct filesystem users behind it. The Worker-side handler adds the
   installation prefix.
   This includes the chunked restore upload store (#205 and #206, module Backup, `DATA_DIR/uploads/restore/`;
   QA's N-1584). It writes its chunks and manifest to the local filesystem directly today. In P4-4 it goes through
   `Core\Storage`, and its manifest lock moves to the Durable Object (`transactionSync`) or to R2 conditional
   writes. Restore confirm runs synchronously in the request today. On D1 and the Durable Object it will likely
   become a background job on the jobs worker, with progress read from `GET /api/v1/jobs/{id}` (QA's N-1589).
5. **P4-5: hosted wiring.** Durable Object SQLite per installation, host-only cookies in hosted mode, the
   `edge-local` run green with storage, the P4(b) guard cases run again on a preview D1, and the runbook.

## Ownership

| Area | Owner |
|---|---|
| `edge/wrangler.jsonc` (`secrets.required`, vars) and `edge/storage.jsonc` (bindings, jurisdiction) | DevOps |
| `scripts/edge-storage-config.mjs`, `scripts/deploy-scripts.test.mjs` | DevOps |
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
4. `edge/src` creates every `Installation` id through `env.INSTALLATION.jurisdiction("eu")`. The test for this is
   green, and on the preview an `Installation` object is checked to be in the EU jurisdiction before any real
   installation is created. This can't be corrected after the ids exist.
5. The one-time steps are done. These are owned by DevOps and Stathis, not by any PR:
   - create the D1 database and the R2 bucket in the EU jurisdiction (DevOps);
   - set the Worker secrets `APP_PASSWORD` (Stathis) and `APP_KEY` (DevOps), with `wrangler secret put`, never
     through GitHub;
   - add the deploy token to the `deploy-edge` environment, and the D1 database id as a repository variable (never
     in the repository; see #204's flip checklist);
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
