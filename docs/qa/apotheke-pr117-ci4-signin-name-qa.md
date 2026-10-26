# Apotheke PR #117 — CI4 sign-in NUL-name QA

| | |
|---|---|
| PR | https://github.com/thordin9/apotheke/pull/117 |
| Base tip | `c8163afa91c7c17aeb8f987c3263fcec285f4284` (`feature/ci4` at start) |
| Port head | `5c6bf1c2be902213ca228cb2423389d77bdde0fd` |
| Verdict head | `c606488a573ff161e5fd46c8247edbee7fec33c6` (merge of `68aaa86` / #110 onto the port) |
| Main sync | `a0753ad` ← `ff23b38` **v2.6.18** (#116) |
| Scope | PHP 8.4 CI4 + legacy legs; SQLite + PG17. N-221 port + main sync + #110 merge. |

## Checklist

| Check | Result |
|---|---|
| Base / heads resolve | Pass |
| CI4 NUL login → 401 all positions (both DBs) at c606488 | Pass |
| Re-enable NUL → 403, auth off, no create | Pass |
| Same probes → 303 on `c8163af` (lead/trail) | Pass |
| Byte status parity vs legacy `ff23b38` (both DBs) | Pass (0 mismatches) |
| Limits: `ada\0` → ada bucket; address cap; no bypass | Pass |
| Callers / N-251 parity (leading NUL create) | Pass (same as legacy) |
| Merge `a0753ad` (v2.6.18): remerge CHANGELOG only; VERSION 2.6.18 | Pass |
| Merge `c606488` (#110): remerge CHANGELOG+HANDOFF; both-parent files listed | Pass |
| `password()` → `Accounts::checkCurrent` (L-170); Accounts.php = #110 | Pass (0-byte diff) |
| N-221 kept after #110 merge (findByName + re-enable `$nul`) | Pass |
| CHANGELOG Unreleased Fixed: #110 then #117; released ≡ `ff23b38` | Pass |
| No `skipOnCi4` left in HardeningHttpTest (parity skips = vendor/8.4 only) | Pass |
| New unit test fails on `c8163af`, passes at head | Pass |
| Timing (CI4, n=50) | Pass (N-260) |
| Lint / deptrac 0 / DI OK | Pass |
| gitleaks `c8163af..c606488` | Pass (17 commits, 0 leaks) |
| `.gitleaks.toml` sha256 | `b5bc9f98…993a8f` |
| CI4 suites @ c606488 | sqlite: 716 / 1 status-0 flake (alone 5/5 OK); **pgsql 716 OK** |
| Mutants (NUL guards) | **3/3 killed** |
| L-170 + M-200 @ c606488 | Pass both DBs; M-200 0/5 fail |
| Cloudflare-phase (Auth delta) | Pass (no new static/superglobals) |

## Security

**Port (N-221):** `UserRepository::findByName` refuses any NUL in the **submitted** name before trim/key. `/login` and re-enable inspect raw input before `Text::cleanLine`; both rate limits still record first. Matches legacy 2.6.18 (#116).

### Evidence (`qa117_`; PHP 8.4)

**c606488 CI4 — login NUL → 401** (inner/lead/trail/multi/only/case/space; right pw on NUL still 401). **Re-enable** → **403**, `auth_enabled=false`, user count unchanged. Limits: 9×`ada\0` → adaBucket=9 then ada right 303; after 10× both 429; 40×inner → 30×401+10×429 signed=0 then ada 429. Identical on SQLite and PG17. Same status matrix as head legacy and `ff23b38` legacy.

**c8163af (pre-port):** lead/trail/case/space-trail → **303** (signed in); re-enable turns auth **on**. Inner NUL already 401 (#113 N-160). Unit `testANameWithANullCharacterFindsNoAccount` fails on base (`\0ada` finds ada); passes at head.

**Callers:** register/admin-add inner/trail → 422; **leading** `\0…` → 303 creates trimmed short name (**N-261**, same as legacy N-251). CLI argv truncates at NUL. No new 500s.

**Merges:** `a0753ad` remerge-diff = CHANGELOG only; files vs both parents include CHANGELOG/README/HardeningHttpTest. `c606488` remerge = CHANGELOG + HANDOFF; both-parent files: CHANGELOG, SecurityController, UserRepository, SignInAccountsTest, HANDOFF, HardeningHttpTest. Resolution keeps N-221 `$nul` / findByName guard and wires `checkCurrent` for L-170. `Accounts.php` unchanged vs `68aaa86`.

**Timing CI4 (median ms, n=50, clear between):** SQLite wrong/nul/unk ≈ **1007** (1 s deadline); PG ≈ **1031–1035**. Constant; not an existence leak (**N-260**).

**Mutants (sqlite):** drop findByName guard → 303≠401; drop login raw short-circuit → 303≠401; drop re-enable `$nul` → 422≠403.

**Suites:** CI4@c606488 pgsql **716 OK**; sqlite one `Ci4HttpTest::testShippedBootDoesNotWriteThePgsqlProbe` count drift under load — **alone 5/5 OK** (status-0). L-170 HTTP tests OK both DBs; M-200 edits race 0/5 fail both DBs.

## Findings (IDs from 260)

| ID | Sev | Note |
|---|---|---|
| N-221 | — | **Fixed** on CI4 (verified at c606488). |
| N-260 | N | CI4 sign-in deadline ~1 s; wrong≈NUL≈unknown. |
| N-261 | N | Leading-NUL register/admin-add still creates trimmed name (legacy N-251 parity; not a sign-in bypass). |
| N-262 | N | `testShippedBootDoesNotWriteThePgsqlProbe` drifts under parallel PG load; alone green. |

No new H/M. Open: none for this port (CI4 leading/trailing closed by #117).

## Verdict

**PASS** at `c606488` — N-221 on CI4, v2.6.18 sync, and #110 merge preserve L-170 and NUL refusals; gates green; mutants killed; only informational N-260–N-262.
