# QA: apotheke #131, CI4 step 14 follow-ups (#127 findings)

- **PR:** [#131](https://github.com/thordin9/apotheke/pull/131). **Verified head:** `857f428c2daf984f494ec6dc39aa1239fea12fca`, a plain merge of `feature/ci4` `d8033ab` (#128) into content `b8ed66b`. Base `16c54b7` (#127 merged).
- **Verdict: PASS.** 0H / 0M / 0L / 6N. L-370 and N-371 to N-375 are closed.

## Checklist

- [x] Merge `857f428` (`--remerge-diff`): the only conflicts are in CHANGELOG and HANDOFF, both resolved as a union. Three files differ from both parents: CHANGELOG, HANDOFF and `tests/lib.php` (a clean union). The diff-of-diffs, excluding HANDOFF, CHANGELOG and docs/qa, differs only where `$pageVerbs` became `$importVerbs = ['GET','HEAD','POST','PUT','PATCH','DELETE']`, the same six verbs. Lines 1–80 of Routes.php (the #128 `add()` auth routes) are identical to `d8033ab`.
- [x] Gates on `857f428`:
  - lint ok, check-commits 22 ok, deptrac 0 errors, DI OK.
  - gitleaks 8.30.1 on `16c54b7..857f428`: no leaks (`.gitleaks.toml` `b5bc9f98…993a8f`).
- [x] Suites on `857f428`, 0 failures; skips are environment guards:
  - CI4 app suite, PHP 8.4: SQLite 790 tests (7 skip), PG17 790 (6 skip).
  - CI4 module suite: 649, both drivers.
  - Legacy suite, PHP 8.3: SQLite 790 (72 skip), PG 790 (72 skip).
  - Playwright CI4 `import.spec.ts` (desktop-chromium and chromium-pixel): 4 passed.
  - GitHub CI on `857f428`: 22/22 green (last check 00:45 EET).
- [x] **L-370 (streamed export).** Same seed on both apps: formula cells incl. TAB/CR, quotes, newlines, BOM, emoji, tags across batch edges. Server memory peak (VmHWM), wall time:

  | Items × notes | CI4 SQLite | Legacy SQLite | CI4 PG | Legacy PG | `16c54b7` |
  |---|---|---|---|---|---|
  | 100k × 100 B (18 MB) | 57 MB 1.3 s | 60 MB 0.6 s | 56 MB 2.3 s | 108 MB 0.8 s | SQLite 297 MB; PG 500 |
  | 100k × 2 KB (208 MB) | 60 MB 1.9 s | 61 MB 1.1 s | 59 MB 2.5 s | 291 MB 1.3 s | 500 |
  | 200k × 100 B (37 MB) | 57 MB 2.5 s | 68 MB 1.3 s | 56 MB 5.4 s | 166 MB 1.5 s | 500 |

  - In every row, both apps return 200 and byte-identical files (same sha256), including the BOM, the header, quoting and the `'` guard on TAB/CR cells.
  - GET and HEAD headers are unchanged from `16c54b7`.
  - The new `ExportMemoryTest` (24 MB limit) fails with a 500 on `16c54b7`'s code (SQLite and PG) and passes on `857f428`.
- [x] **N-371:** answers unchanged (419 before the import lookup), now in the CHANGELOG. Accepted.
- [x] **N-372:** PUT, PATCH and DELETE on `/import` return the upload page (200) with a token and 419 without one, as documented. On `/import/{id}` and on preview they return 404, or 303 to the done page once the import is done, as on legacy. A viewer gets 403.
- [x] **N-373 race.** Real HTTP race, `PHP_CLI_SERVER_WORKERS=4`, 30 rounds of 40 rows, four patterns (2 commits, 3 commits, commit+preview, preview+commit), on SQLite and PG17. Bad round = an import not exactly once, partial rows, a duplicate location or tag, or a file left behind.
  - CI4 `857f428`: 0 bad rounds, 0 × 500, 0 × 410. A preview that lost the race gets 303 to the done page (SQLite 35, PG 31).
  - `16c54b7`: 410 on SQLite 14 times, on PG 18 times.
- [x] **N-374 sweep.**
  - An upload not imported after 23 h 59 min keeps its file. After 24 h 01 min the next listing removes it; the history row stays and opening it answers 410.
  - Tested under the timezones UTC, UTC+14 and UTC−10. Other files in `tmp/` are untouched, and only uploads still in `uploaded` status are swept.
- [x] **N-375:** `CsvExportTest` exports TAB and CR cells. The TAB/CR mutant is now killed.
- [x] Legacy locking: this tree's legacy copy is **v2.6.20**, which predates main #130 (v2.6.22). See N-424.
- [x] Mutants: **16/17 killed**, all by the PR's tests. Killed mutants:
  - Streaming: unbounded batch, buffered SQLite read, no PG cursor, no flush, no early query, no rollback, no REPEATABLE READ.
  - Import fixes: done check for commits only, preview ignoring `done_before`, map page for any non-POST, PUT dropped from `$importVerbs`.
  - Sweep: any status, flipped cutoff, call removed, 0 s.
  - The TAB/CR guard.

  The survivor is N-422.

## Findings

- **N-420:** OPTIONS and TRACE on `/import` answer 404 on CI4, while legacy shows the import page (200). OPTIONS on a done import answers 404 where legacy answers 303. #128 fixed the same gap on the sign-in pages with `add()`; `$importVerbs` keeps the old six verbs. Use `add()` or list these verbs too, and test them.
- **N-421:** On PG, the export holds a read-only REPEATABLE READ transaction and its cursor open until the last byte reaches the client. A slow or stalled download (a user with `export`) keeps a snapshot and a connection open. Legacy reads everything first, so it does not. Hardening: set `idle_in_transaction_session_timeout` or `statement_timeout` for the export, or a send timeout.
- **N-422:** Mutant survivor: dropping `WHERE it.item_id IN (…)` from the per-batch tag query passes every test. The output stays correct, but each batch then reads every tag row: memory grows with the number of tags, and time grows quadratically. Add a check, e.g. count the tag queries or test with many tags under the 24 MB limit.
- **N-423 (Cloudflare phase):**
  - `StreamedResponse` writes through `echo`, `ob_flush()` and `flush()` to the SAPI. On Workers it needs a stream adapter.
  - The PG export relies on a server-side cursor inside a transaction that lasts for the whole response.
  - `sweep()` runs on the request path on every GET of `/import` and every upload. On Workers that is a scheduled job.
  - The Storage interface is used throughout. N-376 still stands: the upload temp file and the `CsvCells` spill file.
- **N-424:** Legacy in this tree predates main #130 (v2.6.22 import locking), so the parity tests compare against old legacy. On this tree, legacy:
  - imported one file twice (SQLite, 1/30 rounds of 2 commits);
  - left 15 commits unimported with a 500 while a preview ran;
  - answered 500 on SQLite 56 times and on PG 64 times.

  Main v2.6.22 shows no double imports and no 500s, but previews still get 410 (SQLite 13, PG 11). CI4 already locks like #130, and returns 303 where #130 returns 410 (as the CHANGELOG says). Bring #130 into `feature/ci4` and re-run the parity tests.
- **N-425:** The CI4 export takes about 1.8–3.7× legacy's time: 200k items on PG take 5.4 s against 1.5 s, because of a tag query per batch of 500 and generator overhead. Acceptable; note it for the Cloudflare CPU limits.

## Security

- Every non-safe method on the import pages, including PUT/PATCH/DELETE, still needs the CSRF token. The `import` and `export` permissions are unchanged (viewer 403).
- The sweep deletes only `tmp/import-<id>.csv` files through Storage, for rows in `uploaded` status older than 24 h. It takes no client input.
- The export SQL takes no input: the tag IDs are integers from the database.
- The headers on the streamed answer (CSP, nosniff, frame and referrer, caching, disposition) are identical to `16c54b7`.
- **Security verdict:** no issues. N-421 is hardening.
