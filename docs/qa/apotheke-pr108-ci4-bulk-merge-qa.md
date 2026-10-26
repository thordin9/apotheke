# QA: apotheke #108, CI4 bulk, merge, duplicate, quantity, attachments (step 11b)

- **PR:** [#108](https://github.com/thordin9/apotheke/pull/108). **Head:** `c29203ce795768d09e8818a3427720cf859aa4ba` (75a80dc + #107 `7abebe0`). Feature: `fb5361c`, `0434eba`, `75a80dc`.
- **Verdict: PASS at `c29203ce795768d09e8818a3427720cf859aa4ba`.** 0H / 0M / 0L / 1N (N-150). N-112 closed.

## Evidence

**(a) Gates.** `.gitleaks.toml` sha256 `b5bc9f98ad4322e0fab57368081e7e7ce1df7146ff018701a51030a515993a8f`. gitleaks `HEAD^1~3..HEAD` and merge range: no leaks. `check-commits` on `cf387d6..75a80dc`: 3 commits ok. deptrac: 0 violations (786 allowed). DI (`scripts/ci4-arch.php di`): OK. `lint.sh`: PHP/JS/shellcheck/actionlint/changelog links ok; Markdown links **212 / 0 broken**. CHANGELOG released tail (`## [2.`) **equals `c917cdb`** (30801 B); differs from `main` `cbdae36` only by the v2.6.15 release chore. `docs/qa`: feature commits **66/66 byte-identical** vs `cf387d6`; merge adds only `apotheke-pr106-main-hardening-qa.md`.

**(b) Suites / Playwright (head `c29203c`).** CI4 module: SQLite **443** (1 skip), PG17 **558** OK. Full `APOTHEKE_APP=ci4` PHP 8.4: PG **701** (10 skip); SQLite **701** (43 skip, 1 incomplete). Legacy PHP 8.3: PG **701** (66 skip); SQLite **701** (92 skip, 1 incomplete). `Ci4BulkMergeParityTest`: **138** asserts OK on SQLite and PG. Playwright `E2E_APP=ci4` desktop-chromium+chromium-pixel (bulk/merge/duplicate/stock/attachments/users/roles): **26/26** passed. Merge-head filter (BulkMerge+ItemPhotos+AccountsCli+HardeningHttp): **21** tests / **1875** asserts OK on SQLite and PG (4 skips: CI4 rate-limit/last-admin not ported — #107 follow-up).

**(c) Bulk permissions + mixed IDs.** Role `sorter` = view+edit+bulk (no delete/move/labels): CI4=legacy **move 403, delete 403, labels 403, category 303**; viewer bulk **403**. Mixed ids `[Alpha,Alpha,0,-3,99999,Gamma]` → **303**, `bulk_done=2` (only positive existing). `range(1,501)` → **422**.

**(d) Undo bulk delete.** With `undo_seconds=30`: delete **303**, location carries `undo=<32 hex>`, item gone; POST `/undo` **303**, item restored. With `undo_seconds=0`: delete **303**, **no** `undo=` token, item gone (files not kept for undo). Parity also: files fingerprint unchanged across confirm+undo when token keeps files.

**(e) Merge.** Confirm page **200** with change list; viewer GET merge **403**. Caps: `QTY_MAX=1000000`, `NOTES_MAX=5000`. Merge Beta→Alpha: **303**, qty **5** (2+3), favourite kept, loan `Nikos` copied, notes contain `note-b`, Beta gone. Candidates via `ItemList::search` (same visibility as list); parity GET pick/search/confirm goldens match.

**(f) Duplicate.** Name of 200×`字` → copy length **200**, ends with ` (copy)` (`mb_substr(...,193)`). Fail duplicate (activity trigger): parity asserts **500** + tables/files unchanged (cleanup path). Missing-original (map present, bytes gone): CI4=legacy **500**, no new item (matches after #107).

**(g) Photos / originals.** After upload: original on disk ≠ served hash. URL tricks (`/originals/…`, `/originals/map/…`, `/media/full/../originals/…`, `/media/full/<64hex>`, `/images/<orig>`, `/files/…`, `/writable/data/originals/…`): all **404**, `original_leaked=false`. `/media/full|thumb/<served>` **200** with **not** original bytes. Duplicate with photos: `dup_orig_identical=true`, served ≠ original. Merge moves photos; originals kept (`merge_originals_kept=true`). Same on legacy.

**(h) Attachments.** Sniff: SVG/HTML **null**; PDF detected. Upload SVG/HTML **422**; PNG under `attach_types=pdf` **422**; oversize (>1 MB) **422**. Lied `fake.pdf` with PNG bytes stored as **`.png`**. CRLF name → stored `evil.pdf`; download disposition `attachment; filename="evil.pdf"; filename*=UTF-8''evil.pdf`, type `application/pdf`, **nosniff**, CSP `default-src 'none'; sandbox`, cache `private, no-store`. Viewer download **200** (view); viewer delete **403**; delete wrong item **404**. Traversal displayName → `passwd.pdf` (not a path).

**(i) Forced failures.** Parity, both DBs: for each of `fail qty|duplicate with photos|merge|bulk move|bulk delete|attach|attachment delete`, asserts `[status, unchanged, files unchanged] = [500, true, true]` (activity trigger; twin compare).

**(j) CSRF.** Missing/wrong token → **419** on `/bulk`, `/item/{id}/qty`, `/duplicate`, `/merge`, `/attachments`, `/attachments/{id}/delete` (CI4=legacy). Valid token qty/attach/delete → **303**.

**(k) Concurrency (CI4=legacy).** Double merge A→B and A→C: codes **[303,404]**, A gone. Merge C→B vs bulk delete C: **[303,303]**, C gone (one winner). Concurrent bulk category+tag_add on same ids: **[303,303]**, no crash.

## Findings

| ID | Grade | Status | Finding |
|---|---|---|---|
| N-150 | Nit, tests | Open | Dropping download `nosniff` survives parity (not asserted). Mitigated by `attachment` + CSP sandbox + sniff. Assert `nosniff` on the download golden. |
| N-112 | Nit, e2e | Closed | users/roles/bulk/merge/duplicate/stock/attachments on CI4 Playwright list. |

## Security

Authz per bulk action; sniff-only uploads; sandboxed downloads; Writes + fail-closed; CSRF on POSTs; originals not HTTP-reachable; `Core\Storage` (no statics/superglobals). #107 CI4 rate-limit/last-admin still follow-ups.

**Verdict: PASS at `c29203ce795768d09e8818a3427720cf859aa4ba`** (0H / 0M / 0L / 1N).
