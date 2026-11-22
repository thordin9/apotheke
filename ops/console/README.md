# Operator console

Local preview of the hosted operator console. This preview is not deployed, it is not built into `frontend/dist`, and it is not served on getapotheke.com or api.services.getapotheke.com.

`apotheke-ops` serves the console and `/api/ops/v1` at `console.getapotheke.com`, behind Cloudflare Access. Calls are same-origin. The credential is the Access cookie. There is no app session.

## Preview

```sh
cd ops/console
npm ci
npm run dev
```

The dev server binds to `127.0.0.1:4181` only. It answers the read endpoints (`/config`, `/overview`, `/installations`, `/installations/:id`, `/installations/:id/users`, `/plans`, `/subscriptions`, `/jobs`, `/audit` and `/audit.csv`) with made-up rows on `example.test`. That mock is a Vite dev middleware. `npm run build` does not include it, and `npm run test:bundle` fails if the built files contain it or an inline script, inline style or external URL.

Overview is `/overview`. Counts are tiles. Revenue stays blank (`Not set`). Until the stats cron runs, the preview's `as_of` is null and the screen says stats are not computed yet. Installation users are a list: username, roles, status, and created and last sign-in. While writes are off, write controls are drawn and disabled and the page sends no write. Export CSV is a same-origin link to `/api/ops/v1/audit.csv`; when more than 10,000 rows match, the response's `X-Truncated` header is 1.

For the error states, set the cookie `ops_preview_fault` to `401`, `503` or `410` before loading the preview. The cookie is read only by that middleware.

## API base and writes

The base defaults to same-origin `/api/ops/v1`. Set `<meta name="ops-api-base" content="...">` to point somewhere else. `readApiBase()` is the only reader.

There is no CSRF token. `csrfHeaders()` is the only place a write is marked: every `POST`, `PUT` and `DELETE` sends `X-CSRF: 1`. The server answers 403 without that header, and also checks that `Origin` equals the ops host. The function is the place a real token would replace the fixed value.

When `writes_enabled` is true the installation, plan and job screens post those writes. Destructive actions (purge, delete, removing an override, admin reset, claim, and a plan downgrade) ask for confirmation first. Purge, reset and claim ask for the installation host. A claim link is shown once in the page and is cleared on dismiss or navigation; it is not stored. Disable and Re-enable stay disabled: there is no ops route for them yet. Provision stays disabled while `provisioning_enabled` is false. Limit writes still submit while `limits_enabled` is false, and the screen says limits are stored but not enforced yet.

Answers are `{ok:true,…}` or `{ok:false,code,detail?}`. A 401 says the Access session ended. A 403 `writes_disabled` says writes are disabled. A 403 `primary` says the primary installation can't be changed here. A 422 `invalid_body` shows its `detail` on that field. A 503 is `not_configured`, `access_unavailable`, or `unavailable` with `detail`. `ok: false` on a 2xx is still an error.

`GET /config` is read once at startup (`provisioning_enabled`, `limits_enabled`, and `writes_enabled`). A missing `writes_enabled` is treated as false. The preview reports all three false. Set the dev cookie `ops_preview_writes` to `1` and the mock reports `writes_enabled: true`. The header then hides Read-only and the write screens are live. That cookie is read only by the dev mock, the same way as `ops_preview_fault`. A failed config read shows that error and does not open a screen.

## Checks

```sh
npm run build
npm test
npm run test:bundle
npm run e2e
```
