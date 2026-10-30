# JSON API (v1, first part)

Apotheke 3.1 starts a JSON API under `/api/v1`, for the coming single-page app. This first part has the
session, your own account and the API's own description. The HTML pages keep working as before.

The full description is [`docs/api/openapi.yaml`](api/openapi.yaml), also served at `GET /api/v1/openapi.json`.
It is the contract between the API and the single-page app, changed in the same pull request as the endpoint it
describes, and written in YAML's JSON form so the app can serve it without a YAML parser. The tests check every API
answer against it.

## Endpoints

| Method and path | Who | What |
|---|---|---|
| `GET /api/v1/session` | anyone | `signed_in`, `sign_in_enabled`, `registration_open`, `user` (or `null`), `permissions`, `csrf_token`. Starts a session when there is none. |
| `POST /api/v1/session` | anyone | Sign in with `{"username": …, "password": …}`. Same limits and timing as the sign-in page. Answers like `GET`, with a new CSRF token. |
| `DELETE /api/v1/session` | anyone with a session | Sign out (`204`). |
| `GET /api/v1/me` | signed in | `id`, `username`, `role`, `permissions`. With sign-in turned off there is no account, so this is `403`. |
| `GET /api/v1/openapi.json` | anyone | The OpenAPI document. |
| `POST /api/v1/imports` | Import | Upload a CSV (`multipart/form-data`, field `file`); `201` with the import and a `Location` header. |
| `GET /api/v1/imports/{id}` | Import | The import: status, columns with the guessed field, the first five rows, the fields to map to. |
| `GET /api/v1/imports/{id}/preview` | Import | A dry run: what each row would do (`new`, `update`, `same` or `error`) and the summary. Query `map` (the field per column, comma-separated), `create`, `skip_errors`, `page`, `per_page` (up to 200). Writes nothing, the mapping included. |

Any other path under `/api` answers `404`.

## Requests

* The session is the same cookie session as the pages: `__Host-apothekesid` on HTTPS, `apothekesid` on plain
  HTTP (see [Deployment and operations](deployment-and-operations.md#behind-a-web-server-or-reverse-proxy)).
* `POST`, `PUT`, `PATCH` and `DELETE` need all of these, or they are refused with `403 csrf.invalid`:
  * the `X-CSRF-Token` header set to `csrf_token` from `GET /api/v1/session` (the form field `csrf` does not count here);
  * an `Origin` header that is this server's own origin, or one listed in `CORS_ALLOWED_ORIGINS`;
  * `Sec-Fetch-Site` `same-origin` or `same-site`, when the browser sends it.

  A body must be `application/json`, or `multipart/form-data` for a file, otherwise the answer is
  `415 unsupported_media_type`. So a script needs to send `Origin` too.
* A sign-in or sign-out gives a new session id and a new token; read it from the answer.

## Answers

JSON is `application/json; charset=utf-8`. Errors are [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457)
`application/problem+json`:

```json
{"type": "about:blank", "title": "Unauthorized", "status": 401, "detail": "Sign in first.", "code": "auth.required"}
```

`code` is stable: `auth.required` (401), `auth.invalid_credentials` (401), `auth.pending_approval` (403),
`auth.sign_in_off` (409), `bad_request` (400), `csrf.invalid` (403), `import.done` (409), `import.gone` (410), `not_found` (404), `permission.denied` (403),
`rate_limited` (429, with `Retry-After`), `server_error` (500), `unsupported_media_type` (415), `upload.too_large`
(413) and `validation.failed` (422, with an `errors` map of field to message). An unexpected error under `/api` is
problem+json too: `500 server_error` with the fixed detail `Something went wrong on the server.`, never the error's
own text (it is logged). Any other uncaught error status without a code listed here (a 409 or 410, say) is
`request.failed`. Such an answer comes from the error handler and skips the API's after-filters: it has the security
headers below, but never the CORS headers, so a page on another origin cannot read it (the browser reports a network
error). Its HSTS header follows `PUBLIC_URL` from the environment or `config.php` (the database may be what failed),
or a direct HTTPS connection; a `PUBLIC_URL` changed only in the settings page does not count there.

Every `/api` answer is `Cache-Control: no-store` and carries `Content-Security-Policy: default-src 'none';
frame-ancestors 'none'; sandbox`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`,
`Cross-Origin-Resource-Policy: same-site` and `Cross-Origin-Opener-Policy: same-origin`, plus
`Strict-Transport-Security: max-age=31536000` when `PUBLIC_URL` is `https://`.

## Another origin (CORS)

CORS is off by default: the app's own pages need none. Set `CORS_ALLOWED_ORIGINS` to the exact origins of an app
on another host of the same site (`https://app.example.com`) to let it call the API with the session cookie.
`*`, `null` and paths are ignored, and `http://` counts only for a loopback origin with
`CI_ENVIRONMENT=development`. Origins compare case-insensitively, and one trailing dot of the host is ignored
(`https://app.example.com.` matches `https://app.example.com`); the answer's `Access-Control-Allow-Origin` echoes the
`Origin` as the browser sent it. See [Configuration](configuration.md).

## Behind a proxy

The sign-in limits count the client's address. Besides `X-Forwarded-For` from `TRUSTED_PROXIES`, the app can read
`REAL_IP_HEADER` (for example `CF-Connecting-IP`) from a listed proxy, and `X-Apotheke-Client-IP` from the hosted
edge proxy when it sends the matching `PROXY_SHARED_SECRET`. See [Configuration](configuration.md).
