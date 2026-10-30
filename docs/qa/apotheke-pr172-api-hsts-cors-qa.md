# QA: PR #172, HSTS on /api errors and trailing-dot CORS

- **Head:** `e45dd2366a262cc081e9f7072bedfa66fe9a15f3`. 3 commits, base main `6776b6d` (0 behind, mergeable CLEAN), same repo.
- **Diff:** 8 files (Exceptions/Services wiring, PlainHandler, Cors, CorsTest, PlainHandlerTest, Ci4ApiHttpTest, docs/api.md).
- **CI:** run 41058985068 + CodeQL — 17/17 green on this exact head.
- **Verdict: PASS.** No H/M. L-1220, N-1221 and N-1222 fixed. One N below.

## Checklist

- [x] **Scope.** Delta only for L-1220 / N-1221 / N-1222. `.gitleaks.toml` and CHANGELOG unchanged vs base.
- [x] **Suites.** Root 326 OK (SQLite and PG17). Modules 646 OK SQLite, 795 OK PG. Lint OK. `spark routes --check` OK. Own scratch PG role/DB dropped after.
- [x] **CORS matching.** List and request Origins both go through `SameOrigin::normalise` after `Cors::origin()`. Trailing-dot and case match the listed origin; port, scheme, suffix, `null`, empty, double-dot and unlisted trailing-dot Origins get no CORS headers. Credentials stay `true` only on a match. `Vary: Origin` still set by ApiFilter when the list is non-empty.
- [x] **ACAO echo.** On a match, ACAO is `Cors::origin($origin)` (lowercase, trailing `/` stripped, trailing host dot kept). Trailing-dot Origins are echoed with the dot, which is what browsers need for a byte-exact compare. Unlisted Origins get no ACAO. Mutations (echo without a match; skip normalise on the request side or the list side) fail CorsTest.
- [x] **HSTS on /api errors.** Sent when `PUBLIC_URL` from the env/config.php closure starts with `https://`, or on a direct HTTPS connection. Not from the DB; not from X-Forwarded-Proto. `http://` PUBLIC_URL → no HSTS. Closure throw / DB-down style → answer still problem+json, no HSTS. Header value `max-age=31536000` matches ApiSecurityPolicy / success path. HTTP test with https PUBLIC_URL covers `/api/v1/a;b`. Mutation (treat any non-empty PUBLIC_URL as https) fails PlainHandlerTest.
- [x] **N-1222 docs.** docs/api.md notes: error answers skip after-filters (no CORS); HSTS from env/config.php or direct HTTPS; trailing-dot CORS match + ACAO echo.
- [x] **FrankenPHP.** No new static properties. `publicUrl` is a per-handler closure over `apothekeConfig()->publicUrl()` (env/config.php).
- [x] **Secrets.** gitleaks 8.30.1 clean on the range.

## Prior findings

- **L-1220:** fixed (ce690e0) — HSTS on `/api` errors follows https PUBLIC_URL from env/config.php.
- **N-1221:** fixed (b03dfae) — CORS list/request compare via `SameOrigin::normalise`; ACAO keeps a trailing dot.
- **N-1222:** fixed (e45dd23) — documented: no CORS headers on uncaught `/api` errors.

## Findings

**N-1280: ACAO is shaped by `Cors::origin()`, not the raw header bytes.** Mixed-case Origins that still match receive a lowercased ACAO, so a non-conforming client could fail the browser's byte-exact check. Conforming browsers send a lowercase Origin; trailing-dot echo matches. Prefer the raw Origin string after `allows()` if mixed-case clients matter. Tests pin trailing-dot echo, not uppercase.

## Security

`/api` error answers can now carry the same HSTS as the success path without reading the database or trusting X-Forwarded-Proto; a failing PUBLIC_URL lookup leaves HSTS off and does not break problem+json. CORS allow-list matching accepts one trailing host dot without widening to suffixes, ports, schemes, `null` or empty Origins; non-matches still get no CORS headers. Uncaught `/api` errors still omit ACAO. Testing used a private scratch tree and a throwaway PG17 role/DB.
