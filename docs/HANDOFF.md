# Hand-off: `feature/ci4`

A one-screen status of the CodeIgniter 4 port. Every pull request into `feature/ci4` updates this page. Plan and module
rules: [PLAN.md](plans/feature12-ci4/PLAN.md), [ADR 001](adr/001-codeigniter4.md), [AGENTS.md](../AGENTS.md).

## Current state

- `feature/ci4` carries `main` up to v2.6.9 (sync [#83](https://github.com/thordin9/apotheke/pull/83)). The shipped
  image still runs the legacy app; `APOTHEKE_APP=ci4` selects CI4 in CI and in development.
- CI4 modules: Core (config, DSN, filters, storage, secret sealing), Migrations (PDO runner), Settings (schema,
  service, branding, `apotheke:settings`), Autofill (client and its four POST routes), Auth (current user, admin
  guards, sign out everywhere; no pages yet).
- Stored secrets (S-L5): with `APP_KEY` or `APP_KEY_FILE` set, CI4 seals the AI autofill token in the same `enc:v1:`
  format as `main`, so both apps read one database. `key generate` and `key rotate` stay in `bin/settings.php`.
- Not ported yet: sign-in, account and Users pages, the Layout, Items, Media and Backup modules.

## Open pull requests

- [#85](https://github.com/thordin9/apotheke/pull/85): sync `main` v2.6.13 into `feature/ci4` (waits for the two
  ports below).
- The CI4 session port (session limits, migration 0028, sign out everywhere), opened separately.
- [#89](https://github.com/thordin9/apotheke/pull/89): this page's PR, stored-secret encryption for CI4 (S-L5).

## Next steps

1. Merge the session port and this PR, then merge `feature/ci4` into the #85 branch (plain merge, no force-push) and
   get its CI green.
2. Port the Control Panel key warning with the settings UI (the legacy page shows it once #85 lands).
3. Port the Docker bind and app-password start-up hardening from `main` [#75](https://github.com/thordin9/apotheke/pull/75)
   (SEC-M3) into the CI4 image.
4. Layout module (shared page shell, assets under `ci4/public`), then the Auth pages on top of it.
5. Media module on `Core/Storage` (photos and originals behind the storage interface).

Cloudflare phase: keep storage behind interfaces, no per-request global state, and the database config from a DSN.
The at-rest key comes in through `SecretKey`, so a Worker secret binding can replace the environment and key file.
