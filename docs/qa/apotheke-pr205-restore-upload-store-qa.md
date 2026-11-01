# QA: apotheke PR #205, "feat(backup): chunked restore upload store"

**Verdict: PASS.** Reviewed head `caacb8cb87184aa22109f6ac0455cfe63f06e014`, 2026-11-01 (EET), full gate:
- same repo, 4 commits, 10 files (+739/−3), CI 20/20, both Tests jobs green;
- 2 behind `main` (v3.10.0) and now conflicting in the CHANGELOG (N-1585).

Part 1 of 2. No route reaches the store yet; #206 (the API) was read only for context.

## Checklist

- [x] **Caps.** `RESTORE_CHUNK_KB` is clamped to 64–16384 and `RESTORE_MAX_MB` to 1–4096. Both clamps are tested.
  - The declared size must be 1 to the cap, and the cap itself is allowed.
  - Each chunk is checked against the chunk size, the bytes left and the expected index.
  - Out-of-order, repeated, oversized, past-the-end, short-but-not-last and empty chunks are refused and change nothing. A short copy is truncated back.
- [x] **Finish.** The received count and the file size must match, then the optional SHA-256 (lowercase hex, compared with `hash_equals`).
  - The zip is handed to the caller. In #206 that is `Restorer::open`, then `Restorer::restore` on confirm, so the existing check, dry-run and confirm flow stays the only way to apply a restore.
  - A mismatch or a refused zip removes the upload. Confirm before finish gets 409. A 409 during the restore keeps the upload.
- [x] **Confinement.** Ids are `random_bytes(16)` hex and must match `^[a-f0-9]{32}$`; 200 ids were all distinct.
  - `uploads` and `uploads/restore` must be real folders (lstat plus a real-path check).
  - The folder, the manifest and the data are never opened through a link, and the manifest's inode is compared.
  - Traversal-shaped, linked, malformed and other owners' ids all get the same 404; a guest gets 403 before anything is written.
  - Errors name `DATA_DIR/...` literally, never a real path.
- [x] **Locking.** Every operation takes a non-blocking exclusive lock on the manifest (409 when busy) and re-reads the manifest under the lock. Manifest values are type-checked, so a tampered manifest is not found.
- [x] **Sweep.** Uploads idle for 1 h or more are removed on each store call and by `spark apotheke:restore-sweep`, which creates no upload folder.
  - Only 32-hex real folders are listed. Removal unlinks entries (a link itself, never its target) and does not recurse.
  - A linked folder was left in place on a live run; the docs say to run the command as the data dir's owner.
- [x] **No table.** No migration, so 2.6.x reads nothing new.
- [x] **N-443 (in #206).** The backup's settings are applied only with the settings permission. Forcing the flag on or off is killed by #206's API test.
- [x] **Edge and worker mode.** No static properties; the owner is resolved on each call. Storage is direct filesystem (N-1584).
- [x] **Docs.** CHANGELOG `[Unreleased]`, configuration (two settings) and deployment (folder, cleanup, cron) are accurate.
- [x] **Suites.** SQLite 446 tests OK; own PG17 cluster with password auth, 446 OK. Lint, deptrac and `routes --check` are clean. gitleaks 8.30.1 on `main..pr205`: no leaks.
- [x] **Mutations: 35 of mine, 22 killed by the PR's tests.**
  - Coder2's two survivor pairs are confirmed: dropping both manifest checks, or both size checks at finish, is killed.
  - Covered by an overlapping guard or harmless: id without `/D`, the short-copy check alone, the real-path check alone, removal using `is_dir`, and the idle boundary.
  - Not covered by another guard (L-1580): the sweep listing following a linked folder, the data-file link check, and the id's start anchor. QA probe tests kill all three, and the code as written passes them.

## Findings

- **L-1580:** Three guards that nothing else backs up have no test. Mutating any one passes both the #205 and #206 suites:
  - the sweep's real-folder filter (otherwise removal goes through a linked folder);
  - the data file's link check (otherwise a chunk is written through a link);
  - the id pattern's start anchor (otherwise a path-shaped id reaches the store).

  The code is correct and each was verified with a probe test. Each needs write access to the restore folder. Fix: add one test per guard.
- **L-1581:** "One upload per account" isn't atomic. Concurrent creates by one account can each survive.
  - There is no overall count or free-disk check, so disk use is bounded only by accounts × parallel creates × `RESTORE_MAX_MB` until the idle sweep.
  - It needs the backup permission. Fix: lock the create step per owner (or for the root) and cap open uploads per account. Otherwise, reword the "one at a time" docs.
- **N-1582:** The listing check and the removal (and, in a lock, the folder check and the open) are separate steps. A folder swapped for a link in between would be followed.
  - This needs write access as the data dir's owner, which already owns the data.
  - The `root()` docblock still says the command line "may run as root". Align it with the docs (run as the owner) or re-check right before removing.
- **N-1583:** The sweep ignores the manifest lock. A finish or confirm still running an hour after its last touch could be swept. This is unlikely at the caps.
- **N-1584:** The store uses the local filesystem directly (flock, rename, lstat), not `Core\Storage`. ADR-002 P4-4 should list it, with the lock moving to the Durable Object or an R2 conditional write.
- **N-1585:** The branch conflicts with `main` v3.10.0 in the CHANGELOG `[Unreleased]` section. It needs a main merge, then a merge-only read.

## Security

- Uploads are confined to `DATA_DIR/uploads/restore/<random id>/`. Ids are server-made and strictly matched, and links are never followed by the request path or the sweep.
- Owner scoping is exact and indistinguishable from not found. Guests are refused before any write.
- Sizes are bounded per chunk and per upload. The SHA-256 is optional but constant-time compared.
- The restore itself stays in `Restorer` (validation, dry-run, N-443).
- No path, secret or token leaks in errors, code, tests or docs. Nothing is H or M.

## Verdict

**PASS.** No H/M findings and no decision needed. L-1580 and L-1581 go to Coder2 (can ride with #206). N-1582 to N-1585 are backlog or process. Next free ID: 1586.
