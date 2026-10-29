# AI autofill (optional)

Apotheke can ask a separate **autofill service** to suggest an item's name, category, tags and description from its
photo. The service is its own project
([thordin9/apotheke-autofill-service](https://github.com/thordin9/apotheke-autofill-service), API v1; the optional
model and image-size fields are API v1.2). It usually runs as a Cloudflare Worker
([deploy steps](https://github.com/thordin9/apotheke-autofill-service/blob/main/docs/cloudflare-workers-deploy.md));
the older Jetson/GPU build speaks the same API.

Autofill is **off by default**, and while it is off Apotheke never contacts the service. With it on, nothing is sent
until someone taps **Analyze** on a photo, unless an admin turns on *Analyze photos automatically*. What people see
on the item form is in the [user guide](user-guide.md#suggestions-from-a-photo-ai-autofill).

## Setting it up

Control Panel → **AI autofill** (`/settings/autofill`, needs the *Settings* permission, admins only by default):

1. Switch on **Suggest details from the photo**.
2. **Service address**: your Worker URL without `/v1`, for example `https://autofill.example`. Only `http://` and
   `https://`. If Apotheke runs in Docker, `localhost` is the container itself: use an address the container can reach.
3. **Timeout (seconds)**: about 5 s more than the service's own timeout.
4. Press **Test connection** (nothing is saved).
5. If the service runs with `AUTOFILL_TOKEN`, enter it under **Access token** and press **Save token**. Set
   `APP_KEY` first so it is encrypted at rest ([Stored secrets](configuration.md#stored-secrets)).
6. Press **Save**. Apotheke fetches the service's `/v1/info` once (at most 5 s), so the first photo already gets a
   progress estimate.

| Setting | Default | Notes |
|---|---|---|
| Suggest details from the photo | off | Master switch. |
| Analyze photos automatically | off | Off: each photo on the item form gets an *Analyze* button and nothing is sent until someone taps it. On: a picked photo is analyzed right away; the buttons stay for another try. |
| Service address | empty | See step 2. |
| Timeout (seconds) | 50 | 3–60. 50 fits the Jetson setup, where the service gives up after 45 s (`BACKEND_TIMEOUT_S`). A `Retry-After` is honoured only when it is at most 60 s and no longer than this timeout. |
| Model | service default | Shown when the service lists `models` (see below). Sent as `context.model`. |
| Image size | recommended | Shown when the service lists `image_sizes`. The long edge of the suggestion copy; never enlarged. |
| Maximum image size (bytes) | `auto` | The byte cap for the JPEG that is sent; see [Maximum image size](#maximum-image-size). |
| Access token | none | Write-only; see [The access token is write-only](#the-access-token-is-write-only). |

The values live in the `settings` table (`autofill_enabled`, `autofill_auto`, `autofill_url`, `autofill_timeout`,
`autofill_model` (`''` = service default), `autofill_image_size` (`0` = recommended), `autofill_max_image_bytes`,
`autofill_token`). Everything but the token is exported and imported with the other settings.

### Model and image size (API v1.2)

The dropdowns are filled from the saved service's last known `/v1/info`, or from the `/v1/info` a **Test** just
fetched (also for an address that is not saved yet). While the service does not offer the lists, the dropdowns are
hidden and a hidden field keeps the stored choice. A stored choice that the service no longer lists stays selectable,
marked *not offered by the service*.

| Service lists… | Model dropdown | Image size dropdown, long edge sent |
|---|---|---|
| nothing (API v1.0/v1.1) | hidden, no model sent | hidden, 1024 px |
| `models` with one entry (`ALLOWED_MODELS` unset on the service) | shown, disabled, with the hint *More models appear when ALLOWED_MODELS is set on the service.* | – |
| `models` with two or more entries | enabled; *Service default* sends no model | – |
| `image_sizes` | – | enabled; *Recommended* is the size marked `recommended`, else the largest up to 1024 px, else the smallest |

The Jetson setup runs with `IMAGE_SIZES=448,560*,1024`, so *Recommended* is 560 px there. Which model and size suit
your hardware is measured in the service's benchmarks (`docs/benchmarks.md` in the service repository).

*loaded* and a model's typical time are shown when the service reports them; Apotheke does not rely on *loaded*, so
the progress bar always allows for the full timeout. Switching models is a **cold start** on the service: it may
answer `503` with `Retry-After`, which Apotheke handles like a warming model (wait, one retry). An unknown model falls
back to the service's default, reported in `model_used`. The progress estimate uses the chosen model's
`avg_latency_ms`, falling back to the service-wide value.

### Maximum image size

**Maximum image size (bytes)** (`autofill_max_image_bytes`) has no environment variable and no `config.php` key.

| Value | Cap for the JPEG that is sent |
|---|---|
| `auto` (default; an empty field saves `auto`) | `max_image_bytes` from the service's last `/v1/info`, or 983040 bytes (0.9 MiB) when that answer has not been seen |
| a whole number from 65536 to 10485760 | that number, capped to `enforced_image_bytes` when `/v1/info` includes it, otherwise to `max_image_bytes`; used as stored only when neither is known. Other values are rejected with the range in the message |

A lower service cap is logged once per pair of sizes, never with the token. The cap applies to the JPEG itself; the
multipart wrapper (about 320 bytes) is not counted. If the service answers **HTTP 413**, Apotheke fetches `/v1/info`
once, clamps again and uploads once more; a second 413 is the "too large" message. A limit that would display as
0 MiB is shown in KB (1000 bytes is 1 KB). The stored photo is never resized for this.

`AUTOFILL_UPLOAD_MAX_SIDE` and `AUTOFILL_UPLOAD_JPEG_QUALITY` set the starting long edge and JPEG quality of that copy
([Other environment variables](configuration.md#other-environment-variables)); they do not change the byte cap.

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

Each `/v1/info` answer of the **saved** service is cached for an hour: when you save the section or the access
token (one request, at most min(timeout, 5 s); a failure never stops the save), when Test connection or Test with a photo runs
against the saved address (testing an unsaved address caches nothing), and after a successful suggestion
when the copy is older than 10 minutes. Apotheke reads these fields; unknown fields are ignored:

| Field | Required | Used for |
|---|---|---|
| `name`, `version`, `capabilities` | yes | Test connection |
| `backend` | optional | shown by Test connection |
| `max_image_bytes` | optional | when Maximum image size is `auto`, the byte cap for the JPEG that is sent. Missing: 983040 |
| `enforced_image_bytes` | optional | a positive integer. When it is smaller than the configured cap, the JPEG is clamped to it and a warning is logged once for that pair of sizes (the two sizes only, never the token). A wrong shape is ignored and does not reject `/v1/info` |
| `ready` | optional (default `true`) | *still warming up* |
| `avg_latency_ms` | optional | the progress estimate on the item form (*Typical answer* in Test connection). A non-negative integer: the service's recent average time per suggestion in milliseconds. Anything else, or a service that does not send it, is ignored: the form then shows a spinner instead of a percentage. |
| `models` | optional, API v1.2 | the Model dropdown and per-model estimates. A list of `{id, default?, loaded?, avg_latency_ms?}`: `id` a non-empty string (≤ 120 characters), `default`/`loaded` booleans, `avg_latency_ms` as above. Entries of the wrong shape are skipped, duplicates merged, at most one default (the first marked; none marked: the first listed), at most 50. A list with nothing usable counts as absent. |
| `image_sizes` | optional, API v1.2 | the Image size dropdown. A list of `{long_edge, recommended?}`, `long_edge` an integer 64–4096. Wrong entries are skipped; sorted, duplicates merged, at most one recommended. |

A `/v1/suggest` answer may carry `model_used` (API v1.2, a string ≤ 120 characters): Apotheke passes it to the
browser (`data-af-model-used`, see *Debug hooks*) and shows it as **Model used** in Test with a photo.

### Test with a photo

**Test with a photo** (same rules for the address, timeout and token) runs the same
checks and then sends a bundled sample photo (`public/assets/autofill-sample.jpg`, a claw hammer; public
domain, by Evan-Amos on Wikimedia Commons) through `POST /v1/suggest` with your real categories and tags as
context. It shows:

- three checks: **Reachable**, **Token accepted**, **Model ready** (a check that could not run says *not checked*);
- the suggested name, category, tags and description, with the confidence the service gave each field;
- **Model used** (`model_used`, with *asked for X* when the service fell back to another model) and the **Image
  size** sent, when the service offers models / sizes;
- how long the suggestion took.

It uses the **Model** and **Image size** as currently chosen in the form, saved or not, so you can try a model before
saving it.

Each request stays within the time still left of the timeout as typed. A `503` with `Retry-After` of at most 60
seconds, and no longer than that timeout, is retried once after the wait (a cold model, including the first photo
after a model switch), so the test can take as long as the timeout. A longer `Retry-After` is not waited out. A service that is down, slow or refusing the token ends with a clear message
instead of a hanging page. The token is never shown. Like Test connection it is a plain form button: it works
without JavaScript (with JavaScript the result area says *Sending the sample photo…* while it runs) and nothing
is saved.

Typical results and what to do:

| Result | Fix |
|---|---|
| *Could not connect* | Wrong address or port, the service is not running, or a firewall is in the way. |
| *No answer within N seconds* | The service is overloaded, or the timeout is too short. |
| *autofill is unavailable right now* | Test with a photo: the suggestion step timed out, the network failed, or the answer was not the API. |
| *The service requires a token* / *refused the token* | Save the token the service expects, or clear it. |
| *Still warming up* / *Model not ready* | The service is loading its model; try again in a minute. |
| *Model used: X (asked for Y)* | Model Y is not allowed or not installed on the service; pick another. |

### The access token is write-only

- It is saved through its own form and **never shown again**: the page only says whether a token is set.
- It is a `secret` setting: not in the settings export, not in backups, cannot be set by a settings import,
  never written to logs and never sent to the browser. With `APP_KEY` set it is stored as an `enc:v1:` envelope.
  Without a key it stays plaintext and the Control Panel says to set `APP_KEY`. See
  [Stored secrets](configuration.md#stored-secrets). Suggestions go through Apotheke's server, so the
  browser never talks to the service directly.
- **Clear token** removes it.
- Saving a **different service address** (same comparison as above) clears the stored token in the same
  save, and the page says so: enter the token again for the new service. A token submitted with that same save
  (`autofill_token`) is kept instead. Resetting the section to another address clears it too.

All forms on the page need the CSRF token; viewers and editors get `403`.

## Suggestions from a photo

With autofill on **and** a service address set, the item form (new and edit; needs the *Add items*
permission) gets **Analyze** buttons, a status line and a progress bar under the photo. Nothing is shown otherwise.

### Analyze buttons

- **The photo you just picked** gets *Analyze photo* as soon as it is on the form (take a photo or pick one from the
  library). Picking another photo replaces it; an analysis still working on the replaced photo is dropped.
- **On the edit form**, every photo the item already has gets its own *Analyze* button (a thumbnail row
  *Suggest details from a photo of this item*). These are sent by id (`photo_id`); the server reads the stored file.
- **One analysis at a time.** While one runs, its button is marked busy (`aria-busy`) and every Analyze button is
  `aria-disabled`: they stay focusable (keyboard focus is not dropped) and a click or keypress does nothing until the
  run ends (answer, failure or a dropped analysis). Picking a new photo while a stored photo is being analyzed does
  not interrupt it.
- With **Analyze photos automatically** on, a picked photo starts its analysis at once, unless another
  analysis is running.
- The buttons need JavaScript; without it the form works as always, with no suggestions.

### What is sent

1. **Analyze** resizes the picked photo in the browser to the chosen long edge (`data-af-side`: the Image size, or
   1024 px for a service without `image_sizes`), never enlarged and upright after EXIF orientation. It is a JPEG on
   white, or a PNG when the photo has transparency; a small JPEG that would only grow is sent as it is. That copy is
   posted to Apotheke's `POST /autofill/suggest` (same-origin, CSRF token) and is used for the suggestion only.
   Stored photos on the edit form are sent by id (`photo_id`).
2. The server does not pass that upload on. It builds a **separate** JPEG from the camera file when one exists,
   otherwise from the served file: EXIF orientation applied, long side at most `min(chosen size, 1600)`, never
   enlarged, quality about 80, metadata stripped. Over the byte cap, quality steps down by 10 (60 is always tried),
   then the long edge shrinks, until it fits; if it cannot fit, nothing is sent. GD is used first, Imagick when GD
   cannot read the file (HEIC included, when Imagick lists `HEIC` or `HEIF`, as in the Docker image). Imagick
   flattens transparency onto white, converts CMYK to sRGB and writes 4:2:0 chroma subsampling; for a grayscale
   photo it writes a 1-channel JPEG. The temp file `DATA_DIR/tmp/autofill-*.jpg` is deleted when the call ends,
   errors included. A GD-only install keeps a HEIC upload but skips autofill for it.
3. It calls the service's `POST /v1/suggest` with that JPEG, the bearer token and a `context`: the names of your
   **categories** and **tags** (so the service reuses your spelling), `locale`, and `model` when one is chosen.
4. The answer is validated (unknown fields ignored, wrong-shaped fields dropped, name ≤ 200, category ≤ 120,
   at most 8 tags of ≤ 40 characters, description ≤ 2000) and returned to the browser.

### Progress while the service works

The status line goes through these stages; feedback appears at once (well within a second), and the form
stays usable the whole time:

1. **Uploading…** with the real upload progress (an `XMLHttpRequest` upload event; usually a blink on a LAN).
2. **Analyzing photo…** The service does one model call and cannot report progress, so the bar is an
   **estimate**: elapsed time against `avg_latency_ms` from the cached `/v1/info`, filled linearly so the
   percentage and *about N s left* agree (10 s into a 20 s average: 50 %, about 10 s left). It stops at 95 %
   and says *still working…* when the photo takes longer than average; after **5 s at 95 %** the status line
   adds *The model may be loading or busy – this can take up to N s.* (N is the timeout from Control Panel →
   AI autofill), since a cold model or a queued request can take several times the average. It never shows
   100 % before the answer. Without `avg_latency_ms` it is an indeterminate spinner. The bar
   is a `role="progressbar"` with `aria-valuenow` and `aria-valuetext`; the stage text is a polite live
   region. With *reduce motion* the bar does not animate.
3. **Suggestions ready** (and which fields were filled), **No suggestions for this photo**, or the reason it
   failed (table below). On success the bar jumps to 100 % and disappears.

**Warming up or busy.** When the service answers `503` `backend_unavailable` with a `Retry-After` of at most 60
seconds and no longer than the timeout, the browser shows **Model is warming up… retrying in N s** (the message says
*loading* or *warming up*) or **Service is busy… retrying in N s** with a countdown, then resends the same analysis
JPEG **once**. Saving stays available during the wait. There is no retry when another photo was picked meanwhile or
when `Retry-After` is longer (a daily cap that counts down to 00:00 UTC): that is *the service is busy or not ready*.

**Only empty fields are filled, never one you have touched, and never one an earlier analysis filled.** Typing in
a field (even if you then clear it), picking from a list (even the same value) or removing a tag chip makes it yours.
On a **new** item the preselected defaults (default category, tags and notes) count as empty until touched; a
category chosen by the link you came from (`/new?cat=…`) and everything on an existing item's edit form are yours.
The category is set to an existing one when the names match (ignoring case); a new name pre-fills *＋ New category*
only when no category is selected, so saving never creates a category you did not ask for. Suggestions go to Name,
Category, Tags and Notes (the service's `description`), and filled fields are highlighted until you edit them. To
start over, reload the form.

Saving never waits for suggestions and works the same when the service is off, down or slow.

### When it does not work

Every problem means "no suggestions" plus a short reason in the status line; nothing blocks the form.

| Situation | Status line |
|---|---|
| Autofill off | nothing: no status line, no request |
| Service down, connect failure, or suggest timeout | *autofill is unavailable right now* (no retry). Test connection still reports *not reachable* or *timeout* on its own checks |
| HTML 5xx (including a Cloudflare 1102 page), 200 that is not JSON, truncated JSON, or a JSON 5xx that is not the API error object | *autofill is unavailable right now* (no retry). The item can still be saved |
| Wrong token (`401`) | *refused the access token* (an admin fixes it in the Control Panel) |
| No token set, service wants one (`401`) | *requires an access token* |
| Backend down or not ready (`503` `backend_unavailable`) | *busy or not ready* |
| Backend failed on this photo (`503` `backend_error`) | *the service had a problem with this photo. Try again, or fill in the fields yourself* (no retry) |
| Model loading (`503` + `Retry-After`, "model … is loading / warming up") | *Model is warming up… retrying in N s*, one retry; then *the model is warming up. Try again in N s* |
| Queue full (`503` + `Retry-After` ≤ 60 s, e.g. "backend busy, queue full") | *Service is busy… retrying in N s*, one retry; then *the service is busy. Try again in N s* |
| `Retry-After` over 60 s (a daily cap) | *the service is busy or not ready*, no retry, save at once |
| Photo too large (`413`, or the analysis copy cannot be made to fit the byte cap) | *the photo is too large (max N MiB / N bytes)*, with the cap from [Maximum image size](#maximum-image-size); a 413 is retried once after refetching `/v1/info` |
| Image type not supported (`415`) | *cannot read this kind of image* |
| Not an image, or a photo that cannot be turned into a stripped JPEG (no GD/Imagick, HEIC without libheif, broken file) | *cannot be read as a photo* – checked locally, nothing is sent |
| Header over the same dimension limit as an upload, or a decode refused as too large (a PNG above 26 megapixels, a WebP or GIF above 40, or a HEIC the decoder will not allocate) | *Image dimensions are too large.* HTTP 422. Nothing is stored or sent |
| `200 {}` | *No suggestions for this photo.* |

**Saving never waits for a suggestion** – the browser asks `/autofill/suggest` separately. But a pending
suggestion does occupy one PHP worker for up to the timeout. The shipped Docker image runs nginx + php-fpm
(`pm.max_children = 10`), so saves go through meanwhile. Only the single-worker `php -S` dev server is
affected: there a save queues behind a pending suggestion (start it with `PHP_CLI_SERVER_WORKERS=4` to avoid that).

Problems are logged as one short line (`apotheke autofill: …`) without the token or the response body.

### Debug hooks (for QA)

After each analysis the item form (`#itemform`) carries what was sent, readable in the browser's dev tools or by a
test (`getAttribute`):

| Attribute | Content |
|---|---|
| `data-af-upload` | JSON `{type, bytes, width, height}` of the file the **browser** uploaded to `/autofill/suggest` (after the resize; `width`/`height` null when it could not decode the photo, e.g. HEIC outside Safari). Not set for a stored photo. |
| `data-af-sent` | JSON `{type, bytes, width, height}` of the image the **server** sent to the service (the `image` field of the `/autofill/suggest` answer). |
| `data-af-model-used` | the service's `model_used`, when it reports one. |
| `data-af-side`, `data-af-latency`, `data-af-auto` | the long edge the browser resizes to, the expected time per photo (ms) and whether auto-analyze is on (rendered by the server). |


## Upgrading from 2.3

- Migration **0025** sets *Analyze photos automatically* to **off** on every install, including installs that had
  autofill on: people tap **Analyze** on a photo instead. To get the 2.3 behaviour back, turn it on under Control
  Panel → AI autofill and save.
- Model and image size start at *Service default* and *Recommended*. With a service older than API v1.2 nothing
  changes: no model is sent and photos go at 1024 px. Once the service speaks v1.2, press **Test connection** (or
  Save) to see the dropdowns.
- A second analysis no longer replaces what an earlier one filled in.

## Trying it locally

Run the real service's echo backend (no model needed):

```sh
# in apotheke-autofill-service
BACKEND=echo uvicorn app.main:app --port 8080          # add AUTOFILL_TOKEN=… to require a token
```

Then in Apotheke: Control Panel → AI autofill → switch on, address `http://localhost:8080`
(or the machine's LAN address if Apotheke runs in Docker: `localhost` there is the container),
**Test connection**, save, and add an item with a photo, then tap **Analyze photo**. `ECHO_MODE=error|malformed|slow`
on the service simulates failures. The service's own echo backend reports `models` and `image_sizes` from its
`ALLOWED_MODELS` / `IMAGE_SIZES` environment (service API v1.2), so the Model and Image size dropdowns appear after
a Test.

## Tests

CI does not need the Python service. `tests/stubs/autofill-stub.php` mimics the echo backend and is started on a free
port by the unit tests, the HTTP integration suite and Playwright (`tests/visual/autofill-stub.ts`). The mode and
token are picked by a path prefix of the address, e.g. `http://127.0.0.1:PORT/m/slow/t/s3cret`:

| Prefix | Effect |
|---|---|
| `/m/<mode>` | `ok`, `error` (503), `malformed`, `slow`, `toolarge` (413), `unsupported` (415), `empty`, `badinfo`, `warming`, `fault` (503 `backend_error`), `busy` (503 + `Retry-After`, "queue full"), `warmup` ("is warming up"), `cold` (503 "is loading" + `Retry-After` on every other suggestion), `switch` (with `/n/`: a suggestion for a model that is not loaded answers 503 once and loads it) |
| `/r/<s>`, `/l/<ms>`, `/d/<ms>`, `/s/<name>` | the `Retry-After` value, `avg_latency_ms` in `/v1/info`, a delay before the suggestion, the suggested category |
| `/n/<models>` | `/v1/info` lists these `models`, comma-separated (URL-encode `:`); `*` marks the default, `=<ms>` its `avg_latency_ms`, e.g. `/n/qwen2.5vl-jetson%3A3b*=14000,qwen3-vl-2b=6000` |
| `/z/<sizes>` | `/v1/info` lists these `image_sizes`; `*` marks the recommended one, e.g. `/z/448,672*,1024` |
| `/t/<token>` | the token the stub requires |

`GET /_stub/uploads` returns the last 50 `/v1/suggest` requests (`type`, `bytes`, `width`, `height`, `alpha`,
`context`, `mode`, `filename`, `mime`); `DELETE` clears the log and the loaded model. With `STUB_LOG=<file>` the log
is `<file>.jsonl`, otherwise `af-stub-<port>.jsonl` in the temp dir. A full v1.2 address for manual QA:
`http://127.0.0.1:8090/m/switch/r/3/l/4000/n/qwen2.5vl-jetson%3A3b*=4000,qwen3-vl-2b=2500/z/448,672*,1024`.

The tests are `tests/Modules/Autofill/AutofillTest.php` (settings, `/v1/info` and suggestion parsing, image
preparation, every failure code, the token kept out of answers and logs, Test connection, suggest against the stub,
stored photos) and
`tests/visual/autofill-*.spec.ts` (the Control Panel section, the on-demand form, model and size, resizing).

## Security notes

- Photos go from the browser to Apotheke, and from Apotheke's server to the service; the browser never talks to the
  service and never sees the address or the token.
- Test connection can make this server contact any http(s) host and port an admin types in (redirects are not
  followed). Grant the *Settings* permission only to people you trust with that.
- Problems are logged as one short line (`apotheke autofill: …`), never with the token or the service's answer.

## Implementation

The client is `modules/Autofill`, built per request by `Config\Services::autofill()`.
Settings and the token are read only through `SettingsService`. The database is the existing DSN-driven connection.
There is no static and no per-request memory for the warn-once pair: it lives in the storage file, so a new request
sees it. Docs and examples keep the placeholder `https://autofill.example`. Do not commit a real token.

**HTTP.** Every POST needs the session CSRF token (`csrf` or `X-CSRF-Token`), like the other CI4 routes. Responses
that are JSON use `Content-Type: application/json; charset=utf-8` and `Cache-Control: no-store`.

| Method and path | Body | Result |
|---|---|---|
| POST `/autofill/suggest` | `photo_id` of a stored photo, or an `image` upload | JSON. `error` is null or the message. The stored file is not written. |
| POST `/settings/autofill/token` | `token` to save, or `action=clear` to remove it. An empty `token` is 422, not a clear. | JSON `{ok,redirect}` for a fetch client, otherwise 303 to `/settings/autofill?done=token-saved` or `token-cleared`. |
| POST `/settings/autofill/test` | `autofill_url`, `autofill_timeout`, optional `autofill_token` | Always JSON. |
| POST `/settings/autofill/test-photo` | same, plus optional `autofill_model` and `autofill_image_size` | Always JSON. Uses `public/assets/autofill-sample.jpg`. |

**Cap.** The limit is the JPEG, not the multipart wrapper. Order: `enforced_image_bytes` from `/v1/info` (one warning
per distinct pair), then `autofill_max_image_bytes` (`auto` is `/v1/info` `max_image_bytes`, or 983040; an explicit
value is 65536–10485760), then that value as stored when the service has not said. A higher enforced value does not
raise the target. `AUTOFILL_MAX_IMAGE_BYTES` is ignored. `AUTOFILL_UPLOAD_MAX_SIDE` (64–4096, default 1600) and
`AUTOFILL_UPLOAD_JPEG_QUALITY` (40–95, default 80) are environment variables. Quality steps down by 10, and 60 is
tried before the long edge shrinks. On HTTP 413, `/v1/info` is fetched once and the upload is retried once.

**Storage.** `Apotheke\Core\Storage\Storage`, implemented for now by `LocalStorage` with root `DATA_DIR`:

| Key | Contents |
|---|---|
| `autofill/info.json` | Last `/v1/info` for the saved address, and the warn-once clamp pair |
| `autofill/tmp/autofill-<16 hex>.jpg` | The resized JPEG, deleted after the request |

Nothing in the module writes a `writable/` path. A key whose path contains a symlink is refused, and a replace is a
temporary file in the same directory followed by `rename`, so a read-only file can still be replaced. The legacy
filename `autofill-info.json` is not read. Media should implement this same interface later (for example for R2)
rather than add a second one.

**Not in this module yet.** The Control Panel HTML and the item-form Analyze button stay on legacy. The four POST
routes need the session CSRF token, pass the host and same-origin checks
([#76](https://github.com/thordin9/apotheke/pull/76)) and the route's permission, as legacy
([#91](https://github.com/thordin9/apotheke/pull/91)): `/autofill/suggest` needs *Add items*, the settings routes
*Settings*. The warn-once note and the temporary JPEG are files under the data directory, not under CodeIgniter's
`writable/` folder. Category
and tag names, and which file to analyze (kept original, otherwise the served JPEG), are SQL until Taxonomy and Media
exist. Tests use `tests/stubs/autofill-stub.php` on `127.0.0.1`. There is no live Worker check. The cases live in
`tests/Modules/Autofill/AutofillTest.php` and `tests/Modules/Core/LocalStorageTest.php`. Photo size limits are legacy-only for
now: the legacy header pre-check and decode limits ([#72](https://github.com/thordin9/apotheke/pull/72)) and the PNG
cap ([#79](https://github.com/thordin9/apotheke/pull/79)) are on `feature/ci4` since
[#83](https://github.com/thordin9/apotheke/pull/83), but the CI4 Autofill module does not apply them yet, so CI4
suggest has no pixel or decode limit. Porting them is a pending CI4 follow-up (Coder's L-2 port).
