# Apotheke PR #28: stripped analysis JPEG + kept originals (option b), end-to-end QA

- PR: thordin9/apotheke#28 (draft), "feat(autofill): send a stripped JPEG and keep originals"
- Head tested: **`8e55559086e4c55ba35bcac894ea031791587860`**. The app code is identical to `dc8cdd2a4217a608d57e249cd57f7b394e5113b6`,
  where the local runs were made; see §0.
- Earlier heads: `023adf9` → `d0cc4b4` (failed, H-1) → `dc8cdd2` → `8e55559`
- Base / baseline: `main` at `56baf17f7f7561a31a97a0939f3b6a32991c2292`
- Date: 2026-10-20 (Europe/Athens)
- Scope: a local pass against a QA stub (§2), plus one small live pass against the production Worker (§3). The live pass
  used a throwaway install. The token was stored only in that install's database, and it was cleared afterwards.

## 0. Head changes during QA

| Range | Files | Effect on QA |
|---|---|---|
| `023adf9..d0cc4b4` | admin guide row, `tests/visual/autofill-settings.spec.ts` | Timeout path and *Test with a photo* re-run |
| `d0cc4b4..dc8cdd2` | `public/assets/app.js` (upload the picked file untouched; shrink only above max_upload, with a note; Retry-After cap 60 s), `src/autofill.php` (Retry-After > 60 s → *busy*, no retry), `src/bootstrap.php` / items (refuse an `ORIGINALS_DIR` inside the web root; 0750/0640; Apache-2.4-safe `.htaccess`), backup chmod, views (`MAX_FILE_SIZE`), `docker/nginx.conf` (`~*`), docs, tests (new `originals.spec.ts`) | Everything re-run on `dc8cdd2` |
| `dc8cdd2..8e55559` | `tests/e2e/mobile.mjs` only: the gallery fixture is a valid PNG | Test-only. No app, upload, autofill or photo-serving code changed, so the `dc8cdd2` results stand. The old fixture had a bad IDAT CRC and no IEND chunk. GD rejects it (422 "Could not read that image"), and it had only passed while the browser re-encoded every photo. I reproduced both the failure and the fix locally. |

## 1. Test plan

### 1.1 Environment

| Item | Setup |
|---|---|
| Sources | codeload tarballs of the PR head and `main` (`56baf17`), in a throwaway QA directory. The real install was not touched. |
| PHP | 8.4 CLI, built-in server (`php -S … -t public public/index.php`), SQLite, `DATA_DIR` per install, `APOTHEKE_CONFIG=/nonexistent` |
| Image engines | Run twice: **GD + Imagick (libheif)**, and **GD only** (Imagick ini removed via `PHP_INI_SCAN_DIR`) |
| Stub | Own Python stub, API v1. It records every request's raw body, headers and count. Modes: `ok`, `html1102` (HTML 503 "Error 1102"), `nonjson` (200 text/plain), `truncated` (200 JSON cut off), `json5xx` (500 JSON), `fault503` (JSON 503 service fault), `hang` (120 s), `busy<N>` (JSON 503 `backend_unavailable` + Retry-After N), `info_small` / `info_1m` (`max_image_bytes` variants) |
| Browser | Headless Chromium (Playwright 1.64), JS on: item form (`/new`, library input), photo manager (`#photoadd` on the item page), edit form *Analyze* on a stored photo |
| Web servers | PHP built-in server; nginx 1.26 with the shipped `docker/nginx.conf`; Apache 2.4 **without** `mod_access_compat`, with the shipped `public/.htaccess` and the app-written `originals/.htaccess` |
| Inspection | exiftool (all groups), ImageMagick `identify` (format, size, JPEG quality estimate), sha256 everywhere |
| Live pass | Separate throwaway install of the head, Imagick + libheif. A local recording pass-through proxy sat between the app and the production Worker. It recorded method, path, status, latency, body size and body, but no headers. |

### 1.2 Photo set

| File | Purpose |
|---|---|
| `large_gps_o6.jpg` | 4032×3024 JPEG, 3,783,650 B, EXIF Orientation 6, GPS, Make/Model, SerialNumber, LensModel |
| `small_gps.jpg` | 800×600 JPEG, 170,087 B, GPS/Make |
| `png_alpha_small.png` | 1200×900 PNG with alpha + XMP GPS/Make |
| `photo.heic` | 3000×2000 HEIC (x265), 2,677,261 B, EXIF GPS/Make |
| `wide_strip.jpg`, `huge_63mp.jpg`, `noise.jpg` | 12000×400; 9000×7000 (over the 60 MP guard); 1600×1600 noise (does not fit at any quality step) |
| `stepdown.jpg` | 1300×1300 noise + GPS. At GD q80/q70/q60 it is 894,597 / 704,489 / 571,896 B, so it lands on each quality step |
| `corrupt.jpg`, `garbage.jpg`, `garbage.heic` | truncated JPEG + random data; 19 bytes of text; HEIC header + random data |

