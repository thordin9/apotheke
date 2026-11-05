# Hand-off: the CodeIgniter 4 app

A one-screen status of the CodeIgniter 4 port. Every pull request into `main` updates this page; keep it to about one
screen and move detail to the [hand-off log](history/handoff-log.md). Plan and module rules:
[PLAN.md](plans/feature12-ci4/PLAN.md), [ADR 001](adr/001-codeigniter4.md), [ADR-002](adr/002-edge-storage.md),
[AGENTS.md](../AGENTS.md).

## Current state

- `main` is the CI4 app (v3.0.0 and later; latest release v3.18.2). The 2.6.x security line ships from the branch
  `old` for 90 days after 3.0.0. Only `main`, `old` and open PR heads remain.
- Every module is ported and byte-identical to legacy where legacy has the page (parity gate, step 16 done); module
  notes are in the [log](history/handoff-log.md#current-state).
- P4 (edge storage, [ADR-002](adr/002-edge-storage.md)) is the top priority. P4-1 and P4-2a are merged; P4-2b part 1
  (#217), #219, part 2 (#221, item-history sites), #220 (batch allow-list, v3.15.5), restore staging (#223 / #227 /
  #229), the attach/delete and PG batch lock fixes (#230, #231, v3.18.2) and the remaining write sites (#232) are
  merged. Part 2b of restore is done.

## Open pull requests

- P4-2b remaining sites part 2 (`feat/uow-remaining-sites-2`): PhotoRepository, taxonomy default/delete/move,
  Stocktake tick/finish, Settings rewrite, MergeService, RoleAdmin/UserAdmin (via the role and user repositories;
  active-admins unit lock, no row lock). CsvExport stays a read-only snapshot (not a unit). Left for a later PR:
  BulkService, SearchIndex, Imports. N-1747..N-1751 and N-1758, N-1761..N-1763 stay in the backlog; N-1759/N-1760
  go with P4-3.

Every merged PR, with its notes: [log, Pull requests](history/handoff-log.md#pull-requests).

## Next steps

1. Finish this part-2 sites PR (full gate); then BulkService, SearchIndex and Imports (with N-1758).
2. P4-3 (D1 driver and bridge, with N-1640; N-1759 items CHUNK_BYTES/row cap and N-1760 FTS5 shadow-table skip
   in the D1 migrator) to P4-5 (R2). DevOps owns the bindings and the `EDGE_STORAGE_READY` flip checklist
   (#204, #208); N-1442 follows with P4.
3. Backlog on hold during P4: L-1520, N-1502, N-1503, N-1482. Older numbered items:
   [log, Next steps](history/handoff-log.md#next-steps).

## Lanes

- Coder: CI, Docker, CLI port, syncs, the docs; Coder2: auth/accounts, backup and restore, import/export,
  P4 restore and unit-of-work sites; DevOps: Cloudflare bindings and deploy. History:
  [log, Lanes](history/handoff-log.md#lanes).
- Shared files (`Config/Routes.php`, `CHANGELOG.md`, `deptrac.yaml`, `Config\Services`, the AGENTS.md module graph):
  append your own block in a small hunk; do not reorder or reformat other blocks. Merge `main` in (no rebase)
  before pushing when it has moved.

## Links

- [Hand-off log](history/handoff-log.md): module notes, every PR, lanes, numbered next steps and the
  [Tracked](history/handoff-log.md#tracked) differences where CI4 is stricter than legacy.
- [Database](database.md) (lock order), [Testing](testing.md), [Releasing](releasing.md), [API](api.md),
  [Deployment and operations](deployment-and-operations.md).
- Playwright on CI4: the `CI4_UNPORTED` skip list (`tests/visual/fixtures.ts`) is empty.
