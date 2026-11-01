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
| `GET /api/v1/branding` | anyone | `name`, `tagline`, `accent`, `theme_default`, `logo_url`, `favicon_url`, `touch_icon_url`: what every page's head already shows, so no sign-in is needed. |
| `GET /api/v1/users` | Users | Every account, sorted by name: `id`, `name`, `role`, `status`, `created_at`, `last_login_at` (UTC; `null` when never signed in). Nothing else. |
| `GET /api/v1/roles` | Users | Every role, built-in first: `key`, `name`, `builtin`, `permissions`, `users` (how many accounts have it). |
| `GET /api/v1/security` | Users | `sign_in_enabled` and `policy`: the guest, registration and session-length settings of Users & sign-in. The reverse-proxy setting is not included (it is server set-up). |
| `POST /api/v1/imports` | Import, signed in | Upload a CSV (`multipart/form-data`, field `file`); `201` with the import and a `Location` header. A guest gets `403` `permission.denied`, even when the Guest role has Import. |
| `GET /api/v1/imports/{id}` | Import, and the user who uploaded it or an admin | The import: status, columns with the guessed field, the first five rows, the fields to map to. |
| `GET /api/v1/imports/{id}/preview` | Import, and the user who uploaded it or an admin | A dry run: what each row would do (`new`, `update`, `same` or `error`) and the summary. Query `map` (the field per column, comma-separated), `create`, `skip_errors`, `page`, `per_page` (up to 200). Writes nothing, the mapping included. |
| `POST /api/v1/imports/{id}/commit` | Import, and the user who uploaded it or an admin | Import the file as a background job, with an optional `{"map": […], "create": …, "skip_errors": …}`. `202` with the job and a `Location` header; `422` when rows have errors and `skip_errors` is off. Rows are committed one by one, so they show up while the job runs; a stopped or failed job resumes when the import is committed again. |
| `POST /api/v1/restores` | Backup, signed in | Start a [chunked restore](#chunked-restore) with `{"size": <bytes>, "sha256": <hex, optional>}`; `201` with the upload and a `Location` header. |
| `GET /api/v1/restores/{id}` | Backup, your own upload | `status` (`uploading` or `ready`), `size`, `chunk_size`, `received`, `next_chunk`, `idle_seconds`. |
| `POST /api/v1/restores/{id}/chunks/{n}` | Backup, your own upload | Chunk `n` (from 0) as the `multipart/form-data` field `chunk`. |
| `POST /api/v1/restores/{id}/finish` | Backup, your own upload | Check the upload and the backup: the dry run, under `backup`. Nothing is changed. |
| `POST /api/v1/restores/{id}/confirm` | Backup, your own upload | `{"confirm": true}`: replace the catalog with the backup. |
| `DELETE /api/v1/restores/{id}` | Backup, your own upload | Cancel it (`204`); its files are removed. |
| `GET /api/v1/jobs/{id}` | the job type's permission, and the user who started it or an admin | `status` (`queued`, `running`, `done`, `failed`), `progress` (`done` and `total` rows), `result` and `error`. Anyone else gets `404`, as for an id that is not there. |

A job that the worker stops cleanly (SIGTERM, with pcntl) is released and resumes at once. A worker that is killed
(SIGKILL, an out-of-memory kill, a crash) cannot release it: the job stays `running` until its 300-second lease runs
out, and only then does the next `jobs:work` pick it up and resume from its last checkpoint.

Any other path under `/api` answers `404`.

An import is shown only to the user who uploaded it, or an admin; anyone else gets the same `404` as for an id that
is not there. Cell values in an import's `sample` and the preview's `name` are raw, as they are imported. A cell that
starts with `=`, `+`, `-`, `@`, a tab or CR is listed in `sample_formula` (per sample row) and in each preview row's
`formula` (column indexes): a spreadsheet runs such a cell as a formula, so a client that writes these values to a CSV
must neutralise them, as the export does with a leading apostrophe.

## Chunked restore

For a backup larger than the host lets one request carry. Start an upload with the zip's size in bytes (at most
`RESTORE_MAX_MB`) and, if you can, its SHA-256. Send it in order, chunk 0 first, each chunk exactly `chunk_size`
bytes except the last. Always use the `chunk_size` the start answers: it is `RESTORE_CHUNK_KB`, but never more than
this server's PHP takes in one request (`upload_max_filesize`, and `post_max_size` less 64 KiB), so on a host with
PHP's default 2M limit (plain PHP, cPanel) it is 2 MiB. A chunk the server still refuses as too large is
`413 upload.too_large`; start again with a smaller file or ask the host to raise the PHP limits. A chunk out of order is `409 restore.conflict`, one over `chunk_size` or
past the declared size `413 upload.too_large`, a short one before the end `422`; a refused chunk changes nothing,
and `GET` says which chunk is next, so a client can resume. `finish` checks the size and the SHA-256, then the backup
exactly as the Backup page checks an upload, and answers what it holds. `confirm` restores it as the page does (the
backup's settings only for a user who may change settings). While another restore runs it is `409` and the upload is
kept; a SHA-256 that does not match or a file that is not a backup removes it at `finish`.

`finish` and `confirm` can take a while on a large backup, and a proxy may give up first (75 seconds in the Docker
image's nginx for the API, 300 for the restore page). Both run to the end anyway. `finish` again gives the dry run
again. After `confirm` only the upload's record is kept (the backup itself is removed): `GET` answers status
`restored` with the confirm answer in `result`, or `failed` with `result.error`, and `confirm` again gives the same
answer without restoring again. While it still runs, `GET` and `confirm` answer `409`; poll `GET` until it does not.
Status `ready` after a lost answer means it did not restore, and you can confirm again. Status `interrupted` means the
confirm stopped before its outcome was kept (the time limit or a server restart, for example): the backup may have been
applied, so check the data; `result.error` says so and `result.sha256` is the backup's SHA-256. `confirm` again then
answers `409` and never restores it again; to restore after checking, start a new upload.
`DELETE` removes the record; otherwise it goes after an hour like any upload.

An upload belongs to the account that started it: anyone else gets the same `404` as for an id that is not there. A
guest gets `403`. Each account has one upload at a time (a new one replaces it, `409` while a request uses the old
one), and an upload without a request for an hour (`idle_seconds`) is removed. At most `RESTORE_MAX_UPLOADS` uploads
(default 8, all accounts) are open at once. When that many are open, another account's upload that is not confirmed
and has had no request for 10 minutes is removed to make room; if none is, the answer is `503 restore.busy` with
`Retry-After`. This includes a fully sent upload that waits for `confirm`: confirm within 10 minutes of `finish`, or
the upload may have to be sent again (`404` on confirm) while the cap is reached. Operators can raise
`RESTORE_MAX_UPLOADS` ([Configuration](configuration.md)). When the signed-in account cannot be read (a database
error), the answer is the generic `500 server_error`, not `403`. The disk must have the declared size plus 64 MiB free beyond what the other open uploads still expect,
else `507 storage.insufficient`. See [Deployment and operations](deployment-and-operations.md#backup-and-restore).

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
* **Cookie consent.** Until the request carries the cookie `apotheke_consent=v1` (the banner sets it), the API sets
  no cookie: `GET /api/v1/session` starts no session and answers `csrf_token: ""`, and every `POST`, `PUT`, `PATCH`
  and `DELETE` is `403` problem+json with `code` and `error` both `consent_required`. Only the exact value counts;
  any other is the same as none. A cross-origin SPA sends it with `credentials: 'include'` like the session cookie.
  See [Cookie consent](deployment-and-operations.md#cookie-consent).

  ```sh
  # one cookie jar: seed it with the consent cookie (host, subdomains, path, secure, expiry, name, value; tabs)
  printf 'apotheke.example\tFALSE\t/\tFALSE\t0\tapotheke_consent\tv1\n' > jar
  # read the token (this starts the session), then sign in; the jar keeps both cookies
  token=$(curl -s -c jar -b jar https://apotheke.example/api/v1/session | jq -r .csrf_token)
  curl -s -c jar -b jar -H 'Origin: https://apotheke.example' -H "X-CSRF-Token: $token" \
    -H 'Content-Type: application/json' -d '{"username": "admin", "password": "<password>"}' \
    https://apotheke.example/api/v1/session
  ```

## Answers

JSON is `application/json; charset=utf-8`. Errors are [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457)
`application/problem+json`:

```json
{"type": "about:blank", "title": "Unauthorized", "status": 401, "detail": "Sign in first.", "code": "auth.required"}
```

`code` is stable: `auth.required` (401), `auth.invalid_credentials` (401), `auth.pending_approval` (403),
`auth.sign_in_off` (409), `bad_request` (400), `csrf.invalid` (403), `import.done` (409), `restore.conflict` (409), `restore.busy` (503, with `Retry-After`), `storage.insufficient` (507), `import.gone` (410), `not_found` (404), `permission.denied` (403),
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
