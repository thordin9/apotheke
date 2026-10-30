# QA: PR #186, read-only users, roles, security and branding API

- PR: https://github.com/thordin9/apotheke/pull/186 (Coder2), base `main` (288c44f), same repository, 0 behind, mergeable clean
- Reviewed head: `ae3a1437227bc9d28a8e44d2189b9fd5cb674789`, 4 commits. The full gate ran on 143996f; ae3a143 removes `trust_proxy`, and only that delta was re-gated
- CI on ae3a143: 17/17 checks green, including `Tests (PHP 8.4, sqlite)`, `Tests (PHP 8.4, pgsql)` and Secret scan
- Scope: user and security data exposure (full gate)

## PM decisions (recorded, not findings)

- With sign-in off, `/users`, `/roles` and `/security` stay open, as the HTML Users page does (seen live: 200, and the users list is empty)
- `trust_proxy` is dropped from `/security` (done in ae3a143)
- The per-role `users` counts and the branding `tagline` and `theme_default` stay

## Checklist

- [x] Files: routes (source and compiled), `UsersController`, `BrandingController`, `UserRepository`/`UserAdmin` (`created_at` added to the list read), `SecurityView` docblock, OpenAPI, `docs/api.md`, HANDOFF, `Ci4ApiUsersTest`, `PermissionFilterTest`
- [x] Live app on SQLite and on a throwaway PostgreSQL 17 (password auth checked: a wrong password is refused; the driver in use was confirmed as pgsql). Both drivers give the same answers apart from timing
- [x] Matrix (`/users`, `/roles`, `/security`):
  - anonymous: 401 `auth.required`
  - viewer: 403 `permission.denied`
  - a custom role with `users`: 200
  - admin: 200
  - a bogus session cookie: the same 401 body as anonymous
  - Refusals are problem+json with the same shape as the settings read; every answer is `no-store` and sets no cookie
- [x] No enumeration: no endpoint takes a user name or id, so status and timing cannot differ per target. Medians were flat across the four callers (SQLite 2-4 ms, PostgreSQL 10-24 ms)
- [x] `/users` returns only `id`, `name`, `role`, `status`, `created_at`, `last_login_at`, built field by field. No e-mail is stored (2.6.x schema); `name` is the sign-in name, the same one the Users page shows (N-1402)
- [x] `/roles`: `key`, `name`, `builtin`, `permissions` (permission names), `users` (a count)
- [x] `/security` at ae3a143: `sign_in_enabled` plus `auth_guest_disabled`, `auth_register_disabled`, `auth_register_approval` (booleans), `auth_register_role` (a role key), `session_days`, `session_idle_hours` (integers). No secret, hash, token, session value, key, path or hostname. `auth_enabled` (internal) and `password_hash` (secret) are not readable section keys
- [x] `trust_proxy` is gone from `POLICY`, the OpenAPI schema and `docs/api.md`. `NOT_POLICY` lists it, and the test now requires every readable security key to be in exactly one of the two lists
- [x] `/branding` is public and starts no session: no Set-Cookie on a plain or same-origin request, even when the controller writes to the session (N-1401). It is `no-store` and sends no CORS allow header to a foreign Origin
- [x] Every `/branding` value is already public: `name`, `tagline` and the favicon and touch icon are in the sign-in page `<head>`; the logo is on the sign-in page; `accent` is served by `/theme.css` (linked from `<head>`); `theme_default` is `<html data-theme>`
- [x] With #182 (still open, not on `main`) trial-merged: `/branding` is 200 with no cookie under `COOKIE_CONSENT` required and off. The trial merge conflicts on `openapi.yaml` and `RoutesCompiled.php`; both are additive (N-1403)
- [x] Mutations: Coder2's set was spot-checked, and 13 of my own were killed:
  - the hash leaked via a merged row (toArray style)
  - the hash under a neutral key
  - `/users` made public; `/security` given the `self` permission; `/roles` with no filter
  - a new field (`username_key`)
  - the whole row returned, including `session_epoch`
  - `password_hash` added to `POLICY`
  - an extra roles field; an extra branding setting
  - on the new head: `trust_proxy` back in `POLICY`, under another key, or emitted via `NOT_POLICY`, and a new unlisted security key
- [ ] Survived: two session-start mutations on `/branding`, both equivalent because no cookie is emitted (N-1401)
- [x] Full PHPUnit (root plus the module suite via `Ci4ModuleSuiteTest`) on 143996f: SQLite 356 OK (29 skipped, 1 incomplete), PostgreSQL 356 OK (2 skipped)
- [x] On ae3a143, the API, OpenAPI and route tests: 46 OK on both drivers. CI is green on the head
- [x] `scripts/lint.sh` OK, deptrac 0 violations, `spark apotheke:routes --check` up to date
- [x] gitleaks 8.30.1 (checksum verified) on `main..ae3a143`: no leaks. `.gitleaks.toml` and `CHANGELOG.md` are identical to `main`

## Findings

- **N-1400** With a valid admin cookie, a cross-site GET of `/api/v1/users` is answered 200. This is safe in practice: the session cookie is `SameSite=Lax` and HttpOnly, no CORS allow header is sent, and every `/api` read behaves this way. Noted for the CORS opt-in (#172).
- **N-1401** "No session for `/branding`" is enforced by the session layer, not the controller: `session()` and even a session write in the controller emit no cookie. So the test's no-cookie assertion is the right observable, and those mutants are equivalent.
- **N-1402** `last_login_at` (and `created_at`) of every account, admins included, is visible to a non-admin holder of `users`. That matches the HTML Users page for the same permission, which also lets the holder manage accounts. Acceptable; a PM call if `users` is ever split into read and manage.
- **N-1403** This PR, #182, #184 and #185 all touch `openapi.yaml` and the compiled routes. The conflicts are additive: keep both sides, then run `php spark apotheke:routes`.

## Security

All three account and policy reads need the `users` permission and are refused with the same problem+json as the settings read. Every answer is an explicit field list, checked by a recursive key scan and a body scan for stored hashes, session epochs, the CSRF token and cookie values. Adding a security-section setting is a reviewed choice, because the test fails until the key is classified. The reverse-proxy setting is no longer exposed. The public branding read only repeats what the sign-in page already shows, and it sets no cookie with or without consent mode. No secrets were printed or committed during this review.

## Verdict

**APPROVE** at `ae3a1437227bc9d28a8e44d2189b9fd5cb674789`. No H, M or L findings; the notes need no change in this PR.
