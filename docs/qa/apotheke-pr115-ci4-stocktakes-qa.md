# QA: apotheke #115, CI4 stocktakes (PLAN 13, part 2)

- **PR:** [#115](https://github.com/thordin9/apotheke/pull/115). **Head:** `60682e7533103156d2f1b3db2ab1c81101c0cc80` into `feature/ci4` `194074d`, stacked on #114 (`cafa469`). Own commits `05f37dd`, `c03d232`, `b3be9ad`, `370f63f`, merge `6152d62`, `60682e7`. Only the delta over #114 is reviewed.
- **Verdict: PASS** (merge after #114). 0H / 0M / 1L / 6N. No blocker; L-240 and N-241 are pre-existing in legacy too.

## Checklist

- [x] Merge `6152d62` (`git show --remerge-diff`): only CHANGELOG/HANDOFF conflicts, resolved; no code change. `60682e7` fills the PR links; no placeholder left.
- [x] Gates: lint ok (212 links 0 broken, changelog links); check-commits ok; gitleaks 8.30.1 `194074d..60682e7` 11 commits, no leaks (`.gitleaks.toml` `b5bc9f98ad4322e0fab57368081e7e7ce1df7146ff018701a51030a515993a8f`); deptrac 0 errors; DI OK.
- [x] Suites: CI4 PHP 8.4 SQLite 761 (41 skip) / PG17 761 (8 skip); legacy PHP 8.3 SQLite / PG 761 (the stocktake golden test is guarded to PHP 8.4); CI4 module 581. 0 failures. No new CI4 skip.
- [x] Playwright CI4 (desktop-chromium + chromium-pixel): 12 passed, 2 skipped (only the `/settings/labels` case from #114); `stocktake.spec` runs on both projects.
- [x] Parity: 180 QA cases x 2 drivers (hostile location and item names, homes, barcodes; start twice, unknown, zero and huge ids; tick, untick, misplaced add, duplicate barcode, finish with and without moves, cancel, finish after cancel, report; permission matrix; CSRF; forced failures). Status, headers, body and `stocktakes`/`stocktake_lines`/`items`/`activity` identical to legacy, except N-242 and N-245.
- [x] Escaping: `<script>`, `"><svg onload>`, entities and RTL in names, homes, locations and errors are inert on the list, take page, history and report. Raw payloads appear only inside JSON strings (`application/json`, nosniff); the client puts messages in with `textContent` and only server-escaped HTML in `innerHTML`.
- [x] Headers: all 147 HTML pages and error pages send nosniff, `text/html; charset=UTF-8` and the strict CSP.
- [x] Permissions: `view` to look, `stocktake` to start, tick, finish and cancel, `move` also needed to finish with moves (403 without), a role without `view` 403. Identical to legacy; existing and missing ids answer the same.
- [x] CSRF: missing or wrong token answers 419 on every POST (see N-242 for missing ids). Anonymous goes to `/login`.
- [x] IDOR: lines are looked up by stocktake id and item id together, and writes only touch that stocktake's lines. Checking another stocktake's item adds it as misplaced to the open stocktake only, and the other stocktake is unchanged; identical to legacy on both drivers.
- [x] Media: only `ImageStore::validName()` + `/media/thumb/`; invalid names show the box icon.
- [x] Forced failures (trigger on `stocktakes` UPDATE, `stocktake_lines` UPDATE, tick, cancel): 500, tables unchanged, both apps and drivers.
- [x] Concurrency (30 rounds, 4 workers, PG and SQLite): see L-240, N-241.
- [x] Cloudflare phase: no superglobals, statics or file writes; see N-246.
- [x] Flake watch `testEditsAtOnceKeepTheOriginal`: 20/20.
- [x] Mutants (route and gate permissions, escaping, thumbnails, transactions, status checks, JSON codes, duplicate barcode): **20/20 killed** by the PR's own tests.

## Findings

| ID | Grade | Status | Finding |
|---|---|---|---|
| L-240 | Low, integrity | Pre-existing (legacy too), follow-up | The `open` status is read before the write transaction. Two finishes with moves at once both apply and log the "moved" history twice (PG: legacy 15/30, CI4 6/30), and a finish and a cancel can both answer 303. Use `UPDATE ... WHERE status = 'open'` and check the row count, in both apps. |
| N-241 | Nit, availability | Pre-existing | Two scanners adding the same misplaced item at once: the second hits the unique index and answers 500 (both apps, PG). SQLite gives "database is locked" 500s under load on both apps. Answer 409 or treat it as already added; take the write lock first. |
| N-242 | Nit | Accepted deviation | A POST without or with a wrong token to a missing stocktake answers 419 on CI4 and 404 on legacy, because the CSRF filter runs first. Safer, but the comment in `Routes.php` says it matches legacy; fix the comment. |
| N-243 | Nit, tests | Open | Golden case "start without CSRF" passes `['csrf' => 'nope']`, but `['csrf' => $token] + $fields` keeps the real token, so it never tests CSRF (same as N-232). QA checked 419 separately. |
| N-244 | Nit, architecture | Open | deptrac lets Stocktake use the whole Media and Activity layers, though only `ImageStore::validName()` and `ActivityView::time()` are used. Narrow them. |
| N-245 | Nit | Accepted deviation | Ids beyond int4 on PG give 404 on CI4 and 500 on legacy (as N-235). Legacy fix is for main. |
| N-246 | Nit, Cloudflare phase | Open | The shared `stocktakeView` keeps the `stocktake_apply_moves` setting from the first request. Build it per request (or read the setting per call) before a long-lived worker. |

## Security

Stocktakes match legacy. Every user value is escaped in HTML, JSON replies are only shown as text, pages send nosniff and the strict CSP, permissions (including `move` for moves) and CSRF match legacy, a check only changes the stocktake in the URL, failed writes change nothing, and only thumbnails are shown. Open items are hardening and test follow-ups only.

**Verdict: PASS at `60682e7533103156d2f1b3db2ab1c81101c0cc80`** (0H / 0M / 1L / 6N), after #114.
