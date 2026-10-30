# QA: PR #169, API follow-ups (trailing-dot origins, /api errors, SameSite pin)

- **Head:** `e258d1c0c488f007b823e8b5d9e4839195f261e2`. 3 commits, cut from main at `3e2328b` (#166), base main `9f90b9d` (10 behind, mergeable), same repo.
- **Diff:** 9 files (SameOrigin, ApiCsrf, PlainHandler, their tests, Ci4ApiCookieTest, Ci4ApiHttpTest, docs/api.md). Nothing outside that set.
- **CI:** run 41048827478 passed 14/14 on this exact head (plus CodeQL).
- **Verdict: PASS.** No H/M. Prior L-1140, L-1142, N-1146 fixed; N-1144 decided (Lax) and pinned. New L/N below.

## Checklist

- [x] **Scope.** Delta-only: origin normalisation, `/api` exception answers, SameSite=Lax pin test, docs. `.gitleaks.toml` and released CHANGELOG sections unchanged vs cut base.
- [x] **Suites.** Root 324 OK (SQLite; PG17 324). Modules 644 OK SQLite, 793 OK PG. Lint OK. `spark routes --check` OK. Own scratch PG role/DB dropped after.
- [x] **Normalisation (SameOrigin::normalise).** Lowercases, trims, strips a trailing `/`, drops one trailing host dot (port preserved). Applied on both sides in `check()` and `ownOrigins()`, and to Origin in ApiCsrf. Case, one trailing dot on Host/Origin/both, and good requests pass. Refused: double trailing dot (after one strip still ≠ bare host), port mismatch (`:443` ≠ bare), scheme mismatch, `userinfo@`, `null`, empty Origin, suffix (`evil.example` / `x.example`), unicode IDN vs punycode Host. Mutations (drop lowercase; strip all dots; skip normalising Origin) fail SameOriginTest/ApiCsrfTest.
- [x] **CORS allow-list.** Still uses `Cors::origin()` (lowercase + rtrim `/` only). ApiCsrf normalises Origin before `Cors::allows`, so a trailing-dot listed Origin passes CSRF; ApiFilter after still compares the raw Origin, so ACAO is not emitted for a trailing-dot Origin (N-1221). Reflect-any / widen mutations not introduced here.
- [x] **PlainHandler /api.** Production: thrown exception, PDOException, 404, 405, 419→403 `csrf.invalid`, 413 — all `application/problem+json`, fixed 5xx detail, no SQL/path/stack/class leak in the body. Non-`/api` keeps HTML (or text without a page). Cleanup PDO failure is caught; answer still goes out; log is class-only. Security headers match `ApiSecurityPolicy`; no ACAO on the exception path (after filters skipped). HTTP test `/api/v1/a;b` → 400 problem+json. Mutations (leak 5xx detail; remove `/api` branch) fail PlainHandlerTest.
- [x] **HSTS.** Error path: `RequestScheme(false)->isHttps` (direct HTTPS / :443 only). X-Forwarded-Proto alone does not set HSTS. Success path (ApiFilter) keys off `PUBLIC_URL`. Documented in docs/api.md (L-1220).
- [x] **SameSite pin.** `Ci4ApiCookieTest` pins `SameSite=Lax` on `apothekesid` and `__Host-apothekesid` for `/login` and `/api/v1/session`. Mutating SessionCookie/Cookie to Strict or None fails the pin.
- [x] **FrankenPHP.** No new static/per-request properties in the delta (`normalise` is a pure static helper).
- [x] **Secrets.** gitleaks 8.30.1 clean on `3e2328b..pr`.

## Prior findings

- **L-1140:** fixed (70e0f53).
- **L-1142:** fixed (1693dd9).
- **N-1144:** decided — keep Lax while HTML views exist; Strict reconsidered for 4.0. Pin added.
- **N-1146:** fixed (e258d1c pin test).

## Findings

**L-1220: HSTS on the `/api` exception path ignores `trust_proxy` / X-Forwarded-Proto.** PlainHandler uses `RequestScheme(false)`; ApiFilter uses `PUBLIC_URL`. Behind a TLS terminator the success path can send HSTS while an uncaught error on the same install may not. Not a secret leak; documented. **Fix (optional):** key error-path HSTS off `PUBLIC_URL` like ApiFilter, or `RequestScheme(trust_proxy)`.

**N-1221: CORS allow-list still does not use `SameOrigin::normalise`.** Trailing-dot Origin can pass ApiCsrf against a list entry without the dot, but never receives ACAO from ApiFilter. CSRF matching is not widened. Align only if trailing-dot CORS clients matter.

**N-1222: uncaught `/api` errors never get ACAO**, even for a listed Origin (after filters skipped). Intentional; avoids a CORS header leak on the exception path for disallowed origins.

## Security

Origin matching is tighter for case and one trailing host dot without accepting suffix, userinfo, null, empty, port or scheme tricks. Uncaught `/api` errors are problem+json with a fixed 5xx detail and the API security headers; non-API stays HTML. Exception path omits CORS headers. Session cookie SameSite remains Lax and is now pinned. Testing used a private scratch tree and a throwaway PG17 role/DB.
