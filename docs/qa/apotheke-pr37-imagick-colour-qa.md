# QA: apotheke PR #37: Imagick colour (alpha on white, CMYK to sRGB)

- **PR:** [#37](https://github.com/thordin9/apotheke/pull/37) `fix(images): flatten Imagick alpha and convert CMYK to sRGB` (draft, into `main`)
- **Closes:** [#33](https://github.com/thordin9/apotheke/issues/33)
- **Head:** first review at `cee95a8506b74e95f6c97b91e0944c1abc26194e`, base `42f497e` (v2.5.2). **Delta recheck at
  `46a3adf2fe714010457d1addcb3470c87fab3f2f`**, with `main` at v2.5.3 `4b26f72` (see the delta recheck section). First-review commits:
  - `e0135b4` fix(images)
  - `d1c28c9` test(images)
  - `cee95a8` docs
- **Release:** expected to ship as v2.5.4, after #36 (v2.5.3) is merged and `main` is merged into #37.
- **Date:** 2026-10-20 (Europe/Athens).
- **Constraints:** no live service calls and no credentials. The autofill service was the repository's own stub,
  started locally.

## Test plan

1. **Scope.** Diff against `main`. The change must be PHP image derivation only: no Worker, Dockerfile, workflow or
   CHANGELOG change.
2. **Imagick path, against GD as the reference.** Run on host PHP 8.4 and in the PR's own 8.3 Alpine image (built with
   podman from `cee95a8`).
   - Run `autofill_analysis_jpeg()` with the engine forced to `['imagick']`, then to `['gd']`, and compare every pixel.
   - Inputs:
     - the two committed fixtures;
     - a PNG with partial alpha;
     - a palette PNG with `tRNS`, and a transparent GIF;
     - a CMYK JPEG without an ICC profile (fixture and photo-like);
     - a CMYK JPEG with an embedded CMYK ICC profile;
     - a 3000×2000 CMYK JPEG;
     - a grayscale JPEG and a grey+alpha PNG;
     - an EXIF Orientation 6 JPEG with GPS;
     - a HEIC (the existing fixture);
     - a 1800×1200 HEIC with partial alpha.
   - For HEIC, the GD reference is GD's own path run on a lossless PNG decode. The served-copy writer
     `image_store_heic()` is checked as well.
3. **End to end: the original is kept and only a reduced copy is sent.**
   - Over HTTP (`php -S`): upload each case with `/save`, then run *Analyze* both on the stored photo
     (`photo_id`) and on a photo posted directly.
   - The stub saves the exact bytes it receives.
   - Two modes:
     - **default**: GD first, so Imagick only for HEIC;
     - **imagick**: `imagecreatefromstring` disabled through an extra ini file, so the analysis copy of every non-HEIC
       photo goes through Imagick.
   - On every case, check:
     - the original's sha256 before and after analysis;
     - that the served copy is unchanged;
     - that the sent JPEG differs from the upload, the original and the served copy;
     - the size, dimensions and metadata segments of the sent JPEG;
     - that no temp file is left behind.
4. **Mutation.** Each test must fail when its part of the fix is reverted. The same goes for the new Docker step.
5. **Suites and CI.**
   - PHPUnit and `tests/run.sh` on the host.
   - PHPUnit and integration in the image (git and bash added to a throwaway container).
   - `scripts/docker-heic.sh` against the built image.
   - CI run 39431263215 logs: imagick is loaded, and the Docker leg runs the new step.
6. **Docs, links, gitleaks and fixtures.**
   - Read every changed doc line against the code and the measurements.
   - Link checker (via `run.sh`).
   - gitleaks on the delta.
   - Fixture size and metadata.
7. **Release.**
   - Check the post-release note in the PR description.
   - Replay `scripts/release.php`: #36 merged then released, then `main` merged into #37 (trial against #36's
     `b954a5e`), then #37 merged and released.

## Results

### 1. Scope (PASS)

16 files, +434/−18, identical to the PR's numbers.

