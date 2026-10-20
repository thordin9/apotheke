# Apotheke PR #30: HEIC in the Docker image (L-4) and the N-6, N-7 and N-8 fixes, QA

- PR: thordin9/apotheke#30 (draft), "fix: decode HEIC in Docker and hide the originals path"
- Head tested: **`dc0242d1106f6a8abab54942778c4f3ec38a1d74`**. The codeload tarball was checked with `git get-tar-commit-id`.
- Base: `main` at `87850e379d85d6690e232ba107373208f9b7ffbd` (v2.5.0). The PR has main merged in.
- Commits: `2a4ba77` fix(bootstrap), `f32bd2a` fix(photos), `057827f` docs, `001f619` test(autofill), and `dc0242d`, a merge
  of main.
- Diff from v2.5.0: 21 files, 635 lines added and 33 removed. App code: `src/bootstrap.php` only. Also the `Dockerfile`,
  CI, docs, tests, a fixture and its generator.
- Date: 2026-10-20 (Europe/Athens)
- Scope: local runs only, against a QA stub. No live Worker calls were made and 0 Neurons were used.
- Follows up `docs/qa/` for PR #28 (L-4, N-6, N-7 and N-8 come from that report).

## 1. Test plan

### 1.1 Environment

| Item | Setup |
|---|---|
| Sources | codeload tarballs of the PR head and v2.5.0, in a throwaway QA directory. The real install was not touched. |
| Container | Podman 5.4 (Docker CLI compatible) built the PR `Dockerfile` and the v2.5.0 `Dockerfile` on the same machine and day. The containers ran nginx, php-fpm and tini as shipped, with a bind-mounted `/data`. `scripts/docker-heic.sh` ran through a `docker` → `podman` shim. |
| Host PHP | PHP 8.4 CLI and php-cgi, run two ways: GD + Imagick 7.1.1 (libheif), and GD only (Imagick ini removed through `PHP_INI_SCAN_DIR`) |
| Stub | The #28 QA stub, API v1 on loopback. It records every request's raw body. `/v1/info` variants: `max_image_bytes` 983040 with no `image_sizes`; 524288 with no `image_sizes`; 524288 with `image_sizes` [1600] |
| Inspection | exiftool (all groups), ImageMagick `identify` and `compare` (RMSE), magic bytes, GD decode, sha256 |

### 1.2 Photo set

| File | Notes |
|---|---|
| `real_hammer.heic`, `real_tee2.heic`, `real_pi4_2p9mib.heic`, `real_raw_9mib.heic` | Real photos transcoded to HEIC with ImageMagick (q90): 1280×1628, 1024×1024 (Canon EXIF with a serial number), 3900×2606, and 5600×4200 at 20,605,168 B, just under the 20 MB upload limit. They are not camera HEICs (§4, I-3). |
| `photo.heic` | From #28: 3000×2000, with GPS and Make EXIF |
| `apple_like_irot.heic` | `heif-enc` from the #28 `large_gps_o6.jpg`. It has an `irot` box **and** EXIF Orientation 6, as iPhone HEICs do, plus GPS, Make and a serial number. It must be rotated exactly once. |
| `tiny-gps.heic` | The PR fixture: 96×64, 787 B. It is synthetic (EXIF Make `ApothekeTest`), which the PM confirmed. |
| JPEG/PNG regression | From #28: `large_gps_o6.jpg`, `small_gps.jpg`, `png_alpha_small.png`, `wide_strip.jpg`, `stepdown.jpg` (noise, for step-down), `corrupt.jpg`, `garbage.jpg`, `huge_63mp.jpg` |
| Bad HEICs | `garbage.heic` (ftyp header + random bytes); the first 40 % of a real HEIC; only the first 40 B (the ftyp box); plain text named `.heic`; a JPEG named `.heic`; a 0-byte file |

### 1.3 Acceptance checks

1. The image builds. `php -m` lists `imagick`. ImageMagick lists HEIC/HEIF. The image size grew by about 50 MB (144 → 194 MB as the PR reports).
2. HEIC end to end in the container:
   - the original is stored byte-identical (option b);
   - the served view, thumbnail and label images are real JPEGs (magic bytes, they decode) with EXIF and GPS removed;
   - rotation is correct;
   - the analysis copy sent to the stub is a stripped JPEG within `max_image_bytes`.
