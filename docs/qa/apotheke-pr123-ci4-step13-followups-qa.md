# QA: apotheke #123, CI4 step-13 follow-ups (#114/#115 notes)

- **PR:** [#123](https://github.com/thordin9/apotheke/pull/123). **Head:** `d915fe4d63531db1bee0274abb99cd1a37c4381b` into `feature/ci4` `83e733f073e256b4ae7d96660a43f91b375f1d40` (tip verified; merge-base = tip). Commits `1baa093` (L-230), `050b92d` (L-240), `ba99462` (N-211), `862a0ec` (N-232/N-243), `e55a646` (N-234), `f835135` (N-242), `a0ebe1f` (N-233), `d915fe4` (docs).
- **Verdict: PASS.** 0H / 0M / 1L / 3N. Every targeted item is fixed. L-340 is pre-existing (legacy too) and a follow-up.

## Checklist

- [x] Gates: lint ok (212 links 0 broken, changelog links); check-commits 8 ok; gitleaks 8.30.1 `83e733f..d915fe4` 8 commits, no leaks (`.gitleaks.toml` `b5bc9f98ad4322e0fab57368081e7e7ce1df7146ff018701a51030a515993a8f`); deptrac 0 errors; DI OK.
- [x] Suites, PHP 8.4: CI4 app SQLite 777 (7 skip) / PG17 777 (6 skip); CI4 module suite 613 on both. 0 failures. Skips are environment guards only. The new `LoansTest`, `StocktakesTest` (both drivers) and `QrJitTest` ran, none skipped.
- [x] DB compatibility: no migration or schema change; legacy code untouched; the parity goldens for labels, lending and stocktakes still match legacy on both drivers.
- [x] L-230: lend and return lock the item first (PG `FOR UPDATE`, SQLite a no-op write). HTTP race, 4 workers, 30 rounds each, CI4: two lends, two returns, lend + return: 0 errors and 0 bad histories on PG and SQLite (two lends log one `before: none` and one `before: <first>`). Legacy, same harness: PG 6/30 double lends and 7/30 double returns; SQLite 25 500s.
- [x] L-240: finish and cancel close only `WHERE status = 'open'` with a row-count check, as the first write. CI4, 30 rounds: two finishes with moves 303/409 every time, one "moved" entry; finish + cancel 303/409 every time, never "done" after a cancel or "cancelled" with moves. Legacy PG: 9/30 double finishes, 8/30 finish + cancel both 303.
- [x] N-234: control characters leave the borrower name (item and history alike), whitespace controls become a space; a name of only control characters is 422. HTTP check on both drivers. See N-342.
- [x] N-232/N-243: no-CSRF cases now send a wrong or no token; 419 and unchanged tables asserted.
- [x] N-233: `/lent` name, borrower, aria-label, thumbnail and invalid-name cases, the escaped `data-url` and a runtime JIT test (1235/function off, tracing kept) were added.
- [x] N-211: only the deleted actor may be signed out, and exactly one change must succeed. Flake watch `testDeletesAndDemotionsAtOnceKeepAnAdmin` and `testEditsAtOnceKeepTheOriginal`: 20/20 (CI4 and legacy, both drivers, 5 each).
- [x] N-242: `Routes.php` comment now says CSRF comes first.
- [x] Cloudflare phase: no statics, superglobals, file writes or new per-request state; see N-343.
- [x] Mutants: **16/16 killed**, including the earlier survivors (`/lent` name, borrower, aria-label, thumbnail path, `validName()`, JIT guard, `data-url`) and the new code (no lock in lend or return, PG without `FOR UPDATE`, SQLite read-only lock, close without `status = 'open'`, no row-count check, control characters kept, whitespace controls dropped, close moved last).
- [x] Not in scope, still open: N-235, N-236, N-237, N-241, N-244, N-245, N-246.

## Findings

| ID | Grade | Status | Finding |
|---|---|---|---|
| L-340 | Low, integrity | Pre-existing (legacy too), follow-up | A tick that races a finish checks `open` outside its transaction, so it can add a line to a stocktake that was just finished. The summary and the moves miss that item (PG: CI4 7/30, legacy 7/30). Make the tick's first write conditional on the stocktake still being open (as `close()` does) and port to legacy. |
| N-341 | Nit, availability | Pre-existing | On SQLite a tick during a finish can answer 500 "database is locked" (CI4 3/30, legacy 9/30): the tick reads before it writes. Taking the write lock first, as for L-230, covers it. |
| N-342 | Nit | Accepted deviation | CI4 now removes control characters from the borrower name and refuses a name made only of them (422). Legacy keeps them, and on legacy PG a NUL still cuts `lent_to` but not the history. Port to `main` as planned in HANDOFF. |
| N-343 | Nit, Cloudflare phase | Open | The lend/return lock relies on an interactive transaction with `FOR UPDATE` or a no-op write. A D1 port would need a compare-and-set `UPDATE` with a row-count check instead, like `close()`. |

## Security

The fixes only make concurrent writes safer: lends and returns serialise, a closed stocktake stays closed, and control characters no longer reach the item or its history. The new tests close the earlier escaping, thumbnail, JIT and CSRF test gaps, and every mutant of the new code is caught. Open items are hardening follow-ups only.

**Verdict: PASS at `d915fe4d63531db1bee0274abb99cd1a37c4381b`** (0H / 0M / 1L / 3N). The later plain merge with `feature/ci4` after #124 still needs a merge-only read.