| Area | Files |
|---|---|
| Code | `src/items.php` (+72): `imagick_match_gd()`, `imagick_cmyk_to_srgb()`, `imagick_has_transparency()` and `imagick_srgb_icc()`, plus a call in `image_store_heic()`. `src/autofill.php` (+1): the call in `autofill_analysis_imagick()`. |
| Tests | `tests/Unit/ImagickColorTest.php`, `tests/imagick_color_check.php`, fixtures `transparent.png` (130 B) and `cmyk.jpg` (315 B) |
| CI script | `scripts/docker-heic.sh`: a new step, `== Imagick transparency on white, CMYK as sRGB` |
| Docs | `README.md`, `composer.json` (`suggest` text), `docs/{architecture,autofill-admin-guide,autofill,ci,deployment-and-operations,getting-started,user-guide}.md` |

- No change to the Dockerfile, `docker/`, workflows, `release.yml`, `CHANGELOG.md` or `VERSION`. M-1 option (a) is
  applied: `[Unreleased]` is unchanged from `main`.
- The GD path is unchanged: `image_fit()` and `autofill_analysis_gd()` are untouched.
- `imagick_match_gd()` runs after auto-orient and before resize. An opaque sRGB image is left alone.

### 2. Imagick vs GD (PASS)

Engine forced, long side 1600, q80. The results are identical on host PHP 8.4.26 (ImageMagick 7.1.1-43 Q16, libheif)
and in the image (PHP 8.3.35, ImageMagick 7.1.2-34 Q16-HDRI).

The Δ columns compare Imagick with GD on 8-bit channels, over every pixel.

| Case | Imagick output | Δ mean / p99 / max | Notes |
|---|---|---|---|
| `transparent.png` (fixture) | 32×32, 3 components, sRGB, no APPn | 0.17 / 4 / 6 | clear area 255,255,255; red corner 197,10,17. **Before the fix: clear area 0,0,0 (Δ mean 238).** |
| partial alpha PNG 256×128 | 3 components, sRGB | 0.80 / 3 / 7 | Before the fix, Δ mean was 60 and max 224. |
| palette PNG `tRNS` / transparent GIF | 3 components, sRGB | 0.50 / 7 / 13 | Corners are white. Before the fix they were 0,0,0. |
| `cmyk.jpg` (fixture, no ICC) | **3 components**, sRGB | 0 / 0 / 0 | 255,51,26, as GD. Before the fix the output was 4-component CMYK. |
| CMYK photo-like 320×240, no ICC | 3 components, sRGB | 0.61 / 3 / 7 | |
| CMYK photo-like with an embedded CMYK ICC | 3 components, sRGB | 0.61 / 3 / 7 | The output is byte-identical to the no-ICC case: the profile is ignored, as GD ignores it (I-1). |
| CMYK 3000×2000 | 1600×1067, 3 components, sRGB | 0.57 / 3 / 8 | |
| grayscale JPEG | 1-component Gray | 0.23 / 2 / 3 | GD writes 3 components (N-1). |
| grey+alpha PNG | 1-component Gray | 0.38 / 2 / 2 | The transparent half is white. |
| EXIF Orientation 6 + GPS, 2400×1200 | 800×1600 | 0.02 / 0 / 21 | Rotated correctly, as GD: the red block is top-right and the blue block bottom-left. No EXIF or GPS in the output. |
| HEIC fixture 96×64 | 3 components, sRGB | 0.24 / 9 / 22 | No alpha, so the output is unchanged by the fix. |
| HEIC 1800×1200 with alpha | 1600×1067, 3 components, sRGB | 0.65 / 3 / 8 | The served copy from `image_store_heic()` is 0.76 / 4 / 9 against the reference. Before the fix, Δ mean was 38.6 and max 206 (alpha was dropped). |

No Imagick output carries an APPn or COM segment, so profiles and metadata are stripped. All outputs are 4:2:0. The
`tests/imagick_color_check.php` checks pass on both platforms.

### 3. End to end: originals kept, only a reduced copy sent (PASS)

Per run: 13 cases in default mode and 11 in imagick mode, where HEIC is skipped because the HEIC writer itself needs
GD. Each case was analyzed twice, once as a stored photo and once posted directly.

