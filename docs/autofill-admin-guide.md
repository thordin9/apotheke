# AI autofill: admin guide

This guide is for whoever runs Apotheke and its [autofill service](https://github.com/thordin9/apotheke-autofill-service).
It covers the settings under **Control Panel → AI autofill** (`/settings/autofill`), the two test buttons and what
changed in Apotheke 2.4. The technical reference (every field, message and limit) is [AI autofill](autofill.md); what
people see on the item form is in the [user guide](user-guide.md#suggestions-from-a-photo-ai-autofill).

You need the *Settings* permission (admins by default).

## 1. Connect the service

1. Switch on **Suggest details from the photo**.
2. **Service address**: where the service runs, without `/v1`. This is your Worker URL, for example
   `https://autofill.example`. Deploy steps are in the service repository's
   [Worker deploy doc](https://github.com/thordin9/apotheke-autofill-service/blob/main/docs/cloudflare-workers-deploy.md).
   The older Jetson/GPU build speaks the same API and still works if you host it.
   If Apotheke runs in Docker, `localhost` is the container itself: use an address the container can reach.
   The access token is the service's `AUTOFILL_TOKEN` (Control Panel → AI autofill → Access token). Never put a
   real token in a file you commit.
3. **Timeout (seconds)**: about 5 s more than the service's own timeout. The default 50 fits the shipped Jetson
   setup (the service gives up after 45 s).
4. Press **Test connection** (nothing is saved). *Connected* lists the service's name, API version, what it
   suggests, its image limit, its typical answer time and, for a service with API v1.2, its **models** and **image
   sizes**.
5. If the service runs with `AUTOFILL_TOKEN`, enter it under **Access token** and press **Save token**. The token is
   write-only: it is never shown again, exported or backed up. Set `APP_KEY` first so it is encrypted at rest
   ([Stored secrets](configuration.md#stored-secrets)). Without a key the page warns you and the token stays plaintext.
6. Press **Save**. Apotheke fetches the service's details once (at most 5 s), so the first photo already gets a
   progress estimate.

## 2. Analyze photos automatically (off by default)

| Setting | What happens on the item form |
|---|---|
| **Off** (default, recommended) | Nothing is sent when someone takes or picks a photo. Each photo gets an **Analyze** button; the photo goes to the service only when someone taps it. |
| **On** | Every photo picked on the item form is sent to the service right away (the Apotheke 2.3 behaviour). The Analyze buttons stay, for a second try. |

Off is the default on **every** install, new and upgraded: photos are only sent when someone asks for it, and the
service is not kept busy with photos nobody needed suggestions for.

## 3. Model and image size

Both dropdowns appear only when the service offers a choice (API v1.2: `models` and `image_sizes` in its
`/v1/info`). They are filled from the last answer of the saved service, or from a **Test** you just ran.

| The service's `/v1/info` | Model dropdown | Image size dropdown |
|---|---|---|
| no `models` / no `image_sizes` (older service) | hidden; no model is requested | hidden; photos go at **1024 px**, as in 2.3 |
| `models` with **one** entry (`ALLOWED_MODELS` unset on the service) | shown with that model, **disabled**, with the hint *More models appear when ALLOWED_MODELS is set on the service.* | – |
| `models` with two or more entries | enabled | – |
| `image_sizes` listed | – | enabled; *Recommended* preselected |

**Why 1024 px for an older service:** 2.3 already resized every photo to a 1024 px long edge, so an older service
gets that size when it lists no `image_sizes` (never enlarged). A v1.2 service decides through its recommended size
instead. Either way the copy that leaves Apotheke is capped at 1600 px (`AUTOFILL_UPLOAD_MAX_SIDE`) and is a JPEG
at about quality 80 (`AUTOFILL_UPLOAD_JPEG_QUALITY`), with EXIF (including GPS) removed. If that JPEG is
still larger than the byte cap, quality steps down by 10 (60 is always tried), then the long edge shortens, until the JPEG fits.
The service limits that JPEG, so the multipart wrapper (about 320 bytes) is not counted.
The cap is **Maximum image size** (below). Until `/v1/info` has been cached and the setting is `auto`, that cap is
983040 bytes (0.9 MiB). The original upload is never sent.

**The Jetson setup** (from the service benchmarks): the service runs with `IMAGE_SIZES=448,560*,1024` and
`ALLOWED_MODELS` unset, so Image size shows *Recommended (560 px)*, 448, 560 and 1024 px. The Model dropdown shows
the one installed model, disabled, as long as the service lists it in `models` (the dropdown stays hidden if the
service sends no `models` at all).

- **Model**: *Service default (name)* lets the service decide (recommended until you have compared models), or pick
  one of the installed models it allows. Each entry shows *loaded* and its typical time when the service reports them.
  *loaded* is what the service reports; Apotheke does not rely on it, and any photo can still be a cold start, so the
  progress bar and its hint always allow for the full timeout. A cold start (including a model switch) makes the form
  say *Model is warming up… retrying in N s* and retries once. A model the service does not know falls back to its
  default; Test with a photo then shows *Model used: X (asked for Y)*.
- **Image size**: *Recommended (N px)* uses the size the service recommends, or pick a long edge. Smaller images are
  analyzed faster; larger ones can read small print better. That size applies to the suggestion copy (built on the
  server, and also prepared in the browser for Analyze). It is **never enlarged**. The file stored with the item is
  the upload itself.

The progress bar on the item form estimates with the chosen model's own typical time.

## 4. Maximum image size

**Maximum image size (bytes)** is on the same page, under the image-size dropdown. It is stored as
`autofill_max_image_bytes`. The default is `auto`. There is no environment variable and no `config.php` key for it.

| Value | What is sent |
|---|---|
| `auto` (default) | `max_image_bytes` from the service’s last `GET /v1/info`. When that answer has not been seen, 983040 bytes. |
| a whole number | That many bytes, from 65536 to 10485760. An empty value is saved as `auto`. Capped to `enforced_image_bytes` when `/v1/info` includes it, otherwise to `max_image_bytes` when that field is present, and used as stored only when neither is known. Anything outside that range is rejected when you save, with the allowed range in the message. |

This is a hard maximum for the JPEG that leaves Apotheke. The app lowers JPEG quality by 10 from the configured
quality (60 is always tried) and then shortens the long edge until that JPEG fits. The multipart wrapper is not
counted. If it still does not fit, Analyze says the photo is too large and nothing is sent. The file stored with
the item is not changed.

A lower service cap is logged as a warning with the two sizes, once until that pair of sizes changes. The access token is not in that line.

If the service answers **HTTP 413**, Apotheke fetches `/v1/info` once, applies the clamp again, and uploads once
more. It does not keep retrying. A second 413 is the same “too large” message, using the limit from that refetch.
A limit that would display as 0 MiB is shown in KB (1000 bytes is 1 KB).

Defaults: until you choose, Apotheke uses the service's default model and recommended size. Which model and size suit
your hardware best is measured in the service's benchmarks (`docs/benchmarks.md` in the service repository); set the
service's defaults from there, and leave these two on *Service default* / *Recommended* unless you want something
else for this Apotheke.

## 5. The test buttons

Both use the values **as currently typed** (address, timeout, model, image size) and save nothing, so you can try a
model or a new address before saving.

- **Test connection**: is the service reachable, is it the autofill service, does it accept the token, is its model
  ready. Shows its details (see step 1).
- **Test with a photo**: the same checks, then a bundled sample photo (a claw hammer) through the full suggestion
  step, with your real categories and tags. Shows three checks (*Reachable*, *Token accepted*, *Model ready*), the
  suggested name, category, tags and description with their confidence, **Model used**, the **Image size** sent and
  how long it took. It can take as long as the timeout, e.g. on the first photo after a model switch: if the model is
  warming up and the service's Retry-After is at most 60 seconds and no longer than the timeout, the test waits and
  tries once more, the same single retry as Analyze. A longer Retry-After is not waited out.

Typical results and what to do:

| Result | Fix |
|---|---|
| *Could not connect* | Wrong address or port, the service is not running, or a firewall is in the way. |
| *No answer within N seconds* | Test connection: the service is overloaded, or the timeout is too short. |
| *autofill is unavailable right now* | Test with a photo: the suggestion step timed out, the network failed, or the answer was not the API. Analyze shows the same message and does not retry. |
| *The service requires a token* / *refused the token* | Save the token the service expects, or clear it. |
| *Still warming up* / *Model not ready* | The service is loading its model; try again in a minute. |
| *Model used: X (asked for Y)* | Model Y is not allowed or not installed on the service; pick another or ask whoever runs the service. |

## 6. Upgrading from 2.3

- After the upgrade (migration 0025), **Analyze photos automatically is off**, even if autofill was on before:
  people tap **Analyze** on a photo to get suggestions.
- **To turn auto-analyze back on**: Control Panel → AI autofill → switch on **Analyze photos automatically** →
  **Save**.
- Model and Image size start at *Service default* / *Recommended*. Once the service is updated to API v1.2, press
  **Test connection** (or Save) to see the dropdowns.
- A second analysis of an item (another photo) no longer replaces what an earlier one filled in; it only fills
  fields that are still empty.

## Camera files and what autofill reads

New uploads are stored twice. `images/` and `thumbs/` are the stripped JPEGs the app shows. `originals/` (or
`ORIGINALS_DIR`, default `<data_dir>/originals`, outside `public/`) keeps the upload byte for byte under a 64-hex
name. The only pointer is a file in `originals/map/`, which is not a URL. `/originals/…` is 404 in any spelling
(`/Originals`, `/ORIGINALS`) in the app, in `public/.htaccess`, and in the Docker nginx config
(`location ~* ^/originals(/|$)`). The directory is created mode `0750` and the camera files mode `0640` when the
filesystem allows it. If `ORIGINALS_DIR` resolves inside `public/`, Apotheke refuses to start.

A picked file that fits **Maximum upload** (Control Panel → Photos & uploads, default 20 MB) is that camera file.
A larger file is reduced in the browser before it is sent, and the form shows *This photo is over the upload limit,
so a smaller copy will be saved. The original file is not kept.* To keep typical camera files, raise the three
limits together: `uploads.max_mb` (at most 200), PHP `upload_max_filesize` and `post_max_size` (`docker/php.ini`
ships `20M` and `22M`), and the web server body limit (Docker nginx `client_max_body_size 22m`). `post_max_size`
and `client_max_body_size` need to sit a little above `upload_max_filesize`. A backup zip is larger than a photo:
the Docker image allows 520 MB only on `/backup/restore`; other installs stay at about 22 MB and answer 413. For a
large zip use `php bin/restore.php`.

The frame has its own limit, and it depends on the format. A JPEG of at most 250 megapixels, and at most
30,000 px on a side, is accepted. It is decoded already reduced, through Imagick with the JPEG format named
and a size hint, so the decoded frame stays within 128 megapixels. That keeps a 48 MP phone JPEG and a 200 MP
sensor JPEG (16384×12288). The camera file in `originals/` stays byte-identical, and the copy on screen is
still reduced to the full-size long edge. A larger JPEG is refused from the header, before it is decoded.
HEIC and HEIF are not decoded already reduced, so a HEIC photo above 128 megapixels, or above 16,384 px on a
side, is refused from the header with the same message, before any pixels are read. A 48 MP HEIC is still
accepted. A PNG above 26 megapixels is refused before it is decoded. WebP and GIF photos above 40 megapixels
are refused, because those formats are decoded in full. On the Docker image (`memory_limit` 256M), an alpha PNG of
about 32 megapixels exhausts memory while GD decodes it, and a 40 megapixel PNG does too. A 26 megapixel alpha PNG,
with the 20 MB upload still held and then fitted to a JPEG, peaked at 231931904 bytes on that image's GD, about
35 MB under the limit. The same image still decodes a 40 megapixel WebP or GIF (peak 195035136 bytes). Photos
within those limits go through GD. HEIC/HEIF uses Imagick with the format named explicitly.
Imagick's limits are set when the app starts, to the same bounds as the policy the Docker image ships
([`docker/imagemagick-policy.xml`](../docker/imagemagick-policy.xml), installed as
`/etc/ImageMagick-7/policy.xml`): memory 256 MiB, map
256 MiB, disk 1 GiB, area 128 megapixels, width and height 16,384 px. A frame those limits refuse — including
a tighter limit configured on the server, or a HEIC frame the decoder will not allocate — is declined with the
same message as an oversized photo. Suggest uses that message too, including when the refusal happens at decode
rather than from the header. It is not decoded, and the camera file is not kept. If decoding dies after the
camera file was saved, that file is removed before the request ends.

Autofill builds a temporary JPEG from that camera file (or from the served JPEG if the photo predates this version),
sends only that, and deletes the temp file even when the request fails. When Imagick encodes that JPEG it uses
4:2:0 chroma subsampling, so a 4:4:4 source no longer inflates the analysis copy. It also flattens transparent
pixels onto white and converts a CMYK photo to sRGB, then strips colour profiles, the same way GD writes a JPEG.
For a grayscale photo, Imagick writes a 1-channel JPEG, so that match is approximate.
GD's encoder is unchanged, and the JPEG the catalog shows does not get the 4:2:0 override. HEIC needs Imagick with
libheif. The Docker
image includes that. Without it, or if the conversion fails, autofill is skipped with *cannot be read as a photo*,
the camera file stays, and a grey placeholder is what the page shows. The HEIC itself is never served or sent.

An HTML error page (including a Cloudflare 1102), a 200 that is not JSON, truncated JSON, a JSON 5xx that is not the
API's own error object, and a timeout or network error on suggest are all *autofill is unavailable right now*. The
item saves with no suggestions and Apotheke does not retry. A well-formed API 503 (`backend_unavailable`) is retried
once only when `Retry-After` is 60 seconds or less and no longer than the timeout. The retry sends the same
analysis JPEG again, never the camera file, and saving the item is not blocked while it waits. A longer
`Retry-After` (a daily cap that counts down to 00:00 UTC) is *busy*, with no retry, so the item can be saved at
once. Any other JSON 5xx is unavailable and is a single request.

**Upgrade.** Photos already on disk are not migrated. They keep working and have no camera file. New uploads get
one. Backups from this version include `originals/`; older backups still restore. Deleting or replacing a photo
removes the served JPEG, the thumbnail and the camera file.

Disk use is about the size of each new camera file, on top of the smaller stripped JPEG and thumbnail.

## Security notes

- Photos go from the browser to Apotheke, and from Apotheke's server to the service; the browser never talks to the
  service and never sees the address or the token.
- Test connection can make this server contact any http(s) host an admin types in. Grant the *Settings* permission
  only to people you trust with that.
- Problems are logged as one short line (`apotheke autofill: …`), never with the token or the service's answer.
