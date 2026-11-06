# Hand-off: the CodeIgniter 4 app

A one-screen status of the CodeIgniter 4 port. Every pull request into `main` updates this page; keep it to about one
screen and move detail to the [hand-off log](history/handoff-log.md). Plan and module rules:
[PLAN.md](plans/feature12-ci4/PLAN.md), [ADR 001](adr/001-codeigniter4.md), [ADR-002](adr/002-edge-storage.md),
[AGENTS.md](../AGENTS.md).

## Current state

- `main` is the CI4 app (v3.0.0 and later; latest release v3.21.1). The 2.6.x line has security support from `old`
  for 90 days after 3.0.0. Only `main`, `old` and open PR heads remain.
- Every module is ported, with legacy parity at step 16; detailed module notes stay in the
  [log](history/handoff-log.md#current-state).
- P4 (edge storage, [ADR-002](adr/002-edge-storage.md)) is the priority. P4-3 is merged through #240;
  P4-4 PR 1 starts R2 storage with a tested `R2Storage` implementation of the existing `Core\Storage` interface.
  Its bounded bridge accepts application-relative keys only and its tests mock transport. The Worker owns prefixes.
- PDO/self-hosting and default `LocalStorage` construction are unchanged. PR 1 is not wired to a live R2 service.
  PR 2 adds Worker R2/list/conditional operations and explicit opt-in construction; PR 3 migrates direct filesystem
  backup/restore callers, with manifest locking/conditional writes and real race tests. PR 4 takes up background restore.


## Open pull requests

- P4-4 PR 1 (`feat/p4-4-r2-foundation`): the storage adapter and internal object protocol only.
- N-1786 stays deferred. N-1794 and N-1795 stay separate. L-1811 and N-1812 stay tracked for P4-3/CI follow-up.
- Older numbered backlog: N-1747..N-1751, N-1758, N-1761..N-1763, N-1780, N-1782.

Merged PR notes: [log, Pull requests](history/handoff-log.md#pull-requests).

## Next steps

1. Finish P4-4 PRs 2–4, with no readiness flip; then P4-5 hosted wiring.
2. DevOps owns the bindings and the `EDGE_STORAGE_READY` checklist (#204, #208); N-1442 follows with P4.
3. Backlog on hold during P4: L-1520, N-1502, N-1503, N-1482. Others:
   [log, Next steps](history/handoff-log.md#next-steps).

## Lanes

- Coder: CI, Docker, CLI port, syncs, docs; Coder2: auth/accounts, backup/restore, import/export, P4 restore and
  unit-of-work sites; DevOps: Cloudflare bindings and deploy. History: [log, Lanes](history/handoff-log.md#lanes).
- Shared files (`Config/Routes.php`, `CHANGELOG.md`, `deptrac.yaml`, `Config\Services`, AGENTS module graph):
  append your own block in a small hunk; do not reorder other blocks. Merge main (no rebase) before a moved push.

## Links

- [Hand-off log](history/handoff-log.md): every PR, module notes, lanes, next steps and
  [tracked differences](history/handoff-log.md#tracked).
- [Database](database.md), [Testing](testing.md), [Releasing](releasing.md), [API](api.md),
  [Deployment and operations](deployment-and-operations.md).
- CI4 Playwright: the `CI4_UNPORTED` skip list (`tests/visual/fixtures.ts`) is empty.
