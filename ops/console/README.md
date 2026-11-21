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

The screens are read-only. Overview is `/overview`. Revenue stays blank (`Not set`). Until the stats cron runs, the preview's `as_of` is null and the screen says stats are not computed yet. Installation users are a list: username, roles, raw status, and created and last sign-in. There is no disable or reset. Export CSV is a same-origin link to `/api/ops/v1/audit.csv`; when more than 10,000 rows match, the response's `X-Truncated` header is 1.

For the error states, set the cookie `ops_preview_fault` to `401`, `503` or `410` before loading the preview. The cookie is read only by that middleware.

## API base and writes

The base defaults to same-origin `/api/ops/v1`. Set `<meta name="ops-api-base" content="...">` to point somewhere else. `readApiBase()` is the only reader.

There is no CSRF token. `csrfHeaders()` is the only place a write is marked: every `POST` and `PUT` sends `X-CSRF: 1`. This release does not send a write. The server answers 403 without that header, and also checks that `Origin` equals the ops host. The function is the place a real token would replace the fixed value.

Answers are `{ok:true,…}` or `{ok:false,code,detail?}`. A 401 says the Access session ended. A 403 `writes_disabled` says writes are disabled. A 422 `invalid_query` shows its `detail`. A 503 is `not_configured`, `access_unavailable`, or `unavailable` with `detail`. `ok: false` on a 2xx is still an error.

`GET /config` is read once at startup (`provisioning_enabled` and `limits_enabled`). The preview reports both false, matching prod. A failed config read shows that error and does not open a screen.

## Checks

```sh
npm run build
npm test
npm run test:bundle
npm run e2e
```