### 1.3 Acceptance checks

1. The stored original is byte-identical to the picked file (sha256, format, dimensions) for JPEG, PNG and HEIC, both
   without JS and in a real browser (item form and photo manager). Above max_upload, the browser shrinks the photo and
   shows the "original is not kept" note. Below it, the file is never shrunk.
2. A stripped served copy sits next to it, and everything that leaves the app is stripped.
3. Originals are not web-reachable (built-in server, nginx, Apache 2.4). An `ORIGINALS_DIR` inside the web root is
   refused (L-1). Permissions are 0750/0640 (N-5).
4. The analysis copy is EXIF-rotated, at most 1600 px, never upscaled, a stripped ~q80 JPEG, with quality step-down.
   The whole body is at most the service limit (fallback 983040). The temp file is deleted.
5. On a resize failure nothing is sent, and the original is never sent.
6. Five bad answers each give "unavailable" with exactly one request, and the item still saves. Retry-After: 30 s gives
   at most one retry with only the analysis copy; 5400 s gives no retry, *busy*, and the item still saves. A JSON 5xx
   is not retried.
7. Backup/restore/delete/replace leave no orphans.
8. Upgrade in place from main. Docs cover L-3 (restore limits) and N-3 (disk usage). No production hostname appears in
   the repo or the PR body (I-2).
9. Integration suite, CI, and gitleaks on the diff.
10. Live: `/v1/info`, status and latency, suggestions, exact analysis upload size, original byte-identical,
    *Test connection*, *Test with a photo*, and Neurons used before and after. Not tested live: 401, 413 and the
    spending cap.

## 2. Results (local, stub)

### Check 1: originals byte-identical (PASS)

- No-JS posts, 6 uploads per engine (JPEG 4032×3024, JPEG 800×600, PNG 1200×900, HEIC 3000×2000, 12000×400,
  1600×1600): the original is byte-identical every time. HEIC is kept with and without Imagick.
- **JS on (fixes H-1).** In both the item form and the photo manager, `large_gps_o6.jpg`, `small_gps.jpg`,
  `png_alpha_small.png` and `photo.heic` were stored with the same sha256 as the picked file. The extension was kept
  (`.jpg`, `.png`, `.heic`), GPS/Make were still in the stored original, and no limit note was shown. For comparison,
  `d0cc4b4` stored 196,280 / 75,802 / 96,292 B browser re-encodes.
- Over-limit (`max_upload_mb` = 1):
  - `large_gps_o6.jpg` (3,783,650 B) was shrunk by the browser to 196,280 B, 1200×1600. The note "This photo is over
    the upload limit, so a smaller copy will be saved. The original file is not kept." was shown in the item form
    (`#photo-limit`) and the photo manager (`#photoadd-status`).
  - `small_gps.jpg` (170,087 B, under the limit) was byte-identical, kept its GPS, and showed no note.

### Check 2: stripped copies everywhere (PASS, re-run)

- Served copy ≤1600 px at q82 and thumbnail ≤480 px at q76. Large o6 → 1200×1600, rotated, with no EXIF, GPS, Make or
  serial. PNG → JPEG with alpha flattened. HEIC → stripped JPEG with Imagick, or a 64×64 placeholder with GD only.
- Pages and exports (list, item, edit, labels, CSV, settings JSON, `/media/*`) never name or serve an original.

### Check 3: originals not reachable (PASS; L-1, N-1 and N-5 fixed)

- Default location `<DATA_DIR>/originals`, with 64-hex names that are unrelated to the served names.
- **L-1 fixed.** Startup is refused (HTTP 500 in the web server, exit 1 in the CLI, no directory created) when
  `ORIGINALS_DIR` is any of: `public/camera`, `public/Originals`, `public/originals`, `public/x/../cam2`, or a symlink
  into `public/`. A directory outside `public/` starts normally (200). Two nits, N-6 and N-7 below.
- **N-1 fixed.** nginx (new `location ~* ^/originals(/|$)`) and Apache 2.4 both return 404 for `/originals`,
  `/Originals`, `/ORIGINALS`, `/oRiGiNaLs/` and `/%6Friginals`.
- **Apache 2.4 without `mod_access_compat`.** A directory carrying the new app-written `.htaccess` (`<IfModule
  mod_authz_core.c>Require all denied</IfModule>` with a `Deny from all` fallback) returns 403. No 500.
