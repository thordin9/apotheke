# Hand-off: the CodeIgniter 4 app

A one-screen status of the CodeIgniter 4 port. Every pull request into `main` updates this page; keep it to about one
screen and move detail to the [hand-off log](history/handoff-log.md). Plan and module rules:
[PLAN.md](plans/feature12-ci4/PLAN.md), [ADR 001](adr/001-codeigniter4.md), [ADR-002](adr/002-edge-storage.md),
[AGENTS.md](../AGENTS.md).

## Current state

- `main` is the CI4 app (v3.0.0 and later; latest release v3.21.0). The 2.6.x security line ships from the branch
  `old` for 90 days after 3.0.0. Only `main`, `old` and open PR heads remain.
- Every module is ported and byte-identical to legacy where legacy has the page (parity gate, step 16 done); module
  notes are in the [log](history/handoff-log.md#current-state).
- P4 (edge storage, [ADR-002](adr/002-edge-storage.md)) is the top priority. P4-3 PR 1 (#235, D1 HTTP executor) and
  PR 2 (#236, edge handlers) and PR 3 (#238, D1 migrations) are merged; v3.21.0.

## Open pull requests

- P4-3 PR 4 (`feat/p4-3-standin-stocktake`): the local PostgreSQL executor rejects settings/catalog writes and
  server-side objects D1 cannot run, PRAGMA/replace/alter and upload-sweep regressions, and an atomic conditional
  stocktake start. The D1 migration adapter handles nested trigger ends, per-mode synthetic reads, a drop later in
  a malformed trigger, and sanitized stand-in failures. N-1786 remains deferred; N-1794 and N-1795 are separate.
  Next is PR 5: the CI wrangler-local d1 cell and the rest of N-1640. Older backlog: N-1747..N-1751, N-1758,
  N-1761..N-1763, N-1780, N-1782. BulkService, SearchIndex and Imports remain for later P4-3 work.

Every merged PR, with its notes: [log, Pull requests](history/handoff-log.md#pull-requests).

## Next steps

1. Land P4-3 PR 4 (stand-in fidelity, upload-sweep proof and stocktake's conditional batch insert), then the CI
   wrangler-local d1 cell and remaining N-1640 work. N-1786 stays a deferred follow-up, not a hold.
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
