# QA: PR #142, CI4 restore folder modes and parity with #141

Full gate (round 1, head 2a5ce2b), then a delta and merge check (round 2). Verified head: 546e6a7b85b333d2c5a45a81d86a0026eaedd093. CI: 25/25, CLEAN, 0 commits behind feature/ci4 (0d801b7). Round 2: Wed 28 Oct 2026, 13:15 to 13:27 EET.

## Verdict

**Approve, with one decision for the PM (L-610).**
- L-590 is fixed.
- N-591 is done: the branch was merged with feature/ci4 again at 3748ea1.
- N-592 and N-593 go to batch 4.
- The three #141 link behaviours, the shutdown cleanup, the folder modes and the swap loops all hold on SQLite and on PostgreSQL 17.
- L-610 and N-611 are new and come from the new code.

## Checklist (round 2)

- [x] **L-590 (589d5f9), CLI run as root.** An existing map entry owned by the web user (0640 or 0600) keeps 1000:1000 and ends up 0640; the web user can still read it. The same holds on legacy. The sudo test `testARootRestoreKeepsTheOwnerOfAReplacedMapEntry` passes on both databases. A new entry created under root is still owned by root (the batch-4 class).
- [x] **Shutdown cleanup (356df34).** I raised an out-of-memory fatal while the camera file was being unpacked, through the CLI and through the web (500):
  - rows, files, modes and folders are unchanged, and the `files/` folder the restore made is removed;
  - the lock is free, and the next restore succeeds;
  - the only leftovers are temp files (N-611).
- [x] **The three #141 link behaviours** (CLI, web, and both databases):
  - `testALinkWhereANewFileGoesStopsTheRestore`: pass.
  - `testALinkAtACameraMapNameIsLeftAlone`: pass.
  - `testALinkAtACameraFileStopsTheRestore`: pass.
- [x] **Concurrent restore loop** (SQLite and PostgreSQL 17): pass; the catalog was whole after every round.
- [x] **Merge-only read of 3748ea1** (parents 383401c and 0d801b7, base 3660252):
  - The remerge-diff touches only docs/HANDOFF.md, and the resolution is correct.
  - `comm -12` finds CHANGELOG.md, Services.php and HANDOFF.md changed on both sides.
  - The diff-of-diffs (excluding HANDOFF.md, CHANGELOG.md and docs/qa) differs only in one hunk offset in Services.php.
  - The .gitleaks.toml sha256 is b5bc9f98…993a8f, as expected.
  - 05bb80c (the merge of 3660252) is also clean.
- [x] **This doc is not in the tree.**
- [x] **Suites** (backup, restore, failure, lock, memory, CI4 CLI/failure/parity/race/checks, the parity gate, ci4/tests/Backup and LocalStorageTest):
  - PHP 8.4: CI4 and legacy, each on SQLite and PostgreSQL 17, all green.
  - PHP 8.3 legacy on PostgreSQL: green, with the CI4 tests skipped. ci4/tests cannot run on PHP 8.3.
- [x] **Checks:** lint OK, the commit check OK (44 commits), gitleaks finds nothing in the full history.

## Findings

| ID | Sev | Status | Finding |
|---|---|---|---|
| L-590 | L | **Fixed** | A CLI restore run as root keeps a replaced map entry's owner and group. |
| N-591 | N | **Done** | The branch was merged with feature/ci4 again (3748ea1); the PR is CLEAN. |
| N-592 | N | Batch 4 | A chattr test that kills the map loop's `return`→`continue`. |
| N-593 | N | Batch 4 | Warnings from after the restore are not shown. |
| L-610 | L | **Fixed in #146** | Low, hardening: root CLI restore file ownership handling. |
| N-611 | N | **New** | **A fatal error mid-restore leaves the `.part` temp files in `tmp/`:** the copy of backup.json (the whole catalog) and the partly unpacked entry (up to 200 MiB). They are removed only by the one-hour prune in `tmpDir()`. `tmp/` is 0750, so nothing is exposed. The fatal test does not check `tmp/`. **Fix:** add the current `.part` file and the JSON temp to the cleanup closure. |

## Mutants

Coder2 left no new mutant logs for this round under `dirmode/`. I ran seven of my own on SQLite:

| # | Change | Result |
|---|---|---|
| 31 | `keepOwner()` call removed | Killed by the sudo test. |
| 32 | The mode of a replaced file is not kept | Killed by LocalStorageTest. |
| 33 | `drop()` after the rows commit removed | Killed: the web after-filter deletes the restored files. |
| 34 | Cleanup closure emptied | Killed by the fatal test. |
| 35 | `removeEmpty()` removed from the closure | Killed by the fatal test. |
| 36 | chgrp skipped | Killed by the sudo test. |
| 37 | The plain-file check before keeping the owner removed | **Survives; equivalent.** `path()` already refuses a link, and a folder makes the rename fail. Only a FIFO or socket at the name would differ. |

Round 1 judgements still stand: 11, 22 and 23 are equivalent; 17 and 19 to 21 are equivalent on a local disk; 13 is killed on PostgreSQL only; 18 is killed on both.

## Round 1 (2a5ce2b), still valid

- **Folder modes** match the PR table at umask 022 and 002 (`files/` is 0750 / 0770, as on legacy).
- **N-547 is closed.**
- **Lock:** a lock name that is a link, a FIFO or a folder is refused at once.
- **Full disk:** a full tmpfs or an immutable map entry leaves no temp file.
- **Map write:** `put()` writes a temp file and renames it, so it never writes through a link.

## Security

- **Links:** no write and no chmod goes through a link at a camera, map or lock name, with or without root, including under swap loops.
- **Failed restores:** a failed or fatal restore leaves the catalog and the files as they were.
- **Root CLI (L-610):** a Low hardening note on file ownership handling; fixed in #146.
- **No secrets** were printed. gitleaks is clean.

## Cloudflare phase

Owner, mode, umask, links, flock and the root CLI (L-590, L-610) do not apply to R2 or Workers.
- The restore lock needs a Durable Object or a database lock.
- The "a fatal error leaves nothing" rule has to cover the R2 multipart uploads and temp objects (the N-611 class).
