# Hand-off: the CodeIgniter 4 app

A one-screen status of the CodeIgniter 4 port. Every pull request into `main` updates this page; keep it to about one
screen and move detail to the [hand-off log](history/handoff-log.md). Plan and module rules:
[PLAN.md](plans/feature12-ci4/PLAN.md), [ADR 001](adr/001-codeigniter4.md), [ADR-002](adr/002-edge-storage.md),
[AGENTS.md](../AGENTS.md).

## Current state

- `main` is the CI4 app (v3.0.0 and later; latest release v3.20.0). The 2.6.x security line ships from the branch
  `old` for 90 days after 3.0.0. Only `main`, `old` and open PR heads remain.
- Every module is ported and byte-identical to legacy where legacy has the page (parity gate, step 16 done); module
  notes are in the [log](history/handoff-log.md#current-state).
- P4 (edge storage, [ADR-002](adr/002-edge-storage.md)) is the top priority. P4-3 PR 1 (#235, D1 HTTP executor) and
  PR 2 (#236, edge handlers) are merged; v3.20.0.

## Open pull requests

- P4-3 PR 3 (`feat/p4-3-d1-migrations`): the D1 migration dialect. Also in flight: a small fix to the edge
  error bodies. Later P4-3 PRs: stand-in fidelity and stocktake batch open guard, CI d1 cell. Left for a later PR:
  BulkService, SearchIndex, Imports. Backlog: N-1747..N-1751, N-1758, N-1761..N-1763, N-1780, N-1782.

Every merged PR, with its notes: [log, Pull requests](history/handoff-log.md#pull-requests).

## Next steps

1. Land the edge error-body fix and P4-3 PR 3 (skip connection PRAGMAs / `user_version` / `VACUUM INTO`; `DB.exec`
   for multi-statement heredocs; never DROP parent), then stand-in allow-list fidelity, the stocktake batch open
   guard, and the CI wrangler-local d1 cell (remaining N-1640).
2. P4-4 (R2 / `Core\Storage`) to P4-5 (hosted wiring). DevOps owns the bindings and the `EDGE_STORAGE_READY`
   flip checklist (#204, #208); N-1442 follows with P4.
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
