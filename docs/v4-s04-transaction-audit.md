# S0.4 — transaction audit: the 10 `atomically()` / `inUnit()` sites

Phase 0, slice S0.4 of `docs/v4-migration.md` (revision 6). Read-only audit of the PHP application at
`experimental-v4` @ `1146d86f` (v3.72.0 code). No v4 code yet — S0.2 is blocked on registry access, so this
classifies the existing sites against the batch contract before any of them is ported.

The contract being audited against is fixed by the plan: the unit of work is **plan-then-flush** on all three
backends (D1 `batch()`, one `/db/execute?transaction` request on rqlite, `transactionSync` locally), the batch is
sent in one shot, and rule 10 (D10) wraps every write unit in a `_write_log` idempotency envelope.

## What already exists, and why it matters

The PHP app does **not** rely on interactive transactions for these sites. Since ADR-002 every write goes through
a `UnitOfWork` that reads first and queues its writes, and two implementations exist:

- `ConnectionUnitOfWork` — a real transaction (self-host SQLite/PostgreSQL today).
- `BatchUnitOfWork` (`modules/Core/Database/BatchUnitOfWork.php`) — **one batch, no BEGIN/SAVEPOINT**, used today
  on D1 and on Durable Object SQLite. It already implements plan-then-flush with:

  | Mechanism | Where | What it does |
  |---|---|---|
  | `Ref` (ULID) + `uow_refs` | `insert()` returns a `Ref`; the batch writes `ulid → id` right after the insert and reads the ids back with one list parameter at the end | an id created by an earlier statement, used by a later one, without seeing an intermediate result |
  | `hold()` first | `flush()` sorts holds to the front | the legacy "write first" lock, expressed as a statement |
  | guards | `expect`→`uow_guard`, `assert(condition)` | an invariant re-checked **inside** the batch; the whole batch fails, nothing commits |
  | failed-step attribution | `BatchUnitOfWork::failedStep()` replays prefixes | turns "the batch failed" into the caller's `UserError` |
  | `afterWrites()` | run after the flush | side effects that need the commit |

So the question per call site is not "can a transaction survive" but "is the invariant a statement in the batch".
PHP already answers that for the D1 hosted path, and `tests/Modules/Core/UnitOfWork*Test.php` plus
`tests/Modules/Core/D1HostedWritesTest.php` exercise it in both modes. The v4 port inherits the model; the audit
below says what each site needs when it moves.

## The ten sites

Line numbers are at `1146d86f`. "One batch" means it already flushes as a single atomic request today and ports
as-is behind the `_write_log` envelope.

