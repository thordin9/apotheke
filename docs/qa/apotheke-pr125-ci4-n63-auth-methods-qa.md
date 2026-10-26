# QA: apotheke #125, CI4 N-63 (other methods on the auth pages)

- **PR:** [#125](https://github.com/thordin9/apotheke/pull/125). **Head:** `780b6620b38e770cb3cb072726fe73051716f2c7` into `feature/ci4` `83e733f073e256b4ae7d96660a43f91b375f1d40` (`3ab851b` fix, `8f8735a` tests, `780b662` docs).
- **Verdict: PASS.** 0H / 0M / 0L / 4N (N-350 accepted; N-351 to N-353 follow-ups).

## Checklist

- [x] Change: `/login`, `/register` and `/account` route PUT, PATCH and DELETE with the same options as POST (`none`, `none`, `self`). The controllers act only on `POST`; every other method renders the page.
- [x] Method matrix, CI4 (PHP 8.4) vs legacy (PHP 8.3), SQLite and PG17 (594 cases per app and DB, all the same on both DBs). Covers 3 configs (sign-in on, on with guest, off) × anonymous/editor/admin × 3 routes × PUT/PATCH/DELETE/OPTIONS/PROPFIND × no token/header token/form token, POST with `_method`, and cross-origin PUT. With the header token, every PUT/PATCH/DELETE case matches legacy (status, location, cookies, normalised body). Only the cache header on redirects and errors differs (pre-existing; both no-store; normalised as in the parity test).
- [x] No state change: none of the new verbs changes users, auth attempts or the session in either app. The permission filter gives the same answers (303 `/login` for an anonymous visitor on `/account`, 303 `/` when signed in on `/login` and `/register`, 403 cross-site with sign-in off, 404 with registration closed).
- [x] CSRF: `/account` without the header token gives 419 for PUT, PATCH and DELETE, also with the token in a form body (PHP does not parse it). Cross-origin PUT is refused as on legacy. A mutant that drops the CSRF check is killed.
- [x] Sign-in limits and pace: a failed POST still waits ≥400 ms and counts both rows; POST at the per-address cap gives 429. The new verbs and `_method` never touch the limiter or check a password (both apps, both DBs).
- [x] Gates: check-commits 3 ok; lint ok (0 broken links); deptrac 0 errors; DI OK; gitleaks 8.30.1 no leaks; `.gitleaks.toml` `b5bc9f98ad4322e0fab57368081e7e7ce1df7146ff018701a51030a515993a8f`, unchanged.
- [x] Suites at head: CI4 PHP 8.4 SQLite 777 (7 skipped) / PG 777 (6); CI4 module 600 OK; legacy PHP 8.3 SQLite 777 / PG 777 (69 each). 0 failures.
- [x] Without the route change, `Ci4AuthParityTest` fails (CI4 gives 404 where 419 is expected) and so does `PermissionFilterTest`.
- [x] Mutants: 10/10 killed; 9/10 by the repo tests, and M9 only by the QA matrix (N-351). The mutants: base routes; new `/account` verbs as `none` (filter test only, since legacy gives `/account` self either way); new verbs need view; drop DELETE; drop PATCH on `/register`; `/account` skips CSRF; `/account`, `/login` and `/register` act on every non-GET; CSRF treats PUT/PATCH/DELETE as safe.
- [x] CI run 40366874510: 22/22 at `780b662`. Later head `a466646` (feature/ci4 with #123 merged in): conflicts in docs only; on top of the new base it adds the same #125 code and test patch, and the method matrix and limit probes are byte-identical; gates clean.
- [x] Cloudflare phase: no new statics, superglobals or `getenv`; `$pageVerbs` is route config read once at boot.

## Findings

| ID | Grade | Status | Finding |
|---|---|---|---|
| N-350 | Nit, hardening | Accepted (deliberate) | PUT/PATCH/DELETE `/account` need the CSRF token in the header (419) where legacy shows the page without it. Stricter, nothing changes on either app. |
| N-351 | Nit, tests | Follow-up | The parity test sends PUT/PATCH/DELETE `/register` only while registration is closed (the default), so both apps answer 404. A `/register` that acts on every non-GET passes the repo tests. Add the three verbs with registration open, and assert the page plus no new account. |
| N-352 | Nit, parity | Follow-up | POST with `_method=PUT/PATCH/DELETE` on these routes: CI4 takes the override and shows the page (no token check on `/login` and `/register`; 419 on `/account` without a token). Legacy ignores `_method` and acts on the POST. Safe: nothing changes on CI4, no limiter rows, no password check, and no app page sends `_method`. Before this PR it was 404. Ignore `_method` to match legacy, or record it as a difference. |
| N-353 | Nit, parity | Follow-up | OPTIONS and other methods on the three pages still 404 on CI4, where legacy shows the page or redirects. N-63 covered PUT, PATCH and DELETE. Stricter. |

## Security

Hardening at parity: the new methods only render the page, under the same route permission. `/account` keeps the CSRF requirement on every non-safe method; cross-site requests are still refused, and the sign-in limits and pace are unchanged. No new auth or CSRF bypass through `_method`. No new state for the Cloudflare phase. Follow-ups: N-351, N-352, N-353.

**Verdict: PASS at `780b6620b38e770cb3cb072726fe73051716f2c7`** (0H / 0M / 0L / 4N).
