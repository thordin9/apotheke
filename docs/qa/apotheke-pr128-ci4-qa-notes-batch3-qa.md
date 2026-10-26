# QA: apotheke #128, CI4 QA notes batch 3

- **PR:** [#128](https://github.com/thordin9/apotheke/pull/128). **Verified head:** `1eac9252701855029884818c188ab12b85e371f6`, a merge of `feature/ci4` `16c54b72e1679801660b71248772bc5b2af2c41d` (#127) into content head `0491ac1d2551db450a3ef220ab90982d553b0485` (content base `d0a3916`).
- **Verdict: PASS.** 0H / 0M / 0L / 2N new (N-380, N-381). L-340, N-341, N-351, N-352 and N-353 closed; N-350 still accepted.

## Checklist

- [x] Merge `1eac925`: remerge-diff conflicts only in HANDOFF (both sides kept). Files that differ from both parents: CHANGELOG, Routes.php, HANDOFF. Diff-of-diffs (`d0a3916..0491ac1` vs `16c54b7..1eac925`) is identical apart from one hunk offset. `ci.yml` comes from #127 (import spec).
- [x] L-340/N-341: `check()` writes first (`UPDATE … WHERE id=? AND status='open'`, one row or 409). Real HTTP tick-vs-finish race (`PHP_CLI_SERVER_WORKERS=4`, 30 rounds, 10 ticks plus 1 finish each): CI4 SQLite and PG17 add 0 lines after the finish, give no 500s, and log no lock errors.
- [x] Method matrix, CI4 (PHP 8.4) vs legacy (PHP 8.3), SQLite and PG17 (1,260 cases per app and DB, all the same on both DBs). Covers 4 configs (sign-in on, guest, registration closed, sign-in off) × 3 visitors × 3 routes × GET/PUT/PATCH/DELETE/OPTIONS/TRACE/CONNECT/PROPFIND × token none/header/form, POST with `_method`, and cross-origin requests. 1,122 identical; the rest are N-350 (non-safe `/account` without a header token gives 419, now also CONNECT) and PROPFIND 404 (known difference, pinned by a test).
- [x] N-351: PUT/PATCH/DELETE `/register` with registration open match legacy and create no account; my #125 M9 mutant is now killed by the repo tests.
- [x] N-352: POST with `_method` acts as a POST on both apps. Without a token it gets 419; a wrong password waits ≥400 ms and counts both rows; the address cap gives 429; registration stops at 5 (429). Output is byte-identical CI4 = legacy on both DBs.
- [x] N-353 and XST: OPTIONS, TRACE and CONNECT behave as on legacy; CONNECT `/account` needs the token. A marker cookie and header are never echoed (0 of 5,040 answers), and no `Allow` or other extra header is sent.
- [x] Max-Age: the Item and Layout parity tests use `cookie_times_settled()`.
- [x] Gates `d0a3916..1eac925`: check-commits 12 ok; lint ok (0 broken links); deptrac 0 errors; DI OK; gitleaks 8.30.1 no leaks; `.gitleaks.toml` `b5bc9f98ad4322e0fab57368081e7e7ce1df7146ff018701a51030a515993a8f`.
- [x] Suites at `1eac925`: CI4 PHP 8.4 SQLite 788 (7 skipped) / PG 788 (6); CI4 module 639 OK; legacy PHP 8.3 SQLite 788 / PG 788 (71 each). 0 failures. CI 40392701496 (`0491ac1`) and 40396963211 (`1eac925`): 22/22.
- [x] Mutants: 14/15 killed; the survivor is equivalent (dropping the early 409 before the transaction, which `stillOpen()` repeats). Author's survivor: `/account` with `none` is equivalent (legacy route_perm gives self); dropping the option is not (it fails closed) and is killed.
- [x] Cloudflare phase: no new statics, superglobals or `getenv`; `posted()` reads the request object.

## Findings

| ID | Grade | Status | Finding |
|---|---|---|---|
| L-340 / N-341 | Low / Nit | **Closed** | A late tick waits, then gets 409 and changes no line; SQLite takes the write lock first. |
| N-350 | Nit, hardening | Accepted | Non-safe methods on `/account` (now also CONNECT) need the header token where legacy shows the page. |
| N-351 | Nit, tests | **Closed** | Open-registration verbs are covered and mutation-tested. |
| N-352 | Nit, parity | **Closed** | `_method` no longer changes what the auth pages do. |
| N-353 | Nit, parity | **Closed** (PROPFIND known) | OPTIONS, TRACE and CONNECT at parity; WebDAV methods 404, pinned by a test. |
| N-380 | Nit, hardening | Follow-up | `add()` also registers the CLI route type, so `php ci4/public/index.php login` or `register` now runs the auth controllers (page or redirect only; nothing is posted). Use an HTTP-only method list if CLI routing is not wanted. |
| N-381 | Nit, tests | Follow-up | `tests/lib.php`: the `titles()` docblock now sits above `cookie_times_settled()`'s. |

Legacy on this tree still lets late ticks through (PG adds lines after the finish; SQLite gives 500s). The port to `main` is tracked in HANDOFF; it is not part of this PR.

## Security

Hardening at parity: the auth pages act only on a real POST, under the same permission and CSRF rules; the method override cannot skip the token, the sign-in limits or the pace; TRACE echoes nothing. Stocktake ticks cannot change a finished stocktake. No new state for the Cloudflare phase. Follow-ups: N-380, N-381.

**Verdict: PASS at `1eac9252701855029884818c188ab12b85e371f6`** (0H / 0M / 0L / 2N new).
