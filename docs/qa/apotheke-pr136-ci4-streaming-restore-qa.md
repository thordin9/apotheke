# QA: PR #136 — CI4 streaming restore and restore QA notes

**PR:** [#136](https://github.com/thordin9/apotheke/pull/136), `ci4/streaming-restore` → `feature/ci4`
**Head:** `88de3a4` · **Base / merge-base:** `27c422b` (#134) · **CI:** run 40485736625, 23/23 green (parity gate included)
**Gate:** full QA plus a merge-only read of `bba1172` · 27 Oct 2026 (EET)

## Verdict

**PASS. Ready to merge.** No H, M or L findings. There are four N notes, and none of them blocks the merge.

## Checklist

- [x] **Merge `bba1172`** (#134 into the branch).
  - Remerge-diff conflicts are in `docs/HANDOFF.md` only, and both blocks were kept. `CHANGELOG.md` keeps both Unreleased entries.
  - Diff-of-diffs against #134, excluding docs and CHANGELOG, is identical apart from one hunk offset. #134's change is intact.
  - `.gitleaks.toml` sha256 is `b5bc9f98…993a8f`.
- [x] **Streaming (L-441, L-451).** Tested with `memory_limit` at 128M and 64M.
  - A `backup.json` with 160 MB of rows (160,000 rows) restores in full.
  - A 190 MiB attachment restores. It is staged as `.part` and nothing is left behind.
  - Single notes up to 60 MiB restore. From 62 MiB they are refused with the "too large … memory" message. No 500 at any size.
- [x] **N-442, L-480.** A 300 MB zeros entry in a 300 KB zip is refused from the central directory in 0.02 s, naming the entry. Nothing is written and no `.part` file is left.
- [x] **N-443.** Already covered by a test: a role with backup permission but not settings gets the settings skipped and a warning; a role with settings permission gets them restored; `bin/restore.php` restores them.
  - Documented in CHANGELOG and HANDOFF as a CI4-only stricter rule.
  - `users` and `roles` are not in the backup, and `app_settings` holds only seed flags, so this rule cannot be bypassed through table rows.
  - The command line runs as the operator, who can already edit settings.
- [x] **N-444, N-448, N-454.** Covered by suites and mutants. JPEG and decode limits, booleans and duplicate keys are refused.
  - Columns are checked against the schema before `insertRows` builds its SQL.
- [x] **N-445.** Deadlock injected on PG17.
  - One lost deadlock: the restore is tried again and gives 303.
  - A hostile loop: the restore loses 3 times, then gives 409 with the CONFLICT message, and nothing is changed.
- [x] **N-446.** `bin/backup.php` into a missing folder and into a read-only folder: "Cannot write …", exit 1, no file left.
- [x] **N-452, full disk.** Small tmpfs mounts on `tmp/`, `files/` and `images/`: 422 FAILED, state byte-identical, no `.part` or `.tmp-` file left. An upload that fills `tmp/` gives "The upload could not be stored." with nothing left.
- [x] **N-453.** A missing `files/` folder plus a DB failure: the folder is gone again after the failure.
- [x] **N-458, N-459, the lock.**
  - Refused with LOCK_REFUSED, target not created or changed: a dangling link, a link to a file, a link to a folder, a folder, a FIFO.
  - A stale plain lock (as left by kill -9) restores. A hard link to an outside file is accepted, which is harmless because nothing is written through the lock.
  - A loop swapping the lock for a link during 12 web and 8 CLI restores: the outside target was never created.
- [x] **Root-owned lock.** After a root CLI restore (lock 0644, uid 0), web restore works. While root holds the lock, web gets 409 BUSY.
- [x] **N-481.** A link at a new photo, at a new attachment, or a linked `files/` folder: the restore stops with 422, the link is untouched and nothing is written outside.
- [x] **Failure matrix (SQLite and PG).** CRC damage to a photo or an original, a duplicate primary key, read-only `images/` or `thumbs/`: 422 every time, with rows, bytes, modes and folders identical.
- [x] **Race, `PHP_CLI_SERVER_WORKERS=4`.** 36 rounds per DB: web bad+good, good×3, restore with downloads, web+CLI both ways, CLI+CLI.
  - Every end state is whole: either the good backup or the prior catalog.
  - Only 303, 409 or 422. No 500 and no leftovers.
- [x] **88de3a4 and originals.** After a good restore the existing original's bytes are unchanged and every file in `originals/` is 0640, including one that was 0644. A failed restore changes no modes. A stray file in `images/` is kept.
- [x] **Suites.** CI4 on PHP 8.4: SQLite 832 OK, PG17 832 OK. Legacy pgsql: 8.4 832 OK, 8.3 832 OK. CI4 module suite: 532 OK.
  - lint, check-commits (14 commits), deptrac and DI pass. gitleaks 8.30.1 finds no leaks in `27c422b..` or `31de273..88de3a4`.

## Findings

| ID | Sev | Finding |
|---|---|---|
| N-520 | N | The lock fallback for filesystems without hard links has no test (mutant 11 survives). On such a filesystem the mutant fails every restore. It could not be reproduced here because the kernel has no vfat support. A seam test would close the gap. |
| N-521 | N | If the lock name disappears between creating and opening it (attacker-only race), the restore says the generic FAILED message rather than LOCK_REFUSED. It is safe but the message is less clear. |
| N-522 | N | CHANGELOG says "as on `main` since v2.6.23", but `feature/ci4` has no [2.6.23] section or compare link yet. Fix at the next sync with main. |
| N-523 | N | Cloudflare phase, not blocking: these do not go through Storage yet and need an interface before Workers. See the list after this table. |

N-523 covers:
- restore staging (`tmp/*.part` and `backup.json` copies, ZipArchive on paths);
- the `flock` lock file;
- `chmod`, `is_link` and `rmdir` on DATA_DIR paths;
- raw `pg_*` calls on `connID`;
- memory headroom read from `ini_get` and `memory_get_usage`.

`writeAll`'s retry for partial writes only matters for non-local streams.

## Surviving mutants (SQLite)

| # | Mutant | Judgement |
|---|---|---|
| 11 | No-hard-link fallback off | Real test gap, low risk. This is N-520. |
| 13 | `sameFile` ignores `plainFile` | Equivalent in effect. A link never has the same inode as the file opened. A FIFO swapped in after `lstat` would block the open, or else be flocked harmlessly. Not a finding. |
| 28 | Row memory check removed | Near-equivalent. The JSON guard needs about twice the value while decoding, so a value that passes it always leaves room for the driver copy. The 56–68 MiB grid at 128M gives the same results with and without the check. Defence in depth. Not a finding. |
| 33 | N-446 folder check removed | Same output ("Cannot write …", exit 1, nothing left). Only the dump work is wasted. Not a finding. |
| 39 | `writeAll` without the partial-write retry | Equivalent for local files: a short write on a full disk is followed by `false`. Relevant only to future non-local storage (N-523). Not a finding. |

The PG run kills 30, 31, 32 and 40.

## Security

- Lock creation never follows a link: a hard link with an `x` fallback, an `lstat` pre-check, and an inode re-check after open.
- Restored paths refuse links in any path component.
- Entry size and total size are capped from the zip directory before anything is read. Each entry is checked by CRC and size while it streams.
- Failure rolls back rows, files, created folders and temp files. Memory stays bounded, and refusals are clean 4xx responses.
- Settings need the settings permission on the web. The CLI is operator-only.
- No secrets in the diff (gitleaks clean). No new endpoints. CSRF and auth order are unchanged.
