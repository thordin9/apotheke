# QA: PR #166, P1 API foundation (session, CSRF, CORS, cookies)

- **Head:** `fa6a706a40df26e7d88052b896176d6a9c867d47`. 11 commits, base main `b96f934`, 0 behind, mergeable, same repo.
- **CI:** run 41041840842 passed 14/14 on this exact head.
- **Verdict: PASS.** No H/M. L/N below. SameSite=Lax is the open PM decision (N-1144).

## Checklist

- [x] **Scope.** New API modules/filters/HTTP helpers, routes, docs/api, OpenAPI, tests, Dockerfile COPY of the spec, composer require-dev + lock. Existing auth changes are limited: `attemptSignIn` shared with the page login; SameOriginFilter skips `/api` (ApiCsrf covers it); PermissionFilter answers problem+json on `/api`. Entrypoint unchanged.
- [x] **Suites.** Root 322 OK (SQLite and PG17). Modules 642 OK SQLite, 791 OK PG. Contract/API unit set 34 OK.
- [x] **CSRF (ApiCsrf).** Non-safe `/api` needs Origin (own or CORS list), optional Sec-Fetch-Site same-origin/same-site when present, JSON/multipart (or empty body), and `X-CSRF-Token`. Safe methods pass. Null/empty/foreign Origin and Referer-only refuse. Form `csrf` does not count. Page `sessioncsrf` stays except login/register/logout/api. Host/port/case checked; X-Forwarded-Host is not an own origin. Mutations (empty Origin allowed; Origin skipped when Sec-Fetch-Site set) fail tests.
- [x] **CORS.** Exact listed origins only; no `*`, `null`, paths; http only for loopback in development; credentials true; Vary: Origin; no headers when the list is empty or the Origin is foreign. Reflect-any-Origin mutation fails.
- [x] **ClientAddress.** XFF and REAL_IP_HEADER only from configured proxies with trust_proxy; edge IP only with PROXY_SHARED_SECRET. Used by RateLimiter (login limits). Trust-XFF-always mutation fails.
- [x] **Session endpoints.** Login regenerates the id and CSRF token; logout empties and regenerates; wrong/unknown user share one 401 body; 429 with Retry-After; `/me` has id/username/role/permissions only. No-regen mutation fails. Web `/login` still works and shares the session.
- [x] **Cookies.** HTTPS: `__Host-apothekesid` with Secure, Path=/, HttpOnly, no Domain. HTTP: `apothekesid`. Plain name still resumes once on HTTPS (documented to drop in 3.2). Dropping `__Host-` fails the cookie test. SameSite stays Lax (N-1144).
- [x] **SEC-M3.** Docker entrypoint still requires sign-in / APP_PASSWORD. SameOriginFilter unchanged for non-API. ApiCsrf Origin check covers `/api`.
- [x] **Deps.** 20 new lock packages, all require-dev (MIT; marc-mabe/php-enum BSD-3). Production lock stays codeigniter4 + laminas-escaper + psr/log. `composer audit`: 0. Image uses `composer install --no-dev`.
- [x] **OpenAPI in the image.** Copied for `GET /api/v1/openapi.json`; DockerfileTest pins the COPY. Public by design (contract, no secrets).
- [x] **FrankenPHP.** SessionCookie clears `$_SESSION` and closes a leftover session each request. No static request caches in ClientAddress, ApiCsrf or Cors. Cookie name chosen per request.
- [x] **Secrets / CHANGELOG.** gitleaks 8.30.1 clean on the range; `.gitleaks.toml` unchanged. Released sections byte-identical to main.

## Findings

**L-1140: trailing-dot Host without PUBLIC_URL.** `ownOrigins` keeps a trailing dot from Host. A browser Origin without the dot then fails CSRF. With PUBLIC_URL set (normal), the second origin still matches. **Fix:** strip a trailing dot when building own origins.

**L-1141: plain cookie not cleared on the HTTPS rename.** The session moves to `__Host-apothekesid`; the old `apothekesid` may remain until it expires (one-release fallback). **Fix (3.2):** expire the plain name when issuing the `__Host-` cookie.

**L-1142: uncaught `/api` errors are not always problem+json.** Controllers use ApiResponse; PlainHandler still answers HTML or `{"ok":false}` for unexpected throws. No stack/path leak in production. **Fix:** route API exceptions through ApiResponse::problem.

**L-1143: no Unreleased CHANGELOG bullet** for the API work (3.0.1 already released from #164). Add bullets before merge if 3.1 notes should list it.

**N-1144: SameSite=Lax (PM decision; plan said Strict).** Risk for this API: low. Non-safe `/api` already needs Origin + `X-CSRF-Token` + JSON/multipart, so cross-site writes fail under Lax. Lax still sends the cookie on top-level GET from another site (useful for opening the HTML app from a link). Strict would block that and help only if a GET ever gained side effects. Recommendation: keep Lax for the shared cookie unless the SPA is fully same-site and link-entry is not needed; pin the choice in a test either way.

**N-1145:** OpenAPI is public at `/api/v1/openapi.json` (intentional).

**N-1146:** no test pins session `SameSite=Lax`.

## Security

Hardening kept or added: Origin+token CSRF on `/api`, closed CORS, proxy-gated client IP, login fixation regeneration, lockout parity, problem+json without secrets, `__Host-` cookie on HTTPS, Docker sign-in gate. Gaps: L-1140–L-1142, N-1144. Testing used private scratch and a throwaway PG role/DB.
