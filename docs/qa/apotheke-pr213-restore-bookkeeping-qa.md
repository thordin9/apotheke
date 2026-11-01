# QA: apotheke PR #213, "fix(backup): tighten restore upload bookkeeping"

**Verdict: PASS.** Reviewed head `df8d5de98c1a4a4a527e00a300940df87eab6dba`, 2026-11-01 (EET), full gate:
- same repo, base `main` `c6a22ee` (v3.14.1), 0 behind, 3 commits (the last a plain main merge, CHANGELOG/HANDOFF only), 11 files (+426/−47);
- CI 20/20, both Tests jobs and CodeQL green.

It closes #211's N-1591 to N-1595.

## Checklist

- [x] **N-1594, owner key.** The owner is now `user:<id>:<sha256 of created_at>`; `created_at` is NOT NULL and never updated.
  - Backups don't contain `users`. SQLite ids are AUTOINCREMENT and PG uses identity, so ids aren't reused in normal use; the hash covers explicitly inserted ids.
  - Another account with the same id gets 404 (tested). Older uploads get 404 after the update, and the CHANGELOG says so.
- [x] **N-1594, crash window.** Under the manifest lock, confirm writes and fsyncs `applying` with the backup's SHA-256 before the restore.
  - The next lock holder turns it into `interrupted`, removes the zip, and answers 409 to confirm and finish. Restore is never called again.
  - A Restorer 409 (lock busy or write conflict, rolled back, nothing applied) returns it to `ready`; other errors become `failed`. Error text from a non-user exception is generic.
  - The SIGKILL test passes. My own probe of a left `applying` record agrees.
- [x] **N-1591.** A linked `create.lock` is a 500 with the generic `LOCK_UNUSABLE` text and no path. Through the API it is the fixed `server_error`.
- [x] **N-1592.** At the cap, only other accounts' `uploading`/`ready` uploads idle 600 s or more are removed, re-checked under their lock.
  - An upload whose lock is held (a confirm running) is never evicted; probed. Neither is one under 600 s idle, nor anything when the cap isn't reached; probed. Outcome records don't count.
- [x] **N-1593.** Under the create lock, the free-disk check subtracts the bytes other open uploads still expect. The reservation, its absence and full-size reservation are all covered by mutation.
- [x] **N-1595.** Tests now cover:
  - the post-lock re-checks (`$afterLock` seam) and a swapped manifest;
  - `.removing-*` cleanup;
  - the 409 on your in-use earlier upload;
  - the cap ignoring restored, failed and interrupted records;
  - the API's 503 with `Retry-After` and 507.
- [x] **Docs.** CHANGELOG `[Unreleased]`, api.md (`interrupted`, eviction, reservation), OpenAPI (`interrupted` status and result), configuration (the trade-off), deployment and HANDOFF are accurate and neutral.
- [x] **Suites.** SQLite 457 tests OK (29 skipped, 1 incomplete: PostgreSQL-only and environment tests); own PG17 cluster with password auth, 457 OK (2 skipped). Lint, deptrac and `routes --check` are clean. gitleaks 8.30.1 on `main..pr213`: no leaks.
- [x] **Mutations: 21 on the state machine, eviction, reservation, lock fault and owner key, 18 killed.** The survivors are in N-1634.

## Findings

- **N-1634:** Three behaviours have no test (each verified by reading the code, two by probe):
  - eviction only when the cap is reached (a mutant evicting on every start survives);
  - the fsync of `applying` (not testable without a crash of the machine);
  - the owner when `created_at` can't be read (guest, below).
- **N-1635:** Eviction is first come, first served. At the cap, a new start can remove another account's fully sent `ready` upload that has waited 10 minutes between finish and confirm.
  - That owner then has to upload again. It never touches an upload that is in use or being confirmed.
  - This is a documented trade-off. Operators can raise `RESTORE_MAX_UPLOADS`; alternatively exempt `ready` or give it longer.
- **N-1636:** If the account's `created_at` can't be read (a database error), the request is treated as a guest: 403 "Restores need a signed-in account." This is safe but misleading; a 500 or 503 would describe it better.
- **N-1584 and N-1589 (open, P4):** The store is still direct filesystem with flock, and confirm is still synchronous.

## Security

- Uploads are bound to the account, not just its id. Another account, including a later one with the same id, sees 404.
- A backup is applied at most once. A confirm that stops before its outcome is recorded can't be repeated; Restorer's own 409 means nothing was applied.
- Eviction and the sweep never remove an upload that is in use, swapped or linked, and every check is repeated under the lock.
- Disk use is reserved across open uploads.
- Lock faults, 503, 507 and failed restores carry fixed text: no paths, internals or free-space figures.
- N-443, CSRF and the permission checks are unchanged. Nothing is H or M.

## Verdict

**PASS.** No H/M findings and no decision needed. N-1591 to N-1595 are fixed. N-1634 to N-1636 go to the backlog; N-1584 and N-1589 stay with P4. Next free ID: 1637.
