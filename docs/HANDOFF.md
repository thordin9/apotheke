# Hand-off: `feature/ci4`

A one-screen status of the CodeIgniter 4 port. Every pull request into `feature/ci4` updates this page. Plan and module
rules: [PLAN.md](plans/feature12-ci4/PLAN.md), [ADR 001](adr/001-codeigniter4.md), [AGENTS.md](../AGENTS.md).

## Current state

- `feature/ci4` carries `main` up to v2.6.14 and the docs trim [#88](https://github.com/thordin9/apotheke/pull/88)
  (this sync PR), including the legacy session limits, `user signout NAME`, the Control Panel key warning and #87's
  `keepSignIn` rule. The shipped image still runs the legacy
  app; `APOTHEKE_APP=ci4` selects CI4 in CI and in development.
- CI4 modules: Core (config, DSN, filters, storage, secret sealing), Migrations (PDO runner, 28 migrations shared with
  `main`), Settings (schema, service, branding, `apotheke:settings`), Autofill (client and its four POST routes), Auth
  (current user, admin guards, session epoch, sign out everywhere, the Permission filter; no pages yet).
- Permissions: a required filter applies legacy's per-route check (`user_can()`) to every CI4 request, before CSRF,
  with legacy's 401, 303 and 403 answers. The permission comes from the routed path: each route's `permission` option
  (a route without one is refused), or legacy `route_perm()` where no route matches.
- Sessions: native `apothekesid` files, started on demand. CI4 applies `session_idle_hours` and `session_days` from
  sign-in, and each account has a session epoch (migration 0028), as `main` does.
- Stored secrets (S-L5): with `APP_KEY` or `APP_KEY_FILE` set, CI4 seals the AI autofill token in the same `enc:v1:`
  format as `main`, so both apps read one database. `key generate` and `key rotate` stay in `bin/settings.php`.
- Not ported yet: sign-in, account and Users pages, the Layout, Items, Media and Backup modules. When those pages
  arrive they pass `keepSignIn` to `SessionGate::signIn()` where `main` passes it to `login_session()` (sign out
  everywhere, and a new password for your own account on Account, the password page and Users).

## Pull requests

- [#89](https://github.com/thordin9/apotheke/pull/89): stored-secret encryption for CI4 (S-L5). Merged.
- [#90](https://github.com/thordin9/apotheke/pull/90): the CI4 session port. Merged.
- [#85](https://github.com/thordin9/apotheke/pull/85): sync `main` v2.6.13 into `feature/ci4`. Merged.
- [#91](https://github.com/thordin9/apotheke/pull/91): PLAN PR 7, the Permission filter for CI4 routes. Merged.
- This sync PR: merge `main` v2.6.14 and later (#87, #88) into `feature/ci4`. In review.

## Next steps

1. One batched PR of small follow-ups: #89 N-1, N-2, N-3 and N-11, and #91 N-14 (the `RoleRepository` fallback
   only on database (PDO) errors, as legacy does) and N-15.
2. Layout module: the shared page shell, assets under `ci4/public` and the error page the 403 answer then uses. The
   sign-in, register, account, users and roles pages and rate limits follow on top of it.
3. Port the Docker bind and app-password start-up hardening from `main` [#75](https://github.com/thordin9/apotheke/pull/75)
   (SEC-M3) into the CI4 image.
4. Media module on `Core/Storage` (photos and originals behind the storage interface).

Cloudflare phase: keep storage behind interfaces, no per-request global state, and the database config from a DSN.
The at-rest key comes in through `SecretKey`, so a Worker secret binding can replace the environment and key file.