3. N-6: the startup-refusal page is generic and the path appears only in the log. N-7: the message goes through `error_log`,
   not `STDERR`, under php-fpm. N-8: README and `docs/autofill.md` are accurate. The only service URL in the docs is
   `https://autofill.example`.
4. Test suites: PHPUnit (including `testNoisy1024AnalysisMultipartFitsHalfMebibyte`), `tests/integration.php`, lint, and CI.
5. Regression: JPEG and PNG behave as in v2.5.0. Fake or corrupt HEICs are handled cleanly.
6. Secret and hostname scan of the diff.
7. Release: merging gives a correctly named v2.5.1.

## 2. Results

### Check 1: image build (PASS)

- The PR image built cleanly. The build's own guards passed: `php -m | grep imagick`, and `Imagick::queryFormats()` includes HEIC.
- Inside the image:
  - PHP 8.3.35 with `gd`, `exif` and `imagick` loaded.
  - ImageMagick 7.1.2-34 Q16-HDRI. `Imagick::queryFormats()` includes `HEIC`, `HEIF` and `AVIF`. `magick -list format` shows HEIC/HEIF read support through libheif 1.23.4 (libde265).
  - imagick is pinned to 3.8.0 (pecl).
- Size, measured with podman on the same machine (uncompressed local storage): v2.5.0 **101,439,249 B (101 MB)** → PR
  **136,323,094 B (136 MB)**, **+34.9 MB (+34.4 %)**.
  - The PR reports `docker image ls` 144 MB → 194 MB (+50 MB, +34.7 %).
  - The absolute numbers come from a different tool and storage driver, so they do not match. The relative growth agrees within 0.3 points.
  - The 144/194 MB figures were not reproduced exactly (I-2).

### Check 2: HEIC end to end in the container (PASS)

No-JS item saves through nginx and php-fpm (`POST /save`). Sizes are in bytes.

