# QA: PR #109: CI4 sign-in limits (L-61 / L-66 port)

Head `fa5e0f9124671fe25d445372d4589fd570550ef3`, base `7abebe036e259a4d3c039b0f04c06ebe1d463c22` (`feature/ci4`, verified as the merge base). Commits: f32d941, fa5e0f9.

**Verdict: APPROVE.** L-61 and L-66 match legacy on SQLite and PG17 under real concurrency. No High, Medium or Low findings. Notes only.

## Checklist
- [x] gitleaks on `7abebe0..fa5e0f9`: no leaks. `.gitleaks.toml` sha256 `b5bc9f98…3a8f` (unchanged).
- [x] check-commits: 2 ok. lint: 0. check-links: 0 broken of 212. deptrac: 0. DI rule: OK.
- [x] Suites (throwaway `qa109_` role/DB on PG17:5432):

  | Suite | SQLite | PG17 |
  |---|---|---|
  | Legacy PHP 8.3 | 700 OK (65 skip) | 700 OK (65 skip) |
  | Full CI4 PHP 8.4 | 700 OK (9 skip) | 700 OK (8 skip) |
  | CI4 module | 552 OK | 552 OK |

- [x] HardeningHttpTest (skips removed): `testTheSignInLimitHoldsForRequestsAtOnce`, `testSignInsFromOneAddressHaveACap`, and `testEditsAtOnceKeepTheOriginal` (known flake) all OK on SQLite and PG (128 assertions).
- [x] Ci4AuthParityTest: OK. Compares `auth_attempts` for every kind except `login` (current-password throttle deferred).
- [x] SignInAccountsTest: 47 OK on both databases.

## Parity with legacy (SQLite and PG; same seed, shipped router)
Byte-identical for the rate-limit behaviour. Only header order / `Content-Type` vs `Content-type` differ (pre-existing).

| Check | Result (CI4 = legacy) |
|---|---|
| Limits / windows | login 10/900, login_address 30/900, register 5/3600 |
| Key hashing | `sha256(addr)` / `sha256(addr NUL key)` → 32 hex; same keys for the same inputs |
| Row shape after 10 fails + 1 refuse | login n=10, login_address n=11 (address row kept; refused login row deleted) |
| Address before account | At the address cap, a right password gets 429 with the address message; no new login row |
| Success clears both | After mixed fails, a good sign-in leaves only the other account's login rows |
| 429 body | Same text; Cache-Control: no-store. Neither side sends Retry-After |
| Window edges | 30 rows at now−900 → 429; at now−901 → 401. Prune keeps at−86400, drops at−86401 |
| Username variants | Case/space fold to one bucket; ZWSP, fullwidth, combining accents and TAB each get their own (same as legacy) |
| Empty / password NUL | 401 on both |
| Register | 5 created of 20 concurrent; rest 429 |

## Concurrency (10 rounds, `PHP_CLI_SERVER_WORKERS=16`)
| Probe | Base 7abebe0 (CI4) | This head (CI4 = legacy) |
|---|---|---|
| 40 same-name at once | over-cap in 5/10 rounds | 10 × 401, 30 × 429; over=0 |
| 9 used + 6 at once | admitted >1 | admitted ≤1 every round |
| 70 names (address cap) | over-cap 5/5 | 30 × 401, 40 × 429; over=0 |
| Register ×20 | 7 created | 5 created |
| Leftover own rows after 429 | (address had leftovers) | 0 |
| "database is locked" / fatal | 0 | 0 |

Same results on PG17 for this head.

## Bypasses
- **X-Forwarded-For:** trust off → REMOTE_ADDR only (spoof ignored). Trust on + `TRUSTED_PROXIES=127.0.0.1` → rightmost non-proxy hop; forged left hops share one bucket; another client still signs in. Keys match `sha256('203.0.113.9')`.
- **Register** uses the same RateLimiter::hit (5/hour); concurrent probe matches legacy.

## Cloudflare / worker
- RateLimiter holds no per-request state. `Services::rateLimiter` is not in `WorkerMode::$persistentServices`, so address and clock are taken per request from `$_SERVER` / Clock. No new storage path; still the shared `auth_attempts` table. No Cloudflare-phase blocker from this PR.

## Mutants: 9/10 killed
Killed: off-by-one; refused row kept; count-before-insert (old order); no address hit; address not cleared; address after account; bucket always address-only; no prune; address cap 300.

Survived: dropping `freeResult()` (R3). Equivalent here: my SQLite burst with that mutant still had 0 locks and 0 over-cap. Keep it as SQLite defence-in-depth (same as legacy `closeCursor`).

## Findings
- **N-160.** Username with an embedded NUL (`ada\0x`) and the right password signs in on CI4 (303) and not on legacy (401). Present on base `7abebe0` too; out of scope. Likely CI4's request parsing. Follow-up with the NUL password work.
- **N-161.** CI4 inherits legacy's L-120: a successful sign-in clears the whole per-address bucket. Intentional; already tracked on #106.
- **N-162.** `freeResult()` has no failing test. Optional: a unit test that keeps a live result across the delete, or leave as documented defence-in-depth.

## Security
Hardening port of #106 L-61 and L-66 into CI4. Public wording: hardening plus IDs only. Out of scope (Coder2): current-password throttle, last-admin lock, APP_PASSWORD NUL.
