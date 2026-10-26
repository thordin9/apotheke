# QA: apotheke #110, CI4 port of #106 hardening for accounts

- **PR:** [#110](https://github.com/thordin9/apotheke/pull/110). **Head:** `7469c63726d9ed6b113a4e6b4818fdb945dbab03` (refs/pull/110/head re-checked) into `feature/ci4` `c8163af` (#113). Full gate at `df2a289` (FAIL, M-200); delta `7f80534` PASS; this delta: merge `2051354`, `fca8e65` (L-170), `7469c63` (docblocks).
- **Verdict: PASS at `7469c63`.** 0H / 0M / 1L / 5N open. M-200, L-202, N-205, N-207, N-210, N-222 closed.

## Checklist

- [x] Merge `2051354` (`--remerge-diff`): CHANGELOG/HANDOFF keep both sides; HardeningHttpTest drops both `skipOnCi4`; parity keeps #113's filter. Merge vs `c8163af` adds only #110's module files. #113 `addressScope`, hit id, `forget`, turn-on cap intact (RateLimiter/SignIn = `c8163af`).
- [x] Lock code unchanged since `7f80534` (UserAdmin, RoleAdmin; UserRepository only gains #113's NUL check). Role-edit race: 80/80 303 on SQLite and PG (2 runs × 40 each).
- [x] 401 (N-208): 144 real rounds. 401 only on CI4, only for the deleted actor, never both requests; no 500; exactly 1 active admin every round. Legacy: 303/403/409.
- [x] L-170 `checkCurrent`: address hit, account hit, verify, clear account, `forget($mine)`; same as main `user_check_current`. HTTP probe (XFF, 6 address pairs) byte-identical CI4 = legacy, SQLite = PG: same-/64 sibling at 30 gives 429 address text with 0 account rows; wrong gives 403, address +1, account +1; right clears the account count and leaves address rows identical (own row only; sibling and other-address rows kept). IPv4 per address; other /64 separate; `::ffff:0:0/96`, `::/96`, `64:ff9b::/96` kept whole.
- [x] Gates `7f80534..7469c63`: check-commits 15 ok / 3 release skip; lint ok (0 broken links); deptrac 0 errors; DI OK; gitleaks 8.30.1 18 commits, no leaks; `.gitleaks.toml` `b5bc9f98ad4322e0fab57368081e7e7ce1df7146ff018701a51030a515993a8f`.
- [x] Suites at head: CI4 PHP 8.4 SQLite 715 (39 skip, 33 of them PG-only with PG unset), PG17 715 (6 skip); CI4 module 578 OK; legacy PHP 8.3 SQLite 715, PG 715 (67 skip). Two load flakes (permission parity status 0 on PG; autofill timeout at 289 s on legacy SQLite) outside the delta pass isolated (3/3, 1/1). CI run 40276562623: 22/22 at head.
- [x] Skips: CI shows CI4 5/3, legacy 8.4 4/3, legacy 8.3 65/66 (8.3 skips CI4 vendor tests). Local PG 6 = php-cgi, upgrade note, HEIC limit, catalog HTTP on legacy, superuser trigger, no-backslash password. No new skips beyond #113's legacy-PG NUL name (main #116).
- [x] Cloudflare phase: no new statics, superglobals or `getenv` in the delta; `addressScope` is pure.
- [x] Mutants on `checkCurrent`: 5/5 killed on both DBs (drop address hit; clear whole address count; swap order; forget every row up to its own; keep account count). Earlier 16/16 stand.

## Findings

| ID | Grade | Status | Finding |
|---|---|---|---|
| M-200 | Medium, availability | **Closed** (`bb4f4d1`) | SQLite role edits under concurrent writes gave 500; lock first. Code and race test unchanged; 80/80 on both DBs. |
| L-201 | Low, hardening | Pre-existing, follow-up | CI4 migration settings seed skips the `APP_PASSWORD` check (short value accepted; NUL gives "Migration failed."). |
| L-202 | Low, hardening | **Closed** (#113) | Turning sign-in back on is limited (address + account, `forget` own row) in both apps, same as main #111. |
| N-203 | Nit | Pre-existing, both apps | Role delete racing a change into that role can leave accounts on a deleted role. |
| N-204 | Nit, Cloudflare phase | Pre-existing | Shared `rateLimiter` fixes address and clock at first use; make it per request before a long-lived worker. |
| N-205 | Nit, tests | **Closed** (`73e13c2`) | Pre-lock-row mutants killed. |
| N-206 | Nit, tests | Open, narrowed | Generic parity golden still compares per-kind counts; the new address-cap asserts check the exact bucket. |
| N-207 | Nit | **Closed** (`7469c63`) | `user_approve()` docblock moved above `approve()`. |
| N-208 | Nit | Accepted | Actor deleted mid-request: CI4 401 vs legacy carrying on; both refuse, admin kept. |
| N-209 | Nit | Pre-existing, both apps | Concurrent first-admin creation while sign-in is off (SQLite 500s; PG 2 to 4 admins). |
| N-210 | Nit | **Closed** (`7469c63`) | Stray `UserAdminTest` docblock fixed. |
| N-211 | Nit, tests | Open | `testDeletesAndDemotionsAtOnceKeepAnAdmin` accepts 401 for either request and needs no 303, so a double 401 (broken sessions) would pass. Allow 401 only for the deleted actor and require one 303. |
| N-222 | Low (#113) | **Closed** (`fca8e65`) | CI4 current-password checks now have the per-address (/64) cap. |

N-160 (NUL name) is closed by #113's `findByName` check. Main v2.6.18 (#116) is not in `feature/ci4` yet: next sync.

## Security

Hardening at parity with main: current-password and turn-on checks share the per-address cap (/64 for IPv6, mapped forms whole) and record first; a success takes back only its own row. Last-admin lock is unchanged. No new state for the Cloudflare phase. Follow-ups: L-201, N-203, N-204, N-209.

**Verdict: PASS at `7469c63726d9ed6b113a4e6b4818fdb945dbab03`** (0H / 0M / 1L / 5N open).
