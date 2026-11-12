# Hand-off: the CodeIgniter 4 app

A one-screen status of the CodeIgniter 4 port. Every pull request into `main` updates this page; keep it to about one
screen and move detail to the [hand-off log](history/handoff-log.md). Plan and module rules:
[PLAN.md](plans/feature12-ci4/PLAN.md), [ADR 001](adr/001-codeigniter4.md), [ADR-002](adr/002-edge-storage.md),
[AGENTS.md](../AGENTS.md).

## Current state

- `main` is the CI4 app (v3.0.0 and later; latest release v3.28.0). The 2.6.x line has security support from `old`
  for 90 days after 3.0.0. Only `main`, `old` and open PR heads remain.
- Every module is ported, with legacy parity at step 16; detailed module notes stay in the
  [log](history/handoff-log.md#current-state).
- P4 (edge storage, [ADR-002](adr/002-edge-storage.md)) is the priority. P4-3 is merged through #240;
  P4-4 PR 1 (#241) added a tested R2 storage with a tested `R2Storage` implementation of the existing `Core\Storage` interface.
  PR 2 (#242) added scoped Worker R2 operations, resumable bounded multipart, conditions, and explicit
  opt-in construction. The maximum escaped multipart marker-cap fix (#244) and the marker escaping fix (#246) are merged.
  PR 3a (#245) is merged: backup export reads through storage, and a remote manifest's bytes and version come from one object read.
  PR 3b (#247) is merged: an explicit R2 restore keeps its chunks, manifest and lease on that storage, and the catalog fence covers the settings import, undo prune, and map pointers.
  PR 4 (#248) is merged: on a batch-only database, chunked confirm is one `restore.confirm` job.
  The orphan multipart sweep (#249) is merged as an uncalled helper. It does not create the account lifecycle rule, and the hosted gate stays closed.
  P4-5 piece 1 (#251) is merged: R2 is selected only from the merged storage bindings, never a var. Piece 2a (#252, passed QA) exports the `Installation` Durable Object and serves `d1.internal` from its SQLite; the storage mode needs `STORAGE`, `DB` and `INSTALLATION` together, any other mix refuses to start, and both bridges are closed outside it.
  Piece 2b (branch `feat/p4-5-db-driver`; for its review state, see the open PRs on GitHub) adds the CodeIgniter driver `Apotheke\Core\Database\D1` over `d1.internal` (chunk 1). It reads a statement as a read or a write on the same comment-stripped text as the statement guard. Chunk 2-i selects it in storage mode: the Worker sets `DB_DRIVER=d1` with `STORAGE_BACKEND=r2` in the container env, PHP refuses `d1` without R2 and refuses to serve when storage mode is set without `d1` (so it cannot fall back to SQLite on the container disk), the SQL executor is the batch-only bridge, and the schema bootstraps on the object under a lease. A callable migration's writes go in one batch with its schema_migrations row, so a failed step leaves the schema unchanged and the lease is released. Chunk 2-ii-A moves item create/edit/delete, account creation, undo and the search rebuild to one batch per step on `d1` (the driver refuses a unit inside a transaction, a second unit, and a direct write beside a unit); the item form's inline new location or category (422) and duplicating with photos stay refused on `d1`. Photos, originals, attachments, imports and brand files follow `StorageFactory` (branch `feat/p4-5-r2-files`; for its review state, see the open PRs on GitHub): storage mode uses R2, and a selected R2 backend that cannot be used is an error, not a disk fallback. Storage preflight skips photo, thumb and originals directories when the backend is R2, and still creates the sessions directory. When `DB_DRIVER=d1`, PHP sessions are stored in the CI4-only `sessions` table on the installation database; PDO and the default keep the file handler. A session write from a request whose claim another request has taken stores nothing and returns normally, so the session closes and the next request on the worker is unaffected (L-1894).
  On `d1` the restore refusals are 501 before anything is stored (on `/api` the problem code `hosted.unavailable` with the message; any other 5xx stays generic), and the restore section shows "Not available on hosted yet." instead of its actions. Self-hosting shows them as before.
  Bulk editing runs on `d1`: the whole selection (at most 500 items) is one unit of work, one batch, so a failed batch writes nothing and the same request resumes; a delete of an item that changed or went meanwhile fails the batch with 409. The undo token is stored in that same batch; a selection whose snapshot would exceed the statement size limit is refused with 422 and nothing is written. Restore is the next conversion.
  CSV import runs on `d1`: the preview validates without writing; the page commit and the `import.commit` job import one batch per line, the line's `import_rows` marker claimed in that batch (expect 1), so a commit that failed half-way resumes and racing commits import each line once. A line is checked by a dry run first; its new locations and categories are their own idempotent steps before the line's batch.
- PDO/self-hosting and default `LocalStorage` stay the filesystem restore. Explicit R2 keeps chunk payloads, the
  restore manifest and the lease on that same storage. A new object is created with the condition on that PUT, so a
  write still inside the bridge does not replace an object another restore has stored. The catalog transaction and
  the staged swap batch, the settings import, and the undo prune (including the files it releases) reject a
  fence token another restore has already committed. That token is not the staged restore's own lock. claim()
  waits while a restore transaction holds the row, and an object-lease renew does not wait on that claim.
  A map pointer is replaced under the row lock. A photo, thumbnail, attachment, or camera file that is already
  there is kept. A chunk is named in the manifest before its bytes, and the upload id is indexed before the
  manifest. A lost manifest update leaves the chunk in place. Design-B staged restore is unchanged. On a
  batch-only database, chunked confirm is one `restore.confirm` job and its progress is `GET /api/v1/jobs/{id}`.
  PDO confirm, the Backup page, and `bin/restore.php` still run in the request. One upload has one queued or running
  job. A read of that job, and a worker before it claims, copy the upload manifest onto the row. A live lease stays
  `running`. A claim that died before `applying`, after its lease has ended and the upload is free and still ready,
  is queued again as the same job; the original worker cannot start `applying` afterwards. A manifest that already
  says restored is `done` with that result, and a finished job is not given a different result. A second confirm
  does not insert another row. A SQLite catalog-fence claim waits for the holder's bridge work and still
  commits only after that transaction ends. An expired restore job whose upload is gone is failed, and that
  attempt cannot store a result afterwards. The next finding id is 1846.
  Remaining R2 object reads stay bounded by the current bridge
  (they are not yet streaming reads). The Worker forwards to the container over HTTP with `redirect: manual`, overwrites `X-Forwarded-Proto`, `X-Forwarded-Host` and `X-Apotheke-Client-IP` (from `cf-connecting-ip`), and the readiness probe uses the first `ALLOWED_HOSTS` entry (or `127.0.0.1` if that list is empty). The entrypoint recreates Caddy's `/run` directories before FrankenPHP starts. Caddy compares the `APOTHEKE_EDGE_CLIENT_IP` flag as a quoted string so `frankenphp validate` accepts the Caddyfile with the flag set or unset. In storage mode the boot builds the D1 settings without the autoloader, `auth status` reads sign-in over `d1.internal` (same answers and exit codes, so the entrypoint check still applies), and R2 metadata is `GET ?action=head` (the R2 protocol has no HEAD). `EDGE_STORAGE_READY` stays off.


## Open pull requests

- For the review state of P4-5 piece 2a (#252, passed QA), piece 2b (`feat/p4-5-db-driver`) and the storage-factory routing (`feat/p4-5-r2-files`), see the open PRs on GitHub.
- The account lifecycle rules for incomplete multipart uploads on `apotheke-api-storage` are reported applied by the PM (7 days for the bucket, 1 day under `installations/primary/`). The marked-upload sweep stays an uncalled helper.
- Do not treat restore conversion as hosted wiring. R2 remains explicitly opt-in; PDO/SQLite with LocalStorage remains the default. Batch-only confirm is the background job; PDO confirm is not.
- N-1786 stays deferred. N-1794 and N-1795 stay separate. L-1811 and N-1812 stay tracked for P4-3/CI follow-up.
- Older numbered backlog: N-1747..N-1751, N-1758, N-1761..N-1763, N-1780, N-1782.

Merged PR notes: [log, Pull requests](history/handoff-log.md#pull-requests).

## Next steps

1. P4-5 piece 2b: chunk 2-ii-B converts bulk editing, CSV import and backup restore to the batch path (or keeps them refused on `d1`), and closes the opt-in `d1` test matrix's harness gaps (`APOTHEKE_CI4_TEST_D1=1`); chunk 3 is the `edge-local` end-to-end check and the runbook. Media, attachments, imports and brand files already follow `StorageFactory`. Hosted sessions (`DB_DRIVER=d1`) use the installation database, each row keyed by the SHA-256 (hex) of its session id; PDO keeps the file handler. Other remaining disk users are still on the container. Until 2-ii-B, storage mode must not hold real data. Then host-only cookies, `edge-local` with storage, the preview guard cases, and the runbook. The storage-mode preview's boot, `auth status` and `/media` fixes are in; the Worker's forward and start robustness and the inactivity sleep follow separately. The sweep helper stays uncalled. The next finding id is 1846.
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
