# QA: apotheke #114, CI4 QR labels, barcode lookup and lending (PLAN 13, part 1)

- **PR:** [#114](https://github.com/thordin9/apotheke/pull/114). **Head:** `cafa46985258eb10e737eee1b6406c6343b763d6` into `feature/ci4` `194074d` (tip verified; merge-base = tip). Commits `6a6d4e0`, `d5093ae`, `9f1adca`, `32e5e89`, `cd49326`, `cafa469`.
- **Verdict: PASS.** 0H / 0M / 1L / 7N. No blocker; L-230 and N-231 are pre-existing in legacy too.

## Checklist

- [x] Gates: lint ok (PHP, JS, shellcheck, actionlint, 212 links 0 broken, changelog links); check-commits 6 ok; gitleaks 8.30.1 `194074d..cafa469` no leaks (`.gitleaks.toml` `b5bc9f98ad4322e0fab57368081e7e7ce1df7146ff018701a51030a515993a8f`); deptrac 0 errors; DI OK.
- [x] Suites: CI4 PHP 8.4 SQLite 760 (41 skip) / PG17 760 (8 skip); legacy PHP 8.3 SQLite 760 / PG 760; CI4 module 569. 0 failures. The one incomplete test is the PG-unreachable case on SQLite legs.
- [x] Skips: only `labels.spec` "print labels for a selection" (`/settings/labels`) added to `CI4_UNPORTED`; the `list.spec` labels empty state left the list and passes on CI4. New PHPUnit skips are PHP 8.4 / zbarimg guards only.
- [x] Playwright CI4 (desktop-chromium + chromium-pixel): labels, barcode, lending 8 passed, 2 skipped (the case above).
- [x] Parity: 298 QA cases x 2 drivers (hostile names, borrowers and locations; barcode `/`, `+`, `.`, `%2F`, `%252F`, unicode, invalid UTF-8, empty, 2000 chars, CRLF, dot segments; unknown, zero, huge ids; lending a lent item, returning a non-lent item twice; dates; permission matrix; CSRF; forced failures). Status, headers, body and `items`/`activity` byte-identical to legacy, except the accepted deviations in N-234/N-235.
- [x] Escaping: `<script>`, `"><svg onload>`, `]]>`, entities, RTL/zero-width in names, locations and borrowers are inert in `/labels`, `/item/{id}/label`, `/lent`, the item page and the SVG `aria-label` (the QR SVG has no `<title>`). No raw payload in any HTML body.
- [x] SVG is inline only: `/item/1/label.svg`, `/item/1/qr`, `/qr.svg`, `/labels.svg`, `/labels/1` and `?format=svg` are 404 on both. All 64 HTML 200s on CI4 send nosniff, `text/html; charset=UTF-8` and the strict CSP; the parity test asserts all three.
- [x] Permissions (anonymous, viewer, view, view+stocktake, view+lend, view+labels, lend+labels without view, admin; GET/HEAD/POST/PUT/DELETE): identical to legacy. Without the permission an existing and a missing item both answer 403. No extra 403/404 leak.
- [x] CSRF: missing or wrong token on lend/return answers 419 for existing and missing items; anonymous goes to `/login`, viewer 403. Same as legacy.
- [x] QR JIT: with `opcache.jit` 1235, 1205 and `function`, `QrCode` is not loaded before `guard()`, and JIT is off afterwards; `tracing` and 1254 stay on. 300 codes are identical to the no-JIT output.
- [x] Media: only `ImageStore::validName()`; `/lent` uses `/media/thumb/<valid>.jpg` only, and `../../etc/passwd` is refused (box icon). No Media, Autofill or legacy files changed.
- [x] Forced failures (trigger on `items` UPDATE and `activity` INSERT, lend and return): 500, tables unchanged, both apps and drivers.
- [x] Concurrency (25 bursts of lend/lend/return/return, 4 workers): PG no 500 on either app; SQLite see L-230.
- [x] Cloudflare phase: no superglobals, statics, file writes or new storage; see N-237.
- [x] Flake watch `testEditsAtOnceKeepTheOriginal`: 20/20 (8 in suites + 12 extra under load).
- [x] Mutants (permissions, escaping, redirect, transactions, JIT, thumbnails): **13/20 killed**. Survivors: N-233.

## Findings

| ID | Grade | Status | Finding |
|---|---|---|---|
| L-230 | Low, availability | Pre-existing (legacy too), follow-up | On SQLite, concurrent lend/return answer 500 "database is locked": CI4 5/100, legacy 8/100. Take the write lock first (as in the M-200 fix) in both apps. |
| N-231 | Nit | Pre-existing | Two returns at once on PG can both log "returned" (legacy 2/25 rounds). Use a conditional UPDATE (`WHERE lent_to IS NOT NULL`) and check the row count. |
| N-232 | Nit, tests | Open | Parity case "lend without CSRF" passes `['csrf' => 'nope']`, but `['csrf' => $token] + $fields` keeps the real token, so it returns item 1 and never tests CSRF. QA checked 419 separately. |
| N-233 | Nit, tests | Open | Surviving mutants: `/lent` name, borrower and aria-label unescaped (no hostile value on the lent list), thumbnail path or `validName()` dropped (no valid image), `guard()` removed (no runtime JIT test), `data-url` raw (near-equivalent). QA's probe kills the escaping and thumbnail mutants. |
| N-234 | Nit | Pre-existing driver behaviour | A borrower name with a null character: CI4 SQLite stores `lent_to` cut at the NUL, but the history keeps the full value; legacy SQLite stores it whole; both PG store the cut value. Strip control characters in `cleanLine()` in both apps. |
| N-235 | Nit | Accepted deviation | CI4 is stricter than legacy: ids beyond int4 on PG give 404 (legacy 500), and `/barcode/%00` gives 303 (legacy 500 without security headers). Legacy fix is for main. |
| N-236 | Nit, architecture | Open | deptrac lets Lending use the whole Media layer, though only `ImageStore::validName()` is used. Narrow it to a class-level layer. |
| N-237 | Nit, Cloudflare phase | Open | The shared `labelsView` keeps the first request's Host and public base and the label settings. Build it per request before a long-lived worker. |

## Security

Labels, barcode lookup and lending match legacy. Every user value is escaped in HTML and in the SVG label, the SVG is only inline, pages send nosniff and the strict CSP, permissions and CSRF match legacy, failed writes change nothing, the QR encoder loads after function JIT is off, and only thumbnails are shown. Open items are hardening and test follow-ups only.

**Verdict: PASS at `cafa46985258eb10e737eee1b6406c6343b763d6`** (0H / 0M / 1L / 7N).
