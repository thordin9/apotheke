# Apotheke PR #116 — main sign-in NUL-name QA

| | |
|---|---|
| PR | https://github.com/thordin9/apotheke/pull/116 |
| Base | `0d9f6f3` (origin/main tip, **v2.6.17**) |
| Head | `b06aa7937a48ce08ce5a368774d3c2480c0679c0` |
| Scope | Legacy PHP 8.3; SQLite + PG17. Fixes **M-220** / **N-221**. |
| CI4 leading/trailing counterpart | **Open** (out of scope). |

## Checklist

| Check | Result |
|---|---|
| Main tip = `0d9f6f3` / v2.6.17 | Pass |
| Head NUL login → 401 (all positions) SQLite+PG | Pass |
| Head re-enable NUL → 403, auth off, no create | Pass |
| Base `0d9f6f3` same probes → 303 (SQLite lead/trail; PG nearly all) | Pass |
| Other `user_by_name` callers safe (no new 500s) | Pass |
| Limit: `ada\0` → ada bucket; address cap; no bypass | Pass |
| Non-NUL auth parity vs base | Pass (identical) |
| Timing (≈50 tries, clear between) | Pass (constant offset; N-250) |
| New test fails on old main both drivers | Pass |
| Lint / links / shellcheck | Pass |
| gitleaks `0d9f6f3..HEAD` | Pass (0 leaks; 2 commits) |
| `.gitleaks.toml` sha256 | `b5bc9f98ad4322e0fab57368081e7e7ce1df7146ff018701a51030a515993a8f` |
| Legacy suite PHP 8.3 | **501/501** SQLite; **501/501** PG17 (3 skipped each) |
| Mutants on NUL checks | **3/3 killed** |
| `testEditsAtOnceKeepTheOriginal` flake ×10 | 0/10 fail both drivers |

## Security

**Fixed (claimed):** `user_by_name()` returns null before bind if the name contains NUL. `/login` and `/settings/password` (re-enable) inspect the **raw** submitted name before `clean_line()`, so any NUL → usual 401 (re-enable 403, nothing created). Rate limits still recorded first: trimmed `ada\0` shares ada’s `login` bucket; inner-NUL variants get their own bucket and still count toward the address cap.

### Evidence (throwaway `qa116_`; both drivers)

**Head — login NUL → 401, never signed in:** inner `ada\0x`, lead `\0ada`, trail `ada\0`, `ADA\0`, multi, only-NUL, spaced/cased variants; right password on NUL name still 401. Control: `ada`+right → 303.

**Head — re-enable:** `/settings/password` mode=change with NUL names → **403**, `auth_enabled=false`, user count unchanged; ada+right → 303 turns auth on.

**Base `0d9f6f3`:** SQLite lead/trail/case/space-trail → **303** (signed in as ada). PG: `ada\0x`, lead, trail, multi, case → **303**; only-NUL and mid-only `a\0da` → 401. Re-enable on base turns auth **on** for those bypass names. PG 40× inner-NUL + right pw → **signed=40** (bypass).

**Limits (head):** 9× `ada\0` → adaBucket=9, then ada+right → 303; after 10× both `ada\0` and ada → **429**. 40× unique inner-NUL → 30×401 + 10×429, signed=0, then ada → 429 (address cap). Bypass gone.

**Callers:** `user_by_name` / `user_verify` unit: null for NUL names. Register/admin-add: inner/trail NUL → 422; **leading** `\0…` still **303** creating the trimmed short name (same on base — pre-existing, not a sign-in bypass; see N-251). CLI argv truncates at NUL (C string): add/rename/role/delete/approve/password with embedded NUL match nothing useful / refuse; no 500. Import/restore/account paths do not bind raw NUL names into `user_by_name` in a new way.

**Non-NUL parity:** `ada` / `ADA` / ` ada ` + right → 303; wrong / unknown / unicode-unknown → 401 — identical head vs base, both drivers.

**Timing (median ms, n=50, `auth_attempts` cleared each try):**

| | wrong-pw | NUL-inner | NUL-lead | unknown | right-pw |
|---|---:|---:|---:|---:|---:|
| SQLite | 453.2 | 402.3 | 402.2 | 452.2 | 51.0 |
| PG17 | 469.8 | 418.7 | 419.4 | 470.3 | 67.7 |

NUL path skips `login()`/verify (~50 ms faster than wrong/unknown). Wrong ≈ unknown. **Not** an account-existence oracle.

**New test on old tree:** `testSignInNamesWithANullCharacterAreRefused` → FAIL SQLite (`\0ada` 303≠401); FAIL PG (`ada\0x` 303≠401). Head: OK both drivers (+ unit + edits flake watch).

**Mutants (SQLite, filter NUL tests):** drop `user_by_name` guard → unit FAIL; drop login raw short-circuit → HTTP 303≠401; drop re-enable `$nul` → 422≠403. **3/3 killed.**

## Findings (IDs from 250)

| ID | Sev | Note |
|---|---|---|
| M-220 / N-221 | — | **Fixed** by this PR (verified). |
| N-250 | N | NUL short-circuit ~50 ms faster than wrong/unknown; constant offset, not existence leak. |
| N-251 | N | Pre-existing: register/admin-add with **leading** NUL still creates trimmed short username via `clean_line` (same on `0d9f6f3`). Not a sign-in bypass. |
| — | Open | CI4 leading/trailing counterpart (out of scope). |

No new H/M. No secrets; no posts/pushes. Parallel `qa114_`/`qa115_` untouched.

## Verdict

**PASS** — M-220/N-221 closed on legacy main for SQLite and PG17; gates green; mutants killed; only informational N-250/N-251 and the open CI4 counterpart.
