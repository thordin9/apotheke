# Hand-off: `feature/ci4`

A one-screen status of the CodeIgniter 4 port. Every pull request into `feature/ci4` updates this page. Plan and module
rules: [PLAN.md](plans/feature12-ci4/PLAN.md), [ADR 001](adr/001-codeigniter4.md), [AGENTS.md](../AGENTS.md).

## Current state

- `feature/ci4` carries `main` up to v2.6.9 (sync [#83](https://github.com/thordin9/apotheke/pull/83)); #85 brings v2.6.13. The shipped
  image still runs the legacy app; `APOTHEKE_APP=ci4` selects CI4 in CI and in development.
- CI4 modules: Core (config, DSN, filters, storage), Migrations (PDO runner, 28 migrations shared with `main`),
  Settings (schema, service, branding, `apotheke:settings`), Autofill (client and its four POST routes), Auth (current
  user, admin guards, session epoch, sign out everywhere; no pages yet).
- Sessions: native `apothekesid` files, started on demand. CI4 enforces `session_idle_hours` and `session_days` from
  sign-in, and each account has a session epoch (migration 0028), as `main` does.
- Not ported yet: sign-in, account and Users pages, the Layout, Items, Media and Backup modules.

## Open pull requests

- [#85](https://github.com/thordin9/apotheke/pull/85): sync `main` (now v2.6.13) into `feature/ci4` (DevOps).
- [#89](https://github.com/thordin9/apotheke/pull/89): CI4 stored settings encrypted at rest (S-L5).
- [#90](https://github.com/thordin9/apotheke/pull/90): the CI4 session port (this page's PR).

## Next steps

1. Merge `feature/ci4` into the session-port branch after #85 lands (plain merge, no rebase). #85 brings the
   legacy `user signout NAME` command and the legacy session-limit checks onto this branch.
2. When the Auth pages land, call `SessionGate::signIn()` with `keepSignIn` only where `main` #87 passes it (sign out
   everywhere, a new password for your own account). A real sign-in never passes it.
3. Port the Docker bind and app-password start-up hardening from `main` [#75](https://github.com/thordin9/apotheke/pull/75)
   (SEC-M3) into the CI4 image.
4. Layout module (shared page shell, assets under `ci4/public`), then the Auth pages on top of it.
5. Media module on `Core/Storage` (photos and originals behind the storage interface).

Cloudflare phase: keep storage behind interfaces, no per-request global state, and the database config from a DSN.