- **N-5 fixed.** `originals/` and `map/` are 0750. The `.htaccess`, all 27 originals and all map files are 0640.
- Built-in server: the 16 guessed and traversal URLs still return 404.

### Check 4: analysis copy (PASS, re-run)

| Case | Sent | Body bytes |
|---|---|---|
| Large o6, no `image_sizes` in `/v1/info` | 768×1024 q80, rotated, stripped | 92,483 |
| Large o6, `image_sizes` 1600, max 983040 | 1200×1600 q80 | 211,150 |
| Small 800×600 | 800×600 q80, not upscaled | 81,303 |
| PNG alpha / HEIC (Imagick) | JPEG 1024×768 / 1024×683 q80 | 70,450 / 83,421 |
| HEIC (GD only) | nothing sent, `unsupported` | 0 |
| `stepdown.jpg` at 1600, max 983040 / 800000 / 640000 / 500000 | q80 894,918 / q70 704,810 / q60 572,217 / nothing (`too_large`) | ≤ limit |
| `noise.jpg` at 1600 | nothing sent, `too_large` | 0 |
| *Test with a photo* sample | 800×363, stripped | 22,187 |

The limit is checked on the whole multipart body. No `autofill-*` temp file was left after success, `too_large`,
`unsupported`, any failure mode, or the photo test. No captured image matched an original.

### Check 5: resize failures (PASS, unchanged; L-2, N-2)

`garbage.jpg`, `huge_63mp.jpg`, `garbage.heic` and `corrupt.jpg` (GD only) each gave `unsupported` with 0 requests.
`corrupt.jpg` with Imagick sends an Imagick-recovered stripped re-encode (N-2). Saving the item is rejected with 422 for
broken JPEGs and accepted with a placeholder for broken HEIC (L-2, pre-existing). The original was never sent.

### Check 6: bad answers and Retry-After (PASS)

| Stub answer | Requests | Browser message | Item saves, original identical |
|---|---|---|---|
| HTML 503 "Error 1102" | 1 | "autofill is unavailable right now…" | yes |
| 200 text/plain | 1 | unavailable | yes |
| 200 truncated JSON | 1 | unavailable | yes |
| 500 JSON | 1 | unavailable | yes |
| JSON 503 service fault | 1 | "service had a problem with this photo…" | yes |
| hang (timeout) | 1 | unavailable | yes |
| 503 `backend_unavailable`, Retry-After 30, then 200 | 2, 30 s apart | suggestions filled in | yes |
| Same, still busy on the retry | 2 (no loop) | "busy. Try again in 30 s." | yes |
| Retry-After 5400 | 1 | "busy or not ready. Try again later." Save stays enabled | yes |

- These are real-browser runs (picked photo for the five answers, stored photo for Retry-After). Each body is the
  stripped 768×1024 analysis copy (92,483 B), never the original. The same five answers through the no-JS endpoint and
  *Test with a photo* also gave `unavailable` with 1 request each.
- This covers "save after a failed suggest".
- No PHP warning or fatal appeared, and the log snippets are token-redacted.

### Check 7: backup, restore, delete, replace (PASS; L-3 docs fixed)

The no-JS E2E was re-run on `dc8cdd2` with the same counts as `d0cc4b4`: phase B 33/0 per engine; phase C 144/2
(Imagick) and 135/3 (GD), where the extras are expected-behaviour lines; phase C2 7/0; phase D 8/1, the one being the
PHP stat-cache artifact with the files confirmed gone on disk. The backup carries originals with matching hashes.
Web and CLI restore bring them back. Delete, replace, duplicate and rotate leave no orphans (0 orphan originals, 0 orphan
map entries).

### Check 8: upgrade and docs (PASS; L-3, N-3, I-2 resolved)

- An in-place upgrade from main kept legacy photos serving the same bytes, with no re-migration.
- **L-3 fixed.** The deployment doc explains the 413 on large restores, how to raise the PHP limits, and `bin/restore.php`.
- **N-3 fixed.** "Disk usage is mostly `data/originals`".
- The admin guide documents the over-limit note. `autofill.md` documents the 60 s Retry-After cap.
- **I-2 resolved.** The PR tree and PR body have 0 matches for the production domain or hostname. Docs use
  `https://autofill.example` and link to the service repo's Worker deploy doc (`docs/cloudflare-workers-deploy.md`,
  reachable).
- Stale lines, N-8 below: README "any other image the browser can decode is converted to JPEG on the device", and the
  `autofill.md` HEIC paragraph's step 1, which describes only the analysis copy.