| Run | Original sha256 = upload, before and after analysis | Served copy unchanged | Sent JPEG ≠ upload / original / served | Largest sent | Temp files left |
|---|---|---|---|---|---|
| host 8.4, default | 13/13 | 13/13 | 26/26 | 1024 px, 25,445 B | 0 |
| host 8.4, imagick | 11/11 | 11/11 | 22/22 | 1024 px, 18,840 B | 0 |
| image 8.3, default | 13/13 | 13/13 | 26/26 | 1024 px, 25,444 B | 0 |
| image 8.3, imagick | 11/11 | 11/11 | 22/22 | 1024 px, 18,834 B | 0 |

- **What the service receives:** always `photo.jpg` (`image/jpeg`), within the existing limits. The long side is the
  stub's 1024 image size (1600 cap, never enlarged), at quality 80, far below the size limit.
- **Metadata:** the sent copy never has EXIF, GPS, XMP, IPTC or ICC (no APPn segment).
- **Engine actually used:** the GD analysis copy carries only GD's `CREATOR` comment, and the Imagick copy carries no
  segment at all. That shows imagick mode really went through Imagick, and default mode used Imagick only for HEIC.
- **Original kept as uploaded:** each original sits in `originals/` with its own extension (png, gif, jpg or heic) and
  is byte-identical to the upload.
- **Colour:** the sent copies show the same white, sRGB and orientation results as section 2.

### 4. Mutation (PASS)

| Mutation | Transparent PNG | CMYK JPEG | Original sha256 | HEIC writer | Docker step |
|---|---|---|---|---|---|
| `src/items.php` and `src/autofill.php` reverted to `main` | ✘ 0,0,0 | ✘ 4 components | ✘ derived is black | ✘ 0,0,0 | ✘ "transparent pixel is not white: 0,0,0" |
| no alpha flatten | ✘ | ✔ | ✘ | ✘ | n/a |
| no CMYK conversion | ✔ | ✘ | ✘ | ✔ | ✘ "still 4 components" |
| call removed from `autofill_analysis_imagick()` only | ✘ | ✘ | ✘ | ✔ | n/a |
| call removed from `image_store_heic()` only | ✔ | ✔ | ✔ | ✘ | ✘ "served … 0,0,0" |
| `image_original_save()` stores altered bytes | ✔ | ✔ | ✘ sha differs | ✔ | n/a |

Each of the three named tests fails when its part of the fix is reverted. The fourth test, the HEIC writer, does too.
The original-sha256 test also catches a broken original store.

### 5. Suites and CI (PASS)

**Host (PHP 8.4):**
- PHPUnit: 281 tests, 2057 assertions, 8 skipped (pgsql ×2, zbarimg ×6).
- `ImagickColorTest`: 4 tests, 74 assertions, all OK.
- `tests/run.sh`: lint, JS, 186 links with 0 broken, shellcheck, and greps all OK. Integration: 1423 passed, 0 failed.

**Image (PHP 8.3, git and bash added):**
- PHPUnit: 281 tests, 9 skipped, OK.
- Integration: 1423 passed, 0 failed.
- `scripts/docker-heic.sh` (via podman): all four steps OK, including `ok imagick white background and sRGB`.

**CI run 39431263215** (`pull_request` on `cee95a8`): **10/10 jobs succeeded**, 16:32 to 16:42 Europe/Athens.
- Both Tests legs show `imagick Enabled`.
  - sqlite: PHPUnit OK (281 tests, 0 skipped, so the HEIC test ran too), integration 1425 passed, and
    `coverage-sqlite` uploaded.
  - pgsql: PHPUnit OK (281 tests).
- The Docker job's `docker-heic.sh` log shows the new step `== Imagick transparency on white, CMYK as sRGB` →
  `ok imagick white background and sRGB`. Smoke, checks and Playwright 1/3, 2/3 and 3/3 all succeeded.

### 6. Docs, links, gitleaks and fixtures (PASS, nits)

- **Docs:** every changed doc line matches the code and the measurements, with N-1 below as the one exception.
  Specifically:
  - Transparent areas go to white in both engines.
  - CMYK becomes sRGB and profiles are stripped. GD's served copy is 3-component with no profile.
  - The GD path is unchanged, and the 4:2:0 override applies only to the analysis copy.
  - The `docs/ci.md` Docker row matches the script.
