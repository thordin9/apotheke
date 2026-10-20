# QA: apotheke PR #34, CI4 PR 3 (PHP 8.4 suites, parity recorder, `fix(php84)` QR JIT guard)

- **PR:** [#34](https://github.com/thordin9/apotheke/pull/34) (draft, into `feature/ci4`)
- **Reviewed head:** `2582d7f46a91cad4635d5a3daedb58262dd3ac61` (full review). **Final head:** `222a282ed23c7c75822a0e04cfa743d3e940497a` (delta recheck, §10)
- **Base:** `feature/ci4`, at `ef9356681612d06f4394bbf6b76f54d21a2a9f65` for the review and `4c07b89d33479339512550bf9e817f634c5a8212` after #35 merged
- **Date:** 2026-10-20 (Europe/Athens). No live service calls, no credentials.
- **Scope:** §1–9 are the full review of the pre-merge head `2582d7f`. §10 is the **delta recheck** of the post-merge
  head `222a282`: the merge of `feature/ci4` (with #35) into #34, plus the N-1 and N-2 fixes.

## Test plan

1. **Scope.** Diff `ef93566...2582d7f`, per commit, from codeload tarballs checked with `git get-tar-commit-id`. Confirm
   that `release.yml` is unchanged and that nothing changed outside the stated scope.
2. **CI run 39416413749.** 22/22 jobs. The `php -v` and setup lines show 8.4 legs on 8.4 and 8.3 legs on 8.3. Exactly
   one `coverage-sqlite` upload. Run actionlint.
3. **JIT fix.**
   - Reproduce the `GET /labels` hang on PHP 8.4 with `opcache.jit=1235` at the base, using a timeout. Confirm it is
     fixed at the head.
   - Compare QR matrices across JIT modes.
   - Review the guard: is it safe, how wide is it, and does it cost performance?
   - Mutation-test `testFunctionJitMatchesInterpreter`.
   - Check the shipped image's opcache settings.
4. **Parity recorder.** What it records, whether the allowlist is justified and narrow, and whether it runs in CI.
5. **Dockerfile.**
   - `PHP_VERSION` defaults to 8.3.
   - Build 8.3 and 8.4 with podman.
   - The guard and `/labels` work under php-fpm.
   - For the post-#35 risk, build the trial-merged Dockerfile with `PHP_VERSION=8.4` and run `scripts/docker-heic.sh`.
6. **N-4 and N-5.**
   - Check the doc wording against PR 2 QA.
   - Run `check-links.mjs` and expect 228 links in 30 files.
   - Inject broken links under `docs/qa/` and `docs/superpowers/`.
7. **Suites.**
   - Host PHP 8.4.26: PHPUnit and `tests/run.sh`.
   - PHP 8.3.35 in the head-built 8.3 image: PHPUnit and `tests/integration.php`.
8. **gitleaks** on the range and on the head tree.
9. **Merge readiness after #35.** Trial-merge #35's current head `a06bbc7` into #34 and list the conflicts.
10. **Delta recheck at `222a282`.**
    - The merge parents include `4c07b89`. Replay the merge and check the three conflict resolutions; the Dockerfile,
      `ci.yml` and CHANGELOG.
    - N-1 docs against the code.
    - N-2: classify every `opcache.jit` mode, run `testJitModeClassification`, and mutation-test it.
    - CI run 39420997519, including Imagick and `docker-heic.sh` in both Docker legs.
    - Local suites and podman images.
    - gitleaks on the delta.

## Results

### 1. Scope (PASS)

Tarball ids match `ef93566` and all five PR commits (linear, parent chain `ef93566 → fb225e0 → b885291 → 3db6d40 →
b8fed39 → 2582d7f`). `mergeable_state` is `clean`.

| Commit | Files |
|---|---|
| `fb225e0` ci: check links under all of docs | `scripts/check-links.mjs`, `tests/Unit/LinkCheckTest.php` |
| `b885291` ci(ci4): run lint, mobile, e2e and Docker on PHP 8.4 | `ci.yml`, `Dockerfile`, `scripts/e2e-container-setup.sh`, `tests/Unit/Ci4BranchCiTest.php` |
| `3db6d40` test(parity): record install, healthz and settings | `tests/parity/recorder.php`, `tests/parity/allowlist.yml`, `tests/Unit/ParityRecorderTest.php` (all new) |
| `b8fed39` docs: describe PHP 8.4 suites, parity and N-4 | 12 docs files plus `Ci4BranchCiTest.php` (N-4 wording test), new `php84-legacy-diff.md` |
| `2582d7f` fix(php84): keep QR labels working under JIT 1235 | `src/qr.php`, new `src/qr-encode.php`, `QrCodeTest.php`, `scripts/coverage-readme.php`, 6 docs files |

- **`release.yml`:** unchanged.
- **`src/`:** the only change is the QR split. `src/qr-encode.php` is byte-identical to the old `QrCode` class and
  `qr_svg()`, including the header comment. `src/qr.php` is now only the guard plus `require_once`.
- **`coverage-readme.php`:** adds `src/qr-encode.php` to the QR coverage area, so the README coverage table still counts
  the encoder.
- No schema, migration, config or runtime-dependency change.

### 2. CI run 39416413749 (PASS)

The run was a `pull_request` run on `2582d7f`, from 14:38:40 to 14:49:18 (Europe/Athens). All 22 jobs succeeded.

| Leg | Evidence |
|---|---|
| Lint 8.3 / 8.4 | setup-php "Updated to PHP 8.3.35" / "Installed PHP 8.4.26". Both: 228 links in 30 files, 0 broken |
| Headless mobile 8.3 / 8.4 | Dev server "PHP 8.3.35" / "PHP 8.4.26 Development Server". 8.4 passes "labels sheet shows QR codes" |
| Playwright 8.3 / 8.4 (3 shards each) | `php -v` in the container: PHP 8.3.6 (Ubuntu) / PHP 8.4.26 (PPA) |
| Docker 8.3 / 8.4 | `--build-arg PHP_VERSION=8.3` with `FROM php:8.3-fpm-alpine@sha256:454b…`, and `8.4` with `php:8.4-fpm-alpine@sha256:78cd…`. gha cache scopes `php8.3` / `php8.4`. Smoke, docker-checks and `@smoke` Playwright pass |
| Tests legacy@8.3 sqlite | PHP 8.3.35. 290 tests, 1 skipped (the 8.4-only JIT test). Integration 1415 passed. **`coverage-sqlite` uploaded** |
| Tests legacy@8.4 sqlite | PHP 8.4.26, `OK (290 tests, 1969 assertions)`. The JIT test **runs** here |
| Others | legacy 8.3/8.4 pgsql, ci4 8.4 sqlite/pgsql (gated off), deptrac, DI rule, Secret scan, Conventional Commits |

- **Uploads:** the only `coverage-sqlite` upload is guarded by `legacy && 8.3 && sqlite`, which is what `release.yml`
  downloads. The other uploads are on failure only, and their names now carry the PHP version
  (`playwright-report-php-<v>-shard-N` and `playwright-report-docker-php-<v>`), so the matrix cells don't collide.
- **actionlint:** clean on all workflows.

### 3. JIT fix (PASS, see L-1, N-1, N-2)

**Reproduced at the base.** Host PHP 8.4.26, `php -S` with demo data seeded, `GET /labels` with a client timeout:

| Tree | `opcache.jit` | Result |
|---|---|---|
| base | 1235 | HTTP 500 after 30 s: "Maximum execution time of 30 seconds exceeded in src/qr.php on line 198" (finder loop) |
| base | disable / 1205 / tracing | 200, 10 QR SVGs |
| head | 1235 / 1205 / tracing / disable | 200, 10 QR SVGs |

**QR matrices.** A CLI harness built 240 codes (ECC L/M/Q/H, versions 1 to 40) and hashed every module:

| Version and tree | interpreter | 1235 | 1205 / `function` | 1215 | 0235 | tracing / 1254 / 1255 |
|---|---|---|---|---|---|---|
| 8.4 base | `502d18ad` | hangs | **`e21183ea` (240/240 codes differ, 99 with the same mask)** | **differs** | hangs | `502d18ad` |
| 8.4 head | `502d18ad` | `502d18ad` | `502d18ad` | `502d18ad` | `502d18ad` | `502d18ad` |

- **So on 8.4 the bug is not only a hang:** `opcache.jit=1205` silently produces wrong QR codes at the base. The guard
  fixes both.
- **The interpreter output is the same on both versions:** 8.3 and 8.4 produce byte-identical results.

**How the guard works.**

- Logic: PHP ≥ 8.4 and opcache present → JIT currently on → mode is not tracing (`tracing`, `on`, `125x`) →
  `ini_set('opcache.jit', 'disable')`, then `require_once qr-encode.php`.
- In practice, the encoder is never compiled or JIT-compiled while function JIT is on in that process. JIT stays off for
  the rest of the process; `ini_get` keeps returning `disable`. There is no warning on later requests, because the guard
  returns early once JIT is off.
- **Under php-fpm** (8.4 image, `opcache.jit=1235` from an extra ini), with 6 `/labels` requests each:
  - Head image: all 200 in about 20 ms, with an SVG hash identical to the JIT-off image.
  - Trial-merge image: the same.
  - Control (base `src/` mounted into the same image): the first request hung past the 45 s client timeout.
- **Scope:** `bootstrap.php` requires `qr.php` on every request, so on 8.4 with function JIT the guard turns JIT off for
  the **whole worker process from its first request**, not just for QR (N-1).
- **Performance:** `/labels` takes 19 to 21 ms in every mode. The app is I/O- and DB-bound. The shipped images do not run
  JIT at all (see below), so in practice nothing changes.
- **A narrower option doesn't work:** PHP 8.4's `opcache_jit_blacklist()` on every `QrCode` method still hangs under 1235
  and still differs under 1205. It only applies to tracing JIT. Disabling JIT is the practical fix short of rewriting the
  loops.
- **Shipped image opcache:**
  - 8.3 image: `opcache.jit=tracing`, `jit_buffer_size=0`, so JIT is off.
  - 8.4 image: `opcache.jit=disable` (the PHP 8.4 default).
  - `docker/php.ini` sets no JIT options, so the guard is a no-op in both images. The PR doesn't touch `php.ini`.

**`testFunctionJitMatchesInterpreter` can fail.**

- Head: passes, 16 assertions.
- Mutant A (base `qr.php`, no guard): fails "opcache.jit=1235 is disabled before encode".
- Mutant B (no guard, and the `disable` assertion removed): fails "opcache.jit=1235 exited 255 … Maximum execution time
  of 5 seconds exceeded". So the behavioural check catches the hang as well.
- It is skipped on 8.3 and runs on CI legacy@8.4.

**PHP 8.3 check (L-1).** In the 8.3.35 image built from the head, `opcache.jit=1235` on the encoder does not hang, but
**1 of 240 codes differs from the interpreter with the same mask**, so the module bits are wrong. 1205 and tracing match.

### 4. Parity recorder (PASS, see I-3)

- **What it records:** `tests/parity/recorder.php` starts two fresh legacy installs on the job's PHP, with a fixed clock
  and public URL. It captures:
  - `/`: status, headers, HTML with CSRF masked, data-dir file modes, and every DB table's rows.
  - `/healthz` and `/health`: body, version header and JSON.
  - A settings round trip: `/settings/export.json` before and after a General settings save (303), plus the saved page.
- **Diff:** `parity_diff()` is a recursive diff. Only `date`, `host`, `set-cookie` and `content-length` headers and
  CSRF tokens are normalised.
- **Allowlist:** exactly N-2, QA L-2, N-4 and I-3, all "identical to legacy". The loader rejects a missing entry, an
  extra entry, an unknown line or a version other than 1. It is narrow and justified (PLAN §5.2), and it suppresses no
  diff.
- **In CI:** `ParityRecorderTest` is in the unit suite, so it runs in every legacy PHPUnit cell (8.3 and 8.4, SQLite and
  PostgreSQL). Locally: 5/5 pass, including "Legacy pair agrees on install healthz and settings".

### 5. Dockerfile and images (PASS)

- **The arg:** `ARG PHP_VERSION=8.3` comes before `FROM php:${PHP_VERSION}-fpm-alpine`, and it is used only in `FROM`.
  Compose, `release.yml` and `update-snapshots.sh` don't pass it, so **the shipped image stays 8.3**.
  `e2e-container-setup.sh` with no argument stays on 8.3 for the baselines. It rejects versions other than 8.3 and 8.4,
  and it asserts the resulting `php` major.minor.
- **podman builds:**

  | Build | Time | Result |
  |---|---|---|
  | Head, default | 51 s | PHP 8.3.35 |
  | Head, `PHP_VERSION=8.4` | 52 s | PHP 8.4.26 |

  Both have gd, exif, opcache, pdo_pgsql and zip. Each one passes `scripts/smoke.sh` and returns `/labels` 200 with
  10 QR SVGs.
- **Imagick and HEIC:** not on this base. They arrive with #35, so I checked the post-merge risk directly:
  - I built the **trial-merged Dockerfile (#34 + #35 `a06bbc7`) with `PHP_VERSION=8.4`** in 2 min 6 s.
  - `pecl install imagick-3.8.0` succeeded on `php:8.4-fpm-alpine`, and the in-build HEIC self-check passed.
  - The image shows PHP 8.4.26, `imagick` loaded, and `HEIC,HEIF` in `queryFormats()`.
  - `scripts/docker-heic.sh` passes (`ok Imagick lists HEIC`, `ok heic round-trip`, `ok refusal`), and so do smoke and
    `/labels`.

### 6. N-4 and N-5 (PASS)

- **N-4:**
  - AGENTS.md and ADR-001 now read "legacy@8.3 and legacy@8.4 PHPUnit also run on that branch".
  - PLAN row 3 reads "Docker build + smoke".
  - The L-b and rev. 3 "PHP 8.4" changelog lines are **byte-identical to their text before PR 2** (checked against the
    PR 2 base snapshot). The PM entry is unchanged, and a PR 3 entry is added.
  - `Ci4BranchCiTest` now checks this wording.
- **N-5:** `check-links.mjs` walks all of `docs/` recursively.
  - Head: **228 relative links checked in 30 files, 0 broken**. The base gave 220 in 21.
  - Broken links injected into `docs/qa/ci4-pr02-qa.md` (missing file, missing anchor) and into a
    `docs/superpowers/plans/` file are all reported, and the checker exits 1.
  - The new `APOTHEKE_LINK_ROOT` override is used only by `LinkCheckTest`.

### 7. Suites (PASS)

| Where | PHPUnit | Integration |
|---|---|---|
| Host PHP 8.4.26 | **290 tests, 1960 assertions, 8 skipped** (pgsql ×2, zbarimg ×6). The JIT test runs | `tests/run.sh`: lint OK (228/30/0 links, shellcheck, actionlint), **1413 passed, 0 failed** |
| 8.3.35 image (podman, git and bash added) | **290 tests, 1930 assertions, 11 skipped** (adds the 8.4-only JIT test, php-cgi, node) | `tests/integration.php`: **1413 passed, 0 failed** |

On the trial merge, the targeted suites (QrCode, ParityRecorder, LinkCheck, Ci4BranchCi, Heic, Release) pass: 49 tests,
6 skipped (zbarimg).

### 8. gitleaks (PASS)

- gitleaks 8.30.1 finds 0 leaks on the replayed range `ef93566..2582d7f` and 0 on the full head tree.
- Added URLs and addresses: example domains and a loopback test payload in `QrCodeTest`. The two others (the reference
  implementation's page and the SVG namespace) were moved verbatim with the encoder.
- This agrees with CI's Secret scan job and the PM's scan.

### 9. Merge readiness after #35 (see I-4)

Trial merge of #35 `a06bbc7` (`ff88ee1` plus the CHANGELOG fix) into `2582d7f`, with merge base `ef93566`:

- **Merged automatically:** `ci.yml`, `Dockerfile`, `CHANGELOG.md`, `docs/architecture.md`, `src/bootstrap.php` and the
  rest.
  - `ci.yml` keeps the 8.3/8.4 matrices and gains `imagick` and the `docker-heic.sh` step, which now runs in both Docker
    cells. actionlint is clean.
  - The single `coverage-sqlite` upload is kept.
  - The Dockerfile keeps the arg and gains Imagick/libheif.
- **Conflicts (as DevOps found):**
  - `README.md`: CI jobs table (mobile / Playwright / Docker rows) and the `Dockerfile` line in Project layout.
  - `docs/ci.md`: the same three job rows.
  - `docs/getting-started.md`: "What's in the image".
  - Every conflict is a "both sides add text" case. Resolve each as a union: keep #34's 8.3/8.4 wording and add #35's
    `imagick` (HEIC), `docker-heic.sh` and "Installs without Docker stay on GD".
- After the merge the link check gives 228 links in 33 files, because main's 3 QA docs have no relative links.

### 10. Delta recheck at `222a282` (PASS)

The delta from `2582d7f` is three commits:

- `5954bba` `chore(ci4): merge feature/ci4 into PR 3 branch`, a merge commit with parents `2582d7f` and **`4c07b89`**
  (`feature/ci4` after #35).
- `7b0182f` `docs: say the PHP 8.4 JIT guard covers the worker` (N-1).
- `222a282` `fix(php84): detect function JIT from the CRTO trigger` (N-2).

All tarball ids were checked with `git get-tar-commit-id`.

**10.1 Merge (PASS).**

- `4c07b89` is a real parent. Its tree is #35's reviewed head `a06bbc7` plus the committed #35 QA doc, which is
  byte-identical to the reviewed copy.
- I replayed `git merge` of `4c07b89` into `2582d7f`. Only `README.md`, `docs/ci.md` and `docs/getting-started.md`
  conflict, and **every other file at `5954bba` is byte-identical to the automatic merge**.
- The three resolutions keep both sides:
  - **README.md:** the Docker row has #34's "shipped default PHP 8.3, plus a PHP 8.4 test build" and #35's "checks
    Imagick lists HEIC (`scripts/docker-heic.sh`)", now "on both". The Dockerfile layout line has #34's "by default
    (build arg PHP_VERSION)" and #35's "imagick (HEIC)". The mobile and Visual E2E rows keep #34's 8.3/8.4 wording, and
    #35 had nothing else in them.
  - **docs/ci.md:** the Docker row merges #34's per-version cache scope, `PHP_VERSION` default and 8.4 test image with
    #35's image-size summary and the full `docker-heic.sh` description. It adds that both cells install
    `pecl imagick-3.8.0`. The mobile and Visual rows keep #34's text, and #35's older 8.3-only wording is superseded.
  - **docs/getting-started.md:** #35's `imagick` (HEIC/HEIF via `libheif`) extension list, then #34's "default is the
    shipped 2.5.x image" and `--build-arg PHP_VERSION=8.4` sentences (now "including Imagick"), then #35's "Installs
    without Docker stay on GD".
- **Dockerfile:** `ARG PHP_VERSION=8.3` comes before `FROM php:${PHP_VERSION}-fpm-alpine`, followed by main's Imagick
  and libheif build and its HEIC self-check.
- **ci.yml:**
  - The 8.3/8.4 matrices are kept, and so are the gha cache scopes `php${{ matrix.php }}`.
  - Artifact names are per version: `playwright-report-php-<v>-shard-N` and `playwright-report-docker-php-<v>`.
  - There is **one `coverage-sqlite` upload**, guarded by legacy && 8.3 && sqlite.
  - `imagick` is in the Tests setup-php, and `docker-heic.sh` runs in the Docker job (both cells).
  - actionlint is clean, and `release.yml` is identical to `feature/ci4`'s.
- **CHANGELOG:**
  - `[Unreleased]` holds only `### Maintenance`: the PR 2 CI4 line plus #34's ci, test and fix lines.
  - The header and everything from `## [2.5.2]` down are byte-identical to `feature/ci4` (and so to `main`).

**10.2 N-1 at `7b0182f` (closed).**

- `docs/ci.md`, `php84-legacy-diff.md` and the CHANGELOG fix line now say three things:
  - On PHP 8.4 with function JIT on, the guard sets `opcache.jit=disable` for the whole worker from its first request
    (`qr.php` loads on every request).
  - The cost is negligible.
  - `opcache_jit_blacklist()` does not help.
- This matches the code (`bootstrap.php` → `require_once qr.php` → guard) and my measurements in §3: `/labels` takes
  19–21 ms in every mode, and the blacklist still hangs under 1235 and differs under 1205.

**10.3 N-2 at `222a282` (closed).**

- The comment, the test docblock and `php84-legacy-diff.md` now say the loops "count up from a negative index".
- The guard now calls `qr_php84_jit_is_tracing($jit, $kind)`:
  - `tracing` and `on` → tracing.
  - `function` → function JIT.
  - A 4-digit CRTO → tracing only if the trigger digit (the third) is `5`.
  - Anything else falls back to `opcache_get_status()['jit']['kind'] === 5`. This covers `on`, `1`, `yes` and `true`,
    which ini_get reports as `1`.
- The guard is still 8.4-only, as expected (L-1 goes to `main`).
- **Empirical check** (host PHP 8.4.26, 240-code harness, interpreter hash `502d18ad`):

  | `opcache.jit` | JIT on before | Guard | After | Matrix |
  |---|---|---|---|---|
  | 1205, 1215, 1225, 1235, 1201, 0205, 0235, `205`, `function` | yes | disables | `disable` | = interpreter |
  | 1254, 1255, 0255, 1155, 1055, `tracing`, `on`/`1`/`yes`/`true` (`1`) | yes | keeps | unchanged | = interpreter |
  | `0`, `off`, `disable` | no | returns early (JIT not on) | unchanged | = interpreter |

  Every function-JIT mode gets the guard, and every tracing mode stays on.
- **`testJitModeClassification`** asserts these values:
  - Tracing: `tracing`, `on`, 1254, 0255, 1055, `1`/kind 5.
  - Function JIT: `function`, 1235, 1205, 1215, 1225.
- **`testFunctionJitMatchesInterpreter`** now runs:
  - 1235, 1205, 1215 and `function`, which must be disabled and match the interpreter.
  - `tracing`, 1254, 0255 and `on`, which must stay on and match.
- The off modes (`0`, `off`, `disable`) never reach the classifier, because the guard returns while JIT is off. The
  interpreter baseline runs with `disable`.
- **Mutants:**
  - Putting the old `125` prefix check back fails both tests ("opcache.jit=0255 stays tracing").
  - Classifying `function` as tracing fails both tests ("opcache.jit=function is disabled before encode").

**10.4 CI run 39420997519 (PASS).** A `pull_request` run on **`222a282`**, from 15:16:21 to 15:27:04 (Europe/Athens).
**22/22 green.**

- **Docker 8.3:** `FROM php:8.3-fpm-alpine@sha256:454b…`. `pecl install imagick-3.8.0` → "install ok", into the
  `20230831` (8.3) extension dir. The in-build HEIC check passes. The `docker-heic.sh` step prints `ok HEIC`,
  `ok heic round-trip` and `ok refusal`, followed by smoke and docker-checks.
- **Docker 8.4:** `FROM php:8.4-fpm-alpine@sha256:78cd…`. `pecl install imagick-3.8.0` → "install ok", into the
  `20240924` (8.4) extension dir. The HEIC check, `docker-heic.sh` (all three ok), smoke and docker-checks pass. This
  closes the DevOps risk for the first 8.4 Imagick/HEIC build in CI.
- **legacy@8.3 sqlite:** PHP 8.3.35. 300 tests, 1 skipped (the 8.4-only JIT test). Integration 1425 passed.
  `coverage-sqlite` uploaded.
- **legacy@8.4 sqlite:** PHP 8.4.26, `OK (300 tests, 2151 assertions)`, so the JIT tests ran.
- **Lint 8.4:** 228 links in 34 files, 0 broken.
- **Mobile 8.4:** dev server PHP 8.4.26. "labels sheet shows QR codes" passes.

**10.5 Local at `222a282` (PASS).**

| Check | Result |
|---|---|
| JIT tests (host PHP 8.4.26) | `OK (2 tests, 63 assertions)` |
| Host PHP 8.4.26 PHPUnit | **300 tests, 2142 assertions, 8 skipped** (pgsql ×2, zbarimg ×6) |
| `tests/run.sh` | lint OK (228 links / 34 files / 0 broken, shellcheck, actionlint), **1423 passed, 0 failed** |
| podman image builds | default → PHP 8.3.35 (JIT off: `tracing`, buffer 0); `PHP_VERSION=8.4` → PHP 8.4.26 (`opcache.jit=disable`) |
| `docker-heic.sh` | passes on both images |
| php-fpm 8.4 image, `opcache.jit` default / 1235 / 1205 / tracing | `/labels` 4× each: all 200, identical QR SVG hash |

**10.6 gitleaks (PASS).**

- gitleaks 8.30.1 finds 0 leaks on the replayed delta `2582d7f..222a282`.
- New URLs come only from the merged `main` and #35 content reviewed in #35: GitHub PR and compare links, the
  placeholder Worker URL `https://autofill.example`, and loopback addresses in test scripts.
- The N-1 and N-2 commits add none.

## Findings

| ID | Sev | Finding | Status / decision |
|---|---|---|---|
| L-1 | **Medium (raised by the PM), non-blocking for #34** | PHP 8.3 function JIT (`opcache.jit=1235`) also miscompiles the encoder: 1/240 codes has wrong module bits with the same mask (8.3.35 Alpine). The guard and test stay 8.4-only. It isn't a regression from #34, because `main`/2.5.x has the same code. The shipped 8.3 image has JIT off (`jit_buffer_size=0`), so only non-Docker installs with function JIT are exposed. | **PM decision:** follow-up fix as a PR to `main` for **v2.5.3**. The guard covers PHP 8.3 and any PHP with function JIT, the JIT test runs there, and the v2.5.3 changelog carries the known-issue note. It doesn't block #34, since the code is pre-existing and the fix belongs on `main`. |
| N-1 | Nit (docs) | The guard runs on every request, so with function JIT it disables JIT for the whole worker. The cost is negligible, and `opcache_jit_blacklist()` is not an alternative. | **Closed** at `7b0182f` (§10.2). |
| N-2 | Nit | The comment said the loops count down, and tracing detection only matched `125x`. | **Closed** at `222a282`: CRTO trigger-digit classification, verified for every mode and mutation-tested (§10.3). |
| I-3 | Info | The recorder compares two legacy installs on the same PHP, and the 8.3↔8.4 comparison is a written note (PLAN row 3). The allowlist is checked for shape but not used by the diff yet. | Noted. CI4 legs start using it from PR 4. |
| I-4 | Info (merge) | Merging #35 conflicted in three docs files, and the 8.4 Docker leg builds Imagick/HEIC for the first time. | Noted. The resolutions are verified (§10.1), and both Docker legs are green with `pecl imagick-3.8.0` and `docker-heic.sh` (§10.4). **DevOps watches the 8.4 Imagick/HEIC leg.** |

No new findings in the delta. No H findings. The only M finding is L-1, which the PM ruled non-blocking for #34 and
which is tracked for v2.5.3.

## Verdict

**PASS. PR #34 is ready to merge into `feature/ci4` at `222a282`.**

- The merge of `feature/ci4` (`4c07b89`, including #35) is a real merge commit. The three docs conflicts are resolved as
  unions with nothing lost, and the other files equal the automatic merge.
- The Dockerfile default stays 8.3, cache and artifact names are per PHP version, there is a single `coverage-sqlite`,
  and `[Unreleased]` holds only CI4 Maintenance.
- N-1 and N-2 are verified and closed. The JIT guard classifies every mode correctly, and its tests fail on mutants.
- CI run 39420997519 on `222a282` is 22/22, including both Docker legs with Imagick 3.8.0 and `docker-heic.sh`.
- Local suites pass, and gitleaks is clean.
- Merge as a merge commit, pinned to `222a282`, after the PM re-scan. L-1 follows as the v2.5.3 PR to `main`.

Evidence on the QA box is in `/workspace/apotheke-qa/pr34/` (review) and `/workspace/apotheke-qa/pr34/delta/` (delta).
The delta folder has the tarballs, the replayed merge `replay/`, `jit-modes-head.txt`, CI job logs, mutants, builds,
`docker-heic`, suites and gitleaks.