### Check 9: tests, CI, secrets

- `php tests/integration.php`: **1413 passed, 0 failed**, with GD + Imagick and again GD-only (main 1396, `d0cc4b4` 1411).
  PHPUnit was not run locally (Composer TLS failure on the QA machine); CI runs it.
- CI at `dc8cdd2`: *Headless mobile checks* **failed**. The gallery step timed out because the corrupt PNG fixture was
  now uploaded as-is and rejected with 422. All other completed jobs were green.
- CI at `8e55559`: see §5.
- gitleaks (`detect --no-git`, default rules): 0 leaks on the 1,687 added lines of `main..dc8cdd2`, on the 473 added
  lines of `d0cc4b4..dc8cdd2`, and on the whole tree. `8e55559` adds only a base64 PNG fixture and comments.
- The only IPs in the diff are loopback addresses (127.0.0.1).

## 3. Live pass (production Worker)

Throwaway install of the head (app code as `dc8cdd2`/`8e55559`) with GD + Imagick/libheif and a fresh `DATA_DIR`. The
service address was a local recording pass-through proxy to the production Worker, with a 50 s timeout. Run on
2026-10-20 between 03:00 and 03:02 (Europe/Athens), just after the Worker's daily budget reset. Three photos, as
planned, plus the built-in *Test with a photo* sample. 401, 413 and the spending cap were not tested.

**`/v1/info` as the app sees it:** name `cloudflare-workers`, API version 1, backend `cloudflare-workers`, capabilities
name/category/tags/description, `max_image_bytes` **3,145,728** (3 MiB), `ready: true`. There are no `image_sizes` and
no `models`, so the app sends at most **1024 px** (I-1 confirmed). The 983040 fallback is not in play.

| Call | HTTP | Latency (Worker, via proxy) | Result |
|---|---|---|---|
| *Test connection* | 200 | `/healthz` 0.16 s (no token sent) + `/v1/info` 0.14 s | "Connected to “cloudflare-workers”." Image limit shown as 3 MiB |
| *Test with a photo* | 200 | 1.13 s (`elapsed_ms` 1136) | ok, sample 800×363, body 22,187 B. Suggested "claw hammer" / Tools / metal, orange handle, hand tool: correct for the sample |

| Photo (item form, JS on, *Analyze*) | Browser → app | App → Worker: body / image part | HTTP, Worker latency | Suggestions shown | Original |
|---|---|---|---|---|---|
| `large_gps_o6.jpg` | JPEG 768×1024, 85,557 B | **90,409 / 90,088 B**, JPEG 768×1024 q80, rotated, no GPS/Make/Serial/Orientation | 200, 2.50 s | name "Of multicolored blended colors", category General, tags abstract/colorful/texture, description "An abstract pattern of multicolored blended colors." (confidence 0) | `.jpg`, sha256 identical |
| `stepdown.jpg` | JPEG 1024×1024, 411,247 B | **401,748 / 401,387 B**, JPEG 1024×1024 q80, stripped | 200, 5.56 s | "visual noise", General, noise/pattern/static, "The image contains only visual noise…" (confidence 0) | `.jpg`, sha256 identical |
| `photo.heic` | raw HEIC 2,677,261 B (Chromium cannot decode HEIC) | **83,488 / 83,100 B**, JPEG 1024×683 (Imagick), stripped | 200, 2.29 s | "abstract background", General, abstract/colorful/texture, "An abstract pattern of multicolored textured strokes." | `.heic`, sha256 identical |

- **Plausibility.** The test photos are synthetic (colour patterns and noise), and the suggestions describe them
  correctly. The Worker returned confidence 0 where it had nothing real to name. Its first name, "Of multicolored blended
  colors", is awkward wording from the service, not an app issue. The UI status was "Suggestions ready: name, category,
  tags, description filled in. Check before saving." Time from the click to filled fields was about 3–6 s.
- `model_used`: a Workers AI vision model. Each multipart body also carries a `context` part.
- **Neurons**, from the Worker's `/v1/info` budget: before 0 used (0 requests, fresh daily window), after **22.46** used
  (4 requests). Consumed **22.46 Neurons** for 4 analyses (about 5.6 each), of a daily limit of 6000.
- **Hygiene.**
  - The token was stored only in the throwaway install's SQLite database (mode 600, directory 700). It was cleared and
    the database vacuumed afterwards.
  - The `/v1/info` reads used a mode-600 temporary header file, deleted after use.
  - A count-only scan of the install, its logs, the proxy recordings, `/tmp` and the QA directories found 0 matches for
    the token or its prefix.
  - No temp files were left in `DATA_DIR/tmp`, and the server log had no warning or fatal.
  - The token went only to `/v1/info` and `/v1/suggest`, never to `/healthz`.

