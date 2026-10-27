# QA: apotheke #133, CI4 backup and restore (PLAN 14, part 2)

- **PR:** [#133](https://github.com/thordin9/apotheke/pull/133). **Verified head:** `9c85513dceaec9ffe6c153d74451f700c33f5453`, a docs commit on merge `7ad07d7`. Base `feature/ci4` `ad343bf` (#129, includes #131 `57eae25`); merge-base `ad343bf`.
- **CI:** run 40427295574 on `9c85513`, 22/22 green (completed 02:53 EET). Checked with read-only `gh`.
- **Verdict: PASS.** 0H / 0M / 2L / 7N. L-440 and L-441 are follow-ups (L-440 also on legacy).

## Checklist

- [x] Gates: lint ok (212 links, 0 broken); check-commits 9 ok; deptrac 0; DI OK; gitleaks 8.30.1 `ad343bf..9c85513` no leaks (`.gitleaks.toml` `b5bc9f98…993a8f`).
- [x] Suites, 0 failures (skips are environment guards):
  - CI4, PHP 8.4: SQLite 803 (7 skip), PG17 803 (6 skip); module suite 659.
  - Legacy, PHP 8.3: SQLite 803, PG 803.
  - Playwright CI4 `data.spec.ts` (desktop + pixel): 8 passed.
- [x] **Parity (rows and files).** 16 round trips: {legacy, CI4} × {SQLite, PG} backups restored on every app/DB pair.
  - Rows, sequences and files (images, thumbs, files, originals, map) equal the source in every pair.
  - A restore on legacy and on CI4 leaves byte-identical tables and files.
  - `backup.json` from both apps is byte-identical (apart from `created_at`).
  - Camera originals (EXIF JPEG, PNG) stay byte-identical to the upload; only served copies are derived.
- [x] **Recorded deviations** (six, all accepted): no DB text in errors (better), duplicate names refused, decode limit on redrawn thumbs, writes through Storage, PG table lock, CLI write error exits 1 (see N-446).
- [x] **Hostile zips** (both apps, both DBs, ~45 cases).
  - Refused with 422 and the same text as legacy: `../`, absolute, backslash, drive letter, NUL, case variants, upper-case hex, 100,001 entries (100,000 accepted), >4 GB declared, truncated/half/empty/not a zip, bad/deep JSON, wrong types, nested arrays, row length, version 2 or `"1"`, newer schema, unknown table or column (incl. SQL text in a name), missing items.
  - Symlink entries are written as plain bytes or skipped; no link is created anywhere.
  - Nothing appeared outside the data dir.
  - A failed restore leaves every DB table intact, but not all files (L-440). Huge `backup.json` → 500 on both apps, DB intact (L-441, N-442).
- [x] **Permissions and CSRF.** 908 cases per app: 5 routes × 7 methods × anon/no cookie/stale/viewer/editor/backup-only role/disabled/admin, with sign-in on, guests and sign-in off, with and without token.
  - Status, redirect, content type and cookies match legacy in all 908. Only the Cache-Control wording differs (both no-store).
  - Probe: 204/401/403 as legacy, empty body, no cookie, no session file. This resolves #132's nginx 500 (not run under nginx here).
- [x] **Races.** `PHP_CLI_SERVER_WORKERS=4`, 30 rounds each of restore vs restore, restore vs 3 writes, backup vs restore, on both DBs.
  - CI4: 0 bad rounds and 0 × 500. The final state is one whole backup, with no orphans. Each downloaded backup is entirely pre- or post-restore.
  - Legacy PG: 16 restore 422s in restore vs restore.
  - PG lock: a write open before the restore is replaced on CI4, where legacy PG keeps it (a mixed catalog). SQLite matches.
- [x] **Memory** (256 MB limit; peak RSS, items × 2 KB notes):
  - Backup: CI4 54–59 MB at any size. Legacy 187 MB at 20k and fails (500) from 50k.
  - Restore: both apps restore 50k (102 MB JSON, ~300 MB RSS; CI4 2.4× slower) and both answer 500 at 75k. Parity, accepted. See L-441.
- [x] **Mutants: 19/23 killed:** names, duplicates, entry cap ±1, size cap, PG lock and its mode, columns, tables, version, schema, attachment sniff, original names, route permissions, probe gate and codes, web settings actor, plain values. Survivors: N-444.

## Findings

- **L-440:** A failed restore says "Nothing was changed", but the files are not rolled back (legacy has the same gap):
  - Photo, attachment and original files from the zip stay.
  - Existing photos' links to their camera originals are rewritten to the zip's camera files.

  Hardening: write the original links only after the rows commit, and undo new files on failure.
- **L-441:** CI4 now writes backups it cannot restore. At 100k × 2 KB it writes a 204 MB `backup.json` (legacy failed to back that up). Neither app restores past ~100 MB at 256 MB, and the restore answers 500, not a message. Hardening: refuse with 422 above a limit tied to `memory_limit`, warn when backing up, or stream the restore.
- **N-442:** No per-entry size cap. A referenced entry up to the 4 GB total is read whole; at 1 GB the PHP process dies (both apps; no DB change). Cap each entry.
- **N-443:** The web restore applies the backup's settings (guest access, public URL, proxy trust, session lengths), also for a role with only `backup`. Sign-in on/off stays protected. Same on legacy. Hardening: need `settings`, or keep the access settings.
- **N-444:** Untested checks (surviving mutants):
  - the end anchor of the `files/` name rule;
  - the JPEG check on restored photos;
  - the confirm token format;
  - the decode limit as `restorePhotos` uses it.
- **N-445:** On PG, a restore that meets a write touching items and then locations (e.g. an import creating locations) loses the deadlock. It answers the generic 422 and leaves the catalog intact; suggest retrying.
- **N-446:** CLI:
  - Backing up into a missing directory prints a raw error and "Server error" (exit 1), not "Cannot write …".
  - The backup zip's mode follows the umask (0644, as legacy). Hardening: write it as 0600.
- **N-447 (Cloudflare):** Backups read images, thumbs, files and originals from local paths, not Storage. Also local-only:
  - `ZipArchive` on local files;
  - zip and JSON temp files in `DATA_DIR/tmp`;
  - `move_uploaded_file`;
  - an hourly sweep with `glob`/`filemtime`/`time()` (not Clock).

  Restore writes go through Storage. `getenv`/`putenv` appear only in the CLI.
- **N-448:** On PG, CI4 accepts JSON booleans for integer columns (stored as 1/0), where legacy PG refuses with 422 (legacy SQLite accepts). A lenient deviation that isn't recorded.

## Security

- Entry names are allowlisted; tables and columns are checked against the schema before any SQL.
- Count and size caps hold; there are no links and no writes outside the data dir.
- Every route except the probe needs `backup`, and all POSTs need CSRF. The probe leaks nothing and starts no session. The PG lock removes legacy's mixed-catalog race.
- **Security verdict:** no H/M. L-440 (file atomicity) and N-442/N-443 are hardening.
