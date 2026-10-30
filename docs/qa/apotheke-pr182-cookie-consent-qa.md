# QA: apotheke PR #182, "feat(core): set no cookies before consent"

**Verdict: PASS.**
- Final reviewed head: `d9a47537ff74198c314bfe9df85aa0a7791a566e`. Same repo, 0 behind `main`, 15 commits.
- CI 17/17, both Tests jobs green.
- Rounds: 1 at `6a62313`, 2 at `d7f2792`, 3 at `d9a4753` (2026-10-30, EET).

## Decision

**D-1340, accepted by the PM.** The parity break is accepted with conditions:
- a no-JS `POST /consent`, strictly same-origin;
- reads keep working without consent;
- an API curl example;
- `COOKIE_CONSENT=required|off`;
- upgrade and sign-out wording;
- L-1341 and L-1342 fixed in this PR.

All conditions are met. L-1421 is a follow-up, approved by the PM.

## Checklist

- [x] **Round 1.** Real app on SQLite and PG17, 25 routes without consent:
  - Zero `Set-Cookie`. Reads work.
  - Writes get 403 `consent_required` (problem+json under `/api`).
  - Near-miss values answer byte-identically to no cookie.
  - With consent: sign-in, CSRF 419, theme, recently-viewed and backup all work.
  - An old session is ignored until Accept.
- [x] **Round 2: `POST /consent`**, 27 cases on SQLite, PG and the Docker image:
  - 303 only for `Sec-Fetch-Site: same-origin` or our own Origin.
  - 403 for same-site, cross-site, none, a foreign, sibling or `null` Origin, and for neither header.
  - 400 for any `consent` other than `accept`.
  - `X-Forwarded-Host` is never used.
  - Real Chromium: no-JS works; sibling-subdomain and cross-site pages are refused.
- [x] **Round 2: other checks.**
  - Return-path fuzz, 52 cases: always a same-origin relative path.
  - Cookie: `Path=/; Max-Age=15552000; SameSite=Lax`, Secure on HTTPS, not HttpOnly.
  - The `Domain=getapotheke.com` branch: never triggered by lookalike hosts or XFH.
  - `COOKIE_CONSENT=off` is byte-identical to base `1272ec9` (24 requests, `php -S` and Docker). Garbage values count as required.
  - Cookieless dynamic answers are `no-store`; assets, media and branding keep their caching.
  - `upgrade-rollback.sh` on SQLite: all steps equal.
- [x] **Round 3, M-1420 and N-1422 (diff read).**
  - `upgrade-3.0.md` and the CHANGELOG `[Unreleased]` now say three things: signed-in users appear signed out until Accept, then keep their session; scripted `/api` clients must send the cookie, with a link to `api.md#requests` (the anchor exists); `COOKIE_CONSENT=off`.
  - The one-jar curl example signs in on `php -S`, SQLite and PG.
- [x] **Round 3, N-1424 (code).**
  - Only bytes 0x80–0xFF are percent-encoded, after validation, so ASCII and existing `%XX` stay untouched (no double-encoding).
  - 24 new fuzz cases on SQLite and PG all gave an ASCII-only, same-origin relative `Location`. They covered: raw or encoded UTF-8, overlong `\xC0\xAF`, fullwidth and ideographic marks, RTL override, invalid bytes, `//`, `\`, CR/LF with UTF-8, and encoded `https:` / `%68ttps:`.
- [x] **Round 3, #186 reads** (live, SQLite and PG):
  - Sign-in on: without consent, branding is 200 and users, roles, security, settings and me are 401, all cookieless. An admin session gets 401 until consent, then 200.
  - Sign-in off, with `COOKIE_CONSENT=required`: the reads stay open (200, cookieless), as the PM decided.
- [x] **Merges** `2a29423` and `a819bfb` (remerge-diff): conflicts only in the routes hash, the OpenAPI description and the CHANGELOG, all resolved to main plus this PR.
  - `[3.3.0]` is byte-identical to main, and the other released sections are unchanged.
  - `php spark apotheke:routes` output is byte-identical to the committed `RoutesCompiled.php` (N-1403). `routes --check` and OpenApiTest are OK.
  - `d9a4753`: every consent "3.3" now reads "3.4", and none are left.
- [x] **Suites.** The full root and module suites (round 2) are OK on both drivers. Round 3 consent, API and OpenAPI tests (root 88, modules 102) are OK on SQLite and on PG17 with password auth.
- [x] **Tooling.** Lint and deptrac are clean. gitleaks 8.30.1 on `main..pr182`: no leaks. `.gitleaks.toml` is unchanged.
- [x] **Playwright `consent.spec.ts`** (round 2): desktop and Pixel Chromium, plus `pgsql-smoke`.
- [x] **Mutations.**
  - Round 1: 12 of mine; round 2: 30 of mine plus 8 of Coder2's. Survivors are covered by N-1423.
  - Round 3: Coder2's 31 (`none` plus own Origin) and 32 (no encoding) are killed, and so are mine (double-encoding, encoding all bytes).

## Findings

**Fixed:**
- M-1420: upgrade and sign-out wording.
- L-1341: backstop test.
- L-1342: `no-store`.
- N-1422: one-jar curl example.
- N-1423: `none` plus own Origin test.
- N-1424: ASCII-only `Location`.
- N-1343, N-1344, N-1345.

**Noted:**
- N-1346: no decline button, by design.

**Open, follow-ups:**
- **L-1421:** `Config\Consent::HOSTED_DOMAIN` hardcodes the project's public domain. It is safe as written; a setting such as `COOKIE_DOMAIN` is recommended. PM-approved follow-up.
- **N-1425:** an Origin with an explicit `:80` is refused. Browsers never send one.
- **N-1426:** with consent, TRACE answers 200 on `php -S`. nginx returns 405 in Docker.
- **N-1460:** the 2 KB return-path cap applies before encoding, so `Location` can grow to about 6 KB. That can exceed a proxy's response-header buffer for that one request. Cap the length after encoding, or fall back to `/`.

## Security

- `POST /consent` takes no token. It is strictly same-origin through Fetch Metadata or Origin, and fails closed when neither header is present.
- It sets only the consent cookie. Forwarded headers do not influence the origin decision or the cookie's Domain.
- The return path is always a local relative path. Encoded, control-character and Unicode variants fall back to `/` or stay inside the path. Output is ASCII-only and never double-encoded.
- Without consent, every other `Set-Cookie` is stripped, error answers included. Other security headers stay.
- `COOKIE_CONSENT=off` restores the previous behaviour and is not triggered by unexpected values.
- No H or M findings remain.

## Verdict

**PASS.** No H/M findings. L-1421 and N-1425, N-1426 and N-1460 are follow-ups.
