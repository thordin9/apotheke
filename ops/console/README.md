# Operator console

Local preview of the hosted operator console. It is not deployed, it is not built into `frontend/dist`, and it is not served on getapotheke.com or api.services.getapotheke.com.

`apotheke-ops` will serve this console and `/api/ops/v1` on its own host, behind Cloudflare Access. Calls are same-origin. The credential is the Access cookie. There is no app session.

## Preview

```sh
cd ops/console
npm ci
npm run dev
```

The dev server binds to `127.0.0.1:4181` only. It answers `GET /api/ops/v1/config`, `GET /overview`, `GET /installations` and `GET /installations/:id` with made-up rows. That mock is a Vite dev middleware. `npm run build` does not include it, and `npm run test:bundle` fails if the built files contain it.

For the error states, set the cookie `ops_preview_fault` to `401`, `503` or `410` before loading the preview. The cookie is read only by that middleware.

## API base and writes

The base defaults to same-origin `/api/ops/v1`. Set `<meta name="ops-api-base" content="...">` to point somewhere else. `readApiBase()` is the only reader.

There is no CSRF token. `csrfHeaders()` is the only place a write is marked: every `POST` and `PUT` sends `X-CSRF: 1`. The server answers 403 without that header, and also checks that `Origin` equals the ops host. The function is the place a real token would replace the fixed value.

`GET /config` is read once at startup (`provisioning_enabled` and `limits_enabled`). The preview reports both false, matching prod. A failed config read shows that error and does not open a screen.

## Checks

```sh
npm run build
npm test
npm run test:bundle
npm run e2e
```
