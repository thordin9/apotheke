# QA: apotheke #133, CI4 backup and restore (PLAN 14, part 2)

- **PR:** [#133](https://github.com/thordin9/apotheke/pull/133). **Verified head:** `a2e56e2e44b33a8596353cb78975d30122e7bd39`. Base `feature/ci4` `7e927f7`.
- **Delta `357d672..a2e56e2`** (2 commits, narrow re-check): `4866497` changes `Restorer::lock()` and adds a `BackupHelpersTest` case; `a2e56e2` changes CHANGELOG and HANDOFF. Nothing else.
- **CI:** run 40458526923 on `a2e56e2`, 21/21 green (completed 08:18 EET). Checked with read-only `gh`.
- **Verdict: PASS.** 0H / 0M / 0L / 2N new, both deferred. L-455, N-456 and N-457 are fixed in `a2e56e2`.

## Checklist (this round)

- [x] **L-455.** A root CLI restore (`sudo`) makes `restore.lock` `root 0644`, not writable for the web user.
  - Then 3 web restores answer 303 on SQLite and PG, and the root CLI works again after them.
- [x] **Exclusion across owners.** Root's `'c'` handle refuses the web (409 BUSY). The web user's `'r'` handle refuses the root CLI (exit 1, BUSY) and the web (409).
  - Nothing changes. After the release, the same upload restores (303).
- [x] **Race, web against root CLI,** failing against good (both roles swapped; CLI first or web first; 600-photo zips).
  - 48 rounds per DB, 0 bad: no row points at a missing file, and a failed restore leaves the tables at baseline. Only 303, 409, 422, exit 0 and exit 1 with the exact texts.
  - BUSY was seen in both directions on both DBs.
- [x] **N-456.** With a planted link (dangling, or to an existing file), the root CLI exits 1 and the web answers 422, both with "The backup could not be restored. Nothing was changed."
  - The dangling target is not created, and an existing target keeps its bytes. The catalog is unchanged and the link stays in place.
- [x] **Mutants:** removing the `'r'` fallback, removing `is_link`, and `'r'`→`'c'` are all killed by `testTheRestoreLockCopesWithAReadOnlyFileAndRefusesALink`. That test skips as root, by design.
- [x] **N-457:** the sentence is gone, and the CHANGELOG from `[2.6.22]` down is identical to `main`.
- [x] **Backup tests, 0 failures:**
  - CI4 on PHP 8.4, SQLite and PG: `BackupMemory`, `Backup`, `Ci4BackupCli`, `Failure`, `Parity`, `Race`, and the module `Backup` tests (10).
  - Legacy on PHP 8.3 and 8.4, SQLite and PG: `Backup`, `BackupMemory`. The CI4 classes skip on 8.3 by design.
- [x] **Gates:** lint ok; check-commits 20 ok; gitleaks 8.30.1 `357d672..a2e56e2` and `7e927f7..a2e56e2` no leaks (`.gitleaks.toml` `b5bc9f98…`).

## Earlier rounds (summary)

- `357d672`:
  - The merge `b41f60f` read clean: the diff-of-diffs against #132 was identical, and `ci.yml`, CHANGELOG and `Services.php` were whole.
  - L-450 races: 528 rounds, 0 bad.
  - A stale lock after `kill -9` did not deadlock.
  - The 409 shows only after the auth, permission, CSRF and token checks.
  - The handle is local, so long-lived workers are fine.
  - N-431 checks ran on the CI4 image.
  - Full suites green: 808 tests ×4 and the module suite.
- `f2037c6`: the L-440 and L-441 matrices, thresholds and mutants.

## Findings

- **Fixed:** L-440, L-441, L-450; **L-455, N-456, N-457 in `a2e56e2`.**
- **Moved to the streaming-restore follow-up (PM):**
  - **L-451:** large single values cause a 500.
  - **N-452:** ENOSPC leaves a partial `.tmp`.
  - **N-453:** an empty `files/` is left after a failed restore.
  - **N-454:** test gaps (D10, pre-read `2×`).
  - **N-458, N-459** (new, below).
- **N-458 (new, deferred):** `is_link()` and the `fopen()` after it are two steps. The `www-data` owner of `tmp` could swap in a link between them while a root CLI restore starts. The effect is still only an empty file at the link target. Close with `lstat` after open (compare inode) if ever needed.
- **N-459 (new, deferred):** a linked lock file gets the generic FAILED text on the web and the CLI. It is true ("Nothing was changed.") but doesn't name `DATA_DIR/tmp/restore.lock`, so an admin can't tell why.
- **Open, unchanged:** N-442 (entries read whole) and N-443 to N-448 (access settings on web restore; 4 untested checks; PG deadlock gives a generic 422; CLI missing-dir error and zip mode; local paths, `ZipArchive`, temp files and sweep, `flock` for Cloudflare; PG accepts booleans).

## Security

- The lock no longer depends on who made the file, and it never follows a link. A root CLI run can no longer create files outside the data dir through `restore.lock` (only the N-458 window remains).
- The BUSY text still shows only after authentication, permission, CSRF and token checks.
- Failed or refused restores change no file or row, and the races lose no photo files.
- **Security verdict:** no H/M.

_Scratch `/tmp/qa133d-U8Mo` (own mktemp), DBs/role `qa133d_*`; removed after the run._
