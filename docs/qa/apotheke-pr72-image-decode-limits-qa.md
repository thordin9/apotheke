# QA: apotheke #72, image decode limits (S-M1, SEC-N5) and the suggest header check (N-3)

- **PR:** [#72](https://github.com/thordin9/apotheke/pull/72), `cursor/image-decode-limits-2197` into `main`, by Coder
- **Head reviewed:** `a29e1ee632f8a9b4bbbee88e7f4ebce55c86aebf` (the PR head when I re-checked at the end of the review)
- **Base:** `main` at `1dbe986` (`chore(release): v2.6.3`), the second parent of the merge `baa0174`
- **Scope:** a full review of the whole PR against `main`, plus the new range `3b64cbf..a29e1ee`: `d09ee4d` (N-3, `/autofill/suggest` runs the upload header check), the main merge `baa0174` and `a29e1ee` (CHANGELOG bullets moved to `[Unreleased]`)
- **Finding IDs:** N-1 to N-3 are taken by the first round (N-3 is the suggest check fixed in `d09ee4d`), so this round's Nits are N-4 to N-6.
- **Verdict: PASS.** 0 High, 0 Medium, 2 Low, 3 Nits, 6 Info. Neither Low is a regression from `main`, and neither blocks.

## 1. Test plan

1. Code from codeload tarballs of the head, `3b64cbf`, `d09ee4d`, `baa0174`, `242c534` (the merge base) and `main` `1dbe986`. Diff review of the whole PR and of the new range. Commit subjects.
2. Merge check: every file in `baa0174` compared against a 3-way merge (`git merge-file`) of `d09ee4d` and `1dbe986` over `242c534`.
3. CHANGELOG gate (PM rule) at the head.
4. PHPUnit, including `HttpSuiteTest` (which runs `tests/integration.php` and `tests/integration_autofill.php`), on PHP 8.3 and 8.4 on SQLite and PostgreSQL 17, and on a Debian PHP 8.3 stack with a newer libheif.
5. Real HTTP runs: each photo goes through `/save` and then `/autofill/suggest` on a fresh `php -S` with the autofill stub from `tests/lib.php`. The harness records status, error text, files kept, a SHA-256 of the kept original, what the stub received, temp files left behind and peak RSS.
6. Mutations against the N-3 check and the HEIC side limit.
7. Docs read against the code. Secret and host-name scan of the diff.
8. CI run 40085863706, checked through the GitHub API.

**Environment, and how it differs from the built image.** The box has no Docker.
- **Image stack:** Alpine 3.24 rootfs from `php:8.3.36-fpm-alpine` and `php:8.4` Alpine, with the Dockerfile's own `apk` and Imagick 3.8.0 build steps: PHP 8.3.36 and 8.4.27, ImageMagick 7.1.2-34 Q16-HDRI, libheif 1.23.4 (libde265 and dav1d plugins). The tests ran in a chroot with `/proc` and `/dev` mounted only while they ran.
- **Debian stack:** PHP 8.3 with distribution ImageMagick 7.1.1-43 and libheif 1.23.6 with all plugins, including the uncompressed codec. I used it to tell libheif build differences apart from code faults.
- **PostgreSQL:** 17.11 on the box, with a throwaway role and throwaway databases that were dropped afterwards.
- **Not repeated:** the Docker build and the image smoke test. CI's Docker job covers them (below).

## 2. Results

### 2.1 CI

Run 40085863706 (`pull_request`, head `a29e1ee`): `completed` / `success`, 10/10 jobs `success`. The jobs are Lint, Tests (PHP 8.3, sqlite), Tests (PHP 8.3, pgsql), Headless mobile checks, Visual E2E 1/3, 2/3 and 3/3, Docker build + smoke test, Conventional Commits, and Secret scan. The run started at 13:25:39 and ended at 13:34:11 (Athens, UTC+3).

### 2.2 Diff and commits

Against `main` the PR changes 12 files: CHANGELOG, `composer.json` (the `ext-imagick` suggest text), `docs/autofill-admin-guide.md`, `docs/autofill.md`, `docs/deployment-and-operations.md`, `public/index.php`, `src/autofill.php`, `src/bootstrap.php`, `src/image-color.php`, `src/items.php`, `tests/integration_autofill.php`, and the new `tests/Unit/ImageDecodeTest.php` (20 tests).

| Commit | Length | Subject |
|---|---|---|
| `378447e` | 60 | `fix(images): bound large photo decode and refuse huge frames` |
| `de27c45` | 48 | `docs(images): note the limit on photo dimensions` |
| `15d17a9` | 53 | `docs(changelog): record the large-photo decode limits` |
| `e79fc07` | 61 | `fix(images): turn resource-limit refusals into the size error` |
| `6b6435b` | 58 | `fix(images): align HEIC header gate with the 128 MP policy` |
| `344f8d9` | 47 | `fix(images): keep the HEIC side cap at 16384 px` |
| `678c276` | 46 | `fix(images): set the HEIC side cap to 20000 px` |
| `6c02e78` | 46 | `fix(images): set the HEIC side cap to 16384 px` |
| `dc41c9a` | 55 | `test(images): require the iloc error only when reported` |
| `3b64cbf` | — | Merge of `origin/main` (`242c534`) |
| `d09ee4d` | 52 | `fix(autofill): refuse an over-limit photo on suggest` |
| `baa0174` | — | Merge of `origin/main` (`1dbe986`, v2.6.3) |
| `a29e1ee` | 48 | `docs(changelog): keep #72 notes under Unreleased` |

All subjects are Conventional Commits and 72 characters or shorter. Merge commits are skipped by the CI check. There are no rewrites. There is no `docs(qa):` commit yet; this doc becomes the last commit.

**N-3 (`d09ee4d`).** The new `image_assert_bytes_within_limits()` is the upload's header check, pulled out into `image_raster_header_over_limit()` and `image_heic_header_over_limit()`, and `image_store()` now calls the same helpers. `autofill_suggest_path()` calls it right after reading the bytes and before any analysis copy is built. That covers both the picked-photo and the `photo_id` branch. `autofill_suggest()` rethrows `UserError` instead of turning it into "unavailable". The route wraps both branches in `try`/`catch (UserError)` and answers `json_out([... 'error' => msg, 'message' => msg], 422)`. `json_out()` is `never`, so no path falls through. CSRF and `no_store()` still come first, and the `max_upload()` size check still runs before the header check. The browser's `afSend()` parses JSON whatever the status, so the 422 message reaches the form.

### 2.3 Merge `baa0174` and the CHANGELOG gate

| Check | Result |
|---|---|
| Merge has no code conflicts | **Yes.** Every file in `baa0174` equals the clean 3-way merge of `d09ee4d` and `1dbe986` over `242c534`. 4 files needed a real 3-way merge: CHANGELOG, `docs/deployment-and-operations.md`, `public/index.php`, `src/items.php`. All merged cleanly (`rc=0`) and match the commit. The #71 QA doc comes from main unchanged, and `ImageDecodeTest.php` comes from the branch unchanged. |
| The head contains main's latest release commit `1dbe986` | **Yes.** It is the second parent of `baa0174`. `1dbe986` is still main's latest release commit. `main` itself moved during the review, to the #73 merge `a94b76a`, which is not a release (I-6). |
| `## [2.6.3]` to the end of the file (34,517 bytes, includes all older sections and the link refs) vs main | **Byte-identical** (same SHA-256) |
| Everything above `## [Unreleased]` | Identical to main |
| #72 bullets | 3 bullets, all under `## [Unreleased]` → `### Fixed`, each linking #72 and naming S-M1 and SEC-N5. None anywhere else. `a29e1ee` moves them out of the released `[2.6.3]`, where the merge had left them. |
| Link refs | `[Unreleased]: …/compare/v2.6.3...HEAD`, the same as main |

**Gate: PASS.**

### 2.4 Test suites (PHPUnit, 419 tests each)

| Leg | Result | Failures (all also seen without this PR or environment-only, see I-2) |
|---|---|---|
| Alpine PHP 8.3, SQLite | 419 tests, 3,005 assertions, 9 failed, 19 skipped | 413 test, 2 × `ReleaseTest`, 6 × HEIC unit tests on the synthetic uncompressed sample |
| Alpine PHP 8.3, PostgreSQL 17 | 419 tests, 3,033 assertions, 9 failed, 9 skipped | same 9 |
| Alpine PHP 8.4, SQLite | 419 tests, 3,005 assertions, 9 failed, 19 skipped | same 9 |
| Alpine PHP 8.4, PostgreSQL 17 | 419 tests, 3,033 assertions, 9 failed, 9 skipped | same 9 |
| Debian PHP 8.3, SQLite | 419 tests, 3,062 assertions, 3 failed, 18 skipped | 413 test, 2 × `ReleaseTest` |
| Debian PHP 8.3, PostgreSQL 17 | 419 tests, 3,089 assertions, 4 failed, 8 skipped | the same 3 plus `HttpSuiteTest` (one `/healthz` missing-schema check) |

- **Every #72 test passes on the Debian stack,** on both drivers. That includes `testSuggestRefusesAnOverLimitHeader` and the integration checks "over-limit JPEG on /autofill/suggest → 422" and "is the dimension error and is not sent".
- **`HttpSuiteTest`** passes on all four Alpine legs and on Debian SQLite.
- **`tiny-gps.heic`:** `HeicTest`'s 3 tests (fixture, upload keeps the original, display sampling) pass on all 6 legs (I-1).
- **Skips:** `QrCodeTest` (6, no `zbarimg`), `ReleaseTest` (1), `ImageDecodeTest` (1, a memory-runner case), `OriginalsTest` (1, no `php-cgi`). SQLite legs also skip the PG-only `PgsqlSchemaCreateTest` and 2 `BackupTest` cases.

### 2.5 Decode limits over HTTP (Alpine PHP 8.3, the image stack)

"Kept" means `/save` answered 200, `originals/` holds exactly one file, and its SHA-256 equals the upload's. "Sent" is what the autofill stub received.

| Photo | Header | `/save` | `/autofill/suggest` | Sent to the stub | Peak RSS save/suggest |
|---|---|---|---|---|---|
| JPEG 48 MP | 8000×6000 | 200, kept, display 1600×1200 | 200 ok | JPEG 12,159 B, 1000×750 | 78 / 53 MB |
| JPEG 50 MP | 8160×6120 | 200, kept, 1600×1200 | 200 ok | JPEG 1020×765 | 80 / 54 MB |
| JPEG ~60 MP | 9000×6667 | 200, kept, 1125×834 (L-2) | **200 ok** (N-3 risk note: accepted, as on upload) | JPEG 10,578 B, 1024×759 | 50 / 72 MB |
| JPEG 200 MP | 16320×12240 | 200, kept, 1600×1200 | 200 ok | JPEG 1024×768 | 81 / 106 MB |
| JPEG 20,000 px wide, 100 MP | 20000×5000 | 200, kept, 1600×400 | 200 ok | JPEG 1024×256 | 58 / 71 MB |
| JPEG 20,000 px wide, 200 MP | 20000×10000 | 200, kept, 1600×800 | 200 ok | JPEG 1024×512 | 81 / 100 MB |
| JPEG 240 MP | 30000×8000 | 200, kept, 1600×427 | 200 ok | JPEG 1024×273 | 92 / 103 MB |
| JPEG 252 MP | 30000×8400 | **422** too large, nothing kept | **422 `Image dimensions are too large.`** | nothing | 35 / 36 MB |
| JPEG side 30,001 | 30001×1000 | **422**, nothing kept | **422** | nothing | 34 / 33 MB |
| JPEG header 65535×65535 | — | **422** | **422** | nothing | 34 / 34 MB |
| JPEG 40 MP (GD) / 40 MP + 1 column (reduced decode) | 8000×5000 / 8001×5000 | 200 / 200, kept | 200 / 200 | JPEG 1024×640 / 1001×625 | 202 / 71 MB |
| JPEG 48 MP, EXIF orientation 6 | 8000×6000 | 200, kept, 1200×1600 | 200 | JPEG 750×1000 | 103 / 59 MB |
| PNG 64 MP | 8000×8000 | **422**, nothing kept | **422** | nothing | 34 / 34 MB |
| PNG 40 MP | 6400×6250 | **500**, original left behind (L-1) | **500** | nothing | 318 / 326 MB |
| HEIC 48 MP (2 encodings) | 8064×6048 | 200, kept, 1600×1200 | 200 ok | JPEG 1024×768 | 264 / 264 MB |
| HEIC side 16,384 | 16384×2048 | 200, kept, 1600×200 | 200 ok | JPEG 1024×128 | 195 / 195 MB |
| HEIC side 16,385 (2 encodings) | 16385×2048 | **422**, nothing kept | **422** | nothing | 38 / 38 MB |
| HEIC declared 200 MP (2 samples) / declared side 16,385 | ispe 16384×12288 / 16385×… | **422** | **422** | nothing | 38 / 38 MB |
| HEIC 128.06 MP | 16000×8008 | **422** | **422** | nothing | 38 / 38 MB |
| HEIC exactly 128 MP / 89.5 MP | 16000×8000 / 11000×8136 | 422 at decode (resource limit) | 200 `unsupported` (N-4) | nothing | 43 / 43 MB |
| HEIC 89.4 MP | 11000×8134 | 200, kept | 200 ok | JPEG 1024×757 | 428 / 428 MB, 9.5 s, ~1 GiB pixel cache |
| HEIC with a 599 MB `iloc` extent (2 samples) | — | 200, kept, 64×64 placeholder, 0.02 s | 200 `unsupported`, 0.02 s | nothing | 43 / 42 MB, **never decoded** |
| JPEG renamed `.heic` / SVG renamed `.jpg` | — | 200 kept / 422 unsupported | 200 / 200 `unsupported` | JPEG 1000×750 / nothing | — |

- **No 500s outside L-1.** No PHP fatal and no temp files left in either temp dir.
- **Nothing reached the stub on a refusal.** On every 422, `originals/` and `images/` stayed empty after the suggest call, and the stub's request log did not grow.
- **PHP 8.4 (Alpine):** a 10-photo subset (48, 60, 200 and 252 MP JPEG, the 20,000 px panorama, 48 MP HEIC, HEIC sides 16,384 and 16,385, declared 200 MP HEIC, the 599 MB `iloc` HEIC) gave the same statuses, sizes and sent copies.
- **Debian stack (its own libheif):** the HEIC side cap gives the same answers, as do the declared 200 MP HEIC, the 60 MP JPEG (display copy 1600×1185 there), the 252 MP JPEG and the 599 MB `iloc` HEIC (64×64 placeholder, never decoded). The uncompressed `iloc` sample also stays undecoded on this libheif, which does have the uncompressed codec.

**Against the #70 policy.** Policy and code agree: width and height 16,384 px, area 128 MP, memory 256 MiB, map 256 MiB, disk 1 GiB. `testImagickResourceLimitsAreSet` and `testShippedPolicySideCapMatchesHeicConstant` pass. The JPEG header cap is 250 MP and 30,000 px a side. A JPEG over 40 MP is decoded already reduced via `jpeg:size`, and the 200 MP JPEG decodes in 81 to 106 MB RSS. The HEIC header refuses anything over 128,000,000 px or 16,384 px a side before any pixels are read (0.01 s, 38 MB).

### 2.6 Stathis's photo requirement, end to end

For every photo that was kept (24 cases on 8.3, 7 on 8.4, 6 on Debian), the file in `originals/` was **byte-identical** to the upload (SHA-256 match). The autofill stub **never** received the original: in all 32 suggest calls that sent something, the SHA-256 of what was sent differs from the upload's. Each time it received a re-encoded JPEG, at most 1,024 px on the long edge and 286 B to 25 KB, including for HEIC uploads (converted to JPEG). Refused uploads kept nothing and sent nothing, and the never-decoded `iloc` HEICs sent nothing.

### 2.7 Mutations (Debian PHP 8.3, SQLite)

Unit filter: `ImageDecodeTest|HeicTest|AutofillTest|ImagickColorTest|DockerfileTest|OriginalsTest` (106 tests). The baseline has 1 failure, the 413 test (I-2). The source was restored and compared with the head after each mutant.

| Mutant | Change | Killed by |
|---|---|---|
| M1 | drop `image_assert_bytes_within_limits()` from `autofill_suggest_path()` | `testSuggestRefusesAnOverLimitHeader`; `HttpSuiteTest` (integration 422 checks) |
| M2 | route answers 200 `unsupported` instead of the 422 dimension error | `HttpSuiteTest` |
| M3 | skip the HEIC branch of the suggest check | `testSuggestRefusesAnOverLimitHeader` |
| M4 | skip the raster branch of the suggest check | `testSuggestRefusesAnOverLimitHeader`; `HttpSuiteTest` |
| M5 | `IMAGE_HEIC_MAX_SIDE` 16384 → 16385 | 4 tests, including `testHeicSideOf16384IsAcceptedAnd16385IsRefused` and the policy-match test |
| M6 | the HEIC header side check allows 16,385 (constant unchanged) | `testHeicSideOf16384IsAcceptedAnd16385IsRefused` |
| M7 | the Imagick width and height limits allow 16,385 | `testImagickResourceLimitsAreSet`, `testJpeg16320By12240DecodesUnderTheAreaLimit` |
| M6+M7 | both | 3 tests, including the 16,384/16,385 test |

All 8 mutants were killed. The HTTP-level baseline (`HttpSuiteTest`) passes. On the Alpine stack, M3 would survive, because the HEIC half of the unit test cannot ping its synthetic sample there (N-6).

### 2.8 Docs

`docs/autofill.md` gains a row for the 422 on `/autofill/suggest`. The admin guide and the deployment doc state the 250 MP / 30,000 px JPEG cap, the reduced decode above 40 MP, the 128 MP / 16,384 px HEIC header stop, and the Imagick limits that match the shipped policy. The CHANGELOG bullets match the code. Two statements are not quite accurate: "40 MP fits under memory_limit 256M" (the `image-color.php` header, and the GD budget in the docs) does not hold for PNG (L-1), and `autofill_suggest()`'s docblock is out of date (N-5).

## 3. Findings

### L-1 (Low, not a regression): a 40 MP PNG still exhausts `memory_limit` and leaves its original behind

A 6400×6250 PNG is exactly at the GD budget (`IMAGE_GD_MAX_PIXELS`, 40 MP), so the header check lets it through. It then fails in `imagecreatefrompng()` with "Allowed memory size of 268435456 bytes exhausted":
- `/save` answers 500, and the file already written to `originals/` stays there with no item and no display copy.
- `/autofill/suggest` also answers 500, at `autofill_analysis_gd()`.

The same happens on `main` (whose cap was 60 MP), so #72 doesn't cause it. It does contradict the PR's "40 MP fits under memory_limit 256M" and "the page does not show a server error". Other formats at the same size are fine:
- WebP and GIF at 6400×6250 pass (357 MB / 103 MB RSS).
- PNG at 30, 35 and 36 MP passes.
- JPEG at 40 MP passes (202 MB).

PHP's limit holds, so the process does not grow past 256 MiB.

**Suggested fix (follow-up is fine):**
- Give PNG a lower GD budget (36 MP passes here), or decode large PNGs already reduced.
- Remove the kept original when the display copy cannot be built because of a fatal error, for example with a shutdown hook.

### L-2 (Low): on the image's ImageMagick, `jpeg:size` can decode below the size asked for

With ImageMagick 7.1.2-34 (Alpine, the shipped stack), libjpeg's scaled decode rounds to the nearest 1/8 step instead of the next one up. So the reduced frame can come out smaller than the target:
- **60 MP JPEG (9000×6667):** asked for 1600×1185, decoded at 1125×834. The display copy is therefore 1125×834 instead of 1600×1185, below the Full-size setting.
- **48 MP JPEG:** the analysis copy is 1000×750 instead of 1024×768.

A sweep of 4 JPEGs and 9 target sizes found 16 undersized results on Alpine and none on Debian's ImageMagick 7.1.1-43. The original stays byte-identical and nothing breaks; only some large JPEGs get a smaller, softer display copy.

**Suggested fix:** ask for a hint about 2× the target, or round the scale up to the next 1/8 step, then resize down to the target as now. Add a test that the display copy of a >40 MP JPEG reaches `image_full_max()`.

### N-4 (Nit): suggest says "cannot be read" when upload says "too large" for a HEIC refused at decode

A HEIC inside the header limits that ImageMagick then refuses for a resource limit gets 422 `Image dimensions are too large.` on `/save`, thanks to `e79fc07`. On `/autofill/suggest` the same file gets 200 `unsupported` ("this kind of file cannot be read") instead. On Alpine that covers 16000×8000, which is exactly 128 MP and not over the cap, and 11000×8136. This matches the PR's stated contract, "over that same header limit", and nothing is stored, sent or left behind. Only the message differs. If parity matters, map a resource-limit failure in `autofill_analysis_imagick()` to the same `UserError`.

### N-5 (Nit): `autofill_suggest()` docblock still says "Never throws"

It now rethrows the dimension `UserError`, and so does `autofill_suggest_path()`. Both docblocks should say so.

### N-6 (Nit): the HEIC half of the N-3 test depends on the libheif build

`/autofill/suggest`'s HEIC refusal is tested only in the unit test, and only with a synthetic uncompressed (`unci`) sample whose `ispe` is patched. Alpine's libheif 1.23.4, the image's own, cannot ping that sample. So on the image stack the test fails rather than skipping (the same happens to 5 older HEIC tests), and mutant M3 would survive there. Real HEIC files are refused correctly on Alpine (2.5). Consider a real HEVC-coded fixture, or skipping when the sample cannot be pinged, plus one HTTP-level HEIC case in `integration_autofill.php`.

### I-1 (Info): `tiny-gps.heic`

Coder sees a local decode failure on `tiny-gps.heic`. It did not reproduce here: `HeicTest` passes on all 6 legs, on libheif 1.23.4 (Alpine) and 1.23.6 (Debian), and CI passes it. That is consistent with Coder's explanation that it comes from the libheif build on Coder's own machine.

### I-2 (Info): environment-only failures, the same on `main`

- `AutofillTest::testHttp413RefetchesInfoReclampsAndRetriesOnce` fails the same way on `main` `1dbe986`, on both stacks ("Failed asserting that 871962 is greater than 2031616"). CI passes it.
- `ReleaseTest` ×2: exit 127, because there is no `git` in the rootfs.
- Debian PostgreSQL `HttpSuiteTest`: the single "GET /healthz is 500 when the schema is missing" check fails the same way on `main`. It passes on the Alpine PostgreSQL legs.
- Alpine HEIC unit tests ×6: see N-6.

### I-3 (Info): the real HEIC decode envelope on the image stack

Inside the 128 MP header cap, Alpine decodes an 89.4 MP HEIC in 9.5 s with about 428 MB RSS and a pixel cache of about 1 GiB. From about 89.5 MP up to 128 MP, ImageMagick's disk limit refuses it at decode, and upload turns that into the size error. On Debian's libheif the 128 MP HEIC decodes, at 615 MB RSS. That only affects non-Docker installs and is inside the documented cap.

### I-4 (Info): the 60 MP behaviour change on suggest is as intended

A 60 MP JPEG is now accepted by `/autofill/suggest`, as on upload. It is analysed from a reduced decode (1024×759 sent, 72 MB RSS). It used to be refused by the old 60 MP check.

### I-5 (Info): the rootfs used

The PHP Alpine rootfs set aside for QA has no Imagick, so it cannot exercise this PR. The suites and HTTP runs used a separate Alpine rootfs built with the Dockerfile's own packages and Imagick build steps (section 1).

### I-6 (Info): `main` moved during the review (#73 merged)

`main` is now `a94b76a` (merge of #73, 14:03 Athens). That is not a release commit, so the gate above still holds. But #72 is now 1 merge behind. A simulated merge of `a94b76a` into the head conflicts only in `CHANGELOG.md`'s `[Unreleased]` section, where both sides add bullets (#73's upgrade note, `### Fixed` and `### Maintenance` versus #72's `### Fixed`). `docs/deployment-and-operations.md` merges cleanly, and no code file is changed on both sides. Resolving it means keeping both sets of bullets under `[Unreleased]`, with a single `### Fixed` heading. After that it is a diff read plus a re-run of the CHANGELOG gate, which counts as a minor change. `a94b76a`'s `docs/qa/` has the v2.6.2 and #73 docs but not `apotheke-v2.6.3-release-qa.md`.

## 4. Security

- **S-M1 (decode memory through Imagick):** fixed for the cases in scope.
  - Every JPEG above 40 MP is decoded already reduced, so the 200 MP and 240 MP JPEGs stay at about 81 to 106 MB RSS.
  - Anything over the header caps is refused before decode in about 0.01 s and at about 35 to 38 MB RSS. That holds on upload and, with N-3, on `/autofill/suggest`.
  - Imagick's own resource limits are set at start-up to the shipped policy's values.
  - The HEIC with a 599 MB `iloc` extent is never decoded on either libheif.
- **SEC-N5 (explicit format):** Imagick is called with a named format only (JPEG, PNG, GIF, WebP, HEIC or HEIF). SVG, MVG, PostScript and PDF payloads under photo names are refused on upload and are not sent on suggest.
- **N-3:** the suggest route keeps its CSRF check and `no_store()`. The header check runs before anything is written or sent, on both the upload and the stored-photo branch. A refusal returns a fixed message with no path or library detail.
- **L-1 is not a resource-exhaustion issue.** PHP's `memory_limit` bounds it. It is a 500 plus an orphaned original, and it is unchanged from `main`.
- **Secrets and host names:** the diff against `main` has no tokens, keys, passwords, account ids or private host names. The only URLs are `github.com` links and `127.0.0.1` in a test. CI's secret scan passed.
- **No High or Medium findings.**

## 5. Gate summary

| Gate | Status |
|---|---|
| CI green at `a29e1ee` | Yes, 10/10 |
| Docs and tests in the PR | Yes |
| QA with a Security section, no High or Medium | Yes (this doc) |
| CHANGELOG gate | PASS |
| PM secret scan | PM to run |
| Merge pinned to the `docs(qa):` head | after this doc is committed unchanged as the last commit |
