# Apotheke PR #134: CI4 step 16 part 1, parity gate QA

| | |
|---|---|
| PR | https://github.com/thordin9/apotheke/pull/134 |
| Head | `4fc120041f66c93cd8ac3d76d63c383758c8b326` (open; CI 40464849377 23/23 success, read-only gh) |
| Base | `feature/ci4` `31de273` (#133 merged), merge-base `31de273` |
| Scope | M-470/L-471 delta `8df9bfd..4fc1200` + merge-only read of `afa6e2c` (merge of `31de273`) |
| Earlier | Full gate at `8df9bfd`: CHANGES REQUESTED (M-470, L-471; N-473…N-477) |

## Checklist

| Check | Result |
|---|---|
| Gate | Pass: 196 steps, 14 areas, 0 diffs, SQLite and PG; 2 pending (Control Panel `json.html`, as before) |
| Budget (median gap, +8 / +20 ms) | Met: local median gap 2.1–3.2 ms SQLite, 11.8–13.6 ms PG |
| M-470 cookies + headers | Fixed: every Set-Cookie with attributes; CSP, COOP, CORP, Permissions-Policy, Referrer-Policy, HSTS, nosniff, XFO; Cache-Control, Pragma, Expires on every status |
| L-471 cache pair | Fixed: `PARITY_GATE_CACHE_PAIR` is a const, used only on non-JSON non-200 answers; no env var can widen it |
| L-471 hashes | Fixed: algorithm and cost kept, plus which scenario password each hash verifies; nia and Neon sign in |
| ItemController 422 | Correct: legacy's form page keeps PHP's session `no-store, no-cache, must-revalidate`; the gate kills the revert (2 steps) |
| Playwright on CI4 | Pass: CI 40464849377 runs auth, qa-fixes and qa-v2 with `E2E_APP=ci4` in the 8.4 shards |
| N-476 wording | Fixed: docs and output say budget on the median gap |
| Deferrals | N-473, N-474 and L-472 are in HANDOFF item 9 (L-472 is the item 8 boot-cost lane) |
| Merge `afa6e2c` | Clean (below) |
| Suites | CI4 8.4 sqlite 820 / pg 820 OK; CI4 module 668 OK ×2; `ParityGateTest` 12 OK |
| Legacy suites | Not rerun: the merge and the delta don't touch legacy code |
| gitleaks / lint / toml / allowlist | Clean on `8df9bfd..4fc1200` / clean / unchanged / unchanged |

## Security

**Injection re-check** (CI4 only, one change at a time, SQLite). Every one now fails the gate:

| # | Change | Steps |
|---|---|---:|
| D2 / D6 | theme cookie `SameSite=None` / wrong value | 3 / 3 |
| D3 | session cookie without HttpOnly | 5 |
| D4 / D5 | XFO changed / nosniff removed | 180 / 177 |
| L1 | `public, max-age=3600` on redirects and errors | 75 |
| H1 / H2 | cost-4 hash / hash of the wrong password | 7 / 117 |
| N1 / N2 / N3 | session `Secure` / theme `path=/x` / `Domain` added | 5 / 3 / 3 |
| N4 | session lifetime +30 d (Max-Age) | 5 |
| N6 / N7 | CSP `unsafe-inline` / Referrer-Policy removed | 179 / 180 |
| N8 / N9 | cache pair on a JSON answer / on a 200 page | 8 / 52 |
| N10 / N11 | session id alphabet (5 bits) / length 48 | 5 / 5 |
| F1 | no id regeneration at sign-in | 4 |
| N5 | Expires +6 h, Max-Age kept | survives (N-491) |
| F2 / F3 | `regenerate(false)` at sign-in / sign-out | survive (N-492) |

**Session id by shape.** It is compared as `<length alphabet>` (hex, sid or other). That is tight enough: a wrong length or alphabet fails, and a missing regeneration fails because no Set-Cookie is sent. Values can't be compared across runs anyway.

## Merge afa6e2c (31de273)

- **Conflicts:** `ci.yml`, `CHANGELOG.md` and `docs/HANDOFF.md`. The `--remerge-diff` resolution keeps both sides.
- **Files that differ from both parents:** ci.yml, CHANGELOG, `ci4/app/Config/Services.php` (auto-merged), HANDOFF and PLAN.
- **Diff-of-diffs** against #133's range (`7e927f7..31de273`, without HANDOFF, CHANGELOG and docs/qa): the only difference is the Playwright CI4 list, which keeps #133's `data.spec.ts` and #134's three specs.
- **ci.yml:** keeps both PRs' jobs (#133's restore steps and #134's `parity-gate`).
- **CHANGELOG:**
  - The released sections are byte-equal to `main`.
  - Unreleased keeps both blocks: #133 backup/restore, the #132 entry in #133's wording (the N-431 404 sentence is gone, correctly), and #134.
- **Backup/restore:** still out of the part 1 gate, and stated as such in gate.php, the CHANGELOG ("all but backup and restore") and HANDOFF ("part 2 covers them"). `Ci4BackupParityTest` covers it until then.

## Findings (new IDs from 490)

| ID | Sev | Note |
|---|---|---|
| N-490 | N | A false diff depends on file mtimes. `ci4/public/assets` is a byte-identical copy of `public/assets`, and both apps set `?v=` from mtime and size. The manifest JSON icon URLs are not normalised (HTML ones are). A fresh clone gave `DIFF core: GET /manifest.webmanifest` on both DBs, and `touch -r` cleared it. CI can flake when checkout crosses a second boundary. Normalise `?v=` in JSON too, or derive it from content. |
| N-491 | N | Cookie Expires is bucketed to minutes or days, so an Expires-only shift (+6 h) with the same Max-Age passes. Max-Age is exact and wins in browsers. |
| N-492 | N | `sessions/` is excluded and an old cookie is never replayed, so keeping the old session file at sign-in or sign-out passes the gate. F3 is killed by `Ci4AuthParityTest`. |

M-470 and L-471 are fixed. N-475 to N-477 are folded in. N-473, N-474 and L-472 are deferred (HANDOFF 9). No H, M or L.

The `Ci4BranchCiTest` pgsql probe error seen once came from my own parallel runs sharing the probe table; alone it passes 18/18.

## Verdict

**PASS** at `4fc1200`. Every injection from the full gate fails the gate now, and so does every new one except N5, F2 and F3 (N-491, N-492). The 422 fix is correct, CI4 Playwright runs the three suites, and the merge of `31de273` is clean. N-490 to N-492 are optional.
