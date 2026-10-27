# QA: PR #141 — restore entry cap, links and lock (main)

**PR:** [#141](https://github.com/thordin9/apotheke/pull/141) `fix/restore-entry-cap` → `main` · **Final head:** `204ec54` (CI 40508577310, 10/10) · **Code head:** `661fbb8` (CI 40507146180, 10/10). `661fbb8..204ec54` changes `README.md` only, so the code is identical. · Base `458a3c5` (v2.6.23) · 27 Oct 2026 (EET)

## Verdict

**PASS. Ready to merge.**
- Round 1 (`26331cf`): no H or M findings.
- Round 2: every in-PR item the PM picked is fixed and verified (L-560, L-561, N-562, N-564, and the temp-write mutant).
- The new N notes (580–582) and N-563 and N-565 to N-567 go to batch 4.

## Checklist (round 2, delta since `26331cf`)

- [x] **L-560.** A link at a camera file's name stops the restore before commit (422 or exit 1), with nothing changed and the link kept. Tested on both DBs:
  - a link at an existing or a new name;
  - a link to a file or to nowhere;
  - a root CLI restore with a root-owned 0604 file outside: it stays 0:0 0604 with the same bytes.
  - The post-commit chmod now runs only when `lstat` still shows a plain file.
  - **A folder at the name:** the restore succeeds and the folder is left empty. That camera file and the later ones are skipped, and the old map stays. **Acceptable:** nothing is written through it and the photos are whole (N-580).
- [x] **L-561.** CHANGELOG and the ops docs no longer promise a visible warning: "that photo is not linked to its camera file, and the restore continues". One over-claim is left (N-580).
- [x] **N-562.** `clearstatcache(true, $path)` now runs before the lock `fopen`.
  - Swap loop, 5 runs on SQLite 8.4 (the flaky leg) and PG 8.3: after the loop, 6 of 6 web restores give 303.
  - After a root CLI restore web gives 303, and while root holds the lock it gives 409. The outside target was never made.
- [x] **Temp write.** The temp file is opened with `'x'`, every byte is counted, and `fflush` and `fclose` are checked. With `images/` full (tmpfs), web gives 422 and the CLI exits 1, with nothing changed and no `.restore-*` left.
- [x] **N-564.** The README table is byte-identical to `coverage-readme.php` run on artifact 40507146180: Backup 93.4 / 91.2 / 97.8, 7698 lines.
- [x] **Suites.**
  - PHPUnit 542 OK: SQLite 8.3 and PG 8.4.
  - `integration.php`: SQLite 8.4 1765 passed, PG 8.3 1436 passed.
  - lint OK, commits OK (4), gitleaks clean on `26331cf..204ec54` and on `--all`.

**Round 1 (`26331cf`), still holds:**
- The 200 MiB cap (as CI4 #136) is read from the zip directory.
- A fatal error leaves no files.
- Dangling links stop the restore.
- The lock is a plain file only.
- The failure matrix, races with 4 workers, and full disk all passed.
- Originals keep their bytes and are 0640. A root CLI restore keeps an existing map's owner.

## Findings and routing

| ID | Sev | Status |
|---|---|---|
| L-560 | L | **Fixed** in `c959676`; verified as above, including as root. |
| L-561 | L | **Fixed** (docs only, as the PM decided); main still shows no restore warnings. |
| N-562 | N | **Fixed**: the clear now runs before the open. The swap-loop refusal is gone. |
| N-563 | N | Batch 4: tests for the map write-failure and kept-owner mutants. The temp-write mutant is fixed and tested. |
| N-564 | N | **Fixed**: README matches the code head's artifact. |
| N-565–N-567 | N | Batch 4 (unchanged): links to files at new photo or attachment names, root-owned new files, partial upload until the sweep. |
| N-580 | N | **New, batch 4.** The docs say "a file that can only be written in part (a full disk) fails it". That is true for photos, thumbnails and attachments. A full `originals/` still gives 303 without the camera file, and a full map folder skips the map. Reword, and mention the folder-at-the-name case. |
| N-581 | N | **New, batch 4.** The "no clearstatcache before the lock open" mutant can be killed in-process. First a dangling link at the name when it is opened (refused), then a plain file: the real code locks, the mutant refuses. Add that as a test. |
| N-582 | N | **New, batch 4.** One window remains: between the post-commit `lstat` and `chmod`, a racing writer in `originals/` could swap in a link. This is the "chmod through a link" mutant, which only a swap can kill. It needs write access to `originals/` during a restore. Hardening only. |

## Surviving mutants (round 2)

| Mutant | Judgement |
|---|---|
| post-commit chmod through a link | Killable only by a swap between the pre-commit check and the chmod. Acceptable residual risk (N-582). |
| temp no fflush | Equivalent: plain-file writes are not buffered. With `images/` full it still fails clean. |
| temp fclose unchecked | Equivalent on local disks: errors show up at the write, and it fails clean with `images/` full. It only matters on filesystems that report at close (NFS, quotas). |
| no clearstatcache before the lock open | **Not equivalent:** killed by the repro in N-581. Test gap. |

## Security

- **Links at camera file names** stop the restore before commit. The mode is set only on a plain file, so a root CLI restore can no longer chmod a file outside the data folder (L-560). The only window left is N-582.
- **The lock cannot be poisoned:** after a link was seen at the lock name, a long-running worker no longer refuses restores (N-562).
- **Short writes fail the restore**, with no partial temp file left.
- **No new endpoints**; gitleaks is clean.
