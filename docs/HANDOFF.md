# Hand-off: the CodeIgniter 4 app

A one-screen status of the CodeIgniter 4 port. Every pull request into `main` updates this page; keep it to about one
screen and move detail to the [hand-off log](history/handoff-log.md). Plan and module rules:
[PLAN.md](plans/feature12-ci4/PLAN.md), [ADR 001](adr/001-codeigniter4.md), [ADR-002](adr/002-edge-storage.md),
[AGENTS.md](../AGENTS.md).

## Current state

- `main` is the CI4 app (v3.0.0 and later; latest release v3.68.0). The 2.6.x line has security support from `old`
  for 90 days after 3.0.0. Only `main`, `old` and open PR heads remain.
- Every module is ported, with legacy parity at step 16; detailed module notes stay in the
  [log](history/handoff-log.md#current-state).
- P4 edge storage ([ADR-002](adr/002-edge-storage.md)) is in use on the hosted API (flip 2026-11-12). Backup restore
  on d1 is in: each restore keeps the catalog before it under the installation's `backups/`. On d1, duplicating an
  item with its photos, and adding a new location or category in the same step as saving the item, stay refused. Do
  not move real data onto the hosted installation until a backup has been tested by a round trip there.
- The lifecycle Workflow (`apotheke-lifecycle`) runs on `apotheke-api`. The operator console Worker binds it and
  starts a confirmed job; it does not host the Workflow.
- The operator console is live at `console.getapotheke.com`, behind Cloudflare Access. Merged notes:
  [log, Pull requests](history/handoff-log.md#pull-requests).
- Operator console writes: both Worker configs declare `OPS_WRITES_ENABLED=1`; the approved deploy (apotheke-api
  first, then apotheke-ops) turns on the implemented write routes and lifecycle confirmations. Access, CSRF and the
  primary refusals stay. `OPS_PROVISIONING=0` and `LIMITS_ENABLED=0` are unchanged; the ops deploy config accepts
  writes `0` or `1` for rollback and still refuses provisioning or limits. See
  [deploying the console](operator-console.md#deploying-the-console).

## Open pull requests

- Enable operator console writes in production, and drop the unused `AUTOFILL` binding from `apotheke-ops`
  (branch `cursor/lifecycle-followups`, [#362](https://github.com/thordin9/apotheke/pull/362)).
- Repo documentation sweep (branch `docs/repo-sweep`): docs-only updates off v3.68.0. No pull request yet.

## Next steps

1. Land the open pull requests above.
2. Self-host storage bind-and-create steps on [Self-hosting on Cloudflare](self-host-cloudflare.md#storage) are still
   to be written. Planned operator-console endpoints that are not served yet stay in
   [Operator console](operator-console.md#planned).
3. Older backlog: [log, Next steps](history/handoff-log.md#next-steps).

## Lanes

- Coder: CI, Docker, CLI port, syncs, docs; Coder2: auth/accounts, backup/restore, import/export, P4 restore and
  unit-of-work sites; DevOps: Cloudflare bindings and deploy. History: [log, Lanes](history/handoff-log.md#lanes).
- Shared files (`Config/Routes.php`, `CHANGELOG.md`, `deptrac.yaml`, `Config\Services`, AGENTS module graph):
  append your own block in a small hunk; do not reorder other blocks. Merge main (no rebase) before a moved push.

## Links

- [Hand-off log](history/handoff-log.md): every PR, module notes, lanes, next steps and
  [tracked differences](history/handoff-log.md#tracked).
- [Database](database.md), [Testing](testing.md), [Releasing](releasing.md), [API](api.md),
  [Deployment and operations](deployment-and-operations.md), [Operator console](operator-console.md),
  [Workers migration plan](future/workers-migration-plan.md) (a future option, not scheduled).
- CI4 Playwright: the `CI4_UNPORTED` skip list (`tests/visual/fixtures.ts`) is empty.
