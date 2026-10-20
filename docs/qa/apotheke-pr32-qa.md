# QA: apotheke PR #32, Imagick JPEG 4:2:0 and CHANGELOG cleanup

- **PR:** [#32](https://github.com/thordin9/apotheke/pull/32) `fix(photos): force 4:2:0 in Imagick JPEG encodes` (draft, into `main`)
- **Head:** `fb857dcb12145523e0ffadc7fcefadf9a594fcdc`. **Base:** `main` at `eeb7a34748abc8f62b269b2cea11bb511f4a549b` (v2.5.1)
- **Closes:** #30 L-1 (Imagick keeps 4:4:4; size test went *incomplete*), #30 N-9 and CI4 PR 2 I-6 (stale `[Unreleased]`)
- **QA date:** 2026-10-20 (Europe/Athens). No live service calls, no credentials, 0 Neurons.

## Test plan

1. **Scope.** Compare base...head: only the described files change, nothing unexpected. No secrets, addresses or
   hostnames; the only Worker URL allowed in docs is `https://autofill.example`. gitleaks on the commit range.
2. **L-1 fix.** Every Imagick JPEG encode that produces the analysis copy or a HEIC-derived JPEG forces 4:2:0
   (`2x2,1x1,1x1`). Search for missed encode paths. Run PHPUnit and `tests/integration.php` with GD + Imagick and with
   GD only. Independently push a fixture set through the analysis writer (4:4:4, progressive 4:4:4, 4:2:2, 4:2:0,
   noisy 1024 px 4:4:4, EXIF orientation 6 + GPS, PNG with alpha, grayscale, CMYK, HEIC incl. `irot` + EXIF 6) with
   automatic engine choice and with Imagick forced. Check sampling, colourspace, multipart size against 524288,
   dimensions, orientation, metadata and SSIM against the source.
3. **Original kept.** The stored original is byte-identical (sha256) to the upload for every type, HEIC included.
   Compare the served copy base vs head at `jpeg_quality` 82 (default) and 95 (maximum), on the host and in the
   Docker image, and report any quality change for the PM to judge.
4. **Tests.** Run the two tests on base code (expect failure) and on head (expect pass). The noisy test no longer goes
   incomplete; the new test skips only without Imagick.
5. **CHANGELOG / release.** Released sections byte-identical to base. `[Unreleased]` holds nothing that already
   shipped. Replay the 4 commits on a v2.5.1-tagged repo and run `scripts/release.php plan` and `apply`.
6. **Docs** match the code.
7. **CI** at head: count and conclusions.

## Environment

| Where | Stack |
|---|---|
| Host | PHP 8.4.26 CLI, GD (Debian libgd) + Imagick with ImageMagick 7.1.1-43 Q16 and libheif 1.23.4; GD-only runs drop the Imagick ini through `PHP_INI_SCAN_DIR` |
| PR image | built with podman from the head tree: PHP 8.3.35, Alpine libgd, ImageMagick 7.1.2-34 Q16-HDRI, HEIC readable |
| Source | codeload tarballs; `git get-tar-commit-id` = `eeb7a347…` and `fb857dcb…`; the 3 intermediate commits fetched the same way |

## Results

### Check 1: scope and secrets (PASS)

- 7 files, +146 / −37, matching GitHub: `CHANGELOG.md`, `docs/autofill.md`, `docs/autofill-admin-guide.md`,
  `docs/deployment-and-operations.md`, `src/autofill.php`, `src/items.php`, `tests/Unit/AutofillTest.php`.
- Commits: `9fbd39a` test(photos), `1ca0677` fix(photos), `f6e4f62` docs(photos), `fb857dc` docs(changelog). The
  local replay of the 4 trees reproduces the same per-commit stats and the head tree.
- gitleaks: 0 leaks on the replayed range `v2.5.1..pr` (4 commits) and 0 on the changed files plus the diff.
- The added lines contain no URL, IP address or hostname. `VERSION` is untouched (2.5.1 = last tag).

### Check 2: L-1 fix (PASS, see L-1 for the HEIC writer)

- **Code.** A new `autofill_imagick_jpeg_blob()` sets `setOption('jpeg:sampling-factor', '2x2,1x1,1x1')` (and the
  image property), quality, strip, and keeps the `FF D8` check. It is used by `autofill_analysis_imagick()` and by
  `image_store_heic()`. `image_store_heic()` still returns early unless `autofill_heic_supported()` exists, so it
  can never call the new helper before `autofill.php` is loaded.
- **Missed paths.** `rg` over `src`, `public`, `bin`, `scripts` finds only those two Imagick encodes. Every other
  JPEG write (`image_write_pair`, `photo_edit.php`, `backup.php` thumbnails, `seed.php`, `autofill.php:653`) is GD,
  as the PR says.
- **Suites (head).**

| Suite | GD + Imagick | GD only |
|---|---|---|
| PHPUnit (unit + integration) | **276 tests, 1975 assertions, 8 skipped, 0 incomplete**, exit 0 | **276 tests, 1923 assertions, 9 skipped**, exit 0 |
| `tests/integration.php` | 1423 passed, 0 failed | 1422 passed, 0 failed |
| `scripts/lint.sh` | OK (php -l, 186 links / 0 broken, shellcheck) | |

  The 8 skips are environmental (no `pdo_pgsql` ×2, no `zbarimg` ×6). GD-only adds the new test's
  `Imagick is not available` skip. Inside the PR image the two tests plus the HEIC tests pass: 5 tests, 106 assertions.
- **Analysis writer, independent fixtures** (head, host, max_image_bytes 524288, side 1024, quality step 80/70/60):

| Fixture | Engine | q | Out | Multipart B | Sampling | SSIM | Base (Imagick) |
|---|---|---|---|---|---|---|---|
| 3024×4032 4:4:4 q95 | auto (GD) | 80 | 768×1024 | 83,377 | 2x2,1x1,1x1 | 0.897 | same |
| same | Imagick | 80 | 768×1024 | **82,115** | **2x2,1x1,1x1** | 0.896 | 132,403 B, 1x1 (4:4:4), SSIM 0.938 |
| same, progressive | Imagick | 80 | 768×1024 | 82,115 | 2x2,1x1,1x1 | 0.896 | 132,403 B, 4:4:4 |
| 4:2:2 q92 | Imagick | 80 | 768×1024 | 82,131 | 2x2,1x1,1x1 | 0.896 | 99,502 B, 2x1 (4:2:2) |
| 4:2:0 q90 | Imagick | 80 | 768×1024 | 82,106 | 2x2,1x1,1x1 | 0.895 | identical size |
| noisy 1024 4:4:4 | auto (GD) | 60 | 1024×1024 | 495,180 | 2x2,1x1,1x1 | n/a | same |
| same | Imagick | 60 | 1024×1024 | **465,282** | 2x2,1x1,1x1 | n/a | **too_large** (over at q60) |
| 1200×800 EXIF 6 + GPS | GD / Imagick | 80 | 683×1024 | 16,052 / 7,415 | 2x2,1x1,1x1 | 0.995 / 0.995 | Imagick 4:4:4 |
| HEIC (4:2:0, 4:4:4, `irot`, EXIF 6) | Imagick | 80 | 768×1024 | 81,862–90,885 | 2x2,1x1,1x1 | 0.87–0.90 | identical size |
| grayscale | Imagick | 80 | 768×1024 | 63,394 | 2x2 (1 component) | 0.979 | 1x1, same size |

  - Orientation: in both engines the stored left band is at the top and the stored top band is on the right, as
    orientation 6 requires. `irot` + EXIF 6 HEIC comes out 768×1024 portrait. No EXIF Orientation or GPS tag is left
    in any analysis copy.
  - The Imagick 4:2:0 copy now matches GD's own copy in size and SSIM. Zoomed crops look the same (`visual/analysis-photo444.png`).
  - PR image: the same sizes within 0.1 % and 2x2,1x1,1x1 everywhere, so ImageMagick 7.1.2 honours the option too.
    Coder reports ImageMagick 6.9.12 locally, and CI ran the new test (check 7).
  - PNG with alpha and CMYK: see I-1. Both behave the same on base and head.

### Check 3: original kept (PASS) and served copy (see L-1)

- `image_store()` was run for all 13 fixtures on base and head, host and PR image, `jpeg_quality` 82 and 95. The
  stored original's sha256 equals the upload's **in every case** (JPEG, PNG and HEIC). HEIC is stored as `.heic`,
  never converted. This is the behaviour #30 shipped, and what `docs/autofill.md` and the admin guide describe.
- Served copy (GD writes it after Imagick decodes the HEIC through an intermediate JPEG):

| `jpeg_quality` | JPEG / PNG uploads | HEIC uploads, host (Debian libgd) | HEIC uploads, PR image (Alpine libgd) |
|---|---|---|---|
| 82 (default) | byte-identical base = head | **byte-identical** base = head | **byte-identical** |
| 95 (max) | byte-identical | differs: 587,079 → 518,862 B; SSIM 0.976 → 0.945 (`irot` file 0.939 → 0.892) | differs: 374,758 → 370,917 B; SSIM 0.948 → 0.943 |

  At quality 90 and above ImageMagick keeps chroma at full resolution by default, and Debian's libgd also writes
  4:4:4. The forced 4:2:0 intermediate now halves the chroma of the served HEIC copy. At 3× zoom the magenta edges are
  slightly softer (`visual/served-heic-q95.png`). The Docker image's GD writes 4:2:0 anyway, so there the change is
  marginal. This intermediate never leaves the server and no size limit applies to it.

### Check 4: tests before and after (PASS)

- **Base code + head tests** (Imagick): both fail as claimed. Noisy: `imagick q80=1058754 q70=842770 q60=678252`, so
  678252 > 524288. New test: `imagick quality 80 kept 4:4:4`.
- **Base code + base tests:** the noisy test is *incomplete* with the same figures, as #30 found.
- **Head:** both pass (62 assertions). GD-only: noisy passes, new test skips with `Imagick is not available`.
  `markTestIncomplete` is gone from the noisy test, and its size miss is now an `assertLessThanOrEqual(524288, …)`.
  The new test also checks that the GD copy keeps GD's own chroma.

### Check 5: CHANGELOG and release (PASS, see N-1)

- From `## [2.5.1]` to the end of the file, and the 8-line header: sha256 identical between base and head.
- `[Unreleased]` no longer repeats 2.5.0's Added/Security blocks or the 2.5.1 HEIC/startup lines.
- Replay on a repo tagged `v2.5.1`, merged with a merge commit: `plan` gives `bump=patch next=2.5.2 release=true`.
  `apply` writes `VERSION` 2.5.2 and a `## [2.5.2]` section: Fixed = `photos: force 4:2:0 in Imagick JPEG encodes`.
  Maintenance = the test and 2 docs commits. It also writes the compare links. A squash merge with the PR title
  (`fix(photos): …`) is also a patch.
- Not clean: the 5 lines left in `[Unreleased]` have all shipped (N-1).

### Check 6: docs (PASS, N-2)

- `docs/autofill.md`: GD first, Imagick only if GD cannot read the file, Imagick forces 4:2:0, GD unchanged. Matches the code.
- `docs/deployment-and-operations.md`: "GD still writes the file everyone sees" and "Imagick's own JPEG encode uses
  4:2:0". Both are accurate, but the page does not say that this can soften served HEIC copies at quality 90 or above (L-1).
- `docs/autofill-admin-guide.md`: "so a 4:4:4 source cannot blow the size limit" overclaims (N-2). The source line
  break before "image includes that" is uneven but renders fine.

### Check 7: CI (PASS)

- Run 39401562240 at `fb857dc`: **10/10 success**. Lint, Conventional Commits, Secret scan, Tests (PHP 8.3, sqlite),
  Tests (PHP 8.3, pgsql), Headless mobile checks, Docker build + smoke test, Visual E2E shards 1–3. It ran
  12:33–12:42 (Europe/Athens).
- Tests (sqlite) log: `OK (276 tests, 1984 assertions)` with no skips, so the new Imagick test ran in CI. Then
  `1425 passed, 0 failed`. The setup-php extension list does not name `imagick`, so CI relies on the runner image
  providing it (I-3).

## Findings

| ID | Sev | Finding | Suggestion |
|---|---|---|---|
| L-1 | L (quality, non-default setting) | `image_store_heic()` also forces 4:2:0, but its JPEG is only an intermediate that GD decodes and re-encodes. No size limit applies to it. At `jpeg_quality` ≥ 90 the served HEIC copy loses half its chroma: on the host at q95 SSIM falls from 0.976 to 0.945 (0.939 → 0.892 for the `irot` photo) and the file shrinks by 12–15 %. In the PR image it falls from 0.948 to 0.943 (−1 %). At the default 82 it is byte-identical. The original HEIC is untouched. My #30 L-1 suggested forcing 4:2:0 in "both Imagick encoders"; this measurement shows the HEIC writer did not need it. | PM to judge. Either force 4:2:0 only in `autofill_analysis_imagick()` (e.g. a `$force420` flag on the helper) and leave `image_store_heic()` as it was in v2.5.1, or keep it and note it in `deployment-and-operations.md`. Non-blocking. |
| N-1 | nit (changelog) | I-6/N-9 are only partly closed. All 5 lines left in `[Unreleased]` have shipped. The focus, photo-test retry and image-limit notes and the `loaded` docs note were already under `[Unreleased]` at tag `v2.4.1`, so they shipped in 2.4.1 ("focus, cold photo-test retry, and size limit (#26)"). The HTML-unavailable note was there at `v2.5.0`. After `release.php` cuts 2.5.2 they will still sit above it. | Empty the block: an `[Unreleased]` heading with nothing under it, the released sections untouched. It can go in this PR as one more `docs(changelog)` commit, or in the next change. |
| N-2 | nit (docs) | Admin guide: "so a 4:4:4 source cannot blow the size limit". 4:2:0 removes the inflation, but a noisy or larger source can still end in `too_large` after q60. That case is handled and messaged. | "…so a 4:4:4 source no longer inflates the analysis copy". |
| I-1 | info (pre-existing, unchanged) | The Imagick fallback flattens transparent PNG areas to black, where GD gives white. A CMYK JPEG stays CMYK in the analysis copy (now `2x2,1x1,1x1,1x1`). Both happen only when GD cannot read the file. Head and base output have the same size and look the same. | Later, optional: `setImageBackgroundColor('white')` + `mergeImageLayers(FLATTEN)` and `transformImageColorspace(SRGB)` in the analysis writer. |
| I-2 | info | Merging cuts **v2.5.2** (patch). Simulated with `release.php`. | — |
| I-3 | info (CI) | `Tests` uses setup-php with `extensions: gd, pdo_sqlite, pdo_pgsql, exif, mbstring, dom, zip`. Imagick comes from the runner image, so if the image dropped it the new test would skip and CI would stay green. | Add `imagick` to that list in a CI4 PR. |

No H or M findings. #30 L-1 is fixed for the analysis copy, the size test now fails on a miss, and #30 N-9 / I-6 are
partly fixed (N-1).

## Verdict

**PASS** at `fb857dcb12145523e0ffadc7fcefadf9a594fcdc`.

- Every Imagick JPEG encode now writes 4:2:0. With Imagick, a 4:4:4 source gives a 4:2:0 analysis copy the same size
  as GD's: 82,115 B instead of 132,403 B, and a noisy 1024 px image at 465,282 B fits where base said `too_large`.
- Orientation, stripping, dimensions and the 524288 limit hold for every fixture.
- Every stored original is byte-identical to its upload, HEIC included. Served copies are unchanged at the default
  quality.
- PHPUnit: 276 tests with Imagick and 276 with GD only, 0 incomplete. Both tests fail on base and pass on head.
- CI 10/10, gitleaks clean, released CHANGELOG sections byte-identical. Merging gives v2.5.2.
- L-1 (served HEIC softer at quality ≥ 90) is for the PM to judge. N-1 and N-2 are small text fixes.

Evidence: `/workspace/apotheke-qa/pr32/` on the QA box: `pr32.diff`, PHPUnit, integration and lint logs, the
`targeted-*` logs, `out-*.json`, `report-matrix.md`, `out/`, `visual/`, `gl/`, the release replay in `replay/` and
the CI log `ci-tests-sqlite.log`.
