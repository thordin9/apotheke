# QA: apotheke #135, main: failed-restore cleanup and restore lock

- **PR:** [#135](https://github.com/thordin9/apotheke/pull/135), `fix/restore-failure-cleanup`.
  - **Head:** `420e694c43f8f5729f94c6207915019ce002f9c5`.
  - **Base and merge-base:** `main` `dd019fb`.
  - **Commits:** `e127944` fix, `420e694` tests. Only `src/backup.php`, `public/index.php`, CHANGELOG, the deployment docs and the 2 test files change.
- **CI:** run 40465304872 on `420e694`, 10/10 green (completed 09:21 EET). Checked with read-only `gh`.
- **Verdict: PASS.** 0H / 0M / 1L / 4N.
  - The ports (L-440, L-450, L-455, N-456) work as claimed on PHP 8.3 and 8.4, SQLite and PG17.
  - L-480 is pre-existing on `main` and can follow.

## Checklist

- [x] **Gates:** lint ok; check-commits 2 ok; gitleaks 8.30.1 `dd019fb..420e694` no leaks (`.gitleaks.toml` `b5bc9f98…`).
- [x] **Suites, 0 failures:** phpunit 526 on each of 8.3/8.4 × SQLite/PG (3 environment skips); `tests/integration.php` 1754 passed on 8.3 and 8.4.
- [x] **L-440 matrix:** 28 failure points × 4 legs.
  - The base has photos, thumbs, attachments, originals, the map, same-name zip entries with other bytes, and an orphan photo.
  - Points: bad JSON; CRC errors (first photo, thumb, attachment, `originals/index.json`, last original); size ±1; a folder in a file's place; read-only `images/`, `thumbs/`, `files/`; DB errors at the first delete, first insert, photo rows and last rows (web and CLI, and after a redrawn thumb); read-only `originals/` with a DB error.
  - After each failure, every file's sha256, mode, uid, mtime and inode, every link and directory mode, all tables and the sequences match the snapshot. The only exception is N-481.
  - No `.restore-*` temp file is left. Existing photos and originals are never overwritten or removed. The map changes only after the commit.
  - The answer is 422 or exit 1 with "…Nothing was changed." On DB errors, main's message with the DB text is kept.
  - Temp and rename share a folder, so the rename is atomic.
- [x] **ENOSPC** (a 96 KB tmpfs over the folder):
  - in `thumbs/` or `files/`: 422, nothing changed, no temp left;
  - in `originals/`: 303 with the "camera files could not be restored" warning, by design.
- [x] **CRC and size:** a CRC error, a lying size, and a deflated 50 MB entry declared as ~1 KB all give 422 with nothing changed. A high-ratio entry with honest sizes: L-480.
- [x] **L-450 race** (`PHP_CLI_SERVER_WORKERS=4`, 600 extra photos, 24 rounds per mode and leg, both start orders; 672 rounds):
  - failing against good: web/web, CLI/CLI, web/CLI, CLI/web;
  - good against good: web/web, web/CLI;
  - backup download against restore.
  - 0 bad rounds: no row points at a missing file, failed restores leave the tables at baseline, no temp files, and downloads are whole.
  - The loser always gets 409 or exit 1 with "Another restore is running. Try again when it has finished.", the same as CI4. The upload stays, so the same confirm works afterwards.
  - With the `flock` removed, Coder2's race test fails in round 0 on both DBs.
- [x] **Stale lock:** after `kill -9` of the web server (about 200 files in) or of the CLI, the lock file stays. The next web and CLI restores succeed at once on all legs.
- [x] **L-455 in the main image** (podman, isolated storage, PHP 8.3.36):
  - A root `bin/restore.php` leaves the lock `0:0 0644`; 2 web restores then answer 303. Root's `'c'` handle gives the web 409; `www-data`'s `'r'` handle gives the root CLI exit 1 BUSY. Same on the host with `sudo`.
- [x] **N-456:**
  - A link to a dangling target or an existing target is refused: 422 on the web, exit 1 on the root CLI, both with the FAILED text.
  - A dangling target (`/etc/…` in the image) is not created; an existing one keeps its bytes; the catalog is unchanged.
  - The check-then-open window remains: N-482.
- [x] **Order while the lock is held:**
  - Sign-in on: guest 303, viewer/editor 403, no CSRF 419, unknown Host 421, bad token 410, no tick 422; only a valid admin sees 409.
  - No login (SEC-M3): cross-origin or cross-site 403, unknown Host 421 (also via nginx in the image), no CSRF 419.
- [x] **Lock path** `DATA_DIR/tmp/restore.lock`, made with the same `mkdir(0770)` as `backup_tmp_dir()`, without its sweep (the sweep only matches `restore-*.zip`):
  - Modes tmp 0750, lock 0644. Missing tmp is recreated. Read-only tmp: FAILED with nothing changed if there is no lock file, works if there is one. Read-only data dir with no tmp: FAILED.
- [x] **Mutants:**
  - The suite kills C3b (lock kept in a static), C4, C5, C6, no CRC check, discard only on DB errors, temp kept on write failure, `is_link` after `fopen`, no tmp `mkdir`, discard skips the last, and 409→422.
  - Coder2's `no LOCK_UN`, `no fclose` and `no finally` survive and are equivalent: the handle is a local and is freed on return or throw. The same-process test passes, and the static variant is killed.
  - My survivors: no size check (CRC covers it), write in place (same result, not atomic), and N-483.
  - Coder2's 18/21 count holds at the head, but see N-484.
- [x] **CHANGELOG** Unreleased (Fixed): accurate (except for L-480), public wording, no QA IDs. The deployment doc matches.

## Findings

- **L-480 (pre-existing on main):** a crafted zip can crash a web restore and leave files behind.
  - Setup: a photo entry of 300 MB of zeros, deflated to a 0.3 MB zip, with honest sizes under the 4 GB cap.
  - On the web (`memory_limit` 256M), `getFromName` runs out of memory and answers 500. The photo and thumb already written stay as orphans with no rows; the catalog is unchanged.
  - The CLI, with no memory limit, restores it.
  - This is L-441 territory, which was not ported.
  - Fix: refuse an entry whose declared size exceeds a per-entry cap (for example the photo upload limit, or a share of `memory_limit`) before reading it.
- **N-481:** a dangling symlink planted where a new file goes is replaced by the rename, and a later failure removes it. `is_file()` is false for such a link. No target is touched. Fix: treat `is_link()` as "present" or refuse it.
- **N-482 (= CI4 N-458):** `is_link()` and `fopen()` are separate steps. In that window, a `www-data` user could make a root CLI create an empty file at the link target.
- **N-483, test gaps:**
  - an attachment temp left on a type mismatch (`@unlink` removed) survives;
  - turning the originals write failure into a fatal error survives: the read-only/ENOSPC `originals/` warning path is untested.
- **N-484:** Coder2's `mutants-sqlite.log` (08:48) predates the final tests and lists 6 survivors; at `420e694` C4–C6 are killed. Rerun or relabel it.

## Security

- No writes outside `DATA_DIR`/`ORIGINALS_DIR` through the lock or the restore. A linked lock file is refused. Root and `www-data` exclude each other on one lock file.
- The lock status shows only after the Host, same-origin, sign-in, permission, CSRF and token checks, so nothing leaks to guests, viewers or editors.
- Failed restores keep every existing file byte-identical (mode, owner, inode) and never replace originals. The races lose no files.
- Error text: main's DB message (with the PG `CONTEXT`) is kept and shown only to `backup` users. This is pre-existing.
- L-480 is a 500 from a crafted backup by a `backup` user (availability, no exposure).
- **Security verdict:** no H/M.

_Scratch `/tmp/qa135-99lv` (own mktemp), DBs/role `qa135_*`, podman storage inside the scratch dir; removed after the run._
