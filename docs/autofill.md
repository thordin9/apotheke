# AI autofill (optional)

Apotheke can ask a separate **autofill service** to suggest an item's name, category, tags and
description from its photo. The service is its own project
([thordin9/apotheke-autofill-service](https://github.com/thordin9/apotheke-autofill-service), API v1)
and runs wherever you like: on the same box, a Jetson or a GPU machine on your network.

Autofill is **off by default**. While it is off, Apotheke never contacts the service.

## Setting it up

Control Panel → **AI autofill** (`/settings/autofill`, needs the *Settings* permission – admins only by default):

| Setting | Default | Notes |
|---|---|---|
| Suggest details from the photo | off | Master switch. |
| Service address | empty | Base URL without `/v1`, e.g. `http://192.168.1.10:8080`. Only `http://` and `https://`. |
| Timeout (seconds) | 30 | 3–60. The service answers `504` itself after 25 s, so 30 s is a good value. |
| Access token | none | Only if the service runs with `AUTOFILL_TOKEN`. See below. |

All values live in the `settings` table like every other Control Panel option.

### Test connection

**Test connection** checks the address and timeout *as typed* (nothing is saved):

1. `GET /healthz` (no token) – is anything listening?
2. `GET /v1/info` (with a token) – is it the autofill service, API v1, and does it accept the token?

The stored token is only sent when the typed address is the **saved** address (compared after trimming
trailing slashes and lower-casing scheme and host). For any other address no token is sent, unless one is
typed into the request as `autofill_token`; the stored, write-only token never leaves for an unsaved host.

Results: *Connected* (with name, API version, capabilities, backend and image limit in MiB), *could not
connect* (also when the 3 s connect timeout runs out), *no answer within N seconds*, *the service requires a
token* (401, none set), *the service refused the token* (401, wrong token), *not a valid address* (anything but
`http://`/`https://`), *not a valid /v1/info answer* or *still warming up* (`ready: false`).

**Probing internal hosts.** Test connection makes this server request any http(s) host and port an admin
types in, and the messages tell "nothing listening", "no answer" and HTTP errors apart, so it can be used to
map which internal hosts and ports are open. Only users with the *settings* permission can use it, redirects
are not followed and only http(s) is allowed; for a home-network app that is acceptable, but grant the
*settings* permission only to people you would trust with that.

### The access token is write-only

- It is saved through its own form and **never shown again**: the page only says whether a token is set.
- It is a `secret` setting: not in the settings export, not in backups, cannot be set by a settings import,
  never written to logs and never sent to the browser. Suggestions go through Apotheke's server, so the
  browser never talks to the service directly.
- **Clear token** removes it.

All forms on the page need the CSRF token; viewers and editors get `403`.

## Suggestions after a photo

With autofill on, the item form (new and edit; needs the *Add items* permission) shows a status line under
the photo. Right after you take or pick a photo:

1. The browser makes a JPEG of about **1024 px** on the long edge and posts it to Apotheke's
   `POST /autofill/suggest` (same-origin, CSRF token).
2. The server decodes it again with GD, applies the EXIF orientation and re-encodes a ≤ 1024 px JPEG, so
   the service always gets the recommended format. Images GD cannot read (e.g. HEIC from an old browser)
   are passed through and the service decides (`415`).
3. It calls the service's `POST /v1/suggest` with the image, the bearer token and a `context`: the names of
   your existing **categories** and **tags** (so the backend reuses your spelling) and `locale`.
4. The answer is validated (unknown fields ignored, wrong-shaped fields dropped, name ≤ 200, category ≤ 120,
   at most 8 tags of ≤ 40 characters, description ≤ 2000) and returned to the browser.

**Only empty fields are filled, and never one you have touched.** Typing in a field (even if you then clear
it) or removing a tag chip makes it yours. Filled fields are highlighted until you edit them; picking another
photo may replace earlier *suggestions*, never your input. The category is filled only when no category is
selected: an existing one is selected when the names match (ignoring case), otherwise "＋ New category" is
pre-filled. Suggestions go to Name, Category, Tags and Notes (the service's `description`).

Saving never waits for suggestions and works the same when the service is off, down or slow.

### When it does not work

Every problem means "no suggestions" plus a short reason in the status line; nothing blocks the form.

| Situation | Status line |
|---|---|
| Autofill off | nothing: no status line, no request |
| Service down / wrong address / connect timeout (3 s) | *the autofill service is not reachable* |
| Slower than the timeout | *did not answer within N seconds* (`504` from the service: *took too long*) |
| Wrong token (`401`) | *refused the access token* (an admin fixes it in the Control Panel) |
| No token set, service wants one (`401`) | *requires an access token* |
| Backend failed or not ready (`503`) | *busy or not ready* |
| Not a JSON object (malformed) | *sent an unexpected answer* |
| Photo too large (`413`, or larger than `max_image_bytes` from the last Test connection) | *larger than the service accepts* |
| Image type not supported (`415`) | *cannot read this kind of image* |
| Not an image at all (PDF, text, broken file) | *cannot be read as a photo* – checked locally, nothing is sent. Only HEIC/HEIF, which PHP cannot read, is passed through unchanged for the service to decide |
| `200 {}` | *No suggestions for this photo.* |

**Saving never waits for a suggestion** – the browser asks `/autofill/suggest` separately. But a pending
suggestion does occupy one PHP worker for up to the timeout. The shipped Docker image runs nginx + php-fpm
(`pm.max_children = 10`), so saves go through meanwhile. Only the single-worker `php -S` dev server is
affected: there a save queues behind a pending suggestion (start it with `PHP_CLI_SERVER_WORKERS=4` to avoid that).

Problems are logged as one short line (`apotheke autofill: …`) without the token or the response body.

## Trying it locally

Run the real service's echo backend (no model needed):

```sh
# in apotheke-autofill-service
BACKEND=echo uvicorn app.main:app --port 8080          # add AUTOFILL_TOKEN=… to require a token
```

Then in Apotheke: Control Panel → AI autofill → switch on, address `http://localhost:8080`
(or the machine's LAN address if Apotheke runs in Docker: `localhost` there is the container),
**Test connection**, save, and add an item with a photo. `ECHO_MODE=error|malformed|slow` on the service
simulates failures.

## Tests

CI does not need the Python service. `tests/stubs/autofill-stub.php` mimics the echo backend and is
started on a free port by the unit tests, the HTTP integration suite and Playwright
(`tests/visual/autofill-stub.ts`). The mode and token are picked by a path prefix of the address, e.g.
`http://127.0.0.1:PORT/m/slow/t/s3cret`: modes `ok`, `error` (503), `malformed`, `slow`, `toolarge` (413),
`unsupported` (415), `empty`, `badinfo`, `warming`.

- `tests/Unit/AutofillTest.php`: settings, `/v1/info` and suggestion parsing, image preparation, every failure code.
- `tests/integration_autofill.php` (part of `tests/integration.php`): settings security (CSRF, viewers and
  editors, token never in pages, exports, backups or logs), Test connection, suggest end to end.
- `tests/visual/autofill-settings.spec.ts` and `autofill-suggest.spec.ts`: the Control Panel section and
  the form on every device, with visual baselines.
