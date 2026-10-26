# QA: apotheke #127, CI4 CSV import and export (PLAN 14, part 1)

- **PR:** [#127](https://github.com/thordin9/apotheke/pull/127). **Verified head:** `138dbc6d019b26e6d9803404d587ba80bfc19f65` (plain merge of `feature/ci4` `d0a3916`, #124). Content `5cc01b8` (`9805bb9`, `02422b4`, `4729c93`, `5cc01b8`) reviewed against base `3c67df7`. Earlier merge `249a859` (#125) also read.
- **Verdict: PASS.** 0H / 0M / 1L / 6N. L-370 is a follow-up, needed before the Cloudflare phase.

## Checklist

- [x] Merges: `249a859` and `138dbc6` (`--remerge-diff`): only HANDOFF conflicts, resolved correctly. Files differing from both parents: CHANGELOG, ci.yml, HANDOFF (and Routes/PermissionFilterTest for `249a859`), each a clean union. Diff-of-diffs: the PR's code diff is byte-identical on `138dbc6`.
- [x] Gates (`138dbc6`): lint ok (212 links 0 broken); check-commits 26 ok; gitleaks 8.30.1 `3c67df7..138dbc6` no leaks (`.gitleaks.toml` `b5bc9f98ad4322e0fab57368081e7e7ce1df7146ff018701a51030a515993a8f`); deptrac 0 errors; DI OK. Same on `5cc01b8` and `249a859`.
- [x] Suites, PHP 8.4: `138dbc6` CI4 app SQLite 788 (7 skip) / PG17 788 (6 skip) and module suite 635 (both drivers); `5cc01b8` 779 / 779 and module suite 628. 0 failures; skips are environment guards. `Ci4ImportExportParityTest`, `Ci4ImportRaceTest` and `ImportsTest` (both drivers) ran.
- [x] Playwright CI4 `import.spec.ts`: desktop-chromium + chromium-pixel 4 passed, pgsql-smoke 1 passed.
- [x] Parity (legacy PHP 8.3 vs CI4 8.4, SQLite and PG17): 278 QA cases each. Export bytes, BOM, `,` delimiter, quoting, column order, filename, `Content-Disposition`, cache headers, upload answers, stored files, preview/commit pages and all tables (items, tags, locations, categories, history incl. moves and lends, imports) are identical, except N-371 and N-372.
- [x] Formula injection: cells starting `=` `+` `-` `@` TAB CR (also after apostrophes) get a leading `'` in every column, as in legacy; the round trip restores them.
- [x] Uploads: 5 MB exact accepted, +1 byte 422; 5,000 rows ok, 5,001 422; NUL in the first 4 KB 422 (later NUL stored, as legacy); BOM, CRLF, CR, `;`, tab, Windows-1252, invalid UTF-8, malformed quotes, 1 MB field, empty, wrong MIME/extension, `../` and `\` names, `.htaccess`, markup: same as legacy. The file is always `DATA_DIR/tmp/import-<id>.csv` through the Storage interface; the client name is only a label. A committed file is deleted.
- [x] Dry run: two previews change nothing (tables and files identical).
- [x] Permissions (anonymous, viewer, editor, import-only, export-only, admin; sign-in on, guests on, sign-in off; GET/HEAD/POST/PUT/DELETE): identical to legacy except N-372.
- [x] CSRF: missing or wrong token answers 419 on upload, preview, commit and missing imports (N-371).
- [x] IDOR: imports are shared by design, as in legacy: anyone with `import` can open another user's import, nobody without it.
- [x] XSS: markup in file names, headers, cells, locations, categories, tags and error messages is escaped on the home, history, map and preview pages. All 258 HTML answers send nosniff and the strict CSP.
- [x] Rollback: a statement failing on row 3 of 4 answers 500 and changes nothing (only the saved mapping, as legacy). Row errors without "skip" import nothing.
- [x] Concurrency (`PHP_CLI_SERVER_WORKERS=4`, 30 rounds of 40 rows each; 2 commits, 3 commits, commit+preview, preview+commit): CI4 imports each file exactly once, with no partial rows, no 500s and the file removed, on SQLite and PG. Legacy imported one file twice (SQLite, 2/30 triple submits) and answered 500 86 times (SQLite 27, PG 59).
- [x] Memory: 5 MB / 5,000-row import ok on both apps. Export: see L-370.
- [x] Mutants: **17/18 killed** by the PR's tests (permissions, CSRF filter, lock and its SQLite/PG variants, done check, dry run committing, commit with errors, savepoint, `@` guard, unprotect, escaping, size and NUL checks). The survivor (TAB/CR guard) is killed by the QA parity probe (N-375).

## Findings

| ID | Grade | Status | Finding |
|---|---|---|---|
| L-370 | Low, availability | Open, regression | `/export.csv` builds every row and the whole file in memory. At the shipped 256 MB limit it answers 500 from 100k items with short notes on PG (~18 MB file; 40k with 2 KB notes), and twice that on SQLite. Legacy streams and served 200k. Stream the rows into the response; a Workers-sized memory limit will need that too. |
| N-371 | Nit | Accepted deviation, document | The CSRF check runs first wider than described: besides a missing import (legacy 404), a POST without a token to a done import gives 419 (legacy 303 to the done page) and `POST /import/{id}` gives 419 (legacy 404). Safer. Mention it in the CHANGELOG. |
| N-372 | Nit, parity | Open | PUT, PATCH and DELETE on `/import` show the import page on legacy (200) and 404 on CI4, the N-63 class. Add the verbs (CSRF still applies) or record the deviation. |
| N-373 | Nit | Pre-existing (legacy too) | A preview racing a commit can answer 410 "The uploaded file is gone" although the import just finished (CI4 30/120 rounds). After the lock, send a done import to the done page as commit does. |
| N-374 | Nit, hardening | Pre-existing (legacy too) | Uploads that are never imported stay in `DATA_DIR/tmp` and the history forever, 5 MB each. Prune old waiting uploads, or cap them per user. |
| N-375 | Nit, tests | Open | No PR test exports a cell starting with TAB or CR, so dropping them from the formula guard survives. Add one to the golden export. |
| N-376 | Nit, Cloudflare phase | Open | The shared `imports` service keeps `import_create_missing` from the first request (as N-246); `upload()` reads PHP's upload temp file directly and `CsvCells::parse()` spills to a temp file above 2 MB. Pass the upload through Storage or a stream before a long-lived worker or R2. |

Legacy follow-up for `main`: port the import lock and the in-transaction "done" mark (double import and 500s above).

## Security

The import and export match legacy. Cells that start a formula are neutralised, uploads are size, row and NUL checked and always stored under a fixed name through the storage layer, the dry run writes nothing, permissions and CSRF match legacy or are stricter, every user value is escaped, a failed run changes nothing, and concurrent submits import a file once. Open items are hardening, memory and test follow-ups only.

**Verdict: PASS at `138dbc6d019b26e6d9803404d587ba80bfc19f65`** (0H / 0M / 1L / 6N).