| # | Site | Where | Unit of work | Verdict |
|---|---|---|---|---|
| 1 | `ItemRepository::saveRef()` (item create/update, tags, cover, photo renumber) | `modules/Catalog/Items/ItemRepository.php:156` | reads item/tags/photos/taxonomy names first; `assert` item still exists; `Ref` for a new item; history rows queued; cover removed and `position` renumbered as statements | **one batch** |
| 2 | `ItemRepository::delete()` | `…/ItemRepository.php:320` | `assert EXISTS(items)`; history row; `DELETE FROM items` (children cascade); prune unused tags | **one batch** |
| 3 | `ItemRepository::atomically()` — the caller's wrapper | `…/ItemRepository.php:267` | delegates to `Writes::atomic()`: on D1 the callback may open **one** unit and nothing else | **one batch**, with the "one inner unit" rule kept |
| 4 | `ItemRepository::inUnit()` — the caller's join | `…/ItemRepository.php:295` | `units->run('items', $fn)`: a `save()` inside joins the open batch | **one batch** |
| 5 | `Imports::hostedLine()` (one CSV row) | `modules/ImportExport/Imports.php:566` | per-row batch: the `import_rows` marker with `expect 1` ("another run imported this line" → 409), then the row's save and loan via `$later($uow)` | **one batch**; the marker is already rule 10's pattern |
| 6 | `UndoApply::apply()` | `modules/Catalog/Items/UndoApply.php:40` | token delete as the first step with `expect 1` → 410 on a second apply; then N restores (items, photos, attachments, tags) with nested saves; prune tags | **one batch**; needs a payload-size cap (#see below) |
| 7 | `ItemController::save()` (POST `/item`) | `modules/Catalog/Controllers/ItemController.php:195` | `atomically()` around inline taxonomy creation **and** the save; on hosted it refuses inline new location/category with 422 because that would be two units | **one batch after a rewrite**: taxonomy creation must return a `Ref` the item insert binds. Until then the 422 refusal stays |
| 8 | `Duplicate::copy()` | `modules/BulkMerge/Duplicate.php:47` | `atomically()` around `save()` and the photo rows; on hosted it refuses copying photos with 501 because `PhotoRepository::insert()` (`modules/Catalog/Items/PhotoRepository.php:72`) takes an `int` | **one batch after a rewrite**: let the photo insert take `int\|Ref`; then the 501 goes away. Until then the 501 stays |
| 9 | `BulkService::run()` (bulk move/category/tag/delete over a selection) | `modules/BulkMerge/BulkService.php:132` | the whole selection is one unit on hosted; each item's `save()` joins; per-item `expect 1` guard on delete ("selection changed", 409) | **one batch**; statement count grows with the selection — needs the cap below |
| 10 | `UserRepository::atomically()` / `underActiveAdminGuard()` | `modules/Auth/UserRepository.php:163`, `:179` | wrapper + the `users` unit scope that admin writes run in | **one batch for the wrapper**; one caller's invariant is not yet a statement (#see below) |

### The two rewrites, in full

**#7 — inline taxonomy.** Today: `$vals[col] = (int)$tax->create(...)['id']` then `save()`; on D1 that is two
units, so hosted refuses with 422. Port: `TaxonomyRepository::create()` returns a `Ref` when a unit is open, the
item row binds it, and the batch's `uow_refs` tail resolves the id for the response. `INSERT … SELECT
last_insert_rowid()` is the fallback shape if the port prefers no `uow_refs` table for this. Either way it is
SQL-expressible; the hosted 422 is a **behaviour improvement**, not a regression, and needs PM sign-off because
the contract currently promises that refusal on hosted.

**#8 — duplicate with photos.** Same shape: `PhotoRepository::insert()` (`modules/Catalog/Items/PhotoRepository.php:72`) takes an `int`, so the copies cannot be
written in the same batch as the new item. Port: accept `int|Ref`. The hosted 501 then becomes a working
duplicate, again a behaviour improvement needing sign-off.

### The one invariant that is not a statement yet

`RoleAdmin::updateLocked()` / `deleteLocked()` (`modules/Auth/RoleAdmin.php:131`, `:170`) compute
`managers()` in PHP — role list plus active users — and then write, relying on the **unit lock** to serialise.
On a connection that is correct. On a batch backend there is no lock at all (`UnitsOfWork::run()`: "on a batch
executor the unit's guards hold the checks instead"), and `UserAdmin::setRole()`/`delete()` do carry their guard
in SQL (`… OR EXISTS (SELECT 1 FROM users WHERE role = 'admin' AND status = 'active' AND id <> ?)`), so the
asymmetry is visible in the code. Two concurrent role edits can therefore both pass the manager check on D1
today, and would on rqlite too, where there is no DO serialisation either.

Port requirement: express "at least one account can still manage users" as a batch `assert` — a single
`EXISTS` over `users` joined to `roles` with the post-change permission set — and drop the reliance on the
scope key. This is still **one batch**; it is the only place in the ten where correctness needs a new statement
rather than a mechanical port.

### What is not expressible: nothing

No call site needs read-after-write inside one unit. The two places that need a value produced by an earlier
statement (#7, #8) are the `Ref` case, which the batch path already implements. **No escalation to Stathis
required for atomicity.**

## Cross-cutting requirements the port carries from this audit

1. **The envelope is per unit, not per call site.** Rule 10 wraps the flush: `INSERT INTO _write_log (id,
   created_at) VALUES (?, ?)` as the batch's first statement, PK conflict → mapped to success, for single-statement
   writes too. `#5`'s `import_rows` marker keeps its own meaning (resume point) and coexists.
2. **Guards, not locks.** Every invariant that today rests on `units->run()`'s scope key must become an `assert` /
   `expect` in the batch. #10 is the only one found; the port should be written so a lock-only check is a review
   failure.
3. **Statement budget.** A batch is bounded by the engine, not by the code: D1 documents 100 KB per statement,
   2 MB per row, 30 s per query (ADR-002), and rqlite's HTTP body limit is unknown until S0.8 measures it.
   `#9` (selection size) and `#6` (undo payload, already truncated to R2 above ~512 KB) can generate large
   batches. The port needs a per-unit statement cap and a defined refusal (`422`, "select fewer items") rather
   than a chunked retry, because chunking silently drops all-or-nothing.
4. **Failed-step attribution.** On rqlite the per-statement results already say which statement failed, so
   `BatchUnitOfWork::failedStep()`'s prefix replay can be dropped there; on D1 it stays. Verify in S0.8.
5. **Post-flush side effects stay post-flush.** `dropAfterCommit` (image files) and `afterWrites()`
   (`HostedAdminReset`) must run after the batch returns, never "inside" it — on a batch backend they were never
   inside the transaction.
6. **Proof, not claim.** This document classifies; G3's write-replay test (same recorded write sequence on D1,
   local SQLite and rqlite) is what proves it.

## Open items handed to the PM

- Sign-off on the two behaviour improvements (#7, #8): on v4, hosted would gain inline taxonomy creation and
  photo duplication that hosted refuses today with 422/501. Keeping the refusals is cheaper; lifting them needs
  the rewrite above and changes a documented contract.
- The "10 sites across 7 files" count maps to the entry points above. If the plan meant specific internal
  `units->run()` lines instead, say which and this table is corrected — the other `units->run()` sites
  (`PhotoRepository`, `Attachments`, `TaxonomyRepository`, `Stocktakes`, `Loans`, `Settings`) are the same
  batch shape and are ported with their modules.