## 4. Findings

| Id | Sev | Finding | Status / evidence |
|---|---|---|---|
| H-1 | H | With JS on, the browser re-encode was stored as the "original" | **Fixed in `dc8cdd2`**: check 1, JS on |
| CI-1 | M | At `dc8cdd2`, *Headless mobile checks* failed: the corrupt PNG fixture now reaches GD and is rejected | **Fixed in `8e55559`** (test-only) |
| L-1 | L | `ORIGINALS_DIR` inside `public/` was served | **Fixed**: startup is refused |
| L-2 | L (pre-existing) | Undecodable JPEG / >60 MP → 422, item not saved (only HEIC saves with a placeholder) | Open, unchanged from main |
| L-3 | L (docs) | Large backups and the default PHP limits → 413 on restore | **Fixed** (documented) |
| L-4 | L | Photos the browser can decode but the server cannot are no longer converted on the device. Seen in CI (bad-CRC PNG → 422). Likely case: HEIC picked in desktop Safari, or iOS with "keep originals", on a GD-only install such as the shipped Docker image (no Imagick), now shows a grey placeholder where `main`/`d0cc4b4` showed the browser's JPEG. iOS file inputs usually hand over a JPEG. Not verified on a real Safari. | Option (b) by design. Suggest Imagick + libheif in the Docker image, or a browser-made JPEG as the served copy when the server cannot decode it |
| N-1 | nit | nginx block was case-sensitive | **Fixed** (`~*`) |
| N-2 | nit | With Imagick, a corrupt JPEG that GD rejects is still analysed (stripped re-encode) | Open |
| N-3 | nit | Disk-usage doc line | **Fixed** |
| N-4 | nit (pre-existing) | Deleted items' originals stay until the next undoable action | Open |
| N-5 | nit | 0755/0644 on originals | **Fixed** (0750/0640) |
| N-6 | nit | The startup-refusal 500 page shows the absolute web-root path | Open. Log the path, keep the page generic |
| N-7 | nit | The refusal writes with `fwrite(STDERR, …)`, and `STDERR` is not defined under php-fpm (from reading the code; still refuses) | Open. Use `error_log()` or `defined('STDERR')` |
| N-8 | nit (docs) | README still says the browser converts decodable images to JPEG on the device. The `autofill.md` HEIC paragraph could say that only the analysis copy is converted | Open |
| I-1 | info | Analysis side is 1024 unless `/v1/info` lists `image_sizes`. **Live: the Worker lists none, so 1024 px is sent** | Confirmed live |
| I-2 | info | Production hostname in the docs | **Resolved**: 0 matches, placeholder plus a deploy-doc link |
| I-3 | info | Edits apply to the served copy; autofill analyses the unedited original | Unchanged |

No open H or M findings at `8e55559`.

## 5. CI at the final head

**10/10 green at `8e55559`**: lint, conventional commits, secret scan, tests (PHP 8.3 sqlite and pgsql), Docker
build + smoke, headless mobile checks, and Playwright shards 1–3. The last job finished at 03:05 (Europe/Athens).
At `dc8cdd2` the result was 9/10: everything except *Headless mobile checks* passed (CI-1).

## 6. Verdict

**PASS at `8e55559`** (app code as `dc8cdd2`). No open H or M findings.

- H-1 is fixed. With JS on, the item form and photo manager store the picked file byte-identical (JPEG, PNG and HEIC).
  Above max_upload the browser shrinks the photo and says the original is not kept.
- Only stripped copies are served or exported. The analysis copy is a stripped JPEG with step-down and a check of the
  whole body against the limit.
- The five bad answers give one request and the item still saves. Retry-After is capped at 60 s, with at most one retry.
- Originals are blocked on all three servers, and an `ORIGINALS_DIR` in the web root is refused at startup.
  Permissions are 0750/0640.
- The integration suite passes 1413/0 on both engines. CI is 10/10 green and gitleaks finds nothing.
- The live pass against the production Worker succeeded: 3/3 photos and both settings tests returned 200 with
  plausible suggestions. Each analysis upload was a stripped JPEG of 83–402 KB at ≤1024 px. Every stored original was
  byte-identical. 22.46 Neurons were used for 4 analyses.
- Open, non-blocking: L-2 (pre-existing), L-4 (server-undecodable photos such as HEIC on a GD-only/Docker install now
  show a placeholder), N-2, N-4, N-6, N-7, N-8 (stale README sentence), and I-1 (1024 px, because the Worker lists no
  `image_sizes`).
