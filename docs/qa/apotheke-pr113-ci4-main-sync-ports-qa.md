# QA: PR #113: CI4 sync of main 2.6.16/2.6.17 + ports

Head `c8554af70ec7f2008a7b5a43dcd962e10839461e`. Base tip `194074dea994d62f657dbad5265d0b232ce43179` (`feature/ci4`, verified). Main at `0d9f6f3` (v2.6.17).

**Verdict: APPROVE.** Merges are clean; every claimed port matches legacy on SQLite and PG17. One Medium finding is on **main** (legacy PG), not introduced by this PR. CI4 notes only; L-170 on current-password checks stays open for #110.

## Checklist
- [x] Two plain merges: `b4dfa14` (← `b411df1` v2.6.16), `2e899b8` (← `0d9f6f3` v2.6.17). `--remerge-diff` conflicts only in CHANGELOG (and expected docs/test union files). `src/`, `public/`, `VERSION` at each merge tip match the main parent byte-for-byte.
- [x] CHANGELOG: released sections from `## [2.6.17]` onward are byte-identical to main `0d9f6f3`. Unreleased keeps CI4 bullets; the #106 security Fixed bullet is only under 2.6.15 (not Unreleased).
- [x] gitleaks `194074d..HEAD`: clean. `.gitleaks.toml` sha256 `b5bc9f98…3a8f`. check-commits ok (releases skipped). lint 0. check-links 0/212. deptrac 0. DI OK.
- [x] Suites (`qa113_` on PG17:5432):

  | Suite | SQLite | PG17 |
  |---|---|---|
  | Legacy 8.3 | 713 OK (66 skip) | 713 OK (67 skip) |
  | Full CI4 8.4 | 713 OK (11 skip) | 713 OK (10 skip) |
  | CI4 module | 571 OK | 571 OK |

- [x] HardeningHttpTest incl. `testEditsAtOnceKeepTheOriginal`: OK this run. `testANameWithANulNeverSignsIn` skips on legacy PG (documented).

## Ports vs legacy @ 0d9f6f3 (SQLite + PG17)
| ID | Result |
|---|---|
| **L-120** | Success: `clear(login)` + `forget(mine)`. After 20 fails + success, address rows stay 20 (not 0). Concurrent bursts still cap. |
| **N-125 / N-174** | `addressScope` byte-identical to legacy for IPv4, ::1, ::ffff:, NAT64 `64:ff9b::`, ::/96, upper/zero-padded IPv6, zone id, malformed, cli. Keys = `sha256(scope)[0:32]`. Login buckets still use the full address. |
| **/64 rotation** | 40 clients in one /64 → 30×401 + 10×429, one key; right password at cap → 429; other /64 → 303. |
| **N-124 + L-170 (re-enable)** | 12 wrong → 10×403 then 429. Right password at /64 cap → 429; auth stays off. |
| **N-126** | Replace+failing trigger: old kept, files+0. Replace+ok: old gone after commit. Mutating away deferral fails `testACoverReplacedInsideAnOuterTransactionGoesOnlyAfterItsCommit`. |
| **Photo requirement** | Original byte-identical; served 1600×1200; `/media/full` = served; original URL 404. |
| **N-160** | Inner NUL (`ada\0x`) → 401 on CI4 SQLite and PG. Without the check, CI4 PG → 303 (same as legacy PG). |
| **N-162** | Mock test kills dropping `freeResult()`. |

## Concurrency (re-check from #109)
CI4 and legacy, SQLite and PG: burst40 and addr70 over-cap rounds = 0. L-120 under load: addr remains at prior fails.

## Main finding (legacy @ 0d9f6f3)
- **M-220. On PostgreSQL, a sign-in name with an inner NUL is cut at the NUL when bound, so `ada\0…` plus ada’s password signs in (303).** SQLite returns 401. The per-account limit key still hashes the full name (with NUL), so that bucket is not shared with `ada` — a per-account limit bypass. Register and user-create reject such names (regex). CI4’s `findByName()` closes this (N-160). Probe skipped on legacy PG in HardeningHttpTest pending a main fix.

## Cloudflare / #110
- **N-204:** `rateLimiter` is request-shared, not in `WorkerMode::$persistentServices`. This PR does not worsen that. Address/clock still taken per request.
- **Open:** L-170 on CI4 current-password checks waits for Coder2 #110 (expected to call `hit('login_address')` + `forget()` on the API this PR adds). Not graded here.

## Mutants: 9/9 killed (with the right DB/test)
Killed: L-120 clear-whole / no-forget / bad id; N-125 no-scope / scope-on-login; N-124 re-enable; N-126 immediate delete; N-162 freeResult; N-160 (PG only — on SQLite the driver already fails the bind).

## Findings
- **M-220** (main / legacy PG): see above.
- **N-221.** Leading/trailing NUL in a sign-in name is trimmed by `key()` / `user_key()`, so `ada\0` signs in as ada on both apps (both DBs). Pre-existing; N-160 covers inner NUL only.
- **N-222.** CI4 current-password address cap (L-170) open for #110.
- **N-223.** Shared `rateLimiter` per request unchanged (N-204); not a new blocker.

## Security
Hardening sync and CI4 port of main’s L-120, N-124/125/174, L-170 (re-enable), N-126, N-160, N-162. Public wording: hardening plus IDs only.
