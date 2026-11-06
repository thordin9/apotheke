# Hand-off: the CodeIgniter 4 app

A one-screen status of the CodeIgniter 4 port. Every pull request into `main` updates this page; keep it to about one
screen and move detail to the [hand-off log](history/handoff-log.md). Plan and module rules:
[PLAN.md](plans/feature12-ci4/PLAN.md), [ADR 001](adr/001-codeigniter4.md), [ADR-002](adr/002-edge-storage.md),
[AGENTS.md](../AGENTS.md).

## Current state

- `main` is the CI4 app (v3.0.0 and later; latest release v3.24.0). The 2.6.x line has security support from `old`
  for 90 days after 3.0.0. Only `main`, `old` and open PR heads remain.
- Every module is ported, with legacy parity at step 16; detailed module notes stay in the
  [log](history/handoff-log.md#current-state).
- P4 (edge storage, [ADR-002](adr/002-edge-storage.md)) is the priority. P4-3 is merged through #240;
  P4-4 PR 1 (#241) added a tested R2 storage with a tested `R2Storage` implementation of the existing `Core\Storage` interface.
  PR 2 (#242) added scoped Worker R2 operations, resumable bounded multipart, conditions, and explicit
  opt-in construction. The maximum escaped multipart marker-cap fix (#244) and the marker escaping fix (#246) are merged.
  PR 3a (#245) is merged: backup export reads through storage, and a remote manifest's bytes and version come from one object read.
- PDO/self-hosting and default `LocalStorage` stay the filesystem restore. Explicit R2 keeps chunk payloads, the
  restore manifest and the lease on that same storage. The lease is taken before a chunk or the catalog restore and
  renewed before each later write; losing it stops the next write. The database stage lock is separate and does not
  cover this lease. A chunk is named in the manifest before its bytes, and the upload id is indexed before the
  manifest, so the next read or sweep removes that crash leftover. Design-B staged restore is unchanged. PR 4 is
  still the background restore and its progress. Remaining R2 object reads stay bounded by the current bridge
  (they are not yet streaming reads). `EDGE_STORAGE_READY` stays off.


## Open pull requests

- P4-4 PR3b [#247](https://github.com/thordin9/apotheke/pull/247) (`cursor/p4-4-restore-storage-e9c8`): explicit R2 chunked restore uses one storage for payloads, conditional manifests and leases. The lease is renewed for the whole mutation and a lost hold cannot write again. Local disk behavior is unchanged. No readiness flip.
- **Mandatory before hosted wiring:** PM owns and must implement an orphan multipart marker/upload sweep or lifecycle rule. This slice does not change the account or add that sweep.
- Do not treat restore conversion as hosted wiring. R2 remains explicitly opt-in; PDO/SQLite with LocalStorage remains the default. A background restore job is still PR 4.
- N-1786 stays deferred. N-1794 and N-1795 stay separate. L-1811 and N-1812 stay tracked for P4-3/CI follow-up.
- Older numbered backlog: N-1747..N-1751, N-1758, N-1761..N-1763, N-1780, N-1782.

Merged PR notes: [log, Pull requests](history/handoff-log.md#pull-requests).

## Next steps

1. Finish P4-4 PR 4 (background restore and progress), with no readiness flip; then P4-5 hosted wiring. The orphan multipart sweep stays before that wiring.
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
