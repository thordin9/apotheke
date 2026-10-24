# Hand-off: `feature/ci4`

A one-screen status of the CodeIgniter 4 port. Every pull request into `feature/ci4` updates this page. Plan and module
rules: [PLAN.md](plans/feature12-ci4/PLAN.md), [ADR 001](adr/001-codeigniter4.md), [AGENTS.md](../AGENTS.md).

## Current state

- `feature/ci4` carries `main` up to v2.6.9 (sync [#83](https://github.com/thordin9/apotheke/pull/83)); #85 brings
  v2.6.13. The shipped image still runs the legacy app; `APOTHEKE_APP=ci4` selects CI4 in CI and in development.
- CI4 modules: Core (config, DSN, filters, storage, secret sealing), Migrations (PDO runner, 28 migrations shared with
  `main`), Settings (schema, service, branding, `apotheke:settings`), Autofill (client and its four POST routes), Auth
  (current user, admin guards, session epoch, sign out everywhere; no pages yet).
- Sessions: native `apothekesid` files, started on demand. CI4 applies `session_idle_hours` and `session_days` from
  sign-in, and each account has a session epoch (migration 0028), as `main` does.
- Stored secrets (S-L5): with `APP_KEY` or `APP_KEY_FILE` set, CI4 seals the AI autofill token in the same `enc:v1:`
  format as `main`, so both apps read one database. `key generate` and `key rotate` stay in `bin/settings.php`.
- Not ported yet: sign-in, account and Users pages, the Layout, Items, Media and Backup modules.

## Pull requests

- [#89](https://github.com/thordin9/apotheke/pull/89): stored-secret encryption for CI4 (S-L5). Merged.
- [#90](https://github.com/thordin9/apotheke/pull/90): the CI4 session port (this page's PR). In review.
- [#85](https://github.com/thordin9/apotheke/pull/85): sync `main` v2.6.13 into `feature/ci4`. Open.

## Next steps

1. After #90, merge `feature/ci4` into the #85 branch (plain merge, no force-push) and get its CI to 22/22.
2. PLAN PR 7: the Permission filter for CI4 routes. It must land before the cutover.
3. One batched PR of small follow-ups: #89 test and cleanup items, the Control Panel key warning with the settings
   UI, and `SessionGate::signIn()` call sites with `keepSignIn` only where `main` #87 passes it.
4. Port the Docker bind and app-password start-up hardening from `main` [#75](https://github.com/thordin9/apotheke/pull/75)
   (SEC-M3) into the CI4 image.
5. Layout module (shared page shell, assets under `ci4/public`), then the Auth pages on top of it.
6. Media module on `Core/Storage` (photos and originals behind the storage interface).

Cloudflare phase: keep storage behind interfaces, no per-request global state, and the database config from a DSN.
The at-rest key comes in through `SecretKey`, so a Worker secret binding can replace the environment and key file.