- **Links:** `run.sh` checked 186 relative links: 0 broken.
- **gitleaks:** no leaks in the 16 changed files, in the full diff, or in the replayed range `v2.5.2..cee95a8`. The
  only URLs added are GitHub links.
- **Fixtures:** `transparent.png` is 130 B, 32×32 RGBA, with only a `pHYs` chunk (no text, EXIF or ICC).
  `cmyk.jpg` is 315 B, 16×16, 4-component, with JFIF and an Adobe APP14 marker (YCCK) only (no EXIF, ICC or
  comment). Both are synthetic, with no private data.

### 7. CHANGELOG note and release (PASS)

- **Release replay:** v2.5.2, then #36 merged → plan `bump=patch next=2.5.3`; apply, tag. Then `main` merged into #37,
  then #37 merged → plan `last_tag=v2.5.3 commits=3 bump=patch next=2.5.4`.
  - Fixed: `**images:** flatten Imagick alpha and convert CMYK to sRGB (#37)`.
  - Maintenance: the test and docs commits.
  - The merge commits are ignored.
- **Merging `main` into #37:** this conflicts only in docs.
  - `README.md` has one hunk in the Requirements bullet: #36's JIT lines plus #37's Imagick sentence.
  - `docs/getting-started.md` has two hunks: "What's in the image" (#36's "Opcache is on and JIT is left off" plus
    #37's Imagick sentence) and "Without Docker" (#37's `imagick` sentence plus #36's JIT guard paragraph).
  - Every other file auto-merges. #36 does not touch `src/items.php` or `src/autofill.php`, and #37 does not touch
    `src/qr.php`.
  - A union resolution passes PHPUnit on the host: 283 tests, 8 skipped, OK, including `ImagickColorTest` and
    `QrCodeTest`.
  - #36's head has since moved to `8b4a1a3`, which only adds its QA doc under `docs/qa/`. The conflicts are the same.
  - The real release commit also rewrites the README coverage line, which is outside these hunks.
- **Post-release note in the PR description:**
  `**images:** flatten Imagick transparency onto white and convert CMYK to sRGB (#33)`. It is accurate. See N-3 on
  how to add it.

## Delta recheck: `cee95a8` → `46a3adf`

**Commits since the first review:**
- `7725b41`: merge of `main` (v2.5.3, `4b26f72`); no rebase.
- `64eee8a` refactor(images): share Imagick colour helpers.
- `fae5602` docs: note that an Imagick grayscale JPEG is one channel (N-1).
- `46a3adf` test: count the HEIC writer as a fourth Imagick test (N-2).

The PR is now 7 commits, 17 files, +459/−23 against `4b26f72`, 0 behind `main`. CI run 39439265871 ran on `46a3adf`.

### D1. Merge `7725b41` (PASS)

- **Parents:** `cee95a8` (the PR lineage) and `4b26f72`, which is still the tip of `main`.
- **Same as my trial merge:** the tree equals my trial merge of #36 into #37, except for three things the real v2.5.3
  added to `main`. All three equal `main`:
  - the CHANGELOG `qa` Maintenance line;
  - the README coverage rows;
  - `docs/qa/apotheke-pr36-qr-jit-fix-qa.md`.
- **Conflicts:** the `README.md` hunk is identical to my union resolution. The two `docs/getting-started.md` hunks have
  the same words and are only re-wrapped. Both resolutions keep both sides: the v2.5.3 JIT text and the Imagick text.
- **#36's files equal `main` byte for byte**, at the merge and at `46a3adf`: `src/qr.php`, `src/qr-encode.php`,
  `docker/php.ini`, `AGENTS.md`, `docs/configuration.md`, `scripts/coverage-readme.php`, `tests/Unit/QrCodeTest.php`,
  `CHANGELOG.md` and `VERSION`.
- **#37's code, tests, fixtures and other docs are unchanged by the merge** (same bytes as `cee95a8`).

### D2. Refactor `64eee8a` is a pure move, and L-1 is closed (PASS)

- **The move:**
  - `src/image-color.php` is a new file: a 6-line header, then the four helpers. Those helpers are byte-identical to
    the 3,191-byte block removed from `src/items.php`.
  - Apart from that removal, `items.php` gains one line, `require_once __DIR__ . '/image-color.php';`, and nothing
    else. `autofill.php` also gains only that line.
  - Each `imagick_*` helper is defined only once, and `php -l` is clean.
- **Every entry point loads it:**
  - `bootstrap.php` loads `autofill.php`, which loads the helpers.
  - `items.php`, which the front controller, `bin/*`, PHPUnit and the check scripts load, loads them as well.
  - `image_store_heic()` needs them for the HEIC display copy.
  - The image built from `46a3adf` contains `/var/www/app/src/image-color.php`, and the Dockerfile is unchanged.
- **Bootstrap-only caller** (loads `bootstrap.php` only, so `items.php` is not loaded):

  | Tree | `imagick_match_gd` defined | HEIC 1800×1200 with alpha | `transparent.png` (forced Imagick) | `cmyk.jpg` (forced Imagick) |
  |---|---|---|---|---|
  | `7725b41` (before the refactor) | no | **null** | **null** | **null** |
  | `46a3adf`, host 8.4 | yes | JPEG 1600×1067 | JPEG, clear area 255,255,255 | JPEG 255,51,26 |
  | `46a3adf`, image 8.3 | yes | JPEG 1600×1067 | JPEG, clear area 255,255,255 | JPEG 255,51,26 |

  This shows that the L-1 risk was real and is now gone.

### D3. End to end, tests, mutation and suites (PASS)

The image was built with podman from `46a3adf`: PHP 8.3.35, ImageMagick 7.1.2-34.

- **GD vs Imagick comparison** (13 cases plus a new grayscale HEIC): every derived JPEG is byte-identical to `cee95a8`'s
  on the host. In the image the Δ values are unchanged. The grayscale HEIC gives Gray 1-component in Imagick against
  GD's 3, with Δ max 3.
- **End to end over HTTP**, both modes, host 8.4 and image 8.3:

  | Mode | Cases | Result |
  |---|---|---|
  | default | 14 | All invariants hold. |
  | imagick (GD's `imagecreatefromstring` off) | 11 | All invariants hold. |

  The invariants, checked for every case and both Analyze paths (stored photo and posted directly):
  - The original's sha256 equals the upload, before and after analysis.
  - The served copy is unchanged.
  - The sent JPEG differs from the upload, the original and the served copy.
  - The sent JPEG has no APPn segment, its long side is at most 1024 px, and it is at most 25,445 B.
  - No temp file is left.

  Per-case results:
  - Transparent areas are 255,255,255 in both modes.
  - The CMYK fixture becomes 3-component 255,51,26.
  - A HEIC with alpha has a white clear area.
  - In imagick mode all 11 copies came from Imagick.
- **`ImagickColorTest`:** 4 tests, 74 assertions, OK.
  - Mutations on `src/image-color.php`:
    - `imagick_match_gd()` made a no-op: 4/4 fail.
    - No alpha flatten: 3 fail; the CMYK test passes.
    - No CMYK conversion: 2 fail; the alpha and HEIC tests pass.
  - The Docker step against the no-op mutation fails with "transparent pixel is not white: 0,0,0".
- **`scripts/docker-heic.sh` on the new image:** all four steps OK, including `ok imagick white background and sRGB`.
- **Suites:**
  - Host 8.4: PHPUnit 283 tests, 8 skipped, OK. `tests/run.sh`: 186 links with 0 broken, lint OK, 1423 passed, 0
    failed.
  - Image 8.3 (git and bash added): PHPUnit 283 tests, 9 skipped, OK. Integration: 1423 passed, 0 failed.

### D4. Docs (N-1, N-2), the post-release note (N-3) and the release replay (PASS, one nit)

- **N-1:** the grayscale sentence was added to `README.md`, `composer.json`, `docs/autofill.md`,
  `docs/autofill-admin-guide.md`, `docs/deployment-and-operations.md`, `docs/getting-started.md` (×2) and
  `docs/user-guide.md`. `docs/architecture.md` again says "Imagick for HEIC" and lists `image-color.php`.
  - Measured: the Imagick **analysis** JPEG of a grayscale JPEG, grey+alpha PNG or grayscale HEIC is 1-component.
  - But the **served** copy from `image_store_heic()` is always 3-component, because GD re-encodes it. See N-4.
- **N-2:** the PR text and the class docblock now name four tests, including
  `testHeicWriterFlattensTransparencyOntoWhite`.
- **N-3:** the PR description now says the note "replaces or extends the auto-generated `fix(images)` Fixed line under
  `[2.5.4]`. Do not add it as a second line." The checklist says the same, and `gh release edit` uses the same note.
  The note itself, `**images:** flatten Imagick transparency onto white and convert CMYK to sRGB (#33)`, is accurate.
- **Release replay:** `main` at v2.5.3, then #37 merged with its main-merge → `last_tag=v2.5.3 commits=6 bump=patch
  next=2.5.4`. The merge commits are ignored. The generated `[2.5.4]` section has:
  - **Fixed:** `**images:** flatten Imagick alpha and convert CMYK to sRGB (#37)`, the line N-3 replaces or extends.
  - **Changed:** `**images:** share Imagick colour helpers (#37)`.
  - **Maintenance:** the test and docs commits.

### D5. CI, links and gitleaks (PASS)

- **CI run 39439265871** on `46a3adf`: **10/10 jobs succeeded**, 17:26 to 17:35 Europe/Athens.
  - Both Tests legs show `imagick Enabled`. PHPUnit on sqlite and on pgsql: OK (283 tests).
  - The sqlite leg also ran integration (1425 passed, 0 failed) and uploaded `coverage-sqlite`.
  - The Docker job's `docker-heic.sh` log shows `== Imagick transparency on white, CMYK as sRGB` →
    `ok imagick white background and sRGB`.
- **Link check:** 186 relative links, 0 broken.
- **gitleaks:** no leaks in the replayed range (the main-merge, the refactor, docs and tests), in the combined delta
  diff, or in `src/image-color.php`. The delta adds no URLs.

### D6. Decisions

- **L-1:** closed by the shared `src/image-color.php`, the approach the PM and ITAdvisor chose. Verified in D2.
- **N-1:** closed. The 1-channel behaviour is documented, and architecture.md is fixed. N-4 is a follow-up on wording
  scope.
- **N-2:** closed.
- **N-3:** closed. Apply the note at release time by replacing or extending the generated line.
- **Watch item:** `main` was still `4b26f72` at recheck time. If #39 (docs only) lands first and branch protection
  requires an up-to-date branch, another `main` merge into #37 is needed. Its delta should be docs only; a quick
  re-diff is enough.

## Findings

| ID | Sev | Finding | Suggestion |
|---|---|---|---|
| L-1 | Low (latent): **closed in `64eee8a`** | `imagick_match_gd()` is defined in `src/items.php` but called from `src/autofill.php`. `bootstrap.php` loads `autofill.php` and not `items.php`. Every current entry point loads `items.php` (the front controller, `bin/*`, PHPUnit, `tests/imagick_color_check.php`), so nothing breaks today. But a future caller that loads only `bootstrap.php` would hit an undefined function inside `autofill_analysis_imagick()`. Its `catch (Throwable)` would hide that, and autofill would silently skip HEIC ("could not build a stripped JPEG"). | Follow-up: move the four `imagick_*` helpers to `src/autofill.php`, which both callers already load, or `require_once` them. |
| N-1 | Nit (docs): **closed in `fae5602`** | For a grayscale source, Imagick writes a 1-component Gray JPEG, while GD writes 3-component sRGB. The pixel values match (Δ max 3). `docs/autofill.md` says the Imagick copy "matches what GD would have written". `docs/architecture.md` dropped "Imagick for HEIC" from the `items.php` line. | Follow-up: either convert Gray to sRGB in `imagick_match_gd()`, or say "colour, not channel count". Optionally restore "for HEIC" in `architecture.md`. |
| N-2 | Nit (tests): **closed in `46a3adf`** | The PR and the `ImagickColorTest` docblock say three tests ("one test per case"), but the class has four: `testHeicWriterFlattensTransparencyOntoWhite` is not in the docblock. | Follow-up: add the fourth to the docblock. |
| N-3 | Nit (release): **closed in the PR description** | `release.php` will already write `**images:** flatten Imagick alpha and convert CMYK to sRGB (#37)` under `[2.5.4]` Fixed. Adding the post-release note "under that version's section" gives two near-identical bullets. | When doing the post-release edit, replace or extend the generated bullet rather than add a second one. For example: "**images:** when Imagick decodes a photo (HEIC, or a file GD cannot read), transparent areas are white instead of black and CMYK is converted to sRGB, like GD. Stored originals are unchanged ([#33](https://github.com/thordin9/apotheke/issues/33), [#37](https://github.com/thordin9/apotheke/pull/37))". Use the same text in `gh release edit v2.5.4`. |
| N-4 | Nit (docs, new in the recheck) | The grayscale sentence in `docs/user-guide.md`, which is about the photo the catalog shows, and the HEIC part of `docs/deployment-and-operations.md` say Imagick writes a 1-channel JPEG. Only the **analysis** copy is 1-channel. The served copy from `image_store_heic()` is re-encoded by GD and is always 3-component sRGB (measured for a grayscale JPEG, a grey+alpha PNG and a grayscale HEIC). "Approximate" is also generous: the pixel values match within 3 levels, and only the channel count differs. | Follow-up: limit the sentence to the autofill analysis copy, e.g. "The copy sent to autofill is a 1-channel JPEG for a grayscale photo; the photo shown is a normal JPEG." Non-blocking. |
| I-1 | Info | A CMYK JPEG's embedded ICC profile is ignored: the conversion is naive and byte-identical to the no-ICC case. GD does the same, which is the stated target. The `imagick_srgb_icc()` profile fallback and the `LAYERMETHOD_FLATTEN` branch did not run on either ImageMagick version tested, and there is no ICC directory in the image. | None needed now. |
| I-2 | Info (pre-existing) | GD's analysis JPEG carries GD's `CREATOR: gd-jpeg … quality = 80` comment. It holds no user data and is not part of this PR. | None. |

No High or Medium findings. After the recheck the only open item is N-4, a docs nit. I-1 and I-2 are informational.

## Verdict

**Final, at `46a3adf`: PASS, ready to merge** once it is out of draft. Merge with a merge commit, pinned to `46a3adf`.
- **The main-merge is correct:** it keeps both sides, and #36's files equal `main`.
- **The refactor is a pure move**, and it removes the L-1 risk: a caller that loads only bootstrap and
  `autofill.php` now gets the HEIC and Imagick analysis JPEGs.
- **Stathis's requirement holds end to end** on host 8.4 and in the 8.3 image built from `46a3adf`: the original upload
  is unchanged, and only the reduced, metadata-free JPEG is sent.
- **The colour fix** gives white for transparency and sRGB for CMYK.
- **Tests:** all 4 tests and the Docker step fail under mutation. The suites are green, CI is 10/10, links and gitleaks
  are clean, and the replay gives v2.5.4 (patch) with the generated `fix(images)` line.
- **Findings:** L-1 and N-1 to N-3 are closed. N-4 (docs nit) is a follow-up issue.

(First review at `cee95a8`: PASS with L-1 and N-1 to N-3 as follow-ups.)

Evidence on the QA box: `/workspace/apotheke-qa/pr37/`.
- `pr37.diff`
- `cases/` (inputs) and `gen.php` (the generator)
- `compare.php`, `out-host-84/`, `logs/out-alpine-83/`
- `e2e/` (the harness and the bytes the stub received)
- `mut/` (mutation trees)
- `rel/` (the release replay)
- `logs/` (suite, CI, gitleaks, comparison and E2E JSON, mutation, `trial-merge-conflicts.diff`)
- recheck: `46a3adf/`, `7725b41/`, `d-*.diff`, `bootonly.php`, `graycheck.php`, `mut2/`, `rel2/`, and the
  `logs/*46a3adf*`, `logs/*2-*` and `logs/bootonly.log` files
