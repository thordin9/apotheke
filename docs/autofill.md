# AI autofill (optional)

Apotheke can ask a separate **autofill service** to suggest an item's name, category, tags and
description from its photo. The service is its own project
([thordin9/apotheke-autofill-service](https://github.com/thordin9/apotheke-autofill-service), API v1; the
optional model and image-size fields are API v1.2) and runs wherever you like: on the same box, a Jetson or a GPU
machine on your network.

Autofill is **off by default**. While it is off, Apotheke never contacts the service. Since Apotheke 2.4 it also
works **on demand**: even with autofill on, nothing is sent when a photo is picked; each photo gets an **Analyze**
button. *Analyze photos automatically* (off by default, on new and upgraded installs) brings back the 2.3 behaviour.

This page is the technical reference. For a task-oriented walk-through see the
[admin guide](autofill-admin-guide.md) (settings, test buttons, upgrade notes) and the
[user guide](user-guide.md#suggestions-from-a-photo-ai-autofill) (the Analyze button, the progress bar, what gets filled).

## Setting it up

Control Panel → **AI autofill** (`/settings/autofill`, needs the *Settings* permission – admins only by default):

| Setting | Default | Notes |
|---|---|---|
| Suggest details from the photo | off | Master switch. |
| Analyze photos automatically | off | **New in 2.4.** Off: the item form shows an *Analyze* button per photo and nothing is sent until someone taps it. On: a photo picked on the form is analyzed right away (the 2.3 behaviour); the buttons stay for another try. Migration 0025 writes *off* on every install, new and existing. |
| Service address | empty | Your Worker URL, without `/v1`, for example `https://autofill.example`. Deploy steps: [Worker deploy doc](https://github.com/thordin9/apotheke-autofill-service/blob/main/docs/cloudflare-workers-deploy.md). Only `http://` and `https://`. |
| Timeout (seconds) | 50 | 3–60. Set it about 5 s above the service's own timeout: 50 for the default Jetson setup, where the service gives up after 45 s (`BACKEND_TIMEOUT_S`). A `Retry-After` is honoured only when it is at most 60 s and no longer than this timeout. Upgrading from 2.2 moves a stored 30 (the old default) to 50 (migration 0024); any other value is kept. |
| Model | service default | **New in 2.4.** A dropdown of the service's `models` from `/v1/info` (API v1.2): *Service default (…)* or one model, with *loaded* and its typical time when the service says so. *loaded* is what the service reports; Apotheke does not rely on it, and any photo can still be a cold start, so the progress bar and its hint always allow for the full timeout. Sent as `context.model`. Hidden when the service lists no models; then no model is sent. With exactly **one** model (`ALLOWED_MODELS` unset on the service) it is shown with that model, disabled, with the hint *More models appear when ALLOWED_MODELS is set on the service.*; two or more models make it selectable. |
| Image size | recommended | **New in 2.4.** A dropdown of the service's `image_sizes` (long edge in px): *Recommended (N px)* or one size. The suggestion copy is resized to it and never enlarged. The stored upload is the file you picked, unless that file is over the upload limit. Hidden when the service lists no sizes; then the suggestion copy goes at 1024 px as before (2.3 already resized every photo to 1024 px, so an older service gets what it got before). The Jetson runs with `IMAGE_SIZES=448,560*,1024`, so *Recommended* is 560 px there. |
| Maximum image size (bytes) | `auto` | **New in 2.6.** `autofill_max_image_bytes`. `auto` uses `max_image_bytes` from `GET /v1/info`, or 983040 when that answer is unavailable. An explicit value is a whole number of bytes from 65536 to 10485760; anything else is rejected with that range in the message. There is no environment override. `enforced_image_bytes` in `/v1/info`, when smaller, clamps the copy. The stored upload is not resized. |
| Access token | none | Only if the service runs with `AUTOFILL_TOKEN`. See below. |

All values live in the `settings` table like every other Control Panel option (`autofill_auto`, `autofill_model`
— `''` = the service's default —, `autofill_image_size` — `0` = the recommended size,
`autofill_max_image_bytes` — `auto` or a byte count). Model, image size and the byte cap are
exported and imported with the other settings. Migration **0026** inserts `auto` when the row is missing and does
not replace a value an admin already saved.

### Model and image size (API v1.2)

The dropdowns are filled from the saved service's **last known** `/v1/info` (the cache below, however old, as long
as it belongs to the saved address), or from the `/v1/info` a **Test** just fetched, also for an address that is not
saved yet. While the service does not offer the lists, the dropdowns are not shown and a hidden field keeps the stored
choice, so saving the section never loses it. A stored choice that the service no longer lists stays selectable,
marked *not offered by the service*.

What is sent:

| Service lists… | Model sent (`context.model`) | Long edge of the image |
|---|---|---|
| nothing (API v1.0/v1.1) | none, whatever is stored | 1024 px, whatever is stored (unchanged from 2.3) |
| `models` | the chosen model; none for *Service default* | – |
| `image_sizes` | – | the chosen size; for *Recommended* the size marked `recommended`, else the largest size up to 1024 px, else the smallest |

The service decides what an unknown model means: per contract v1.2 it falls back to its default and says so in
`model_used`. Switching models is a **cold start** on the service (it loads the other model): it may answer `503`
with `Retry-After`, which Apotheke handles like any warming-up model (wait, one retry; see below).

The progress estimate uses the chosen model's own `avg_latency_ms` from `models` (the default model's for *Service
default*), falling back to the service-wide `avg_latency_ms`.

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
| `enforced_image_bytes` | **optional, new in Apotheke 2.6** | a positive integer. When it is smaller than the configured cap, the JPEG is clamped to it and a warning is logged (the two sizes only, never the token). A wrong shape is ignored and does not reject `/v1/info` |
| `ready` | optional (default `true`) | *still warming up* |
| `avg_latency_ms` | **optional, new in Apotheke 2.3** | the progress estimate on the item form (*Typical answer* in Test connection). A non-negative integer: the service's recent average time per suggestion in milliseconds. Anything else, or a service that does not send it, is ignored: the form then shows a spinner instead of a percentage. |
| `models` | **optional, API v1.2, new in Apotheke 2.4** | the Model dropdown and per-model estimates. A list of `{id, default?, loaded?, avg_latency_ms?}`: `id` a non-empty string (≤ 120 characters), `default`/`loaded` booleans, `avg_latency_ms` as above. Entries of the wrong shape are skipped, duplicates merged, at most one default (the first marked; none marked: the first listed), at most 50. A list with nothing usable counts as absent. |
| `image_sizes` | **optional, API v1.2, new in Apotheke 2.4** | the Image size dropdown. A list of `{long_edge, recommended?}`, `long_edge` an integer 64–4096. Wrong entries are skipped; sorted, duplicates merged, at most one recommended. |

A `/v1/suggest` answer may carry `model_used` (API v1.2, a string ≤ 120 characters): Apotheke passes it to the
browser (`data-af-model-used`, see *Debug hooks*) and shows it as **Model used** in Test with a photo.

### Test with a photo

**Test with a photo** (next to Test connection, same rules for the address, timeout and token) runs the same
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
- Saving a **different service address** (same comparison as above) clears the stored token in the same
  save, and the page says so: enter the token again for the new service. A token submitted with that same save
  (`autofill_token`) is kept instead. Resetting the section to another address clears it too.

All forms on the page need the CSRF token; viewers and editors get `403`.

## Suggestions from a photo

With autofill on **and** a service address set, the item form (new and edit; needs the *Add items*
permission) gets **Analyze** buttons, a status line and a progress bar under the photo. Nothing is shown otherwise.

### Analyze buttons (on demand, new in 2.4)

- **The photo you just picked** gets *Analyze photo* as soon as it is on the form (take a photo or pick one from the
  library). Picking another photo replaces it; an analysis still working on the replaced photo is dropped.
- **On the edit form**, every photo the item already has gets its own *Analyze* button (a thumbnail row
  *Suggest details from a photo of this item*). These are sent by id (`photo_id`); the server reads the stored file.
- **One analysis at a time.** While one runs, its button is marked busy (`aria-busy`) and every Analyze button is
  `aria-disabled`: they stay focusable (keyboard focus is not dropped) and a click or keypress does nothing until the
  run ends (answer, failure or a dropped analysis). Picking a new photo while a stored photo is being analyzed does
  not interrupt it.
- With **Analyze photos automatically** on, a picked photo starts its analysis at once (as in 2.3), unless another
  analysis is running.
- The buttons need JavaScript; without it the form works as always, with no suggestions.

### What is sent

1. **Analyze** resizes the picked photo in the browser to the chosen **long edge** (`data-af-side` on the form: the
   Image size, or 1024 px for a service without `image_sizes`). It is **never enlarged**. The browser's decoder
   applies the EXIF orientation, so a phone photo stored sideways is sent upright. It is a JPEG (on white), or a
   **PNG when the photo has transparency** (a PNG/WebP/GIF whose pixels are not all opaque), so the alpha channel
   survives. A small JPEG that would only grow by re-encoding is sent as it is. That copy is posted to Apotheke's
   `POST /autofill/suggest` (same-origin, CSRF token). It is the suggestion request only. Saving the item uploads
   the picked file unchanged when it fits *Photos & uploads → Maximum upload* (default 20 MB). Over that limit the
   browser saves a smaller JPEG and the form says the original is not kept. The byte cap for the suggestion is
   **Maximum image size**: `auto` uses a cached `max_image_bytes`, or 983040 bytes (0.9 MiB) when `/v1/info` has
   not been seen. An explicit value is used as stored. `enforced_image_bytes`, when smaller, clamps that cap.
2. The server does not send that upload on. It builds a **separate** JPEG from the camera file when one exists,
   otherwise from the served file: EXIF orientation first, long side at most `min(chosen size, 1600)`, never
   enlarged, quality about 80 (then 70, then 60). If the multipart body is still over the byte cap, the long edge
   is reduced and those qualities are tried again, until the body fits. If it cannot, nothing is sent.
   Metadata is stripped. GD is used first, Imagick if GD cannot read the file. Imagick flattens transparency onto white and
   converts CMYK to sRGB, then strips colour profiles, so that JPEG matches what GD would have written. For a
   grayscale photo, Imagick writes a 1-channel JPEG, so that match is approximate. GD's encoder
   is left as it is. That analysis JPEG forces 4:2:0 chroma subsampling (`jpeg:sampling-factor` `2x2,1x1,1x1`), so a
   4:4:4 source is not copied through. The JPEG the catalog shows does not get this override.
   The temp file is `DATA_DIR/tmp/autofill-*.jpg`
   and is deleted when the call ends, including on errors. The stored photo is not rewritten. Only that stripped
   JPEG leaves Apotheke. If it cannot be built, nothing is sent.
3. It calls the service's `POST /v1/suggest` with the image, the bearer token and a `context`: the names of
   your existing **categories** and **tags** (so the backend reuses your spelling), `locale`, and `model` when a
   model is chosen and the service lists models.

**HEIC/HEIF.** Saving a photo uploads the file untouched when it fits the upload limit. The browser shrinks it only
when it is over that limit, and the form says the original is not kept. The server keeps those bytes and, when it
can read them, builds two JPEGs: the stripped copy the catalog shows, and a separate analysis copy (long side at
most 1600 px, metadata removed, 4:2:0 only when Imagick builds that analysis copy). HEIC is read with Imagick
when `Imagick::queryFormats()` includes `HEIC` or `HEIF`.
The Docker image is built that way (`libheif`). A GD-only install keeps the HEIC and shows a grey placeholder;
autofill is skipped and the HEIC is not sent. Where the browser can decode HEIC it may still make a smaller copy
for the Analyze request only. That copy is not what is stored.
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

**Warming up or busy.** When the service answers `503` with `error.code` `backend_unavailable` and a
`Retry-After` header (the model is loading, or its queue is full), and that wait is **at most 60 seconds**,
Apotheke passes `retry_after` (seconds) and
`retry_reason` on to the browser. The reason comes from the service's `error.message`: *loading* or *warming
up* means a cold model (`warming`), anything else is `busy`. The browser shows **Model is warming up…
retrying in N s** or **Service is busy… retrying in N s** with a countdown and then tries **once** more, resending
the same analysis JPEG, not the camera file. Saving the item stays available during the wait. It does not retry when you picked another photo meanwhile, when `Retry-After` is longer than the
timeout, or when `Retry-After` is over 60 seconds (a daily cap that counts down to 00:00 UTC). That last case is
*the service is busy or not ready*, with no countdown, so the item can be saved at once. When the one short retry
fails too, the usual message is shown.

**Only empty fields are filled, never one you have touched, and never one an earlier analysis filled.** Typing in a field (even if you then clear
it), picking from a list (even the same value again) or removing a tag chip makes it yours. On a **new** item a
preselected default counts as empty while you have not touched it: the default category (e.g. *General ★*),
the default tags and the default notes from Control Panel → Items. A category chosen by the link you came from
(`/new?cat=…`) and everything on an existing item's edit form are your choices and are never replaced. Filled fields are highlighted until you edit them. **A second
analysis** (another photo, or the same one again) only fills fields that are **still** empty or hold an untouched
default: what an earlier analysis filled in stays, as does your input (changed in 2.4: before, a new photo could
replace earlier suggestions). A field you clear yourself counts as yours and stays empty; to start over with fresh
suggestions, reload the form. The category is filled only when none is selected
(or only the untouched default): an existing one is selected when the names match (ignoring case). A name that
is not in your list pre-fills "＋ New category" only when **no** category is selected; an untouched default
such as *General ★* is kept instead, so saving never creates a category you did not ask for. Suggestions go to Name, Category, Tags and Notes (the service's `description`).

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
| Photo too large (`413`, or the analysis copy cannot be made to fit the byte cap) | *the photo is too large (max N MiB / N bytes)* — the cap after `auto` / the explicit size and `enforced_image_bytes`. An HTTP 413 refetches `GET /v1/info` once, clamps again, and uploads once more. A second 413 stops. The token is not logged. 0.9 MiB / 983040 bytes is the `auto` cap when `/v1/info` has not been seen |
| Image type not supported (`415`) | *cannot read this kind of image* |
| Not an image, or a photo that cannot be turned into a stripped JPEG (no GD/Imagick, HEIC without libheif, broken file) | *cannot be read as a photo* – checked locally, nothing is sent |
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

The test stub also logs every upload (type, bytes, width, height, `alpha` for PNG, `context`) and serves the last 50
at `GET /_stub/uploads` (see *Tests*).

## Upgrading from 2.3

- **Analysis is now on demand.** Migration **0025** sets *Analyze photos automatically* to **off** on every install,
  including installs that had autofill on: after the upgrade nothing is sent when a photo is picked. Each photo on the
  item form has an **Analyze** button instead.
- **To get the 2.3 behaviour back**, an admin turns on Control Panel → AI autofill → **Analyze photos
  automatically** and saves. Picked photos are then analyzed right away again; the Analyze buttons stay.
- Model and image size start at *Service default* and *Recommended*. With a service older than API v1.2 nothing
  changes: no model is sent and photos go at 1024 px.
- A second analysis no longer replaces what an earlier one filled in (see above).
- No other setting changes; no schema change.

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

CI does not need the Python service. `tests/stubs/autofill-stub.php` mimics the echo backend and is
started on a free port by the unit tests, the HTTP integration suite and Playwright
(`tests/visual/autofill-stub.ts`). The mode and token are picked by a path prefix of the address, e.g.
`http://127.0.0.1:PORT/m/slow/t/s3cret`: modes `ok`, `error` (503), `malformed`, `slow`, `toolarge` (413),
`unsupported` (415), `empty`, `badinfo`, `warming`, `fault` (503 `backend_error`), `busy` (503 + `Retry-After`, "queue
full", always), `warmup` (the same, "is warming up") and `cold` (503 "is loading" + `Retry-After`
on every other suggestion). More prefixes before `/t/`: `/r/<s>` (the `Retry-After` value), `/l/<ms>`
(`avg_latency_ms` in `/v1/info`), `/d/<ms>` (the suggestion waits that long) and `/s/<name>` (the suggested
category).

API v1.2 additions (new in 2.4):

| Prefix / mode | Effect |
|---|---|
| `/n/<models>` | `/v1/info` lists these `models`, comma-separated (URL-encode ids with `:`); `*` after an id marks the default (else the first), `=<ms>` its `avg_latency_ms`. Example: `/n/qwen2.5vl-jetson%3A3b*=14000,qwen3-vl-2b=6000`. `/v1/suggest` answers with `model_used`: `context.model` when listed, otherwise the default (an unknown model falls back). |
| `/z/<sizes>` | `/v1/info` lists these `image_sizes`; `*` marks the recommended one. Example: `/z/448,672*,1024`. |
| `/m/switch` (with `/n/`) | the stub remembers the *loaded* model (the default at first; `/v1/info` marks it `loaded`). A suggestion for another model answers `503 backend_unavailable` "model '…' is loading" + `Retry-After` (`/r/`) once and loads it, so Apotheke's one retry gets the answer: a model switch is a cold start. |
| `GET /_stub/uploads` | the last 50 `/v1/suggest` requests (no token needed): `type`, `bytes`, `width`, `height`, `alpha` (PNG with transparency), `context`, `mode`, `filename`, `mime`. `DELETE /_stub/uploads` clears the log and the loaded model. With `STUB_LOG=<file>` the log is `<file>.jsonl`, otherwise `af-stub-<port>.jsonl` in the temp dir. |

A full v1.2 address for manual QA: `http://127.0.0.1:8090/m/switch/r/3/l/4000/n/qwen2.5vl-jetson%3A3b*=4000,qwen3-vl-2b=2500/z/448,672*,1024`.

- `tests/Unit/AutofillTest.php`: settings, `/v1/info` and suggestion parsing (`avg_latency_ms`, `Retry-After`,
  confidence), image preparation, every failure code, Test with a photo, migration 0024.
- `tests/integration_autofill.php` (part of `tests/integration.php`): settings security (CSRF, viewers and
  editors, token never in pages, exports, backups or logs), Test connection, suggest end to end.
- `tests/visual/autofill-settings.spec.ts` and `autofill-suggest.spec.ts`: the Control Panel section and
  the form on every device, with visual baselines (`autofill-suggest` runs with auto-analyze on, the 2.3 path).
- `tests/visual/autofill-on-demand.spec.ts` (2.4): nothing sent on upload, the Analyze button per photo (picked and
  stored), one analysis at a time, a second analysis only filling empty fields, auto-analyze as an option, the
  upgrade (migration 0025) not analyzing on upload, the Model / Image size dropdowns shown and hidden, the chosen model
  sent, a model switch as a cold start, and the resize (EXIF orientation, no upscaling, PNG alpha, the size limit
  after resizing, HEIC).
- `tests/Unit/AutofillTest.php` (2.4 additions): the new settings and their validation, migration 0025, parsing
  `models` / `image_sizes`, which model and size are sent, per-model estimates, a model switch, an older service
  unchanged, image preparation at a chosen size (orientation, transparency), Test with a photo with a model and size,
  the dropdowns shown and hidden. `tests/integration_autofill.php` covers the same end to end over HTTP, plus stored
  photos (`photo_id`).
