# QA: apotheke PR #211, "fix(backup): harden the chunked restore upload limits"

**Verdict: PASS.** Reviewed head `5a602a0192e02b3636742df068ceea6083d1a04d`, 2026-11-01 (EET), full gate:
- same repo, base `main` `4702c69`, 0 behind, 4 commits, 14 files (+481/−85);
- CI 20/20, both Tests jobs green; CodeQL ran (actions and JS/TS) and passed.

It follows up #205/#206: L-1580, L-1581, N-1582, N-1583 and N-1586 to N-1588.

## Checklist

- [x] **L-1580 fixed.** New tests for the sweep's real-folder filter, the data file's link check and the id's start anchor. All three earlier survivors are now killed.
- [x] **L-1581 fixed.** Starts are serialised by `create.lock`.
  - My probe of 15 rounds × 8 parallel creates by one account left exactly 1 upload (6 before). Dropping the lock is killed.
  - `RESTORE_MAX_UPLOADS`: default 8, clamped 1–64 (tested). Over it, 503 `restore.busy` with `Retry-After: 60`.
  - The free-disk check needs the declared size plus 64 MiB, else 507 `storage.insufficient`. Margin and check are both killed by mutation.
  - Understating the size gains nothing: chunks stop at the declared size, and the size cap still gives 413.
- [x] **N-1582 fixed.** Removal takes the manifest lock, checks again (inode, real folder), renames the folder aside and then empties it.
  - Opening an upload re-checks folder, manifest inode and real path under the lock.
  - The `root()` docblock now says to run as the data dir's owner. See N-1595 for tests.
- [x] **N-1583 fixed.** The sweep skips an upload whose lock is held; this is killed by mutation.
- [x] **N-1586 fixed.** `1`, `"true"`, `"yes"` and `[true]` get 422; a truthy check is killed.
- [x] **N-1587 fixed.** The chunk size is `RESTORE_CHUNK_KB`, capped at `upload_max_filesize` and at `post_max_size` minus 64 KiB, and returned by start.
  - The ini parsing is tested. A server with 2M/8M limits accepts a full 2 MiB chunk, and all three limits are killed by mutation.
- [x] **N-1588 fixed, by outcome rather than proxy timeouts.** finish and confirm get `set_time_limit(300)`, and confirm sets `ignore_user_abort`.
  - The nginx API timeout stays 75 s; FrankenPHP has no write timeout. The docs say this and tell clients to poll.
  - After confirm, the manifest keeps the outcome. GET returns 200 with `restored` and `result`, which matches the OpenAPI.
  - A second confirm returns the same answer without restoring. Six parallel confirms gave one 200 and five 409, and the data was applied once. finish after confirm gets 409.
- [x] **Docs.** api.md, OpenAPI (409/503/507, `result`, statuses), configuration, deployment, CHANGELOG `[Unreleased]` and HANDOFF are accurate on `chunk_size`, 413 and the limits. The HANDOFF #205/#206 status is fixed.
- [x] **Suites.** SQLite 453 tests OK; own PG17 cluster with password auth, 453 OK. Lint, deptrac and `routes --check` are clean. gitleaks 8.30.1 on `main..pr211`: no leaks.
- [x] **Mutations: 28, of which 18 killed.** The 10 survivors are in N-1595.

## Findings

- **N-1591:** A linked or non-plain `create.lock` is refused, but in the wrong terms.
  - The message names `DATA_DIR/tmp/restore.lock`, and the API answers 422 `validation.failed`.
  - The lock and its target are untouched, and no real path is shown. Give it its own message and a 5xx.
- **N-1592:** The open-upload cap is global. One account holds at most one slot (verified), so blocking others takes `RESTORE_MAX_UPLOADS` accounts with the backup permission, each keeping an upload alive. With sign-in off there is one owner. Low impact. Document it, or reserve a slot per account.
- **N-1593:** The free-disk check runs per start and reserves nothing. Open uploads can together exceed free space (up to the cap × `RESTORE_MAX_MB`). It could subtract the bytes the other open uploads still expect.
- **N-1594:** The kept outcome is keyed by `user:<id>`. After a restore, that id may be another account in the restored users table, which can read the counts and warnings or delete the record within the hour.
  - Separately, a crash after the restore commits but before the outcome is written leaves `ready`, so a repeat confirm would apply again. Consider writing a `restoring` marker first.
- **N-1595:** These guards have no test (each survives mutation alone):
  - the new re-checks: inode in removal, rename-aside, the locked re-check including the real path;
  - `.removing-*` cleanup;
  - create's 409 when your old upload is in use;
  - confirmed records not counting toward the cap;
  - the API's 503/507 mapping and `Retry-After` (verified live);
  - `ignore_user_abort` (not testable on `php -S`).

  The tests' upload listing now ignores non-id entries, so leftovers go unseen. Restorer's `$afterCheck` seam would cover the races.
- **N-1584 and N-1589 (open, P4):** The store is still direct filesystem with flock, and confirm is still synchronous. The HANDOFF points the background job to ADR P4-4.

## Security

- One start at a time, at most one upload per account, a global cap, and a free-disk floor.
- The declared size bounds every byte written.
- Removal and the sweep never act on a folder that's in use or has been swapped, and never through a link.
- The new lock file is refused when linked, and nothing is written through it.
- Confirm applies a backup once, even under concurrency or after a lost answer. N-443 is unchanged.
- The 503 and 507 bodies carry fixed text with no path or free-space figures.
- Nothing is H or M.

## Verdict

**PASS.** No H/M findings and no decision needed. L-1580, L-1581, N-1582, N-1583 and N-1586 to N-1588 are fixed. N-1591 to N-1595 go to the backlog; N-1584 and N-1589 stay with P4. Next free ID: 1596.
