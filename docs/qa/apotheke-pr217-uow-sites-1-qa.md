# QA: apotheke PR #217, "refactor(core): move write paths onto the unit of work (P4-2b, 1/N)"

**Verdict: PASS.** Reviewed head `34b6cb7f66f404be159450be6d9b5d8d0fc64606`, 2026-11-01 (EET), full gate:
- same repo, base `main` `5e37a2c` (v3.15.2), 0 behind, 3 commits, 9 files (+343/−39), no migrations;
- CI 20/20 green on the head.

## Checklist

- [x] **`SavedFilters::create()`.** The id comes from a `Ref`. The limit and name are read first as before and asserted again in the unit; messages and codes are unchanged (422 limit, 409 name, 422 empty name or query).
  - A save that lands between the reads and the write is refused with 422/409 on the batch path (tested).
  - With two concurrent clients:
    - SQLite: the PR now gives 422/409, where `main` gave a write-failure 500, because the unit takes the write lock before its reads.
    - PostgreSQL: unchanged from `main` (L-1660).
- [x] **`Favourites::record()`.** One batch: the sequence and the prune are subqueries.
  - Compared with `main` on both DBs: identical per-step sequence and prune trace (ordering and repeat views), `viewed_at` refresh, scope isolation, and the old-view sweep firing at a multiple of 50 only.
  - Concurrent records are equal to or better than `main`: SQLite now waits for the lock instead of failing. PostgreSQL still fails one of two simultaneous first views of the same item, as before (N-1663).
- [x] **`RoleRepository::delete()` / `resetBuiltin()`.** Same rows as `main`: accounts move, the role goes, built-in names and permissions come back, custom roles and `created_at` are untouched.
  - `RoleAdmin::delete()` still runs it inside `atomically()` with the admin hold. In both modes the unit runs inside that transaction (connection: savepoint; local batch: savepoint on the same connection), and an outer rollback undoes it, so nothing escapes the admin lock.
  - On a real D1 there is no outer transaction. That wrapper is listed for part 2.
- [x] **`Stocktakes::start()`.** The lines bind the stocktake's `Ref`.
  - 3,000 lines start in both modes on both DBs. Each statement has ≤7 parameters; D1 counts a batch as one query and does not cap the number of statements.
  - A failing line keeps nothing: no stocktake, no lines, no `uow_refs`/`uow_guard` rows. Home locations per item match `main`.
- [x] **`UnitsOfWork::forSite()`** refuses units on another connection for all four sites (tested for one, probed for all). Batch executors have no connection to compare.
- [x] **`UnitOfWorkSitesTest`** covers both modes on both DBs with identical rows and ids and no leftover helper rows.
  - Dropping `created_at` from the role comparison hides nothing: the converted statements never write it (verified unchanged in both modes). The seeded rows carry the migration's real time.
- [x] **Parity and permissions.** No controller, route or permission check changed, and user-visible messages are identical. Sites are built per request through `Services` (not persistent in worker mode), with no static state.
- [x] **Deferred sites.** Every remaining `Writes::transaction`/`atomically`/`transBegin` call site is in the body and HANDOFF lists (restore and backup after N-1644).
- [x] **Suites:** SQLite 458 OK (29 skipped, 1 incomplete; PostgreSQL-only and environment tests); own PG17 cluster with password auth, 458 OK (2 skipped).
- [x] **Tooling:** lint and deptrac clean (0 violations), routes up to date, gitleaks 8.30.1 on `main..pr217`: no leaks.
- [x] **Mutations: 24 on the five sites and `forSite()`, 18 killed.**
  - Killed: each guard dropped or off by one, guard status and key, sequence arithmetic, role move, delete and reset, stocktake lines, name and status, and the `forSite()` check and its use of the given units.
  - Survivors: the early limit read (equivalent, as stated) and five in L-1661.

## Findings

- **L-1660:** On PostgreSQL, two simultaneous saves still behave as on `main`:
  - same name: the second gets a 500 from the unique index;
  - at the limit: both are saved (limit + 1).
  - The "racing save gets 422/409" claim holds for SQLite and the batch path, not for concurrent PostgreSQL transactions. Not a regression. Reword the claim, or add a hold for PostgreSQL when the guarded sites are designed.
- **L-1661:** Five mutants on converted statements survive every related test; my probes show the code itself behaves as on `main`:
  - the recent-views prune across all scopes;
  - a repeat view not refreshing `viewed_at`;
  - the 1-in-50 sweep (cadence, or the write dropped);
  - a line's home location taken from the stocktake instead of the item.
  - Worth tests in part 2, because the first one would delete other devices' lists.
- **N-1662:** On the batch path the 409 names the name as typed, while the early check names the stored one (casing or spacing can differ).
- **N-1663:** On PostgreSQL, simultaneous views can still share a `seq`, and simultaneous first views of one item fail one write, as on `main`.

## Security

- No new endpoint, route, permission or input. Bound parameters throughout; SQL text built only from constants.
- Guards add write-time checks without removing the early ones. `forSite()` blocks a site writing through another connection than it reads.
- The role delete stays under the admin hold in both local modes.
- No secrets in the diff. Nothing is H or M.

## Verdict

**PASS.** No H/M findings and no decision needed. L-1660, L-1661, N-1662 and N-1663 go to the backlog. Next free ID: 1664.