| HEIC | Original | Served view | Thumbnail | Rotation and content (RMSE vs. reference) | Analysis body at 983040 / 524288 / 524288 + [1600] |
|---|---|---|---|---|---|
| `real_hammer.heic` 1,205,102 | identical, `.heic`, 0640 | JPEG 1259×1600 q82, 409,581 | 378×480 | 0.0166 | 806×1024: 155,690 / 155,690 / 1259×1600: 394,758 |
| `real_tee2.heic` 839,353 | identical | 1024×1024, 218,478 | 480×480 | 0.0184 | 212,916 at every limit (1024 px) |
| `real_pi4_2p9mib.heic` 3,433,823 | identical | 1600×1069, 394,737 | 480×321 | 0.0129 | 207,856 / 207,856 / 385,303 |
| `real_raw_9mib.heic` 20,605,168 | identical (save took 3.1 s) | 1600×1200, 222,541 | 480×360 | 0.0134 | 82,509 / 82,509 / 214,253 |
| `photo.heic` (#28) 2,677,261 | identical | 1600×1067, 205,485 | 480×320 | 0.0156 | 83,515 / 83,515 / 192,847 |
| `apple_like_irot.heic` 4,794,286 | identical | **1200×1600** (portrait), 220,480 | 360×480 | **0.0071** vs. the GD-rotated served JPEG of the same photo | 768×1024: 90,980 / 90,980 / 1200×1600: 207,290 |
| `tiny-gps.heic` 787 | identical | 96×64 (decoded, not the 64×64 placeholder) | 96×64 | n/a | 788 at every limit |

- **Served copies.** Every served view and thumbnail starts `FF D8 FF`, decodes in GD and identifies as JPEG. Each has
  no EXIF APP1, no GPS, Make, Model, serial or Orientation tag, and is served as `image/jpeg`.
- **Labels.** `/labels?ids=…` returned 200 for each item. It references no original and no `.heic` name.
- **Rotation.** The `irot` + EXIF 6 file comes out upright and rotated once. It matches the GD output for the source JPEG
  at the same size (RMSE 0.007). There was no double rotation, with the QA machine's ImageMagick 7.1.1 or the image's 7.1.2.
- **Analysis copies.** Every analysis body was a stripped JPEG at q80 under the advertised limit. No body contained
  `ftypheic` or matched an original. The long side was 1024 px without `image_sizes` and 1600 px with [1600]. The
  largest realistic body was **394,758 B**, at 1600 px with a 524288 limit.
- **Picked HEIC.** A raw HEIC sent to `/autofill/suggest`, which Chromium does because it cannot decode HEIC, came back
  as a stripped JPEG analysis copy within 524288 B: `real_hammer` 155,690 B and `photo.heic` 83,515 B.
- **Temp files.** No `autofill-*` temp file was left behind.
- **Contrast with the v2.5.0 image.** The same HEICs got the 64×64 grey placeholder, and Analyze returned `unsupported`
  with 0 requests. That is the L-4 behaviour, and this PR fixes it for Docker installs.
- **In-image script.** `scripts/docker-heic.sh` against the built image printed `ok HEIC`, `ok heic round-trip` and `ok refusal`, and exited 0.

### Check 3: N-6, N-7, N-8 and the hostname rule (PASS)

- **N-6 fixed.** In the container (php-fpm) with `ORIGINALS_DIR` inside `public/`:
  - The response is HTTP 500 with the body "The catalog cannot start because photo storage is inside the public web
    directory. Set originals_dir to a directory outside public/." There is no filesystem path in the body.
  - The container log has `ORIGINALS_DIR is inside the web root (originals=…, web=…)` with both paths.
  - For comparison, the v2.5.0 image shows the web-root path in the browser.
- **N-7 fixed.** The message reaches the log through `error_log` as a php-fpm `PHP message`.
  - The v2.5.0 image logs `PHP Fatal error: Uncaught Error: Undefined constant "STDERR"` for the same request. In #28
    I flagged this from reading the code, and it is now confirmed at run time.
  - The PR has no `STDERR` reference left in `src/bootstrap.php`.
  - The new php-cgi and `php -S` tests pass locally with php-cgi installed (not skipped).
- **N-8 fixed.**
  - README: the line "any other image the browser can decode is converted to JPEG on the device" is gone. The README now
    says the picked file is uploaded untouched when it fits, the browser shrinks it only above the limit, and HEIC is
    decoded on the server when Imagick lists it.
  - `docs/autofill.md`: the HEIC paragraph now separates the stored file, the served JPEG and the analysis copy. The
    line "may still make a smaller copy for the Analyze request only" matches `public/assets/app.js`.
  - Also updated consistently: `user-guide.md`, `autofill-admin-guide.md`, `deployment-and-operations.md`,
    `getting-started.md`, `architecture.md`, `ci.md` and the `composer.json` suggest entry.
- **Hostnames.** The production Worker hostname, its apex domain and any `workers.dev` name appear 0 times in the PR tree
  and the PR body. These were checked by count only, against the service's route config. The only service URL in the
  docs is `https://autofill.example`. The other URLs are GitHub links to the service repo and its deploy doc.

### Check 4: tests (PASS, with L-1)

| Suite | GD + Imagick (host PHP 8.4) | GD only (host PHP 8.4) | Inside the PR image (PHP 8.3.35) |
|---|---|---|---|
| `php tests/integration.php` | **1423 passed, 0 failed** | **1422 passed, 0 failed** | n/a |
| PHPUnit unit | 274 tests, 1942 assertions, 8 skipped, **1 incomplete** (L-1) | 274 tests, 1919 assertions, 8 skipped, 0 incomplete | `Noisy1024` + `HeicTest`: OK, 3 tests, 76 assertions |
| PHPUnit integration | OK (1 test) | n/a | n/a |
| `scripts/lint.sh` | OK (php -l, links 186/0 broken, shellcheck) | | |

- The reported 1422 matches the GD-only run. With Imagick the HEIC block adds one more check, which gives 1423.
- `testNoisy1024AnalysisMultipartFitsHalfMebibyte` (multipart body sizes, q80 / q70 / q60):
  - GD on the host (PHP 8.4): **501,999** / 385,375 / 313,609. This equals the PR's host figure.
  - In the PR image, GD: **443,624** / 340,247 / 281,912. Imagick: **429,944** / 327,226 / 265,422. Both match the PR exactly.
  - Imagick on the host: **1,058,754** / 842,770 / 678,252. That is over 524288 even at q60, so the test marks itself
    **incomplete**, not failed (L-1).
- **CI.** 10/10 green at `dc0242d`: Lint, Conventional Commits, Secret scan, Tests (sqlite, pgsql), Headless mobile
  checks, Docker build + smoke test (now including `docker-heic.sh`), and Playwright shards 1–3. The last job finished
  at 10:48 (Europe/Athens).

### Check 5: regression (PASS)

- **JPEG/PNG identical to v2.5.0.** Both images were built the same day and given the same uploads. `large_gps_o6.jpg`,
  `small_gps.jpg`, `png_alpha_small.png`, `wide_strip.jpg` and `stepdown.jpg` produced byte-identical served views and
  thumbnails (same sha256) and identical analysis bodies, for example 92,582 / 81,402 / 70,549 / 4,964 / 396,983 B at
  1024 px. Originals were byte-identical with mode 0640. The o6 photo was served upright at 1200×1600.
- `stepdown.jpg` (noise only) gives `too_large` at 1600 px with a 524288 limit, with 0 requests. That is the same in
  v2.5.0 and expected for noise-only fixtures under the PM's #27 rule.
- `corrupt.jpg`, `garbage.jpg` and `huge_63mp.jpg` were rejected on save with 422 ("Could not read that image.",
  "Unsupported image…", "Image dimensions are too large."), as in v2.5.0.
- One change: a picked `corrupt.jpg` sent to Analyze in the PR image is now analysed from an Imagick re-encode (1
  request, stripped). The v2.5.0 image returned `unsupported`. This is N-2 from #28, which is now reachable in Docker.
- **Bad HEICs: no crash, no PHP warning, nothing sent.**
  - `garbage.heic`, the truncated real HEIC and the header-only file are saved with the camera bytes kept (`.heic`) and
    the 64×64 placeholder JPEG served. Analyze gives `unsupported` with 0 requests. This is the existing L-2 behaviour,
    unchanged.
  - Text named `.heic` and the 0-byte file are rejected with 422 "Unsupported image…".
  - A JPEG named `.heic` is sniffed as JPEG and handled as a normal JPEG (`.jpg` original, 800×600 served).
- The container log had no PHP fatal, warning or `Undefined constant` in any run.

### Check 6: secrets and hostnames (PASS)

- gitleaks (`detect --no-git`, default rules): **0 leaks** on the 635 added lines and on the whole PR tree. trufflehog
  is not installed on the QA machine. The PM's re-scan covers it.
- The only IP literals added are loopback addresses in tests and `docker-heic.sh`. There are no tokens, keys or account
  ids. The `/tmp/secret-*` strings in `OriginalsTest` are dummy paths.

### Check 7: release naming (PASS, see N-9)

- The release workflow runs `scripts/release.php`. It computes the bump from Conventional Commits since the last tag
  (`feat` = minor, `fix`/`perf` = patch, merge commits ignored). It refuses to apply when `VERSION` does not match the
  last tag.
- The PR leaves `VERSION` at 2.5.0, which matches tag `v2.5.0`. That is correct: the bot writes the bump. The v2.5.0
  bump was the bot's own `chore(release): v2.5.0 [skip ci]` commit, not a hand edit.
- **Simulation.** I made a local git repo with the v2.5.0 tree tagged `v2.5.0` and replayed the 4 PR commit subjects.
  Merging with a merge commit, `release.php plan` printed `bump=patch next=2.5.1 release=true`, and `apply` wrote
  `VERSION` 2.5.1 and a `## [2.5.1]` section with the two `fix` lines plus Maintenance. A squash merge with the PR title
  ("fix: …") also gives 2.5.1.
- Merging with a `feat:` commit or a `!` would make it 2.6.0 or 3.0.0.

## 3. Image size detail

| Image | podman `.Size` | PR report (`docker image ls`) |
|---|---|---|
| v2.5.0 | 101,439,249 B | 144 MB |
| PR #30 | 136,323,094 B | 194 MB |
| Growth | +34,883,845 B (+34.4 %) | +50 MB (+34.7 %) |

## 4. Findings

| Id | Sev | Finding | Status / evidence |
|---|---|---|---|
| L-4 (#28) | L | HEIC on the Docker image showed a grey placeholder | **Fixed for the Docker image**: check 2. GD-only installs are unchanged and that is now documented. |
| N-6 (#28) | nit | The refusal page showed the web-root path | **Fixed**: check 3 |
| N-7 (#28) | nit | `fwrite(STDERR)` under php-fpm | **Fixed**: check 3. The v2.5.0 image really throws `Undefined constant "STDERR"`. |
| N-8 (#28) | nit (docs) | Stale README and `autofill.md` HEIC wording | **Fixed**: check 3 |
| L-1 | L (test) | `testNoisy1024AnalysisMultipartFitsHalfMebibyte` depends on the environment, and an over-limit result shows as *incomplete*, so PHPUnit still exits OK. Cause: `autofill_analysis_imagick()` keeps the source JPEG's chroma subsampling. The seeded fixture is written by GD at q95. Debian's libgd (PHP 8.4) writes it as 4:4:4, while Alpine's in the image writes 4:2:0. With 4:4:4, the Imagick copy is about 2.5× the size: 1,058,754 B at q80 and 678,252 B at q60, over 524288. | Open, non-blocking. **No realistic impact today**: Imagick is used only for HEIC, which has no JPEG subsampling to keep (all HEIC bodies were 0.8–395 KB), and for JPEGs that GD cannot read. Suggest forcing 4:2:0 in both Imagick encoders (`setSamplingFactors(['2x2','1x1','1x1'])`), and making the test fail when q60 is over the limit, or pin the fixture's subsampling. |
| L-2 (#28) | L (pre-existing) | Undecodable HEIC is saved with a placeholder (and undecodable JPEG → 422) | Unchanged; check 5 |
| N-2 (#28) | nit | With Imagick, a corrupt JPEG that GD rejects is still analysed (stripped re-encode) | Open. **It now applies to Docker installs too**: check 5 |
| N-9 | nit (docs/release) | `release.php` leaves hand-written `## [Unreleased]` text in place. The block still lists items that shipped in v2.5.0, and this PR adds two more lines to it. After the merge, v2.5.1 gets a section built from commit subjects, but the hand-written lines stay under *Unreleased*. | Pre-existing since v2.5.0. Suggest a docs commit that clears the block, or have `release.php` move it into the new section. It does not block the release; check 7. |
| I-1 | info | Merging cuts **v2.5.1** as expected (patch from 2 `fix` commits; `VERSION` 2.5.0 = last tag) | Simulated; check 7 |
| I-2 | info | Image growth measured as +34.9 MB (+34.4 %) with podman, against +50 MB (+34.7 %) reported from Docker | Same proportion. The absolute numbers depend on the tool. |
| I-3 | info | No real camera HEIC in the set. `tiny-gps.heic` is synthetic (`ApothekeTest`, PM-confirmed), and the "real" HEICs are real photos transcoded with ImageMagick. An Apple-style `irot` + EXIF 6 file was built with `heif-enc` instead. | Known gap, as in #27. Nothing else in the HEIC path changed in this PR. |

No open H or M findings at `dc0242d`.

## 5. Verdict

**PASS at `dc0242d`.** No open H or M findings.

- The Docker image now has Imagick 3.8.0 / ImageMagick 7.1.2 with libheif. A HEIC upload is kept byte-identical. The
  served view, thumbnail and label images are real stripped JPEGs, rotated once, including an iPhone-style `irot` +
  EXIF 6 file. The analysis copy is a stripped JPEG of 0.8–395 KB, within 524288 at 1024 px and at 1600 px.
- N-6, N-7 and N-8 are fixed. Under php-fpm the browser sees a generic 500 page and the paths go to the log through
  `error_log`. v2.5.0 had a fatal error there.
- JPEG and PNG output is byte-identical to v2.5.0. Bad HEICs are handled as before, with no warnings and nothing sent.
- Integration: 1423/0 with Imagick and 1422/0 GD-only. PHPUnit passes (1 incomplete on the host, L-1). Lint is OK. CI
  is 10/10. gitleaks found 0 leaks, and the production hostname appears 0 times.
- Merging cuts v2.5.1.
- Open, non-blocking: L-1 (Imagick keeps 4:4:4, and the test goes *incomplete* rather than failing), L-2, N-2 (now in
  Docker), N-9 (stale *Unreleased* block), I-2 and I-3.
